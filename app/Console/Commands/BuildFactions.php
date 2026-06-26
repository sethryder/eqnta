<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Regenerates database/eq/factions.json from an imported PEQ content database.
 *
 * For each zone, finds the "social" guard factions of its resident NPCs and records their base
 * standing plus race/class/deity modifiers. The Router uses this to compute a traveler's con and
 * flag kill-on-sight legs. Run after eq:import-peq; needs the faction/spawn tables in the `peq`
 * connection (config/eqrouter.php → connection).
 */
class BuildFactions extends Command
{
    protected $signature = 'eq:build-factions';

    protected $description = 'Rebuild zone KOS faction data from the PEQ content database';

    public function handle(): int
    {
        $db = DB::connection(config('eqrouter.connection'));

        // Full faction table: base standing + race/class/deity modifiers (mod_name is
        // r<raceId>/c<classId>/d<deityId>). We classify every faction below into guard vs monster.
        $base = [];
        foreach ($db->table('faction_list')->get(['id', 'base']) as $row) {
            $base[$row->id] = (int) $row->base;
        }
        $mods = [];
        foreach ($db->table('faction_list_mod')->get(['faction_id', 'mod', 'mod_name']) as $row) {
            if (preg_match('/^[rcd]\d+$/', $row->mod_name)) {
                $mods[$row->faction_id][$row->mod_name] = (int) $row->mod;
            }
        }

        $threshold = (int) config('eqrouter.kos_threshold');
        $raceIds = array_map(fn (array $race) => $race['id'], config('eqrouter.races'));

        // A faction's best and worst standing across the player races (base + race mod). The spread
        // tells us what kind of faction it is.
        $extremes = function (int $fid) use ($base, $mods, $raceIds): array {
            $standings = array_map(fn (int $id) => ($base[$fid] ?? 0) + ($mods[$fid]['r'.$id] ?? 0), $raceIds);

            return [min($standings), max($standings)];
        };

        // Classify every faction that is KOS to at least one race (worst standing at/below the line):
        //  - KOS faction: also allied (standing >= 0) to at least one player race. This is a
        //    *civilization that takes sides* — a city/guard order that welcomes its own and attacks
        //    outsiders. Covers good cities (Guards of Qeynos: 0 to most, KOS to trolls/iksar) and evil
        //    cities (DaBashers: +50 trolls, KOS to good races). These scale and aggro regardless of a
        //    traveller's level, so they earn a faction warning.
        //  - Monster faction: allied to no player race (best standing below 0) — Sabertooths, undead,
        //    the universal "KOS" faction, and low-level creatures that merely hate a race (Frogloks of
        //    Guk, Crushbone orcs). These aren't a civilization snubbing you; they're just mobs, handled
        //    by the level-gated danger number (a level-50 troll ignores level-5 frogloks).
        $factions = [];
        $monsterFactions = [];
        foreach ($base as $fid => $_) {
            [$worst, $best] = $extremes($fid);
            if ($worst > $threshold) {
                continue;   // not KOS to any race — not a hazard to anyone
            }
            if ($best >= 0) {
                $factions[$fid] = ['base' => $base[$fid] ?? 0, 'mods' => $mods[$fid] ?? []];
            } else {
                $monsterFactions[$fid] = true;
            }
        }

        // Zone → the social factions its spawned NPCs primarily belong to, with how many distinct
        // spawn points each faction holds. spawn2.zone → spawnentry → npc_types → npc_faction.
        // A faction needs kos_min_spawns points in the zone to count, so one stray patrol (e.g. a
        // single Qeynos guard wandering into Blackburrow) doesn't read as "the town guards you".
        $minSpawns = (int) config('eqrouter.kos_min_spawns');
        $zones = [];
        $pairs = $db->table('spawn2 as s')
            ->join('spawnentry as se', 'se.spawngroupID', '=', 's.spawngroupID')
            ->join('npc_types as n', 'n.id', '=', 'se.npcID')
            ->join('npc_faction as nf', 'nf.id', '=', 'n.npc_faction_id')
            ->where('nf.primaryfaction', '>', 0)
            ->groupBy('s.zone', 'nf.primaryfaction')
            ->get(['s.zone as zone', 'nf.primaryfaction as fid', DB::raw('COUNT(DISTINCT s.id) as points')]);

        foreach ($pairs as $pair) {
            if (isset($factions[$pair->fid]) && $pair->points >= $minSpawns) {
                $zones[$pair->zone][$pair->fid] = true;
            }
        }

        $zonesOut = [];
        foreach ($zones as $zone => $fids) {
            $ids = array_map('intval', array_keys($fids));
            sort($ids);
            $zonesOut[$zone] = $ids;
        }

        // One in-game zone can appear under twin shorts (classic vs revamped, e.g. freporte +
        // freeporteast — same "East Freeport"). The PEQ spawns live under just one, so without this
        // the twin reads as faction-free and a traveler routed through it dodges both the KOS warning
        // and the safest-mode penalty. Share each zone's factions across all shorts with its long name.
        // ponytail: unions the symptom; the real fix is one node per zone in the graph, but that's a
        // bigger surgery on edges/coords/paths than the leak warrants.
        foreach ($this->twinShorts() as $shorts) {
            $union = [];
            foreach ($shorts as $short) {
                foreach ($zonesOut[$short] ?? [] as $id) {
                    $union[$id] = true;
                }
            }
            if ($union === []) {
                continue;
            }
            $ids = array_map('intval', array_keys($union));
            sort($ids);
            foreach ($shorts as $short) {
                $zonesOut[$short] = $ids;
            }
        }
        // Hand-curated guard factions for zones PEQ's spawns don't reach (post-RoF zones sourced from
        // client data — see database/eq/overrides.json `factions`). Merged before the "used" filter
        // below so a referenced faction's base/mods survive into the output. A faction id that isn't a
        // PEQ guard faction is kept but warned — it carries no standing, so it can never read as KOS.
        $overrides = $this->factionOverrides();
        $zonesOut = self::applyFactionOverrides($zonesOut, $overrides);
        $unknownOverrides = [];
        foreach ($overrides as $fids) {
            foreach ($fids as $fid) {
                if (! isset($factions[$fid])) {
                    $unknownOverrides[$fid] = true;
                }
            }
        }
        if ($unknownOverrides !== []) {
            $this->warn(sprintf(
                'Override factions not found as PEQ guard factions (no standing, will not read as KOS): %s',
                implode(', ', array_keys($unknownOverrides)),
            ));
        }
        ksort($zonesOut);

        // Keep only factions actually referenced by a zone; emit sorted, integer-keyed data.
        $used = [];
        foreach ($zonesOut as $ids) {
            foreach ($ids as $id) {
                $used[$id] = true;
            }
        }

        $factionsOut = [];
        foreach (array_keys($used) as $id) {
            $factionsOut[$id] = [
                'base' => $factions[$id]['base'] ?? 0,
                'mods' => $factions[$id]['mods'] ?? [],
            ];
        }

        // Per-zone danger level for "safest" routing: the median level of the zone's mobs that would
        // actually attack a passer-by — those on a monster faction (KOS to most races). Guards,
        // friendly guild trainers, and unfactioned service NPCs are excluded, so a city doesn't read
        // as deadly just because its trainers are level 60.
        $levels = $this->zoneDangerLevels($db, array_keys($monsterFactions));
        foreach ($this->twinShorts() as $shorts) {
            $max = max(array_map(fn (string $s) => $levels[$s] ?? 0, $shorts));
            if ($max > 0) {
                foreach ($shorts as $short) {
                    $levels[$short] = $max;
                }
            }
        }
        ksort($levels);

        $out = [
            'threshold' => (int) config('eqrouter.kos_threshold'),
            'factions' => $factionsOut,
            'zones' => $zonesOut,
            'levels' => $levels,
        ];

        $path = config('eqrouter.factions_path');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            'Wrote %d guard factions across %d zones to %s',
            count($factionsOut),
            count($zonesOut),
            $path,
        ));

        return self::SUCCESS;
    }

    /**
     * Union hand-curated guard factions into the per-zone map. Adds to, never replaces, the
     * PEQ-derived factions already on a zone; ids are kept sorted and de-duplicated.
     *
     * @param  array<string, list<int>>  $zonesOut  zone short → faction ids
     * @param  array<string, list<int>>  $overrides  zone short → curated faction ids
     * @return array<string, list<int>>
     */
    public static function applyFactionOverrides(array $zonesOut, array $overrides): array
    {
        foreach ($overrides as $zone => $fids) {
            $union = array_flip($zonesOut[$zone] ?? []);
            foreach ($fids as $fid) {
                $union[(int) $fid] = true;
            }
            $ids = array_map('intval', array_keys($union));
            sort($ids);
            $zonesOut[$zone] = $ids;
        }

        return $zonesOut;
    }

    /**
     * Hand-curated zone short → guard faction ids from the graph overrides file, for zones PEQ's
     * spawn data doesn't reach. Faction ids should exist in PEQ's faction_list to carry standing.
     *
     * @return array<string, list<int>>
     */
    private function factionOverrides(): array
    {
        $path = database_path('eq/overrides.json');
        if (! is_file($path)) {
            return [];
        }

        $factions = json_decode((string) file_get_contents($path), true)['factions'] ?? [];

        return array_map(fn ($fids) => array_map('intval', (array) $fids), $factions);
    }

    /**
     * Groups of zone shorts that share a long name in the graph (e.g. freporte + freeporteast).
     * Only groups with more than one short are returned.
     *
     * @return list<list<string>>
     */
    private function twinShorts(): array
    {
        $path = config('eqrouter.graph_path');
        if (! is_file($path)) {
            return [];
        }

        $zones = json_decode((string) file_get_contents($path), true)['zones'] ?? [];
        $byLong = [];
        foreach ($zones as $zone) {
            $byLong[$zone['long']][] = $zone['short'];
        }

        return array_values(array_filter($byLong, fn (array $shorts) => count($shorts) > 1));
    }

    /**
     * Zone short → median level of the mobs that would attack a passer-by: NPCs on a monster faction
     * (KOS to most races). The median is weighted by distinct spawn points, so a few high-level
     * roamers don't dominate a newbie zone. Zones with no such spawns are omitted.
     *
     * @param  ConnectionInterface  $db
     * @param  list<int>  $monsterFactionIds  factions that attack essentially anyone
     * @return array<string, int>
     */
    private function zoneDangerLevels($db, array $monsterFactionIds): array
    {
        $monsters = array_flip($monsterFactionIds);

        $rows = $db->table('spawn2 as s')
            ->join('spawnentry as se', 'se.spawngroupID', '=', 's.spawngroupID')
            ->join('npc_types as n', 'n.id', '=', 'se.npcID')
            ->leftJoin('npc_faction as nf', 'nf.id', '=', 'n.npc_faction_id')
            ->where('n.level', '>', 0)
            ->groupBy('s.zone', 'n.level', 'nf.primaryfaction')
            ->get(['s.zone as zone', 'n.level as level', DB::raw('COALESCE(nf.primaryfaction, 0) as fid'), DB::raw('COUNT(DISTINCT s.id) as points')]);

        // zone => [level => spawn points], hostile mobs only.
        $hist = [];
        foreach ($rows as $row) {
            if (! isset($monsters[(int) $row->fid])) {
                continue;
            }
            $hist[$row->zone][(int) $row->level] = ($hist[$row->zone][(int) $row->level] ?? 0) + (int) $row->points;
        }

        $levels = [];
        foreach ($hist as $zone => $byLevel) {
            ksort($byLevel);
            $total = array_sum($byLevel);
            $cumulative = 0;
            foreach ($byLevel as $level => $points) {
                $cumulative += $points;
                if ($cumulative >= $total / 2) {
                    $levels[$zone] = $level;
                    break;
                }
            }
        }

        return $levels;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

        // "Social" factions: ones that carry race/class/deity modifiers (city/guild/guard factions).
        // Pure-monster factions (KOS to everyone via base alone, no per-race mods) are skipped so a
        // zone of orcs doesn't read as race-specific KOS. mod_name is r<raceId>/c<classId>/d<deityId>.
        $factions = [];
        foreach ($db->table('faction_list_mod')->get(['faction_id', 'mod', 'mod_name']) as $row) {
            if (! preg_match('/^[rcd]\d+$/', $row->mod_name)) {
                continue;
            }
            $factions[$row->faction_id]['mods'][$row->mod_name] = (int) $row->mod;
        }

        foreach ($db->table('faction_list')->whereIn('id', array_keys($factions))->get(['id', 'base']) as $row) {
            $factions[$row->id]['base'] = (int) $row->base;
        }

        // Keep only city/guard-style factions: ones that tolerate a majority of player races but are
        // KOS to at least one. This is the signature that separates civilization factions (Guards of
        // Qeynos — fine to most, KOS to trolls/iksar) from monster factions that happen to carry race
        // mods (Sabertooths of Blackburrow — KOS to nearly everyone), which aren't a "the town hates
        // you" signal. Discrimination is judged on race alone; class/deity still adjust standing at
        // runtime for the factions we keep.
        $threshold = (int) config('eqrouter.kos_threshold');
        $raceIds = array_map(fn (array $race) => $race['id'], config('eqrouter.races'));
        $minTolerated = (int) ceil(count($raceIds) / 2);

        $factions = array_filter($factions, function (array $faction) use ($raceIds, $threshold, $minTolerated) {
            $base = $faction['base'] ?? 0;
            $tolerated = 0;
            $hostile = 0;
            foreach ($raceIds as $id) {
                if ($base + ($faction['mods']['r'.$id] ?? 0) >= $threshold) {
                    $tolerated++;
                } else {
                    $hostile++;
                }
            }

            return $hostile >= 1 && $tolerated >= $minTolerated;
        });

        // Zone → the social factions its spawned NPCs primarily belong to.
        // spawn2.zone → spawnentry → npc_types → npc_faction.primaryfaction.
        $zones = [];
        $pairs = $db->table('spawn2 as s')
            ->join('spawnentry as se', 'se.spawngroupID', '=', 's.spawngroupID')
            ->join('npc_types as n', 'n.id', '=', 'se.npcID')
            ->join('npc_faction as nf', 'nf.id', '=', 'n.npc_faction_id')
            ->where('nf.primaryfaction', '>', 0)
            ->distinct()
            ->get(['s.zone as zone', 'nf.primaryfaction as fid']);

        foreach ($pairs as $pair) {
            if (isset($factions[$pair->fid])) {
                $zones[$pair->zone][$pair->fid] = true;
            }
        }

        // Keep only factions actually referenced by a zone; emit sorted, integer-keyed data.
        $used = [];
        $zonesOut = [];
        foreach ($zones as $zone => $fids) {
            $ids = array_map('intval', array_keys($fids));
            sort($ids);
            $zonesOut[$zone] = $ids;
            foreach ($ids as $id) {
                $used[$id] = true;
            }
        }
        ksort($zonesOut);

        $factionsOut = [];
        foreach (array_keys($used) as $id) {
            $factionsOut[$id] = [
                'base' => $factions[$id]['base'] ?? 0,
                'mods' => $factions[$id]['mods'] ?? [],
            ];
        }

        $out = [
            'threshold' => (int) config('eqrouter.kos_threshold'),
            'factions' => $factionsOut,
            'zones' => $zonesOut,
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
}

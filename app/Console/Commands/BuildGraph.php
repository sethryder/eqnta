<?php

namespace App\Console\Commands;

use App\Services\Eq\GraphDelta;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Regenerates database/eq/graph.json from an imported PEQ content database.
 *
 * Pulls zones + walkable zone lines from the PEQ tables, merges any hand-curated
 * overrides, and writes the JSON the Router serves. Not run until a dump is imported
 * into the configured `peq` connection (config/eqrouter.php → connection).
 */
class BuildGraph extends Command
{
    protected $signature = 'eq:build-graph';

    protected $description = 'Rebuild the zone routing graph from the PEQ content database';

    /** PEQ uses this sentinel in coordinate columns to mean "wildcard / use current location". */
    private const WILDCARD = 999999;

    public function handle(): int
    {
        $connection = config('eqrouter.connection');
        $db = DB::connection($connection);

        // Nodes: zone id ↔ short ↔ long. A zone short can appear under multiple ids
        // (instanced/era versions); keep the first for display.
        $zoneRows = $db->table('zone')
            ->select('zoneidnumber as id', 'short_name as short', 'long_name as long', 'expansion')
            ->whereNotNull('short_name')
            ->get();

        $shortById = $zoneRows->pluck('short', 'id')->all();
        $expansionByShort = [];
        $longToShort = [];
        $zones = [];
        $seen = [];
        foreach ($zoneRows as $z) {
            // zone.expansion = the EQ expansion a zone debuted in. A short may have several rows
            // (revamped/instanced versions); the earliest expansion it existed wins.
            $expansion = max(0, (int) $z->expansion);
            $expansionByShort[$z->short] = min($expansionByShort[$z->short] ?? $expansion, $expansion);
            $longToShort[mb_strtolower($z->long)] ??= $z->short;
            if (! isset($seen[$z->short])) {
                $seen[$z->short] = true;
                $zones[] = ['id' => (int) $z->id, 'short' => $z->short, 'long' => $z->long];
            }
        }

        // Walk edges from zone_points. A zone line requires both endpoints to exist, so its
        // expansion is the later of the two. (zone_points.min_expansion is unset/-1 in the dump.)
        $edges = [];
        foreach ($db->table('zone_points')->get() as $point) {
            $targetShort = $shortById[$point->target_zone_id] ?? null;
            if ($targetShort === null || $point->zone === $targetShort) {
                continue;
            }

            $edge = [
                'from' => $point->zone,
                'to' => $targetShort,
                'type' => 'walk',
                'expansion' => $this->edgeExpansion($expansionByShort, $point->zone, $targetShort),
                'x' => $this->coord($point->x),
                'y' => $this->coord($point->y),
                'z' => $this->coord($point->z),
            ];

            // Landing coords in the *target* zone (where you arrive). Lets the UI mark the real
            // entrance point on the destination's map, not just the source-side zone line. Only
            // carried when present (non-wildcard) to keep the graph lean.
            $tx = $this->coord($point->target_x ?? null);
            $ty = $this->coord($point->target_y ?? null);
            if ($tx !== null && $ty !== null) {
                $edge['tx'] = $tx;
                $edge['ty'] = $ty;
                $edge['tz'] = $this->coord($point->target_z ?? null);
            }

            $edges[] = $edge;
        }

        // Teleport/translocate/evac edges from spells_new, gated per class + level.
        $edges = array_merge($edges, $this->teleportEdges($db, $shortById, $expansionByShort));

        // Brewall maps: backfill missing zone-line coords and add boats/spires/books/clicks.
        $edges = $this->applyBrewall($edges, $longToShort, $expansionByShort);

        // Universal corrections baked into the base graph: hand-curated fixes (overrides.json) plus
        // the post-RoF expansion graph the PEQ dump can't supply (postrof.json — client-sourced nodes
        // + community-sourced edges for expansions 21-32). Per-server deltas (P99, Legends, ...) are
        // applied later at request time, on top of this base.
        $corrections = GraphDelta::merge(
            GraphDelta::load(database_path('eq/overrides.json')),
            GraphDelta::load(database_path('eq/postrof.json')),
        );
        [$zones, $edges] = GraphDelta::apply($zones, $edges, $corrections);

        // Hub zones aren't reached on foot: retype any remaining "walk" into/out of a hub (Plane of
        // Knowledge → book, Nexus → spire), whatever its source (zone_points or Brewall).
        $edges = $this->retypeHubEdges($edges);

        // Sea crossings that zone_points mislabels as walk (see boat_links) become boats.
        $edges = $this->retypeBoatLinks($edges);

        // Collapse duplicate connections (e.g. a PoK book that came from both zone_points and a
        // Brewall POI). Distinct class teleports to the same zone are kept (key includes class).
        $edges = $this->dedupeEdges($edges);
        if (! GraphDelta::isEmpty($corrections)) {
            $this->line(sprintf(
                '  Corrections: +%d zones, +%d edges, -%d edges',
                count($corrections['zones']),
                count($corrections['add']),
                count($corrections['remove']),
            ));
        }

        $path = config('eqrouter.graph_path');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(
            ['zones' => $zones, 'edges' => $edges],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        $this->info(sprintf('Wrote %d zones and %d edges to %s', count($zones), count($edges), $path));

        return self::SUCCESS;
    }

    /** spells_new effect (SPA) id → edge type. Fixed-destination ports only; gate-to-bind (26) is excluded. */
    private const TELEPORT_EFFECTS = [83 => 'teleport', 104 => 'translocate', 88 => 'evac'];

    /** classesN column index → EQEmu class short code. */
    private const CLASS_BY_INDEX = [
        1 => 'WAR', 2 => 'CLR', 3 => 'PAL', 4 => 'RNG', 5 => 'SHD', 6 => 'DRU', 7 => 'MNK', 8 => 'BRD',
        9 => 'ROG', 10 => 'SHM', 11 => 'NEC', 12 => 'WIZ', 13 => 'MAG', 14 => 'ENC', 15 => 'BST', 16 => 'BER',
    ];

    /** classesN sentinel meaning "this class cannot cast the spell". */
    private const CANNOT_CAST = 255;

    /**
     * Teleport-type edges from spells_new. Each castable class becomes a "*" → destination edge,
     * gated by that class's level. Era is inherited from the destination zone's min_expansion.
     *
     * @param  array<int, string>  $shortById
     * @param  array<string, int>  $expansionByShort
     * @return list<array>
     */
    private function teleportEdges(ConnectionInterface $db, array $shortById, array $expansionByShort): array
    {
        $knownShorts = array_flip($shortById);

        $spells = $db->table('spells_new')
            ->whereIn('effectid1', array_keys(self::TELEPORT_EFFECTS))
            ->where('teleport_zone', '!=', '')
            ->get();

        $edges = [];
        foreach ($spells as $spell) {
            $to = $spell->teleport_zone;
            if (! isset($knownShorts[$to])) {
                continue; // destination isn't a routable zone (instance/removed)
            }

            $type = self::TELEPORT_EFFECTS[(int) $spell->effectid1];
            $expansion = $expansionByShort[$to] ?? 0; // castable anywhere → gated by the destination

            foreach (self::CLASS_BY_INDEX as $index => $class) {
                $level = (int) $spell->{"classes{$index}"};
                if ($level < 1 || $level >= self::CANNOT_CAST) {
                    continue;
                }

                $edges[] = [
                    'from' => '*',
                    'to' => $to,
                    'type' => $type,
                    'class' => $class,
                    'min_level' => $level,
                    'expansion' => $expansion,
                    'spell' => $spell->name,
                ];
            }
        }

        return $edges;
    }

    /**
     * Apply Brewall map data: backfill coords on zone-line edges that lack them, and add
     * transport connections (boats/spires/books/clicks) that zone_points doesn't carry.
     *
     * @param  list<array>  $edges
     * @param  array<string, string>  $longToShort
     * @param  array<string, int>  $expansionByShort
     * @return list<array>
     */
    private function applyBrewall(array $edges, array $longToShort, array $expansionByShort): array
    {
        $files = $this->mapFiles();
        if ($files === []) {
            $this->warn('No Brewall/Goodurden maps found — skipping boats/spires and coord backfill.');

            return $edges;
        }

        // Index the first walk edge per from→to so we can backfill its coordinates.
        $walkIndex = [];
        foreach ($edges as $i => $edge) {
            if ($edge['type'] === 'walk') {
                $walkIndex[$edge['from']."\0".$edge['to']] ??= $i;
            }
        }

        $backfilled = 0;
        $new = []; // key from\0to => edge, preferring a typed connection over a plain walk
        foreach ($this->brewallConnections($files, $longToShort, $expansionByShort) as $conn) {
            $key = $conn['from']."\0".$conn['to'];

            if (isset($walkIndex[$key])) {
                $edge = &$edges[$walkIndex[$key]];
                // zone_points often stores a missing zone line as null or 0,0,0; Brewall has the real spot.
                $missing = $edge['x'] === null || ($edge['x'] == 0.0 && $edge['y'] == 0.0 && $edge['z'] == 0.0);
                if ($missing && $conn['x'] !== null) {
                    $edge['x'] = $conn['x'];
                    $edge['y'] = $conn['y'];
                    $edge['z'] = $conn['z'];
                    $backfilled++;
                }
                unset($edge);

                continue;
            }

            // New connection. Prefer a real transport type over a plain "walk" label if both appear.
            if (! isset($new[$key]) || ($new[$key]['type'] === 'walk' && $conn['type'] !== 'walk')) {
                $new[$key] = $conn;
            }
        }

        $this->line(sprintf('  Maps: backfilled %d coords, added %d connections', $backfilled, count($new)));

        return array_merge($edges, array_values($new));
    }

    /**
     * Map .txt files to scan for POIs/coords: Brewall first, with Goodurden filling in only the
     * zones Brewall doesn't cover. Deduped by zone short (filename minus the `_N` layer suffix),
     * so a Brewall-covered zone is never overridden by Goodurden.
     *
     * @return list<string> absolute file paths
     */
    private function mapFiles(): array
    {
        $shortOf = fn (string $file) => strtolower((string) preg_replace('/(_\d+)?\.txt$/i', '', basename($file)));

        $glob = function (?string $dir): array {
            return $dir !== null && is_dir($dir) ? (glob(rtrim($dir, '/').'/*.txt') ?: []) : [];
        };

        $brewall = $glob(config('eqrouter.brewall_path'));
        $covered = array_flip(array_map($shortOf, $brewall));

        $goodurden = array_filter(
            $glob(config('eqrouter.goodurden_path')),
            fn (string $file) => ! isset($covered[$shortOf($file)]),
        );

        return array_merge($brewall, array_values($goodurden));
    }

    /**
     * Parse Brewall/Goodurden map files into directed connections. Each `to_<Destination>` POI
     * becomes an edge from the file's zone to the destination zone (matched by long name), typed
     * from the label's parenthetical (boat/spire/book/teleport) and defaulting to walk.
     *
     * @param  list<string>  $files  map .txt paths to parse (see mapFiles())
     * @param  array<string, string>  $longToShort
     * @param  array<string, int>  $expansionByShort
     * @return list<array{from:string, to:string, type:string, expansion:int, x:?float, y:?float, z:?float}>
     */
    private function brewallConnections(array $files, array $longToShort, array $expansionByShort): array
    {
        $connections = [];
        foreach ($files as $file) {
            // Filename → zone short: strip the `_1`/`_2` layer suffix and extension.
            $from = strtolower(preg_replace('/(_\d+)?\.txt$/i', '', basename($file)));
            if (! isset($expansionByShort[$from])) {
                continue; // not a routable zone (interior layer files, etc.)
            }

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (! str_starts_with($line, 'P ')) {
                    continue;
                }

                // P x, y, z, r, g, b, size, label
                $parts = explode(',', $line, 8);
                if (count($parts) < 8) {
                    continue;
                }
                $label = trim($parts[7]);
                if (! preg_match('/^to[ _]/i', $label)) {
                    continue;
                }

                $name = preg_replace('/^to[ _]/i', '', $label);
                $name = preg_replace('/[ _]\(.*$/', '', $name); // drop "(Translocator ...)" etc.
                $dest = $longToShort[mb_strtolower(trim(str_replace('_', ' ', $name)))] ?? null;
                if ($dest === null || $dest === $from) {
                    continue;
                }

                // Map .txt coords are in the client's screen frame (x=east, y=south); graph edges
                // store the PEQ world frame (screen = -world), so negate to keep one convention.
                $x = $this->coord((float) substr($parts[0], 2));
                $y = $this->coord((float) $parts[1]);
                $connections[] = [
                    'from' => $from,
                    'to' => $dest,
                    'type' => $this->classifyTransport($label),
                    'expansion' => $this->edgeExpansion($expansionByShort, $from, $dest),
                    'x' => $x === null ? null : -$x,
                    'y' => $y === null ? null : -$y,
                    'z' => $this->coord((float) $parts[2]),
                ];
            }
        }

        return $connections;
    }

    /** Derive an edge type from a Brewall connection label's keywords. */
    private function classifyTransport(string $label): string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'spire') => 'spire',
            str_contains($l, 'boat') || str_contains($l, 'translocator') || str_contains($l, 'dock') || str_contains($l, 'ship') => 'boat',
            str_contains($l, 'book') || str_contains($l, 'pok') => 'book',
            str_contains($l, 'portal') || str_contains($l, 'teleport') || str_contains($l, 'pad') => 'teleport',
            default => 'walk',
        };
    }

    /**
     * Retype "walk" edges touching a hub zone to that hub's travel type (see zone_point_hubs).
     * Hubs are reached by book/spire, not on foot — but neither zone_points nor Brewall labels them,
     * so they arrive as walk. Only walk is retyped; real teleport/translocate edges are left alone.
     * First matching hub wins, so a Nexus↔PoK link reads as a spire, not a book.
     *
     * @param  list<array>  $edges
     * @return list<array>
     */
    private function retypeHubEdges(array $edges): array
    {
        $hubs = config('eqrouter.zone_point_hubs', []);

        foreach ($edges as &$edge) {
            if ($edge['type'] !== 'walk') {
                continue;
            }
            foreach ($hubs as $hub => $type) {
                if ($edge['from'] === $hub || $edge['to'] === $hub) {
                    $edge['type'] = $type;
                    break;
                }
            }
        }
        unset($edge);

        return $edges;
    }

    /**
     * Retype walk edges that are really sea crossings (config boat_links) to "boat", keeping coords.
     * zone_points has no type, so cross-water boat routes arrive as walk; this corrects the verified
     * ones (e.g. North Ro ⇄ Iceclad Ocean, the classic Velious boat).
     *
     * @param  list<array>  $edges
     * @return list<array>
     */
    private function retypeBoatLinks(array $edges): array
    {
        $links = [];
        foreach (config('eqrouter.boat_links', []) as [$a, $b]) {
            $links[$a."\0".$b] = true;
            $links[$b."\0".$a] = true;
        }

        foreach ($edges as &$edge) {
            if ($edge['type'] === 'walk' && isset($links[$edge['from']."\0".$edge['to']])) {
                $edge['type'] = 'boat';
            }
        }
        unset($edge);

        return $edges;
    }

    /**
     * Drop duplicate edges, keeping the first of each from→to→type→class. A null class (walk, boat,
     * book, …) collapses dupes; distinct teleport classes to the same zone are preserved.
     *
     * @param  list<array>  $edges
     * @return list<array>
     */
    private function dedupeEdges(array $edges): array
    {
        $seen = [];
        $unique = [];
        foreach ($edges as $edge) {
            $key = $edge['from']."\0".$edge['to']."\0".$edge['type']."\0".($edge['class'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $edge;
        }

        return $unique;
    }

    /**
     * An edge belongs to the later expansion of its two endpoints (both must exist for it to route).
     *
     * @param  array<string, int>  $expansionByShort
     */
    private function edgeExpansion(array $expansionByShort, string $from, string $to): int
    {
        return max($expansionByShort[$from] ?? 0, $expansionByShort[$to] ?? 0);
    }

    /** Treat the 999999 wildcard sentinel (and a missing column) as "no coordinate". */
    private function coord(int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return abs((float) $value) >= self::WILDCARD ? null : (float) $value;
    }
}

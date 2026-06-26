<?php

namespace App\Services\Eq;

use RuntimeException;

/**
 * Loads the JSON zone graph and finds the cheapest typed path between two zones, filtered by the
 * enabled expansions and (for teleport-type edges) class + level. A per-server delta (custom
 * zones/edges) can be layered over the shared base graph.
 *
 * The graph is tiny, so we filter edges per request and run a plain O(V^2) Dijkstra.
 * ponytail: O(V^2) is fine for hundreds of zones; reach for a heap only if it ever matters.
 */
class Router
{
    /** @var array<string, array{zones: list<array>, edges: list<array>}> base graph keyed by file path */
    private static array $cache = [];

    /** @var array<string, array{zones: list<array>, edges: list<array>}> delta-resolved graph keyed by cache key */
    private array $resolved = [];

    /** @var array<string, array<string, string>> zone short → continent, keyed by file path */
    private static array $continents = [];

    public function __construct(private FactionService $factions) {}

    /**
     * @return array{zones: list<array{id:int, short:string, long:string}>, edges: list<array>}
     */
    public function graph(): array
    {
        $path = config('eqrouter.graph_path');

        if (! isset(self::$cache[$path])) {
            if (! is_file($path)) {
                throw new RuntimeException("Graph file missing: {$path}");
            }

            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            self::$cache[$path] = ['zones' => $data['zones'] ?? [], 'edges' => $data['edges'] ?? []];
        }

        return self::$cache[$path];
    }

    /**
     * The base graph with a server's delta applied, cached per cache key.
     *
     * @param  array{zones: list<array>, add: list<array>, remove: list<array>}  $delta
     * @return array{zones: list<array>, edges: list<array>}
     */
    public function resolvedGraph(string $cacheKey = 'base', array $delta = []): array
    {
        if (! isset($this->resolved[$cacheKey])) {
            $base = $this->graph();

            if ($delta === [] || GraphDelta::isEmpty($delta)) {
                $this->resolved[$cacheKey] = $base;
            } else {
                [$zones, $edges] = GraphDelta::apply($base['zones'], $base['edges'], $delta);
                $this->resolved[$cacheKey] = ['zones' => $zones, 'edges' => $edges];
            }
        }

        return $this->resolved[$cacheKey];
    }

    /**
     * Zone list (sorted by long name) for the chosen server's graph. When `$expansions` is given,
     * the list is narrowed to zones touched by at least one edge in that expansion set — i.e. zones
     * you can actually reach in that era. Passing null returns every zone in the graph.
     *
     * @param  array{zones: list<array>, add: list<array>, remove: list<array>}  $delta
     * @param  list<int>|null  $expansions  enabled expansion ordinals, or null for no filtering
     * @return list<array{short:string, long:string, continent:?string}>
     */
    public function zones(string $cacheKey = 'base', array $delta = [], ?array $expansions = null): array
    {
        $graph = $this->resolvedGraph($cacheKey, $delta);
        $continents = $this->continents();

        $zones = array_map(
            fn (array $z) => [
                'short' => $z['short'],
                'long' => $z['long'],
                'continent' => $continents[$z['short']] ?? null,
            ],
            $graph['zones'],
        );

        if ($expansions !== null) {
            $enabled = array_flip($expansions);
            $reachable = [];
            foreach ($graph['edges'] as $edge) {
                if (! isset($enabled[$edge['expansion'] ?? 0])) {
                    continue;
                }
                // '*' is "castable from anywhere", so only its destination is a concrete zone.
                if (isset($edge['from']) && $edge['from'] !== '*') {
                    $reachable[$edge['from']] = true;
                }
                if (isset($edge['to'])) {
                    $reachable[$edge['to']] = true;
                }
            }
            $zones = array_values(array_filter($zones, fn ($z) => isset($reachable[$z['short']])));
        }

        usort($zones, fn ($a, $b) => strcmp($a['long'], $b['long']));

        return $zones;
    }

    /**
     * Zone short → continent map (from eq:build-continents). Cached for the process.
     *
     * @return array<string, string>
     */
    private function continents(): array
    {
        $path = config('eqrouter.continents_path');

        if (! isset(self::$continents[$path])) {
            self::$continents[$path] = is_file($path)
                ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                : [];
        }

        return self::$continents[$path];
    }

    /**
     * Find the cheapest route. Returns null when no path exists.
     *
     * @param  list<int>  $expansions  enabled expansion ordinals; an edge routes only if its expansion is in this set
     * @param  'fastest'|'safest'  $mode  cost profile: fastest time, or least overland exposure
     * @param  string|null  $race  race code; with class/deity, drives the KOS-zone penalty and flags
     * @param  array{zones: list<array>, add: list<array>, remove: list<array>}  $delta  per-server delta
     * @return array{steps: list<array>, hops: int, weight: int}|null
     */
    public function find(string $from, string $to, array $expansions, ?string $class, int $level, string $mode = 'fastest', ?string $race = null, ?string $deity = null, string $cacheKey = 'base', array $delta = []): ?array
    {
        if ($from === $to) {
            return ['steps' => [], 'hops' => 0, 'weight' => 0];
        }

        $adjacency = $this->buildAdjacency($expansions, $class, $level, $cacheKey, $delta);
        $fastest = config('eqrouter.weights');
        $weights = $mode === 'safest' ? config('eqrouter.weights_safe', $fastest) : $fastest;

        // Zones that are KOS for this race/class/deity (real PEQ faction cons). In "safest" mode
        // entering one costs extra; every mode tags the leg so the UI can warn.
        $kos = $this->factions->kosZones($race, $class, $deity);
        $kosPenalty = $mode === 'safest' ? (int) config('eqrouter.kos_penalty', 0) : 0;

        // Level danger: a zone whose hostile-mob median out-levels the traveler (beyond a margin) is
        // flagged on every leg so the UI can warn; in "safest" mode each level over also adds cost,
        // so the router threads toward gentler zones.
        $levels = $this->factions->zoneLevels();
        $dangerMargin = (int) config('eqrouter.danger_margin', 0);
        $dangerPerLevel = $mode === 'safest' ? (int) config('eqrouter.danger_per_level', 0) : 0;

        // Dijkstra.
        $dist = [];
        $prev = [];   // node => [from, edge]
        $visited = [];
        $dist[$from] = 0;
        $queue = [$from];

        while ($queue !== []) {
            // Pull the unvisited node with the smallest distance.
            $current = null;
            $best = PHP_INT_MAX;
            foreach ($queue as $node) {
                if (! isset($visited[$node]) && ($dist[$node] ?? PHP_INT_MAX) < $best) {
                    $best = $dist[$node];
                    $current = $node;
                }
            }

            if ($current === null) {
                break;
            }
            if ($current === $to) {
                break;
            }

            $visited[$current] = true;
            $queue = array_values(array_filter($queue, fn ($n) => $n !== $current));

            foreach ($adjacency[$current] ?? [] as $edge) {
                $next = $edge['to'];
                if (isset($visited[$next])) {
                    continue;
                }

                $cost = $dist[$current] + ($weights[$edge['type']] ?? 10);
                if (isset($kos[$next])) {
                    $cost += $kosPenalty;
                }
                if (($over = (($levels[$next] ?? 0) - $level - $dangerMargin)) > 0) {
                    $cost += $over * $dangerPerLevel;
                }
                if ($cost < ($dist[$next] ?? PHP_INT_MAX)) {
                    $dist[$next] = $cost;
                    $prev[$next] = ['from' => $current, 'edge' => $edge];
                    $queue[] = $next;
                }
            }
        }

        if (! isset($dist[$to])) {
            return null;
        }

        $result = $this->reconstruct($prev, $from, $to, $dist[$to]);

        // Flag each leg whose destination zone is KOS for this race, or whose hostile mobs out-level
        // the traveler beyond the margin (both independent of mode).
        foreach ($result['steps'] as &$step) {
            $step['kos'] = isset($kos[$step['to']]);
            $zoneLevel = $levels[$step['to']] ?? 0;
            if ($zoneLevel > $level + $dangerMargin) {
                $step['danger'] = $zoneLevel;
            }
        }

        return $result;
    }

    /**
     * Filtered adjacency list: short => list of usable edges.
     *
     * @return array<string, list<array>>
     */
    private function buildAdjacency(array $expansions, ?string $class, int $level, string $cacheKey = 'base', array $delta = []): array
    {
        $graph = $this->resolvedGraph($cacheKey, $delta);
        $enabled = array_flip($expansions);
        $shorts = array_column($graph['zones'], 'short');

        $adjacency = [];
        foreach ($graph['edges'] as $edge) {
            // Expansion gate: the edge's expansion must be one the player has enabled.
            if (! isset($enabled[$edge['expansion'] ?? 0])) {
                continue;
            }

            // Class/level gate (teleport-type edges only carry a class).
            if (isset($edge['class'])) {
                if ($class !== $edge['class'] || $level < ($edge['min_level'] ?? 0)) {
                    continue;
                }
            }

            // "*" means castable from anywhere → fan out to every known zone.
            $sources = $edge['from'] === '*' ? $shorts : [$edge['from']];
            foreach ($sources as $source) {
                if ($source !== $edge['to']) {
                    $adjacency[$source][] = $edge;
                }
            }
        }

        return $adjacency;
    }

    /**
     * @param  array<string, array{from:string, edge:array}>  $prev
     * @return array{steps: list<array>, hops: int, weight: int}
     */
    private function reconstruct(array $prev, string $from, string $to, int $weight): array
    {
        $steps = [];
        $node = $to;
        while ($node !== $from) {
            $hop = $prev[$node];
            $edge = $hop['edge'];
            $step = [
                'from' => $hop['from'],
                'to' => $node,
                'type' => $edge['type'],
                'x' => $edge['x'] ?? null,
                'y' => $edge['y'] ?? null,
                'z' => $edge['z'] ?? null,
            ];
            // Landing coords in `to` (where you arrive), when known — lets the UI estimate the
            // run across the next zone and draw the entrance→exit path line.
            if (isset($edge['tx'], $edge['ty'])) {
                $step['tx'] = $edge['tx'];
                $step['ty'] = $edge['ty'];
            }
            $steps[] = $step;
            $node = $hop['from'];
        }

        $steps = array_reverse($steps);

        return ['steps' => $steps, 'hops' => count($steps), 'weight' => $weight];
    }
}

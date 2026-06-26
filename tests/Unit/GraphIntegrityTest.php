<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the committed database/eq/graph.json against regressions in eq:build-graph — specifically
 * the hub edge-typing fix (PoK/Nexus are book/spire, never walk) and edge de-duplication.
 */
class GraphIntegrityTest extends TestCase
{
    /** @return array{zones: list<array>, edges: list<array>} */
    private function graph(): array
    {
        return json_decode((string) file_get_contents(config('eqrouter.graph_path')), true);
    }

    public function test_hub_zones_are_never_reached_on_foot(): void
    {
        $edges = $this->graph()['edges'];

        foreach (array_keys(config('eqrouter.zone_point_hubs')) as $hub) {
            $walkToHub = array_filter(
                $edges,
                fn (array $e) => $e['type'] === 'walk' && ($e['from'] === $hub || $e['to'] === $hub),
            );

            $this->assertCount(0, $walkToHub, "Found walk edges touching hub zone '{$hub}'");
        }
    }

    public function test_no_walk_edge_crosses_between_continents(): void
    {
        $edges = $this->graph()['edges'];
        $continent = json_decode((string) file_get_contents(config('eqrouter.continents_path')), true);

        $crossings = [];
        foreach ($edges as $edge) {
            if ($edge['type'] !== 'walk' || ($edge['expansion'] ?? 0) > 2) {
                continue;
            }
            $from = $continent[$edge['from']] ?? null;
            $to = $continent[$edge['to']] ?? null;
            if ($from !== null && $to !== null && $from !== $to) {
                $crossings[] = "{$edge['from']}({$from}) → {$edge['to']}({$to})";
            }
        }

        $this->assertSame([], $crossings, 'Walk edges cross continents (should be boats or junk)');
    }

    public function test_graph_has_no_duplicate_edges(): void
    {
        $edges = $this->graph()['edges'];

        $keys = array_map(
            fn (array $e) => $e['from']."\0".$e['to']."\0".$e['type']."\0".($e['class'] ?? ''),
            $edges,
        );

        $this->assertSame(count($keys), count(array_unique($keys)), 'Duplicate edges in graph.json');
    }
}

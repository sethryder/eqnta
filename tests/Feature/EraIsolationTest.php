<?php

namespace Tests\Feature;

use App\Services\Eq\GraphDelta;
use Tests\TestCase;

/**
 * Era isolation: the post-RoF expansion graph (overrides.json CoTF + postrof.json 21-32) must stay
 * gated behind its expansion ordinals, so classic-era servers (P99, Legends) never see those zones.
 * Routes against a self-built post-rebuild graph: the committed base merged with both delta files,
 * exactly as eq:build-graph does — so this guards the real intent even before graph.json is rebuilt.
 */
class EraIsolationTest extends TestCase
{
    private string $mergedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $base = json_decode(file_get_contents(database_path('eq/graph.json')), true);
        $delta = GraphDelta::merge(
            GraphDelta::load(database_path('eq/overrides.json')),
            GraphDelta::load(database_path('eq/postrof.json')),
        );
        [$zones, $edges] = GraphDelta::apply($base['zones'], $base['edges'], $delta);

        $this->mergedPath = tempnam(sys_get_temp_dir(), 'mergedgraph').'.json';
        file_put_contents($this->mergedPath, json_encode(['zones' => $zones, 'edges' => $edges]));
        config(['eqrouter.graph_path' => $this->mergedPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->mergedPath);
        parent::tearDown();
    }

    public function test_post_rof_zones_absent_for_p99_and_legends_but_present_for_live(): void
    {
        $postRof = ['ethernere', 'laurioninn', 'stratos', 'overtheretwo', 'eastwastestwo'];

        foreach (['p99', 'legends'] as $server) {
            $shorts = array_column($this->getJson("/api/zones?server={$server}")->json(), 'short');
            $this->assertContains('qeynos2', $shorts, "$server should still have classic zones");
            foreach ($postRof as $z) {
                $this->assertNotContains($z, $shorts, "$server must NOT expose post-RoF zone $z");
            }
        }

        // Live (expansions through 32) DOES expose them — proves gating is expansion-based, and that
        // the live server set actually enables 21-32 (regression: it was once capped at 20).
        $live = array_column($this->getJson('/api/zones?server=live')->json(), 'short');
        $this->assertContains('ethernere', $live);   // CoTF, exp 20
        $this->assertContains('laurioninn', $live);  // Laurion's Song, exp 30
        $this->assertContains('spite', $live);       // Shattering of Ro, exp 32
    }

    public function test_p99_cannot_route_into_a_post_rof_zone(): void
    {
        // ethernere's edges are expansion 20 (gated out of P99's [0,1,2]), so Dijkstra finds nothing.
        // The UI also never offers it as a destination (test above), so this is the API backstop.
        $this->getJson('/api/route?from=qeynos2&to=ethernere&server=p99')->assertStatus(404);
    }

    public function test_p99_classic_route_still_resolves_and_is_never_flagged_incomplete(): void
    {
        $res = $this->getJson('/api/route?from=qeynos2&to=freportw&server=p99');
        $res->assertOk();
        $res->assertJsonPath('incomplete', false);
        $this->assertGreaterThan(0, count($res->json('steps')));
    }
}

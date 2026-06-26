<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Route against a stable fixture, not the generated database/eq/graph.json.
        config(['eqrouter.graph_path' => __DIR__.'/../Fixtures/graph.json']);
    }

    public function test_zones_endpoint_returns_seed_zones_sorted_by_long_name(): void
    {
        $response = $this->getJson('/api/zones');

        $response->assertOk();
        $qeynos = collect($response->json())->firstWhere('short', 'qeynos2');
        $this->assertSame('North Qeynos', $qeynos['long']);
        $this->assertArrayHasKey('continent', $qeynos);

        $longs = array_column($response->json(), 'long');
        $sorted = $longs;
        sort($sorted);
        $this->assertSame($sorted, $longs);
    }

    public function test_zone_list_narrows_to_the_enabled_expansions(): void
    {
        config(['eqrouter.graph_path' => __DIR__.'/../Fixtures/era_graph.json']);

        // Classic only: the Luclin-gated zone is not reachable, so it's hidden.
        $classic = $this->getJson('/api/zones?expansions=0');
        $classic->assertOk();
        $shorts = array_column($classic->json(), 'short');
        $this->assertContains('classiczone', $shorts);
        $this->assertNotContains('luclinzone', $shorts);

        // With Luclin enabled it appears.
        $withLuclin = $this->getJson('/api/zones?expansions[]=0&expansions[]=3');
        $this->assertContains('luclinzone', array_column($withLuclin->json(), 'short'));
    }

    public function test_map_exits_returns_exit_triggers_and_arrival_landings_per_neighbour(): void
    {
        $response = $this->getJson('/api/map/nro/exits');

        $response->assertOk();

        // `exit` = the zone-line trigger here, leaving toward each neighbour (edge from=nro).
        $response->assertJsonPath('oasis.exit', ['x' => 100, 'y' => -1600]);
        $response->assertJsonPath('ecommons.exit', ['x' => -1400, 'y' => -100]);

        // `enter` = where you land here arriving from a neighbour (reverse edge's target coord).
        // Only oasis->nro carries landing coords in the fixture.
        $response->assertJsonPath('oasis.enter', ['x' => 90, 'y' => -1550]);
        $this->assertArrayNotHasKey('enter', $response->json('ecommons'));

        // Boat/teleport edges are excluded — walk zone lines only.
        $this->assertArrayNotHasKey('butcher', $response->json());
    }

    public function test_map_exits_rejects_malformed_zone_names(): void
    {
        $this->getJson('/api/map/..%2f..%2fsecret/exits')->assertNotFound();
    }

    public function test_map_paths_returns_baked_navmesh_polylines_for_a_zone(): void
    {
        config(['eqrouter.paths_path' => __DIR__.'/../Fixtures/paths.json']);

        $response = $this->getJson('/api/map/blackburrow/paths');

        $response->assertOk();
        $response->assertJsonPath('everfrost|qeytoqrg.len', 1091);
        $this->assertCount(4, $response->json('everfrost|qeytoqrg.line'));
        $this->assertSame([340, -95], $response->json('everfrost|qeytoqrg.line.0'));
    }

    public function test_map_paths_is_empty_for_an_unbaked_zone(): void
    {
        config(['eqrouter.paths_path' => __DIR__.'/../Fixtures/paths.json']);

        $this->getJson('/api/map/oasis/paths')->assertOk()->assertExactJson([]);
    }

    public function test_route_step_carries_baked_run_length_when_a_path_is_baked(): void
    {
        config(['eqrouter.paths_path' => __DIR__.'/../Fixtures/paths.json']);

        // qeynos2 -> qeytoqrg -> northkarana: the run across qeytoqrg (step 1) is baked under
        // "qeynos2|northkarana", so that step gains a runLen for the ETA; step 0 has none.
        $response = $this->getJson('/api/route?from=qeynos2&to=northkarana&server=live');

        $response->assertOk();
        $response->assertJsonPath('steps.1.runLen', 1500);
        $this->assertArrayNotHasKey('runLen', $response->json('steps.0'));
    }

    public function test_route_step_carries_landing_coords_when_the_edge_has_them(): void
    {
        // The fixture's oasis->nro edge carries target landing coords (tx/ty); the step exposes
        // them so the client can estimate the cross-zone run and draw the path line.
        $response = $this->getJson('/api/route?from=oasis&to=nro&server=live');

        $response->assertOk();
        $response->assertJsonPath('steps.0.tx', 90);
        $response->assertJsonPath('steps.0.ty', -1550);
    }

    public function test_multi_hop_walk_route_returns_ordered_typed_steps(): void
    {
        $response = $this->getJson('/api/route?from=qeynos2&to=freportw&server=live');

        $response->assertOk();
        $steps = $response->json('steps');

        $this->assertGreaterThan(1, count($steps));
        $this->assertSame('qeynos2', $steps[0]['from']);
        $this->assertSame('freportw', $steps[array_key_last($steps)]['to']);
        $this->assertSame('walk', $steps[0]['type']);

        // Steps chain head-to-tail.
        for ($i = 1; $i < count($steps); $i++) {
            $this->assertSame($steps[$i - 1]['to'], $steps[$i]['from']);
        }
    }

    public function test_safest_mode_prefers_boats_over_a_shorter_overland_walk(): void
    {
        config(['eqrouter.graph_path' => __DIR__.'/../Fixtures/safest_graph.json']);

        // Fastest: the single overland walk wins.
        $fastest = $this->getJson('/api/route?from=start&to=dest&server=live&mode=fastest');
        $fastest->assertOk();
        $this->assertSame(1, $fastest->json('hops'));
        $this->assertSame('walk', $fastest->json('steps.0.type'));

        // Safest: overland is penalised, so the all-boat detour wins despite more hops.
        $safest = $this->getJson('/api/route?from=start&to=dest&server=live&mode=safest');
        $safest->assertOk();
        $this->assertSame(3, $safest->json('hops'));
        $this->assertSame(['boat', 'boat', 'boat'], array_column($safest->json('steps'), 'type'));
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->getJson('/api/route?from=qeynos2&to=freportw&server=live&mode=cheapest')
            ->assertStatus(422);
    }

    public function test_safest_routes_a_kos_race_around_a_guard_city(): void
    {
        config([
            'eqrouter.graph_path' => __DIR__.'/../Fixtures/kos_graph.json',
            'eqrouter.factions_path' => __DIR__.'/../Fixtures/factions.json',
        ]);

        // Fastest ignores faction: an Iksar takes the short path straight through the guard city,
        // but the leg is flagged KOS so the UI can warn.
        $fast = $this->getJson('/api/route?from=start&to=dest&server=live&mode=fastest&race=IKS');
        $fast->assertOk();
        $this->assertContains('goodcity', array_column($fast->json('steps'), 'to'));
        $this->assertTrue(collect($fast->json('steps'))->firstWhere('to', 'goodcity')['kos']);

        // Safest detours the Iksar around the KOS city despite the extra hop.
        $safe = $this->getJson('/api/route?from=start&to=dest&server=live&mode=safest&race=IKS');
        $safe->assertOk();
        $this->assertNotContains('goodcity', array_column($safe->json('steps'), 'to'));

        // A race the guards tolerate has no problem, so safest still takes the short path.
        $human = $this->getJson('/api/route?from=start&to=dest&server=live&mode=safest&race=HUM');
        $human->assertOk();
        $this->assertContains('goodcity', array_column($human->json('steps'), 'to'));
        $this->assertFalse(collect($human->json('steps'))->firstWhere('to', 'goodcity')['kos']);
    }

    public function test_safest_routes_an_underlevelled_traveler_around_a_dangerous_zone(): void
    {
        config([
            'eqrouter.graph_path' => __DIR__.'/../Fixtures/kos_graph.json',
            'eqrouter.factions_path' => __DIR__.'/../Fixtures/danger_factions.json',
        ]);

        // Fastest takes the short path through the level-40 zone regardless of level, but the leg is
        // flagged with the zone's danger level so the UI can warn.
        $fast = $this->getJson('/api/route?from=start&to=dest&server=live&mode=fastest&level=1');
        $this->assertContains('goodcity', array_column($fast->json('steps'), 'to'));
        $this->assertSame(40, collect($fast->json('steps'))->firstWhere('to', 'goodcity')['danger']);

        // A traveler who out-levels the zone gets no danger flag on that leg.
        $safeLevel = $this->getJson('/api/route?from=start&to=dest&server=live&mode=fastest&level=60');
        $this->assertArrayNotHasKey('danger', collect($safeLevel->json('steps'))->firstWhere('to', 'goodcity'));

        // Safest detours a level-1 around the dangerous zone despite the extra hop.
        $low = $this->getJson('/api/route?from=start&to=dest&server=live&mode=safest&level=1');
        $low->assertOk();
        $this->assertNotContains('goodcity', array_column($low->json('steps'), 'to'));

        // A traveler who out-levels the zone has nothing to fear, so safest keeps the short path.
        $high = $this->getJson('/api/route?from=start&to=dest&server=live&mode=safest&level=60');
        $high->assertOk();
        $this->assertContains('goodcity', array_column($high->json('steps'), 'to'));
    }

    public function test_deity_changes_whether_a_zone_is_kos(): void
    {
        config([
            'eqrouter.graph_path' => __DIR__.'/../Fixtures/kos_graph.json',
            'eqrouter.factions_path' => __DIR__.'/../Fixtures/factions.json',
        ]);

        // A Human is fine in the guard city — until they worship Innoruuk, whom the guards loathe.
        $agnostic = $this->getJson('/api/route?from=start&to=dest&server=live&race=HUM&deity=AGNOSTIC');
        $this->assertFalse(collect($agnostic->json('steps'))->firstWhere('to', 'goodcity')['kos']);

        $heretic = $this->getJson('/api/route?from=start&to=dest&server=live&race=HUM&deity=INNORUUK');
        $this->assertTrue(collect($heretic->json('steps'))->firstWhere('to', 'goodcity')['kos']);
    }

    public function test_invalid_race_is_rejected(): void
    {
        $this->getJson('/api/route?from=qeynos2&to=freportw&server=live&race=ELDER')
            ->assertStatus(422);
    }

    public function test_druid_gets_port_only_at_required_level(): void
    {
        $low = $this->getJson('/api/route?from=oasis&to=northkarana&server=live&class=DRU&level=20');
        $low->assertOk();
        $this->assertGreaterThan(1, $low->json('hops'));

        $high = $this->getJson('/api/route?from=oasis&to=northkarana&server=live&class=DRU&level=50');
        $high->assertOk();
        $this->assertSame(1, $high->json('hops'));
        $this->assertSame('teleport', $high->json('steps.0.type'));
    }

    public function test_server_expansion_set_excludes_later_expansion_teleport(): void
    {
        // Wizard port to freportw is expansion 4 (PoP) — out of P99 (Velious), in on Live.
        $p99 = $this->getJson('/api/route?from=oasis&to=freportw&server=p99&class=WIZ&level=50');
        $p99->assertOk();
        $this->assertGreaterThan(1, $p99->json('hops'));

        $live = $this->getJson('/api/route?from=oasis&to=freportw&server=live&class=WIZ&level=50');
        $live->assertOk();
        $this->assertSame(1, $live->json('hops'));
        $this->assertSame('teleport', $live->json('steps.0.type'));
    }

    public function test_custom_expansion_set_overrides_server_default(): void
    {
        $without = $this->getJson('/api/route?from=oasis&to=freportw&class=WIZ&level=50&expansions[]=0&expansions[]=1&expansions[]=2');
        $without->assertOk();
        $this->assertGreaterThan(1, $without->json('hops'));

        $with = $this->getJson('/api/route?from=oasis&to=freportw&class=WIZ&level=50&expansions[]=0&expansions[]=2&expansions[]=4');
        $with->assertOk();
        $this->assertSame(1, $with->json('hops'));
    }

    public function test_tlp_server_requires_explicit_expansions(): void
    {
        // The 'tlp' profile has no default expansion set; omitting expansions is a validation error.
        $this->getJson('/api/route?from=qeynos2&to=freportw&server=tlp')
            ->assertStatus(422);

        $this->getJson('/api/route?from=qeynos2&to=freportw&server=tlp&expansions[]=0')
            ->assertOk();
    }

    public function test_per_server_delta_customizes_the_graph(): void
    {
        config([
            'eqrouter.deltas_path' => __DIR__.'/../Fixtures/deltas',
            'eqrouter.servers.testserver' => ['label' => 'Test', 'expansions' => [0, 1, 2], 'deltas' => ['testdelta']],
        ]);

        // The delta adds "customzone" + a port to it; only this server's graph has it.
        $custom = $this->getJson('/api/route?from=qeynos2&to=customzone&server=testserver');
        $custom->assertOk();
        $this->assertSame(1, $custom->json('hops'));
        $this->assertSame('customzone', $custom->json('steps.0.to'));

        // A server without the delta doesn't know the zone at all.
        $this->getJson('/api/route?from=qeynos2&to=customzone&server=live')
            ->assertStatus(422);
    }

    public function test_servers_endpoint_lists_profiles_and_expansion_catalog(): void
    {
        $response = $this->getJson('/api/servers');

        $response->assertOk();
        $keys = array_column($response->json('servers'), 'key');
        $this->assertContains('p99', $keys);
        $this->assertContains('live', $keys);
        $this->assertContains(['id' => 0, 'name' => 'Classic'], $response->json('expansions'));
        $this->assertSame('live', $response->json('default'));
    }

    public function test_unknown_server_is_rejected(): void
    {
        $this->getJson('/api/route?from=qeynos2&to=freportw&server=nope')
            ->assertStatus(422);
    }

    public function test_unknown_zone_is_rejected(): void
    {
        $this->getJson('/api/route?from=qeynos2&to=narnia&server=live')
            ->assertStatus(422);
    }

    public function test_disconnected_pair_returns_404(): void
    {
        $this->getJson('/api/route?from=qeynos2&to=permafrost&server=live')
            ->assertStatus(404);
    }
}

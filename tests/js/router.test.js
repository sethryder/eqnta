// Port of tests/Feature/RoutingTest.php + EraIsolationTest.php + tests/Unit/DeltaMatchTest.php
// + GraphIntegrityTest.php against the JS router. Fixtures are the same files the PHP tests use.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { applyDelta, deltaMatches, mergeDeltas, normalizeDelta, createRouter } from '../../src/router.js';
import { committedData, config, fixture, fixtureRouter, json } from './helpers.js';

const shorts = (steps) => steps.map((s) => s.to);
const stepTo = (steps, to) => steps.find((s) => s.to === to);

describe('servers', () => {
    test('frostreaver TLP unlocks expansions automatically by date', () => {
        const r = fixtureRouter();
        const on = (date) => r.serversMeta(date).servers.find((s) => s.key === 'frostreaver').expansions;

        assert.deepEqual(on('2026-01-01'), [0]);
        assert.deepEqual(on('2026-05-27'), [0, 1, 2]); // RoK + SoV together, inclusive on the day
        assert.deepEqual(on('2026-09-30'), [0, 1, 2]);
        assert.deepEqual(on('2026-10-01'), [0, 1, 2, 3]); // Luclin
        assert.deepEqual(on('2031-01-01'), Array.from({ length: 33 }, (_, i) => i)); // no Special/99 on a TLP
    });

    test('named TLP servers are grouped and resolve to current era', () => {
        const servers = fixtureRouter().serversMeta('2026-06-26').servers;
        const by = (key) => servers.find((s) => s.key === key);

        assert.equal(by('frostreaver').group, null);
        assert.equal(by('teek').group, 'tlp');
        assert.deepEqual(by('agnarr').expansions, [0, 1, 2, 3, 4, 5, 6]);
        assert.equal(Math.max(...by('yelinak').expansions), 23);
        assert.equal(Math.max(...by('mangler').expansions), 32);
    });

    test('servers meta lists profiles and expansion catalog', () => {
        const meta = fixtureRouter().serversMeta();
        const keys = meta.servers.map((s) => s.key);

        assert.ok(keys.includes('p99'));
        assert.ok(keys.includes('live'));
        assert.ok(meta.expansions.some((e) => e.id === 0 && e.name === 'Classic'));
        assert.equal(meta.default, 'legends');
        assert.ok(meta.classes.find((c) => c.code === 'DRU').port);
        assert.equal(meta.classes.find((c) => c.code === 'WAR').port, undefined);
    });

    test('unknown server is rejected', () => {
        assert.throws(() => fixtureRouter().findRoute({ from: 'qeynos2', to: 'freportw', server: 'nope' }), RangeError);
        assert.throws(() => fixtureRouter().zones('nope'), RangeError);
    });
});

describe('zones', () => {
    test('returns seed zones sorted by long name with a continent', () => {
        const zones = fixtureRouter().zones('live');
        const qeynos = zones.find((z) => z.short === 'qeynos2');

        assert.equal(qeynos.long, 'North Qeynos');
        assert.ok('continent' in qeynos);
        const longs = zones.map((z) => z.long);
        assert.deepEqual(longs, [...longs].sort());
    });

    test('narrows to the enabled expansions', () => {
        const r = fixtureRouter({ graph: 'era_graph.json' });

        const classic = r.zones('live', [0]).map((z) => z.short);
        assert.ok(classic.includes('classiczone'));
        assert.ok(!classic.includes('luclinzone'));

        assert.ok(r.zones('live', [0, 3]).map((z) => z.short).includes('luclinzone'));
    });

    test('null expansions returns every zone', () => {
        const r = fixtureRouter({ graph: 'era_graph.json' });
        assert.ok(r.zones('live', null).map((z) => z.short).includes('luclinzone'));
    });
});

describe('maps', () => {
    test('map exits returns exit triggers and arrival landings per neighbour', () => {
        const exits = fixtureRouter().mapExits('live', 'nro');

        assert.deepEqual(exits.oasis.exit, { x: 100, y: -1600 });
        assert.deepEqual(exits.ecommons.exit, { x: -1400, y: -100 });
        assert.deepEqual(exits.oasis.enter, { x: 90, y: -1550 }); // only oasis->nro carries landing coords
        assert.ok(!('enter' in exits.ecommons));
        assert.ok(!('butcher' in exits)); // boat/teleport edges excluded
    });

    test('map exits for an unknown zone is empty', () => {
        assert.deepEqual(fixtureRouter().mapExits('live', '../../secret'), {});
    });

    test('map paths returns baked navmesh polylines for a zone', () => {
        const paths = fixtureRouter({ paths: 'paths.json' }).mapPaths('blackburrow');

        assert.equal(paths['everfrost|qeytoqrg'].len, 1091);
        assert.equal(paths['everfrost|qeytoqrg'].line.length, 4);
        assert.deepEqual(paths['everfrost|qeytoqrg'].line[0], [340, -95]);
    });

    test('map paths is empty for an unbaked zone', () => {
        assert.deepEqual(fixtureRouter({ paths: 'paths.json' }).mapPaths('oasis'), {});
        assert.deepEqual(fixtureRouter().mapPaths('blackburrow'), {}); // nothing loaded at all
    });
});

describe('routes', () => {
    test('step carries baked run length when a path is baked', () => {
        const { steps } = fixtureRouter({ paths: 'paths.json' }).findRoute({ from: 'qeynos2', to: 'northkarana', server: 'live' });

        assert.equal(steps[1].runLen, 1500);
        assert.ok(!('runLen' in steps[0]));
    });

    test('step carries landing coords when the edge has them', () => {
        const { steps } = fixtureRouter().findRoute({ from: 'oasis', to: 'nro', server: 'live' });

        assert.equal(steps[0].tx, 90);
        assert.equal(steps[0].ty, -1550);
    });

    test('multi-hop walk route returns ordered typed steps', () => {
        const { steps } = fixtureRouter().findRoute({ from: 'qeynos2', to: 'freportw', server: 'live' });

        assert.ok(steps.length > 1);
        assert.equal(steps[0].from, 'qeynos2');
        assert.equal(steps.at(-1).to, 'freportw');
        assert.equal(steps[0].type, 'walk');
        for (let i = 1; i < steps.length; i++) { assert.equal(steps[i].from, steps[i - 1].to); }
    });

    test('same zone is an empty route', () => {
        assert.deepEqual(fixtureRouter().findRoute({ from: 'oasis', to: 'oasis', server: 'live' }), { steps: [], hops: 0, weight: 0 });
    });

    test('flags legs into post-RoF expansions as incomplete', () => {
        const r = fixtureRouter({ graph: 'postrof_graph.json' });

        const post = r.findRoute({ from: 'qeynos2', to: 'newzone', server: 'live' });
        assert.equal(post.incomplete, true);
        assert.equal(post.steps[0].incomplete, true);

        const old = r.findRoute({ from: 'qeynos2', to: 'oldzone', server: 'live' });
        assert.equal(old.incomplete, false);
        assert.ok(!('incomplete' in old.steps[0]));
    });

    test('safest mode prefers boats over a shorter overland walk', () => {
        const r = fixtureRouter({ graph: 'safest_graph.json' });

        const fastest = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'fastest' });
        assert.equal(fastest.hops, 1);
        assert.equal(fastest.steps[0].type, 'walk');

        const safest = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'safest' });
        assert.equal(safest.hops, 3);
        assert.deepEqual(safest.steps.map((s) => s.type), ['boat', 'boat', 'boat']);
    });

    test('invalid mode is rejected', () => {
        assert.throws(() => fixtureRouter().findRoute({ from: 'qeynos2', to: 'freportw', server: 'live', mode: 'cheapest' }), RangeError);
    });

    test('safest routes a KOS race around a guard city', () => {
        const r = fixtureRouter({ graph: 'kos_graph.json', factions: 'factions.json' });

        const fast = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'fastest', race: 'IKS' });
        assert.ok(shorts(fast.steps).includes('goodcity'));
        assert.equal(stepTo(fast.steps, 'goodcity').kos, true);

        const safe = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'safest', race: 'IKS' });
        assert.ok(!shorts(safe.steps).includes('goodcity'));

        const human = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'safest', race: 'HUM' });
        assert.ok(shorts(human.steps).includes('goodcity'));
        assert.equal(stepTo(human.steps, 'goodcity').kos, false);
    });

    test('safest routes an under-levelled traveler around a dangerous zone', () => {
        const r = fixtureRouter({ graph: 'kos_graph.json', factions: 'danger_factions.json' });

        const fast = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'fastest', level: 1 });
        assert.ok(shorts(fast.steps).includes('goodcity'));
        assert.equal(stepTo(fast.steps, 'goodcity').danger, 40);

        const safeLevel = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'fastest', level: 60 });
        assert.ok(!('danger' in stepTo(safeLevel.steps, 'goodcity')));

        const low = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'safest', level: 1 });
        assert.ok(!shorts(low.steps).includes('goodcity'));

        const high = r.findRoute({ from: 'start', to: 'dest', server: 'live', mode: 'safest', level: 60 });
        assert.ok(shorts(high.steps).includes('goodcity'));
    });

    test('deity changes whether a zone is KOS', () => {
        const r = fixtureRouter({ graph: 'kos_graph.json', factions: 'factions.json' });

        const agnostic = r.findRoute({ from: 'start', to: 'dest', server: 'live', race: 'HUM', deity: 'AGNOSTIC' });
        assert.equal(stepTo(agnostic.steps, 'goodcity').kos, false);

        const heretic = r.findRoute({ from: 'start', to: 'dest', server: 'live', race: 'HUM', deity: 'INNORUUK' });
        assert.equal(stepTo(heretic.steps, 'goodcity').kos, true);
    });

    test('invalid race, class, deity, level, expansion are rejected', () => {
        const r = fixtureRouter();
        const base = { from: 'qeynos2', to: 'freportw', server: 'live' };
        assert.throws(() => r.findRoute({ ...base, race: 'ELDER' }), RangeError);
        assert.throws(() => r.findRoute({ ...base, class: 'JEDI' }), RangeError);
        assert.throws(() => r.findRoute({ ...base, deity: 'CTHULHU' }), RangeError);
        assert.throws(() => r.findRoute({ ...base, level: 0 }), RangeError);
        assert.throws(() => r.findRoute({ ...base, level: config.maxLevel + 1 }), RangeError);
        assert.throws(() => r.findRoute({ ...base, level: 1.5 }), RangeError);
        assert.throws(() => r.findRoute({ ...base, expansions: [0, 77] }), RangeError);
        assert.throws(() => r.findRoute({ ...base, rituals: ['JEDI'] }), RangeError);
        // Empty strings (unset UI selects) are "not given", not invalid.
        assert.ok(r.findRoute({ ...base, race: '', class: '', deity: '' }));
    });

    test('druid gets port only at required level', () => {
        const r = fixtureRouter();

        const low = r.findRoute({ from: 'oasis', to: 'northkarana', server: 'live', class: 'DRU', level: 20 });
        assert.ok(low.hops > 1);

        const high = r.findRoute({ from: 'oasis', to: 'northkarana', server: 'live', class: 'DRU', level: 50 });
        assert.equal(high.hops, 1);
        assert.equal(high.steps[0].type, 'teleport');
        assert.equal(high.steps[0].class, 'DRU');
        assert.ok(high.steps[0].spell);
    });

    test('legends ritual grants port to non-porting class', () => {
        const r = fixtureRouter();

        const without = r.findRoute({ from: 'oasis', to: 'northkarana', server: 'legends', class: 'WAR', level: 20 });
        assert.ok(without.hops > 1);

        const withRitual = r.findRoute({ from: 'oasis', to: 'northkarana', server: 'legends', class: 'WAR', level: 20, rituals: ['DRU'] });
        assert.equal(withRitual.hops, 1);
        assert.equal(withRitual.steps[0].type, 'teleport');
    });

    test('ritual param ignored on servers without the mechanic', () => {
        const route = fixtureRouter().findRoute({ from: 'oasis', to: 'northkarana', server: 'live', class: 'WAR', level: 20, rituals: ['DRU'] });
        assert.ok(route.hops > 1);
    });

    test('server expansion set excludes later-expansion teleport', () => {
        const r = fixtureRouter();

        const p99 = r.findRoute({ from: 'oasis', to: 'freportw', server: 'p99', class: 'WIZ', level: 50 });
        assert.ok(p99.hops > 1);

        const live = r.findRoute({ from: 'oasis', to: 'freportw', server: 'live', class: 'WIZ', level: 50 });
        assert.equal(live.hops, 1);
        assert.equal(live.steps[0].type, 'teleport');
    });

    test('custom expansion set overrides server default', () => {
        const r = fixtureRouter();

        const without = r.findRoute({ from: 'oasis', to: 'freportw', class: 'WIZ', level: 50, expansions: [0, 1, 2] });
        assert.ok(without.hops > 1);

        const withPop = r.findRoute({ from: 'oasis', to: 'freportw', class: 'WIZ', level: 50, expansions: [0, 2, 4] });
        assert.equal(withPop.hops, 1);
    });

    test('tlp server requires explicit expansions', () => {
        const r = fixtureRouter();
        assert.throws(() => r.findRoute({ from: 'qeynos2', to: 'freportw', server: 'tlp' }), RangeError);
        assert.ok(r.findRoute({ from: 'qeynos2', to: 'freportw', server: 'tlp', expansions: [0] }));
    });

    test('per-server delta customizes the graph', () => {
        const r = fixtureRouter({
            deltas: { testdelta: fixture('deltas/testdelta.json') },
            config: { servers: { ...config.servers, testserver: { label: 'Test', expansions: [0, 1, 2], deltas: ['testdelta'] } } },
        });

        const custom = r.findRoute({ from: 'qeynos2', to: 'customzone', server: 'testserver' });
        assert.equal(custom.hops, 1);
        assert.equal(custom.steps[0].to, 'customzone');

        assert.throws(() => r.findRoute({ from: 'qeynos2', to: 'customzone', server: 'live' }), RangeError);
    });

    test('a missing delta file is ignored', () => {
        const r = fixtureRouter({
            deltas: {},
            config: { servers: { ...config.servers, ghost: { label: 'Ghost', expansions: [0], deltas: ['nothere'] } } },
        });
        assert.ok(r.zones('ghost').length > 0);
    });

    test('unknown zone is rejected', () => {
        assert.throws(() => fixtureRouter().findRoute({ from: 'qeynos2', to: 'narnia', server: 'live' }), RangeError);
    });

    test('disconnected pair returns null', () => {
        assert.equal(fixtureRouter().findRoute({ from: 'qeynos2', to: 'permafrost', server: 'live' }), null);
    });
});

describe('era isolation (committed graph + overrides + postrof, as eq:build-graph merges them)', () => {
    const data = committedData();
    const delta = mergeDeltas(normalizeDelta(json('database/eq/overrides.json')), normalizeDelta(json('database/eq/postrof.json')));
    const [zones, edges] = applyDelta(data.graph.zones, data.graph.edges, delta);
    const r = createRouter({ ...data, graph: { zones, edges } });

    test('post-RoF zones absent for p99 and legends but present for live', () => {
        const postRof = ['ethernere', 'laurioninn', 'stratos', 'overtheretwo', 'eastwastestwo'];

        for (const server of ['p99', 'legends']) {
            const list = r.zones(server).map((z) => z.short);
            assert.ok(list.includes('qeynos2'), `${server} should still have classic zones`);
            for (const z of postRof) { assert.ok(!list.includes(z), `${server} must NOT expose post-RoF zone ${z}`); }
        }

        const live = r.zones('live').map((z) => z.short);
        assert.ok(live.includes('ethernere'));   // CoTF, exp 20
        assert.ok(live.includes('laurioninn'));  // Laurion's Song, exp 30
        assert.ok(live.includes('spite'));       // Shattering of Ro, exp 32
    });

    test('p99 cannot route into a post-RoF zone', () => {
        assert.equal(r.findRoute({ from: 'qeynos2', to: 'ethernere', server: 'p99' }), null);
    });

    test('p99 classic route still resolves and is never flagged incomplete', () => {
        const res = r.findRoute({ from: 'qeynos2', to: 'freportw', server: 'p99' });
        assert.equal(res.incomplete, false);
        assert.ok(res.steps.length > 0);
    });
});

describe('delta matching', () => {
    test('partial spec matches on listed keys only', () => {
        const edge = { from: 'a', to: 'b', type: 'walk', expansion: 0 };
        assert.equal(deltaMatches(edge, [{ from: 'a', to: 'b' }]), true);
        assert.equal(deltaMatches(edge, [{ from: 'a', to: 'b', type: 'walk' }]), true);
    });

    test('spec with any mismatched key does not match', () => {
        const edge = { from: 'a', to: 'b', type: 'walk' };
        assert.equal(deltaMatches(edge, [{ from: 'a', to: 'b', type: 'boat' }]), false);
        assert.equal(deltaMatches(edge, [{ from: 'a', to: 'c' }]), false);
    });

    test('matches when any spec in the list matches', () => {
        const edge = { from: 'a', to: 'b', type: 'walk' };
        assert.equal(deltaMatches(edge, [{ from: 'x', to: 'y' }, { from: 'a', to: 'b' }]), true);
        assert.equal(deltaMatches(edge, []), false);
    });

    test('apply adds zones, edges and removes matching', () => {
        const [zones, edges] = applyDelta(
            [{ id: 1, short: 'a', long: 'A' }],
            [{ from: 'a', to: 'b', type: 'walk' }],
            { zones: [{ id: 2, short: 'c', long: 'C' }], add: [{ from: 'a', to: 'c', type: 'boat' }], remove: [{ from: 'a', to: 'b' }] },
        );
        assert.deepEqual(zones.map((z) => z.short), ['a', 'c']);
        assert.deepEqual(edges, [{ from: 'a', to: 'c', type: 'boat' }]);
    });

    test('normalize treats a bare list as additions and a missing file as empty', () => {
        assert.deepEqual(normalizeDelta([{ from: 'a', to: 'b' }]), { zones: [], add: [{ from: 'a', to: 'b' }], remove: [] });
        assert.deepEqual(normalizeDelta(undefined), { zones: [], add: [], remove: [] });
    });
});

describe('graph integrity (committed database/eq/graph.json)', () => {
    const { graph, continents } = committedData();
    // Mirrors config/eqrouter.php zone_point_hubs (build-time config, not shipped to the browser).
    const hubs = ['nexus', 'poknowledge'];

    test('hub zones are never reached on foot', () => {
        for (const hub of hubs) {
            const walkToHub = graph.edges.filter((e) => e.type === 'walk' && (e.from === hub || e.to === hub));
            assert.equal(walkToHub.length, 0, `Found walk edges touching hub zone '${hub}'`);
        }
    });

    test('no walk edge crosses between continents', () => {
        const crossings = [];
        for (const e of graph.edges) {
            if (e.type !== 'walk' || (e.expansion ?? 0) > 2) { continue; }
            const from = continents[e.from] ?? null, to = continents[e.to] ?? null;
            if (from !== null && to !== null && from !== to) { crossings.push(`${e.from}(${from}) → ${e.to}(${to})`); }
        }
        assert.deepEqual(crossings, [], 'Walk edges cross continents (should be boats or junk)');
    });

    test('graph has no duplicate edges', () => {
        const keys = graph.edges.map((e) => `${e.from}\0${e.to}\0${e.type}\0${e.class ?? ''}`);
        assert.equal(new Set(keys).size, keys.length, 'Duplicate edges in graph.json');
    });
});

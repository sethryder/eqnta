// Browser port of app/Services/Eq/{Router,GraphDelta,FactionService}.php plus the request-shaping
// bits of RoutingController.php. Pure: everything comes in through `data` (see data.js) so tests
// can hand it fixtures. The graph is ~600 zones, so edges are filtered per call and the Dijkstra is
// the same plain O(V²) scan as the PHP — identical tie-breaking, identical routes.
import * as defaultConfig from './config.js';

// ---- GraphDelta -----------------------------------------------------------------------------

/** Normalise a delta file: {zones, add, remove}; a bare edge list means additions. */
export function normalizeDelta(raw) {
    if (!raw) { return { zones: [], add: [], remove: [] }; }
    if (Array.isArray(raw)) { return { zones: [], add: raw, remove: [] }; }
    return { zones: raw.zones ?? [], add: raw.add ?? [], remove: raw.remove ?? [] };
}

export function mergeDeltas(...deltas) {
    const merged = { zones: [], add: [], remove: [] };
    for (const d of deltas) {
        merged.zones.push(...(d.zones ?? []));
        merged.add.push(...(d.add ?? []));
        merged.remove.push(...(d.remove ?? []));
    }
    return merged;
}

export function deltaIsEmpty(delta) {
    return !(delta.zones?.length || delta.add?.length || delta.remove?.length);
}

/** True when every key a spec lists equals the edge's value, for any spec in the list. */
export function deltaMatches(edge, specs) {
    return specs.some((spec) => Object.keys(spec).every((key) => (edge[key] ?? null) === spec[key]));
}

/** Apply a delta: drop matching edges, append added edges, append custom zones (deduped by short). */
export function applyDelta(zones, edges, delta) {
    if (delta.remove?.length) { edges = edges.filter((e) => !deltaMatches(e, delta.remove)); }
    edges = [...edges, ...(delta.add ?? [])];
    const known = new Set(zones.map((z) => z.short));
    zones = [...zones];
    for (const zone of delta.zones ?? []) {
        if (!known.has(zone.short)) { known.add(zone.short); zones.push(zone); }
    }
    return [zones, edges];
}

// ---- Router ---------------------------------------------------------------------------------

export function utcToday() {
    return new Date().toISOString().slice(0, 10);
}

/**
 * @param {object} data  { graph: {zones, edges}, factions, continents, deltas: {name: delta}, paths? }
 * @param {object} config  the config.js module (overridable for tests)
 */
export function createRouter(data, config = defaultConfig) {
    const resolved = Object.create(null); // server key → delta-resolved graph
    const factions = data.factions ?? { threshold: config.kosThreshold, factions: {}, zones: {}, levels: {} };

    /** Enabled expansions for a server today: static list, resolved unlock schedule, or null (caller picks). */
    function serverExpansions(server, today = utcToday()) {
        if (!server.unlocks) { return server.expansions ?? null; }
        const enabled = [0]; // Classic is always on from launch.
        for (const [ordinal, date] of Object.entries(server.unlocks)) {
            if (date <= today) { enabled.push(Number(ordinal)); }
        }
        return enabled.sort((a, b) => a - b);
    }

    /** The /api/servers payload: profiles, expansion catalog, and the race/class/deity pick lists. */
    function serversMeta(today = utcToday()) {
        const codeList = (map) => Object.entries(map).map(([code, e]) => (e.port ? { code, label: e.label, port: true } : { code, label: e.label }));
        return {
            servers: Object.entries(config.servers).map(([key, s]) => ({
                key, label: s.label, group: s.group ?? null, expansions: serverExpansions(s, today), rituals: s.rituals ?? null,
            })),
            expansions: Object.entries(config.expansions).map(([id, name]) => ({ id: Number(id), name })),
            default: config.defaultServer,
            races: codeList(config.races),
            classes: codeList(config.classes),
            deities: codeList(config.deities),
        };
    }

    /** Server key → {key, config (with resolved expansions), delta}. Throws on an unknown key. */
    function resolveServer(key = config.defaultServer, today = utcToday()) {
        if (typeof key !== 'string' || !Object.hasOwn(config.servers, key)) { throw new RangeError('Unknown server.'); }
        const server = config.servers[key];
        const delta = mergeDeltas(...(server.deltas ?? []).map((name) => normalizeDelta(data.deltas?.[name])));
        return { key, config: { ...server, expansions: serverExpansions(server, today) }, delta };
    }

    function resolvedGraph(serverKey = config.defaultServer) {
        if (!resolved[serverKey]) {
            const { delta } = resolveServer(serverKey);
            const base = data.graph;
            if (deltaIsEmpty(delta)) {
                resolved[serverKey] = base;
            } else {
                const [zones, edges] = applyDelta(base.zones, base.edges, delta);
                resolved[serverKey] = { zones, edges };
            }
        }
        return resolved[serverKey];
    }

    /**
     * Zone list for a server, sorted by long name. `expansions` undefined → the server's current
     * era; null → every zone; a list → only zones touched by an edge in that set.
     */
    function zones(serverKey = config.defaultServer, expansions = undefined) {
        const server = resolveServer(serverKey);
        if (expansions === undefined) { expansions = server.config.expansions; }
        const graph = resolvedGraph(serverKey);
        const continents = data.continents ?? {};

        let list = graph.zones.map((z) => ({
            short: z.short,
            long: z.long,
            continent: z.continent ?? continents[z.short] ?? null,
        }));

        if (expansions !== null) {
            const enabled = new Set(expansions.map(Number));
            const reachable = new Set();
            for (const edge of graph.edges) {
                if (!enabled.has(edge.expansion ?? 0)) { continue; }
                if (edge.from !== undefined && edge.from !== '*') { reachable.add(edge.from); }
                if (edge.to !== undefined) { reachable.add(edge.to); }
            }
            list = list.filter((z) => reachable.has(z.short));
        }

        // Byte-order compare, matching PHP strcmp (not locale-aware).
        return list.sort((a, b) => (a.long < b.long ? -1 : a.long > b.long ? 1 : 0));
    }

    // ---- factions

    function modifierKeys(race, klass, deity) {
        const keys = [];
        if (race && config.races[race]) { keys.push(`r${config.races[race].id}`); }
        if (klass && config.classes[klass]) { keys.push(`c${config.classes[klass].id}`); }
        if (deity && config.deities[deity] && config.deities[deity].id !== 140) { keys.push(`d${config.deities[deity].id}`); }
        return keys;
    }

    /** Zones KOS for this race/class/deity: standing = base + modifiers, KOS at/below threshold. */
    function kosZones(race, klass, deity) {
        const keys = modifierKeys(race, klass, deity);
        const kos = new Set();
        if (!keys.length) { return kos; }
        const standing = {};
        for (const [zone, ids] of Object.entries(factions.zones ?? {})) {
            for (const id of ids) {
                if (standing[id] === undefined) {
                    const f = factions.factions?.[id];
                    standing[id] = keys.reduce((v, k) => v + (f?.mods?.[k] ?? 0), f?.base ?? 0);
                }
                if (standing[id] <= factions.threshold) { kos.add(zone); break; }
            }
        }
        return kos;
    }

    // ---- routing

    function buildAdjacency(graph, expansions, klass, level, rituals) {
        const enabled = new Set(expansions);
        const shorts = graph.zones.map((z) => z.short);
        const adjacency = {};
        for (const edge of graph.edges) {
            if (!enabled.has(edge.expansion ?? 0)) { continue; }
            // Class/level gate (teleport-type edges). A ritual class bypasses both.
            if (edge.class != null && !rituals.includes(edge.class)) {
                if (klass !== edge.class || level < (edge.min_level ?? 0)) { continue; }
            }
            const sources = edge.from === '*' ? shorts : [edge.from];
            for (const source of sources) {
                if (source !== edge.to) { (adjacency[source] ??= []).push(edge); }
            }
        }
        return adjacency;
    }

    function reconstruct(prev, from, to, weight) {
        const steps = [];
        let node = to;
        while (node !== from) {
            const { from: hopFrom, edge } = prev[node];
            const step = {
                from: hopFrom, to: node, type: edge.type, expansion: Number(edge.expansion ?? 0),
                x: edge.x ?? null, y: edge.y ?? null, z: edge.z ?? null,
            };
            // `!= null` mirrors PHP isset(): a JSON null is "absent", not a value.
            if (edge.tx != null && edge.ty != null) { step.tx = edge.tx; step.ty = edge.ty; }
            if (edge.spell != null) { step.spell = edge.spell; }
            if (edge.class != null) { step.class = edge.class; }
            steps.push(step);
            node = hopFrom;
        }
        steps.reverse();
        return { steps, hops: steps.length, weight };
    }

    /** Cheapest route or null. Same Dijkstra + tie-breaking as Router::find. */
    function find(graph, from, to, expansions, klass, level, mode, race, deity, rituals) {
        if (from === to) { return { steps: [], hops: 0, weight: 0 }; }

        const adjacency = buildAdjacency(graph, expansions, klass, level, rituals);
        const weights = mode === 'safest' ? config.weightsSafe : config.weights;
        const kos = kosZones(race, klass, deity);
        const kosPenalty = mode === 'safest' ? config.kosPenalty : 0;
        const levels = factions.levels ?? {};
        const dangerPerLevel = mode === 'safest' ? config.dangerPerLevel : 0;

        const dist = { [from]: 0 };
        const prev = {};
        const visited = new Set();
        let queue = [from];

        while (queue.length) {
            let current = null, best = Infinity;
            for (const node of queue) {
                if (!visited.has(node) && (dist[node] ?? Infinity) < best) { best = dist[node]; current = node; }
            }
            if (current === null || current === to) { break; }
            visited.add(current);
            queue = queue.filter((n) => n !== current);

            for (const edge of adjacency[current] ?? []) {
                const next = edge.to;
                if (visited.has(next)) { continue; }
                let cost = dist[current] + (weights[edge.type] ?? 10);
                if (kos.has(next)) { cost += kosPenalty; }
                const over = (levels[next] ?? 0) - level - config.dangerMargin;
                if (over > 0) { cost += over * dangerPerLevel; }
                if (cost < (dist[next] ?? Infinity)) {
                    dist[next] = cost;
                    prev[next] = { from: current, edge };
                    queue.push(next);
                }
            }
        }

        if (dist[to] === undefined) { return null; }
        const result = reconstruct(prev, from, to, dist[to]);

        let incomplete = false;
        for (const step of result.steps) {
            step.kos = kos.has(step.to);
            const zoneLevel = levels[step.to] ?? 0;
            if (zoneLevel > level + config.dangerMargin) { step.danger = zoneLevel; }
            if ((step.expansion ?? 0) >= config.limitedDataExpansion) { step.incomplete = true; incomplete = true; }
        }
        result.incomplete = incomplete;
        return result;
    }

    /** Baked navmesh length of each walk step's run across its `from` zone, when baked. */
    function attachRunLengths(steps) {
        const paths = data.paths ?? {};
        for (let i = 1; i < steps.length; i++) {
            if (steps[i].type !== 'walk') { continue; }
            const len = paths[steps[i].from]?.[`${steps[i - 1].from}|${steps[i].to}`]?.len;
            if (len !== undefined) { steps[i].runLen = len; }
        }
        return steps;
    }

    /**
     * The /api/route request. Throws RangeError on invalid input (the old 422); returns null when
     * no path exists (the old 404).
     *
     * @param {{from:string, to:string, server?:string, expansions?:number[], class?:string, level?:number,
     *          mode?:'fastest'|'safest', race?:string, deity?:string, rituals?:string[]}} req
     */
    function findRoute(req) {
        const server = resolveServer(req.server ?? config.defaultServer);
        const shorts = new Set(resolvedGraph(server.key).zones.map((z) => z.short));
        const inKeys = (map, v) => v == null || v === '' || Object.hasOwn(map, v);

        if (!shorts.has(req.from) || !shorts.has(req.to)) { throw new RangeError('Unknown zone.'); }
        if (!inKeys(config.classes, req.class)) { throw new RangeError('Unknown class.'); }
        if (!inKeys(config.races, req.race)) { throw new RangeError('Unknown race.'); }
        if (!inKeys(config.deities, req.deity)) { throw new RangeError('Unknown deity.'); }
        const mode = req.mode ?? 'fastest';
        if (!['fastest', 'safest'].includes(mode)) { throw new RangeError('Unknown mode.'); }
        const level = req.level == null || req.level === '' ? 1 : Number(req.level); // a cleared input means "unset"
        if (!Number.isInteger(level) || level < 1 || level > config.maxLevel) { throw new RangeError('Level out of range.'); }
        if (req.expansions?.some((id) => !Object.hasOwn(config.expansions, id))) { throw new RangeError('Unknown expansion.'); }
        if ((req.rituals ?? []).some((c) => !Object.hasOwn(config.classes, c))) { throw new RangeError('Unknown ritual class.'); }

        // Explicit expansions win; else the server's era; a TLP/custom profile needs the caller's list.
        let expansions = req.expansions?.length ? req.expansions.map(Number) : server.config.expansions;
        if (expansions == null) { throw new RangeError('This server requires an explicit expansions list.'); }

        // Rituals only exist on servers that declare them.
        const rituals = (req.rituals ?? []).filter((c) => (server.config.rituals ?? []).includes(c));

        const result = find(resolvedGraph(server.key), req.from, req.to, expansions, req.class || null, level, mode, req.race || null, req.deity || null, rituals);
        if (result === null) { return null; }
        result.steps = attachRunLengths(result.steps);
        return result;
    }

    // ---- maps

    /** Per neighbour: `exit` (zone-line trigger here, leaving) and `enter` (landing here, arriving). */
    function mapExits(serverKey, zone) {
        const points = {};
        for (const edge of resolvedGraph(serverKey).edges) {
            if ((edge.type ?? 'walk') !== 'walk') { continue; }
            if (edge.from === zone && edge.x != null && edge.y != null) {
                (points[edge.to] ??= {}).exit = { x: edge.x, y: edge.y };
            }
            if (edge.to === zone && edge.tx != null && edge.ty != null) {
                (points[edge.from] ??= {}).enter = { x: edge.tx, y: edge.ty };
            }
        }
        return points;
    }

    /** Baked walkable paths within a zone, "from|to" → {len, line}; {} when nothing is baked. */
    function mapPaths(zone) {
        return data.paths?.[zone] ?? {};
    }

    return { serverExpansions, serversMeta, resolveServer, resolvedGraph, zones, kosZones, findRoute, mapExits, mapPaths, attachRunLengths };
}

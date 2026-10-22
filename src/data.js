// The committed world data, bundled by Vite (hashed with the JS, so no cache-busting concerns).
// paths.json is ~550 KB and only matters once a route is computed, so it loads lazily.
import graph from '../database/eq/graph.json' with { type: 'json' };
import factions from '../database/eq/factions.json' with { type: 'json' };
import continents from '../database/eq/continents.json' with { type: 'json' };
import p99 from '../database/eq/deltas/p99.json' with { type: 'json' };
import legends from '../database/eq/deltas/legends.json' with { type: 'json' };

export const data = { graph, factions, continents, deltas: { p99, legends }, paths: undefined };

/** Load the baked navmesh paths into `data.paths` (once); resolves when they are available. */
export function loadPaths() {
    return (loadPaths.promise ??= import('../database/eq/paths.json', { with: { type: 'json' } })
        .then((m) => { data.paths = m.default; return data.paths; })
        .catch((e) => { loadPaths.promise = null; throw e; })); // let the next call retry
}

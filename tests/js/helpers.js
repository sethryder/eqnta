import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as baseConfig from '../../src/config.js';
import { createRouter } from '../../src/router.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export const json = (rel) => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));
export const fixture = (name) => json(`tests/Fixtures/${name}`);

/** The committed world data, as the app bundles it. */
export function committedData() {
    return {
        graph: json('database/eq/graph.json'),
        factions: json('database/eq/factions.json'),
        continents: json('database/eq/continents.json'),
        deltas: { p99: json('database/eq/deltas/p99.json'), legends: json('database/eq/deltas/legends.json') },
        paths: json('database/eq/paths.json'),
    };
}

/** Router over a fixture graph (default tests/Fixtures/graph.json), with optional data/config overrides. */
export function fixtureRouter({ graph = 'graph.json', factions, paths, deltas, config = {} } = {}) {
    const committed = committedData();
    return createRouter({
        graph: fixture(graph),
        factions: factions ? fixture(factions) : committed.factions,
        continents: committed.continents,
        deltas: deltas ?? committed.deltas,
        paths: paths ? fixture(paths) : undefined,
    }, { ...baseConfig, ...config });
}

export const config = baseConfig;

// Download the EQEmu collision .map files the path bake needs, into storage/app/eq/eqmaps-base/.
// Only fetches zones with >=2 walk zone-lines (what bake-paths.mjs actually processes); skips files
// already present. Source: https://github.com/EQEmu/maps (base/ folder, modern-era geometry).
//
// Usage: node database/eq/tools/fetch-maps.mjs   (or: npm run fetch:maps)
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(import.meta.dirname, '../../..');
const DIR = path.join(ROOT, 'storage/app/eq/eqmaps-base');
const BASE = 'https://raw.githubusercontent.com/EQEmu/maps/master/base';

const graph = JSON.parse(fs.readFileSync(path.join(ROOT, 'database/eq/graph.json')));
const deg = {};
for (const e of graph.edges) {
  if ((e.type ?? 'walk') !== 'walk') { continue; }
  (deg[e.from] ??= new Set()).add(e.to);
  if (e.tx != null) { (deg[e.to] ??= new Set()).add(e.from); }
}
const zones = Object.entries(deg).filter(([, s]) => s.size >= 2).map(([z]) => z).sort();

fs.mkdirSync(DIR, { recursive: true });
let got = 0, had = 0, missing = 0;
const queue = [...zones];
async function worker() {
  while (queue.length) {
    const z = queue.pop();
    const file = path.join(DIR, `${z}.map`);
    if (fs.existsSync(file) && fs.statSync(file).size > 0) { had++; continue; }
    const r = await fetch(`${BASE}/${z}.map`);
    if (!r.ok) { missing++; continue; }
    fs.writeFileSync(file, Buffer.from(await r.arrayBuffer()));
    got++;
  }
}
await Promise.all(Array.from({ length: 12 }, worker));
console.log(`maps: downloaded ${got}, already present ${had}, no map available ${missing} (of ${zones.length} routable zones)`);

#!/usr/bin/env bash
# Copy one map .txt per graph zone into dist/maps/: Brewall first, Goodurden as the fallback; an
# empty file counts as missing (some zones ship a 0-byte Brewall map). The map packs are gitignored
# (licensing) — run `npm run fetch:vmaps` first. Absent packs → 0 copied, build still succeeds.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p dist/maps
node -e '
const fs = require("fs");
const zones = new Set(JSON.parse(fs.readFileSync("database/eq/graph.json")).zones.map((z) => z.short));
for (const f of fs.readdirSync("database/eq/deltas")) {
  for (const z of JSON.parse(fs.readFileSync(`database/eq/deltas/${f}`)).zones ?? []) { zones.add(z.short); }
}
const nonEmpty = (p) => { try { return fs.statSync(p).size > 0; } catch { return false; } };
let copied = 0, missing = 0;
for (const z of zones) {
  const src = ["storage/app/eq/maps", "storage/app/eq/maps-goodurden"].map((d) => `${d}/${z}.txt`).find(nonEmpty);
  if (src) { fs.copyFileSync(src, `dist/maps/${z}.txt`); copied++; } else { missing++; }
}
console.log(`maps: copied ${copied}, missing ${missing} (of ${zones.size} zones)`);
'

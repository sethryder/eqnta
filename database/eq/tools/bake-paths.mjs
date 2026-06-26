// Offline bake: for every zone with an EQEmu .map collision file, build a recast navmesh and find
// the walkable path between each pair of its zone-line points. Writes database/eq/paths.json:
//   { "<zone>": { "<fromShort>|<toShort>": { "len": <units>, "line": [[x,y], ...] } } }
//
// Coordinates: graph zone-line points are in "world" frame; the app negates them for display, and
// the .map geometry is already in that negated/screen frame. recast is Y-up, EQ/.map is Z-up, so
// recast(x,y,z) = map(x, z, y). Output polylines are in screen frame → drop straight into the SVG.
//
// Usage: node database/eq/tools/bake-paths.mjs [zone ...]   (no args = every .map present)
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';
const require = createRequire(import.meta.url);
const { parseMapV2 } = require('./parse-map.cjs');
const { init, NavMeshQuery } = await import('recast-navigation');
const { generateSoloNavMesh } = await import('recast-navigation/generators');

const ROOT = path.resolve(import.meta.dirname, '../../..');
const MAP_DIR = path.join(ROOT, 'storage/app/eq/eqmaps-base');
const GRAPH = path.join(ROOT, 'database/eq/graph.json');
const OUT = path.join(ROOT, 'database/eq/paths.json');
const RUN_UNITS_PER_MIN = 2750;

// recast solo-navmesh tuning for EQ scale (units ≈ 0.1m). Generous slope/climb for EQ terrain.
const CFG = {
  cs: 2, ch: 2, walkableSlopeAngle: 75, walkableHeight: 4, walkableClimb: 4,
  walkableRadius: 1, maxEdgeLen: 12, maxSimplificationError: 1.3,
  minRegionArea: 8, mergeRegionArea: 20, maxVertsPerPoly: 6,
  detailSampleDist: 6, detailSampleMaxError: 1,
};
// Snap search extent. Wide horizontally so a zone-line point sitting just off the walkable mesh
// still snaps to the nearest mesh edge; huge vertically since zone lines vary a lot in height.
const HALF = { x: 150, y: 4000, z: 150 };

// The .map geometry's horizontal frame relative to world (PEQ/brewall coords) is not uniform across
// zones — some are negated, some identity, some axis-swapped. Each frame says how to map a world
// zone-line point INTO the navmesh query frame (toMap), and how to map a resulting path point
// (recast x,z = map x,y) OUT to the app's screen frame (= -world) (toScreen). Auto-detected per zone
// by whichever frame snaps the most zone-lines onto the mesh; 'negate' (listed first) wins ties.
const FRAMES = [
  { name: 'negate', toMap: (x, y) => [-x, -y], toScreen: (px, pz) => [px, pz] },
  { name: 'identity', toMap: (x, y) => [x, y], toScreen: (px, pz) => [-px, -pz] },
  { name: 'swap', toMap: (x, y) => [y, x], toScreen: (px, pz) => [-pz, -px] },
  { name: 'swapneg', toMap: (x, y) => [-y, -x], toScreen: (px, pz) => [pz, px] },
];

// A candidate from a (x,y) pair: a point when both are set, else a wildcard *line* (one axis fixed,
// the other was EQ's 999999 "anywhere along this edge" sentinel, nulled by the graph build).
function candOf(x, y) {
  if (x != null && y != null) { return { x, y, full: true }; }
  if (x != null) { return { axis: 'x', v: x, full: false }; } // y is wildcard
  if (y != null) { return { axis: 'y', v: y, full: false }; } // x is wildcard
  return null;
}

// World zone-line candidates per neighbour, in this zone's frame: the exit trigger (from=Z) and the
// arrival landing (reverse edge's tx/ty), each a point or a wildcard line. Full points are listed
// first so the bake prefers them and only falls back to wildcard lines.
function zonePoints(edges) {
  const byZone = {};
  for (const e of edges) {
    if ((e.type ?? 'walk') !== 'walk') { continue; }
    const ex = candOf(e.x, e.y);
    if (ex) { ((byZone[e.from] ??= {})[e.to] ??= []).push(ex); }
    const en = candOf(e.tx, e.ty);
    if (en) { ((byZone[e.to] ??= {})[e.from] ??= []).push(en); }
  }
  for (const z of Object.values(byZone)) {
    for (const n in z) { z[n].sort((a, b) => (b.full ? 1 : 0) - (a.full ? 1 : 0)); }
  }
  return byZone; // { zone: { neighbour: [candidate, ...] } }
}

// Snap one candidate onto the navmesh under a frame. A full point queries a tight box; a wildcard
// line queries with a huge extent along the free axis so the nearest walkable point on that edge wins.
function snapCandidate(query, fr, cand) {
  if (cand.full) {
    const [sx, sz] = fr.toMap(cand.x, cand.y);
    const r = query.findClosestPoint({ x: sx, y: 0, z: sz }, { halfExtents: HALF });
    return r.success ? r.point : null;
  }
  const w0 = cand.axis === 'x' ? [cand.v, 0] : [0, cand.v];
  const w1 = cand.axis === 'x' ? [cand.v, 1] : [1, cand.v]; // +1 along the free world axis
  const b = fr.toMap(...w0), b1 = fr.toMap(...w1);
  const freeIsRecastX = Math.abs(b1[0] - b[0]) > Math.abs(b1[1] - b[1]);
  const half = freeIsRecastX ? { x: 9000, y: HALF.y, z: HALF.z } : { x: HALF.x, y: HALF.y, z: 9000 };
  const r = query.findClosestPoint({ x: b[0], y: 0, z: b[1] }, { halfExtents: half });
  return r.success ? r.point : null;
}

// Drop triangles with any vertex far outside the bulk per-axis range (skyboxes, death-planes, stray
// placements), then compact to only referenced verts. A single far outlier — some zones have a vertex
// at z≈-32000 — otherwise blows up recast's heightfield height so the real ground becomes a thin slice
// and no walkable mesh survives. IQR-based band [Q1-W, Q3+W], W=max(2000, 3·IQR): robust to a sizable
// chunk of outlier geometry (percentile bands aren't), while keeping real terrain and elevation.
function sanitize(verts, indices) {
  const band = (key) => {
    const a = verts.map((v) => v[key]).sort((p, q) => p - q);
    const q1 = a[Math.floor(a.length * 0.25)], q3 = a[Math.floor(a.length * 0.75)];
    const w = Math.max(2000, 3 * (q3 - q1));
    return [q1 - w, q3 + w];
  };
  const [bxlo, bxhi] = band('x'), [bylo, byhi] = band('y'), [bzlo, bzhi] = band('z');
  const ok = (v) => v.x >= bxlo && v.x <= bxhi && v.y >= bylo && v.y <= byhi && v.z >= bzlo && v.z <= bzhi;

  const remap = new Map(); const nv = []; const ni = []; let dropped = 0;
  for (let i = 0; i < indices.length; i += 3) {
    const tri = [indices[i], indices[i + 1], indices[i + 2]];
    if (!ok(verts[tri[0]]) || !ok(verts[tri[1]]) || !ok(verts[tri[2]])) { dropped++; continue; }
    for (const idx of tri) {
      if (!remap.has(idx)) { remap.set(idx, nv.length); nv.push(verts[idx]); }
      ni.push(remap.get(idx));
    }
  }
  return { verts: nv, indices: ni, dropped };
}

await init();
const graph = JSON.parse(fs.readFileSync(GRAPH));
const points = zonePoints(graph.edges);

const want = process.argv.slice(2);
const files = (want.length ? want.map((z) => `${z}.map`) : fs.readdirSync(MAP_DIR).filter((f) => f.endsWith('.map')));

const result = {};
for (const file of files) {
  const zone = file.replace(/\.map$/, '');
  const mapPath = path.join(MAP_DIR, file);
  if (!fs.existsSync(mapPath)) { console.warn(`skip ${zone}: no .map`); continue; }
  const nbrs = points[zone];
  if (!nbrs || Object.keys(nbrs).length < 2) { console.log(`${zone}: <2 zone lines, skipping`); continue; }

  let verts, indices;
  try { ({ verts, indices } = parseMapV2(mapPath)); } catch (e) { console.warn(`${zone}: parse failed ${e.message}`); continue; }
  ({ verts, indices } = sanitize(verts, indices));

  if (!verts.length) { console.log(`${zone}: empty after sanitize, skipping`); continue; }
  const positions = new Float32Array(verts.length * 3);
  let xmn = Infinity, xmx = -Infinity, ymn = Infinity, ymx = -Infinity;
  for (let i = 0; i < verts.length; i++) {
    positions[i * 3] = verts[i].x; positions[i * 3 + 1] = verts[i].z; positions[i * 3 + 2] = verts[i].y;
    if (verts[i].x < xmn) { xmn = verts[i].x; } if (verts[i].x > xmx) { xmx = verts[i].x; }
    if (verts[i].y < ymn) { ymn = verts[i].y; } if (verts[i].y > ymx) { ymx = verts[i].y; }
  }
  // Adaptive cell size: keep the heightfield grid bounded (~2000 cells/side) so big outdoor zones
  // don't blow recast's wasm memory. Dungeons stay at the fine cs=2.
  const cs = Math.max(CFG.cs, Math.ceil(Math.max(xmx - xmn, ymx - ymn) / 2000));

  let navMesh;
  try {
    const r = generateSoloNavMesh(positions, Array.from(indices), { ...CFG, cs });
    if (!r.success) { console.warn(`${zone}: navmesh build failed`); continue; }
    navMesh = r.navMesh;
  } catch (e) { console.warn(`${zone}: navmesh error (${e.message})`); continue; }
  const query = new NavMeshQuery(navMesh);

  // Detect the .map's frame: try each, snapping each neighbour's candidates (full point first, then
  // wildcard line). Score full snaps ×10 so reliable points pick the frame and wildcard lines (which
  // land easily under several frames) only break ties / add coverage. FRAMES order makes 'negate' win.
  const candList = Object.entries(nbrs);
  let best = null;
  for (const fr of FRAMES) {
    const snap = {}; let score = 0;
    for (const [n, cands] of candList) {
      for (const c of cands) {
        const p = snapCandidate(query, fr, c);
        if (p) { snap[n] = p; score += c.full ? 10 : 1; break; }
      }
    }
    if (!best || score > best.score) { best = { fr, snap, score }; }
  }
  if (!best || Object.keys(best.snap).length < 2) {
    navMesh.destroy();
    console.log(`${zone}: ${best ? Object.keys(best.snap).length : 0}/${candList.length} snapped, skipping`);
    continue;
  }
  const { fr, snap } = best;
  const names = Object.keys(snap);

  const zonePathsArr = {};
  let pairs = 0;
  for (let i = 0; i < names.length; i++) {
    for (let j = 0; j < names.length; j++) {
      if (i === j) { continue; }
      const res = query.computePath(snap[names[i]], snap[names[j]], { halfExtents: HALF });
      const pts = res.path || [];
      if (!res.success || pts.length < 2) { continue; }
      let len = 0;
      for (let k = 1; k < pts.length; k++) { len += Math.hypot(pts[k].x - pts[k - 1].x, pts[k].y - pts[k - 1].y, pts[k].z - pts[k - 1].z); }
      // Path points (recast x,z = map frame) → app screen frame via the detected frame.
      const line = pts.map((p) => { const [sx, sy] = fr.toScreen(p.x, p.z); return [Math.round(sx), Math.round(sy)]; });
      zonePathsArr[`${names[i]}|${names[j]}`] = { len: Math.round(len), line };
      pairs++;
    }
  }
  navMesh.destroy();
  if (pairs) { result[zone] = zonePathsArr; }
  console.log(`${zone}: ${pairs} paths, frame=${fr.name}, ${names.length}/${Object.keys(nbrs).length} snapped (${(JSON.stringify(zonePathsArr).length / 1024).toFixed(1)}KB)`);
}

// Merge with any existing baked data so a partial run doesn't drop other zones.
const prev = fs.existsSync(OUT) ? JSON.parse(fs.readFileSync(OUT)) : {};
fs.writeFileSync(OUT, JSON.stringify({ ...prev, ...result }));
console.log(`wrote ${OUT}: ${Object.keys({ ...prev, ...result }).length} zones`);

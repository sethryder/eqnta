// EQEmu .map V2 parser → triangle soup (verts[], indices[]). Port of EQEmu zone/map.cpp LoadV2.
// Usage: node parse-map.cjs <path-to.map>
const fs = require('fs');
const zlib = require('zlib');

function rotate(v, rx, ry, rz) {
  let n = { ...v };
  n.y = Math.cos(rx) * v.y - Math.sin(rx) * v.z;
  n.z = Math.sin(rx) * v.y + Math.cos(rx) * v.z;
  v = { ...n };
  n.x = Math.cos(ry) * v.x + Math.sin(ry) * v.z;
  n.z = -Math.sin(ry) * v.x + Math.cos(ry) * v.z;
  v = { ...n };
  n.x = Math.cos(rz) * v.x - Math.sin(rz) * v.y;
  n.y = Math.sin(rz) * v.x + Math.cos(rz) * v.y;
  return { ...n };
}
const scale = (v, sx, sy, sz) => ({ x: v.x * sx, y: v.y * sy, z: v.z * sz });
const translate = (v, tx, ty, tz) => ({ x: v.x + tx, y: v.y + ty, z: v.z + tz });
const D2R = 3.14159 / 180;

function parseMapV2(path) {
  const raw = fs.readFileSync(path);
  const version = raw.readUInt32LE(0);
  if (version !== 0x02000000) { throw new Error(`not V2: ${version.toString(16)}`); }
  const dataSize = raw.readUInt32LE(4);
  const bufferSize = raw.readUInt32LE(8);
  const buf = zlib.inflateSync(raw.subarray(12, 12 + dataSize));
  if (buf.length !== bufferSize) { console.warn(`inflate size ${buf.length} != ${bufferSize}`); }

  let o = 0;
  const u32 = () => { const v = buf.readUInt32LE(o); o += 4; return v; };
  const f32 = () => { const v = buf.readFloatLE(o); o += 4; return v; };
  const u8 = () => { const v = buf.readUInt8(o); o += 1; return v; };
  const bool = () => { const v = buf.readUInt8(o); o += 1; return v !== 0; };
  const cstr = () => { let e = o; while (buf[e] !== 0) { e++; } const s = buf.toString('latin1', o, e); o = e + 1; return s; };

  const vertCount = u32(), indCount = u32(), ncVert = u32(), ncInd = u32();
  const modelCount = u32(), placCount = u32(), placGroupCount = u32(), tileCount = u32();
  const quadsPerTile = u32(), unitsPerVertex = f32();

  const verts = []; // {x,y,z}
  const indices = [];
  for (let i = 0; i < vertCount; i++) { verts.push({ x: f32(), y: f32(), z: f32() }); }
  for (let i = 0; i < indCount; i++) { indices.push(u32()); }
  o += ncVert * 12 + ncInd * 4; // skip non-collision

  const models = {};
  for (let i = 0; i < modelCount; i++) {
    const name = cstr();
    const vc = u32(), pc = u32();
    const mv = []; const mp = [];
    for (let j = 0; j < vc; j++) { mv.push({ x: f32(), y: f32(), z: f32() }); }
    for (let j = 0; j < pc; j++) { mp.push({ v1: u32(), v2: u32(), v3: u32(), vis: u8() }); }
    models[name] = { verts: mv, polys: mp };
  }

  const pushTri = (a, b, c) => {
    verts.push({ x: a.y, y: a.x, z: a.z }); // x/y swapped, per source
    verts.push({ x: b.y, y: b.x, z: b.z });
    verts.push({ x: c.y, y: c.x, z: c.z });
    indices.push(verts.length - 3, verts.length - 2, verts.length - 1);
  };

  // Placements
  for (let i = 0; i < placCount; i++) {
    const name = cstr();
    const x = f32(), y = f32(), z = f32();
    const xr = f32(), yr = f32(), zr = f32();
    const xs = f32(), ys = f32(), zs = f32();
    const m = models[name];
    if (!m) { continue; }
    for (const p of m.polys) {
      if (p.vis === 0) { continue; }
      let v1 = m.verts[p.v1], v2 = m.verts[p.v2], v3 = m.verts[p.v3];
      for (const [vi, vv] of [[0, v1], [1, v2], [2, v3]]) {
        let v = rotate(vv, xr, yr, zr);
        v = scale(v, xs, ys, zs);
        v = translate(v, x, y, z);
        if (vi === 0) { v1 = v; } else if (vi === 1) { v2 = v; } else { v3 = v; }
      }
      pushTri(v1, v2, v3);
    }
  }

  // Placement groups
  for (let i = 0; i < placGroupCount; i++) {
    const x = f32(), y = f32(), z = f32();
    const xr = f32(), yr = f32(), zr = f32();
    const xs = f32(), ys = f32(), zs = f32();
    const xt = f32(), yt = f32(), zt = f32();
    const pc = u32();
    for (let j = 0; j < pc; j++) {
      const name = cstr();
      const px = f32(), py = f32(), pz = f32();
      const pxr = f32() * D2R, pyr = f32() * D2R, pzr = f32() * D2R;
      const pxs = f32(), pys = f32(), pzs = f32();
      const m = models[name];
      if (!m) { continue; }
      for (const p of m.polys) {
        if (p.vis === 0) { continue; }
        let tri = [m.verts[p.v1], m.verts[p.v2], m.verts[p.v3]].map((vv) => {
          let v = scale(vv, pxs, pys, pzs);
          v = translate(v, px, py, pz);
          v = rotate(v, xr * D2R, 0, 0);
          v = rotate(v, 0, yr * D2R, 0);
          let corr = rotate({ x: px, y: py, z: pz }, xr * D2R, 0, 0);
          v = translate(v, -corr.x, -corr.y, -corr.z);
          v = rotate(v, pxr, 0, 0);
          v = rotate(v, 0, -pyr, 0);
          v = rotate(v, 0, 0, pzr);
          v = translate(v, corr.x, corr.y, corr.z);
          v = rotate(v, 0, 0, zr * D2R);
          v = scale(v, xs, ys, zs);
          v = translate(v, xt, yt, zt);
          v = translate(v, x, y, z);
          return v;
        });
        pushTri(tri[0], tri[1], tri[2]);
      }
    }
  }

  // Terrain tiles
  const terQuad = quadsPerTile * quadsPerTile;
  const terVert = (quadsPerTile + 1) * (quadsPerTile + 1);
  for (let i = 0; i < tileCount; i++) {
    const flat = bool();
    const x = f32(), y = f32();
    if (flat) {
      const z = f32();
      const upt = quadsPerTile * unitsPerVertex;
      const cv = verts.length + 3;
      verts.push({ x, y, z });
      verts.push({ x: x + upt, y, z });
      verts.push({ x: x + upt, y: y + upt, z });
      verts.push({ x, y: y + upt, z });
      indices.push(cv, cv - 2, cv - 1, cv, cv - 3, cv - 2);
    } else {
      const flags = []; const floats = [];
      for (let j = 0; j < terQuad; j++) { flags.push(u8()); }
      for (let j = 0; j < terVert; j++) { floats.push(f32()); }
      let row = -1;
      const seen = new Map();
      const idx = (vx, vy, vz) => {
        const k = `${vx},${vy},${vz}`;
        if (seen.has(k)) { return seen.get(k); }
        const id = verts.length; verts.push({ x: vx, y: vy, z: vz }); seen.set(k, id); return id;
      };
      for (let quad = 0; quad < terQuad; quad++) {
        if (quad % quadsPerTile === 0) { row++; }
        if (flags[quad] & 0x01) { continue; }
        const q1x = x + row * unitsPerVertex, q1y = y + (quad % quadsPerTile) * unitsPerVertex, q1z = floats[quad + row];
        const q2x = q1x + unitsPerVertex, q2y = q1y, q2z = floats[quad + row + quadsPerTile + 1];
        const q3x = q1x + unitsPerVertex, q3y = q1y + unitsPerVertex, q3z = floats[quad + row + quadsPerTile + 2];
        const q4x = q1x, q4y = q1y + unitsPerVertex, q4z = floats[quad + row + 1];
        const i1 = idx(q1x, q1y, q1z), i2 = idx(q2x, q2y, q2z), i3 = idx(q3x, q3y, q3z), i4 = idx(q4x, q4y, q4z);
        indices.push(i4, i2, i3, i4, i1, i2);
      }
    }
  }

  return { verts, indices, meta: { vertCount, indCount, modelCount, placCount, placGroupCount, tileCount, quadsPerTile, unitsPerVertex, bytesRead: o, bufLen: buf.length } };
}

if (require.main === module) {
  const { verts, indices, meta } = parseMapV2(process.argv[2]);
  let mnx = Infinity, mny = Infinity, mnz = Infinity, mxx = -Infinity, mxy = -Infinity, mxz = -Infinity;
  for (const v of verts) {
    mnx = Math.min(mnx, v.x); mxx = Math.max(mxx, v.x);
    mny = Math.min(mny, v.y); mxy = Math.max(mxy, v.y);
    mnz = Math.min(mnz, v.z); mxz = Math.max(mxz, v.z);
  }
  console.log('meta:', meta);
  console.log('verts:', verts.length, 'tris:', indices.length / 3);
  console.log('bbox x:', mnx.toFixed(0), mxx.toFixed(0), '| y:', mny.toFixed(0), mxy.toFixed(0), '| z:', mnz.toFixed(0), mxz.toFixed(0));
  console.log('fully consumed buffer:', meta.bytesRead === meta.bufLen);
}

module.exports = { parseMapV2 };

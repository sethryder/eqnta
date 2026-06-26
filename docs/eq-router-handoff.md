# EverQuest Zone Router — Project Handoff

## What we're building

A website that gives **step-by-step zone-to-zone travel directions** in EverQuest. The user picks a starting zone and a destination zone; the site returns an ordered list of steps, where each step is a typed edge (run, boat, teleport, PoK book, spire, translocate, etc.) with an icon per type.

Key requirements:
- **Multiple eras** — Project 1999 (frozen at Velious), classic TLP progression, and Live. The graph must be era-aware so connections and teleports that don't exist yet in a given era are excluded.
- **Class-aware routing** — if the user is a Wizard or Druid (or has another teleport-capable class), include their port/translocate/evac spells as graph edges, gated by class and level.
- **Typed steps with icons** — distinguish running between zones vs. taking a boat vs. a teleport vs. a PoP portal/book, etc.

This file is a research handoff. It documents the data sources, how to extract them, and a suggested build pipeline. No code has been written yet.

---

## Core data model

Model the world as a directed graph:

- **Nodes** = zones (keyed by short name; carry id + long name for display).
- **Edges** = a connection from one zone to another, each tagged with:
  - `type`: `walk` | `boat` | `teleport` | `book` | `spire` | `translocate` | `evac`
  - `era` / expansion availability
  - optional `class` + `min_level` restriction (for teleport-type edges)
  - coordinates of the trigger point (for display / "go to X,Y" instructions)

The front end runs a pathfinder (Dijkstra or A\*) over the filtered subgraph (filtered by selected era + class/level) and renders the resulting edge list as the step-by-step directions.

---

## Data sources

### 1. EQEmu / PEQ content database — PRIMARY SOURCE

The EverQuest emulator project maintains a complete, open MySQL database reconstructing the game world. Distributed as downloadable SQL dumps on GitHub (ProjectEQ / PEQ). Schema fully documented at https://docs.eqemu.io. ~261 tables; we only need a few.

**Tables that matter:**

- **`zone_points`** — THE zone adjacency list for walkable connections. Each row = a physical zone line:
  - source `zone` (short name)
  - `target_zone_id` (where it leads)
  - x/y/z of the zone line + target x/y/z (landing spot)
  - `content_flags` / `content_flags_disabled` + `version` — used to gate content by expansion/era
  - Note: x/y/target coords can contain the sentinel value `999999`, which acts as a wildcard (wide zone lines like EC tunnel, or "use current location as destination").
- **`spells_new`** — every spell, including:
  - `teleport_zone` (destination zone short name)
  - effect fields identifying gate / portal / translocate / succor(evac) spells
  - per-class level columns → model "level 29 Druid has these ports, level 49 has these"
  - Filter to teleport effects + class to get the full Druid/Wizard (and other) port edge set.
- **`zone`** — id ↔ short_name ↔ long_name lookup for the dropdowns and display.

**Caveats:**
- PEQ targets roughly **Live**-era content. For authentic **P99** (frozen at Velious) or **classic TLP**, the connection set and teleport spell list differ. Handle eras by filtering on version/content-flag columns, and cross-check the classic/Velious subset against P99 data (see source #4).
- What `zone_points` does NOT cover: boats, PoK books, spires, PoP portals — these are NPCs / clickable objects / quest scripts, not zone lines. See source #2 to fill those in.

**Extraction approach:** import the SQL dump locally, export `zone`, `zone_points`, and the teleport-filtered `spells_new` to JSON, then flatten the relational data into the edge/node shape above. (This mirrors the EQGuessr project's approach: load tables → JSON → flatten.)

### 2. Brewall map files — fills the boat/book/spire/translocate gap

The de facto standard downloadable maps (https://www.eqmaps.info). Plain-text `.txt`, one file per zone, documented format. Parseable.

**File format — two line types:**
- `L x1,y1,z1,x2,y2,z2,r,g,b` — geometry line segments (not needed for routing).
- `P x,y,z,r,g,b,size,label` — points of interest. **This is where the gold is.**

**Why it matters:** Brewall follows a strict labeling convention. Zone connections that are NPCs/objects (boats, translocators, spires, books) are labeled with `to ` followed by the name, in large red text — e.g. `to Erud's Crossing (Translocator Sedina)`. So every dock, translocator, spire, and book is a labeled POI with coordinates.

**Extraction approach:** regex the `P` lines for labels starting with `to `; the color/size encode the type. Ground spawns are prefixed `GS:`. This gives you the special-transport edges that the database doesn't cleanly expose. Reference implementation exists: the `everquest-mcp` project's `localdata.ts` parses these same files (34K+ Brewall POIs).

**Licensing note:** Brewall's maps are his personal work. EQEmu data is community-shared and fine to use, but if shipping parsed Brewall data publicly, do a courtesy check on redistribution. Generating client-side or keeping server-side is lower-risk.

### 3. Brewall world map (validation only)

Brewall publishes a single high-res image showing ALL zones, connections, portals, and clickies. Not parseable (it's an image), but an excellent ground-truth reference to validate the generated graph against.

### 4. Project 1999 wiki (validation + classic/Velious teleports)

MediaWiki site with structured porting guides (e.g. "Sorn's Guide to Porting for Porters") that enumerate exactly which translocates land where, by zone, for the classic/Velious era. Use the **MediaWiki API** (not HTML scraping) to pull structured data. Best source for validating the P99/classic teleport edge set, since P99 runs its own tuned database frozen at Velious.

### 5. Reference: existing parsers / MCP servers

- `ArtSabintsev/everquest-mcp` — parses EQ game files directly; includes Brewall POI parsing + teleport spell lookup. Good reference implementation.
- `straps-eq/eqemu-mcp-server` — documents the EQEmu schema (261 tables, column-level docs). Useful for getting table/column names right.

### 6. Skip these

Raw game files (`.s3d` / `.eqg` / `.zon`) contain the actual geometry and hardcoded zone-line trigger regions. Parseable but a serious undertaking and unnecessary — `zone_points` already provides the connection data in tabular form.

---

## Suggested build pipeline

1. **Import PEQ SQL dump** locally. Export `zone`, `zone_points`, and `spells_new` (filtered to teleport effects) → JSON. Produces nodes + walk edges + teleport edges.
2. **Parse Brewall map pack** — regex the POI (`P`) labels for `to ` connections → boat/book/spire/translocate edges with coordinates and types.
3. **Tag every edge with era/expansion** using version + content-flag columns, plus expansion metadata for teleport spells.
4. **Cross-validate** the classic/Velious subset against P99 wiki porting data (MediaWiki API).
5. **Store as a single JSON graph**: nodes = zones, edges = `{ from, to, type, era, class, min_level, coords }`.
6. **Front end** loads the JSON, filters by selected era + class/level, runs Dijkstra/A\* with tunable edge weights, and renders the step list with one icon per edge `type`.

---

## Open questions / decisions to make

- **Edge weights:** how to weight a long zone run vs. an instant teleport vs. a boat with wait time? Affects what "best route" means. Probably want tunable weights, maybe a user toggle (fastest vs. fewest-zones).
- **Era data fidelity for P99/TLP:** how much manual correction is acceptable vs. fully derived from content flags. P99 may need its own curated overrides.
- **Class/level granularity:** model exact spell-acquisition levels, or simplify to "has ports / doesn't"?
- **One-way edges:** many ports and some zone lines are one-directional — make sure the graph is directed and edges aren't assumed bidirectional.
- **Redistribution:** confirm Brewall map data handling before shipping parsed POIs publicly.

---

## Source links

- EQEmu schema docs: https://docs.eqemu.io
- `zone_points` schema: https://docs.eqemu.io/schema/zone/zone_points/
- `spells_new` schema: https://docs.eqemu.io/schema/spells/spells_new/
- PEQ database (GitHub): https://github.com/ProjectEQ (project-everquest)
- Brewall maps: https://www.eqmaps.info/eq-map-files/
- Brewall mapping standards (label conventions): https://www.eqmaps.info/eq-map-files/mapping-standards/
- everquest-mcp (parser reference): https://github.com/ArtSabintsev/everquest-mcp
- eqemu-mcp-server (schema reference): https://github.com/straps-eq/eqemu-mcp-server
- P99 porting guide (validation): https://wiki.project1999.com/Sorn's_Guide_To_Porting_For_Porters

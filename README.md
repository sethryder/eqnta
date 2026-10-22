# Norrath Transit Authority

Zone trip planner for EverQuest emulator servers: https://eqnta.washclof.com

A static site. Routing (Dijkstra over a typed zone graph, era/class/level gating, KOS and level
danger from real PEQ faction data) runs in the browser over JSON committed under `database/eq/`.

## Site

```
npm ci
npm run fetch:vmaps   # Brewall + Goodurden zone maps → storage/app/eq/ (gitignored, licensing)
npm run dev           # Vite dev server
npm test              # node --test tests/js
npm run build         # dist/ (vite build + one map .txt per zone)
```

- `index.html`, `src/app.js` (Alpine UI), `src/router.js` (routing), `src/config.js` (servers,
  eras, races/classes/deities, cost weights), `src/data.js` (bundled JSON).
- Pushes to `main` deploy `dist/` to GitHub Pages via `.github/workflows/deploy.yml`.

## Data pipeline (PHP, offline only)

The graph and faction JSON under `database/eq/` are built from the ProjectEQ database dump by
Artisan commands. Needs PHP 8.3, Composer, Docker (for the MariaDB the dump loads into), and Node.
One-time setup:

```
composer install
cp .env.example .env        # DB_* are the MariaDB credentials compose.yaml starts with
```

### Refresh everything from a new PEQ dump

```
scripts/refresh-peq.sh
```

That runs, in order (each can also be run by hand):

| Step | What it does | Needs |
|---|---|---|
| `docker compose up -d mariadb --wait` | the DB the dump loads into | Docker |
| `npm run fetch:vmaps` | Brewall + Goodurden map packs → `storage/app/eq/` (gitignored) | network |
| `php artisan eq:import-peq --build` | downloads the latest PEQ dump, loads the tables we use, then runs `eq:build-graph` + `eq:build-factions` | DB, network, maps |
| `php artisan eq:build-continents` | zone → continent map, from `graph.json` | graph.json |
| `php artisan eq:audit-graph` | reports walk edges that cross continents (fix via `overrides.json` and rerun) | graph.json |
| `npm test` | graph integrity tests against the fresh JSON | — |

Then review `git diff database/eq`, commit, push. The deploy workflow rebuilds the site.

Individual commands:

- `php artisan eq:import-peq [--file=dump.zip|.sql] [--build]` — `--file` skips the download.
- `php artisan eq:build-graph` — zones + typed edges from `zone_points`, spells, and the Brewall maps
  (boats, spires, books, zone-line coords). Skips the map-derived parts if the packs are absent.
  Applies `overrides.json` and `postrof.json` on top; per-server differences go in `deltas/`.
- `php artisan eq:build-factions` — per-zone guard factions and monster level medians for KOS and
  danger flags.
- `php artisan eq:build-continents`, `php artisan eq:audit-graph` — see table.
- `php artisan test` — tests for the build commands.

### Zone maps (site build)

The site serves one Brewall/Goodurden `.txt` per zone. They are not committed (licensing);
`npm run fetch:vmaps` downloads them and `npm run build` copies them into `dist/maps/`. The deploy
workflow does the same, caching the packs monthly.

### Navmesh run paths

`database/eq/paths.json` holds walkable paths between zone lines, used for run-time estimates and
the charted line on the inline maps. Only re-bake after zone-line changes in `graph.json`:

```
npm run fetch:maps                      # EQEmu collision .map files → storage/app/eq/eqmaps-base/
npm run bake:paths                      # every zone with a .map (slow: builds a recast navmesh each)
node database/eq/tools/bake-paths.mjs qeynos2 nektulos   # just these zones
```

### Hand corrections

- `database/eq/overrides.json` — universal fixes baked into the base graph by `eq:build-graph`.
- `database/eq/postrof.json` — hand-curated connectivity past Rain of Fear (PEQ stops there).
- `database/eq/deltas/<server>.json` — per-server zones/edges, applied in the browser. Register the
  server in `src/config.js`.

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

The graph and faction JSON are built from the ProjectEQ database dump by Artisan commands
(`eq:import-peq`, `eq:build-graph`, `eq:build-factions`, `eq:build-continents`, `eq:audit-graph`).
Needs PHP 8.3, Composer, and Docker for the MariaDB the dump loads into:

```
scripts/refresh-peq.sh   # import + rebuild; then review the diff, commit, push
php artisan test         # tests for the build commands
```

Navmesh run paths (`database/eq/paths.json`) come from `npm run fetch:maps && npm run bake:paths`
and only need re-baking after zone-line changes. Hand corrections live in
`database/eq/overrides.json`, `postrof.json`, and the per-server `deltas/`.

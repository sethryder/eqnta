# Static site migration plan (GitHub Pages + Actions)

Goal: eqnta.com becomes a static site built by Vite, deployed by a GitHub Actions workflow to
GitHub Pages. Data refreshes (PEQ import, graph/faction builds, navmesh bake) stay local, one
script each; pushing the regenerated JSON triggers the deploy. No PHP at runtime.

## Status (2026-10-21)

- Phase 1 done: `src/router.js` + `src/config.js` + `src/data.js`, `app.js` rewired, 43 `node --test`
  tests green, PHP-vs-JS parity verified on 613 routed/no-route/invalid cases with zero mismatches.
- Phase 2 done: `index.html`, Vite config, `scripts/build-maps.sh`, `public/CNAME`. Build verified
  in a headless browser. Map pack is ~125 MB (553 of 565 zones), cached monthly in Actions.
- Phase 3 code done: `.github/workflows/deploy.yml` and `test.yml`. Remaining is the repo Pages
  setting (Source = GitHub Actions, custom domain) and the DNS change — manual, see Phase 3.
- Phase 4 done: `scripts/refresh-peq.sh`.
- Phase 5 not started (needs sign-off on deleting the replaced PHP tests).

## Why this is feasible

The site is already a client-side app. The only server work is six stateless GET endpoints in
`app/Http/Controllers/RoutingController.php`, all reading committed JSON under `database/eq/` plus
the gitignored Brewall/Goodurden map `.txt` files. Nothing uses a database, session, or auth at
request time. The graph is 564 zones / 1437 edges (412 KB raw JSON) — trivially routed in the
browser.

| Endpoint | Today | Static replacement |
|---|---|---|
| `GET /` | Blade view | `index.html` (Vite entry) |
| `/api/servers` | `config/eqrouter.php` | `src/config.js` (same data as a JS module) |
| `/api/zones` | `Router::zones` | JS: filter resolved graph by expansion set |
| `/api/route` | `Router::find` (Dijkstra + KOS + danger) | JS port of `Router.php` + `FactionService.php` + `GraphDelta.php` |
| `/api/map/{zone}` | serves map `.txt` | static file `maps/{zone}.txt`, fetched into the build (never committed) |
| `/api/map/{zone}/exits` | filters edges of resolved graph | JS: same filter, graph is already in memory |
| `/api/map/{zone}/paths` | slice of `paths.json` | `import('../database/eq/paths.json')` lazy chunk, slice client-side |

Shared planner URLs (`/?from=…&to=…&server=…`) keep working: same path, same query params. The
`/api/*` URLs go away; the planner is their only consumer.

## Target layout

```
index.html                     ← resources/views/planner.blade.php, de-Bladed
src/app.js                     ← resources/js/app.js (fetches replaced by local calls)
src/router.js                  ← port of Router.php + GraphDelta.php + FactionService.php
src/config.js                  ← servers/expansions/races/classes/deities/weights from config/eqrouter.php
src/app.css                    ← resources/css/app.css
public/                        favicon.*, og.png, robots.txt, sitemap.xml, CNAME  (Vite copies verbatim; index.php deleted)
database/eq/*.json             unchanged — imported directly into the bundle by Vite
database/eq/tools/             unchanged (fetch-maps, fetch-vmaps, bake-paths)
app/Console/Commands/          unchanged — the PHP data pipeline stays, runtime PHP goes
tests/js/                      node:test port of the Router feature tests (reuses tests/Fixtures/*.json)
scripts/refresh-peq.sh         local PEQ import + rebuild (compose MariaDB + artisan)
.github/workflows/deploy.yml   build + deploy on push to main
```

Data JSON is `import`ed, not fetched: Vite inlines `graph.json`, `factions.json`, `continents.json`,
and the two deltas into the hashed JS bundle (~500 KB raw, ~80 KB gzipped), so there is no data
fetch, no cache-busting problem, and no copy step. `paths.json` (548 KB) is a dynamic `import()` so
it only loads when a map is first expanded.

Maps are the one thing that must stay out of git (licensing — see `fetch-vmaps.sh`). The deploy
workflow downloads them and puts `maps/{zone}.txt` in the Pages artifact. The Pages artifact is an
uploaded tarball, not a branch, so the maps are published exactly as the Laravel server publishes
them today and never enter the repository. Brewall-then-Goodurden fallback and the "0-byte counts as
missing" rule move from `mapFile()` into a 10-line build script that copies one file per graph zone.

## Phases

Each phase ships on its own; the site keeps working after every one.

### Phase 1 — Port the router to JS (no deploy change)

1. `src/config.js`: export `servers`, `expansions`, `races`, `classes`, `deities`, `weights`,
   `weightsSafe`, `kosThreshold`, `kosPenalty`, `dangerMargin`, `dangerPerLevel`,
   `limitedDataExpansion`, `maxLevel`, `defaultServer`. Copy the values, keep the comments that
   explain tuning.
2. `src/router.js`, one file, four exports:
   - `resolveGraph(serverKey)` — base graph + merged deltas (`GraphDelta::merge/apply/matches`), memoised per server.
   - `zones(serverKey, expansions)` — `Router::zones` incl. continent lookup and reachability filter.
   - `findRoute({from, to, server, expansions, class, level, mode, race, deity, rituals})` — `Router::find` + `buildAdjacency` + `reconstruct` + `kosZones` + `attachRunLengths` (via the lazy paths import). Returns the same step shape the UI already consumes.
   - `serverExpansions(server, today)` — the TLP unlock-schedule resolver; `today` is injectable for tests.
   - `mapExits(serverKey, zone)` — the exits/enter point filter.
   Keep the O(V²) Dijkstra as-is; 564 nodes is nothing.
   Keep the controller's guards: unknown server/zone/class/race/deity → treat as "no route" with the existing UI message; rituals intersected with the server's declared list.
3. `tests/js/router.test.js` with `node --test` (stdlib, no new dependency). Port the behaviour
   tests from `tests/Feature/RoutingTest.php` and `EraIsolationTest.php` (route shape, safest mode,
   KOS, deity, danger, druid level gate, rituals, expansion gating, deltas, TLP unlock by date,
   zones filtering, map exits, run lengths) and `tests/Unit/DeltaMatchTest.php` and
   `GraphIntegrityTest.php`. The fixtures in `tests/Fixtures/` are plain JSON and load unchanged.
   The PHP tests that exercise HTTP validation (`invalid_mode_is_rejected`, `unknown_server…`)
   collapse into "returns null" assertions.
   Add `"test": "node --test tests/js"` to `package.json`.
4. Wire `app.js` to call `router.js` instead of `fetch('/api/…')`. The sequence-token and
   loading-timer code can go (routing is now synchronous); keep `message` handling identical.
   Map loading becomes one fetch (`/maps/${zone}.txt`) plus two local calls.

Done when: every planner interaction behaves the same with the PHP API no longer being called
(verify in the Network tab: only `/maps/*.txt` requests). Note: once Phase 2 removes
`public/index.php`, `php artisan serve` no longer boots — the PHP runtime is only reachable through
the old deployed host from then on, which is what the rollback relies on.

### Phase 2 — Static build

1. Move `planner.blade.php` → `index.html`. Replace: `@vite` → `<script type="module" src="/src/app.js">` and a `<link>` for the CSS; `url('/')`/`url('/og.png')` → literal `https://eqnta.com/…`; `{{ date('Y') }}` → a tiny `x-text` or hardcode; `{!! $jsonLd !!}` → inline the JSON-LD literal (it's static); the two `<x-zone-combobox field="…">` uses → inline the markup twice (it's ~30 lines) or an Alpine `x-component`-free copy. `app()->getLocale()` → `en`.
2. `vite.config.js`: drop `laravel-vite-plugin`, root stays repo root, `build.outDir = 'dist'`, keep `@tailwindcss/vite`. Remove `laravel-vite-plugin` and `concurrently` from devDependencies.
3. `scripts/build-maps.sh`: for each `short` in `graph.json`, copy `storage/app/eq/maps/{short}.txt` if non-empty, else `storage/app/eq/maps-goodurden/{short}.txt`, into `dist/maps/`. Reuses `fetch-vmaps.sh` for the download. One-liner in `package.json`: `"build": "vite build && bash scripts/build-maps.sh"`.
4. `public/`: delete `index.php` and `.htaccess`; add `CNAME` containing `eqnta.com`; keep favicons, `og.png`, `robots.txt`, `sitemap.xml`.
5. Local check: `npm run build && npx vite preview`.

Done when: `dist/` served by any static server is the whole site.

### Phase 3 — Deploy workflow + DNS cutover

`.github/workflows/deploy.yml` — on push to `main` and `workflow_dispatch`:

```yaml
permissions: { contents: read, pages: write, id-token: write }
concurrency: { group: pages, cancel-in-progress: true }
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: npm }
      - run: npm ci
      - run: npm test
      - run: npm run fetch:vmaps        # Brewall + Goodurden → storage/app/eq/ (gitignored)
      - run: npm run build              # vite build + maps copy → dist/
      - uses: actions/upload-pages-artifact@v3
        with: { path: dist }
  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment: { name: github-pages, url: ${{ steps.deployment.outputs.page_url }} }
    steps:
      - id: deployment
        uses: actions/deploy-pages@v4
```

Repo settings: Pages → Source = GitHub Actions; custom domain `eqnta.com`; Enforce HTTPS.

DNS (Cloudflare): `A @ → 185.199.108/109/110/111.153`, `CNAME www → <user>.github.io`, both
**DNS-only (grey cloud)**. GitHub's CDN (Fastly) serves the site with HTTPS. This removes the
Cloudflare Cache Rule and the "purge after every deploy" step from `CLAUDE.md` entirely:
GitHub Pages serves HTML with `cache-control: max-age=600`, and Vite's hashed asset names make
stale HTML harmless within that window.

Cutover order: set the custom domain in Pages settings **before** the first deploy (the build uses
root-absolute URLs, so the interim `<user>.github.io/<repo>/` URL would 404 its assets) → merge →
the workflow deploys → switch DNS → verify `curl -sI https://eqnta.com/` shows `server: GitHub.com`
→ decommission the PHP host.

Rollback: flip DNS back to the old host. Keep it up for a week.

### Phase 4 — Data refresh: one local script

The PHP pipeline (`eq:import-peq`, `eq:build-graph`, `eq:build-factions`, `eq:build-continents`,
`eq:audit-graph`) stays and runs locally. It needs MariaDB, which `compose.yaml` already provides.
No CI workflow for this: the deploy workflow fires on the push of the regenerated JSON, which is
the only part that needs automating.

`scripts/refresh-peq.sh`:

```bash
#!/usr/bin/env bash
# Pull the latest PEQ dump and regenerate database/eq/*.json. Commit + push the diff to deploy.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d mariadb --wait
npm run fetch:vmaps                        # build-graph reads Brewall for boats/books/coords
php artisan eq:import-peq --build          # import + build-graph + build-factions
php artisan eq:build-continents
php artisan eq:audit-graph
npm test                                   # graph integrity tests against the fresh JSON
git status --short database/eq
```

Add `"refresh:peq": "bash scripts/refresh-peq.sh"` to `package.json`. Review the diff, commit,
push; `deploy.yml` does the rest. The navmesh bake stays as it is today
(`npm run fetch:maps && npm run bake:paths`), run only after zone-line changes.

Skipped: a scheduled Actions workflow with a MariaDB service container that opens a PR. Add it
if PEQ refreshes become frequent enough that running one command locally is a chore.

### Phase 5 — Delete the PHP runtime

After Phase 3 has been live for a week:

- Delete `routes/web.php` + `routes/api.php` contents, `app/Http/Controllers/RoutingController.php`,
  `app/Services/Eq/{Router,FactionService,GraphDelta}.php`, `resources/views/`, `resources/js`,
  `resources/css`, `public/index.php`.
- Delete the PHP tests the JS tests replaced (`tests/Feature/RoutingTest.php`,
  `EraIsolationTest.php`, `tests/Unit/DeltaMatchTest.php`, `GraphIntegrityTest.php`,
  `ExampleTest`s). Keep `FactionOverrideTest.php` and `MapFilesTest.php` — they test the build
  commands, which stay. **This needs explicit sign-off; nothing in `tests/` is removed before the
  JS equivalent is green.**
- `BuildGraph.php` and `BuildFactions.php` import `GraphDelta` — move it to
  `app/Console/` (or keep the one class under `app/Services/Eq/`). Keep whichever is smaller.
- Remove `laravel/boost`, `laravel/pail`, `laravel/sail`, `laravel/tinker` from `composer.json`.
  Keep `laravel/framework` (the Artisan commands, `DB`, `Http`, `File` facades) and `pint`.
- Rewrite `README.md` (currently the Laravel skeleton README) and prune `CLAUDE.md` / `AGENTS.md`
  of the Laravel-runtime and Cloudflare-purge sections.
- Add a `test.yml` workflow (PR + push): `npm ci && npm test`.

Skipped: porting the PHP build commands to Node. ~900 lines of SQL-driven code with no user-visible
payoff; the workflow runs them fine. Revisit only if keeping Composer in CI becomes a nuisance.

## Risks and decisions

- **Map pack size.** Brewall + Goodurden are a few hundred `.txt` files for 564 zones, tens of MB.
  GitHub Pages' limit is 1 GB per site and a 100 GB/month soft bandwidth cap; the map requests are
  on-demand per expanded zone, as today. If bandwidth ever matters, re-enable the Cloudflare proxy
  (orange cloud) in front, which also brings back the purge step.
- **Brewall download in CI.** `fetch-vmaps.sh` scrapes eqmaps.info for the current zip. If the page
  changes shape the deploy fails loudly (it `exit 1`s) rather than shipping a mapless site. Mitigate
  with `actions/cache` keyed on the zip URL so a scrape failure after a cache hit still deploys;
  add when it bites, not before.
- **TLP unlock dates are resolved in the browser** using the visitor's clock instead of the server's.
  Off by at most one day around an unlock; acceptable. Use UTC date to match the current behaviour.
- **Bundle size.** Graph + factions in the JS bundle add ~80 KB gzipped to first load, versus two
  edge-cached JSON fetches today. Net it's one fewer round trip. Split them back into fetched files
  only if Lighthouse complains.
- **No server-side validation anymore.** Nothing to protect: there was no write path. The JS router
  still rejects unknown servers/zones so a malformed shared URL falls back to the empty state.
- **Cloudflare features lost by going DNS-only:** the Cache Rule (no longer needed), analytics,
  and the Rocket Loader `data-cfasync` attribute in the view (delete it). If any Cloudflare feature
  is wanted, keep the proxy and add a purge step (`curl` to the purge API with a scoped token in
  repo secrets) at the end of `deploy.yml`.
- **404s.** GitHub Pages returns its own 404 page for missing maps; the client already treats
  non-OK as "no map". A custom `404.html` is optional.

## Order of work and effort

| Phase | Work | Size |
|---|---|---|
| 1 | router.js + config.js + node tests + rewire app.js | ~1 day; the bulk of the migration |
| 2 | index.html, vite config, maps copy script, public/ cleanup | ~2 hours |
| 3 | deploy.yml, Pages settings, DNS cutover | ~1 hour + DNS propagation |
| 4 | scripts/refresh-peq.sh | ~30 minutes |
| 5 | delete PHP runtime, trim deps, docs | ~1 hour, after sign-off on test removal |

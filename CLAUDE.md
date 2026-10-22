# Project

Static EverQuest zone trip planner (see README.md). The site is `index.html` + `src/` built by Vite;
routing runs in the browser. PHP exists only for the offline PEQ → JSON data pipeline (`app/Console/Commands`,
`php artisan eq:*`). Do not add server-side runtime code.

## Conventions

- JS: plain ES modules, Alpine for the UI, no framework. Tests are `node --test` in `tests/js`
  (`npm test`); run them after touching `src/router.js`, `src/config.js`, or `database/eq/*.json`.
- PHP 8.3: curly braces always, constructor promotion, explicit return/param types, PHPDoc with array
  shapes. Run `vendor/bin/pint --dirty --format agent` after editing PHP. Tests are PHPUnit
  (`php artisan test --compact`), only for the build commands.
- Do not add dependencies or new top-level folders without asking. Do not create docs files unless asked.
- Server profiles, eras, TLP unlock dates and routing weights live in `src/config.js`. Add a server
  there — no code changes.


# Deployment

- The site is static: `npm run build` → `dist/`, published to GitHub Pages by `.github/workflows/deploy.yml` on every push to `main`. No PHP at runtime; `php artisan serve` no longer serves the site (the Artisan `eq:*` commands remain for data builds — see `scripts/refresh-peq.sh`).
- DNS for `eqnta.washclof.com` is DNS-only (grey cloud) so GitHub's CDN serves it; there is no Cloudflare cache to purge. If the Cloudflare proxy is ever re-enabled, add a purge step at the end of `deploy.yml` — the old 24h edge-cached HTML would otherwise reference deleted hashed assets.
- Local dev: `npm run dev` (Vite). Maps need `npm run fetch:vmaps` once.

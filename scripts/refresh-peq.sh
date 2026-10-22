#!/usr/bin/env bash
# Pull the latest PEQ dump and regenerate database/eq/*.json. Review the diff, commit, push —
# the Deploy workflow does the rest. Needs PHP 8.3 + composer (first run: composer install).
set -euo pipefail
cd "$(dirname "$0")/.."

[ -d vendor ] || composer install --no-interaction
[ -f .env ] || { cp .env.example .env && php artisan key:generate --no-interaction; }

docker compose up -d mariadb --wait
npm run fetch:vmaps                 # eq:build-graph reads Brewall for boats/books/coords
php artisan eq:import-peq --build   # import + eq:build-graph + eq:build-factions
php artisan eq:build-continents
php artisan eq:audit-graph
npm test                            # graph integrity against the fresh JSON

echo; git status --short database/eq

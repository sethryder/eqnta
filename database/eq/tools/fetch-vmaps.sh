#!/usr/bin/env bash
# Fetch the visual zone maps the app serves. These are gitignored (licensing — not ours to
# redistribute via the repo), so each box pulls them from the original source instead.
#   Brewall   -> storage/app/eq/maps/           (https://www.eqmaps.info/eq-map-files/)
#   Goodurden -> storage/app/eq/maps-goodurden/  (github.com/RedGuides/goodurden-maps, "Good's Maps")
# Re-run any time to refresh. Usage: npm run fetch:vmaps
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Brewall: scrape the current dated zip off the downloads page so the URL never goes stale.
BREWALL_DIR="$ROOT/storage/app/eq/maps"
url=$(curl -fsSL https://www.eqmaps.info/eq-map-files/ \
  | grep -oE 'https://[^"'"'"']+brewall-[0-9]+\.zip' | head -1)
[ -n "$url" ] || { echo "brewall: no zip link found on eqmaps.info"; exit 1; }
echo "brewall: $url"
curl -fsSL "$url" -o "$TMP/brewall.zip"
mkdir -p "$BREWALL_DIR"
unzip -oq "$TMP/brewall.zip" -d "$BREWALL_DIR"
echo "brewall: $(find "$BREWALL_DIR" -name '*.txt' | wc -l) files"

# Goodurden: repo tarball; strip the two leading path segments so "Good's Maps/*.txt" lands flat.
GOOD_DIR="$ROOT/storage/app/eq/maps-goodurden"
curl -fsSL https://github.com/RedGuides/goodurden-maps/archive/refs/heads/main.tar.gz -o "$TMP/good.tgz"
mkdir -p "$GOOD_DIR"
tar -xzf "$TMP/good.tgz" -C "$GOOD_DIR" --strip-components=2 "goodurden-maps-main/Good's Maps/"
echo "goodurden: $(find "$GOOD_DIR" -name '*.txt' | wc -l) files"

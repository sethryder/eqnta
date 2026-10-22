# TLP (progression) servers

Named Time-Locked Progression servers in the Era picker. **Frostreaver** sits in the main server list
(newest); the rest are grouped under a collapsible **"TLP Servers"** sub-list (`group: 'tlp'` in
`src/config.js`).

## How the expansion set is determined

Each server resolves its enabled expansions at request time (`serverExpansions()` in `src/router.js`):

- **`unlocks` schedule** (actively-progressing servers): a map of expansion ordinal → unlock date.
  Classic is always on; each expansion auto-enables once its date passes. **No manual upkeep** — the
  set advances on its own, and the post-RoF graph + incomplete-route banner compose automatically.
- **static `expansions`** (caught-up or era-locked servers): a fixed list.

## Roster (state as of June 2026)

| Server | Type | Launched | Current era | Data |
|--------|------|----------|-------------|------|
| Frostreaver | Player-voted ruleset | 2026 | Scars of Velious | schedule (official) |
| Fangbreaker | Expanded-start / Resource Hunter | 2025-05-28 | Gates of Discord | schedule |
| Teek | Random Loot, Free-Trade | 2024-05-22 | Secrets of Faydwer | schedule |
| Tormax | Standard TLP | 2024-05-22 | Secrets of Faydwer | schedule |
| Oakwynd | Evolving / Legacy Characters | 2023-05-24 | Rain of Fear | schedule |
| Yelinak | True-Box | 2022-05-25 | Empires of Kunark | schedule |
| Vaniki | Level-Locked | 2022-05-25 | Shattering of Ro (caught up) | static all |
| Mischief | Random Loot | 2021-05-26 | Night of Shadows | schedule |
| Aradune | Standard TLP | 2020-05-27 | Laurion's Song | schedule |
| Mangler | Standard TLP | 2019-03-16 | Shattering of Ro (caught up) | static all |
| Agnarr | **PoP-era locked** | 2017-05-24 | Lost Dungeons of Norrath (permanent) | static [0–6] |

## Notes / caveats

- **Schedules** are from the official rulesets/news + [TLP Tracker](https://tlptracker.com) and the
  ZAM/Allakhazam progression wikis. **Near-term dates are confirmed/historical; far-future dates
  (roughly 1+ year out) are projections** from the published cadence and may shift a few days. That's
  fine for routing — a zone may light up a couple days early/late at worst.
- **Caught-up servers** (Vaniki, Mangler) are set to all expansions (`0–32`); when a new expansion is
  added to the catalog, bump these (and `live`) to include it.
- **Agnarr** never changes — permanently locked at the Planes of Power era (Classic through Lost
  Dungeons of Norrath, ordinals 0–6).
- **Thornblade** is intentionally absent — it was merged into **Mischief** (2024-07-17). **Rizlona**
  likewise merged into **Aradune** (2026-04-15).
- Adding a future TLP = one config entry with a `group: 'tlp'` + an `unlocks` schedule. No code.

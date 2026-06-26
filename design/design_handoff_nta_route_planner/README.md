# Handoff: Norrath Transit Authority — EverQuest Zone Route Planner

## Overview
A trip-planning web app for EverQuest, styled after a public-transit (MTA-style) journey
planner. A player picks a **departing** zone and a **destination** zone, their **class** and
**race**, and a **routing priority** (Fastest vs Safest); the app computes a step-by-step
itinerary across the world, rendering each leg as a colored "line" on a transit spine
(run / boat / class teleport / etc.) with hazard and faction warnings.

A header **ruleset selector** governs which game era / expansions are in play (Project 1999,
Live, a Time-Locked Progression server, or a manual Custom expansion set), which in turn
gates the available travel "lines."

Scope of this prototype: **classic-continent zones only** (Antonica, Faydwer, Odus, plus the
sea routes). See **Known Gaps & TODOs** at the bottom — several systems are scaffolded in the
UI but not yet backed by full data.

## About the Design Files
The single file in this bundle — `Norrath Transit Authority.dc.html` — is a **design
reference created in HTML**, not production code to ship as-is. It is a self-contained
prototype demonstrating the intended look, layout, data model, and interactions.

The task is to **recreate this design in the target codebase's environment** (e.g. a React/
Next app, Vue, etc.) using that project's established patterns, component library, and state
management. If no codebase exists yet, choose an appropriate stack (React + a routing/graph
lib is a natural fit) and implement there. The HTML's inline-styled markup and hand-rolled
`Component` class are an authoring convenience of the prototyping tool — reorganize freely
into idiomatic components, hooks/stores, and a real module structure.

> Note on the file format: the prototype is a "Design Component" — the markup lives between
> `<x-dc>…</x-dc>` and the logic in a `class Component` inside the trailing
> `<script type="text/x-dc">`. Read it as plain HTML + a plain JS class. `renderVals()` returns
> the values the template binds to via `{{ }}` holes. There is no framework dependency you
> need to carry over.

## Fidelity
**High-fidelity.** Colors, typography, spacing, the two-theme system, the transit-spine
treatment, and all interactions are final and intentional. Recreate the UI to match. The
**routing logic is real and functional** (a working weighted graph + Dijkstra), but the
underlying **data set is a curated classic-era subset**, not the complete EverQuest world —
treat the algorithm as the spec and expand the data.

---

## Screens / Views

This is a **single-screen app** with one overlay (the ruleset popover). Layout is a sticky
header above a two-column grid: a sticky planner panel on the left, the itinerary results on
the right.

### 1. App shell / header
- **Layout:** horizontal flexbox, `padding:18px 30px`, `border-bottom:1px solid var(--bd)`,
  `background:var(--header)` with `backdrop-filter:blur(6px)`, `position:sticky; top:0; z-index:20`.
- **Components (left→right):**
  - **Logo badge** — 46×46 circle, `border:2.5px solid var(--tx)`, `background:var(--panel2)`.
    Stacked "N" (Oswald 700, 20px) over "T·A" (JetBrains Mono, 5.5px, letter-spacing 0.18em, `var(--tx3)`).
  - **Title block** — "NORRATH TRANSIT AUTHORITY" (Oswald 600, 21px, letter-spacing 0.12em)
    over "ZONE TRIP PLANNER · EST. 1999" (JetBrains Mono, 10.5px, letter-spacing 0.16em, `var(--tx3)`).
  - **Ruleset selector button** (pushed right) — pill: `padding:5px 11px 5px 12px`,
    `border:1px solid var(--bd2)`, `border-radius:20px`, `background:var(--panel)`. Contents:
    a 7px green dot (`#3fb950`), the live badge text (e.g. `P99 · VELIOUS`), and a small `▼`.
    Opens the **Ruleset popover** (see below).
  - **Theme toggle** — pill, `background:var(--chip)`, `border:1px solid var(--bd2)`. Label
    `☾ DARK` / `☀ LIGHT`. Toggles theme, persists to `localStorage['nta-theme']`, and also sets
    `document.body.style.background` so overscroll matches.

### 2. Planner panel (left column)
- **Layout:** `background:var(--panel)`, `border:1px solid var(--bd)`, `border-radius:14px`,
  `padding:22px`, `position:sticky; top:96px`. Column width `minmax(300px,348px)`; grid `gap:26px`.
- **Components (top→bottom):**
  - **"● PLAN A TRIP"** section heading — Oswald 600, 15px, letter-spacing 0.14em, `var(--tx2)`,
    with an 8px `#3fb950` dot.
  - **Field group labels** — JetBrains Mono, 10px, letter-spacing 0.16em, uppercase, `var(--tx3)`.
  - **Departing** `<select>` — full-width, `padding:11px 34px 11px 13px`, `background:var(--panel2)`,
    `border:1px solid var(--bd2)`, `border-radius:9px`, `color:var(--tx)`, 14.5px. Native chevron
    hidden (`appearance:none`); custom `▼` absolutely positioned right. Options grouped by
    continent via `<optgroup>` (Antonica, Faydwer, Odus, At Sea).
  - **SWAP button** — centered pill between the two selects; swaps from/to. `background:var(--chip)`,
    `border:1px solid var(--bd2)`, JetBrains Mono 10px, with a vertical swap SVG icon.
  - **Destination** `<select>` — identical styling to Departing.
  - **Traveler row** — 2-col grid (`gap:8px`): **Class** select and **Race** select (13.5px,
    `padding:10px 28px 10px 12px`). Class options append " ✦" to porting classes (Druid, Wizard).
  - **Routing priority** — segmented control: two buttons in a `border:1px solid var(--bd2)`,
    `border-radius:9px` container. Active button `background:#1f6feb; color:#fff`; inactive
    `background:var(--panel2); color:var(--tx3)`. JetBrains Mono 11px, letter-spacing 0.14em, 700.
  - **Mode hint** — 12px `var(--tx3)` helper text that changes with Fastest/Safest.

### 3. Itinerary results (right column)
- **Result header:** "ITINERARY" eyebrow (JetBrains Mono 10px, letter-spacing 0.2em, `var(--tx3)`)
  above `{fromLabel}  →  {toLabel}` (Oswald 600, 25px) with an arrow SVG in `var(--tx3)`.
- **Primary service card:** `background:var(--panel)`, `border:1px solid var(--bd)`,
  `border-radius:14px`.
  - **Card header:** a colored **service tag** (Oswald 600, 15px, white text, `border-radius:7px`,
    background = service color) + right-aligned metadata pills (`{n} stops`, `~{n} min`) and a
    **hazard pill** (green "no hazards" or red "{n} hazard(s)"). Service tag text & color:
    - Druid/Wizard port route → "Druid Grove Express" / "Wizard Arcane Express" (purple/magenta)
    - Has a boat leg → "Land & Sea Route" (blue `#2f81f7`)
    - Otherwise → "Overland Route" (green `#3fb950`)
  - **Transit spine:** vertical list of stations. Each row is a 30px-wide spine column
    (top bar / bullet / bottom bar) + content. The **bar segments are colored by the leg's
    travel mode**, forming a continuous multi-color line. Bullets are 26px circles: the origin
    is a hollow ring (`background:var(--panel2)`, 3px colored border); leg bullets are filled
    with the mode color and hold a 15px white mode icon; the final bullet gets a
    `0 0 0 4px <color>33` halo.
  - **Station content:** origin shows "DEPART" + zone name (Oswald 500, 18px). Each leg shows a
    mono **line name** in the mode color (e.g. "DRUID GROVE LINE"), an optional red danger chip,
    the destination zone name, and an optional note (13px `var(--tx3)`).
- **Overland service card (alternative):** only rendered when the primary route used a teleport.
  Same spine treatment, dimmer chrome (`background:var(--panel2)`-ish header), labeled
  "OVERLAND SERVICE · no ports · group-friendly". Lets non-porting group members follow along.
- **Line Guide (legend):** grid of all 8 travel modes, each a 28px rounded square in the mode
  color with a white icon + name + tag. **Modes whose required expansion is not enabled render
  "locked"**: greyed square (`background:var(--chip)`, 0.7 opacity, icon in `var(--tx3)`), name
  in `var(--tx3)`, tag = "LOCKED · NEEDS {EXP}".

### 4. Ruleset popover (overlay)
Opens from the header selector button. A full-viewport transparent catcher
(`position:fixed; inset:0; z-index:50`) closes it on outside click; the panel is
`position:absolute; right:0; top:42px; width:340px; z-index:60`, `background:var(--panel)`,
`border:1px solid var(--bd2)`, `border-radius:14px`, `box-shadow:0 18px 44px rgba(0,0,0,0.4)`.
- **Tab bar:** two segment buttons "RULESET" / "EXPANSIONS" (JetBrains Mono 10px). Active tab
  `background:var(--chip); color:var(--tx)`; inactive transparent / `var(--tx3)`.
- **Ruleset tab:** three preset rows (button cards, `border-radius:10px`), each with a colored
  dot, name (Oswald 600, 14px), description, and a green ✓ when active:
  - **Project 1999** (`#a371f7`) — "Classic → Velious, frozen"
  - **Live** (`#2f81f7`) — "Every expansion unlocked"
  - **Time-Locked Progression** (`#3fb950`) — "Pick a server & unlock point"
  - When TLP is active, a sub-panel appears with a **Server** select (Teek, Oakwynd, Yelinak,
    Tormax, Vaniki, Mischief) and an **Unlocked through** expansion select.
- **Expansions tab:** intro line + a checklist of expansions (Classic, Ruins of Kunark, Scars of
  Velious, Shadows of Luclin, Planes of Power), each a toggle row with a 20px checkbox (checked =
  `#3fb950`), name + year, and the travel feature it unlocks. **Toggling any expansion sets the
  ruleset to "Custom".** Footer note explains Live unlocks everything beyond.

---

## Interactions & Behavior
- **Live recompute:** every input (from, to, class, race, mode, ruleset/expansions) recomputes
  the route immediately — no submit button.
- **Swap:** swaps from/to zones.
- **Fastest vs Safest:** changes the cost function (see Routing Algorithm). Safest avoids hazard
  zones and KOS (kill-on-sight) cities when an alternative exists; Fastest ignores danger.
- **Ruleset selector:** toggles open/closed; outside-click closes; tabs switch; preset selection
  and expansion toggles update the badge and the locked/unlocked line set live.
- **Theme toggle:** flips dark/light via a `data-theme` attribute on the root; persisted.
- **Edge cases handled:** same-zone selection ("You're already there"), and no-route
  ("No charted route…").
- **Animations/transitions:** none beyond default; the design is intentionally crisp/instant.
  (One `@keyframes eqFloat` is defined but unused — safe to drop.)

## State Management
Current prototype state (all client-side, in the `Component` class):
- `from`, `to` — zone ids
- `klass`, `race` — traveler selection
- `mode` — `'fastest' | 'safest'` (defaults to the `defaultMode` prop / `'safest'`)
- `theme` — `'dark' | 'light'`, persisted to `localStorage['nta-theme']`
- `rulesetOpen` — popover visibility
- `ruleset` — `'p99' | 'live' | 'tlp' | 'custom'`
- `rulesetTab` — `'ruleset' | 'exp'`
- `tlpServer` — string; `tlpThrough` — expansion id
- `customExp` — `{ [expId]: boolean }`

Derived per render: the enabled-expansion set, the badge text, the primary route legs, the
alternative overland legs (only when the primary used a teleport), per-route summaries
(stops / time / hazards), the legend with locked flags, and all the inline style objects.

## Data Model (port this — it's the heart of the app)
- **`ZONES`**: `{ id: { label, cont } }`. `cont` ∈ Antonica / Faydwer / Odus / At Sea. ~47 zones.
- **`EDGES`**: array of `[a, b, mode, time, danger?, note?, hazardLabel?]`. Undirected (the
  builder adds both directions). `mode` ∈ run / boat. `time` is a rough minutes weight.
  `danger` > 0 flags a hazardous leg (currently Kithicor Forest, both directions, danger 3,
  "DEADLY AFTER DARK").
- **`MODES`**: `{ run, boat, druid, wizard, translocator, gate, spire, pop }` → `{ code, color, name }`.
- **`PORTS`**: per-class teleport lists `{ druid:[…], wizard:[…] }`, each `{ to, spell }`. Injected
  as zero-ish-cost edges from anywhere to the port target when the traveler's class can port.
- **Faction:** `GOOD_CITIES`, `EVIL_CITIES` sets + `RACE_ALIGN` map → `isKOS(zone, race)`.
- **`EXPS`**: ordered expansion list `{ id, short, name, year, unlocks }`.
- **`reqExp`** (in `renderVals`): maps each travel mode → the expansion that unlocks it
  (run/boat/druid/wizard/gate → classic, translocator/spire → luclin, pop → pop).

## Routing Algorithm
- **`dijkstra(from, to, useTele, mode, race, klass)`** — standard Dijkstra over the adjacency
  list. When `useTele`, the class's port targets are added as edges (time ≈ 0.6) from every node.
- **`edgeCost(e, mode, race)`** — base = `e.time`; in **Safest** mode add `danger*120` and `+90`
  if the destination is KOS for the race. (The large penalties make Safest strongly prefer
  avoiding danger but still find a path if it's the only option.)
- The primary route uses teleports if the class can; if it did, a **second** Dijkstra with
  `useTele=false` produces the overland alternative.

## Design Tokens

### Theme variables (CSS custom properties on `[data-theme]`)
| Token | Dark | Light |
|---|---|---|
| `--bg` | `#0a0e14` | `#e9edf2` |
| `--panel` | `#11161f` | `#ffffff` |
| `--panel2` | `#0d1117` | `#f4f6f9` |
| `--chip` | `#161b22` | `#eaeef4` |
| `--bd` | `#21262d` | `#dce2ea` |
| `--bd2` | `#30363d` | `#c4ccd8` |
| `--tx` | `#e6edf3` | `#15191f` |
| `--tx2` | `#adbac7` | `#475160` |
| `--tx3` | `#7d8590` | `#6b7480` |
| `--header` | `rgba(13,17,23,0.7)` | `rgba(255,255,255,0.82)` |

### Travel-line / mode colors (constant across themes)
- run `#3fb950` · boat `#2f81f7` · druid `#a371f7` · wizard `#e668c4` ·
  translocator `#2bb4a6` · gate `#f778ba` · spire `#58a6ff` · pop `#e3b341`
- Active toggle / accent blue: `#1f6feb`
- Hazard red: `#f85149` (chips: `rgba(248,81,73,0.13)` fill, `rgba(248,81,73,0.4)` border)
- Success green (no-hazard chip): `#3fb950` (`rgba(63,185,80,0.12)` fill, `…,0.35` border)

### Typography
- **Display / headings / labels:** `Oswald` (400/500/600/700)
- **Mono / eyebrows / badges / tags:** `JetBrains Mono` (400/500/700)
- **Body / form controls:** `Helvetica Neue, Helvetica, Arial, sans-serif`
- (Fonts loaded from Google Fonts in the prototype; swap to the codebase's font pipeline.)

### Radii & misc
- Cards / popover: `14px` · inputs: `9px` · popover selects: `8px` · pills & badges: `20px` ·
  service tag: `7px` · legend dots: `7px` (28px square) · station bullets: `50%` (26px)
- Popover shadow: `0 18px 44px rgba(0,0,0,0.4)`
- Header blur: `backdrop-filter: blur(6px)`

## Assets
- **No external image assets.** All icons (run/boat/teleport/gate/translocator/spire/plane-book,
  swap, arrow, chevrons) are **inline SVGs** drawn with `stroke="currentColor"`/explicit color —
  reproduce as an icon component set. Mode icons are generated in the `icon(mode, size, color)`
  helper; copy those path definitions.
- Fonts: Google Fonts (Oswald, JetBrains Mono).

## Files
- `Norrath Transit Authority.dc.html` — the complete prototype (markup in `<x-dc>`, logic in the
  trailing `<script type="text/x-dc">`). Read `renderVals()` for the data→view mapping and the
  data tables (`ZONES`, `EDGES`, `MODES`, `PORTS`, `EXPS`, faction sets) near the top of the class.

---

## Known Gaps & TODOs (intentional — wire up in the real build)
1. **Zone graph is classic-continent only.** Kunark, Velious, Luclin, and PoP zones are not in
   `ZONES`/`EDGES` yet. Enabling those expansions currently only gates the Line Guide and the
   badge — it does **not** add new routable zones or travel. Expanding the graph (especially
   **PoP → Plane of Knowledge book travel**, which reshapes routing dramatically) is the big
   next step.
2. **TLP server unlock state is manual.** The "Unlocked through" select is user-set; there is no
   live/known TLP progression data. Wire real per-server unlock state when available.
3. **Port lists & travel times are plausible placeholders**, not verified P99 data. Confirm the
   exact Druid/Wizard port spells, destinations, and reagent/level constraints before relying on
   them. The `time` weights on edges are rough.
4. **Faction model is coarse** — a simple good/evil/neutral race alignment vs. a few good/evil
   city sets. Real EQ faction is far more granular (per-NPC, quest-adjustable). Refine as needed.
5. **Translocator / Spire / Gate / Plane-Book lines** exist in the legend and `MODES` but have no
   edges yet — they light up as "unlocked" with the right expansion but don't produce route legs.

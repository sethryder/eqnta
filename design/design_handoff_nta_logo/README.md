# Handoff: Norrath Transit Authority — Logo / Identity Mark

## Overview
The **NTA roundel** is the brand mark for the Norrath Transit Authority — a fictional
EverQuest "zone trip planner" styled after civic public-transit identities. The mark is a
filled disc carrying a heavy condensed **NTA** wordmark, a knowing nod to transit-agency
roundels (e.g. the MTA monogram). This package documents the mark precisely enough to
rebuild it from scratch.

## About the Design Files
The files in this bundle are **design references created in HTML** — a prototype showing the
intended look of the mark, not production code to ship as-is. The task is to **recreate the
mark in the target codebase's environment** (React/Vue component, SVG asset, native view,
favicon, etc.) using that project's established patterns. The mark is pure typography on a
circle, so it can be rebuilt as a tiny component, an inline SVG, or an exported asset —
whichever the target app needs.

## Fidelity
**High-fidelity.** Final colors, typography, sizing ratios, and spacing are all specified
below. Recreate pixel-for-pixel.

## The Mark

### Construction
- **Shape:** a perfect circle (the "disc"). Never an ellipse.
- **Wordmark:** the three letters `N`, `T`, `A` set in **Oswald 700**, uppercase, centered
  in the disc, baseline-aligned, with **letter-spacing −0.01em**.
- **Size relationship (critical):** the lead **N is larger** than the trailing **TA**. This
  asymmetry anchors the read and balances the lockup inside the circle. The three letters
  must **not** be set at equal size.
- The wordmark sits on a single baseline (`display:inline-flex; align-items:baseline`) and is
  clipped by the disc (`overflow:hidden`), so the cap tops and the letter feet kiss the
  disc's interior.

### Sizing ratios (relative to disc diameter `D`)
| Element | Value |
|---|---|
| `N` font-size | ≈ **0.52 × D** |
| `TA` font-size | ≈ **0.46 × D** |
| letter-spacing | −0.01em (−0.02em at very small sizes) |

Reference instances actually used in the prototype:

| Disc diameter | `N` px | `TA` px |
|---|---|---|
| 46px (app header) | 24 | 21 |
| 32px | 17 | 15 |
| 24px (minimum) | 13 | 11 |
| 58px (lockup) | 30 | 26 |
| 84px | 44 | 38 |
| 180px (hero) | 94 | 82 |

### Color variants
1. **Reversed / on dark (primary in-app):** disc `#E6EDF3`, type `#0A0E14`.
2. **Primary / on light:** disc `#15191F`, type `#E9EDF2`.
3. **Outline / single-color:** transparent disc, **2px** stroke in the foreground color, type
   in the same foreground color. Use when only one ink is available.

### Clear space
Maintain clear space equal to **½ × the disc diameter** on all sides. Keep type, layout
edges, and other marks outside that zone.

### Minimum size
**24px** disc diameter. Below 24px the three letters lose legibility — fall back to the
standalone **N** monogram (same Oswald 700, same disc).

## Horizontal lockup
Mark + wordmark, used in the app header:
- Disc (e.g. 58px) · optional 1px vertical rule divider (`#30363D`, ~46px tall) · text block.
- Gap between mark and text ≈ the badge's interior padding (~20px at 58px disc).
- **Wordmark line:** `NORRATH TRANSIT AUTHORITY` — Oswald 600, letter-spacing 0.12em,
  line-height 1.
- **Tagline line:** `ZONE TRIP PLANNER · EST. 1999` — JetBrains Mono, ~11px,
  letter-spacing 0.16em, color `#7D8590`.

## Design Tokens

### Colors
| Hex | Role |
|---|---|
| `#E6EDF3` | Foreground / disc (on dark) |
| `#0A0E14` | Background / type-on-disc (on dark) |
| `#15191F` | Disc (on light) |
| `#E9EDF2` | Type / page bg (on light) |
| `#2F81F7` | Accent — links / focus |
| `#A371F7` | Accent — teleport lines |
| `#3FB950` | Accent — status / safe |
| `#F85149` | Negative — errors / "don't" |
| `#21262D` / `#30363D` | Borders |
| `#ADBAC7` / `#7D8590` | Secondary / tertiary text |

### Typography
- **Oswald** — weight **700** for the mark and lockup wordmark; **600** for headings.
  (Google Fonts: `Oswald:wght@400;500;600;700`)
- **JetBrains Mono** — weights **400 / 500** for labels, metadata, and data.
  (Google Fonts: `JetBrains+Mono:wght@400;500;700`)

## Usage — Do / Don't
**Do:** keep the disc a perfect circle · preserve the N > TA size relationship · honor the
½-diameter clear space · use the outline variant when limited to one color.

**Don't:** stretch, rotate, or skew the disc · recolor the type to an accent hue · add a drop
shadow or inner bevel · set the three letters at equal size.

## Reference implementation (HTML/CSS)
The mark as built in the prototype — a 46px header badge:

```html
<div style="width:46px; height:46px; border-radius:50%; background:#E6EDF3;
            display:flex; align-items:center; justify-content:center; overflow:hidden;">
  <span style="font-family:'Oswald',sans-serif; font-weight:700; color:#0A0E14;
               letter-spacing:-0.01em; line-height:1;
               display:inline-flex; align-items:baseline;">
    <span style="font-size:24px;">N</span><span style="font-size:21px;">TA</span>
  </span>
</div>
```

Suggested React component shape (recreate in the target app's conventions):

```jsx
function NtaMark({ size = 46, variant = "reversed" }) {
  const v = {
    reversed: { disc: "#E6EDF3", ink: "#0A0E14", border: "none" },
    light:    { disc: "#15191F", ink: "#E9EDF2", border: "none" },
    outline:  { disc: "transparent", ink: "#E6EDF3", border: "2px solid #E6EDF3" },
  }[variant];
  return (
    <span style={{
      width: size, height: size, borderRadius: "50%",
      background: v.disc, border: v.border, overflow: "hidden",
      display: "inline-flex", alignItems: "center", justifyContent: "center",
    }}>
      <span style={{
        fontFamily: "Oswald, sans-serif", fontWeight: 700, color: v.ink,
        letterSpacing: "-0.01em", lineHeight: 1,
        display: "inline-flex", alignItems: "baseline",
      }}>
        <span style={{ fontSize: size * 0.52 }}>N</span>
        <span style={{ fontSize: size * 0.46 }}>TA</span>
      </span>
    </span>
  );
}
```

## Files in this bundle
- `NTA Logo Handoff.dc.html` — the full identity sheet (mark, lockup, clear space, color
  variants, minimum sizes, specs, do/don't). Open in a browser to view.
- `NTA Logo Handoff.html` — the same sheet as a single self-contained offline file.

## Assets
None external. The mark is pure typography (Oswald 700) on a CSS circle — no image assets.
Fonts load from Google Fonts; self-host equivalents in production if offline support is
required.

# Post-Rain-of-Fear expansion travel

How a player first reaches each post-RoF expansion from the rest of the world, and the hub zone
the rest of the expansion branches off. Edges live in `database/eq/postrof.json` (CoTF in
`overrides.json`); these are community-sourced and best-effort, so the Router flags any route
crossing them as incomplete (`limitedDataExpansion` in `src/config.js`).

`from` zones below are existing graph nodes; the **hub** is the post-RoF zone you land in.

| # | Expansion | Hub (short) | Primary entry |
|---|-----------|-------------|---------------|
| 20 | Call of the Forsaken | `ethernere` | Bonfire in West Karana (`qey2hh1`) → Ethernere; Lady Lendiniara's portals fan out to the other zones (attunement-gated) |
| 21 | The Darkened Sea | `tempesttemple` | Monkey Rock pillar in The Buried Sea (`buriedsea`) → Tempest Temple; Guild Hall portal → Katta Castrum |
| 22 | The Broken Mirror | `pohealth` | White crystal in SW Plane of Tranquility (`potranquility`) → Plane of Health; chain inward to Sul Vius / the Crypts |
| 23 | Empires of Kunark | `lceanium` | Guild Hall portal (Fragment of the Combine Spire) → Lceanium; or Nexus port stone. Also old-Kunark zone lines (`skyfire`, `overthere`) |
| 24 | Ring of Scale | `overtheretwo` | Guild Hall / Guild Lobby portal → The Overthere (RoS revamp) |
| 25 | The Burning Lands | `stratos` | Portal in Plane of Tranquility (`potranquility`) → Stratos; Guild Hall portal. Gnome Memorial Mountain: cannon in Dragonscale Hills (`dragonscale`) |
| 26 | Torment of Velious | `eastwastestwo` | NPC Belinda in North Ro (`nro`) → Eastern Wastes; Guild Hall gemstone portal |
| 27 | Claws of Veeshan | `cobaltscartwo` | From ToV Kael Drakkel (`kaeltwo`) west side → Cobalt Scar; druid/wizard ports; Guild Hall Othmir Clamshell |
| 28 | Terror of Luclin | `maidentwo` | Say `stones` to a concerned shade in The Nexus (`nexus`) → Maiden's Eye |
| 29 | Night of Shadows | `shadeweavertwo` | PoK "Shar Vahl Teleport Stone" → Shadeweaver's Tangle; Guild Hall token → Shar Vahl Divided; open-world from ToL Umbral Plains (`umbraltwo`) → Firefall Pass |
| 30 | Laurion's Song | `laurioninn` | Portal in Plane of Tranquility (`potranquility`) → Laurion Inn; druid/wizard ports; Guild Hall. Strict hub-and-spoke from the Inn |
| 31 | The Outer Brood | `hodstock` | Zone lines from East Freeport (`freporte`) and Nektulos Forest (`nektulos`) → Hodstock Hills; Guild Hall portal. Griffon up to the Leviathan zones |
| 32 | Shattering of Ro | `embattledpogrowth` | Burning tree in The Wakening Lands (`wakening`) → Scarred Grove; druid/wizard ports; Guild Hall. Linear chain inward |

## Notes / soft spots to verify on a real build

- **NoS** open-world entry uses `umbraltwo` (the ToL Umbral Plains revamp) — the source said "Terror of Luclin's Umbral Plains" but wrote the classic short; verify which zone line is correct.
- **RoS** `frontiermtnsb ↔ overthere` is flagged RoS-era; may not exist at EoK launch.
- Many inner zone lines (TBM, NoS Deepshade/Darklight, Shattering) are **progression-gated** in game but modeled as open — correct for "can I get there", not "is it unlocked yet".
- Druid/Wizard port spells (cast from anywhere) are mostly not modeled as fixed edges; only fixed in-world portals/NPCs are.

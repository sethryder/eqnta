<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    {{-- Set theme before paint (no flash): saved choice, else OS preference, else dark. --}}
    <script>
        document.documentElement.dataset.theme =
            localStorage.getItem('nta-theme')
            || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Norrath Transit Authority · Zone Trip Planner</title>
    <meta name="description" content="Plan the fastest route between any EverQuest zones: overland runs, boats, Plane of Knowledge books, spires, and class ports, tuned to your server's expansion era. Every route is shareable.">
    <link rel="canonical" href="{{ url('/') }}">

    {{-- Open Graph / Twitter — routes are shared as links, so previews matter --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Norrath Transit Authority">
    <meta property="og:title" content="Norrath Transit Authority · Zone Trip Planner">
    <meta property="og:description" content="Chart the quickest trip across Norrath: boats, PoK books, spires, and class ports for your server's era. Shareable itineraries.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ url('/og.png') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Norrath Transit Authority · Zone Trip Planner">
    <meta name="twitter:description" content="Chart the quickest trip across Norrath for your server's era. Shareable itineraries.">
    <meta name="twitter:image" content="{{ url('/og.png') }}">

    {{-- JSON-LD built in the route: Blade mangles the literal "@context" key as a directive --}}
    <script type="application/ld+json">{!! $jsonLd !!}</script>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="nta" x-data="planner" x-cloak>

    <header class="nta-header">
        <div class="logo-badge"><span class="wm"><span class="n">N</span><span class="ta">TA</span></span></div>
        <div>
            <h1 class="title-main">NORRATH TRANSIT AUTHORITY</h1>
            <div class="title-sub">ZONE TRIP PLANNER · EST. 1999</div>
        </div>
        <div class="header-spacer"></div>

        {{-- Era selector --}}
        <div style="position:relative">
            <button class="pill" @click="eraOpen = !eraOpen" :aria-expanded="eraOpen">
                <span class="dot"></span>
                <span class="pill-badge" x-text="badge()"></span>
                <span style="font-size:.5rem">▼</span>
            </button>

            <template x-if="eraOpen">
                <div>
                    <div class="popover-catcher" @click="eraOpen = false"></div>
                    <div class="popover" @click.stop>
                        <div class="tab-bar">
                            <button class="tab-btn" :class="{ active: eraTab === 'era' }" @click="eraTab = 'era'">ERA</button>
                            <button class="tab-btn" :class="{ active: eraTab === 'exp' }" @click="eraTab = 'exp'">EXPANSIONS</button>
                        </div>

                        {{-- Era tab: server presets (TLP progression servers tucked into a sub-list) --}}
                        <div class="popover-body" x-show="eraTab === 'era'">
                            <template x-for="s in mainServers()" :key="s.key">
                                <button class="preset-row" :class="{ active: server === s.key }" @click="selectServer(s.key)">
                                    <span class="preset-dot" :style="`background:${presetColor(s.key)}`"></span>
                                    <div>
                                        <div class="preset-name" x-text="s.label"></div>
                                        <div class="preset-desc" x-text="expansionSummary(s)"></div>
                                    </div>
                                    <span class="preset-check" x-show="server === s.key">✓</span>
                                </button>
                            </template>

                            <template x-if="tlpServers().length">
                                <div class="tlp-group">
                                    <button class="tlp-toggle" :class="{ open: tlpOpen, active: tlpServers().some((s) => s.key === server) }"
                                            @click="tlpOpen = !tlpOpen" :aria-expanded="tlpOpen">
                                        <span class="tlp-caret" aria-hidden="true">▸</span>
                                        <div class="tlp-toggle-text">
                                            <div class="preset-name">TLP Servers</div>
                                            <div class="preset-desc" x-text="tlpPreview()"></div>
                                        </div>
                                        <span class="preset-check" x-show="tlpServers().some((s) => s.key === server)">✓</span>
                                    </button>
                                    <div class="tlp-list" x-show="tlpOpen">
                                        <template x-for="s in tlpServers()" :key="s.key">
                                            <button class="preset-row" :class="{ active: server === s.key }" @click="selectServer(s.key)">
                                                <span class="preset-dot" :style="`background:${presetColor(s.key)}`"></span>
                                                <div>
                                                    <div class="preset-name" x-text="s.label"></div>
                                                    <div class="preset-desc" x-text="expansionSummary(s)"></div>
                                                </div>
                                                <span class="preset-check" x-show="server === s.key">✓</span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Expansions tab: checklist --}}
                        <div class="popover-body" x-show="eraTab === 'exp'">
                            <template x-for="e in togglableExpansions()" :key="e.id">
                                <label class="exp-row">
                                    <span class="exp-check" :class="{ on: expEnabled(e.id) }" x-text="expEnabled(e.id) ? '✓' : ''"></span>
                                    <span class="exp-name" x-text="e.name"></span>
                                    <input type="checkbox" class="sr-only"
                                           :checked="expEnabled(e.id)" @change="toggleExp(e.id)">
                                </label>
                            </template>
                            <div class="popover-note">Toggling any expansion switches the world to a custom (TLP) set.</div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <button class="pill chip" @click="toggleTheme()">
            <span class="theme-icon" x-html="themeIcon()"></span>
            <span x-text="theme === 'dark' ? 'DARK' : 'LIGHT'"></span>
        </button>
    </header>

    <main class="nta-main">
        {{-- Planner panel --}}
        <section class="panel planner">
            <div class="section-head"><span class="dot"></span> PLAN A TRIP</div>

            {{-- Searchable, continent-grouped zone pickers, bound to `from`/`to` on the parent scope. --}}
            <div>
                <label class="field-label">Departing</label>
                <x-zone-combobox field="from" />
            </div>

            <div class="swap-row">
                <button class="swap-btn" @click="swap()" aria-label="Swap departing and destination">⇅ SWAP</button>
            </div>

            <div>
                <label class="field-label">Destination</label>
                <x-zone-combobox field="to" />
            </div>

            <div class="two-col">
                <div>
                    <label class="field-label" for="klass">Class</label>
                    <div class="select-wrap">
                        <select id="klass" class="nta" x-model="klass">
                            <option value="">— Any class —</option>
                            <template x-for="c in classes" :key="c.code">
                                <option :value="c.code" x-text="c.label + (c.port ? ' ✦' : '')"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="field-label" for="race">Race</label>
                    <div class="select-wrap">
                        <select id="race" class="nta" x-model="race">
                            <option value="">— Any race —</option>
                            <template x-for="r in races" :key="r.code">
                                <option :value="r.code" x-text="r.label"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>

            <div class="two-col">
                <div>
                    <label class="field-label" for="deity">Deity</label>
                    <div class="select-wrap">
                        <select id="deity" class="nta" x-model="deity">
                            <option value="">— Any deity —</option>
                            <template x-for="d in deities" :key="d.code">
                                <option :value="d.code" x-text="d.label"></option>
                            </template>
                        </select>
                    </div>
                </div>
                {{-- Level gates porting-class teleports and flags zones whose mobs out-level you. --}}
                <div>
                    <label class="field-label" for="level">Level</label>
                    <input id="level" class="nta" type="number" min="1" max="60" x-model.number="level">
                </div>
            </div>

            <div>
                <span class="field-label">Routing priority</span>
                <div class="segmented" role="group" aria-label="Routing priority">
                    <button class="seg-btn" :class="{ active: mode === 'fastest' }"
                            :aria-pressed="mode === 'fastest'" @click="mode = 'fastest'">FASTEST</button>
                    <button class="seg-btn" :class="{ active: mode === 'safest' }"
                            :aria-pressed="mode === 'safest'" @click="mode = 'safest'">SAFEST</button>
                </div>
            </div>

            <p class="mode-hint" x-text="modeHint()"></p>
        </section>

        {{-- Itinerary --}}
        <section>
            <div class="result-eyebrow">ITINERARY<span class="planning" x-show="loading" x-cloak> · planning…</span></div>
            <div class="result-title" x-show="from && to" x-cloak>
                <span x-text="zoneLabel(from)"></span>
                <span class="arrow">→</span>
                <span x-text="zoneLabel(to)"></span>
            </div>

            {{-- Same-zone / no-route message --}}
            <template x-if="(!route || !route.steps.length) && message">
                <div class="service-card">
                    <div class="empty-state" x-text="message"></div>
                </div>
            </template>

            {{-- First-run intro: shown until a trip is planned. --}}
            <template x-if="(!route || !route.steps.length) && !message">
                <div class="service-card intro-card">
                    <h2 class="intro-title">Plan a trip across Norrath</h2>
                    <p class="intro-lede">The Norrath Transit Authority charts the quickest way from any zone to any
                        other — stitching overland runs, boats, Plane of Knowledge books, spires, and class teleports
                        into a single itinerary.</p>
                    <ol class="intro-steps">
                        <li><b>Pick your zones.</b> Choose a departing and destination zone to chart a route.</li>
                        <li><b>Tune the trip.</b> Set class, race, deity, and level to unlock class ports (✦), and switch
                            between <b>Fastest</b> and <b>Safest</b> routing.</li>
                        <li><b>Match your server.</b> Use the <b>Era</b> selector up top to limit travel to the
                            expansions your server runs.</li>
                    </ol>
                    <p class="intro-foot">Every route is shareable. Your selections live in the page URL.</p>
                </div>
            </template>

            {{-- Service card with transit spine --}}
            <template x-if="route && route.steps.length">
                <div class="service-card">
                    <div class="service-header">
                        <span class="service-tag" :style="`background:${serviceTag().color}`" x-text="serviceTag().name"></span>
                        <div class="header-spacer"></div>
                        <span class="meta-pill" x-text="route.hops + (route.hops === 1 ? ' stop' : ' stops')"></span>
                        <span class="meta-pill" x-text="'~' + etaMinutes() + ' min'"></span>
                        <span class="hazard-pill" :class="hazardCount() ? 'danger' : 'clear'"
                              x-text="hazardCount() ? hazardCount() + (hazardCount() > 1 ? ' hazards' : ' hazard') : 'no hazards'"></span>
                    </div>

                    <template x-if="route.incomplete">
                        <div class="incomplete-note">
                            ⓘ This trip crosses zones past <strong>Rain of Fear</strong>. Those are mapped from community data and are best-effort — some connections may be missing and faction/KOS warnings aren't available for them yet.
                        </div>
                    </template>

                    <div class="spine-list">
                        <template x-for="(st, i) in stations()" :key="i">
                            <div class="station">
                                <div class="spine" :style="`color:${st.color}`">
                                    <template x-if="st.incoming">
                                        <div class="bar top" :style="`background:${st.incoming}`"></div>
                                    </template>
                                    <template x-if="st.outgoing">
                                        <div class="bar bottom" :style="`background:${st.outgoing}`"></div>
                                    </template>
                                    <div class="bullet"
                                         :class="{ origin: st.kind === 'depart' }"
                                         :style="st.kind === 'depart' ? '' : `background:${st.color}; box-shadow:${st.isLast ? '0 0 0 4px ' + st.color + '33' : 'none'}`">
                                        <template x-if="st.kind !== 'depart'"><span x-html="st.icon"></span></template>
                                    </div>
                                </div>
                                <div class="station-content">
                                    <template x-if="st.kind === 'depart'">
                                        <div>
                                            <div class="depart-label">Depart</div>
                                            <div class="zone-name" x-text="st.label"></div>
                                        </div>
                                    </template>
                                    <template x-if="st.kind === 'leg'">
                                        <div>
                                            <div class="line-name" :style="`color:${st.color}`" x-text="st.lineName"></div>
                                            <button type="button" class="zone-name zone-map-toggle"
                                                    :class="{ open: openMap === st.zone }"
                                                    @click="toggleMap(st)">
                                                <span x-text="st.label"></span>
                                                <span class="map-caret" aria-hidden="true">▸</span>
                                            </button>
                                            <template x-if="st.kos">
                                                <span class="danger-chip">⚠ Faction KOS here</span>
                                            </template>
                                            <template x-if="st.danger">
                                                <span class="danger-chip warn" x-text="`⚠ Lvl ${st.danger} mobs`"></span>
                                            </template>
                                            <div class="zone-map-panel" x-show="openMap === st.zone" x-cloak
                                                 x-html="mapSvg[st.zone] || ''"></div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Line guide --}}
            <div class="result-eyebrow" style="margin-top:28px">LINE GUIDE</div>
            <div class="guide-grid">
                <template x-for="g in legend()" :key="g.type">
                    <div class="guide-item" :class="{ locked: g.locked }">
                        <div class="guide-square" :style="`background:${g.color}`"><span x-html="g.icon"></span></div>
                        <div>
                            <div class="guide-name" x-text="g.name"></div>
                            <div class="guide-tag" x-text="g.tag"></div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <p class="footer-strip">
            © {{ date('Y') }} Norrath Transit Authority
            <span class="sep" aria-hidden="true">·</span>
            Maps © <a href="https://www.eqmaps.info" rel="noopener" target="_blank">Brewall</a>
            &amp; <a href="https://forums.everquest.com/index.php?threads/goods-maps-pack.238818/" rel="noopener" target="_blank">Good's Maps</a>
            <span class="sep" aria-hidden="true">·</span>
            Community-sourced — verify before long trips
        </p>
        <p class="footer-fine">
            EverQuest is a registered trademark of Daybreak Game Company LLC. The Norrath Transit Authority is an
            unofficial fan project, not affiliated with or endorsed by Daybreak.
        </p>
    </footer>
</body>
</html>

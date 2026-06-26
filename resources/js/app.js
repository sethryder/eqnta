import Alpine from 'alpinejs';

// Travel mode (edge `type`) -> line color, label, and a 20x20 white icon path.
const MODES = {
    walk: { color: '#3fb950', name: 'Overland', tag: 'Run', icon: 'M10 3.3a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zM10 6.6v4.2M10 10.8l-2.1 3.9M10 10.8l2.4 3.4M7.5 8.1l2.5 1 2.5-1.4' },
    boat: { color: '#2f81f7', name: 'Ferry', tag: 'Boat', icon: 'M4 13h12l-2 4H6zM10 4v9M10 4l4 3-4 2' },
    book: { color: '#e3b341', name: 'PoK Book', tag: 'Plane of Knowledge', icon: 'M4 5h12v11H4zM10 5v11' },
    spire: { color: '#58a6ff', name: 'Spire', tag: 'Spire', icon: 'M10 3l5 14H5z' },
    teleport: { color: '#a371f7', name: 'Teleport', tag: 'Class port', icon: 'M10 3l2 5 5 2-5 2-2 5-2-5-5-2 5-2z' },
    translocate: { color: '#2bb4a6', name: 'Translocate', tag: 'Translocator', icon: 'M15 10a5 5 0 1 1-1.5-3.5M14 4v3h-3' },
    evac: { color: '#f778ba', name: 'Evac', tag: 'Succor', icon: 'M7 6v4a4 4 0 0 0 8 0M7 6L4 9M7 6l3 3' },
};

// The lowest expansion ordinal that makes each mode routable, for the "locked" line-guide flags.
const MODE_REQ_EXP = { walk: 0, boat: 0, book: 4, spire: 3, teleport: 0, translocate: 3, evac: 0 };

// Rough minutes per leg by mode, for the itinerary's "~N min" estimate: a zone run is a few minutes,
// a boat adds dock wait, and ports/books/spires are near-instant. Deliberately approximate.
const MODE_MINUTES = { walk: 3, boat: 3, book: 0.5, spire: 0.5, teleport: 0.5, translocate: 0.5, evac: 0.5 };

// ponytail: rough EQ run speed (~46 units/sec → ~2750/min) for distance-based walk estimates.
// No SoW/mount modelling and the path is straight-line, not navmesh — a ballpark, clamped per leg.
const RUN_UNITS_PER_MIN = 2750;

function svgIcon(type, stroke = '#fff') {
    const m = MODES[type] || MODES.walk;
    const filled = type === 'book' || type === 'spire' || type === 'teleport';
    return `<svg viewBox="0 0 20 20" fill="${filled ? stroke : 'none'}" stroke="${stroke}"
        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="${m.icon}"/></svg>`;
}

Alpine.data('planner', () => ({
    // --- state ---
    theme: localStorage.getItem('nta-theme') || 'dark',
    from: '',
    to: '',
    klass: '',
    race: '',
    deity: '',
    level: 60,
    mode: 'fastest', // 'fastest' | 'safest'
    server: 'live',
    defaultServer: 'live',
    customExp: {}, // { [id]: true } — used when server === 'tlp'
    eraOpen: false,
    eraTab: 'era',
    zones: [],
    servers: [],
    expansions: [],
    route: null, // { steps, hops, weight }
    message: '', // empty-state text (same-zone / no-route)
    loading: false,
    openMap: null, // zone short of the currently expanded inline map, or null
    mapSvg: {}, // zone short -> rendered SVG markup (cached per route)
    classes: [], // [{ code, label, port? }] from /api/servers
    races: [],
    deities: [],

    async init() {
        document.documentElement.setAttribute('data-theme', this.theme);

        const meta = await fetch('/api/servers').then((r) => r.json());
        this.servers = meta.servers;
        this.expansions = meta.expansions;
        this.classes = meta.classes;
        this.races = meta.races;
        this.deities = meta.deities;
        this.defaultServer = meta.default;

        // Restore shareable state from the URL: ?from&to&server&class&race&deity&level&mode&exp
        const url = new URLSearchParams(location.search);
        const known = (list, code) => list.some((x) => x.code === code);

        // Era precedence: a shared URL wins, else the last era this browser used, else default.
        const savedServer = localStorage.getItem('nta-server');
        this.server = [url.get('server'), savedServer, meta.default].find((k) => this.servers.some((s) => s.key === k)) || meta.default;
        this.customExp = Object.fromEntries((this.serverConfig()?.expansions || []).map((id) => [id, true]));
        const expStr = url.get('exp') || localStorage.getItem('nta-exp');
        if (this.server === 'tlp' && expStr) {
            const ids = expStr.split(',').map(Number).filter((n) => !Number.isNaN(n));
            this.customExp = Object.fromEntries([0, ...ids].map((id) => [id, true]));
        }
        if (known(this.classes, url.get('class'))) { this.klass = url.get('class'); }
        if (known(this.races, url.get('race'))) { this.race = url.get('race'); }
        if (known(this.deities, url.get('deity'))) { this.deity = url.get('deity'); }
        if (['fastest', 'safest'].includes(url.get('mode'))) { this.mode = url.get('mode'); }
        const lvl = Number(url.get('level'));
        if (lvl >= 1 && lvl <= 60) { this.level = lvl; }

        await this.loadZones();
        // From/to come from the URL when valid for this world; otherwise leave blank so a first-time
        // visitor lands on the intro instead of a pre-filled route they didn't ask for.
        const valid = (s) => s && this.zones.some((z) => z.short === s);
        this.from = valid(url.get('from')) ? url.get('from') : '';
        this.to = valid(url.get('to')) ? url.get('to') : '';

        await this.compute();

        // Recompute when any routing input changes; reload the zone list when the world changes.
        this.$watch('server', async () => {
            this.customExp = Object.fromEntries((this.serverConfig()?.expansions || []).map((id) => [id, true]));
            await this.loadZones();
            this.compute();
        });
        ['from', 'to', 'klass', 'race', 'deity', 'level', 'mode'].forEach((k) => this.$watch(k, () => this.compute()));
        this.$watch('customExp', () => { if (this.server === 'tlp') { this.loadZones().then(() => this.compute()); } }, { deep: true });
    },

    // --- derived ---
    serverConfig() {
        return this.servers.find((s) => s.key === this.server);
    },
    enabledExpansionIds() {
        if (this.server === 'tlp') {
            // Classic (0) is the base game — always on, never a toggle.
            const ids = new Set([0]);
            Object.keys(this.customExp).filter((id) => this.customExp[id]).forEach((id) => ids.add(Number(id)));
            return [...ids];
        }
        return this.serverConfig()?.expansions || [];
    },
    // Toggleable expansions only: Classic (base game, 0) and Special (99) aren't eras you pick.
    togglableExpansions() {
        return this.expansions.filter((e) => e.id >= 1 && e.id <= 19);
    },
    // Human description of a server's era for the era popover (Classic is the base, not counted).
    expansionSummary(server) {
        if (!server.expansions) { return 'Pick the expansions below'; }
        const real = server.expansions.filter((id) => id >= 1 && id <= 19);
        if (real.length === 0) { return 'Base game · no expansions'; }
        // Name the latest enabled expansion rather than claiming "all" — the catalog stops at the
        // newest era we support, so "through <latest>" stays honest as more get added.
        const top = this.expansions.find((e) => e.id === Math.max(...real));
        return `Through ${top?.name || `${real.length} expansions`}`;
    },
    zoneLabel(short) {
        return this.zones.find((z) => z.short === short)?.long || short;
    },
    badge() {
        const cfg = this.serverConfig();
        const ids = this.enabledExpansionIds();
        const top = this.expansions.filter((e) => ids.includes(e.id) && e.id !== 99).pop();
        const key = (cfg?.key || '').toUpperCase().slice(0, 7) || 'CUSTOM';
        const tail = (top?.name || '—').toUpperCase();
        return `${key} · ${tail}`;
    },

    // Build the station list the spine renders from the route steps.
    stations() {
        if (!this.route || !this.route.steps.length) { return []; }
        const steps = this.route.steps;
        const color = (i) => (MODES[steps[i]?.type] || MODES.walk).color;
        const list = [{
            label: this.zoneLabel(this.from), kind: 'depart',
            incoming: null, outgoing: color(0), color: color(0),
            icon: svgIcon(steps[0].type),
        }];
        steps.forEach((step, i) => {
            const m = MODES[step.type] || MODES.walk;
            list.push({
                label: this.zoneLabel(step.to), kind: 'leg', zone: step.to,
                cameFrom: step.from, goingTo: steps[i + 1]?.to || null,
                lineName: `${m.name} line`,
                incoming: color(i), outgoing: i + 1 < steps.length ? color(i + 1) : null,
                color: m.color, icon: svgIcon(step.type), kos: !!step.kos,
                isLast: i === steps.length - 1,
            });
        });
        return list;
    },
    hazardCount() {
        return (this.route?.steps || []).filter((s) => s.kos).length;
    },

    // Expand/collapse the inline Brewall map for a leg, fetching + rendering it the first time.
    async toggleMap(st) {
        if (this.openMap === st.zone) { this.openMap = null; return; }
        this.openMap = st.zone;
        if (this.mapSvg[st.zone]) { return; }
        this.mapSvg[st.zone] = '<div class="map-note">Loading map…</div>';
        this.mapSvg[st.zone] = await this.buildMapSvg(st.zone, st.cameFrom, st.goingTo);
    },

    // Parse a Brewall .txt (L = line segments, "x1,y1,z1,x2,y2,z2,r,g,b") into an SVG, plus
    // entrance/exit dots from the zone's own walk-edge coords. EQ map convention: negate x and y
    // so north is up and west is left — so on screen smaller y is North, smaller x is West.
    async buildMapSvg(zone, cameFrom, goingTo) {
        let text, exits = {}, paths = {};
        try {
            const [g, x, p] = await Promise.all([
                fetch(`/api/map/${zone}`).then((r) => { if (!r.ok) { throw new Error(); } return r.text(); }),
                fetch(`/api/map/${zone}/exits`).then((r) => r.ok ? r.json() : {}),
                fetch(`/api/map/${zone}/paths`).then((r) => r.ok ? r.json() : {}),
            ]);
            text = g; exits = x; paths = p;
        } catch {
            return '<div class="map-note">No map on file for this zone.</div>';
        }

        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        const lines = [];
        for (const row of text.split('\n')) {
            if (row[0] !== 'L') { continue; }
            const p = row.slice(2).split(',');
            const x1 = -parseFloat(p[0]), y1 = -parseFloat(p[1]), x2 = -parseFloat(p[3]), y2 = -parseFloat(p[4]);
            if ([x1, y1, x2, y2].some(Number.isNaN)) { continue; }
            lines.push(`<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="rgb(${p[6] | 0},${p[7] | 0},${p[8] | 0})"/>`);
            minX = Math.min(minX, x1, x2); maxX = Math.max(maxX, x1, x2);
            minY = Math.min(minY, y1, y2); maxY = Math.max(maxY, y1, y2);
        }
        if (!lines.length) { return '<div class="map-note">No map on file for this zone.</div>'; }

        const span = Math.max(maxX - minX, maxY - minY);
        const pad = span * 0.03, sw = span / 600;
        const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2, w = maxX - minX, h = maxY - minY;

        // Compass label for a screen point relative to the map's centre (small deadzone → 'middle').
        const compass = (sx, sy) => {
            const ns = sy - cy < -h * 0.15 ? 'N' : sy - cy > h * 0.15 ? 'S' : '';
            const ew = sx - cx < -w * 0.15 ? 'W' : sx - cx > w * 0.15 ? 'E' : '';
            return (ns + ew) || 'middle';
        };
        // Resolve a neighbour to an on-map point, trying candidate coords in preference order and
        // taking the first that lands inside this map. Returns screen pos + compass label, or null.
        const gate = (short, prefer) => {
            const e = exits[short];
            if (!e) { return null; }
            for (const which of prefer) {
                const c = e[which];
                if (!c) { continue; }
                const sx = -c.x, sy = -c.y;
                if (sx < minX || sx > maxX || sy < minY || sy > maxY) { continue; } // off map → next candidate
                return { sx, sy, dir: compass(sx, sy) };
            }
            return null;
        };
        const dot = (sx, sy, fill) => `<circle cx="${sx}" cy="${sy}" r="${span / 42}" fill="${fill}" stroke="#fff" stroke-width="${sw * 1.6}"/>`;
        const runNote = (units, suffix) => {
            const m = Math.min(8, Math.max(0.3, units / RUN_UNITS_PER_MIN));
            return `<span class="map-note-dim">≈ ${m < 1 ? '<1' : Math.round(m)} min run across${suffix}</span>`;
        };

        const dots = [], notes = [];
        let path = '';
        const baked = (cameFrom && goingTo) ? paths[`${cameFrom}|${goingTo}`] : null;

        if (baked && baked.line.length > 1) {
            // Baked navmesh route: solid line around walls, with dots anchored to its endpoints so
            // the entrance/exit are always shown (even where the zone line is off the drawn map).
            const ln = baked.line, a = ln[0], z = ln[ln.length - 1];
            path = `<polyline class="map-path" points="${ln.map((p) => p.join(',')).join(' ')}"`
                + ` fill="none" stroke="#2f8f46" stroke-width="${sw * 2}" stroke-opacity="0.85" stroke-linejoin="round" stroke-linecap="round"/>`;
            dots.push(dot(a[0], a[1], '#3fb950'), dot(z[0], z[1], '#e3b341'));
            notes.push(`<span class="map-key enter">●</span> Enter from the ${compass(a[0], a[1])} (${this.zoneLabel(cameFrom)})`);
            notes.push(`<span class="map-key exit">●</span> Exit ${compass(z[0], z[1])} toward ${this.zoneLabel(goingTo)}`);
            notes.push(runNote(baked.len, ' (charted path)'));
        } else {
            // No baked path: mark whichever zone-line points fall on the drawn map; straight hint.
            const enter = cameFrom && gate(cameFrom, ['enter', 'exit']);
            if (enter) {
                dots.push(dot(enter.sx, enter.sy, '#3fb950'));
                notes.push(`<span class="map-key enter">●</span> Enter from the ${enter.dir} (${this.zoneLabel(cameFrom)})`);
            }
            const exit = goingTo && gate(goingTo, ['exit', 'enter']);
            if (exit) {
                dots.push(dot(exit.sx, exit.sy, '#e3b341'));
                notes.push(`<span class="map-key exit">●</span> Exit ${exit.dir} toward ${this.zoneLabel(goingTo)}`);
            } else if (goingTo) {
                notes.push(`<span class="map-note-dim">Exit toward ${this.zoneLabel(goingTo)} not charted on this map.</span>`);
            }
            if (enter && exit) {
                path = `<line class="map-path" x1="${enter.sx}" y1="${enter.sy}" x2="${exit.sx}" y2="${exit.sy}"`
                    + ` stroke="#2f8f46" stroke-width="${sw * 1.5}" stroke-opacity="0.7" stroke-dasharray="${span / 55} ${span / 80}"/>`;
                notes.push(runNote(Math.hypot(enter.sx - exit.sx, enter.sy - exit.sy), ''));
            }
        }

        const svg = `<svg class="zone-map" viewBox="${minX - pad} ${minY - pad} ${w + 2 * pad} ${h + 2 * pad}"`
            + ` stroke-width="${sw}" stroke-linecap="round" preserveAspectRatio="xMidYMid meet">${lines.join('')}${path}${dots.join('')}</svg>`;
        return svg + (notes.length ? `<div class="map-caption">${notes.join('<br>')}</div>` : '');
    },
    // Approximate door-to-door minutes: distance-based for walk legs where we know the zone-line
    // points, else the flat per-mode estimate (see MODE_MINUTES / legMinutes).
    etaMinutes() {
        const steps = this.route?.steps || [];
        return Math.max(1, Math.round(steps.reduce((sum, _s, i) => sum + this.legMinutes(steps, i), 0)));
    },
    // Minutes for one leg. A walk leg is the run across its `from` zone, from where you landed
    // (previous leg's landing coords) to this leg's zone-line trigger; clamped to a sane band.
    legMinutes(steps, i) {
        const s = steps[i];
        const flat = MODE_MINUTES[s.type] ?? 3;
        if (s.type !== 'walk' || i === 0) { return flat; }
        // Baked navmesh length is trusted (real around-walls distance); allow a wider band.
        if (s.runLen != null) { return Math.min(15, Math.max(0.3, s.runLen / RUN_UNITS_PER_MIN)); }
        if (steps[i - 1].tx == null || s.x == null) { return flat; }
        const d = Math.hypot(s.x - steps[i - 1].tx, s.y - steps[i - 1].ty);
        return Math.min(8, Math.max(0.3, d / RUN_UNITS_PER_MIN));
    },
    serviceTag() {
        const types = (this.route?.steps || []).map((s) => s.type);
        if (types.includes('teleport')) { return { name: 'Arcane Express', color: '#a371f7' }; }
        if (types.includes('translocate')) { return { name: 'Translocator Service', color: '#2bb4a6' }; }
        if (types.includes('book')) { return { name: 'Plane of Knowledge Route', color: '#e3b341' }; }
        if (types.includes('boat')) { return { name: 'Land & Sea Route', color: '#2f81f7' }; }
        return { name: 'Overland Route', color: '#3fb950' };
    },
    legend() {
        const enabled = this.enabledExpansionIds();
        return Object.entries(MODES).map(([type, m]) => {
            const req = MODE_REQ_EXP[type];
            const locked = !enabled.includes(req);
            const reqName = this.expansions.find((e) => e.id === req)?.name || '';
            return { type, ...m, icon: svgIcon(type, locked ? '#7d8590' : '#fff'),
                locked, tag: locked ? `Locked · needs ${reqName}` : m.tag };
        });
    },

    // Moon/sun glyphs aren't in the display/mono fonts (they render as tofu), so draw them inline.
    themeIcon() {
        return this.theme === 'dark'
            ? '<svg viewBox="0 0 20 20" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M11.4 2.6a6.5 6.5 0 1 0 6 9.3 5.3 5.3 0 0 1-6-6 5.3 5.3 0 0 1 0-3.3z"/></svg>'
            : '<svg viewBox="0 0 20 20" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="10" cy="10" r="3.3"/><path d="M10 2.2v1.8M10 16v1.8M2.2 10h1.8M16 10h1.8M4.5 4.5l1.3 1.3M14.2 14.2l1.3 1.3M15.5 4.5l-1.3 1.3M5.8 14.2l-1.3 1.3"/></svg>';
    },

    // --- actions ---
    toggleTheme() {
        this.theme = this.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('nta-theme', this.theme);
        document.documentElement.setAttribute('data-theme', this.theme);
    },
    swap() {
        [this.from, this.to] = [this.to, this.from];
    },
    // Zones filtered by a search string and grouped by continent, for the combobox dropdowns.
    // Classic continents come first in canonical order; everything else falls under "Other Zones".
    groupedZones(query) {
        const order = ['Antonica', 'Faydwer', 'Odus', 'Kunark', 'Velious'];
        const q = (query || '').toLowerCase().trim();
        const groups = {};
        for (const z of this.zones) {
            if (q && !z.long.toLowerCase().includes(q)) { continue; }
            const key = z.continent || 'Other Zones';
            (groups[key] ||= []).push(z);
        }
        const rank = (c) => { const i = order.indexOf(c); return i < 0 ? order.length : i; };
        return Object.keys(groups)
            .sort((a, b) => rank(a) - rank(b) || a.localeCompare(b))
            .map((continent) => ({ continent, zones: groups[continent] }));
    },
    // Level only affects routing for porting classes (it gates their teleport spells), so the Level
    // input is shown only then.
    klassPorts() {
        return !!this.classes.find((c) => c.code === this.klass)?.port;
    },
    modeHint() {
        return this.mode === 'safest'
            ? 'Safest avoids long overland runs — it favors boats, ports, and the Plane of Knowledge, even if that means more stops.'
            : 'Fastest takes the quickest path by travel time. A porting class (✦) unlocks teleport lines once you meet the level.';
    },
    selectServer(key) {
        this.server = key;
    },
    // Identity dot per era in the popover (matches the handoff's preset colors).
    presetColor(key) {
        return { live: '#2f81f7', p99: '#a371f7', legends: '#3fb950', tlp: '#e3b341' }[key] || '#7d8590';
    },
    toggleExp(id) {
        // Editing the expansion set means a custom world: switch to the TLP/custom profile.
        if (this.server !== 'tlp') {
            this.customExp = Object.fromEntries((this.serverConfig()?.expansions || []).map((e) => [e, true]));
            this.server = 'tlp';
        }
        this.customExp = { ...this.customExp, [id]: !this.customExp[id] };
    },
    expEnabled(id) {
        return this.enabledExpansionIds().includes(id);
    },

    async loadZones() {
        const params = new URLSearchParams({ server: this.server });
        if (this.server === 'tlp') { params.set('expansions', this.enabledExpansionIds().join(',')); }
        this.zones = await fetch(`/api/zones?${params}`).then((r) => r.json());
        // Drop selections that no longer exist in this world, falling back to the intro/empty state
        // rather than silently picking an arbitrary zone the user didn't choose.
        if (this.from && !this.zones.some((z) => z.short === this.from)) { this.from = ''; }
        if (this.to && !this.zones.some((z) => z.short === this.to)) { this.to = ''; }
    },

    // Flat, continent-ordered zone list (each item tagged with its group label) for combobox nav.
    flatZones(query) {
        return this.groupedZones(query).flatMap((g) => g.zones.map((z) => ({ ...z, group: g.continent })));
    },

    // Mirror the current selections into the URL so the page is linkable. Defaults are omitted to
    // keep the link tidy; replaceState avoids spamming browser history as the user tweaks inputs.
    syncUrl() {
        const p = new URLSearchParams();
        if (this.from) { p.set('from', this.from); }
        if (this.to) { p.set('to', this.to); }
        if (this.server !== this.defaultServer) { p.set('server', this.server); }
        if (this.server === 'tlp') { p.set('exp', this.enabledExpansionIds().filter((id) => id !== 0).join(',')); }
        if (this.klass) { p.set('class', this.klass); }
        if (this.race) { p.set('race', this.race); }
        if (this.deity) { p.set('deity', this.deity); }
        if (this.level !== 60) { p.set('level', this.level); }
        if (this.mode !== 'fastest') { p.set('mode', this.mode); }
        const qs = p.toString();
        history.replaceState(null, '', qs ? `?${qs}` : location.pathname);

        // Remember the era for next visit (a plain reload restores it; a shared URL overrides).
        localStorage.setItem('nta-server', this.server);
        localStorage.setItem('nta-exp', this.server === 'tlp' ? this.enabledExpansionIds().filter((id) => id !== 0).join(',') : '');
    },

    async compute() {
        this.syncUrl();
        this.openMap = null; this.mapSvg = {}; // a new route invalidates any expanded maps
        if (!this.from || !this.to) { this.route = null; this.message = ''; return; }
        if (this.from === this.to) { this.route = null; this.message = "You're already there."; return; }

        // Show the loading hint only if the request is slow enough to notice — avoids a flicker
        // on the usual instant local response.
        clearTimeout(this._loadTimer);
        this._loadTimer = setTimeout(() => { this.loading = true; }, 200);

        try {
            const params = new URLSearchParams({ from: this.from, to: this.to, server: this.server, level: this.level, mode: this.mode });
            if (this.klass) { params.set('class', this.klass); }
            if (this.race) { params.set('race', this.race); }
            if (this.deity) { params.set('deity', this.deity); }
            if (this.server === 'tlp') { this.enabledExpansionIds().forEach((id) => params.append('expansions[]', id)); }

            const res = await fetch(`/api/route?${params}`);
            if (res.status === 404) { this.route = null; this.message = 'No charted route between these zones.'; return; }
            if (!res.ok) { this.route = null; this.message = 'Could not plan this trip.'; return; }
            this.route = await res.json();
            this.message = '';
        } finally {
            clearTimeout(this._loadTimer);
            this.loading = false;
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();

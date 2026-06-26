@props(['field'])

{{-- Click-to-open, searchable, continent-grouped zone picker with keyboard navigation. Reads/writes
     `{{ $field }}` on the enclosing planner scope; keeps its own open/query/active state. `items`
     is the flat, continent-ordered list (recomputed on open/typing, not per render). --}}
<div class="combo" x-data="{
        open: false, q: '', active: 0, items: [],
        scrollActive() {
            this.$nextTick(() => this.$refs.list?.querySelectorAll('.combo-item')[this.active]?.scrollIntoView({ block: 'nearest' }));
        },
     }"
     @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" class="nta combo-toggle" :class="{ open }"
            @click="open = !open; if (open) { items = flatZones(''); active = 0; $nextTick(() => $refs.search.focus()) }"
            aria-haspopup="listbox" :aria-expanded="open">
        <span :class="{ placeholder: !{{ $field }} }" x-text="zoneLabel({{ $field }}) || 'Select a zone…'"></span>
        <span class="combo-caret" aria-hidden="true">▼</span>
    </button>

    <div class="combo-panel" x-show="open" x-cloak>
        <input type="text" class="combo-search" x-ref="search" x-model="q" aria-label="Search zones"
               placeholder="Search zones…" autocomplete="off"
               @input="items = flatZones(q); active = 0"
               @keydown.arrow-down.prevent="active = Math.min(active + 1, items.length - 1); scrollActive()"
               @keydown.arrow-up.prevent="active = Math.max(active - 1, 0); scrollActive()"
               @keydown.enter.prevent="if (items[active]) { {{ $field }} = items[active].short; open = false; q = '' }"
               @keydown.escape.stop="open = false">
        <div class="combo-list" x-ref="list" role="listbox">
            <template x-for="(z, idx) in items" :key="z.short">
                <div>
                    <template x-if="idx === 0 || items[idx - 1].group !== z.group">
                        <div class="combo-group" x-text="z.group"></div>
                    </template>
                    <button type="button" class="combo-item" role="option"
                            :class="{ sel: z.short === {{ $field }}, active: idx === active }"
                            :aria-selected="z.short === {{ $field }}"
                            @click="{{ $field }} = z.short; open = false; q = ''" @mousemove="active = idx"
                            x-text="z.long"></button>
                </div>
            </template>
            <template x-if="!items.length">
                <div class="combo-empty">No zones match “<span x-text="q"></span>”.</div>
            </template>
        </div>
    </div>
</div>

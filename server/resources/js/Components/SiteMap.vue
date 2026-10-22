<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import 'leaflet/dist/leaflet.css'

/**
 * A MAP THAT SAYS WHAT IT CAN AND CANNOT SHOW (M11 §6) — both consoles.
 *
 * Leaflet with OpenStreetMap tiles: no key, no per-view bill. The tile address is one setting
 * (`VITE_MAP_TILES_URL`, and its attribution `VITE_MAP_TILES_ATTRIBUTION`), so if the platform's use ever
 * outgrows OpenStreetMap's fair-use policy, MapTiler's or Stadia's free tier is a one-line change (D-173). The
 * Google Maps JavaScript API is not used anywhere: it bills per load.
 *
 * Every state is designed, and none of them is a grey square:
 *   loading   "Loading map…" until the first tiles arrive
 *   drawn     the pins (clustered with a count when dense, all but a pin with words), the geofences, a legend by tone
 *   offline   "The map cannot load offline.", and every place in words
 *   failed    the tiles would not load: said so, with every place in words
 *   empty     nothing has a position yet: said so, and what has none is listed as not yet placed
 *
 * The pins arrive from the page's own props (which the page keeps live); this only draws them. A pin with an
 * `href` opens it. Markers are drawn as vector circles in the design system's colours, so there are no image
 * assets to go missing, and every hit area is at least 28 px across.
 */
const props = defineProps({
    /**
     * `{id, lat, lng, title, detail?, tone?, href?, label?}`. tone: 'danger' (an open panic), 'placing', 'ok', 'neutral',
     * 'stale', and a guard's freshness 'live', 'lastseen', 'nosignal'. `label`: words drawn beside the pin at all times,
     * because a phone has no hover (12 Oct 2026); without one the words show on hover, as before.
     */
    pins: { type: Array, default: () => [] },
    /** Who is going where: `{id, from: {lat, lng}, to: {lat, lng}, label}`, a dashed line from the guard to the alert. */
    lines: { type: Array, default: () => [] },
    /** `{id, lat, lng, radiusM, label}` */
    fences: { type: Array, default: () => [] },
    /** An estate's own boundary from its site plan (M57.5 §4.5), drawn as the shape it is: `{id, points: [[lat, lng], …], label}`. */
    boundaries: { type: Array, default: () => [] },
    /** What the map is of, for a screen reader. */
    label: { type: String, required: true },
    height: { type: Number, default: 320 },
    /** Things with no position yet, named so they are not silently missing: `{id, title}`. */
    unplaced: { type: Array, default: () => [] },
    /** The geofence editor: a click on the map says where (`pick`), rather than doing nothing. */
    picking: { type: Boolean, default: false },
    /** Where the map opens when nothing is placed yet (the estate's parish, say): `{lat, lng, zoom}`. */
    center: { type: Object, default: null },
    /** The editor's typed or pasted position: the map goes there when it changes (M12 §1.5). */
    focus: { type: Object, default: null },
})

const emit = defineEmits(['select', 'pick'])

const TILES = import.meta.env.VITE_MAP_TILES_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const ATTRIBUTION = import.meta.env.VITE_MAP_TILES_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'

/** Above this many pins in view they are drawn as counted clusters, all but the pins with words (`label`). */
const CLUSTER_ABOVE = 25

/** Tiles that fail before any arrive: after this many, the map says it could not be drawn. */
const FAILED_AFTER = 6

/*
 * `danger` is the panic red, for an open alert and nothing else (Hassam's three weights, 12 Oct). `placing` is amber, for
 * a position, a fence or a checkpoint being placed in an editor (Operations, Sites & geofences), which drew in the panic
 * red until it had a tone of its own (RED_MAP.md, routed to Security).
 */
const TONE_VAR = { danger: '--red-700', placing: '--amber-600', ok: '--success-600', neutral: '--navy-600', stale: '--slate-500', live: '--success-600', lastseen: '--slate-500', nosignal: '--amber-600' }
const TONE_WORD = { danger: 'Needs attention', placing: 'Being placed', ok: 'On post', neutral: 'Site', stale: 'Position is old', live: 'Guard, live', lastseen: 'Guard, last seen', nosignal: 'Guard, no signal' }
const RANK = { danger: 4, placing: 3, nosignal: 3, stale: 2, lastseen: 2, ok: 1, live: 1, neutral: 0 }

const el = ref(null)
const stage = ref('loading')
const offline = ref(typeof navigator !== 'undefined' && navigator.onLine === false)

const valid = (p) => Number.isFinite(Number(p?.lat)) && Number.isFinite(Number(p?.lng)) && Math.abs(Number(p.lat)) <= 90 && Math.abs(Number(p.lng)) <= 180
const placed = computed(() => props.pins.filter(valid))
/** The boundaries with at least a triangle of real positions. */
const outlines = computed(() => props.boundaries.filter((b) => Array.isArray(b?.points) && b.points.filter((p) => valid({ lat: p[0], lng: p[1] })).length >= 3))
const inWords = computed(() => placed.value.map((p) => `${p.title} · ${Number(p.lat).toFixed(5)}, ${Number(p.lng).toFixed(5)}`))
const legend = computed(() => [...new Set(placed.value.map((p) => p.tone ?? 'neutral'))].map((tone) => ({ tone, word: TONE_WORD[tone] ?? TONE_WORD.neutral })))

// Law 3 (12 Oct): said in sentences, never across an em dash. "Listed below" only when something is.
const listedBelow = computed(() => (inWords.value.length > 0 ? ' Every place on it is listed below.' : ''))

const why = computed(() => {
    if (offline.value) return `The map cannot load offline.${listedBelow.value}`
    if (stage.value === 'failed') return `The map could not be drawn.${listedBelow.value}`
    if (placed.value.length === 0 && props.fences.filter(valid).length === 0 && outlines.value.length === 0) return 'Nothing here has a position on the map yet.'
    return null
})

// The editor shows the map with nothing on it yet, to place the first thing on.
const showMap = computed(() => !offline.value && stage.value !== 'failed' && (placed.value.length > 0 || props.fences.some(valid) || outlines.value.length > 0 || props.picking || props.center !== null))

let L = null
let map = null
let layer = null
let failures = 0

const colour = (tone) => {
    const name = TONE_VAR[tone] ?? TONE_VAR.neutral
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()

    return value || getComputedStyle(document.documentElement).getPropertyValue('--navy-600').trim()
}

const worst = (pins) => pins.reduce((w, p) => ((RANK[p.tone ?? 'neutral'] ?? 0) > (RANK[w] ?? 0) ? p.tone ?? 'neutral' : w), 'neutral')

const alone = (p) => ({ id: p.id, lat: Number(p.lat), lng: Number(p.lng), pins: [p] })

function clusters() {
    const pins = placed.value

    if (pins.length <= CLUSTER_ABOVE || !map) {
        return pins.map(alone)
    }

    /*
     * A PIN WITH WORDS IS NEVER FOLDED INTO A COUNT (12 Oct 2026). An open panic and a guard on duty carry their words
     * (`label`) because a phone has no hover; inside a cluster both were a number, a panic a red one with no lot. Only
     * the places without words (posts, checkpoints, incidents) are counted together.
     */
    const worded = pins.filter((p) => p.label)

    // A grid an eighth of the view wide: close pins become one counted marker at their centre.
    const bounds = map.getBounds()
    const cellLat = Math.max((bounds.getNorth() - bounds.getSouth()) / 8, 1e-6)
    const cellLng = Math.max((bounds.getEast() - bounds.getWest()) / 8, 1e-6)
    const cells = new Map()

    for (const p of pins.filter((pin) => !pin.label)) {
        const key = `${Math.floor(Number(p.lat) / cellLat)}:${Math.floor(Number(p.lng) / cellLng)}`
        cells.set(key, [...(cells.get(key) ?? []), p])
    }

    return [
        ...[...cells.entries()].map(([key, members]) => ({
            id: members.length === 1 ? members[0].id : `cluster:${key}`,
            lat: members.reduce((sum, p) => sum + Number(p.lat), 0) / members.length,
            lng: members.reduce((sum, p) => sum + Number(p.lng), 0) / members.length,
            pins: members,
        })),
        ...worded.map(alone),
    ]
}

function draw() {
    if (!map || !L) return

    layer.clearLayers()

    // The estate's boundary first, under everything else: the shape the site plan draws, not a circle (M57.5 §4.5).
    for (const outline of outlines.value) {
        L.polygon(outline.points.map((p) => [Number(p[0]), Number(p[1])]), {
            color: colour('neutral'),
            weight: 2,
            fillOpacity: 0.05,
        }).bindTooltip(outline.label ?? 'Estate boundary').addTo(layer)
    }

    for (const fence of props.fences.filter(valid)) {
        // A fence may carry a tone (the one being placed is drawn dashed, in the placing colour) — M12 §1.5.
        L.circle([Number(fence.lat), Number(fence.lng)], {
            radius: Math.max(Number(fence.radiusM) || 0, 1),
            color: colour(fence.tone ?? 'neutral'),
            weight: 2,
            dashArray: fence.dashed ? '6 6' : null,
            fillOpacity: fence.dashed ? 0.04 : 0.08,
        }).bindTooltip(fence.label ?? 'Geofence').addTo(layer)
    }

    // Who is going where (12 Oct 2026): under the pins, so a pin is never hidden by its own line.
    for (const line of props.lines.filter((l) => valid(l?.from) && valid(l?.to))) {
        L.polyline([[Number(line.from.lat), Number(line.from.lng)], [Number(line.to.lat), Number(line.to.lng)]], {
            color: colour('neutral'),
            weight: 3,
            dashArray: '8 6',
        }).bindTooltip(line.label ?? 'Going to the alert').addTo(layer)
    }

    // The most urgent drawn last, so on top: an open panic is never under a post or a guard at the same spot.
    for (const c of clusters().sort((a, b) => (RANK[worst(a.pins)] ?? 0) - (RANK[worst(b.pins)] ?? 0))) {
        const tone = worst(c.pins)

        if (c.pins.length === 1) {
            const p = c.pins[0]
            // An open panic is the largest thing on the map as well as the only red one.
            const marker = L.circleMarker([c.lat, c.lng], { radius: p.tone === 'danger' ? 13 : 9, color: 'white', weight: 2, fillColor: colour(p.tone ?? 'neutral'), fillOpacity: 1 })
                .bindTooltip(p.label ?? (p.detail ? `${p.title} · ${p.detail}` : p.title), p.label ? { permanent: true, direction: 'top', offset: [0, -10], className: `site-map__label site-map__label--${p.tone ?? 'neutral'}` } : {})
                .addTo(layer)

            marker.on('click', () => emit('select', p))
            continue
        }

        const icon = L.divIcon({
            className: 'site-map__cluster',
            html: `<span style="background:${colour(tone)}">${c.pins.length}</span>`,
            iconSize: [34, 34],
        })

        L.marker([c.lat, c.lng], { icon, keyboard: true, title: `${c.pins.length} here` })
            .on('click', () => map.setView([c.lat, c.lng], Math.min(map.getZoom() + 2, 19)))
            .addTo(layer)
    }
}

function fit() {
    if (!map || !L) return

    const points = [
        ...placed.value.map((p) => [Number(p.lat), Number(p.lng)]),
        ...props.fences.filter(valid).map((f) => [Number(f.lat), Number(f.lng)]),
        ...outlines.value.flatMap((b) => b.points.map((p) => [Number(p[0]), Number(p[1])])),
    ]

    if (points.length === 1) {
        map.setView(points[0], 17)
    } else if (points.length > 1) {
        map.fitBounds(L.latLngBounds(points), { padding: [28, 28], maxZoom: 17 })
    } else if (props.center && valid(props.center)) {
        map.setView([Number(props.center.lat), Number(props.center.lng)], Number(props.center.zoom) || 13)
    }
}

async function start() {
    if (map || !showMap.value) return

    await nextTick()

    if (!el.value) return

    L = (await import('leaflet')).default
    map = L.map(el.value, { zoomControl: true, attributionControl: true, scrollWheelZoom: false })
    layer = L.layerGroup().addTo(map)

    const tiles = L.tileLayer(TILES, { attribution: ATTRIBUTION, maxZoom: 19 })
    tiles.on('tileload', () => {
        if (stage.value === 'loading') stage.value = 'ready'
    })
    tiles.on('tileerror', () => {
        failures++

        if (stage.value === 'loading' && failures >= FAILED_AFTER) stage.value = 'failed'
    })
    tiles.addTo(map)

    map.on('zoomend moveend', draw)
    map.on('click', (e) => {
        if (props.picking) {
            emit('pick', { lat: e.latlng.lat, lng: e.latlng.lng })
        }
    })
    fit()
    draw()
}

function stop() {
    map?.remove()
    map = null
    layer = null
    failures = 0
}

const onOnline = () => {
    offline.value = false
    stage.value = 'loading'
    start()
}
const onOffline = () => {
    offline.value = true
    stop()
}

onMounted(() => {
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    start()
})

onBeforeUnmount(() => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
    stop()
})

// The page keeps its pins live; the map redraws them where they are, and refits only when the set changes shape.
// The detail is in the key so a pin's words move with it ("position 3 min ago").
watch(() => [
    ...props.pins.map((p) => `${p.id}:${p.lat}:${p.lng}:${p.tone}:${p.detail ?? ''}:${p.label ?? ''}`),
    ...props.lines.map((l) => `${l.id}:${l.from?.lat}:${l.from?.lng}:${l.to?.lat}:${l.to?.lng}:${l.label ?? ''}`),
    // A geofence being placed grows and shrinks with its radius field: it is redrawn as it changes.
    ...props.fences.map((f) => `${f.id}:${f.lat}:${f.lng}:${f.radiusM}:${f.tone ?? ''}`),
    ...props.boundaries.map((b) => `${b.id}:${(b.points ?? []).length}`),
].join('|'), () => {
    if (!map) {
        start()

        return
    }

    draw()
})

watch(showMap, (show) => (show ? start() : stop()))

watch(() => (props.focus && valid(props.focus) ? `${props.focus.lat}:${props.focus.lng}` : ''), (key) => {
    if (key && map) map.setView([Number(props.focus.lat), Number(props.focus.lng)], Math.max(map.getZoom(), 16))
})
</script>

<template>
    <figure class="site-map" :aria-label="label">
        <div v-if="showMap" class="site-map__frame" :class="{ 'site-map__frame--picking': picking }" :style="{ height: `${height}px` }">
            <div ref="el" class="site-map__canvas" role="application" :aria-label="label"></div>
            <div v-if="stage === 'loading'" class="site-map__loading" role="status">Loading map…</div>
        </div>

        <figcaption v-if="why || !showMap" class="site-map__words" role="status">
            <p v-if="why" class="site-map__why">{{ why }}</p>
            <ul v-if="inWords.length > 0 && !showMap">
                <li v-for="line in inWords" :key="line">{{ line }}</li>
            </ul>
        </figcaption>

        <ul v-if="showMap && legend.length > 1" class="site-map__legend" aria-label="Map key">
            <li v-for="item in legend" :key="item.tone"><span class="site-map__dot" :class="`site-map__dot--${item.tone}`"></span>{{ item.word }}</li>
        </ul>

        <p v-if="unplaced.length > 0" class="site-map__unplaced">
            Not placed on the map yet: {{ unplaced.map((u) => u.title).join(', ') }}.
        </p>
    </figure>
</template>

<style scoped>
.site-map {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.site-map__frame {
    position: relative;
    width: 100%;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--navy-200);
}

.site-map__canvas {
    position: absolute;
    inset: 0;
}

.site-map__frame--picking :deep(.leaflet-container) {
    cursor: crosshair;
}

.site-map__loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--navy-100);
    color: var(--slate-600);
    font-size: 13px;
    font-weight: 600;
    z-index: 500;
}

.site-map__words {
    background: var(--navy-100);
    border-radius: 12px;
    padding: 12px 14px;
    color: var(--navy-900);
    font-size: 13px;
    line-height: 1.5;
}

.site-map__words ul {
    margin: 6px 0 0;
    padding-left: 18px;
}

.site-map__why {
    margin: 0;
    font-weight: 600;
    color: var(--slate-600);
}

.site-map__legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    list-style: none;
    margin: 0;
    padding: 0;
    font-size: 12px;
    color: var(--slate-600);
}

.site-map__legend li {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.site-map__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--navy-600);
}

.site-map__dot--danger { background: var(--red-700); }
.site-map__dot--ok { background: var(--success-600); }
.site-map__dot--stale { background: var(--slate-500); }
.site-map__dot--placing { background: var(--amber-600); }
.site-map__dot--live { background: var(--success-600); }
.site-map__dot--lastseen { background: var(--slate-500); }
.site-map__dot--nosignal { background: var(--amber-600); }

/* A pin's words, drawn at all times (a phone has no hover): white on the pin's own tone, small and bold. */
:deep(.site-map__label) {
    padding: 2px 7px;
    border: 0;
    border-radius: 10px;
    background: var(--navy-900);
    color: white;
    font-size: 11.5px;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(11, 31, 51, 0.25);
}

:deep(.site-map__label::before) {
    display: none;
}

:deep(.site-map__label--danger) {
    background: var(--red-700);
    font-size: 12.5px;
}

:deep(.site-map__label--live) {
    background: var(--success-600);
}

:deep(.site-map__label--lastseen) {
    background: var(--slate-600);
}

/* No signal: amber with near-black words, a warning (Hassam's three weights). */
:deep(.site-map__label--nosignal) {
    background: var(--amber-100);
    color: var(--navy-900);
    border: 1px solid var(--amber-600);
}

.site-map__unplaced {
    margin: 0;
    font-size: 12px;
    color: var(--slate-600);
}

:deep(.site-map__cluster span) {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 2px solid white;
    color: white;
    font-weight: 700;
    font-size: 13px;
}
</style>

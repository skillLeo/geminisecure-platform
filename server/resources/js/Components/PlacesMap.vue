<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import 'leaflet/dist/leaflet.css'
import { isPlaced, openingView } from '../lib/placesView.js'

/**
 * THE PLACES MAP (M30 §7.3): the map a client's estate, gates and patrol are placed on.
 *
 * Leaflet with OpenStreetMap's tiles and attribution, as the classic map has them (one setting, `VITE_MAP_TILES_URL`):
 * no key, no bill. It differs from the classic map (`SiteMap.vue`, not touched) in what placing needs:
 *
 *   it never opens on the world  it opens on whatever is placed; with nothing placed, on `home` (Kingston, a city's
 *                                zoom), never a continent or the globe
 *   a click places               while `picking`, a click says where (`pick`)
 *   the selected pin             drawn larger, its circle drawn live as its radius changes; with `dragSelected` it can
 *                                be dragged to move it (`move`)
 *   a route                      numbered pins joined in order by a thin line in the muted text colour
 *   a search result              `flyTo` shows it, by its box when it has one
 *
 * Every pin is a vector or a few lines of HTML in the design system's colours: no image assets to go missing.
 */
const props = defineProps({
    /** What the map is of, for a screen reader. */
    label: { type: String, required: true },
    height: { type: Number, default: 420 },
    /** `{id, lat, lng, title, number?, selected?}` */
    pins: { type: Array, default: () => [] },
    /** `{id, lat, lng, radiusM, selected?}` */
    circles: { type: Array, default: () => [] },
    /** The route in its order, `{lat, lng}` each, joined by a thin line. */
    route: { type: Array, default: () => [] },
    /** A click on the map says where (`pick`). */
    picking: { type: Boolean, default: false },
    /** The selected pin can be dragged to move it (`move`). */
    dragSelected: { type: Boolean, default: false },
    /** Where the map opens when nothing here is placed: `{lat, lng, zoom}`. */
    home: { type: Object, required: true },
    /** Show this when it changes: `{lat, lng, bbox?: [south, north, west, east], key}`. */
    flyTo: { type: Object, default: null },
})

const emit = defineEmits(['pick', 'select', 'move'])

const TILES = import.meta.env.VITE_MAP_TILES_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
const ATTRIBUTION = import.meta.env.VITE_MAP_TILES_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
const FAILED_AFTER = 6

const el = ref(null)
const stage = ref('loading')
const offline = ref(typeof navigator !== 'undefined' && navigator.onLine === false)

// A missing number is not 0: what is not placed is never drawn (lib/placesView.js).
const valid = isPlaced
const placedPins = computed(() => props.pins.filter(valid))
const placedCircles = computed(() => props.circles.filter(valid))

const why = computed(() => {
    if (offline.value) return 'The map cannot be shown offline. Positions can still be pasted as numbers.'
    if (stage.value === 'failed') return 'The map could not be drawn. Positions can still be pasted as numbers.'
    return null
})

let L = null
let map = null
let layer = null
let failures = 0

const token = (name, fallback) => {
    if (typeof document === 'undefined' || typeof getComputedStyle !== 'function') return fallback
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback
}

function draw() {
    if (!map || !L) return

    layer.clearLayers()

    // The route, in order, under everything: a thin line in the muted text colour (§7.2 step 3).
    const line = props.route.filter(valid).map((p) => [Number(p.lat), Number(p.lng)])
    if (line.length > 1) {
        L.polyline(line, { color: token('--text-placeholder', '#46586F'), weight: 2, opacity: 0.9, interactive: false }).addTo(layer)
    }

    for (const c of placedCircles.value) {
        L.circle([Number(c.lat), Number(c.lng)], {
            radius: Math.max(Number(c.radiusM) || 0, 1),
            color: token(c.selected ? '--navy-600' : '--navy-300', '#1974D2'),
            weight: c.selected ? 2 : 1.5,
            dashArray: c.selected ? null : '6 6',
            fillOpacity: c.selected ? 0.1 : 0.04,
            interactive: false,
        }).addTo(layer)
    }

    for (const p of placedPins.value) {
        const at = [Number(p.lat), Number(p.lng)]
        const fill = token(p.selected ? '--navy-800' : '--navy-600', '#1974D2')

        // Numbered (the route's checkpoints) and draggable (the selected one being moved) pins are a few lines of HTML.
        if (p.number !== undefined || (p.selected && props.dragSelected)) {
            const icon = L.divIcon({
                className: 'pm-pin',
                html: `<span class="pm-pin__dot${p.selected ? ' pm-pin__dot--selected' : ''}" style="background:${fill}">${p.number ?? ''}</span>`,
                iconSize: [p.selected ? 30 : 26, p.selected ? 30 : 26],
            })
            const marker = L.marker(at, { icon, title: p.title, keyboard: true, draggable: p.selected && props.dragSelected })
            marker.on('click', () => emit('select', p))
            marker.on('dragend', () => {
                const moved = marker.getLatLng()
                emit('move', { id: p.id, lat: moved.lat, lng: moved.lng })
            })
            marker.bindTooltip(p.title).addTo(layer)
            continue
        }

        L.circleMarker(at, { radius: p.selected ? 11 : 8, color: 'white', weight: 2, fillColor: fill, fillOpacity: 1 })
            .bindTooltip(p.title)
            .on('click', () => emit('select', p))
            .addTo(layer)
    }
}

// Open on what is placed; with nothing placed, on `home` (lib/placesView.js). Once: after that the view is the person's.
function fit() {
    if (!map || !L) return

    const view = openingView([...placedPins.value, ...placedCircles.value], props.home)

    if (view.bounds) {
        map.fitBounds(L.latLngBounds(view.bounds), { padding: [36, 36], maxZoom: 17 })
    } else {
        map.setView(view.center, view.zoom)
    }
}

async function start() {
    if (map || offline.value) return

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

    map.on('click', (e) => {
        if (props.picking) emit('pick', { lat: e.latlng.lat, lng: e.latlng.lng })
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
    window.addEventListener?.('online', onOnline)
    window.addEventListener?.('offline', onOffline)
    start()
})

onBeforeUnmount(() => {
    window.removeEventListener?.('online', onOnline)
    window.removeEventListener?.('offline', onOffline)
    stop()
})

// Pins, circles and the route are redrawn where they are as they change; the view stays where the person has it.
watch(() => [
    ...props.pins.map((p) => `${p.id}:${p.lat}:${p.lng}:${p.selected ? 1 : 0}:${p.number ?? ''}`),
    ...props.circles.map((c) => `${c.id}:${c.lat}:${c.lng}:${c.radiusM}:${c.selected ? 1 : 0}`),
    ...props.route.map((p) => `${p.lat}:${p.lng}`),
    props.dragSelected ? 'drag' : '',
].join('|'), () => {
    if (map) draw()
})

watch(() => props.flyTo?.key, () => {
    const to = props.flyTo
    if (!map || !L || !to) return

    if (Array.isArray(to.bbox) && to.bbox.length === 4) {
        const [south, north, west, east] = to.bbox.map(Number)
        map.fitBounds([[south, west], [north, east]], { maxZoom: 17 })
    } else if (valid(to)) {
        map.setView([Number(to.lat), Number(to.lng)], Math.max(map.getZoom(), 16))
    }
})
</script>

<template>
    <figure class="pm" :aria-label="label">
        <div v-if="!why" class="pm-frame" :class="{ 'pm-frame--picking': picking }" :style="{ height: `${height}px` }">
            <div ref="el" class="pm-canvas" role="application" :aria-label="label"></div>
            <div v-if="stage === 'loading'" class="pm-loading" role="status">Loading map…</div>
        </div>
        <figcaption v-else class="pm-words" role="status">{{ why }}</figcaption>
    </figure>
</template>

<style scoped>
/* The classic map's frame and words (SiteMap.vue), in the three text colours this screen reads in. */
.pm {
    margin: 0;
}

.pm-frame {
    position: relative;
    width: 100%;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--navy-200);
}

.pm-canvas {
    position: absolute;
    inset: 0;
}

.pm-frame--picking :deep(.leaflet-container) {
    cursor: crosshair;
}

.pm-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--navy-100);
    color: var(--text-body);
    font-size: 13px;
    font-weight: 600;
    z-index: 500;
}

.pm-words {
    background: var(--navy-100);
    border-radius: 12px;
    padding: 12px 14px;
    color: var(--text-body);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
}

:deep(.pm-pin) {
    background: none;
    border: 0;
}

:deep(.pm-pin__dot) {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    border: 2px solid white;
    color: white;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

:deep(.pm-pin__dot--selected) {
    outline: 3px solid var(--navy-300);
}
</style>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import InfoButton from '../../../Components/InfoButton.vue'
import PlacesMap from '../../../Components/PlacesMap.vue'
import Subnav from './Subnav.vue'
import { requestHeaders } from '../../../live/authorize.js'
import { parsePosition } from '../../../lib/coordinates.js'
import { matchPlacements } from '../../../lib/checkpointMessages.js'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'

// As the classic Sites page: a client record or its officers changing moves this page by itself (M18H §7.2).
useLiveReload({ entities: ['shift', 'client', 'officer'] })

/**
 * PLACES (M30 §7) — where a client's estate, its gates and its patrol are, in four steps along the top, beside the
 * classic Sites & geofences (which is not touched).
 *
 *   1 The estate   the estate's own centre and how far out it reaches: one map, one pin, one slider, one Save
 *   2 Gates        the gates, with the map beside them: select one, set or move its location, its clock-in circle
 *   3 Patrol       the patrol's start and its checkpoints, numbered and joined in order
 *   4 Settings     the supervisor's number, the guard positions switch, and how clock in works
 *
 * A search box above the map finds a place by its name (our server asks OpenStreetMap; it never places a pin). A
 * click places. The numbers stay visible, and pasting them works as on the classic page (`lib/coordinates.js`), so a
 * click and a paste store the same value. No reason is asked for any of this (§7.2); the audit log still has who,
 * when, and the position before and after.
 */
const props = defineProps({
    clients: { type: Array, required: true },
    client: { type: Object, default: null },
    step: { type: String, required: true },
    canWrite: { type: Boolean, required: true },
    limits: { type: Object, required: true },
    kingston: { type: Object, required: true },
    fenceRule: { type: Object, required: true },
    /** Whether a shift at a post with no fence at all starts on this server (a demonstration) or waits for a PIN. */
    unfencedStarts: { type: Boolean, default: true },
    estate: { type: Object, default: null },
    gates: { type: Array, default: () => [] },
    patrolPosts: { type: Array, default: () => [] },
    checkpoints: { type: Array, default: () => [] },
    phases: { type: Array, default: () => [] },
    patrolInterval: { type: Object, default: null },
    /** The gate the estate's patrol starts at, or null. */
    patrolStart: { type: Number, default: null },
    estateSeesGuards: { type: Boolean, default: null },
})

const page = usePage()

const STEPS = [
    { key: 'estate', n: 1, label: 'The estate' },
    { key: 'gates', n: 2, label: 'Gates' },
    { key: 'patrol', n: 3, label: 'Patrol' },
    { key: 'settings', n: 4, label: 'Settings' },
]

const realClients = computed(() => props.clients.filter((c) => !c.internal))
const internalClients = computed(() => props.clients.filter((c) => c.internal))
const stepHref = (key) => `/guards/places?${new URLSearchParams({ client: props.client?.id ?? '', step: key }).toString()}`
const chooseClient = (event) => router.get('/guards/places', { client: event.target.value, step: props.step })

const round = (n) => Math.round(Number(n) * 1e7) / 1e7
const shown = (lat, lng) => `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`

/*
 * ---- writes: one way to the server, the refusal in its own words ----
 * Each is pressed with no dialog open, so a refusal is what just happened: a toast top right that stays until it is
 * closed, and what a save did is the shell's toast (Hassam, 12 October).
 */

const saving = ref(false)

const send = (url, data, done = () => {}) => {
    saving.value = true
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => {
            if (!page.props.errors?.places) done()
        },
        onError: (errors) => {
            toast(errors.places ?? Object.values(errors)[0] ?? 'That could not be saved.', { tone: 'error' })
        },
        onFinish: () => {
            saving.value = false
        },
    })
}

/* ---- place search (§7.3): finds the place, never places the pin ---- */

const query = ref('')
const found = ref([])
const searchNote = ref(null)
const searching = ref(false)
const flyTo = ref(null)
let searchTimer = null
let searchSeq = 0

const runSearch = async () => {
    clearTimeout(searchTimer)
    const q = query.value.trim()

    if (q.length < 3) {
        found.value = []
        searchNote.value = null
        return
    }

    const mine = ++searchSeq
    searching.value = true
    try {
        const response = await fetch(`/guards/places/search?${new URLSearchParams({ q }).toString()}`, { headers: requestHeaders(), credentials: 'same-origin' })
        const body = response.ok ? await response.json() : null
        if (mine !== searchSeq) return
        found.value = body?.results ?? []
        searchNote.value = body ? body.message : 'Place search is not answering right now. Click the map where it is, or paste its numbers.'
    } catch {
        if (mine === searchSeq) {
            found.value = []
            searchNote.value = 'No connection: place search is not answering. Click the map where it is, or paste its numbers.'
        }
    } finally {
        if (mine === searchSeq) searching.value = false
    }
}

// Typing waits half a second before it asks (§7.3); Enter asks at once.
watch(query, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(runSearch, 500)
})

const showPlace = (place) => {
    flyTo.value = { lat: place.lat, lng: place.lng, bbox: place.bbox, key: `${place.lat}:${place.lng}:${Date.now()}` }
    found.value = []
    searchNote.value = null
}

/* ---- step 1: the estate ---- */

const estateDraft = reactive({ lat: null, lng: null, radius: props.limits.estate.default })
const estatePaste = ref('')
const estatePasteError = ref(null)

const resetEstate = () => {
    estateDraft.lat = props.estate?.lat ?? null
    estateDraft.lng = props.estate?.lng ?? null
    estateDraft.radius = props.estate?.radius_m ?? props.limits.estate.default
}
resetEstate()
watch(() => props.estate, resetEstate)

const estatePlaced = computed(() => estateDraft.lat !== null && estateDraft.lng !== null)
const estateSaved = computed(() => props.estate?.lat !== null && props.estate?.lat !== undefined)
// A change is a position, or a new reach for a saved one: the slider alone, with nothing placed, is not.
const estateChanged = computed(() => {
    if (!estatePlaced.value) return false

    return estateDraft.lat !== (props.estate?.lat ?? null) || estateDraft.lng !== (props.estate?.lng ?? null) || estateDraft.radius !== (props.estate?.radius_m ?? null)
})

const pasteEstate = () => {
    const at = parsePosition(estatePaste.value)
    estatePasteError.value = at.error ?? null
    if (!at.error) {
        estateDraft.lat = round(at.lat)
        estateDraft.lng = round(at.lng)
        flyTo.value = { lat: at.lat, lng: at.lng, key: `paste:${Date.now()}` }
    }
}

const saveEstate = () => send(`/guards/places/${props.client.id}/estate`, { lat: estateDraft.lat, lng: estateDraft.lng, radius_m: estateDraft.radius })

/* ---- step 2: gates ---- */

const selectedId = ref(props.gates[0]?.id ?? null)
const drafts = reactive({})
const placing = ref(null)
const adding = ref(false)
const newName = ref('')
const newDraft = ref(null)
const renaming = ref(null)
const renameForm = reactive({ name: '', also_called: '' })
const gatePaste = ref('')
const gatePasteError = ref(null)

const gateById = (id) => props.gates.find((g) => g.id === id) ?? null
const draftOf = (g) => drafts[g.id] ?? { lat: g.lat, lng: g.lng, radius: g.lat === null ? props.limits.gate.default : g.radius_m }
const gateChanged = (g) => drafts[g.id] !== undefined && (drafts[g.id].lat !== g.lat || drafts[g.id].lng !== g.lng || drafts[g.id].radius !== g.radius_m)
const gateMax = (g) => Math.max(props.limits.gate.max, g.radius_m ?? 0)

const selectGate = (id) => {
    selectedId.value = id
    gatePaste.value = ''
    gatePasteError.value = null
    if (renaming.value !== id) renaming.value = null
}

const startPlacing = (g) => {
    selectGate(g.id)
    adding.value = false
    newDraft.value = null
    placing.value = g.id
    drafts[g.id] = { ...draftOf(g) }
}

const cancelPlacing = () => {
    if (String(placing.value).startsWith('stop:')) {
        cancelStop()
        return
    }

    if (placing.value === 'new') {
        newDraft.value = null
        adding.value = false
        newName.value = ''
    } else if (placing.value !== null) {
        delete drafts[placing.value]
    }
    placing.value = null
}

const setRadius = (g, value) => {
    drafts[g.id] = { ...draftOf(g), radius: Number(value) }
}

const undoGate = (g) => {
    delete drafts[g.id]
    if (placing.value === g.id) placing.value = null
}

const pasteGate = (g) => {
    const at = parsePosition(gatePaste.value)
    gatePasteError.value = at.error ?? null
    if (!at.error) {
        drafts[g.id] = { ...draftOf(g), lat: round(at.lat), lng: round(at.lng) }
        flyTo.value = { lat: at.lat, lng: at.lng, key: `paste:${Date.now()}` }
    }
}

const saveGate = (g) => {
    const d = draftOf(g)
    send(`/guards/places/gate/${g.id}`, { lat: d.lat, lng: d.lng, radius_m: d.radius }, () => {
        delete drafts[g.id]
        placing.value = null
    })
}

// "Add gate" asks for a name and nothing else, then goes straight into placing mode (§7.2).
const startAdding = () => {
    cancelPlacing()
    adding.value = true
    newName.value = ''
    newDraft.value = null
}

const placeNew = () => {
    if (newName.value.trim() === '') return
    placing.value = 'new'
    selectedId.value = null
}

const saveNew = () => {
    const d = newDraft.value
    const name = newName.value.trim()
    send(`/guards/places/${props.client.id}/gates`, { name, lat: d.lat, lng: d.lng, radius_m: d.radius }, () => {
        adding.value = false
        newName.value = ''
        newDraft.value = null
        placing.value = null
        // The new gate, selected, so its circle can be adjusted at once.
        selectedId.value = props.gates.find((g) => g.name.toLowerCase() === name.toLowerCase())?.id ?? selectedId.value
    })
}

const startRename = (g) => {
    selectGate(g.id)
    renaming.value = g.id
    renameForm.name = g.name
    renameForm.also_called = g.also_called ?? ''
}

const saveRename = (g) => send(`/guards/places/gate/${g.id}/name`, { name: renameForm.name, also_called: renameForm.also_called }, () => {
    renaming.value = null
})

// A gate's status in two lines (§9): the fact, then what follows from it on this server.
const gateStatus = (g) => {
    if (g.lat !== null) return { set: true, head: `Location set · clock in within ${g.radius_m} m`, then: null }

    const then = estateSaved.value
        ? 'A guard here is checked against the estate\'s area instead.'
        : props.unfencedStarts
            ? 'A guard here can clock in from anywhere, and the office is told.'
            : 'A guard here cannot clock in without a supervisor\'s PIN until it has one.'

    return { set: false, head: 'No location yet', then }
}

/* ---- step 3: the patrol ---- */

// The estate's own route (no post), in its order; an older route hanging from a post stays on the classic page.
const route = computed(() => props.checkpoints.filter((c) => c.post_id === null).sort((a, b) => a.sequence - b.sequence || a.id - b.id))
const olderRoute = computed(() => props.checkpoints.filter((c) => c.post_id !== null))
const startGate = computed(() => props.gates.find((g) => g.id === props.patrolStart) ?? null)

const openStop = ref(null)
const stopDrafts = reactive({})
const leaving = ref(null)
const addingStop = ref(null)
const stopForm = reactive({ label: '', phase: '', lines: '', messages: '' })
const intervalOpen = ref(false)
const intervalMinutes = ref(props.patrolInterval?.minutes ?? 15)

const stopById = (id) => route.value.find((c) => c.id === id) ?? null
const stopDraft = (c) => stopDrafts[c.id] ?? { lat: c.lat, lng: c.lng }
const stopChanged = (c) => stopDrafts[c.id] !== undefined && (stopDrafts[c.id].lat !== c.lat || stopDrafts[c.id].lng !== c.lng)

const intervalWords = computed(() => {
    const minutes = props.patrolInterval?.minutes ?? 15
    const said = minutes % 60 === 0 ? `${minutes / 60} hour${minutes === 60 ? '' : 's'}` : `${minutes} minutes`

    return `The next checkpoint is due ${said} after the last${props.patrolInterval?.set ? '' : ' (the platform\'s own interval)'}.`
})

const setStart = (event) => {
    const value = event.target.value
    send(`/guards/places/${props.client.id}/patrol-start`, { post_id: value === '' ? null : Number(value) })
}

const saveInterval = (minutes) => send(`/guards/places/${props.client.id}/patrol-interval`, { minutes }, () => {
    intervalOpen.value = false
})

const toggleStop = (c) => {
    openStop.value = openStop.value === c.id ? null : c.id
    leaving.value = null
}

const placeStop = (c) => {
    openStop.value = c.id
    placing.value = `stop:${c.id}`
    stopDrafts[c.id] = { ...stopDraft(c) }
}

const cancelStop = () => {
    const id = Number(String(placing.value).replace('stop:', ''))
    if (Number.isFinite(id)) delete stopDrafts[id]
    placing.value = null
}

const saveStop = (c) => {
    const d = stopDraft(c)
    send(`/guards/places/checkpoint/${c.id}`, { lat: d.lat, lng: d.lng }, () => {
        delete stopDrafts[c.id]
        placing.value = null
    })
}

const addStop = () => send(`/guards/places/${props.client.id}/checkpoints`, { label: stopForm.label.trim(), phase: stopForm.phase || null }, () => {
    stopForm.label = ''
    addingStop.value = null
})

const addStops = () => send(`/guards/places/${props.client.id}/checkpoints/many`, { lines: stopForm.lines }, () => {
    stopForm.lines = ''
    addingStop.value = null
})

// The installers' messages (M27 §4.3): each line matched to its checkpoint by the classic page's own rule, shown,
// and only the lines that read cleanly are placed.
const placements = computed(() => (addingStop.value === 'messages' ? matchPlacements(stopForm.messages ?? '', route.value) : []))
const placeable = computed(() => placements.value.filter((p) => p.status === 'ok'))

// Why a line is not used, in this screen's words (§9): the classic page's matcher says a repeat is "placed by an
// earlier line", and it is shared with that page, so the one sentence is said again here.
const readingWhy = (p) => (p.status === 'duplicate' ? `An earlier line already gives ${p.checkpoint.label} its location.` : p.why)

const placeFromMessages = () => send(`/guards/places/${props.client.id}/checkpoints/place`, {
    places: placeable.value.map((p) => ({ id: p.checkpoint.id, lat: p.lat, lng: p.lng })),
}, () => {
    stopForm.messages = ''
    addingStop.value = null
})

const retireStop = (c) => send(`/guards/places/checkpoint/${c.id}/retire`, {}, () => {
    leaving.value = null
    openStop.value = null
})

// Reordering: by dragging a row in the list (§7.2), or one place earlier or later from the row's own panel.
const saveOrder = (ids) => send(`/guards/places/${props.client.id}/route`, { order: ids })
const moveStop = (c, by) => {
    const ids = route.value.map((s) => s.id)
    const at = ids.indexOf(c.id)
    const to = at + by
    if (at < 0 || to < 0 || to >= ids.length) return
    ;[ids[at], ids[to]] = [ids[to], ids[at]]
    saveOrder(ids)
}

const draggingStop = ref(null)
const overStop = ref(null)

const dragStopStart = (c, event) => {
    draggingStop.value = c.id
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData?.('text/plain', String(c.id))
    }
}

const dragStopOver = (c, event) => {
    if (draggingStop.value === null) return
    event.preventDefault()
    overStop.value = c.id
}

const dropStop = (c, event) => {
    event.preventDefault()
    const from = draggingStop.value
    draggingStop.value = null
    overStop.value = null
    if (from === null || from === c.id) return

    // Taken out, and put in where the row it was dropped on was: after it moving down, before it moving up.
    const ids = route.value.map((s) => s.id)
    const to = ids.indexOf(c.id)
    ids.splice(ids.indexOf(from), 1)
    ids.splice(to, 0, from)
    saveOrder(ids)
}

const dragStopEnd = () => {
    draggingStop.value = null
    overStop.value = null
}

/* ---- step 4: settings ---- */

const phone = ref(props.estate?.supervisor_phone ?? '')
watch(() => props.estate?.supervisor_phone, (value) => {
    phone.value = value ?? ''
})

const savePhone = () => send(`/guards/places/${props.client.id}/supervisor`, { phone: phone.value.trim() })
const setSees = (sees) => send(`/guards/places/${props.client.id}/guard-positions`, { sees })

/* ---- the map ---- */

const onPick = (at) => {
    if (!props.canWrite) return

    if (props.step === 'patrol' && String(placing.value).startsWith('stop:')) {
        const c = stopById(Number(String(placing.value).replace('stop:', '')))
        if (c) stopDrafts[c.id] = { lat: round(at.lat), lng: round(at.lng) }
        return
    }

    if (props.step === 'estate') {
        estateDraft.lat = round(at.lat)
        estateDraft.lng = round(at.lng)
    } else if (props.step === 'gates' && placing.value === 'new') {
        newDraft.value = { lat: round(at.lat), lng: round(at.lng), radius: newDraft.value?.radius ?? props.limits.gate.default }
    } else if (props.step === 'gates' && placing.value !== null) {
        const g = gateById(placing.value)
        if (g) drafts[g.id] = { ...draftOf(g), lat: round(at.lat), lng: round(at.lng) }
    }
}

const onSelect = (pin) => {
    if (props.step === 'gates' && typeof pin.id === 'number' && placing.value === null) selectGate(pin.id)
    if (props.step === 'patrol' && String(pin.id).startsWith('stop:') && placing.value === null) openStop.value = Number(String(pin.id).replace('stop:', ''))
}

const onMove = (moved) => {
    if (props.step === 'patrol' && String(moved.id).startsWith('stop:')) {
        const c = stopById(Number(String(moved.id).replace('stop:', '')))
        if (c) stopDrafts[c.id] = { lat: round(moved.lat), lng: round(moved.lng) }
        return
    }

    if (props.step === 'estate') {
        estateDraft.lat = round(moved.lat)
        estateDraft.lng = round(moved.lng)
    } else if (moved.id === 'new' && newDraft.value) {
        newDraft.value = { ...newDraft.value, lat: round(moved.lat), lng: round(moved.lng) }
    } else {
        const g = gateById(moved.id)
        if (g) drafts[g.id] = { ...draftOf(g), lat: round(moved.lat), lng: round(moved.lng) }
    }
}

// The route as the map draws it (§7.2 step 3): the start gate, then every placed checkpoint, numbered, in order.
const routePins = computed(() => {
    // A position read from the installers' messages is shown where it would go, before it is saved.
    const proposed = (c) => placeable.value.find((p) => p.checkpoint.id === c.id)
    const pins = route.value
        .map((c, i) => ({ id: `stop:${c.id}`, ...(proposed(c) ? { lat: proposed(c).lat, lng: proposed(c).lng } : stopDraft(c)), title: `${i + 1}. ${c.label}`, number: i + 1, selected: openStop.value === c.id }))
        .filter((p) => p.lat !== null)

    const start = startGate.value
    return start && start.lat !== null ? [{ id: `gate:${start.id}`, lat: start.lat, lng: start.lng, title: `${start.name}: the patrol starts here`, number: 'S' }, ...pins] : pins
})

const mapRoute = computed(() => (props.step === 'patrol' ? routePins.value.map((p) => ({ lat: p.lat, lng: p.lng })) : []))

const mapPins = computed(() => {
    if (props.step === 'patrol') return routePins.value

    if (props.step === 'estate') {
        return estatePlaced.value ? [{ id: 'estate', lat: estateDraft.lat, lng: estateDraft.lng, title: props.client.name, selected: true }] : []
    }

    const pins = props.gates
        .map((g) => ({ id: g.id, ...draftOf(g), title: g.name, selected: g.id === selectedId.value }))
        .filter((p) => p.lat !== null)

    if (newDraft.value) pins.push({ id: 'new', lat: newDraft.value.lat, lng: newDraft.value.lng, title: newName.value, selected: true })

    return pins
})

// The clock-in circle of the one selected, drawn live as its slider moves (§7.2).
const mapCircles = computed(() => {
    if (props.step === 'patrol') {
        const c = stopById(openStop.value)
        const d = c ? stopDraft(c) : null
        return d && d.lat !== null ? [{ id: `stop:${c.id}`, lat: d.lat, lng: d.lng, radiusM: c.radius_m, selected: true }] : []
    }

    if (props.step === 'estate') {
        return estatePlaced.value ? [{ id: 'estate', lat: estateDraft.lat, lng: estateDraft.lng, radiusM: estateDraft.radius, selected: true }] : []
    }

    if (newDraft.value) return [{ id: 'new', lat: newDraft.value.lat, lng: newDraft.value.lng, radiusM: newDraft.value.radius, selected: true }]

    const g = gateById(selectedId.value)
    const d = g ? draftOf(g) : null

    return d && d.lat !== null ? [{ id: g.id, lat: d.lat, lng: d.lng, radiusM: d.radius, selected: true }] : []
})

const mapPicking = computed(() => props.canWrite && (props.step === 'estate' || placing.value !== null))
const mapDrag = computed(() => props.canWrite && (props.step === 'estate' || placing.value !== null))

// The one line above the map while it is placing (§7.2), and the hint under it otherwise.
const placingLine = computed(() => {
    if (placing.value === null) return null

    if (props.step === 'patrol' && String(placing.value).startsWith('stop:')) {
        return `Click the map where the tag for ${stopById(Number(String(placing.value).replace('stop:', '')))?.label} hangs. You can drag its pin to put it exactly.`
    }

    if (props.step !== 'gates') return null
    const name = placing.value === 'new' ? newName.value.trim() : gateById(placing.value)?.name
    return `Click the map where ${name} is. You can drag its pin to put it exactly.`
})

const mapHint = computed(() => {
    if (!props.canWrite) return 'Your role can see these places. Placing them is for a role that plans the workforce.'
    if (props.step === 'estate') return 'Click the map to move the pin, or drag it.'
    if (props.step === 'patrol') return 'The route, in order, from where the patrol starts.'
    return 'Click a pin to select its gate.'
})

const mapLabel = computed(() => {
    if (props.step === 'estate') return `Where ${props.client?.name} is`
    if (props.step === 'patrol') return `${props.client?.name}'s patrol route`
    return `${props.client?.name}'s gates`
})
</script>

<template>
    <Head title="Places" />

    <GeminiConsole title="Guard workforce">
        <Subnav active="places" />


        <EmptyState
            v-if="!client"
            variant="first-use"
            title="No client to place"
            body="Your role reaches no client yet. A client appears here once it is set up in Clients, or once you are given one of its sites."
        />

        <template v-else>
            <div class="pl-top">
                <label class="pl-client" for="pl-client">
                    <span>Client</span>
                    <select id="pl-client" :value="client.id" @change="chooseClient">
                        <option v-for="c in realClients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        <optgroup v-if="internalClients.length" label="Internal, not a client">
                            <option v-for="c in internalClients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </optgroup>
                    </select>
                </label>
            </div>

            <!-- The four steps, in the board's own tab style. -->
            <nav class="subnav pl-steps" aria-label="Steps">
                <Link
                    v-for="s in STEPS"
                    :key="s.key"
                    :href="stepHref(s.key)"
                    class="subnav-item"
                    :class="{ active: step === s.key }"
                    :aria-current="step === s.key ? 'step' : undefined"
                    preserve-scroll
                >
                    {{ s.n }} {{ s.label }}
                </Link>
            </nav>

            <div v-if="step === 'estate' || step === 'gates' || step === 'patrol'" class="pl-grid">
                <section class="pl-card pl-map">
                    <div class="pl-search">
                        <input
                            v-model="query"
                            type="search"
                            class="pl-input"
                            placeholder="Search a place or address"
                            aria-label="Search a place or address"
                            @keydown.enter.prevent="runSearch"
                        />
                        <ul v-if="found.length" class="pl-found">
                            <li v-for="(place, i) in found" :key="i">
                                <button type="button" @click="showPlace(place)">{{ place.name }}</button>
                            </li>
                        </ul>
                        <p v-if="searching" class="pl-muted">Searching…</p>
                        <p v-else-if="searchNote" class="pl-muted" role="status">{{ searchNote }}</p>
                    </div>

                    <p v-if="placingLine" class="pl-placing" role="status">
                        <span>{{ placingLine }}</span>
                        <button type="button" class="text-link-sm" @click="cancelPlacing">Cancel</button>
                    </p>

                    <PlacesMap
                        :label="mapLabel"
                        :pins="mapPins"
                        :circles="mapCircles"
                        :route="mapRoute"
                        :picking="mapPicking"
                        :drag-selected="mapDrag"
                        :home="kingston"
                        :fly-to="flyTo"
                        @pick="onPick"
                        @select="onSelect"
                        @move="onMove"
                    />
                    <p class="pl-muted">{{ mapHint }}</p>
                </section>

                <!-- Step 1: the estate's own area. -->
                <section v-if="step === 'estate'" class="pl-card">
                    <h2 class="pl-h">The estate's area <InfoButton topic="geofence" /></h2>
                    <p class="pl-name">{{ client.name }}</p>
                    <p v-if="estate?.where" class="pl-muted">{{ estate.where }}</p>

                    <p class="pl-coords">{{ estatePlaced ? shown(estateDraft.lat, estateDraft.lng) : 'No location yet' }}</p>
                    <label class="pl-field">
                        <span>Or paste its numbers</span>
                        <input v-model="estatePaste" type="text" class="pl-input" placeholder="e.g. 17.9612, -76.8821" :disabled="!canWrite" @change="pasteEstate" @keydown.enter.prevent="pasteEstate" />
                    </label>
                    <p v-if="estatePasteError" class="pl-error" role="alert">{{ estatePasteError }}</p>

                    <label class="pl-field">
                        <span>How far out it reaches</span>
                        <span class="pl-slider">
                            <input v-model.number="estateDraft.radius" type="range" :min="limits.estate.min" :max="limits.estate.max" step="10" :disabled="!canWrite" aria-label="How far out the estate reaches, in metres" />
                            <span class="pl-metres">{{ estateDraft.radius }} m</span>
                        </span>
                    </label>

                    <div class="pl-actions">
                        <button type="button" class="btn-primary-sm" :disabled="!canWrite || !estatePlaced || !estateChanged || saving" @click="saveEstate"><span>{{ saving ? 'Saving…' : 'Save' }}</span></button>
                        <button v-if="estateChanged" type="button" class="text-link-sm" @click="resetEstate">Undo</button>
                    </div>
                </section>

                <!-- Step 3: the patrol, where it starts and its checkpoints in order. -->
                <section v-else-if="step === 'patrol'" class="pl-card">
                    <h2 class="pl-h">The patrol starts at</h2>
                    <select class="pl-select" :value="patrolStart ?? ''" :disabled="!canWrite || saving" aria-label="The patrol starts at" @change="setStart">
                        <option value="">Not set yet</option>
                        <option v-for="g in gates" :key="g.id" :value="g.id">{{ g.name }}{{ g.also_called ? ` (${g.also_called})` : '' }}</option>
                    </select>
                    <p v-if="startGate && startGate.lat === null" class="pl-muted">{{ startGate.name }} has no location yet, so the route on the map starts at its first checkpoint.</p>

                    <p class="pl-line">{{ intervalWords }}</p>
                    <button v-if="canWrite && !intervalOpen" type="button" class="text-link-sm" @click="intervalOpen = true">Change</button>
                    <div v-if="intervalOpen" class="pl-add">
                        <label class="pl-field">
                            <span>Minutes after one checkpoint the next is due</span>
                            <input v-model.number="intervalMinutes" type="number" class="pl-input pl-short" :min="patrolInterval?.min ?? 5" :max="patrolInterval?.max ?? 240" />
                        </label>
                        <div class="pl-actions">
                            <button type="button" class="btn-primary-sm" :disabled="saving" @click="saveInterval(intervalMinutes)"><span>Save</span></button>
                            <button v-if="patrolInterval?.set" type="button" class="text-link-sm" @click="saveInterval(null)">Use the platform's {{ patrolInterval.default }} minutes</button>
                            <button type="button" class="text-link-sm" @click="intervalOpen = false">Cancel</button>
                        </div>
                    </div>

                    <div class="pl-head pl-head--route">
                        <h2 class="pl-h">Checkpoints</h2>
                        <span v-if="canWrite" class="pl-head-actions">
                            <button type="button" class="btn-outline-sm" @click="addingStop = addingStop === 'one' ? null : 'one'"><span>+ Add</span></button>
                            <button type="button" class="btn-outline-sm" @click="addingStop = addingStop === 'many' ? null : 'many'"><span>+ Many</span></button>
                            <button v-if="route.length" type="button" class="btn-outline-sm" @click="addingStop = addingStop === 'messages' ? null : 'messages'"><span>Place from messages</span></button>
                        </span>
                    </div>

                    <form v-if="addingStop === 'messages'" class="pl-add" @submit.prevent="placeFromMessages">
                        <label class="pl-field">
                            <span>The installers' messages, one tag a line</span>
                            <textarea v-model="stopForm.messages" class="pl-input pl-lines" rows="6" placeholder="Basketball court, 18.01234, -76.95678"></textarea>
                        </label>
                        <ul v-if="placements.length" class="pl-readings">
                            <li v-for="(p, i) in placements" :key="i" :class="{ 'pl-reading--no': p.status !== 'ok' }">
                                <span v-if="p.status === 'ok'">✓ {{ p.checkpoint.label }} · {{ shown(p.lat, p.lng) }}</span>
                                <span v-else>✗ {{ p.line }}: {{ readingWhy(p) }}</span>
                            </li>
                        </ul>
                        <div class="pl-actions">
                            <button type="button" class="btn-primary-sm" :disabled="saving || placeable.length === 0" @click="placeFromMessages"><span>{{ placeable.length === 1 ? 'Place 1 checkpoint' : `Place ${placeable.length} checkpoints` }}</span></button>
                            <button type="button" class="text-link-sm" @click="addingStop = null">Cancel</button>
                        </div>
                    </form>

                    <form v-if="addingStop === 'one'" class="pl-add" @submit.prevent="addStop">
                        <label class="pl-field">
                            <span>Name of the checkpoint</span>
                            <input v-model="stopForm.label" type="text" class="pl-input" maxlength="120" placeholder="e.g. Basketball court" required />
                        </label>
                        <label v-if="phases.length" class="pl-field">
                            <span>Phase</span>
                            <select v-model="stopForm.phase" class="pl-select">
                                <option value="">No phase</option>
                                <option v-for="p in phases" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </label>
                        <div class="pl-actions">
                            <button type="submit" class="btn-primary-sm" :disabled="saving || stopForm.label.trim() === ''"><span>Add</span></button>
                            <button type="button" class="text-link-sm" @click="addingStop = null">Cancel</button>
                        </div>
                    </form>

                    <form v-if="addingStop === 'many'" class="pl-add" @submit.prevent="addStops">
                        <label class="pl-field">
                            <span>One to a line, each with its phase if you like</span>
                            <textarea v-model="stopForm.lines" class="pl-input pl-lines" rows="6" placeholder="Phase 2: Pool deck&#10;Basketball court"></textarea>
                        </label>
                        <div class="pl-actions">
                            <button type="submit" class="btn-primary-sm" :disabled="saving || stopForm.lines.trim() === ''"><span>Add them</span></button>
                            <button type="button" class="text-link-sm" @click="addingStop = null">Cancel</button>
                        </div>
                    </form>

                    <p v-if="!route.length" class="pl-muted">No checkpoints on the route yet.</p>

                    <ol class="pl-route">
                        <li
                            v-for="(c, i) in route"
                            :key="c.id"
                            class="pl-stop"
                            :class="{ 'pl-stop--on': openStop === c.id, 'pl-stop--over': overStop === c.id }"
                            :draggable="canWrite ? 'true' : 'false'"
                            @dragstart="dragStopStart(c, $event)"
                            @dragover="dragStopOver(c, $event)"
                            @drop="dropStop(c, $event)"
                            @dragend="dragStopEnd"
                        >
                            <div class="pl-stop-row">
                                <span v-if="canWrite" class="pl-grip" aria-hidden="true">⠿</span>
                                <span class="pl-num">{{ i + 1 }}</span>
                                <button type="button" class="pl-stop-name" :aria-expanded="openStop === c.id" @click="toggleStop(c)">
                                    {{ c.label }}{{ ' ' }}<span class="pl-phase"><template v-if="c.phase">{{ c.phase }}{{ ' · ' }}</template><span class="pl-stop-status" :class="{ 'pl-stop-status--unset': c.lat === null }">{{ c.lat === null ? 'no location yet' : '✓ location set' }}</span></span>
                                </button>
                                <span v-if="c.sample" class="status-badge draft" title="Made by the demonstration seed, not the client's own">sample</span>
                                <button v-if="canWrite" type="button" class="btn-outline-sm pl-stop-act" @click="placeStop(c)"><span>{{ c.lat === null ? 'Set its location' : 'Move on the map' }}</span></button>
                            </div>

                            <div v-if="openStop === c.id" class="pl-stop-panel">
                                <template v-if="stopChanged(c)">
                                    <p class="pl-coords">{{ shown(stopDraft(c).lat, stopDraft(c).lng) }}</p>
                                    <div class="pl-actions">
                                        <button type="button" class="btn-primary-sm" :disabled="saving" @click="saveStop(c)"><span>{{ saving ? 'Saving…' : 'Save' }}</span></button>
                                        <button type="button" class="text-link-sm" @click="cancelStop">Undo</button>
                                    </div>
                                </template>
                                <p v-else-if="c.lat !== null" class="pl-coords">{{ shown(c.lat, c.lng) }}</p>
                                <div class="pl-actions">
                                    <a :href="`/guards/sites/checkpoints/${c.id}/tag`" class="text-link-sm" target="_blank" rel="noopener">Print its tag</a>
                                    <button v-if="canWrite && i > 0" type="button" class="text-link-sm" @click="moveStop(c, -1)">Move earlier</button>
                                    <button v-if="canWrite && i < route.length - 1" type="button" class="text-link-sm" @click="moveStop(c, 1)">Move later</button>
                                    <button v-if="canWrite && leaving !== c.id" type="button" class="text-link-sm pl-link-danger" @click="leaving = c.id">Take it off the route</button>
                                </div>
                                <p v-if="leaving === c.id" class="pl-confirm">
                                    <span>Take {{ c.label }} off the route? Its scans stay on the record, and its tag stops working.</span>
                                    <button type="button" class="btn-primary-sm pl-danger" :disabled="saving" @click="retireStop(c)"><span>Take it off</span></button>
                                    <button type="button" class="text-link-sm" @click="leaving = null">Keep it</button>
                                </p>
                            </div>
                        </li>
                    </ol>

                    <div v-if="route.length" class="pl-actions">
                        <a :href="`/guards/sites/${client.id}/tags`" class="btn-outline-sm pl-print" target="_blank" rel="noopener"><span>Print all tags</span></a>
                    </div>
                    <p v-if="olderRoute.length" class="pl-muted">{{ olderRoute.length }} older checkpoint{{ olderRoute.length === 1 ? '' : 's' }} hang from a patrol post, and are on the classic page.</p>
                </section>

                <!-- Step 2: the gates. -->
                <section v-else class="pl-card">
                    <div class="pl-head">
                        <!-- M32 F12: what the circle around a gate decides. -->
                        <h2 class="pl-h">Gates <InfoButton topic="geofence" /></h2>
                        <button v-if="canWrite" type="button" class="btn-outline-sm" @click="startAdding"><span>+ Add gate</span></button>
                    </div>

                    <div v-if="adding" class="pl-add">
                        <label class="pl-field">
                            <span>Name of the gate</span>
                            <input v-model="newName" type="text" class="pl-input" maxlength="120" placeholder="e.g. Back Gate" @keydown.enter.prevent="placeNew" />
                        </label>
                        <div v-if="!newDraft" class="pl-actions">
                            <button type="button" class="btn-primary-sm" :disabled="newName.trim() === '' || placing === 'new'" @click="placeNew"><span>{{ placing === 'new' ? 'Click the map' : 'Place it on the map' }}</span></button>
                            <button type="button" class="text-link-sm" @click="cancelPlacing">Cancel</button>
                        </div>
                        <template v-else>
                            <p class="pl-coords">{{ shown(newDraft.lat, newDraft.lng) }}</p>
                            <label class="pl-field">
                                <span>How close a guard must be to clock in</span>
                                <span class="pl-slider">
                                    <input v-model.number="newDraft.radius" type="range" :min="limits.gate.min" :max="limits.gate.max" step="5" aria-label="How close a guard must be, in metres" />
                                    <span class="pl-metres">{{ newDraft.radius }} m</span>
                                </span>
                            </label>
                            <div class="pl-actions">
                                <button type="button" class="btn-primary-sm" :disabled="saving" @click="saveNew"><span>{{ saving ? 'Adding…' : `Add ${newName.trim()}` }}</span></button>
                                <button type="button" class="text-link-sm" @click="cancelPlacing">Cancel</button>
                            </div>
                        </template>
                    </div>

                    <p v-if="!gates.length && !adding" class="pl-muted">No gates yet. Add the first with "+ Add gate".</p>

                    <ul class="pl-gates">
                        <li v-for="g in gates" :key="g.id" class="pl-gate" :class="{ 'pl-gate--on': g.id === selectedId }">
                            <button type="button" class="pl-gate-name" :aria-pressed="g.id === selectedId" @click="selectGate(g.id)">
                                <span class="pl-dot" :class="{ 'pl-dot--unset': g.lat === null }" aria-hidden="true"></span>
                                {{ g.name }}
                            </button>
                            <p v-if="g.also_called" class="pl-muted">Also called {{ g.also_called }}</p>
                            <p class="pl-status" :class="{ 'pl-status--unset': !gateStatus(g).set }">{{ gateStatus(g).head }}</p>
                            <p v-if="gateStatus(g).then" class="pl-muted">{{ gateStatus(g).then }}</p>

                            <template v-if="g.id === selectedId && canWrite">
                                <template v-if="draftOf(g).lat !== null">
                                    <p class="pl-coords">{{ shown(draftOf(g).lat, draftOf(g).lng) }}</p>
                                    <label class="pl-field">
                                        <span>How close a guard must be to clock in</span>
                                        <span class="pl-slider">
                                            <input :value="draftOf(g).radius" type="range" :min="limits.gate.min" :max="gateMax(g)" step="5" :aria-label="`How close a guard must be at ${g.name}, in metres`" @input="setRadius(g, $event.target.value)" />
                                            <span class="pl-metres">{{ draftOf(g).radius }} m</span>
                                        </span>
                                    </label>
                                </template>
                                <label class="pl-field">
                                    <span>Paste its numbers</span>
                                    <input v-model="gatePaste" type="text" class="pl-input" placeholder="e.g. 17.9612, -76.8821" @change="pasteGate(g)" @keydown.enter.prevent="pasteGate(g)" />
                                </label>
                                <p v-if="gatePasteError" class="pl-error" role="alert">{{ gatePasteError }}</p>

                                <div v-if="gateChanged(g)" class="pl-actions">
                                    <button type="button" class="btn-primary-sm" :disabled="saving || draftOf(g).lat === null" @click="saveGate(g)"><span>{{ saving ? 'Saving…' : 'Save' }}</span></button>
                                    <button type="button" class="text-link-sm" @click="undoGate(g)">Undo</button>
                                </div>
                            </template>

                            <div v-if="canWrite && renaming !== g.id && placing !== g.id && !gateChanged(g)" class="pl-row-actions">
                                <button type="button" class="btn-outline-sm" @click="startPlacing(g)"><span>{{ g.lat === null ? 'Set its location' : 'Move on the map' }}</span></button>
                                <button type="button" class="btn-outline-sm" @click="startRename(g)"><span>Rename</span></button>
                            </div>

                            <form v-if="renaming === g.id" class="pl-rename" @submit.prevent="saveRename(g)">
                                <label class="pl-field">
                                    <span>Name</span>
                                    <input v-model="renameForm.name" type="text" class="pl-input" maxlength="120" required />
                                </label>
                                <label class="pl-field">
                                    <span>Also called</span>
                                    <input v-model="renameForm.also_called" type="text" class="pl-input" maxlength="120" placeholder="e.g. Gate 5, Phase 5 Gate" />
                                </label>
                                <div class="pl-actions">
                                    <button type="submit" class="btn-primary-sm" :disabled="saving || renameForm.name.trim() === ''"><span>Save</span></button>
                                    <button type="button" class="text-link-sm" @click="renaming = null">Cancel</button>
                                </div>
                            </form>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Step 4: the supervisor's number and the guard positions switch, in the classic page's words. -->
            <section v-if="step === 'settings'" class="pl-card pl-settings">
                <h2 class="pl-h">The supervisor's number</h2>
                <p class="pl-muted">What a guard at the gate calls when clock in will not start their shift.</p>
                <form class="pl-phone" @submit.prevent="savePhone">
                    <input v-model="phone" type="tel" class="pl-input" maxlength="32" :placeholder="`e.g. ${page.props.phoneExample?.local ?? ''}`" :disabled="!canWrite" aria-label="The supervisor's number" />
                    <button v-if="canWrite" type="submit" class="btn-primary-sm" :disabled="saving || phone.trim() === (estate?.supervisor_phone ?? '')"><span>Save</span></button>
                </form>
                <p v-if="!estate?.supervisor_phone" class="pl-muted">No number is set: a guard refused at the gate is offered a message to the control room instead of a call.</p>

                <h2 class="pl-h pl-h--next">Guard positions on the client's console</h2>
                <p v-if="estateSeesGuards === null" class="pl-muted">This client has no contract yet. The setting belongs to the contract; set the client's plan first.</p>
                <template v-else>
                    <p class="pl-line">
                        {{ estateSeesGuards
                            ? 'On: this client\'s Security page shows its officers on the map while they are clocked in, each with the age of their position.'
                            : 'Off: this client\'s Security page shows how many officers are on site and who, and not where they stand.' }}
                    </p>
                    <button v-if="canWrite" type="button" class="btn-outline-sm" :disabled="saving" @click="setSees(!estateSeesGuards)">
                        <span>{{ estateSeesGuards ? 'Stop showing guard positions to this client' : 'Show guard positions to this client' }}</span>
                    </button>
                </template>
            </section>

            <!-- Step 1's footing, and step 4's: what the estate's area is for, and how clock in decides (collapsed). -->
            <div v-if="step === 'estate' || step === 'settings'" class="pl-foot">
                <p v-if="step === 'estate'" class="pl-muted">Used when a gate has no location of its own.</p>
                <details class="pl-rule">
                    <summary>How clock in works</summary>
                    <!-- The classic page's five facts, in this screen's words (§9): no geofence, fence, radius or placed (M31 B7). -->
                    <ol>
                        <li>The guard's phone takes GPS readings until it knows where it is to within {{ fenceRule.good_accuracy_m }} m, or gives up after a short wait.</li>
                        <li>That is checked against the area a guard must be inside at <strong>the gate on the guard's shift</strong>, and for a patrol at the gate the patrol starts at. If that gate has no location yet, the estate's own area is used. If neither has one, {{ unfencedStarts ? 'the shift starts, and the office is told.' : 'the shift needs a supervisor\'s PIN to start.' }}</li>
                        <li>A reading counts as a circle, not a point: {{ fenceRule.margin_per_accuracy === 2 ? 'twice' : `${fenceRule.margin_per_accuracy} times` }} the phone's own accuracy, up to {{ fenceRule.max_margin_m }} m, is allowed for error. A reading worse than {{ fenceRule.poor_accuracy_m }} m is not used, and the guard is told the position could not be found.</li>
                        <li>Inside the area, the guard confirms with a fingerprint or face. Outside it, or with no position, the guard is told which gate, how far away and which way, and a supervisor's PIN can start the shift. That start is flagged, recorded and reported to the Head of Security. A faked location is always flagged.</li>
                        <li>A patrol is then followed by its checkpoint scans and the guard's position while clocked in, never by an area: a vehicle covering the estate fits none.</li>
                    </ol>
                </details>
            </div>

        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The classic Sites page's own panel, field and list values (Sites.vue), in the three text colours this screen reads
 * in. Layout only besides.
 */
.pl-top {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0 0 12px;
}

.pl-client {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
}

/* `align-items`: a customisable select (`appearance: base-select`) is a flex box, and its text otherwise sits high. */
.pl-client select {
    align-items: center;
    min-height: 40px;
    min-width: 260px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 0 10px;
    background: var(--white);
    color: var(--text-primary);
    font: inherit;
    font-weight: 500;
}

.pl-steps {
    margin-bottom: 16px;
}

a.subnav-item {
    text-decoration: none;
}

.pl-grid {
    display: grid;
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
    gap: 16px;
    align-items: start;
}

@media (max-width: 900px) {
    .pl-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

.pl-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 14px;
    min-width: 0;
}

.pl-map {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.pl-search {
    position: relative;
}

.pl-input {
    width: 100%;
    min-height: 40px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 0 10px;
    background: var(--white);
    color: var(--text-primary);
    font: inherit;
    font-size: 13px;
    font-weight: 500;
}

.pl-found {
    list-style: none;
    margin: 6px 0 0;
    padding: 4px;
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    background: var(--white);
}

.pl-found button {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    border-radius: 8px;
    background: none;
    text-align: left;
    font: inherit;
    font-size: 12.5px;
    color: var(--text-primary);
    cursor: pointer;
}

.pl-found button:hover,
.pl-found button:focus-visible {
    background: var(--navy-100);
}

.pl-placing {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 9px 12px;
    border-radius: 10px;
    background: var(--navy-100);
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-primary);
}

.pl-muted {
    margin: 2px 0 0;
    font-size: 12.5px;
    line-height: 1.45;
    color: var(--text-placeholder);
}

.pl-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.pl-h {
    margin: 4px 0 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
}

.pl-name {
    margin: 0;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-primary);
}

.pl-coords {
    margin: 10px 0 0;
    font-size: 12.5px;
    font-variant-numeric: tabular-nums;
    color: var(--text-body);
}

.pl-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
}

.pl-slider {
    display: flex;
    align-items: center;
    gap: 10px;
}

.pl-slider input {
    flex: 1;
}

.pl-metres {
    min-width: 56px;
    text-align: right;
    font-variant-numeric: tabular-nums;
    color: var(--text-primary);
}

.pl-error {
    margin: 6px 0 0;
    font-size: 12.5px;
    color: var(--red-700);
}

.pl-actions,
.pl-row-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
}

.pl-add {
    margin-bottom: 10px;
    padding: 12px;
    border-radius: 12px;
    background: var(--navy-100);
}

.pl-gates {
    list-style: none;
    margin: 0;
    padding: 0;
}

.pl-gate {
    padding: 10px 0;
    border-top: 1px solid var(--navy-100);
}

.pl-gate--on {
    padding: 10px 10px;
    margin: 0 -10px;
    border-radius: 12px;
    background: var(--navy-100);
}

.pl-gate-name {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0;
    border: 0;
    background: none;
    font: inherit;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
}

.pl-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--navy-600);
}

.pl-dot--unset {
    background: var(--white);
    border: 2px solid var(--navy-300);
}

.pl-status {
    margin: 4px 0 0;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
}

.pl-status--unset {
    color: var(--text-primary);
}

.pl-rename {
    margin-top: 10px;
}

/* Step 3: the patrol. */
.pl-select {
    align-items: center;
    width: 100%;
    min-height: 40px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 0 10px;
    background: var(--white);
    color: var(--text-primary);
    font: inherit;
    font-size: 13px;
    font-weight: 500;
}

.pl-line {
    margin: 14px 0 4px;
    font-size: 12.5px;
    color: var(--text-body);
}

.pl-short {
    max-width: 140px;
}

.pl-lines {
    min-height: 120px;
    padding: 8px 10px;
    resize: vertical;
}

.pl-head--route {
    margin-top: 18px;
    flex-wrap: wrap;
}

.pl-head-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pl-readings {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12.5px;
    color: var(--text-primary);
}

.pl-reading--no {
    color: var(--red-700);
}

.pl-route {
    list-style: none;
    margin: 0;
    padding: 0;
}

.pl-stop {
    border-top: 1px solid var(--navy-100);
}

.pl-stop--on {
    border-radius: 12px;
    background: var(--navy-100);
}

/* The row a dragged checkpoint would land on. */
.pl-stop--over {
    box-shadow: inset 0 2px 0 var(--navy-600);
}

.pl-stop-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 6px;
}

.pl-grip {
    cursor: grab;
    color: var(--text-placeholder);
    font-size: 14px;
    line-height: 1;
}

.pl-num {
    min-width: 22px;
    height: 22px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--navy-600);
    color: var(--white);
    font-size: 11px;
    font-weight: 700;
}

.pl-stop-name {
    flex: 1;
    min-width: 0;
    padding: 0;
    border: 0;
    background: none;
    text-align: left;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
    overflow-wrap: anywhere;
}

/*
 * Under the name, not after it: the phase, then whether the checkpoint has its location. At 1280 a name sharing its
 * line with them wrapped, its phase read as part of it ("court Phase 2"), and with "location set" beside it a long
 * word broke in two.
 */
.pl-phase {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-placeholder);
}

.pl-stop-status {
    font-weight: 600;
    color: var(--success-700);
    white-space: nowrap;
}

.pl-stop-status--unset {
    color: var(--text-placeholder);
}

.pl-stop-panel {
    padding: 0 10px 10px 36px;
}

button.text-link-sm.pl-link-danger {
    color: var(--red-700);
}

.pl-confirm {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 10px 0 0;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-primary);
}

/* The console dialog's danger tone (ConsoleDialog.vue), for taking a checkpoint off for good. */
button.btn-primary-sm.pl-danger {
    background: var(--red-700);
    box-shadow: none;
}

a.btn-outline-sm {
    text-decoration: none;
}

.pl-foot {
    margin-top: 14px;
}

/* Step 4. */
.pl-settings {
    max-width: 640px;
}

.pl-h--next {
    margin-top: 20px;
}

.pl-phone {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
}

.pl-phone .pl-input {
    max-width: 260px;
}

.pl-rule {
    margin-top: 6px;
    font-size: 12.5px;
    color: var(--text-body);
}

.pl-rule summary {
    cursor: pointer;
    font-weight: 700;
    color: var(--text-primary);
}

.pl-rule ol {
    margin: 8px 0 0;
    padding-left: 20px;
    display: grid;
    gap: 6px;
    line-height: 1.5;
}

/* The classic roster's own button declarations (Roster.vue). */
button.btn-primary-sm {
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

button.btn-outline-sm {
    font-family: inherit;
    cursor: pointer;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

a.text-link-sm {
    text-decoration: none;
}

/* A button held down until there is something to save looks held down (app.css's 0.55, as the shift panel's). */
button[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}
</style>

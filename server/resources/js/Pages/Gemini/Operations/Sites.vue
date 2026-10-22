<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SiteMap from '../../../Components/SiteMap.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import Subnav from './Subnav.vue'
import { parsePosition } from '../../../lib/coordinates.js'
import { matchPlacements } from '../../../lib/checkpointMessages.js'
import { useLiveReload } from '../../../composables/useLiveReload'

// Who is on site, each site's officers and its client record move this page by themselves (M18H §7.2).
useLiveReload({ entities: ['shift', 'client', 'officer'] })

/**
 * Sites & geofences — M11 §6, finished for M12 §1: THE ONLY PLACE A GEOFENCE IS MADE. Where a client's posts and
 * checkpoints stand, on an OpenStreetMap map, the geofence a guard must be inside to clock in at each post, and the
 * estate's own fence — the one a clock-in falls back to when the rostered post has no position of its own.
 *
 * Placing is one act, said in words before it happens: choose Place (or Add a post, or Place the estate), then click
 * where it stands, or type the position, or paste a Google Maps address; set the radius and watch the circle; say
 * why; Save. Nothing moves on a click alone, because moving a geofence changes who can clock in there. Every save
 * is audited with who, when, the position before and after, and the reason. A role that may only view sees the
 * same map and no controls.
 *
 * M26, M27: a post carries the other names the estate uses for it ("Gate 5, Phase 5 Gate"), and a post that does not
 * exist is removed here. A patrol is not a post: it is a shift's duty at the gate it starts from. The estate's patrol
 * route is its own list of checkpoints, made many at once, printed together, and placed from the installers' messages.
 */
const props = defineProps({
    estates: { type: Array, required: true },
    estate: { type: String, default: null },
    posts: { type: Array, required: true },
    checkpoints: { type: Array, required: true },
    canPlace: { type: Boolean, default: false },
    radius: { type: Object, required: true },
    /** A checkpoint's circle: `{min, max, default}` metres (M21 §1.1) — a tag on a wall, not a gate's geofence. */
    checkpointRadius: { type: Object, default: () => ({ min: 10, max: 200, default: 25 }) },
    postTypes: { type: Array, default: () => [] },
    fenceRule: { type: Object, default: () => ({ good_accuracy_m: 50, poor_accuracy_m: 200, max_margin_m: 50, margin_per_accuracy: 2 }) },
    /** The estate's own fence and its supervisor's number: `{lat, lng, radius_m, supervisor_phone}`. */
    site: { type: Object, default: null },
    /** TRACK-01: whether this client's own console sees its guards on the map; null with no contract yet. */
    estateSeesGuards: { type: Boolean, default: null },
    /** M27 §4.2: the estate's phases, for the phase a checkpoint stands in. */
    phases: { type: Array, default: () => [] },
    /** M27 §4.4: how long after one checkpoint the next is due on this estate's patrol. */
    patrolInterval: { type: Object, default: () => ({ minutes: 15, set: false, default: 15, grace: 10, min: 5, max: 240 }) },
})

const page = usePage()
const errors = computed(() => page.props.errors ?? {})
const estateName = computed(() => props.estates.find((e) => e.id === props.estate)?.name ?? 'this client')
const sitePlaced = computed(() => props.site && props.site.lat !== null && props.site.lng !== null)

/* ---- TRACK-01 ---- */
const switching = ref(false)

function setEstateSeesGuards(sees) {
    if (!props.estate) return

    switching.value = true
    router.post(`/guards/sites/${props.estate}/guard-positions`, { sees }, { preserveScroll: true, onFinish: () => { switching.value = false } })
}

/* ---- placing ---- */

/**
 * What is being placed: `{kind: 'post'|'checkpoint'|'new-post'|'new-checkpoint'|'new-route-checkpoint'|'estate', id,
 * title}`, or null. A new checkpoint on an older route hangs from its post (`id`); one on the estate's own route
 * (M27) has a phase and may be made with no position, placed later from where its tag is mounted.
 */
const placing = ref(null)
/** Where it goes, before Save — from a click, or from the position field. */
const picked = ref(null)
const positionText = ref('')
const positionError = ref(null)
const radiusM = ref(50)
const reason = ref('')
const newName = ref('')
const newType = ref('gate')
const newAlsoCalled = ref('')
const newPhase = ref('')
const saving = ref(false)

const isRouteCheckpoint = computed(() => placing.value?.kind === 'new-route-checkpoint')
const hasRadius = computed(() => placing.value && placing.value.kind !== 'checkpoint')
const needsReason = computed(() => placing.value && placing.value.kind !== 'checkpoint')
const isNew = computed(() => ['new-post', 'new-checkpoint', 'new-route-checkpoint'].includes(placing.value?.kind))
const isCheckpointKind = computed(() => placing.value?.kind === 'new-checkpoint' || isRouteCheckpoint.value)
const bounds = computed(() => (isCheckpointKind.value ? props.checkpointRadius : props.radius))
const radiusOk = computed(() => Number.isInteger(Number(radiusM.value)) && Number(radiusM.value) >= bounds.value.min && Number(radiusM.value) <= bounds.value.max)
const reasonOk = computed(() => reason.value.trim().length >= 3)
const nameOk = computed(() => newName.value.trim().length > 0)
// A checkpoint on the estate's route may be made with no position (M27 §4.3): its tag first, its place later.
const ready = computed(() => (isRouteCheckpoint.value || !!picked.value) && (!hasRadius.value || radiusOk.value) && (!needsReason.value || reasonOk.value) && (!isNew.value || nameOk.value))
const notReadyWhy = computed(() => {
    if (!placing.value) return null
    if (!picked.value && !isRouteCheckpoint.value) return placing.value.kind === 'new-checkpoint' ? 'Click where the tag will hang on the map, or type or paste its position.' : 'Click where it stands on the map, or type or paste its position.'
    if (placing.value.kind === 'new-post' && !nameOk.value) return 'Give the post a name the roster can show.'
    if (isCheckpointKind.value && !nameOk.value) return 'Give the checkpoint a name a guard can find at night — a landmark, "Basketball court", "Pool gate".'
    if (hasRadius.value && !radiusOk.value) return `The radius is between ${bounds.value.min} and ${bounds.value.max} metres.`
    if (needsReason.value && !reasonOk.value) return 'Say why — it is kept in the audit log beside the change.'
    return null
})

const fixed = (n) => Number(n).toFixed(6)

watch(picked, (p) => {
    // A click writes the position into the field; typing into the field moves the pin (below).
    if (p && positionText.value.trim() !== `${fixed(p.lat)}, ${fixed(p.lng)}`) positionText.value = `${fixed(p.lat)}, ${fixed(p.lng)}`
})

function readPosition() {
    const text = positionText.value.trim()
    if (text === '') {
        positionError.value = null
        if (isRouteCheckpoint.value) picked.value = null
        return
    }

    const got = parsePosition(text)
    if (got.error) {
        positionError.value = got.error
        return
    }

    positionError.value = null
    picked.value = { lat: got.lat, lng: got.lng }
}

function begin(kind, item = null) {
    placing.value = kind === 'new-post'
        ? { kind, id: null, title: 'a new post' }
        : kind === 'new-checkpoint'
            ? { kind, id: item.id, title: `a new checkpoint on ${item.name}` }
            : kind === 'new-route-checkpoint'
                ? { kind, id: null, title: 'a new checkpoint on the patrol route' }
                : kind === 'estate'
                    ? { kind, id: props.estate, title: `${estateName.value} (the estate itself)` }
                    : { kind, id: item.id, title: kind === 'post' ? (item.label ?? item.name) : item.label }
    const from = kind === 'estate' ? props.site : ['new-checkpoint', 'new-route-checkpoint', 'new-post'].includes(kind) ? null : item
    picked.value = from && from.lat !== null && from.lat !== undefined ? { lat: from.lat, lng: from.lng } : null
    positionText.value = picked.value ? `${fixed(picked.value.lat)}, ${fixed(picked.value.lng)}` : ''
    positionError.value = null
    radiusM.value = kind === 'post' ? item.radius_m : kind === 'estate' ? (props.site?.radius_m ?? 300) : ['new-checkpoint', 'new-route-checkpoint'].includes(kind) ? props.checkpointRadius.default : 50
    reason.value = ''
    newName.value = ''
    newType.value = props.postTypes[0]?.value ?? 'gate'
    newAlsoCalled.value = ''
    newPhase.value = ''
}

function cancel() {
    placing.value = null
    picked.value = null
    positionText.value = ''
    positionError.value = null
}

function onPick(position) {
    if (placing.value) picked.value = position
}

const orNull = (s) => (s.trim() === '' ? null : s.trim())

function save() {
    if (!placing.value || !ready.value) return

    const kind = placing.value.kind
    const at = picked.value ? { lat: picked.value.lat, lng: picked.value.lng } : {}
    const [url, body] = kind === 'post'
        ? [`/guards/sites/posts/${placing.value.id}`, { ...at, radius_m: Number(radiusM.value), reason: reason.value }]
        : kind === 'checkpoint'
            ? [`/guards/sites/checkpoints/${placing.value.id}`, at]
            : kind === 'new-post'
                ? [`/guards/sites/${props.estate}/posts`, { ...at, radius_m: Number(radiusM.value), reason: reason.value, name: newName.value, type: newType.value, also_called: orNull(newAlsoCalled.value) }]
                : kind === 'new-checkpoint'
                    ? [`/guards/sites/posts/${placing.value.id}/checkpoints`, { ...at, radius_m: Number(radiusM.value), reason: reason.value, label: newName.value }]
                    : kind === 'new-route-checkpoint'
                        ? [`/guards/sites/${props.estate}/checkpoints`, { ...at, radius_m: Number(radiusM.value), reason: reason.value, label: newName.value, phase: orNull(newPhase.value) }]
                        : [`/guards/sites/${props.estate}/estate`, { ...at, radius_m: Number(radiusM.value), reason: reason.value, supervisor_phone: props.site?.supervisor_phone ?? null }]

    saving.value = true
    router.post(url, body, { preserveScroll: true, onSuccess: () => cancel(), onFinish: () => { saving.value = false } })
}

const patrolRoutes = computed(() => props.posts.filter((p) => p.type === 'patrol'))

function routeOf(checkpoint) {
    return checkpoint.post_id === null ? 'Patrol route' : (props.posts.find((p) => p.id === checkpoint.post_id)?.name ?? 'No route')
}

function unplace(checkpoint) {
    router.post(`/guards/sites/checkpoints/${checkpoint.id}`, { lat: null, lng: null }, { preserveScroll: true })
}

/*
 * Taking a checkpoint off its route (M22): said in words, with a reason, before it happens. It leaves the tour and
 * the map, its tag is refused from then on, and the route's other stops are numbered again.
 */
const retiring = ref(null)
const retireReason = ref('')
const retireSaving = ref(false)

function beginRetire(checkpoint) {
    retiring.value = checkpoint.id
    retireReason.value = ''
}

function retire(checkpoint) {
    if (retireReason.value.trim().length < 3) return
    retireSaving.value = true
    router.post(`/guards/sites/checkpoints/${checkpoint.id}/retire`, { reason: retireReason.value }, {
        preserveScroll: true,
        onSuccess: () => { retiring.value = null; retireReason.value = '' },
        onFinish: () => { retireSaving.value = false },
    })
}

/* ---- the route's order (M27 §4.4) ---- */
function move(checkpoint, direction) {
    router.post(`/guards/sites/checkpoints/${checkpoint.id}/move`, { direction }, { preserveScroll: true })
}

const routeCheckpoints = computed(() => props.checkpoints.filter((c) => c.post_id === null))
const isFirstOnRoute = (cp) => props.checkpoints.filter((c) => c.post_id === cp.post_id)[0]?.id === cp.id
const isLastOnRoute = (cp) => props.checkpoints.filter((c) => c.post_id === cp.post_id).slice(-1)[0]?.id === cp.id

/*
 * ONE POST'S OTHER NAMES, OR TAKING A POST AWAY (M26) — each said in words, with a reason, before it happens.
 * `editing` is `{id, what}`; one at a time.
 */
const editing = ref(null)
const editText = ref('')
const editReason = ref('')
const editSaving = ref(false)

function beginEdit(post, what) {
    editing.value = { id: post.id, what }
    editText.value = post.also_called ?? ''
    editReason.value = ''
}

const isEditing = (post, what) => editing.value?.id === post.id && editing.value?.what === what

function saveEdit(post) {
    if (editReason.value.trim().length < 3 || !editing.value) return
    const [url, body] = editing.value.what === 'names'
        ? [`/guards/sites/posts/${post.id}/also-called`, { also_called: orNull(editText.value), reason: editReason.value }]
        : [`/guards/sites/posts/${post.id}/remove`, { reason: editReason.value }]
    editSaving.value = true
    router.post(url, body, { preserveScroll: true, onSuccess: () => { editing.value = null }, onFinish: () => { editSaving.value = false } })
}

/*
 * THE PATROL ROUTE, MANY AT A TIME (M27 §4). Fifteen checkpoints around five phases: their names in one list, their
 * tags in one print, and their positions from the installers' messages in one paste — shown line by line, and saved
 * only when the Director confirms what each line was read as.
 */
const panel = ref(null) // 'many' | 'place' | 'interval'
const manyLines = ref('')
const manyReason = ref('')
const placeText = ref('')
const placeReason = ref('')
const intervalMinutes = ref(props.patrolInterval.set ? props.patrolInterval.minutes : '')
const intervalReason = ref('')
const bulkSaving = ref(false)

watch(() => props.patrolInterval, (v) => { intervalMinutes.value = v.set ? v.minutes : '' })

function openPanel(which) {
    panel.value = panel.value === which ? null : which
}

const placements = computed(() => matchPlacements(placeText.value, props.checkpoints.map((c) => ({ id: c.id, label: c.label }))))
const placeable = computed(() => placements.value.filter((p) => p.status === 'ok'))

function addMany() {
    if (manyReason.value.trim().length < 3 || manyLines.value.trim() === '') return
    bulkSaving.value = true
    router.post(`/guards/sites/${props.estate}/checkpoints/many`, { lines: manyLines.value, reason: manyReason.value }, {
        preserveScroll: true,
        onSuccess: () => { manyLines.value = ''; manyReason.value = ''; panel.value = null },
        onFinish: () => { bulkSaving.value = false },
    })
}

function placeMany() {
    if (placeReason.value.trim().length < 3 || placeable.value.length === 0) return
    bulkSaving.value = true
    router.post(`/guards/sites/${props.estate}/checkpoints/place`, {
        places: placeable.value.map((p) => ({ id: p.checkpoint.id, lat: p.lat, lng: p.lng })),
        reason: placeReason.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { placeText.value = ''; placeReason.value = ''; panel.value = null },
        onFinish: () => { bulkSaving.value = false },
    })
}

function saveInterval() {
    if (intervalReason.value.trim().length < 3) return
    bulkSaving.value = true
    router.post(`/guards/sites/${props.estate}/patrol-interval`, {
        minutes: intervalMinutes.value === '' || intervalMinutes.value === null ? null : Number(intervalMinutes.value),
        reason: intervalReason.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { intervalReason.value = ''; panel.value = null },
        onFinish: () => { bulkSaving.value = false },
    })
}

const STATUS_WORDS = { ok: 'Will be placed', no_match: 'No checkpoint', ambiguous: 'Which one?', no_position: 'No position', duplicate: 'Twice' }

/* ---- the estate's supervisor number, and taking the estate off the map ---- */
const phone = ref(props.site?.supervisor_phone ?? '')
const phoneReason = ref('')
const phoneSaving = ref(false)
watch(() => props.site?.supervisor_phone, (v) => { phone.value = v ?? '' })

function savePhone() {
    if (phoneReason.value.trim().length < 3) return
    phoneSaving.value = true
    router.post(`/guards/sites/${props.estate}/estate`, {
        lat: props.site?.lat ?? null,
        lng: props.site?.lng ?? null,
        radius_m: props.site?.radius_m ?? null,
        supervisor_phone: phone.value.trim() === '' ? null : phone.value.trim(),
        reason: phoneReason.value,
    }, { preserveScroll: true, onSuccess: () => { phoneReason.value = '' }, onFinish: () => { phoneSaving.value = false } })
}

const unplaceReason = ref('')
const unplacingEstate = ref(false)

function unplaceEstate() {
    if (unplaceReason.value.trim().length < 3) return
    router.post(`/guards/sites/${props.estate}/estate`, {
        lat: null, lng: null, radius_m: null, supervisor_phone: props.site?.supervisor_phone ?? null, reason: unplaceReason.value,
    }, { preserveScroll: true, onSuccess: () => { unplaceReason.value = ''; unplacingEstate.value = false } })
}

/* ---- the map ---- */
const pins = computed(() => [
    ...props.posts.filter((p) => p.lat !== null).map((p) => ({ id: `post-${p.id}`, lat: p.lat, lng: p.lng, title: p.label ?? p.name, detail: `Post · clock-in within ${p.radius_m} m`, tone: 'neutral' })),
    ...props.checkpoints.filter((c) => c.lat !== null).map((c) => ({ id: `cp-${c.id}`, lat: c.lat, lng: c.lng, title: `${c.sequence}. ${c.label}`, detail: c.phase ? `Checkpoint · ${c.phase}` : 'Checkpoint', tone: 'ok' })),
    ...(sitePlaced.value ? [{ id: 'estate', lat: props.site.lat, lng: props.site.lng, title: `${estateName.value}: the estate`, detail: `Estate fence · ${props.site.radius_m} m`, tone: 'neutral' }] : []),
    // Being placed is amber (`placing`): the panic red is an open alert's alone (three weights, 12 Oct).
    ...(picked.value && placing.value ? [{ id: 'picked', lat: picked.value.lat, lng: picked.value.lng, title: `New position: ${placing.value.title}`, tone: 'placing' }] : []),
    // The pasted messages' positions, before they are saved: where each tag will be put (M27 §4.3).
    ...(panel.value === 'place' ? placeable.value.map((p) => ({ id: `proposed-${p.checkpoint.id}`, lat: p.lat, lng: p.lng, title: `To be placed: ${p.checkpoint.label}`, tone: 'placing' })) : []),
])

const fences = computed(() => [
    ...props.posts.filter((p) => p.lat !== null && !(placing.value?.kind === 'post' && placing.value.id === p.id)).map((p) => ({ id: `fence-${p.id}`, lat: p.lat, lng: p.lng, radiusM: p.radius_m, label: `${p.label ?? p.name} · ${p.radius_m} m` })),
    // Each placed checkpoint's circle: a scan counts inside it (M21 §1.1).
    ...props.checkpoints.filter((c) => c.lat !== null && !(placing.value?.kind === 'checkpoint' && placing.value.id === c.id)).map((c) => ({ id: `fence-cp-${c.id}`, lat: c.lat, lng: c.lng, radiusM: c.radius_m, label: `${c.label} · ${c.radius_m} m` })),
    ...(sitePlaced.value && placing.value?.kind !== 'estate' ? [{ id: 'fence-estate', lat: props.site.lat, lng: props.site.lng, radiusM: props.site.radius_m, label: `The estate · ${props.site.radius_m} m` }] : []),
    // The one being placed, as it will be — dashed, redrawn as the radius changes.
    ...(picked.value && hasRadius.value && radiusOk.value ? [{ id: 'fence-picked', lat: picked.value.lat, lng: picked.value.lng, radiusM: Number(radiusM.value), label: `${placing.value.title} · ${radiusM.value} m`, tone: 'placing', dashed: true }] : []),
])

/** The estate's boundary from its site plan (M57.5 §4.5), drawn as the shape it is. */
const boundaries = computed(() => (props.site?.boundary ? [{ id: 'estate-boundary', points: props.site.boundary.points, label: `${estateName.value}, as its site plan draws it` }] : []))

const unplaced = computed(() => [
    ...props.posts.filter((p) => p.lat === null).map((p) => ({ id: `post-${p.id}`, title: p.label ?? p.name })),
    ...props.checkpoints.filter((c) => c.lat === null).map((c) => ({ id: `cp-${c.id}`, title: c.label })),
])

// Jamaica, until something is placed: where every client is today, and a sensible first view for the first pin.
const center = computed(() => (pins.value.length === 0 ? { lat: 18.0179, lng: -76.8099, zoom: 11 } : null))

function postState(post) {
    if (post.lat !== null) return `Placed · clock-in within ${post.radius_m} m`
    return sitePlaced.value
        ? `Not placed — judged against the estate's own fence (${props.site.radius_m} m) until it is`
        : 'Not placed, and the estate has no fence either: its clock-ins cannot be checked and are marked for the office'
}

function openEstate(event) {
    router.get('/guards/sites', { estate: event.target.value }, { preserveState: false })
}
</script>

<template>
    <Head title="Sites & geofences" />

    <GeminiConsole title="Sites & geofences">
        <Subnav active="sites" />

        <EmptyState
            v-if="estates.length === 0"
            variant="first-use"
            title="No clients to place"
            body="Once a client is on the platform its posts and patrol checkpoints are placed here."
        />

        <template v-else>
            <div class="sites-bar">
                <label class="sites-label" for="sites-estate">Client</label>
                <select id="sites-estate" class="sites-select" :value="estate" @change="openEstate">
                    <option v-for="e in estates" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </div>

            <!-- M12 §1: how the decision is made, in the words the client asked for. -->
            <details class="sites-panel sites-rule" open>
                <summary class="sites-h">How clock-in decides whether a guard is at the estate</summary>
                <ol class="sites-steps">
                    <li>The guard's phone takes GPS readings until it is sure to within {{ fenceRule.good_accuracy_m }} m (or gives up after a short wait).</li>
                    <li>It is compared with the geofence of <strong>the post the guard is rostered to</strong> — a patrol officer's too, at the gate the patrol starts from — or, if that post is not placed, with the estate's own fence below. Neither placed: the shift starts and is marked "no geofence configured" for the office.</li>
                    <li>The reading counts as a circle, not a point: {{ fenceRule.margin_per_accuracy === 2 ? 'twice' : `${fenceRule.margin_per_accuracy} times` }} the phone's own accuracy, up to {{ fenceRule.max_margin_m }} m, is allowed as margin of error. Worse than {{ fenceRule.poor_accuracy_m }} m, the position "could not be established".</li>
                    <li>Inside, the guard confirms with their fingerprint or face. Outside, or no position: the guard is told the post, how far and which way, and a supervisor's PIN can start the shift — flagged, audited and reported to the Head of Security. A mocked location is always flagged.</li>
                    <li>A patrol is then shown by its checkpoint scans and the officer's position on duty, never by a circle: a vehicle covering the estate fits none.</li>
                </ol>
            </details>

            <div class="sites-grid">
                <section class="sites-panel">
                    <SiteMap
                        :pins="pins"
                        :fences="fences"
                        :boundaries="boundaries"
                        :unplaced="unplaced"
                        :picking="placing !== null"
                        :center="center"
                        :focus="picked"
                        :height="460"
                        label="The client's posts, their geofences, the estate's own fence and the patrol checkpoints"
                        @pick="onPick"
                    />

                    <form v-if="placing" class="sites-place" role="region" aria-label="Placing on the map" @submit.prevent="save">
                        <p class="sites-place__title">Placing {{ placing.title }}</p>

                        <template v-if="placing.kind === 'new-post'">
                            <label class="sites-field">
                                Post name
                                <input v-model="newName" type="text" maxlength="120" placeholder="e.g. Main Gate, Back Gate" />
                            </label>
                            <label class="sites-field">
                                Also called — the other names the estate uses for it, if any
                                <input v-model="newAlsoCalled" type="text" maxlength="60" placeholder="e.g. Gate 5, Phase 5 Gate" />
                            </label>
                            <label class="sites-field">
                                Kind of post
                                <select v-model="newType">
                                    <option v-for="t in postTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                                </select>
                            </label>
                        </template>

                        <template v-if="isCheckpointKind">
                            <label class="sites-field">
                                Checkpoint name — a landmark the guard knows at night
                                <input v-model="newName" type="text" maxlength="120" placeholder="e.g. Basketball court, Pool gate, Phase 3 roundabout" />
                            </label>
                            <label v-if="isRouteCheckpoint && phases.length > 0" class="sites-field">
                                Phase
                                <select v-model="newPhase">
                                    <option value="">Not said</option>
                                    <option v-for="ph in phases" :key="ph" :value="ph">{{ ph }}</option>
                                </select>
                            </label>
                        </template>

                        <label class="sites-field">
                            Position — click the map, type it, or paste a Google Maps address{{ isRouteCheckpoint ? ' (or leave it empty, and place it once its tag is mounted)' : '' }}
                            <input v-model="positionText" type="text" inputmode="text" placeholder="e.g. 18.0179, -76.8099" @change="readPosition" @blur="readPosition" @keydown.enter.prevent="readPosition" />
                        </label>
                        <p v-if="positionError" class="sites-error" role="alert">{{ positionError }}</p>

                        <label v-if="hasRadius" class="sites-field">
                            {{ isCheckpointKind ? 'How close a phone must be to the tag for a scan to count' : 'Clock-in geofence radius' }}, metres ({{ bounds.min }}–{{ bounds.max }})
                            <input v-model.number="radiusM" type="number" :min="bounds.min" :max="bounds.max" step="1" />
                        </label>

                        <label v-if="needsReason" class="sites-field">
                            Why
                            <input v-model="reason" type="text" maxlength="300" placeholder="e.g. surveyed on site, gate moved, client demonstration" />
                        </label>

                        <p v-if="errors.place || errors.add || errors.site || errors.checkpoint" class="sites-error" role="alert">{{ errors.place || errors.add || errors.site || errors.checkpoint }}</p>
                        <p v-else-if="notReadyWhy" class="sites-muted">{{ notReadyWhy }}</p>

                        <div class="sites-actions">
                            <button type="submit" class="btn-primary-sm" :disabled="!ready || saving">{{ saving ? 'Saving…' : placing.kind === 'new-post' ? 'Add the post' : isCheckpointKind ? 'Add the checkpoint' : 'Save position' }}</button>
                            <button type="button" class="btn-outline-sm" :disabled="saving" @click="cancel">Cancel</button>
                        </div>
                    </form>
                </section>

                <section class="sites-panel sites-list" aria-label="Posts, the estate and checkpoints">
                    <div class="sites-head">
                        <h2 class="sites-h">Posts</h2>
                        <button v-if="canPlace && estate" type="button" class="btn-outline-sm" @click="begin('new-post')">Add a post</button>
                    </div>
                    <p v-if="posts.length === 0" class="sites-muted">This client has no posts yet{{ canPlace ? ' — add its gates here' : '' }}.</p>
                    <ul>
                        <li v-for="post in posts" :key="post.id" class="sites-row">
                            <div>
                                <div class="sites-name">{{ post.name }}</div>
                                <div v-if="post.also_called" class="sites-muted">Also called {{ post.also_called }}</div>
                                <div class="sites-muted">{{ postState(post) }}</div>
                            </div>
                            <div v-if="canPlace" class="sites-row-actions">
                                <button type="button" class="btn-outline-sm" @click="begin('post', post)">{{ post.lat === null ? 'Place' : 'Move' }}</button>
                                <button type="button" class="btn-outline-sm" @click="beginEdit(post, 'names')">Other names</button>
                                <button v-if="post.type === 'patrol'" type="button" class="btn-outline-sm" @click="begin('new-checkpoint', post)">Add a checkpoint</button>
                                <button type="button" class="btn-outline-sm" @click="beginEdit(post, 'remove')">Remove</button>
                            </div>
                            <form v-if="canPlace && isEditing(post, 'names')" class="sites-inline" @submit.prevent="saveEdit(post)">
                                <p class="sites-muted">What else the estate calls {{ post.name }}. Every screen that names the post shows them beside it — the roster, the Guard App, the clock-in and the gate log — so an officer who knows only the local name recognises it. Leave it empty for none.</p>
                                <label class="sites-field">
                                    Also called
                                    <input v-model="editText" type="text" maxlength="60" placeholder="e.g. Gate 5, Phase 5 Gate" />
                                </label>
                                <label class="sites-field">
                                    Why
                                    <input v-model="editReason" type="text" maxlength="300" placeholder="e.g. the name the officers use" />
                                </label>
                                <button type="submit" class="btn-outline-sm" :disabled="editSaving || editReason.trim().length < 3">{{ editSaving ? 'Saving…' : 'Save the names' }}</button>
                                <button type="button" class="btn-outline-sm" @click="editing = null">Cancel</button>
                                <p v-if="errors.names" class="sites-error" role="alert">{{ errors.names }}</p>
                            </form>
                            <form v-if="canPlace && isEditing(post, 'remove')" class="sites-inline" @submit.prevent="saveEdit(post)">
                                <p class="sites-muted">{{ post.name }} goes from the roster, the map and every list. If nothing was ever done at it, it is deleted; if shifts or gate entries were recorded there, it is retired and they keep its name. Shifts not yet started there are taken off the roster, and officers who had it as their post are left without one.</p>
                                <label class="sites-field">
                                    Why
                                    <input v-model="editReason" type="text" maxlength="300" placeholder="e.g. the estate has no such gate" />
                                </label>
                                <button type="submit" class="btn-outline-sm" :disabled="editSaving || editReason.trim().length < 3">{{ editSaving ? 'Removing…' : 'Remove the post' }}</button>
                                <button type="button" class="btn-outline-sm" @click="editing = null">Keep it</button>
                                <p v-if="errors.remove" class="sites-error" role="alert">{{ errors.remove }}</p>
                            </form>
                        </li>
                    </ul>

                    <h2 class="sites-h">The estate itself</h2>
                    <p class="sites-muted">
                        {{ sitePlaced
                            ? `Placed · ${site.radius_m} m. A post that is not placed is judged against this, and the clock-in's record says so.`
                            : 'Not placed. A post with no position of its own then has no geofence at all.' }}
                    </p>
                    <div v-if="canPlace" class="sites-actions">
                        <button type="button" class="btn-outline-sm" @click="begin('estate')">{{ sitePlaced ? 'Move the estate' : 'Place the estate' }}</button>
                        <button v-if="sitePlaced" type="button" class="btn-outline-sm" @click="unplacingEstate = !unplacingEstate">Take it off the map</button>
                    </div>
                    <form v-if="canPlace && unplacingEstate" class="sites-inline" @submit.prevent="unplaceEstate">
                        <label class="sites-field">
                            Why
                            <input v-model="unplaceReason" type="text" maxlength="300" placeholder="e.g. every post is placed now" />
                        </label>
                        <button type="submit" class="btn-outline-sm" :disabled="unplaceReason.trim().length < 3">Take the estate off the map</button>
                    </form>

                    <form class="sites-inline" @submit.prevent="savePhone">
                        <label class="sites-field">
                            Supervisor's number — what a guard at the gate calls
                            <input v-model="phone" type="tel" maxlength="32" :disabled="!canPlace" placeholder="e.g. 876-555-0142" />
                        </label>
                        <template v-if="canPlace">
                            <label class="sites-field">
                                Why
                                <input v-model="phoneReason" type="text" maxlength="300" placeholder="e.g. new night supervisor" />
                            </label>
                            <button type="submit" class="btn-outline-sm" :disabled="phoneSaving || phoneReason.trim().length < 3">{{ phoneSaving ? 'Saving…' : 'Save the number' }}</button>
                        </template>
                        <p v-if="!site?.supervisor_phone" class="sites-muted">No number is set: a guard refused at the gate is offered a message to the control room instead of a call.</p>
                    </form>

                    <div class="sites-head">
                        <h2 class="sites-h">Patrol checkpoints</h2>
                        <a v-if="checkpoints.length > 0 && canPlace && estate" class="btn-outline-sm" :href="`/guards/sites/${estate}/tags`" target="_blank" rel="noopener">Print every tag</a>
                    </div>
                    <p class="sites-muted">
                        The estate's patrol route: a patrol shift tours these, in this order, from the gate it starts at. The next checkpoint is due
                        <strong>{{ patrolInterval.minutes }} minutes</strong> after the last{{ patrolInterval.set ? ' (set for this estate)' : ' (the platform\'s figure)' }};
                        {{ patrolInterval.grace }} minutes past that, the officer shows "Missed checkpoint" on Patrol monitoring.
                    </p>
                    <div v-if="canPlace && estate" class="sites-actions">
                        <button type="button" class="btn-outline-sm" @click="begin('new-route-checkpoint')">Add a checkpoint</button>
                        <button type="button" class="btn-outline-sm" @click="openPanel('many')">Add many at once</button>
                        <button v-if="checkpoints.some((c) => c.lat === null)" type="button" class="btn-outline-sm" @click="openPanel('place')">Place from messages</button>
                        <button type="button" class="btn-outline-sm" @click="openPanel('interval')">Patrol interval</button>
                    </div>

                    <form v-if="canPlace && panel === 'many'" class="sites-inline" @submit.prevent="addMany">
                        <p class="sites-muted">One checkpoint to a line, in the order the patrol reaches them, each a landmark a guard finds at night. Start a line with its phase to print the phase on its tag: "Phase 2: Pool deck". They are made with no position: print their tags, mount them, then place each from the installer's message.</p>
                        <label class="sites-field">
                            Checkpoints
                            <textarea v-model="manyLines" rows="8" maxlength="8000" placeholder="Phase 1: Basketball court&#10;Phase 1: Community centre&#10;Phase 2: Pool deck"></textarea>
                        </label>
                        <label class="sites-field">
                            Why
                            <input v-model="manyReason" type="text" maxlength="300" placeholder="e.g. the client's fifteen checkpoints" />
                        </label>
                        <button type="submit" class="btn-outline-sm" :disabled="bulkSaving || manyReason.trim().length < 3 || manyLines.trim() === ''">{{ bulkSaving ? 'Adding…' : 'Add them' }}</button>
                        <p v-if="errors.checkpoints" class="sites-error" role="alert">{{ errors.checkpoints }}</p>
                    </form>

                    <form v-if="canPlace && panel === 'place'" class="sites-inline" @submit.prevent="placeMany">
                        <p class="sites-muted">Paste the installers' messages: each line a checkpoint's name and its two numbers, "Basketball court, 18.01234, -76.95678". Each line is matched to its checkpoint and shown below, and on the map in red. Only the lines marked "Will be placed" are saved, when you confirm.</p>
                        <label class="sites-field">
                            Messages
                            <textarea v-model="placeText" rows="8" placeholder="Basketball court, 18.01234, -76.95678"></textarea>
                        </label>
                        <table v-if="placements.length > 0" class="sites-table" aria-label="What each pasted line was read as">
                            <thead><tr><th>Line</th><th>Checkpoint</th><th>Position</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="(p, i) in placements" :key="i">
                                    <td>{{ p.line }}</td>
                                    <td>{{ p.checkpoint?.label ?? '—' }}</td>
                                    <td>{{ p.lat === null ? '—' : `${fixed(p.lat)}, ${fixed(p.lng)}` }}</td>
                                    <td :class="p.status === 'ok' ? 'sites-ok' : 'sites-error'" :title="p.why ?? ''">{{ STATUS_WORDS[p.status] }}{{ p.why ? ` — ${p.why}` : '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <label class="sites-field">
                            Why
                            <input v-model="placeReason" type="text" maxlength="300" placeholder="e.g. positions sent by the installers on site" />
                        </label>
                        <button type="submit" class="btn-outline-sm" :disabled="bulkSaving || placeReason.trim().length < 3 || placeable.length === 0">{{ bulkSaving ? 'Placing…' : `Place ${placeable.length} checkpoint${placeable.length === 1 ? '' : 's'}` }}</button>
                        <p v-if="errors.placeMany" class="sites-error" role="alert">{{ errors.placeMany }}</p>
                    </form>

                    <form v-if="canPlace && panel === 'interval'" class="sites-inline" @submit.prevent="saveInterval">
                        <p class="sites-muted">How long after one checkpoint the next is due on this estate's patrol, in minutes ({{ patrolInterval.min }}–{{ patrolInterval.max }}). A guard walking a building reaches the next tag in minutes; a vehicle covering the whole estate takes longer between tags. Leave it empty for the platform's {{ patrolInterval.default }}.</p>
                        <label class="sites-field">
                            Minutes between checkpoints
                            <input v-model.number="intervalMinutes" type="number" :min="patrolInterval.min" :max="patrolInterval.max" step="1" :placeholder="String(patrolInterval.default)" />
                        </label>
                        <label class="sites-field">
                            Why
                            <input v-model="intervalReason" type="text" maxlength="300" placeholder="e.g. a vehicle patrol across five phases" />
                        </label>
                        <button type="submit" class="btn-outline-sm" :disabled="bulkSaving || intervalReason.trim().length < 3">{{ bulkSaving ? 'Saving…' : 'Save the interval' }}</button>
                        <p v-if="errors.interval" class="sites-error" role="alert">{{ errors.interval }}</p>
                    </form>

                    <p v-if="checkpoints.length === 0" class="sites-muted">
                        This client has no patrol checkpoints yet{{ canPlace ? ' — add them above, one at a time or many at once' : '' }}.
                    </p>
                    <p v-if="errors.move" class="sites-error" role="alert">{{ errors.move }}</p>
                    <ul>
                        <li v-for="cp in checkpoints" :key="cp.id" class="sites-row">
                            <div>
                                <div class="sites-name">{{ cp.sequence }}. {{ cp.label }}</div>
                                <div class="sites-muted">{{ cp.post_id === null ? (cp.phase ?? 'Patrol route') : `${routeOf(cp)}${cp.phase ? ` · ${cp.phase}` : ''}` }} · {{ cp.lat === null ? 'Not placed yet: its scans cannot be checked against where it stands' : `Placed · a scan counts within ${cp.radius_m} m` }}</div>
                            </div>
                            <div v-if="canPlace" class="sites-row-actions">
                                <button type="button" class="btn-outline-sm" :disabled="isFirstOnRoute(cp)" title="Earlier on the route" @click="move(cp, 'up')">Up</button>
                                <button type="button" class="btn-outline-sm" :disabled="isLastOnRoute(cp)" title="Later on the route" @click="move(cp, 'down')">Down</button>
                                <button type="button" class="btn-outline-sm" @click="begin('checkpoint', cp)">{{ cp.lat === null ? 'Place' : 'Move' }}</button>
                                <button v-if="cp.lat !== null" type="button" class="btn-outline-sm" @click="unplace(cp)">Remove</button>
                                <!-- M21 §1.1: the tag, printed — a page of its own, opened beside this one. The same code every time. -->
                                <a class="btn-outline-sm" :href="`/guards/sites/checkpoints/${cp.id}/tag`" target="_blank" rel="noopener">Print tag</a>
                                <button v-if="retiring !== cp.id" type="button" class="btn-outline-sm" @click="beginRetire(cp)">Take off the route</button>
                            </div>
                            <form v-if="canPlace && retiring === cp.id" class="sites-inline" @submit.prevent="retire(cp)">
                                <p class="sites-muted">{{ cp.label }} leaves {{ routeOf(cp) }}: out of the guards' tour and off the map, and its tag is refused from now. Its scans stay on the record. The other checkpoints are numbered again.</p>
                                <label class="sites-field">
                                    Why
                                    <input v-model="retireReason" type="text" maxlength="300" placeholder="e.g. placed in error, or the gate was bricked up" />
                                </label>
                                <button type="submit" class="btn-outline-sm" :disabled="retireSaving || retireReason.trim().length < 3">{{ retireSaving ? 'Taking it off…' : 'Take it off the route' }}</button>
                                <button type="button" class="btn-outline-sm" @click="retiring = null">Keep it</button>
                                <p v-if="errors.retire" class="sites-error" role="alert">{{ errors.retire }}</p>
                            </form>
                        </li>
                    </ul>
                    <p v-if="patrolRoutes.length > 0 && routeCheckpoints.length === 0" class="sites-muted">The checkpoints above hang from an older patrol post. New ones go on the estate's own route.</p>

                    <!--
                      TRACK-01 (M11 §3.3): a guard's position belongs to the security company, so this client's own
                      console shows "guards on site" and no pins. Whether its Head of Security should see the pins is
                      the client's policy decision, recorded per contract and off until it is switched on here.
                    -->
                    <h2 class="sites-h">Guard positions on the client's console</h2>
                    <p v-if="estateSeesGuards === null" class="sites-muted">This client has no contract yet. The setting belongs to the contract; set the client's plan first.</p>
                    <template v-else>
                        <p class="sites-muted">
                            {{ estateSeesGuards
                                ? 'On: this client\'s Security page shows its officers on the map while they are on duty, each with the age of their position.'
                                : 'Off: this client\'s Security page shows how many officers are on site and who, and not where they stand.' }}
                        </p>
                        <p v-if="errors.sees" class="sites-error" role="alert">{{ errors.sees }}</p>
                        <div v-if="canPlace" class="sites-actions">
                            <button type="button" class="btn-outline-sm" :disabled="switching" @click="setEstateSeesGuards(!estateSeesGuards)">
                                {{ switching ? 'Saving…' : estateSeesGuards ? 'Stop showing guard positions to this client' : 'Show guard positions to this client' }}
                            </button>
                        </div>
                    </template>
                </section>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
.sites-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin: 16px 0;
}

.sites-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--slate-600);
}

.sites-select {
    min-height: 40px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 0 12px;
    background: var(--white);
    color: var(--navy-900);
    font: inherit;
}

.sites-rule {
    margin-bottom: 16px;
}

.sites-rule summary {
    cursor: pointer;
}

.sites-steps {
    margin: 8px 0 0;
    padding-left: 20px;
    display: grid;
    gap: 6px;
    font-size: 13px;
    line-height: 1.5;
    color: var(--navy-900);
}

.sites-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 16px;
}

@media (max-width: 900px) {
    .sites-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

.sites-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 14px;
    min-width: 0;
}

.sites-list ul {
    list-style: none;
    margin: 0 0 12px;
    padding: 0;
}

.sites-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.sites-h {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 12px 0 8px;
}

.sites-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 0;
    border-top: 1px solid var(--navy-100);
}

/* A question about a row (take off the route, other names, remove) asks on a line of its own, under the row. */
.sites-row > form.sites-inline {
    flex-basis: 100%;
}

.sites-row-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 6px;
}

.sites-name {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--navy-900);
    overflow-wrap: anywhere;
}

.sites-muted {
    font-size: 12.5px;
    line-height: 1.45;
    color: var(--slate-600);
    margin: 2px 0 0;
}

.sites-place {
    margin-top: 12px;
    padding: 12px;
    border-radius: 12px;
    background: var(--navy-100);
}

.sites-place__title {
    margin: 0;
    font-weight: 700;
    color: var(--navy-900);
}

.sites-inline {
    margin: 10px 0 4px;
    display: grid;
    gap: 8px;
    justify-items: start;
}

.sites-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--slate-600);
    width: 100%;
}

.sites-field input,
.sites-field select,
.sites-field textarea {
    width: 100%;
    max-width: 420px;
    min-height: 40px;
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 0 10px;
    background: var(--white);
    color: var(--navy-900);
    font: inherit;
    font-weight: 500;
}

.sites-field textarea {
    padding: 8px 10px;
    min-height: 140px;
    resize: vertical;
}

.sites-field input[type='number'] {
    max-width: 160px;
}

.sites-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.sites-table th,
.sites-table td {
    text-align: left;
    padding: 4px 6px;
    border-top: 1px solid var(--navy-100);
    vertical-align: top;
    overflow-wrap: anywhere;
}

.sites-ok {
    color: var(--green-700, #15803d);
    font-size: 12.5px;
}

.sites-error {
    color: var(--red-700);
    font-size: 12.5px;
    margin: 8px 0 0;
}

.sites-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
</style>

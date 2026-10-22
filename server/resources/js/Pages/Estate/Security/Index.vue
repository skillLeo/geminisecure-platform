<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { register as registerLive } from '../../../live/store.js'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import SiteMap from '../../../Components/SiteMap.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useScreenState } from '../../../composables/useScreenState'
import { useLifeSafetyPoll } from '../../../composables/useLifeSafetyPoll.js'
import { ESTATE_POLL_MS, estateAlertChannel, onEstateAlertActivity } from '../../../composables/useEstatePanic.js'
import { agoFrom, useTickingNow } from '../../../composables/useClock.js'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { guardFreshness, minutesAgo } from '../../../lib/guardFreshness.js'

/**
 * The estate's own security screen — M10 §2, and its live map (Hassam, 12 Oct 2026: "the one the client cares most
 * about").
 *
 * THE TEST THIS PAGE IS HELD TO. A committee member who has never been trained opens it at 2 a.m. while something is
 * happening, on a phone. In five seconds they must know: is something wrong, where, who raised it, and is anyone going.
 * So an open panic is the first and loudest thing on the screen, in the product's only solid red, with the lot, the
 * name, how long it has been open, counting, and who is going and how far away they are; the map comes next, every
 * pin labelled in words drawn at all times, because a phone has no hover; the guards on duty are listed beside it and
 * open their details with a tap.
 *
 * WHAT IS ABSENT RATHER THAN HIDDEN: anything about a guard beyond their working presence. Guards are Gemini Security
 * Limited's employees, a different company: the server (`EstateSecurityMap`) sends each guard field the estate may see
 * by name, and nothing else leaves it. Where each guard stands is sent only once the estate may see positions (the
 * client's question, TRACK-01); until then the page lists who is on duty, at which post, and whether they are responding.
 *
 * TWO STATES OF AN OFFICER, AND NO CLOCK-IN TIME (Hassam's ruling 1, 12 Oct): the estate sees "On duty" or "Responding",
 * as the feed sends them (at post and on patrol fold into On duty; a break is not the estate's to see), and never when a
 * shift began. The post stays. The Gemini console keeps all of it.
 *
 * NO CONTROL ON THIS SCREEN WRITES ANYTHING. Sending a guard, acknowledging an alert and closing an incident happen in
 * the control room and the Guard App. The nearest guards are a suggestion, said as one: a person in the control room
 * decides who goes.
 *
 * THE LOT COMES FIRST ON AN ALERT, the client's own instruction: "they must know there is an emergency at a specific
 * lot."
 */
const props = defineProps({
    estate: { type: Object, required: true },
    alerts: { type: Array, required: true },
    incidents: { type: Array, required: true },
    gate: { type: Array, required: true },

    /*
     * The three tables' states (M32 F5, `TableQuery::meta`), each under its own words in the address — `alerts_sort`,
     * `incidents_page`, `gate_per` — so ordering one never moves another.
     */
    alertsTable: { type: Object, required: true },
    incidentsTable: { type: Object, required: true },
    gateTable: { type: Object, required: true },

    /** `{ count, officers }`: how many guards are on duty here, for the figure above the lists. */
    presence: { type: Object, required: true },

    counts: { type: Object, required: true },
    sourceBadge: { type: Object, required: true },

    /** The estate's fixed ground (M11 §6): posts, fences, checkpoints, its boundary, and what lies outside it. */
    geo: { type: Object, default: null },

    /**
     * The live map's one feed (`EstateSecurityMap`, also `GET security/map`): the open panics with who is going and who
     * is nearest, and the guards on duty here, each with only the fields an estate may see.
     */
    map: { type: Object, default: null },
})

useWireframe('community-admin-08-reports-unit-claims-and-notices')

/*
 * Where this console is rooted, read off the page's own URL: an estate's own hostname in production, the estate
 * key in the path locally. Cut at /security, as the finance pages cut at /finance.
 */
const securityPage = usePage()
const rooted = () => {
    const cut = securityPage.url.indexOf('/security')

    return cut === -1 ? '' : securityPage.url.slice(0, cut)
}
const alertHref = (alert) => `${rooted()}/security/alerts/${alert.id}`
/** Batch 1 item 10, 12 Oct: an incident row opens the same way an alert row already does. */
const incidentHref = (incident) => `${rooted()}/security/incidents/${incident.id}`

// A ticking clock: an alert's age and a guard pin's age are as of now, not as of when the page was drawn.
const now = useTickingNow()

// The live map's own clock, every second: an open alert's age counts up, and a pin goes Live to Last seen on time.
const tick = useTickingNow(1000)

const feed = computed(() => props.map ?? { alerts: [], guards: [], positions: false, zone: 'America/Jamaica', tolerance_s: 300 })

/*
 * "2:04 AM": a moment on the estate's own clock, whatever the reader's device says, written as every time on this
 * console is (Law 5, DR-039: no zone on the estate's console; the Gemini console tags a time, this one does not).
 */
const timeWords = (iso) => {
    if (!iso) return ''

    try {
        return new Intl.DateTimeFormat('en-US', { timeZone: feed.value.zone, hour: 'numeric', minute: '2-digit' }).format(new Date(iso))
    } catch {
        return new Date(iso).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
    }
}

const secondsSince = (iso) => Math.max(0, Math.floor((tick.value - Date.parse(iso)) / 1000))

/** How long an alert has been open, counting up: "12 s", "3 min 12 s", then "1 h 4 min". */
const openFor = (iso) => {
    const s = secondsSince(iso)
    const m = Math.floor(s / 60)

    if (m === 0) return `${s} s`

    return m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min ${s % 60} s`
}

/** "240 m", "1.4 km", "12 km". */
const distanceWords = (metres) => {
    if (metres === null || metres === undefined) return null

    return metres < 1000 ? `${metres} m` : `${(metres / 1000).toFixed(metres < 10000 ? 1 : 0)} km`
}

const freshnessOf = (officer) => guardFreshness(officer, tick.value, timeWords)

/** The pin's own words, short: "Live", "4 min ago", "No signal". */
const pinWords = (fresh) => (fresh.freshness === 'live' ? 'Live' : fresh.freshness === 'last_seen' ? minutesAgo(fresh.ageSeconds ?? 60) : fresh.freshness === 'no_signal' ? 'No signal' : 'Waiting')

const FRESH_TONE = { live: 'live', last_seen: 'lastseen', no_signal: 'nosignal', waiting: 'lastseen', not_shared: 'lastseen' }

const openAlerts = computed(() => feed.value.alerts)

/** The guards on duty, with their freshness and their nearest open alert, as of this second. */
const guards = computed(() => feed.value.guards.map((g) => {
    const fresh = feed.value.positions ? freshnessOf(g) : null
    const nearest = [...(g.distances ?? [])].sort((a, b) => a.metres - b.metres)[0] ?? null
    const alert = nearest ? openAlerts.value.find((a) => a.id === nearest.alert_id) : null

    return { ...g, fresh, toAlert: nearest && alert ? `${distanceWords(nearest.metres)} from ${alert.lot}` : null }
}))

const selectedKey = ref(null)
const selected = computed(() => guards.value.find((g) => g.key === selectedKey.value) ?? null)
const choose = (key) => {
    selectedKey.value = selectedKey.value === key ? null : key
}

// A pin on the map opens the same panel as the guard's row in the list; an alert's pin opens the alert.
const onSelect = (pin) => {
    if (pin.id.startsWith('shift-')) {
        choose(pin.id)
    } else if (pin.href) {
        router.visit(pin.href)
    }
}

/*
 * PRESSED IN ONE PLACE, THE PHONE IN ANOTHER NOW (GUARD_TRACKING.md 5.4; the feed's raised_* fields, added by Security
 * at 5902af4f). Where the phone is now is the red pin, the one a guard goes to; where it was pressed is a grey "Pressed
 * here", an older position of the same phone, joined to it by a line. The feed's `position_moved` says only that the
 * phone has SENT a newer position (it sends one every 10 to 15 seconds while the alert is open), so whether it has gone
 * anywhere is measured here: further than a reading's own wobble, 30 m or the newest reading's accuracy if wider.
 */
const metresBetween = (aLat, aLng, bLat, bLng) => {
    const rad = (degrees) => (degrees * Math.PI) / 180
    const h = Math.sin(rad(bLat - aLat) / 2) ** 2 + Math.cos(rad(aLat)) * Math.cos(rad(bLat)) * Math.sin(rad(bLng - aLng) / 2) ** 2

    return Math.round(2 * 6371000 * Math.asin(Math.sqrt(h)))
}

const raisedPlaced = (a) => a.raised_lat != null && a.raised_lng != null && !a.raised_off_estate
const nowPlaced = (a) => a.lat != null && a.lng != null && !a.off_estate

/** How far the phone is now from where it was pressed, when both are on the map; null otherwise. */
const movedMetres = (a) => (a.position_moved && raisedPlaced(a) && nowPlaced(a) ? metresBetween(a.raised_lat, a.raised_lng, a.lat, a.lng) : null)

const pressedApart = (a) => (movedMetres(a) ?? 0) > Math.max(30, a.accuracy_m ?? 0)

/** Where it was pressed has a pin of its own when the phone is somewhere else now, or is off the map altogether. */
const showPressed = (a) => Boolean(a.position_moved) && raisedPlaced(a) && (pressedApart(a) || !nowPlaced(a))

/** Where, in words under the alert; nothing when the phone is where it was pressed and has sent nothing since. */
const whereLine = (a) => {
    if (a.off_estate) return `Where: ${a.off_estate}`
    if (a.lat === null || a.lat === undefined) return 'Where: the phone sent no position'
    if (!a.position_moved) return null
    if (pressedApart(a)) return `The phone has moved ${distanceWords(movedMetres(a))} since it was pressed. The map shows both places, the phone as of ${timeWords(a.position_at)}.`
    if (movedMetres(a) !== null) return `The phone is still where it was pressed, as of ${timeWords(a.position_at)}.`

    return `The map shows the phone as of ${timeWords(a.position_at)}.`
}

// Who is going to each alert, drawn as a dashed line from the guard to the lot; and how far a moving phone has gone.
const mapLines = computed(() => [
    ...openAlerts.value
        .filter((a) => a.responder && a.responder.lat != null && a.lat != null && !a.off_estate)
        .map((a) => ({ id: `going-${a.id}`, from: { lat: a.responder.lat, lng: a.responder.lng }, to: { lat: a.lat, lng: a.lng }, label: `${a.responder.name}, ${a.responder.state.toLowerCase()}, ${distanceWords(a.responder.distance_m)} away` })),
    ...openAlerts.value
        .filter(pressedApart)
        .map((a) => ({ id: `moved-${a.id}`, from: { lat: a.raised_lat, lng: a.raised_lng }, to: { lat: a.lat, lng: a.lng }, label: 'From where it was pressed to where the phone is now' })),
])

const placedRow = (row) => row.lat !== null && row.lat !== undefined && row.lng !== null && row.lng !== undefined
const openIncidents = computed(() => props.incidents.filter((i) => !i.closed && placedRow(i)))

const mapPins = computed(() => [
    ...openAlerts.value.filter((a) => placedRow(a) && !a.off_estate).map((a) => ({
        id: `alert-${a.id}`, lat: a.lat, lng: a.lng, title: `${a.kind_label} · ${a.lot}`, detail: a.who, tone: 'danger', href: alertHref(a),
        label: `${a.is_drill ? 'DRILL · ' : ''}${a.lot}`,
    })),
    ...openAlerts.value.filter(showPressed).map((a) => ({
        id: `alert-${a.id}-pressed`, lat: a.raised_lat, lng: a.raised_lng, title: `${a.kind_label} · ${a.lot}, where it was pressed`, detail: a.who, tone: 'stale', href: alertHref(a),
        label: 'Pressed here',
    })),
    ...guards.value.filter((g) => g.fresh && placedRow(g)).map((g) => ({
        id: g.key, lat: g.lat, lng: g.lng, title: g.name, detail: g.fresh.label, tone: FRESH_TONE[g.fresh.freshness],
        label: `${g.name.split(' ')[0]} · ${pinWords(g.fresh)}`,
    })),
    ...openIncidents.value.filter((i) => !i.off_estate).map((i) => ({ id: `incident-${i.id}`, lat: i.lat, lng: i.lng, title: `Incident · ${i.kind_label}`, detail: i.at, tone: 'stale' })),
    ...(props.geo?.checkpoints ?? []).map((p) => ({ ...p, tone: 'neutral' })),
    ...(props.geo?.posts ?? []).map((p) => ({ ...p, tone: 'neutral' })),
])

/*
 * POSITION NOT CONFIRMED (Hassam, 12 Oct): an alert, incident, gate or checkpoint outside the estate's boundary is named
 * under the map with how far out it is and never plotted. One bad coordinate cannot stretch the map across continents.
 */
const unconfirmed = computed(() => [
    ...openAlerts.value.filter((a) => a.off_estate).map((a) => ({ id: `alert-${a.id}`, title: `${a.kind_label} · ${a.lot}`, why: a.off_estate, href: alertHref(a) })),
    ...openAlerts.value.filter((a) => a.position_moved && a.raised_off_estate).map((a) => ({ id: `alert-${a.id}-pressed`, title: `${a.kind_label} · ${a.lot}, where it was pressed`, why: a.raised_off_estate, href: alertHref(a) })),
    ...openIncidents.value.filter((i) => i.off_estate).map((i) => ({ id: `incident-${i.id}`, title: `Incident · ${i.kind_label}`, why: i.off_estate })),
    ...(props.geo?.unconfirmed ?? []),
])

const mapFences = computed(() => [
    ...(props.geo?.posts ?? []).map((p) => ({ id: `fence-${p.id}`, lat: p.lat, lng: p.lng, radiusM: p.radius_m, label: `${p.title} · clock-in within ${p.radius_m} m` })),
    // The estate's own fence (M12 §1): what a post with no position of its own is judged against. Placed by Gemini.
    ...(props.geo?.site ? [{ id: 'fence-estate', lat: props.geo.site.lat, lng: props.geo.site.lng, radiusM: props.geo.site.radius_m, label: props.geo?.boundary ? `Clock-in fence for a post not yet placed · ${props.geo.site.radius_m} m` : `The estate · ${props.geo.site.radius_m} m` }] : []),
])

/** The estate's boundary from its site plan (M57.5 §4.5), drawn as the shape it is. */
const mapBoundaries = computed(() => (props.geo?.boundary ? [{ id: 'estate-boundary', points: props.geo.boundary.points, label: 'The estate, as its site plan draws it' }] : []))

// The map fills a laptop's view beside the guards; on a phone it shares the screen with the alert above it.
const narrow = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 640px)').matches : false
const mapHeight = narrow ? 300 : 440

/*
 * THIS SCREEN KEEPS ITSELF CURRENT (B108).
 *
 * It re-reads what it draws when the estate's channel says something moved (every step of an alert, a guard's newer
 * position, a guard clocking on or off: the shell holds that channel and says so through `onEstateAlertActivity`), and
 * every fifteen seconds while an alert is open or while the channel is down, when nothing is pushed. Only these props
 * are re-read, so the scroll position stays where the reader left it.
 */
const RELOAD = ['alerts', 'alertsTable', 'incidents', 'incidentsTable', 'gate', 'gateTable', 'presence', 'counts', 'sourceBadge', 'geo', 'map']

/* The three tables (M32 F5). The lot first on an alert, on the client's own instruction. */
const ALERT_COLUMNS = [
    { key: 'unit', label: 'Lot', sortable: true },
    { key: 'kind', label: 'Alert', sortable: true },
    { key: 'who', label: 'Raised by', sortable: true },
    { key: 'raised', label: 'Raised', sortable: true },
    { key: 'state', label: 'State', sortable: true },
    { key: 'open', label: '' },
]

/** The incident ladder's words (`IncidentLog::SEVERITIES`). */
const SEVERITY = { low: 'Low', med: 'Medium', high: 'High' }

const INCIDENT_COLUMNS = [
    { key: 'occurred', label: 'When', sortable: true },
    { key: 'kind', label: 'Incident', sortable: true },
    { key: 'severity', label: 'Severity', sortable: true },
    { key: 'reported_by', label: 'Reported by', sortable: true },
    { key: 'status', label: 'State', sortable: true },
]

const GATE_COLUMNS = [
    { key: 'occurred', label: 'When', sortable: true },
    { key: 'subject', label: 'Who', sortable: true },
    { key: 'post', label: 'Post', sortable: true },
    { key: 'guard', label: 'Officer', sortable: true },
    { key: 'basis', label: 'On what basis' },
    { key: 'outcome', label: 'Outcome', sortable: true },
]

const pollSeconds = ESTATE_POLL_MS / 1000

const live = computed(() => estateAlertChannel.connected.value)
const down = computed(() => estateAlertChannel.down.value)
const anyOpen = computed(() => props.counts.open_alerts > 0 || props.alerts.some((alert) => alert.open))
const shouldPoll = computed(() => anyOpen.value || !live.value)

const poll = useLifeSafetyPoll(RELOAD, ESTATE_POLL_MS, { active: () => shouldPoll.value })

watch(shouldPoll, (polling) => {
    if (polling) {
        poll.start()
    } else {
        poll.stop()
    }
})

// A raise is followed by its count a moment later: one re-read for the pair, not two.
let settling = null

const stopListening = onEstateAlertActivity(() => {
    clearTimeout(settling)
    settling = setTimeout(() => {
        settling = null
        poll.refresh()
    }, 250)
})

// A guard moved (heard here only when this estate may see its guards' positions): the map is read again.
let moving = null

const unregisterMoves = registerLive({
    page: true,
    signals: false,
    channels: { has: (name) => /^estate\.[^.]+\.security$/.test(name) },
    accepts: (env) => env.e === 'guard',
    apply() {
        clearTimeout(moving)
        moving = setTimeout(() => {
            moving = null
            router.reload({ only: ['map'], preserveScroll: true, preserveState: true })
        }, 300)
    },
    resync: () => router.reload({ only: ['map', 'geo'], preserveScroll: true, preserveState: true }),
})

/*
 * A GUARD WITH NO POSITION YET. The estate is not sent the clock-in time (ruling 1), so the page cannot tell by itself
 * when "Waiting for the first position" becomes "No signal since the shift began": the server says, as of each read.
 * While anybody on the screen is waiting, the map alone is read again once a minute, so the line moves within a minute;
 * with nobody waiting, nothing is read. Nothing that would give the clock-in time back is asked for.
 */
const WAITING_REREAD_MS = 60000
const anyWaiting = computed(() => guards.value.some((g) => g.fresh?.freshness === 'waiting'))
let waiting = null

watch(anyWaiting, (someoneWaits) => {
    clearInterval(waiting)
    waiting = someoneWaits ? setInterval(() => router.reload({ only: ['map'], preserveScroll: true, preserveState: true }), WAITING_REREAD_MS) : null
}, { immediate: true })

onBeforeUnmount(() => {
    stopListening()
    clearTimeout(settling)
    clearTimeout(moving)
    clearInterval(waiting)
    unregisterMoves()
})

// How long ago, never the reader's own clock with no label (M19A §4.1).
const updatedAt = computed(() => (poll.lastUpdated.value ? agoFrom(poll.lastUpdated.value.toISOString(), now.value) ?? 'just now' : null))

/* An alert's age, as of now — not as of when the page was drawn. */
const ageOf = (alert) => agoFrom(alert.raised_at, now.value) ?? alert.ago

/*
 * The four figures above the lists, in the order a security question is asked. Red is reserved for an open panic
 * (Hassam's three weights): an open alert pulls the eye in red; nobody on site is a warning, in amber.
 */
const cards = computed(() => [
    { key: 'open_alerts', value: props.counts.open_alerts, label: 'OPEN ALERTS', urgent: props.counts.open_alerts > 0 },
    { key: 'presence', value: props.presence.count, label: 'GUARDS ON SITE', urgent: false, warn: props.presence.count === 0 },
    { key: 'open_incidents', value: props.counts.open_incidents, label: 'OPEN INCIDENTS', urgent: false },
    { key: 'refused_30d', value: props.counts.refused_30d, label: 'REFUSED AT THE GATE · 30 DAYS', urgent: false },
])

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['incident', 'shift'] })

/* The five states (M98 §3.8): loading, and a refused role or a failed read said in words, with nothing of the lists. */
const state = useScreenState()

const retry = () => router.reload()
</script>

<template>
    <Head title="Security" />

    <EstateConsole title="Security" :estate-name="estate.name" active="security">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="5" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Security is not part of your role’s access"
            body="It opens only to roles that hold Security; a committee officer or the estate administrator can grant it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The security page could not be read"
            body="The database did not answer. A panic still reaches the control room and the guards. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
        <!--
          AN OPEN PANIC, FIRST AND LOUDEST: the product's only solid red. What, where, who, since when (counting), and
          whether anyone is going, without a tap. The whole card opens the alert. The live region is the card's wrapper,
          never the link: role="alert" on the link took away its link, and the card is how the alert is opened.
        -->
        <div v-for="alert in openAlerts" :key="alert.id" role="alert">
        <Link :href="alertHref(alert)" class="sx-alert">
            <span class="sx-alert-top">
                <span class="sx-alert-kind">{{ alert.is_drill ? 'DRILL · ' : '' }}{{ alert.kind_label }}</span>
                <span class="sx-alert-lot">{{ alert.lot }}</span>
            </span>
            <span class="sx-alert-who">{{ alert.who }}</span>
            <!-- The count goes up every second: a screen reader is told the time it was raised, not every tick of it. -->
            <span class="sx-alert-line">Raised {{ timeWords(alert.raised_at) }}<span aria-hidden="true"> · open {{ openFor(alert.raised_at) }}</span></span>
            <span class="sx-alert-line sx-alert-going">
                <template v-if="alert.responder">
                    {{ alert.responder.state }}: {{ alert.responder.name }}<template v-if="alert.responder.distance_m !== undefined && alert.responder.distance_m !== null">, {{ distanceWords(alert.responder.distance_m) }} away</template>
                </template>
                <template v-else>{{ alert.state }} · nobody sent yet</template>
            </span>
            <!-- GUARD_TRACKING.md 5.4: where the phone is now, which is not always where it was pressed. -->
            <span v-if="whereLine(alert)" class="sx-alert-line">{{ whereLine(alert) }}</span>
            <span class="sx-alert-open">Open the alert</span>
        </Link>
        </div>

        <!--
          THE LIVE MAP: open alerts where they were raised and where the phone is now, and, where this estate may see
          them, the guards on duty, each pin labelled in words. Beside it the guards on duty, a tap opens their details,
          and for each open alert the three guards nearest it: a suggestion, said as one.
        -->
        <div class="panel sx-live">
            <div class="panel-head">Live map</div>
            <p class="sx-caption" role="status">
                <template v-if="feed.positions">Guards on duty and open alerts, kept live.</template>
                <template v-else>Open alerts and the guards on duty, kept live. Where each guard stands is shown here once Gemini switches it on for this estate.</template>
            </p>

            <div class="sx-grid">
                <div class="sx-map">
                    <SiteMap
                        :pins="mapPins"
                        :lines="mapLines"
                        :fences="mapFences"
                        :boundaries="mapBoundaries"
                        :unplaced="geo?.unplaced ?? []"
                        :height="mapHeight"
                        :label="feed.positions ? 'The estate: guards on duty, open alerts, gates and checkpoints' : 'The estate: open alerts, gates and checkpoints'"
                        @select="onSelect"
                    />
                </div>

                <div class="sx-side">
                    <div class="sx-side-head">Guards on duty · {{ guards.length }}</div>
                    <p v-if="guards.length === 0" class="sec-none">No guard is clocked in at this estate right now.</p>

                    <button
                        v-for="guard in guards"
                        :key="guard.key"
                        type="button"
                        class="sx-guard"
                        :class="{ chosen: selectedKey === guard.key }"
                        :aria-expanded="selectedKey === guard.key"
                        @click="choose(guard.key)"
                    >
                        <span class="sx-guard-name">
                            <span v-if="guard.fresh" class="sx-dot" :class="`sx-dot--${FRESH_TONE[guard.fresh.freshness]}`" aria-hidden="true"></span>
                            {{ guard.name }}
                        </span>
                        <span class="sx-guard-sub">{{ guard.post }} · {{ guard.state_label }}</span>
                        <span v-if="guard.fresh" class="sx-guard-sub" :class="{ warn: guard.fresh.freshness === 'no_signal' }">
                            {{ guard.fresh.label }}<template v-if="guard.toAlert"> · {{ guard.toAlert }}</template>
                        </span>
                    </button>

                    <!-- Who is nearest each open alert, by where they actually are: a suggestion, never an order. -->
                    <div v-for="alert in openAlerts.filter((a) => a.nearest.length > 0)" :key="`near-${alert.id}`" class="sx-nearest">
                        <div class="sx-side-head">Nearest to {{ alert.lot }} · a suggestion</div>
                        <ol>
                            <li v-for="near in alert.nearest" :key="near.key">
                                <button type="button" class="sx-near" @click="choose(near.key)">
                                    {{ near.name }} · {{ distanceWords(near.distance_m) }} · {{ freshnessOf(near).label }}
                                </button>
                            </li>
                        </ol>
                        <p class="sx-note">The control room decides who to send.</p>
                    </div>
                </div>
            </div>

            <!--
              A guard's details: only what the estate may see, opened by a tap on the pin or the row. With positions, where
              they are and whether that is fresh, and nothing more: the officer's consent says the estate's management sees
              "where you are". The battery and why each reading was taken are the employer's tools and stay on the Gemini
              dispatcher's screens (Hassam, 12 Oct: "when a screen shows more than the consent said, the fix is to show less,
              not to say more").
            -->
            <section v-if="selected" class="sx-panel" :aria-label="`${selected.name}, on duty`">
                <div class="sx-panel-head">
                    <strong>{{ selected.name }}</strong>
                    <button type="button" class="sx-close" aria-label="Close these details" title="Close these details" @click="selectedKey = null">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                    </button>
                </div>
                <dl class="sx-facts">
                    <dt>Post</dt>
                    <dd>{{ selected.post }}</dd>
                    <dt>Doing</dt>
                    <dd>{{ selected.state_label }}</dd>
                    <template v-if="feed.positions && selected.fresh">
                        <dt>Position</dt>
                        <!-- "Away from the post" is where the guard is, not a state: the estate sees two states and no others. -->
                        <dd>{{ selected.fresh.label }}<template v-if="selected.accuracy_m !== null"> · accurate to {{ selected.accuracy_m }} m</template><template v-if="selected.off_post"> · away from the post</template></dd>
                        <dt>Distance</dt>
                        <dd>
                            <template v-if="(selected.distances ?? []).length === 0">No open alert</template>
                            <template v-for="(d, i) in selected.distances ?? []" :key="d.alert_id">{{ i > 0 ? ' · ' : '' }}{{ distanceWords(d.metres) }} from {{ openAlerts.find((a) => a.id === d.alert_id)?.lot }}</template>
                        </dd>
                    </template>
                </dl>
            </section>

            <div v-if="unconfirmed.length > 0" class="sec-unconfirmed">
                <strong>Position not confirmed, so not drawn on the map:</strong>
                <ul>
                    <li v-for="item in unconfirmed" :key="item.id">
                        <Link v-if="item.href" :href="item.href">{{ item.title }}</Link>
                        <template v-else>{{ item.title }}</template>: {{ item.why }}
                    </li>
                </ul>
            </div>
        </div>

        <div class="kpi-row">
            <div v-for="card in cards" :key="card.key" class="kpi-card" :class="{ urgent: card.urgent, warn: card.warn }">
                <div class="kpi-value">{{ card.value }}</div>
                <div class="kpi-label">{{ card.label }}</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">Alerts</div>

            <!--
              HOW THIS LIST IS KEPT CURRENT, said rather than implied (B108): live over the estate's alert channel, with
              where an open alert stands re-read every fifteen seconds, or, with the channel down, all of it re-read on
              that clock. The shell's notice says the channel is down as well.
            -->
            <!-- M78 §2: in plain words, never how the screen works. Connecting shows nothing here: the shell's dot says it. -->
            <p class="sec-live" role="status">
                <template v-if="live">Live.</template>
                <template v-else-if="down">Live alerts are down. This list is checked every {{ pollSeconds }} seconds.</template>
                <template v-if="updatedAt"> Last updated {{ updatedAt }}.</template>
            </p>

            <EmptyState
                v-if="alertsTable.total === 0"
                variant="first-use"
                title="No alerts at this estate"
                body="None has been raised."
            />

            <!-- Open ones first and then newest, unless a heading asks otherwise (M32 F5). Each row opens the alert end
                 to end, still read-only (26 Sep, E4: the rows could not be clicked). -->
            <DataTable v-else :columns="ALERT_COLUMNS" :rows="alerts" :table="alertsTable" noun="alerts">
                <template #cell-unit="{ row: alert }">
                    <Link :href="alertHref(alert)" class="sec-lot" :title="`Open this alert: each step of the response, where it was raised and who was told`">
                        {{ alert.unit }}
                    </Link>
                </template>
                <!-- A drill says so first (M10 §1.3): a test of who a panic reaches, not a person. -->
                <template #cell-kind="{ row: alert }"><template v-if="alert.is_drill">DRILL · </template>{{ alert.kind_label }}</template>
                <template #cell-raised="{ row: alert }">{{ alert.at }} · {{ ageOf(alert) }}</template>
                <!-- The state opens the alert too (Batch 1 item 10, 12 Oct), the same door "View" already is. -->
                <template #cell-state="{ row: alert }">
                    <Link :href="alertHref(alert)" class="sec-state" :class="{ open: alert.open }">
                        {{ alert.open ? (alert.acknowledged ? 'Acknowledged' : 'Unacknowledged') : alert.status === 'false_alarm' ? 'False alarm' : 'Resolved' }}
                    </Link>
                </template>
                <!-- "View", not "Open" (seen on screen, 11 Oct): beside a state column, "Open" read as the alert's state. -->
                <template #cell-open="{ row: alert }"><Link :href="alertHref(alert)" class="text-link-sm">View</Link></template>
            </DataTable>
        </div>

        <div class="panel">
            <div class="panel-head">Incidents</div>

            <EmptyState
                v-if="incidentsTable.total === 0"
                variant="first-use"
                title="No incidents logged"
                body="An officer files an incident from the Guard App and it appears here. Nothing has been filed at this estate."
            />

            <DataTable v-else :columns="INCIDENT_COLUMNS" :rows="incidents" :table="incidentsTable" noun="incidents">
                <template #cell-occurred="{ row: incident }">{{ incident.at }}</template>
                <!-- Open this incident (Batch 1 item 10, 12 Oct): the same door as the alerts list's lot column. -->
                <template #cell-kind="{ row: incident }">
                    <Link :href="incidentHref(incident)" class="sec-lot" :title="`Open this incident: what happened, who was on post, and how it was closed`">
                        <span class="sec-title">{{ incident.kind_label }}</span>
                    </Link>
                    <div v-if="incident.detail" class="sec-detail">{{ incident.detail }}</div>
                </template>
                <template #cell-severity="{ row: incident }">{{ SEVERITY[incident.severity] ?? incident.severity }}</template>
                <!-- An open incident is waiting on somebody, in amber: red is the panic's alone. -->
                <template #cell-status="{ row: incident }">
                    <Link :href="incidentHref(incident)" class="sec-state" :class="{ warn: !incident.closed }">
                        {{ incident.status_label }}
                    </Link>
                </template>
            </DataTable>
        </div>

        <div class="panel">
            <div class="panel-head">Gate activity</div>

            <EmptyState
                v-if="gateTable.total === 0"
                variant="first-use"
                title="Nothing through the gate yet"
                body="Every admission and every refusal an officer records on the Guard App appears here, with who was admitted and on what basis."
            />

            <DataTable v-else :columns="GATE_COLUMNS" :rows="gate" :table="gateTable" noun="gate events">
                <template #cell-occurred="{ row: event }">{{ event.at }}</template>
                <template #cell-basis="{ row: event }">{{ event.basis ?? '—' }}</template>
                <!--
                  The server's word for the verdict (B088): "Admitted", "Admitted on override", "Left" or "Refused", and
                  amber for a refusal: a guard doing their job, never red, which is the panic's alone.
                -->
                <template #cell-outcome="{ row: event }">
                    <div class="sec-state" :class="{ warn: event.outcome === 'refused' }">{{ event.label }}</div>
                </template>
            </DataTable>
        </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Authored. No board draws this screen, so there is nothing to reproduce — these
 * rules reuse the tokens, spacing and type sizes the neighbouring Community
 * Admin screens already use, and introduce no colour the palette does not hold.
 */

/* An open panic: the product's only solid, saturated red (Hassam's three weights), white words, the lot the largest. */
.sx-alert {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 14px;
    padding: 16px 18px;
    border-radius: 16px;
    background: var(--red-700);
    color: white;
    text-decoration: none;
    box-shadow: 0 10px 24px rgba(185, 28, 28, 0.28);
}

.sx-alert:focus-visible {
    outline: 3px solid var(--amber-500);
    outline-offset: 2px;
}

.sx-alert-top {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px 14px;
}

.sx-alert-kind {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.sx-alert-lot {
    font-family: 'Poppins', sans-serif;
    font-size: 26px;
    font-weight: 700;
    line-height: 1.1;
}

.sx-alert-who {
    font-size: 16px;
    font-weight: 700;
}

.sx-alert-line {
    font-size: 14px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.sx-alert-going {
    font-size: 15px;
    font-weight: 800;
}

.sx-alert-open {
    align-self: flex-start;
    margin-top: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    background: white;
    color: var(--red-700);
    font-size: 13px;
    font-weight: 800;
}

.sx-caption {
    margin: -4px 0 10px;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

.sx-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(240px, 1fr);
    gap: 14px;
    align-items: start;
}

.sx-map {
    min-width: 0;
}

.sx-side {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.sx-side-head {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: var(--slate-600);
    text-transform: uppercase;
}

.sx-guard {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    background: var(--white);
    text-align: left;
    cursor: pointer;
    font: inherit;
}

.sx-guard:hover,
.sx-guard.chosen {
    border-color: var(--navy-600);
}

.sx-guard:focus-visible,
.sx-near:focus-visible,
.sx-close:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}

.sx-guard-name {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.sx-guard-sub {
    font-size: 12px;
    line-height: 1.45;
    color: var(--slate-600);
    overflow-wrap: anywhere;
}

/* No signal: a warning in amber, near-black words beside it (the dot carries the amber). */
.sx-guard-sub.warn {
    color: var(--navy-900);
    font-weight: 700;
}

.sx-dot {
    flex: 0 0 auto;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--slate-500);
}

.sx-dot--live { background: var(--success-600); }
.sx-dot--lastseen { background: var(--slate-500); }
.sx-dot--nosignal { background: var(--amber-600); }

.sx-nearest {
    margin-top: 6px;
    padding: 10px 12px;
    border: 1px dashed var(--navy-200);
    border-radius: 12px;
}

.sx-nearest ol {
    margin: 6px 0 0;
    padding-left: 18px;
}

.sx-near {
    padding: 2px 0;
    border: 0;
    background: none;
    color: var(--navy-900);
    font: inherit;
    font-size: 12.5px;
    text-align: left;
    cursor: pointer;
}

.sx-note {
    margin: 6px 0 0;
    font-size: 12px;
    color: var(--slate-600);
}

.sx-panel {
    margin-top: 12px;
    padding: 12px 14px;
    border: 1px solid var(--navy-200);
    border-radius: 12px;
    background: var(--navy-100);
}

.sx-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 14px;
    color: var(--navy-900);
}

.sx-close {
    width: 30px;
    height: 30px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--navy-700);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sx-close svg {
    width: 16px;
    height: 16px;
}

.sx-facts {
    display: grid;
    grid-template-columns: 120px minmax(0, 1fr);
    gap: 6px 12px;
    margin: 8px 0 0;
    font-size: 13px;
}

.sx-facts dt {
    font-weight: 700;
    color: var(--slate-600);
}

.sx-facts dd {
    margin: 0;
    color: var(--navy-900);
    overflow-wrap: anywhere;
}

@media (max-width: 900px) {
    .sx-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 480px) {
    .sx-facts {
        grid-template-columns: minmax(0, 1fr);
        gap: 2px;
    }

    .sx-facts dd {
        margin-bottom: 6px;
    }

    .sx-alert-lot {
        font-size: 22px;
    }
}

.kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.kpi-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
}

/* A figure that should pull the eye in red: an open alert, and only that (nobody on site is .kpi-card.warn, amber). */
.kpi-card.urgent {
    border-color: var(--red-700);
}

.kpi-value {
    font-family: 'Poppins', sans-serif;
    font-size: 30px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.1;
}

.kpi-label {
    margin-top: 6px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: var(--slate-500);
}

.panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 6px 18px 14px;
    margin-bottom: 18px;
}

.panel-head {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--navy-900);
    padding: 14px 0 10px;
}

/* The lot, first and unmissable — the client's own instruction. */
.sec-lot {
    flex: 0 0 auto;
    min-width: 92px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 700;
    color: var(--navy-900);
}

.sec-title {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--navy-900);
}

.sec-detail {
    font-size: 12px;
    color: var(--navy-700);
    margin-top: 4px;
    overflow-wrap: anywhere;
}

.sec-state {
    flex: 0 0 auto;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-500);
    white-space: nowrap;
}

.sec-state.open {
    color: var(--red-700);
}

/* Waiting on somebody, or a refusal at the gate: a warning, in amber. Red is the open panic's alone. */
.sec-state.warn {
    color: var(--amber-700);
}

.kpi-card.warn {
    border-color: var(--amber-700);
}

/* How the alert list is kept current — a caption, in the panel's own muted voice. */
.sec-live {
    margin: -4px 0 10px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

.sec-none {
    margin: 0;
    padding: 4px 0 12px;
    font-size: 12.5px;
    color: var(--slate-500);
}

/* A warning about the data, in amber (Hassam, 12 Oct: amber is for warnings): the words stay near-black to read. */
.sec-unconfirmed {
    margin: 10px 0 0;
    padding: 8px 12px;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--navy-900);
    background: var(--amber-100);
    border-left: 3px solid var(--amber-600);
    border-radius: 6px;
}

.sec-unconfirmed ul {
    margin: 4px 0 0;
    padding-left: 18px;
}

.sec-unconfirmed a {
    color: inherit;
    font-weight: 700;
}
</style>

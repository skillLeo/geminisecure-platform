<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import DispatchConnectionBar from '../../../Components/DispatchConnectionBar.vue'
import PanicAlarmControls from '../../../Components/PanicAlarmControls.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import SiteMap from '../../../Components/SiteMap.vue'
import { guardFreshness } from '../../../lib/guardFreshness.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'
import { register as registerLive } from '../../../live/store.js'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Dispatch live map — board screen super-admin-12.
 *
 * The board draws this map BY HAND: absolutely positioned <div>s on a CSS grid
 * background, with the region boxes, the site cards and the pins each carrying
 * their own inline left/top. There is no map library anywhere in it, and none
 * is introduced here — leaflet is installed, and substituting a tile layer for
 * markup the designer drew would replace the design rather than build it.
 *
 * So the inline positions below are the board's own, reproduced verbatim, with
 * real estates, real shifts and real alerts placed into them. What each BOARD pin
 * means is the post the guard is standing, which the label says outright; it is
 * not a coordinate.
 *
 * The Map view beside it is the real map (M11 §3, §6): posts and geofences where
 * they were placed, open alerts where they were raised, and each officer on duty
 * who shares their position where their newest fix put them — with its age.
 */
const props = defineProps({
    /** Dispatch's own sections. */
    sections: { type: Array, required: true },
    /** The one alert being asked for right now, or null on a quiet platform. */
    banner: { type: Object, default: null },
    kpis: { type: Array, required: true },
    /** Parish boxes: the board's geometry, this platform's parishes. */
    regions: { type: Array, required: true },
    /**
     * Site cards and the pins around each of them.
     *
     * A card's line is `plan · staffing`, joined here from what arrived. `plan`
     * is the client's subscription tier and reaches only a role holding Billing &
     * subscriptions (M10 §5); for anyone else the key is absent and the line is
     * the staffing alone — "4 guards", not a tier with its name blanked.
     */
    sites: { type: Array, required: true },
    legend: { type: Array, required: true },
    onDuty: { type: Array, required: true },
    /** The estates whose alert channel this screen may listen on. */
    estateIds: { type: Array, required: true },
    /** Whether this viewer's role narrows the platform to assigned sites. */
    scoped: { type: Boolean, default: false },
    /**
     * Whether the device-originated data on this screen came from a real
     * handset or from the simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
    /** What has a real position (M11 §6): placed posts, open alerts with a position, what is not placed. */
    geo: { type: Object, default: null },
    /**
     * Every guard clocked in, in full (Hassam, 12 October): the security company's own view of its staff on duty, one
     * row per shift (`LiveMap::roster()`). The estate's Security map shows a deliberately shorter list of the same people.
     */
    roster: { type: Array, default: () => [] },
})

/**
 * THE POLL IS THE GUARANTEE. THE SOCKET IS THE SPEED.
 *
 * One private channel per estate on screen and no others, running ALONGSIDE the
 * poll rather than instead of it: on a screen where a missed panic is a person
 * waiting, quiet-because-nothing-happened and quiet-because-the-socket-dropped
 * must never look the same.
 *
 * Five seconds is the promise this screen's pill makes whenever the socket is
 * down, and it polls only then (13 C2). With the channel up nothing polls: the
 * push re-reads the map, and a socket that dies is caught by its own ping
 * within forty seconds, at which point the poll starts and the bar turns amber.
 */
const { poll, streaming, connection, panic } = useLiveDispatch({
    only: ['banner', 'kpis', 'regions', 'sites', 'onDuty', 'geo', 'roster'],
    intervalMs: 5000,
    estateIds: props.estateIds,
})

/*
 * A ticking clock, so "how long since the last refresh" is a live figure rather
 * than one frozen at mount. Without it a poll that silently stopped would leave
 * the pill claiming the screen was live indefinitely. The guard pins' ages read
 * it too.
 */
const tick = ref(Date.now())
let ticker = null

onMounted(() => {
    ticker = setInterval(() => {
        tick.value = Date.now()
    }, 1000)
})

onBeforeUnmount(() => clearInterval(ticker))

/* ---- the map (M11 §6) ---- */

const view = ref('map')

/*
 * WHERE EACH OFFICER ON DUTY IS, AND HOW FRESH THAT IS (M11 §3.4; GUARD_TRACKING.md 5.5). One rule for both consoles,
 * Security's (`lib/guardFreshness.js`), never a second copy here: Live under a minute; Last seen with the age and the
 * real time; No signal once nothing has arrived for longer than the estate's tolerance; Waiting for the first position;
 * Position not shared. Worked out on the ticking clock, so a pin and its card go from Live to Last seen to No signal
 * without a reload. `GuardPins` sends every officer on duty (`geo.officers`), keyed by the shift as the roster is, and a
 * pin for each one with a position (`geo.guards`); a guard with no pin says why on their card.
 */
const rosterByKey = computed(() => new Map(props.roster.map((row) => [row.key, row])))
const officerByKey = computed(() => new Map((props.geo?.officers ?? []).map((officer) => [officer.key, officer])))

/** A moment on the row's own clock, "9:38 PM JA": its estate's, or the viewer's in My time (the server says which). */
const timeWords = (clock) => (iso) => {
    const at = new Date(iso)

    try {
        return `${new Intl.DateTimeFormat('en-US', { timeZone: clock?.zone ?? undefined, hour: 'numeric', minute: '2-digit' }).format(at)}${clock?.tag ? ` ${clock.tag}` : ''}`
    } catch {
        return new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' }).format(at)
    }
}

/** The shared rule, on this page's clock, for one officer. */
const freshnessOf = (officer) => guardFreshness(officer, tick.value, timeWords(rosterByKey.value.get(officer.key)?.clock))

/** The pin's tone by its freshness (SiteMap's own: green live, then last seen, then no signal). */
const PIN_TONE = { live: 'live', last_seen: 'lastseen', no_signal: 'nosignal' }

const guardPins = computed(() => (props.geo?.guards ?? []).map((g) => {
    const fresh = freshnessOf(g)

    return {
        ...g,
        tone: PIN_TONE[fresh.freshness] ?? 'lastseen',
        detail: [g.detail, fresh.label, g.accuracy_m ? `within ${g.accuracy_m} m` : null].filter(Boolean).join(' · '),
    }
}))

/*
 * WHAT THE DISPATCHER'S CARD ADDS FROM THE PHONE: the same freshness as the pin, what the guard is doing (at post, on
 * patrol, on break, responding to an alert, which it links), and what the phone itself reported with its newest
 * reading: how close the fix is, what took it, moving or still, the battery (amber below 20 %, when the phone slows its
 * readings), away from the post, and a phone clock out by more than two minutes. Amber is a warning; red stays the open
 * panic's alone.
 */
const whereabouts = (row) => {
    const officer = officerByKey.value.get(row.key)

    if (!officer) {
        return null
    }

    const fresh = freshnessOf(officer)
    const placed = officer.lat !== undefined && officer.lat !== null

    return {
        freshness: fresh.freshness,
        label: fresh.label,
        state: officer.state_label ?? null,
        respondingHref: officer.responding_alert_id ? `/dispatch/alerts/${officer.responding_alert_id}` : null,
        phone: placed
            ? [
                  officer.accuracy_m ? `Within ${officer.accuracy_m} m` : null,
                  officer.source_label ?? null,
                  officer.is_moving === true ? 'Moving' : officer.is_moving === false ? 'Not moving' : null,
              ].filter(Boolean).join(' · ')
            : '',
        battery: placed && officer.battery_pct !== null && officer.battery_pct !== undefined ? officer.battery_pct : null,
        offPost: placed && officer.off_post === true,
        clockFlag: officer.clock_flag === 'clock_ahead' ? 'The phone\'s clock is ahead of the server\'s' : officer.clock_flag === 'clock_behind' ? 'The phone\'s clock is behind the server\'s' : null,
    }
}

/** The roster with each guard's whereabouts, worked out once a tick rather than once a line. */
const cards = computed(() => props.roster.map((row) => ({ ...row, where: whereabouts(row) })))

const geoPins = computed(() => [
    ...(props.geo?.alerts ?? []).map((a) => ({ ...a, tone: 'danger' })),
    ...guardPins.value,
    ...(props.geo?.posts ?? []).map((p) => ({ id: p.id, lat: p.lat, lng: p.lng, title: p.title, detail: p.estate, tone: 'neutral' })),
])

/*
 * THE PINS MOVE OVER THE LIVE CHANNEL, NOT A POLL (M11 §3.4). `guard.moved` is a reference — which officer — and
 * the map reads its `geo` again (one read for a burst of them), through this viewer's scope. Caught up after a
 * drop like every other event, so a move made while the socket was down lands.
 */
let moveTimer = null

const unregisterMoves = registerLive({
    page: true,
    signals: false,
    channels: { has: (name) => name === 'gemini.dispatch' || /^gemini\.[^.]+\.dispatch$/.test(name) },
    accepts: (env) => env.e === 'guard',
    apply() {
        clearTimeout(moveTimer)
        moveTimer = setTimeout(() => {
            moveTimer = null
            router.reload({ only: ['geo', 'roster'], preserveScroll: true, preserveState: true })
        }, 300)
    },
    resync: () => router.reload({ only: ['geo', 'roster'], preserveScroll: true, preserveState: true }),
})

onBeforeUnmount(() => {
    clearTimeout(moveTimer)
    unregisterMoves()
})

// A clock-in or clock-out anywhere moves the on-duty panel, the site cards, the pins and the roster (M11 §2, D-175).
useLiveReload({ entities: ['shift'], only: ['kpis', 'sites', 'onDuty', 'geo', 'roster'] })

const geoFences = computed(() => (props.geo?.posts ?? []).map((p) => ({ id: `fence-${p.id}`, lat: p.lat, lng: p.lng, radiusM: p.radius_m, label: `${p.title} · ${p.estate}` })))

const openPin = (pin) => {
    if (pin?.href) {
        router.visit(pin.href)
    }
}

/** Four missed intervals. One late response is a slow request; four is a fault. */
const secondsSinceRefresh = computed(() => Math.round((tick.value - poll.lastUpdated.value.getTime()) / 1000))

/*
 * STALE ONLY MEANS SOMETHING WHILE POLLING. With the channel live nothing is
 * due on a clock — a quiet night is a map that does not change — so staleness
 * is measured against the fallback rate, and only in fallback.
 */
const stale = computed(() => !streaming.value && secondsSinceRefresh.value >= (poll.currentIntervalMs.value / 1000) * 4)

/**
 * What the status pill says, and it never says "live" when it is not.
 *
 * The board's wording is the healthy case and it is literally true: this screen
 * re-asks the server every five seconds whether or not the socket is up, so the
 * promise holds on the poll alone. The socket only makes it faster, which is
 * why losing it changes the hover text rather than the headline — and why the
 * poll falling behind changes the headline outright.
 */
const liveLabel = computed(() => {
    if (stale.value) {
        return `Stale · last update ${secondsSinceRefresh.value}s ago`
    }

    // The board's own wording is the socket-down case, and it stays literally
    // true: with no channel this screen really does re-ask every five seconds.
    return streaming.value ? 'Live · alerts push instantly' : 'Live · updates every 5s'
})

const liveReason = computed(() => {
    if (stale.value) {
        return `The refresh has not completed for ${secondsSinceRefresh.value} seconds. Click to retry now.`
    }

    return streaming.value
        ? 'Alerts and clock-ins arrive instantly over the live channel, and nothing is polling. If the channel '
          + 'drops, this screen falls back to refreshing every five seconds within forty seconds. Click to refresh now.'
        : 'The live alert channel is down, so this screen is refreshing every five seconds until it returns. '
          + 'Click to refresh now.'
})

const state = useScreenState({
    rows: () => props.sites.length,
    filtered: () => props.scoped,
})
</script>

<template>
    <Head title="Dispatch: live map" />

    <GeminiConsole title="Dispatch: live map">
            <template #byline>
                <SourceBadge v-bind="sourceBadge" />
                <DispatchConnectionBar :connection="connection" @refresh="poll.refresh()" />
                <!-- The siren sounds here too, so it can be quietened here (B107). -->
                <PanicAlarmControls :alarm="panic" />
            </template>

        <template #actions>
            <!--
              The board draws this as a <div>. It is a real button because it
              is the one control on the screen that answers "is this thing
              still working" — and the honest answer to that question has to be
              actionable, not decorative.
            -->
            <!--
              Drawn only when the map is stale (seen on screen, 11 Oct): the connection bar beside the title already says
              live or fallback and refreshes on a press, and this pill saying "Live" beside a bar saying "Fallback" was a
              timer that looked live, and crowded the shell's own line into a column over the tabs.
            -->
            <button v-if="stale" type="button" class="btn-outline-sm" :title="liveReason" @click="poll.refresh()">
                <svg viewBox="0 0 24 24" fill="none">
                    <!-- Amber, not red (Hassam, 11 Oct): red is the panic's alone, and this dot only says the screen is live. -->
                    <circle cx="6" cy="6" r="4" :style="{ fill: stale ? 'var(--slate-500)' : 'var(--amber-700)' }" />
                </svg>
                <span>{{ liveLabel }}</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="9" :columns="4" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The map could not be drawn"
            body="The roster and the alert queue could not be read just now. Nothing has been lost: alerts are still being received and are still in the queue. This screen retries every five seconds."
            action-label="Retry now"
            @action="poll.refresh()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Dispatch is not yours to watch"
            body="The live map is limited to roles holding dispatch access. Your role can be granted it from the role access matrix by a platform administrator."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No sites in your assignment"
            body="Your role watches assigned sites rather than the whole platform, and none of the estates assigned to you is provisioned yet. Other sites may be live; they are simply not yours to see."
            action-label="Open the alerts queue"
            @action="router.visit('/dispatch/alerts')"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No sites are being monitored yet"
            body="An estate appears on this map from the moment it is provisioned, with a card for the site and a pin for every guard standing a post there. Provision the first client and it will be here."
            action-label="Go to clients"
            @action="router.visit('/clients')"
        />

        <template v-else>
            <!--
              The banner IS the alert. Drawn only when one is open, because a
              red interruption bar that is permanently on screen has stopped
              interrupting anybody.
            -->
            <div v-if="banner" class="live-banner">
                <div class="lb-dot"></div>
                <div>
                    <div class="lb1">{{ banner.title }}</div>
                    <div class="lb2">{{ banner.meta }}</div>
                </div>
                <Link :href="banner.href" class="lb-btn">Open alert</Link>
            </div>

            <div class="kpi-row">
                <div v-for="kpi in kpis" :key="kpi.label" class="kpi-card">
                    <div class="k-top">
                        <div class="kpi-icon" :class="kpi.tone">
                            <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.value }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <!--
              TWO VIEWS OF ONE PICTURE (M11 §6). The map is OpenStreetMap: every post that has been placed, its
              geofence, and every open alert raised with a position — where they actually are. The board is the
              designer's schematic: every site, whether or not anything on it has been placed, at a glance. What
              has no position is named under the map rather than quietly left off.
            -->
            <div class="map-views" role="tablist" aria-label="Map view">
                <button type="button" role="tab" class="subnav-item" :class="{ active: view === 'map' }" :aria-selected="view === 'map'" @click="view = 'map'">Map</button>
                <button type="button" role="tab" class="subnav-item" :class="{ active: view === 'board' }" :aria-selected="view === 'board'" @click="view = 'board'">Board</button>
            </div>

            <div v-if="view === 'map'" class="geo-panel">
                <SiteMap
                    :pins="geoPins"
                    :fences="geoFences"
                    :boundaries="geo?.boundaries ?? []"
                    :unplaced="geo?.unplaced ?? []"
                    :height="520"
                    label="Every placed post and its geofence, every officer on duty who shares their position, and every open alert with a position"
                    @select="openPin"
                />
                <!--
                  POSITION NOT CONFIRMED (Hassam, 12 Oct): a gate or an alert whose position lies outside its estate's
                  boundary is named here and never plotted, so one bad coordinate cannot stretch the map across
                  continents. An alert keeps its link; nothing is corrected.
                -->
                <div v-if="(geo?.unconfirmed ?? []).length > 0" class="geo-unconfirmed">
                    <strong>Position not confirmed, so not drawn on the map:</strong>
                    <ul>
                        <li v-for="item in geo.unconfirmed" :key="item.id">
                            <Link v-if="item.href" :href="item.href">{{ item.title }}</Link>
                            <template v-else>{{ item.title }}</template>: {{ item.why }}
                        </li>
                    </ul>
                </div>
                <!-- A standing fact about the service, so a line that stays; a guard with no pin says why on their card. -->
                <p v-if="geo && geo.tracking === false" class="geo-unshared">On-duty location sharing is switched off for this service, so no officer is drawn on the map.</p>

                <!--
                  THE DISPATCHER'S ROSTER (Hassam, 12 October): every guard clocked in, in full, as the security company
                  sees its own staff: who, staff number and phone, where they are and how fresh that is, what they are
                  doing, what the phone reported, the post and the shift, how they clocked in, the licence, what a
                  supervisor must look at, an alert they raised. The estate's Security map shows a deliberately shorter
                  list of the same people. A warning reads amber; red is only an open panic.
                -->
                <section class="duty-roster" aria-label="Guards on duty">
                    <h3 class="dr-head">On duty now <span class="dr-count">{{ roster.length }}</span></h3>
                    <p v-if="roster.length === 0" class="dr-empty">Nobody is clocked in on the estates this map shows.</p>
                    <div v-else class="dr-grid">
                        <article v-for="guard in cards" :key="guard.key" class="dr-card">
                            <div class="dr-top">
                                <Link :href="guard.href" class="dr-name">{{ guard.name }}</Link>
                                <span v-if="guard.employee_number" class="dr-staff">{{ guard.employee_number }}</span>
                                <span v-if="guard.simulated" class="dr-tag" title="Clocked on by the simulator, not a real handset.">Simulated</span>
                            </div>
                            <div class="dr-line dr-where">
                                {{ [guard.post, guard.estate].filter(Boolean).join(' · ') }}<template v-if="guard.patrol && !guard.where"> · On patrol</template>
                            </div>
                            <template v-if="guard.where">
                                <div class="dr-line dr-fresh" :class="`dr-fresh--${guard.where.freshness}`">
                                    <span class="dr-fresh-dot" aria-hidden="true"></span>{{ guard.where.label }}
                                </div>
                                <div v-if="guard.where.state" class="dr-line">
                                    <Link v-if="guard.where.respondingHref" :href="guard.where.respondingHref" class="dr-link">{{ guard.where.state }}</Link>
                                    <template v-else>{{ guard.where.state }}</template>
                                </div>
                                <div v-if="guard.where.phone" class="dr-line">{{ guard.where.phone }}</div>
                                <div v-if="guard.where.battery !== null" class="dr-line" :class="{ warn: guard.where.battery < 20 }">Battery {{ guard.where.battery }} %</div>
                                <div v-if="guard.where.offPost" class="dr-line warn">Away from the post</div>
                                <div v-if="guard.where.clockFlag" class="dr-line warn">{{ guard.where.clockFlag }}</div>
                            </template>
                            <div class="dr-line">{{ guard.shift }}</div>
                            <div class="dr-line">{{ guard.clocked_in }}</div>
                            <div v-if="guard.clock_in_check" class="dr-line" :class="{ warn: guard.clock_in_check.tone === 'warn' }">{{ guard.clock_in_check.label }}</div>
                            <div class="dr-line" :class="{ warn: guard.licence.tone === 'warn' }">{{ guard.licence.label }}</div>
                            <div v-if="guard.review" class="dr-line warn">{{ guard.review }}</div>
                            <div v-if="guard.phone" class="dr-line">
                                <a :href="`tel:${guard.phone.replace(/[^\d+]/g, '')}`" class="dr-phone" :title="`Call ${guard.name}.`">{{ guard.phone }}</a>
                            </div>
                            <Link v-if="guard.alert" :href="guard.alert.href" class="dr-alert">{{ guard.alert.label }}</Link>
                        </article>
                    </div>
                </section>
            </div>

            <div v-show="view === 'board'" class="map-panel">
                <div class="map-canvas">
                    <template v-for="region in regions" :key="region.key">
                        <div
                            class="map-region"
                            :style="`left:${region.box.left}px;top:${region.box.top}px;width:${region.box.width}px;height:${region.box.height}px;`"
                        ></div>
                        <div
                            class="map-region-label"
                            :style="`left:${region.labelAt.left}px;top:${region.labelAt.top}px;`"
                        >
                            {{ region.label }}
                        </div>
                    </template>

                    <template v-for="site in sites" :key="site.key">
                        <!--
                          The board draws the site card as a <div>, and so does
                          this: opening a client from a dispatch map is not what
                          the card is for, and the pins beside it are the things
                          a dispatcher reaches for. The guard rows in the side
                          panel are the links.
                        -->
                        <div
                            class="site-cluster"
                            :style="`left:${site.cluster.left}px;top:${site.cluster.top}px;`"
                        >
                            <div class="sc-name">{{ site.name }}</div>
                            <div class="sc-tier">{{ [site.plan, site.staffing].filter(Boolean).join(' · ') }}</div>
                        </div>

                        <div
                            v-for="pin in site.pins"
                            :key="pin.key"
                            class="pin"
                            :style="`left:${pin.left}px;top:${pin.top}px;`"
                        >
                            <div class="pin-dot" :class="pin.dot"></div>
                            <div :class="pin.labelClass" :style="pin.labelStyle">{{ pin.label }}</div>
                        </div>
                    </template>
                </div>

                <div>
                    <div class="map-legend">
                        <h4>Status</h4>
                        <div v-for="row in legend" :key="row.label" class="legend-row">
                            <div class="legend-dot" :style="`background:${row.colour};`"></div>
                            {{ row.label }}
                        </div>
                    </div>

                    <!--
                      Every guard on duty, whether or not a pin could be drawn
                      for them. The canvas has as many slots as the board drew;
                      this list has no limit, so nobody standing a post can be
                      missing from the screen because the picture ran out of
                      room.
                    -->
                    <div class="map-side-panel">
                        <h4>On duty now</h4>
                        <Link v-for="row in onDuty" :key="row.key" :href="row.href" class="msp-row">
                            <div class="msp-dot" :style="`background:${row.colour};`"></div>
                            <div class="msp-txt">
                                <div class="mt1">{{ row.name }}</div>
                                <div class="mt2">{{ row.where }}</div>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the section tabs, the status pill and the "on duty" rows as
 * <div>s, which carry no chrome of their own. Here they are a <button>, a
 * <button> and an <a>, so that they can be operated — and a <button> arrives
 * with the system UI font and a pointer that says "not a control". Nothing
 * below introduces a colour, a size or a spacing the board does not declare.
 */
.subnav-item,
.btn-outline-sm {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

/*
 * Written as :not(.active) deliberately.
 *
 * A plain `.subnav-item { background: none }` carries the same specificity as
 * the board's own `.subnav-item.active`, so which one won would depend on the
 * order the bundler happened to emit them in — and on the build where this file
 * landed second, the active tab's white pill would vanish. Only the inactive
 * tabs need the button face removed, so only they are named.
 */
.subnav-item:not(.active) {
    background: none;
}

.subnav-item[disabled] {
    cursor: not-allowed;
}

/* The board sets .btn-outline-sm's own border and background; only the UA's
 * border needed removing above, and this puts the board's back. Stated here
 * rather than left to specificity luck for the same reason as the tabs. */
.btn-outline-sm {
    border: 1.5px solid var(--navy-200);
}

/* The two views (M11 §6): the board's own tab style, on buttons. */
.map-views {
    display: flex;
    gap: 4px;
    margin: 4px 0 12px;
}

.map-views button {
    font: inherit;
    background: none;
    border: 0;
    cursor: pointer;
}

.geo-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 12px;
    margin-bottom: 16px;
}

.geo-unshared {
    margin: 10px 2px 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
}

/* A warning about the data, in amber (Hassam, 12 Oct: amber is for warnings): the words stay near-black to read. */
.geo-unconfirmed {
    margin: 10px 2px 0;
    padding: 8px 12px;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--navy-900);
    background: var(--amber-100);
    border-left: 3px solid var(--amber-600);
    border-radius: 6px;
}

.geo-unconfirmed ul {
    margin: 4px 0 0;
    padding-left: 18px;
}

.geo-unconfirmed a {
    color: inherit;
    font-weight: 700;
}

/*
 * THE DISPATCHER'S ROSTER (Hassam, 12 October). Authored: the board draws no roster under its map. A card per guard on
 * duty, as many to a row as fit, one to a row on a phone. A warning is amber (the three weights); the one red is an open
 * panic the guard raised, which is the panic itself.
 */
.duty-roster {
    margin: 14px 2px 0;
}

.dr-head {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.dr-count {
    margin-left: 4px;
    padding: 1px 8px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--slate-600);
    font-size: 11px;
}

.dr-empty {
    margin: 0;
    font-size: 12.5px;
    color: var(--slate-600);
}

.dr-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 10px;
}

.dr-card {
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 10px 12px;
    background: var(--white);
    min-width: 0;
}

.dr-top {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 4px;
}

.dr-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
    text-decoration: none;
}

.dr-staff {
    font-size: 11px;
    color: var(--slate-500);
}

.dr-tag {
    font-size: 10.5px;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--navy-100);
    color: var(--slate-600);
}

.dr-line {
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-600);
    overflow-wrap: anywhere;
}

.dr-where {
    color: var(--navy-800);
    font-weight: 600;
}

.dr-line.warn {
    color: var(--amber-700);
}

.dr-phone {
    color: var(--navy-700);
    font-weight: 600;
}

.dr-alert {
    display: inline-block;
    margin-top: 4px;
    font-size: 12px;
    font-weight: 700;
    color: var(--red-700);
}

/*
 * Where the guard is and how fresh that is, in the shared rule's words, with a dot in the colour SiteMap gives the pin:
 * green live, grey last seen, amber no signal (a warning), grey for waiting and not shared. Never red.
 */
.dr-fresh {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}

.dr-fresh-dot {
    flex: none;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--slate-300);
}

.dr-fresh--live {
    color: var(--success-700);
}

.dr-fresh--live .dr-fresh-dot {
    background: var(--success-600);
}

.dr-fresh--last_seen .dr-fresh-dot {
    background: var(--slate-500);
}

.dr-fresh--no_signal {
    color: var(--amber-700);
}

.dr-fresh--no_signal .dr-fresh-dot {
    background: var(--amber-600);
}

.dr-link {
    color: var(--navy-700);
    font-weight: 600;
}
</style>

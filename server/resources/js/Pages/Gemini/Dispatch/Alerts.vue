<script setup>
import { computed, onBeforeUnmount } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { register as registerLive } from '../../../live/store.js'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import DispatchConnectionBar from '../../../Components/DispatchConnectionBar.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import PanicAlarmControls from '../../../Components/PanicAlarmControls.vue'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Active alerts queue — board screen super-admin-14.
 *
 * DOM and class names are the board's. The inline style on the rows' container
 * is the board's too, kept inline rather than promoted to a class, because the
 * board's stylesheet defines none for it and inventing one would be authoring
 * CSS the design does not have.
 *
 * The board draws each row and each tab as a <div>. Here they are links and
 * buttons, because a queue whose rows cannot be opened is a picture of a queue.
 */
const props = defineProps({
    /** Dispatch's own sections. Only Alerts is built; the rest say so. */
    sections: { type: Array, required: true },
    /** The queue's filter tabs. Filtering happens in SQL, not here. */
    tabs: { type: Array, required: true },
    tab: { type: String, required: true },
    alerts: { type: Array, required: true },
    /** The estates whose alert channel this screen may listen on. */
    estateIds: { type: Array, default: () => [] },
    /**
     * Guards on duty whose phone has gone quiet (GuardSignal, 12 Oct 2026): `{ id, shift_id, sentence, raised_at, href }`,
     * "No signal from <name> at <site>, last seen HH:MM, battery 14%" (the battery is the employer's: never on an estate's
     * screen). Cleared by the server when a position arrives.
     */
    noSignal: { type: Array, default: () => [] },
    /**
     * Whether the alerts in this queue came from real handsets or from the
     * simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
})

/**
 * A dispatcher must never need to refresh to see a panic.
 *
 * THIS SCREEN WAS THE LAST ONE IN DISPATCH WITHOUT A SOCKET, which was the
 * wrong way round: the live map and the alertness board both pushed, and the one
 * screen whose entire job is to show a panic the moment it arrives sat on a
 * three-second poll it inherited from before Reverb was installed (D-027, since
 * superseded by D-032).
 *
 * It pushes, and polls every three seconds ONLY while the channel is down
 * (13 C2) — the bar beside the source badge says which, so a dispatcher knows
 * whether a panic reaches them instantly or up to three seconds late.
 *
 * Only `alerts` is re-fetched, so the scroll position and the open tab survive —
 * the tab because it lives in the URL, which the partial reload replays.
 */
const { poll, connection, panic } = useLiveDispatch({
    only: ['alerts', 'noSignal'],
    intervalMs: 3000,
    estateIds: props.estateIds,
})

// A No signal alert raised or cleared (`guard.signal`, on the dispatch feed): the queue reads itself again.
const unregisterSignal = registerLive({
    page: true,
    signals: false,
    channels: { has: (name) => name === 'gemini.dispatch' || /^gemini\.[^.]+\.dispatch$/.test(name) },
    accepts: (env) => env.t === 'guard.signal',
    apply: () => poll.refresh(),
    resync: () => poll.refresh(),
})

onBeforeUnmount(() => unregisterSignal())

const filtered = computed(() => props.tab !== 'all')

/* The five states (M98 §3.8): a refused role and a failed read are said in words, with nothing of the queue. */
const state = useScreenState({ rows: () => props.alerts.length, filtered: () => filtered.value })

/*
 * The empty queue's words. A No signal alert above it is outstanding, so under one it never says nothing is.
 */
const emptyQueue = computed(() => props.noSignal.length > 0
    ? {
        title: 'No panic or duress alerts',
        body: 'Panic and duress alerts appear here the moment a device raises one, ahead of everything else in the queue. Closed alerts move to the Resolved tab rather than disappearing.',
    }
    : {
        title: 'No active alerts',
        body: 'Panic and duress alerts appear here the moment a device raises one, ahead of everything else in the queue. An empty queue means nothing is outstanding. Closed alerts move to the Resolved tab rather than disappearing.',
    })

const retry = () => router.reload()
</script>

<template>
    <Head title="Dispatch: active alerts" />

    <GeminiConsole title="Dispatch: active alerts">
            <template #byline>
                <SourceBadge v-bind="sourceBadge" />
                <DispatchConnectionBar :connection="connection" @refresh="poll.refresh()" />

                <!-- The siren's controls — the same on every screen that sounds it (B107). -->
                <PanicAlarmControls :alarm="panic" />
            </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <!--
                  Every dispatch section has a screen now, and `sectionTabs()`
                  sends an href for all four — the inert branch that stood here
                  for an unbuilt live map could no longer be reached by data.
                -->
                <Link :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
            </template>
        </div>

        <div class="subnav">
            <Link
                v-for="filter in tabs"
                :key="filter.label"
                :href="filter.href"
                class="subnav-item"
                :class="{ active: filter.active }"
            >
                {{ filter.label }}
            </Link>
        </div>

        <!--
          NO SIGNAL, on every tab (12 Oct 2026): a guard on duty whose phone has sent nothing for longer than their
          estate's tolerance. An operational problem, so it is in the queue, in amber (a warning, never the panic's
          red), and it goes by itself when a position arrives.
        -->
        <section v-if="noSignal.length > 0" id="no-signal" class="ns-panel" aria-label="Guards with no signal">
            <h2 class="ns-head">No signal</h2>
            <div v-for="item in noSignal" :id="`no-signal-${item.shift_id}`" :key="item.id" class="ns-row">
                <span class="ns-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 3.5 22 20H2z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" /><path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" /></svg>
                </span>
                <span class="ns-text">{{ item.sentence }}</span>
                <Link :href="item.href" class="ns-link">Open the guard</Link>
            </div>
        </section>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Dispatch is not part of your role’s access"
            body="The alert queue shows every client’s panics and incidents, so it opens only to the control room. A Super Admin can grant it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The alert queue could not be read"
            body="The platform database did not answer. A panic is still delivered to the guards’ phones and pushed to this console when it comes back. Reading it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="alerts.length === 0 && filtered"
            variant="filtered"
            title="Nothing in this tab"
            body="Alerts exist, but none of them match this filter right now. The queue is ordered by what is being asked for rather than by when it arrived, so an empty tab here does not mean an empty queue."
            action-label="Show all alerts"
            @action="router.visit('/dispatch/alerts')"
        />

        <EmptyState
            v-else-if="alerts.length === 0"
            variant="first-use"
            :title="emptyQueue.title"
            :body="emptyQueue.body"
        />

        <!-- The board's own inline style, verbatim: its stylesheet defines no
             class for the rows' container. -->
        <div v-else style="background:var(--white);border:1px solid var(--navy-100);border-radius:16px;overflow:hidden;">
            <Link
                v-for="alert in alerts"
                :key="alert.key"
                :href="alert.href"
                class="alert-row"
                :class="{ urgent: alert.urgent }"
            >
                <div class="alert-icon" :class="{ urgent: alert.urgent }">
                    <BoardIcon :name="alert.icon" :stroke="alert.icon_stroke" />
                </div>
                <div class="alert-txt">
                    <div class="al1">
                        {{ alert.title }}
                        <span :class="alert.tag_class">{{ alert.tag }}</span>
                    </div>
                    <!--
                      Offline capture, a disagreeing device clock and simulated
                      origin all appear in this line. Each changes how far the
                      timestamp beside it can be trusted, and none of them is
                      quietly reconciled away.
                    -->
                    <div class="al2">{{ alert.meta }}</div>
                </div>
                <!-- Red is reserved for an open panic (Hassam, 11 Oct): any other row nobody has answered is amber. -->
                <div class="alert-status" :class="[alert.status_class, { 'not-urgent': !alert.urgent }]">{{ alert.status }}</div>
            </Link>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule below REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the tabs and the rows as <div>s, which carry no default
 * chrome. They are a <button> and an <a> here so they can be operated, and the
 * browser's own styling for those elements — an anchor's underline, a button's
 * border, background and system font — would otherwise show through and change
 * the pixels the board specifies.
 */
.subnav-item,
.alert-row {
    text-decoration: none;
}

/* The row's children each set their own colour; this only stops an anchor's
 * default link blue reaching anything they miss. */
.alert-row {
    color: inherit;
}

.subnav-item {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

/*
 * Written as :not(.active) on purpose.
 *
 * A plain `.subnav-item { background: none }` would carry the same specificity
 * as the board's `.subnav-item.active`, and which of the two won would then
 * depend on the order the bundler happened to emit them in — the active tab's
 * white pill would vanish on a build where this file landed second. The
 * non-active tabs are the only ones that need the button face removed, so this
 * says so and never touches the active rule.
 */
.subnav-item:not(.active) {
    background: none;
}

/* Matches the shell's treatment of a control that is deliberately not ready.
 * Changes the cursor and nothing that occupies space. */
.subnav-item[disabled] {
    cursor: not-allowed;
}

/*
 * RED IS RESERVED FOR PANIC AND LIFE SAFETY (Hassam, 11 Oct: "If red means two things, it means nothing"). The board
 * drew every unanswered row's status in solid red, an incident and a lapsed licence as loudly as a panic, and the
 * Resident tag in red on every row a resident raised. An open panic keeps its red row and its red status; the rest
 * are amber. Written with the row's class in front so it outranks the board's own sheet.
 */
.alert-row .alert-status.unack.not-urgent {
    background: var(--amber-100);
    color: var(--amber-700);
}

.alert-txt .source-tag.resident {
    background: var(--amber-100);
    color: var(--amber-700);
}

/* No signal: a warning, amber with near-black words (Hassam's three weights); the panic's red is not for it. */
.ns-panel {
    margin: 0 0 14px;
    padding: 10px 16px 12px;
    background: var(--amber-100);
    border: 1px solid var(--amber-500);
    border-radius: 16px;
}

.ns-head {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.ns-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 6px 0;
    font-size: 13px;
    line-height: 1.45;
    color: var(--navy-900);
}

.ns-row + .ns-row {
    border-top: 1px solid var(--amber-500);
}

.ns-mark {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    color: var(--amber-700);
}

.ns-mark svg {
    width: 18px;
    height: 18px;
    display: block;
}

.ns-text {
    flex: 1 1 auto;
    min-width: 0;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.ns-link {
    flex: 0 0 auto;
    font-weight: 700;
    color: var(--navy-700);
}
</style>

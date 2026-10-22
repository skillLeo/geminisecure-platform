<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import DispatchConnectionBar from '../../../Components/DispatchConnectionBar.vue'
import PanicAlarmControls from '../../../Components/PanicAlarmControls.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'

/**
 * Guard alertness & patrol monitoring — board screen super-admin-15.
 *
 * DOM and class names are the board's. The board draws the section tabs, the
 * banner's button and the whole table as <div>s; the tabs and the button are
 * real controls here because they navigate, and the table is a real <table>
 * because it is one.
 *
 * SILENCE IS THE FINDING. Every figure on this screen is an argument about
 * elapsed time — how long since this guard last proved they were awake — so a
 * screen that has quietly stopped refreshing is not a stale screen, it is a
 * screen that is actively lying about how long the silence has run. That is why
 * the poll and the socket both run below, and why the topbar control says which
 * of them is working.
 *
 * NOTHING HERE IS A POSITION. The tour dots are an ordered count of fixed,
 * named checkpoints reached; there is no coordinate in any prop on this page,
 * because PatrolMonitor deliberately holds none.
 */
const props = defineProps({
    /** Dispatch's own sections, with this one active. */
    sections: { type: Array, required: true },
    /** The red interruption bar, or null when nobody is late. */
    banner: { type: Object, default: null },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** The cadences this screen enforces, written from the constants. */
    policy: { type: String, required: true },
    /** The estates whose alert channel this screen may listen on. */
    estateIds: { type: Array, required: true },
    /** Whether this viewer's role narrows the platform to assigned sites. */
    scoped: { type: Boolean, default: false },
    /**
     * Whether the scans and challenges on this screen came from real handsets
     * or from the simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
})

/**
 * THE POLL IS THE GUARANTEE. THE SOCKET IS THE SPEED.
 *
 * Five seconds when the socket is down, matching the live map, because the two
 * screens report the same night and a dispatcher who flips between them must not
 * find one of them a minute behind the other.
 *
 * One private channel per estate on screen, and no others. Alongside the poll,
 * never instead of it: quiet-because-nothing-happened and
 * quiet-because-the-socket-dropped must never look the same on a screen whose
 * entire subject is silence. The bar beside the source badge says which mode
 * the screen is in; polling runs every five seconds only while the channel is
 * down (13 C2).
 */
const { poll, streaming, connection, panic } = useLiveDispatch({
    only: ['banner', 'kpis', 'rows'],
    intervalMs: 5000,
    estateIds: props.estateIds,
})

/**
 * What the topbar control discloses.
 *
 * The board draws exactly one control on this screen and nowhere to put a
 * status pill, so the policy and the screen's own liveness are said here —
 * together, because they are one question. "Is this guard late?" is only
 * answerable if you know both the cadence being enforced AND that the elapsed
 * times in front of you are still being refreshed.
 */
const policyTitle = computed(() =>
    streaming.value
        ? `${props.policy} Alerts and clock-ins arrive instantly over the live channel; if it drops, this screen falls back to refreshing every five seconds within forty seconds.`
        : `${props.policy} The live alert channel is down, so this screen is refreshing every five seconds until it returns.`
)

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render and stop agreeing with
 * the props behind it — which on a polled screen means freezing on the state
 * the very first response happened to have.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.scoped,
})
</script>

<template>
    <Head title="Dispatch — guard alertness & patrol monitoring" />

    <GeminiConsole title="Dispatch — guard alertness & patrol monitoring">
        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
            <DispatchConnectionBar :connection="connection" @refresh="poll.refresh()" />
            <!-- The siren sounds here too, so it can be quietened here (B107). -->
            <PanicAlarmControls :alarm="panic" />
        </template>

        <template #actions>
            <!--
              The board draws a pill labelled "Alertness policy". The cadences
              it names are enforced by constants in PatrolMonitor and are not
              editable from this console yet — editing them arrives with
              platform settings — so the control is visibly inert and hands over
              the policy itself, plus whether this screen is still being fed,
              rather than accepting a click and opening nothing.
            -->
            <button type="button" class="btn-outline-sm" disabled :title="policyTitle">
                <BoardIcon name="clock" :stroke="1.8" />
                <span>Alertness policy</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="7" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The alertness feed could not be read"
            body="Tonight's scans and challenges could not be loaded, so this screen cannot say who is reporting. It is showing nothing rather than showing every guard as quiet, which would raise an alert on every post at once."
            action-label="Try again"
            @action="poll.refresh()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Patrol monitoring is not yours to see"
            body="Alertness monitoring is limited to roles holding dispatch access. Your role can be granted it from the role access matrix by a platform administrator."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No guards on duty at your sites"
            body="Your role covers assigned sites rather than the whole platform, and nobody is rostered on at any of them right now. Guards may well be standing posts elsewhere; they are simply not yours to watch."
            action-label="Open the coverage board"
            @action="router.visit('/dispatch/coverage')"
        />

        <!--
          An empty table here is a real answer, not a missing one: nobody is
          rostered on at this hour. It says so in those words, because a blank
          monitoring screen at 4 AM otherwise reads as a broken feed.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nobody is on duty right now"
            body="This screen watches the guards whose rostered shift covers this moment, and no shift does. Guards appear the moment one starts, each with whatever they have last proved — a checkpoint scanned, or an alertness challenge answered."
            action-label="Open the coverage board"
            @action="router.visit('/dispatch/coverage')"
        />

        <template v-else>
            <!--
              Drawn ONLY while somebody is actually late. A red interruption bar
              that is permanently on screen has stopped interrupting anybody.
            -->
            <div v-if="banner" class="live-banner">
                <div class="lb-dot"></div>
                <div>
                    <div class="lb1">{{ banner.title }}</div>
                    <div class="lb2">{{ banner.meta }}</div>
                </div>
                <!--
                  The guard's own record is where the phone number, the post and
                  the supervisor are. There is no dispatch-to-one-guard channel
                  in this console — the Guard App owns that — so this goes where
                  the number is rather than pretending to send something.
                -->
                <Link :href="banner.href" class="lb-btn">{{ banner.label }}</Link>
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

            <table class="patrol-table">
                <thead>
                    <tr>
                        <th>Guard</th>
                        <th>Post type</th>
                        <th>Last checkpoint scan</th>
                        <th>Last challenge response</th>
                        <th>Last position</th>
                        <th>Tour progress</th>
                        <th>State</th>
                        <th>Device</th>
                    </tr>
                </thead>
                <tbody>
                    <!--
                      The red row and the red pill are the same fact, so the row
                      is flagged from the pill's own class rather than from a
                      second prop that could start disagreeing with it.
                    -->
                    <tr v-for="row in rows" :key="row.key" :class="{ flagged: row.state_class === 'alert' }">
                        <td>{{ row.name }}</td>
                        <td>{{ row.post }}</td>
                        <!--
                          A dash in one of these two columns is the normal case,
                          not a gap: a gate guard has no checkpoint to reach and
                          a guard walking a tour is not challenged while they do
                          it. They are alternatives, which is why the board
                          draws them as two columns.
                        -->
                        <td>{{ row.last_scan }}</td>
                        <td>{{ row.last_challenge }}</td>
                        <!--
                          M22 §1.1: when the phone last recorded where the officer
                          is. A gap — nothing taken for ten minutes on a shift they
                          agreed to share — is the board's own red pill: the phone
                          is off, signed out, or its sharing stopped by itself.
                        -->
                        <td>
                            <div v-if="row.position_gap" class="state-pill alert">
                                <i></i>
                                {{ row.position }}
                            </div>
                            <template v-else>{{ row.position }}</template>
                        </td>
                        <td>
                            <div v-if="row.tour" class="tour-progress">
                                <!--
                                  Exactly one dot can be red. The checkpoints
                                  beyond the late one are not missed — nobody
                                  was due at them yet.
                                -->
                                <div
                                    v-for="(dot, index) in row.tour"
                                    :key="index"
                                    class="tour-dot"
                                    :class="dot.class"
                                ></div>
                            </div>
                            <template v-else>—</template>
                        </td>
                        <td>
                            <div class="state-pill" :class="row.state_class">
                                <i></i>
                                {{ row.state }}
                            </div>
                        </td>
                        <td>
                            <div class="device-tag">{{ row.device }} · <b>{{ row.binding }}</b></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule below REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the section tabs, the topbar pill and the banner's button as
 * <div>s, which carry no default chrome. Here two of them are <button>s and one
 * is an <a>, because they have to be operable, and the browser's own styling
 * for those elements — an anchor's underline, a button's border, background and
 * system font — would otherwise show through and change the pixels the board
 * specifies. Nothing here introduces a colour, size or spacing the board does
 * not already declare.
 */
.subnav-item,
.lb-btn {
    text-decoration: none;
}

.subnav-item,
.btn-outline-sm {
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
 * as the board's own `.subnav-item.active`, and which of the two won would then
 * depend on the order the bundler happened to emit them in — the active tab's
 * white pill would vanish on a build where this file landed second.
 */
.subnav-item:not(.active) {
    background: none;
}

/* Inert, and it says so on hover. No opacity change: the board draws these at
 * one weight, and dimming them would be a pixel the design does not have. */
.subnav-item[disabled],
.btn-outline-sm[disabled] {
    cursor: not-allowed;
}

/* The board's own border, restated after the UA one is removed above, rather
 * than left to specificity luck. */
.btn-outline-sm {
    border: 1.5px solid var(--navy-200);
}
</style>

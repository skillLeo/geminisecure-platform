<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import Subnav from './Subnav.vue'
import ShiftPanel from './ShiftPanel.vue'
import { claimQuestion } from '../../../lib/claimQuestion.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Cross-client guard roster — board screen super-admin-24.
 *
 * DOM and class names are the board's. The grid is THE WEEK'S SHIFTS (3 Oct, brought to production by M35 §4): posts
 * down the left grouped by client, each named as the estate names it; the seven days across the top, each with its
 * date; and in each cell every shift that starts at that post that day, with its time and the officer on it, or Open.
 * It used to repeat each officer's usual post in all seven columns, with no date and no time, and was read as a
 * schedule it was not — "the guard is put on all seven days" was that grid, never a shift.
 *
 * EVERY SHIFT IS WRITTEN THROUGH THE SCHEDULE'S OWN PANEL (M35 §2, §3): "Post
 * open shift" on the topbar; on every row of the day list Change guard — or
 * Assign, on an open shift — and Remove; and any shift on the grid, clicked. One
 * panel, with its searchable picker, its rules said before anything is pressed,
 * and its overnight shifts, rather than a second form here with rules of its own.
 */
const props = defineProps({
    /** Column headings, Monday first, each with its date: "Mon 29 Sep". */
    days: { type: Array, required: true },
    /** "Sep 29 – Oct 5, 2026". */
    week: { type: String, required: true },
    /** The Monday before and after, for the week links; and whether the week shown holds today. */
    previousWeek: { type: String, default: '' },
    nextWeek: { type: String, default: '' },
    thisWeek: { type: Boolean, default: true },
    /** One entry per client, each with a line per post and seven cells of shifts. */
    groups: { type: Array, required: true },
    kpis: { type: Array, required: true },
    /** Whether the viewer's role narrows this to their assigned sites. */
    scoped: { type: Boolean, default: false },

    /** One day's rota, and what is open on it (12 §2, Wave 5), with the dates either side as `previous` and `next`. */
    day: { type: Object, required: true },
    /** Whether a day was asked for — the day view is drawn only then, or when a shift is open. */
    dayRequested: { type: Boolean, default: false },
    /** The seven dates behind the grid's day headings. */
    weekDates: { type: Array, default: () => [] },
    canWrite: { type: Boolean, required: true },
    writeBlockedReason: { type: String, required: true },
    /** Officers' claims on open shifts, whatever day each is on (26 Sep, G3). */
    claimsWaiting: { type: Array, default: () => [] },
    /** The schedule's shift panel's footing for every client: its name, its clock and its gates (M35 §2, §3). */
    shiftPanels: { type: Object, default: () => ({}) },
})

/* ------------------------------------------------------------------ */
/* posting, changing and removing a shift — the schedule's own panel (M35 §2, §3) */
/* ------------------------------------------------------------------ */

/*
 * AN OPEN SHIFT IS A REAL ROW WITH NO OFFICER ON IT. A post needing somebody is
 * a fact this console has to hold: a placeholder guard would show an empty gate
 * as manned, and not recording the gap at all would leave it in a dispatcher's
 * head. The panel opens with "Leave it open" chosen, and nobody is put on a
 * shift but by a person choosing them, or by a guard's claim being approved.
 */
const panel = ref({ open: false, clientId: null, start: {}, shiftId: null, act: null })

const panelClients = computed(() => Object.values(props.shiftPanels).map((p) => p.client))
const panelFor = computed(() => props.shiftPanels[panel.value.clientId] ?? null)

// Today at a client, on its own clock: the panel's earliest day, as the server counts the past.
const todayAt = (zone) => {
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
    } catch {
        return ''
    }
}

// The day on screen, or the client's today if that day has gone.
const startDay = (clientId) => {
    const today = todayAt(props.shiftPanels[clientId]?.zone?.estate)

    return { date: props.day.date >= today ? props.day.date : today }
}

const postOpenShift = () => {
    const first = panelClients.value.find((c) => !c.internal) ?? panelClients.value[0]

    if (!props.canWrite || !first) {
        return
    }

    panel.value = { open: true, clientId: first.id, start: startDay(first.id), shiftId: null, act: null }
}

const chooseClient = (id) => {
    panel.value = { ...panel.value, clientId: id, start: startDay(id) }
}

// A row's own buttons: the same panel, opened on that shift, at its guard picker or at "Remove this shift".
const openRow = (row, act) => {
    if (!props.shiftPanels[row.tenant_id]) {
        return
    }

    panel.value = { open: true, clientId: row.tenant_id, start: {}, shiftId: row.id, act }
}

const closePanel = () => {
    panel.value = { ...panel.value, open: false }
}

// Why a row's button is held down, in the words of the rule that holds it.
const guardTitle = (row) => {
    if (!props.canWrite) return props.writeBlockedReason
    if (row.locked) return row.locked
    if (!props.shiftPanels[row.tenant_id]) return `${row.estate} is not a client your role plans for.`

    return row.is_open
        ? 'Put a guard on this shift: every guard at this client is listed, and anybody busy, on leave or out of licence is crossed out with the reason.'
        : `Change who stands it. ${row.officer} is told on their phone, with the reason you give.`
}

const removeTitle = (row) => {
    if (!props.canWrite) return props.writeBlockedReason
    if (row.locked) return row.locked
    if (!row.can_remove) return 'It has started, so it is not removed: a shift is removed only before it starts.'
    if (!props.shiftPanels[row.tenant_id]) return `${row.estate} is not a client your role plans for.`

    return 'Remove this shift. You are asked why, and to confirm it in one line.'
}

/*
 * AN OFFICER'S CLAIM, ANSWERED (26 Sep, G3). A guard claims an open shift from the Guard App; until this, nothing
 * anywhere could answer it. Approve puts that officer on the shift — the same checks as Assign — and declines the
 * other claims on it; Decline leaves the shift open. Both tell the officer's phone, so both ask first, in the
 * Schedule's own words (DR-075, `lib/claimQuestion.js`).
 */
const claimForm = useForm({ note: '' })
const answering = ref(null)

/** The claim being asked about, and the answer: `{ claim, verb }`, or null. */
const claimAsk = ref(null)
const claimWords = computed(() => (claimAsk.value === null ? null : claimQuestion(claimAsk.value.claim, claimAsk.value.verb, props.claimsWaiting)))

const askClaim = (claim, verb) => {
    if (!props.canWrite || answering.value !== null) {
        return
    }

    claimForm.clearErrors()
    claimAsk.value = { claim, verb }
}

const approveClaim = (claim) => askClaim(claim, 'approve')

const declineClaim = (claim) => askClaim(claim, 'decline')

const answerClaim = () => {
    const ask = claimAsk.value

    if (ask === null || !props.canWrite || answering.value !== null) {
        return
    }

    answering.value = ask.claim.id
    // A refusal stays in the question's amber band and the question stays open (Hassam, 12 October); it closes when the
    // claim is answered, and what was done is the shell's toast.
    claimForm.post(`/guards/roster/claims/${ask.claim.id}/${ask.verb}`, {
        preserveScroll: true,
        onSuccess: () => {
            claimAsk.value = null
        },
        onFinish: () => {
            answering.value = null
        },
    })
}

const keepClaim = () => {
    claimAsk.value = null
    claimForm.clearErrors()
}


/**
 * Which day the rota below is showing. A real GET, so it can be bookmarked.
 *
 * THE SERVER'S DAY BEFORE AND DAY AFTER (bug hunt B114). This added a day to
 * `new Date('…T00:00:00')`, which is local midnight, and printed it with
 * `toISOString()`, which is UTC — so in any browser east of UTC "Next day"
 * reloaded the same day and "Previous day" skipped one. A day is a date, so the
 * page links to the dates it is given and the browser's zone never enters it.
 */
const dayHref = (date) => `/guards/roster?date=${date}`

const state = useScreenState({
    rows: () => props.groups.length,
    // A Head of Security sees their assigned sites and nothing else, which is
    // a narrowed view rather than an empty platform: telling them "no posts are
    // staffed yet" when four are staffed outside their scope would be a lie
    // about the company, so the narrowed case gets its own words.
    filtered: () => props.scoped,
})

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['shift'] })
</script>

<template>
    <Head title="Cross-client roster" />

    <GeminiConsole title="Guard workforce">
        <!--
          The board's own topbar button. It posts an OPEN shift — a post, a
          window and nobody yet — which is the row the coverage board then
          shows as uncovered until somebody is put on it. Through the schedule's
          own panel (M35 §3), with "Leave it open" chosen.
        -->
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canWrite || panelClients.length === 0"
                :title="canWrite ? 'Post a shift with no officer on it. The coverage board shows it as uncovered until somebody is assigned, which is what a gap should look like.' : writeBlockedReason"
                @click="postOpenShift"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Post open shift</span>
            </button>
        </template>

        <Subnav active="roster" />


        <!-- AUTHORED (26 Sep, G3). The board has no claims; the Guard App has had a Claim button since M2. -->
        <div v-if="claimsWaiting.length" class="rst-panel" aria-labelledby="rst-claims-head">
            <div id="rst-claims-head" class="rst-head">
                Claims waiting — {{ claimsWaiting.length }} {{ claimsWaiting.length === 1 ? 'officer has' : 'officers have' }} asked
                for an open shift from the Guard App. Approve puts that officer on it and declines any other claim on the same
                shift; both answers reach the officer's phone.
            </div>
            <div v-for="claim in claimsWaiting" :key="claim.id" class="rst-row rst-claim">
                <span class="rst-post">{{ claim.post }} · {{ claim.estate }}</span>
                <span class="rst-window"><a :href="dayHref(claim.day)" class="text-link-sm" :title="`Open ${claim.day} on the roster`">{{ claim.window }}</a></span>
                <span class="rst-who">
                    {{ claim.guard }} <span class="rst-why">{{ claim.employee_number }} · claimed {{ claim.claimed }}</span>
                </span>
                <span class="rst-claim-acts">
                    <button
                        type="button"
                        class="btn-primary-sm"
                        :disabled="!canWrite || answering !== null"
                        :title="canWrite ? `Put ${claim.guard} on this shift.` : writeBlockedReason"
                        @click="approveClaim(claim)"
                    >
                        <span>{{ answering === claim.id ? 'Answering…' : 'Approve' }}</span>
                    </button>
                    <button
                        type="button"
                        class="text-link-sm"
                        :disabled="!canWrite || answering !== null"
                        :title="canWrite ? 'Answer no; the shift stays open for anybody else.' : writeBlockedReason"
                        @click="declineClaim(claim)"
                    >
                        Decline
                    </button>
                </span>
            </div>
        </div>

        <ConsoleDialog
            :open="claimWords !== null"
            :title="claimWords?.title ?? ''"
            :tone="claimWords?.tone ?? 'primary'"
            :confirm-label="claimWords?.confirm ?? 'Approve'"
            busy-label="Answering…"
            :busy="answering !== null"
            :error="claimForm.errors.claim ?? null"
            @confirm="answerClaim"
            @cancel="keepClaim"
        >
            <template v-if="claimWords">
                <p v-for="line in claimWords.lines" :key="line" style="margin: 0 0 6px">{{ line }}</p>
            </template>
        </ConsoleDialog>

        <!--
          AUTHORED. The board draws a week nobody is editing, so it has neither
          the panel nor the day list below. The panel is the schedule's own
          (M35 §2, §3): every shift written here is written through it.
        -->
        <ShiftPanel
            v-if="panelFor"
            :open="panel.open"
            :client="panelFor.client"
            :zone="panelFor.zone"
            :posts="panelFor.posts"
            :start="panel.start"
            :shift-id="panel.shiftId"
            :act="panel.act"
            :clients="panel.shiftId === null ? panelClients : []"
            :can-write="canWrite"
            @client="chooseClient"
            @close="closePanel"
        />

        <div v-if="dayRequested || day.open > 0" class="rst-day">
            <div class="rst-day-top">
                <a :href="dayHref(day.previous)" class="text-link-sm" :title="`The day before — ${day.previous}`">← Previous day</a>
                <span class="rst-day-label">
                    {{ day.date }} — {{ day.rows.length }} shift(s), {{ day.open }} open
                </span>
                <a :href="dayHref(day.next)" class="text-link-sm" :title="`The day after — ${day.next}`">Next day →</a>
            </div>

            <p v-if="day.rows.length === 0" class="rst-none">
                Nothing is rostered for this day. That is a real answer — an unstaffed day is not a screen that failed.
            </p>

            <template v-for="row in day.rows" :key="row.id">
                <div class="rst-row" :class="{ open: row.is_open }">
                    <span class="rst-post">{{ row.post }} · {{ row.estate }}</span>
                    <span class="rst-window">{{ row.window }}</span>
                    <span class="rst-who">
                        {{ row.officer ?? 'Open' }}
                        <span v-if="row.released_reason" class="rst-why">{{ row.released_reason }}</span>
                        <!-- Who has asked for it (G3): answered in Claims waiting above. -->
                        <span v-if="row.claims?.length" class="rst-why">
                            Claimed by {{ row.claims.map((claim) => claim.guard).join(', ') }} — answer it under Claims waiting
                        </span>
                    </span>
                    <!--
                      Its two controls (M35 §2), both the schedule's panel opened on this shift: Change guard — Assign,
                      on an open one — at the panel's picker, and Remove at "Remove this shift", which asks why and
                      confirms in one line. Held down, with the reason, when the rule says no.
                    -->
                    <span class="rst-row-acts">
                        <button
                            type="button"
                            class="text-link-sm"
                            :disabled="!canWrite || row.locked !== null || !shiftPanels[row.tenant_id]"
                            :title="guardTitle(row)"
                            @click="openRow(row, 'guard')"
                        >
                            {{ row.is_open ? 'Assign' : 'Change guard' }}
                        </button>
                        <button
                            type="button"
                            class="text-link-sm rst-danger"
                            :disabled="!canWrite || !row.can_remove || !shiftPanels[row.tenant_id]"
                            :title="removeTitle(row)"
                            @click="openRow(row, 'remove')"
                        >
                            Remove
                        </button>
                    </span>
                </div>
            </template>
        </div>

        <!--
          Loading. The grid's own panel, holding a skeleton of the shape the
          rota arrives in — eight columns, one post and seven days.
        -->
        <div v-if="state.isLoading.value" class="roster-grid">
            <SkeletonRows :rows="6" :columns="8" />
        </div>

        <template v-else>
            <div class="kpi-row">
                <div
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    class="kpi-card"
                    :class="{ warn: kpi.warn }"
                    :title="kpi.title"
                >
                    <div class="k-top">
                        <div class="kpi-icon">
                            <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.value }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <EmptyState
                v-if="state.isDenied.value"
                variant="denied"
                title="You cannot see the cross-client rota"
                body="The rota shows every post at every client. Your role covers guard records rather than deployment, so it stops at the guard directory."
            />

            <EmptyState
                v-else-if="state.isError.value"
                variant="error"
                title="The rota could not be loaded"
                body="Nothing has been changed and no shift has been lost. The assignment register is central, so this is a read that failed rather than a roster that is missing."
            />

            <EmptyState
                v-else-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No posts at your sites"
                body="Your role covers the sites you are assigned to, and none of them has a post yet. Posts at other clients are outside your scope rather than missing."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No posts yet"
                body="Add the client's gates in Sites & geofences, then post shifts at them with Post open shift. Each shift then shows here, on its day, with its time."
            />

            <template v-else>
                <!-- 3 Oct: which week this is, said in words, and the way to the weeks either side. -->
                <div class="rst-week-nav">
                    <Link v-if="previousWeek" :href="`/guards/roster?week=${previousWeek}`" class="text-link-sm">← Previous week</Link>
                    <span class="rst-week-label">
                        Week of {{ week }}{{ thisWeek ? ' (this week)' : '' }} · every shift, on the day it starts, with its time
                    </span>
                    <Link v-if="nextWeek" :href="`/guards/roster?week=${nextWeek}`" class="text-link-sm">Next week →</Link>
                </div>

                <div v-for="group in groups" :key="group.id" class="client-group">
                    <div class="client-group-head">
                        <div class="cg-dot"></div>
                        <h4>{{ group.name }}</h4>
                    </div>
                    <div class="roster-grid">
                        <div class="roster-head-row">
                            <div>Post</div>
                            <!-- Each heading, with its date, opens that day: every shift on it, with Assign, Take off and Remove. -->
                            <div v-for="(label, i) in days" :key="label" :title="week">
                                <Link
                                    v-if="weekDates[i]"
                                    :href="`/guards/roster?date=${weekDates[i]}`"
                                    class="rst-day-link"
                                    :title="`Open ${weekDates[i]}: every shift that day, to assign, take off or remove.`"
                                >
                                    {{ label }}
                                </Link>
                                <template v-else>{{ label }}</template>
                            </div>
                        </div>
                        <div v-for="line in group.lines" :key="line.key" class="roster-row2">
                            <div class="roster-post">{{ line.post }}</div>
                            <div v-for="(cell, index) in line.cells" :key="index" class="roster-cell">
                                <div v-if="cell.length" class="rst-cell-shifts">
                                    <!--
                                      M35 §2: a shift on the grid opens the schedule's panel on it — Change guard (or
                                      Assign), Take off and Remove, each with its reason and its rules. A role that may
                                      only read, or a client the panel does not plan for, opens the day instead.
                                    -->
                                    <template v-for="shift in cell" :key="shift.id">
                                        <button
                                            v-if="canWrite && shiftPanels[group.id]"
                                            type="button"
                                            class="shift-chip rst-shift"
                                            :class="shift.tone"
                                            :title="`${shift.title} Click to change who stands it, take the guard off, or remove it.`"
                                            @click="openRow({ id: shift.id, tenant_id: group.id }, null)"
                                        >
                                            <span class="rst-shift-time">{{ shift.time }}</span>
                                            <span>{{ shift.label }}</span>
                                        </button>
                                        <Link
                                            v-else
                                            :href="`/guards/roster?date=${weekDates[index]}`"
                                            class="shift-chip rst-shift"
                                            :class="shift.tone"
                                            :title="shift.title"
                                        >
                                            <span class="rst-shift-time">{{ shift.time }}</span>
                                            <span>{{ shift.label }}</span>
                                        </Link>
                                    </template>
                                </div>
                                <span v-else class="rst-no-shift" title="Nothing is rostered at this post on this day.">No shift</span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * One rule, and it removes a browser default rather than adding a style.
 *
 * The board draws the topbar action as a <div>. It is a real <button> here so
 * it can be focused and so its disabled state is announced, and a button
 * arrives with a border and a font of the browser's own. The board's
 * .btn-primary-sm already declares everything else.
 */
button.btn-primary-sm {
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button.text-link-sm[disabled] {
    cursor: not-allowed;
}

a.text-link-sm {
    text-decoration: none;
}

/* The day heading's link wears the heading's own type — colour, size and
 * weight inherited — so the grid reads exactly as the board draws it. */
a.rst-day-link {
    color: inherit;
    font: inherit;
    text-decoration: none;
}

/* 3 Oct: the week's shifts — which week, in words; and in a cell each shift's time over who is on it. */
.rst-week-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 0 0 12px;
    font-size: 11.5px;
}

.rst-week-label {
    font-weight: 700;
    color: var(--navy-900);
    text-align: center;
}

.rst-cell-shifts {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.rst-shift {
    display: flex;
    flex-direction: column;
    gap: 2px;
    line-height: 1.25;
    text-decoration: none;
}

.rst-shift-time {
    font-size: 9.5px;
    font-weight: 600;
    opacity: 0.85;
}

.rst-no-shift {
    font-size: 10px;
    color: var(--slate-600);
    opacity: 0.7;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a week nobody is editing, so it has
 * no panel, no flash and no day list. Kept to the tokens the Gemini boards
 * define. An OPEN row is amber because it is a condition somebody must act on,
 * not a failure — the same reading the arrears buckets get. What a press did is the shell's toast; a refusal is said
 * inside the claim's question or the shift panel that was open.
 */
.rst-panel,
.rst-day {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rst-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.rst-day-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}

.rst-day-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
}

.rst-none {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.6;
    margin: 0;
}

.rst-row {
    display: flex;
    align-items: baseline;
    gap: 14px;
    border-top: 1px solid var(--navy-100);
    padding-top: 8px;
    font-size: 11.5px;
    color: var(--navy-900);
    line-height: 1.55;
}

.rst-row.open .rst-who {
    color: var(--amber-700);
    font-weight: 700;
}

.rst-post {
    flex: 1 1 40%;
    font-weight: 600;
}

.rst-window {
    flex: 0 0 190px;
    color: var(--slate-600);
}

.rst-who {
    flex: 1 1 30%;
}

.rst-why {
    display: block;
    font-size: 10.5px;
    font-weight: 400;
    color: var(--slate-500);
}

/* A claim waiting: the officer, then the two answers at the end of the row. */
.rst-claim {
    align-items: center;
    flex-wrap: wrap;
}

.rst-claim-acts {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
}

/* A row's own two controls (M35 §2), at the end of the row as a claim's answers are. */
.rst-row-acts {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-left: auto;
    white-space: nowrap;
}

/* The danger tone the panel's own "Remove this shift" is drawn in. */
button.text-link-sm.rst-danger {
    color: var(--red-700);
}

button.text-link-sm.rst-danger[disabled],
.rst-row-acts button.text-link-sm[disabled] {
    color: var(--slate-500);
}

/* A shift on the grid that opens the panel (M35 §2): the board's own chip, as a button, with nothing of the browser's. */
button.shift-chip.rst-shift {
    border: 0;
    font-family: inherit;
    cursor: pointer;
}
</style>

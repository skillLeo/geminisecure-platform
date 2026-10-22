<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import Subnav from './Subnav.vue'
import ShiftPanel from './ShiftPanel.vue'
import { requestHeaders } from '../../../live/authorize.js'
import { browserZone, zoneSentence } from '../../../lib/scheduleTime.js'
import { plannedMove } from '../../../lib/scheduleMove.js'
import { claimQuestion } from '../../../lib/claimQuestion.js'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * The Schedule (M30 §4) — one client's week of shifts, the way the person planning it reads a week.
 *
 * Gates down the side, the seven days of the week across the top with their dates, and in each cell every shift that
 * starts there that day: its time, and the guard on it or "Needs a guard". Built from the board's own roster grid,
 * chips, cards and buttons; nothing here is a new colour or a new font.
 */
const props = defineProps({
    clients: { type: Array, required: true },
    client: { type: Object, default: null },
    zone: { type: Object, default: null },
    week: { type: Object, default: null },
    days: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    cards: { type: Array, default: () => [] },
    canWrite: { type: Boolean, required: true },
    writeBlockedReason: { type: String, required: true },
    /** Approved leave in the week, as bands across its days (§8.1). */
    leave: { type: Array, default: () => [] },
    /** Leave waiting for a decision, each with the shifts the guard holds in those days (§8.2). */
    leaveWaiting: { type: Array, default: () => [] },
    canDecideLeave: { type: Boolean, default: false },
    /** Guards' claims on open shifts, every client the viewer reaches, oldest first (M35 §1; the Roster's since G3). */
    claimsWaiting: { type: Array, default: () => [] },
})

const page = usePage()

const realClients = computed(() => props.clients.filter((c) => !c.internal))
const internalClients = computed(() => props.clients.filter((c) => c.internal))

const href = (params) => {
    const query = new URLSearchParams({ client: props.client?.id ?? '', ...params })
    return `/guards/schedule?${query.toString()}`
}

const chooseClient = (event) => router.get('/guards/schedule', { client: event.target.value, week: props.week?.monday })

// "All times are Phoenix Park Village 1's time (JA). Your time is 10 hours ahead." — worked out from the zones.
const sentence = computed(() => {
    if (!props.client || !props.zone) return ''

    return zoneSentence({
        client: props.client.name,
        estateZone: props.zone.estate,
        estateTag: props.zone.estateTag,
        shownTag: props.zone.shownTag,
        mine: props.zone.mine,
        viewerZone: props.zone.mine ? props.zone.shown : browserZone(),
    })
})

const tone = (block) => (block.guard === null ? 'open' : block.duty === 'patrol' ? 'night' : 'day')

// Who is on it. A shift that ended with nobody on it no longer needs a guard: it says what happened.
const who = (block) => (block.guard ? block.guard.short : block.state === 'ended' ? 'No guard was on it' : 'Needs a guard')

/* ---- the shift panel ---- */

// Today at the client, as the server counts it: a day before it is the past, and nothing is added there.
const todayAtClient = computed(() => {
    if (!props.zone) return ''
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone: props.zone.estate, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
    } catch {
        return ''
    }
})

const posts = computed(() => props.rows.map((r) => ({ id: r.id, name: r.name, also_called: r.also_called, type: r.type })))

const panelOpen = ref(false)
const panelStart = ref({})
const panelShiftId = ref(null)

// "+ Add shift": the first gate, on today if today is in the week on screen, else the week's first day still to come.
const addShift = () => {
    const today = todayAtClient.value
    const firstToCome = props.days.find((d) => d.date >= today)?.date ?? today
    panelShiftId.value = null
    panelStart.value = { post_id: posts.value[0]?.id ?? null, date: firstToCome }
    panelOpen.value = true
}

// The "+" in a cell: that gate, that day.
const addHere = (row, day) => {
    panelShiftId.value = null
    panelStart.value = { post_id: row.id, date: day.date }
    panelOpen.value = true
}

// A block: the panel, opened on that shift (§4.3) — to change it, or, for a role that only reads, to read it.
const openShift = (block) => {
    panelShiftId.value = block.id
    panelStart.value = {}
    panelOpen.value = true
}

const canAddOn = (day) => props.canWrite && day.date >= todayAtClient.value

/*
 * ---- dragging a block to another cell (§4.3) ----
 *
 * The server is asked first (`/guards/schedule/check`): a move that breaks a rule springs back, with the reason in one
 * line; one that does not asks, in one line, before anything is saved. The panel changes gate and day as well: this is
 * the quicker way, never the only one.
 */
const dragging = ref(null)
const dropKey = ref(null)
const move = ref(null)
const springing = ref(null)

const canDrag = (block) => props.canWrite && block.state === 'upcoming' && Date.parse(block.starts_at) > Date.now()

// The one line is held at the foot of the window, over the grid's own card, so it is in sight wherever the block was
// dropped. Kept in the page it scrolled away with the grid: the console's content box clips (`overflow: hidden`), so
// a sticky bar never held. In a browser it is moved to <body>, as the shift panel is; in the script tests it is in place.
const canTeleport = typeof document !== 'undefined' && typeof document.querySelector === 'function'
const frame = ref(null)
const moveAt = ref({})
const placeMove = () => {
    const box = frame.value?.getBoundingClientRect?.()
    moveAt.value = box ? { left: `${Math.round(box.left)}px`, width: `${Math.round(box.width)}px`, right: 'auto' } : {}
}

const startDrag = (block, row, day, event) => {
    dragging.value = { block, row, day }
    move.value = null
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
        event.dataTransfer.setData?.('text/plain', String(block.id))
    }
}

const endDrag = () => {
    dragging.value = null
    dropKey.value = null
}

const overCell = (row, day, event) => {
    if (!dragging.value) return
    event.preventDefault()
    dropKey.value = `${row.id}|${day.date}`
}

const springBack = (blockId, why) => {
    move.value = { ...(move.value ?? {}), checking: false, refused: why }
    springing.value = blockId
    setTimeout(() => {
        if (springing.value === blockId) springing.value = null
    }, 450)
}

const dropOn = async (row, day, event) => {
    event.preventDefault()
    const from = dragging.value
    endDrag()
    if (!from) return

    const plan = plannedMove({ block: from.block, fromPost: from.row, fromDay: from.day.date, toPost: row, toDay: day.date, zone: props.zone.estate, tag: props.zone.estateTag })
    if (!plan) return

    const { sentence, ...form } = plan
    placeMove()
    move.value = { shiftId: from.block.id, form, sentence, checking: true, refused: null }

    try {
        const response = await fetch('/guards/schedule/check', {
            method: 'POST',
            headers: requestHeaders({ 'Content-Type': 'application/json' }),
            credentials: 'same-origin',
            body: JSON.stringify({ ...form, shift_id: from.block.id, repeat: 'once' }),
        })
        const answer = response.ok ? await response.json() : null

        if (!answer) springBack(from.block.id, 'The move could not be checked. Try again, or open the shift.')
        else if (!answer.ok) springBack(from.block.id, answer.checks.find((c) => !c.ok)?.text ?? 'That move breaks a rule.')
        else move.value = { ...move.value, checking: false }
    } catch {
        springBack(from.block.id, 'No connection: the move could not be checked.')
    }
}

const confirmMove = () => {
    const pending = move.value
    if (!pending || pending.checking || pending.refused) return

    move.value = { ...pending, checking: true }
    router.post(`/guards/schedule/shifts/${pending.shiftId}`, pending.form, {
        preserveScroll: true,
        onSuccess: () => {
            if (!page.props.errors?.panel) move.value = null
        },
        onError: (errors) => springBack(pending.shiftId, errors.panel ?? Object.values(errors)[0] ?? 'That move could not be saved.'),
    })
}

/*
 * ---- leave (§8): the shifts it leaves without a guard are said before it is approved, and a person chooses ----
 */
const deciding = ref(null)
const leaveChoice = ref(null)
const leaveNote = ref('')
const leaveError = ref(null)
const leaveBusy = ref(false)

const openLeave = (request, decision) => {
    deciding.value = { request, decision }
    leaveChoice.value = null
    leaveNote.value = ''
    leaveError.value = null
}

const leaveTitle = computed(() => {
    const d = deciding.value
    if (!d) return ''
    return d.decision === 'approved' ? `Approve ${d.request.guard.name}'s leave, ${d.request.days}?` : `Decline ${d.request.guard.name}'s leave, ${d.request.days}?`
})

// Nothing is chosen for the person (§8.2): with shifts in those days, one of the two must be picked.
const leaveBlocked = computed(() => {
    const d = deciding.value
    if (!d) return null
    if (d.decision === 'denied') return leaveNote.value.trim() === '' ? 'Say why. They see it, and it is what they act on.' : null
    if (d.request.shifts.length === 0 || leaveChoice.value !== null) return null
    return d.request.shifts.length === 1 ? 'Choose what happens to that shift first.' : 'Choose what happens to the shifts first.'
})

const confirmLeave = () => {
    const d = deciding.value
    if (!d || leaveBlocked.value || leaveBusy.value) return

    leaveBusy.value = true
    router.post(`/guards/schedule/leave/${d.request.id}`, {
        decision: d.decision,
        note: d.decision === 'denied' ? leaveNote.value.trim() : null,
        take_off: d.decision === 'approved' && leaveChoice.value === 'take_off',
    }, {
        preserveScroll: true,
        onSuccess: () => {
            if (!page.props.errors?.leave) deciding.value = null
        },
        onError: (errors) => {
            leaveError.value = errors.leave ?? errors.note ?? Object.values(errors)[0] ?? 'That could not be decided.'
        },
        onFinish: () => {
            leaveBusy.value = false
        },
    })
}

/* ---- copy last week into this week (§4.6): said first, then done exactly as said ---- */

const copyOpen = ref(false)
const copyPlan = ref(null)
const copyError = ref(null)
const copying = ref(false)

const shifts = (n) => `${n} ${n === 1 ? 'shift' : 'shifts'}`

const openCopy = async () => {
    copyOpen.value = true
    copyPlan.value = null
    copyError.value = null
    try {
        const query = new URLSearchParams({ client: props.client.id, week: props.week.monday })
        const response = await fetch(`/guards/schedule/copy?${query.toString()}`, { headers: requestHeaders(), credentials: 'same-origin' })
        const body = await response.json().catch(() => null)
        if (response.ok) copyPlan.value = body
        else copyError.value = body?.message ?? 'What the copy would do could not be read. Try again in a moment.'
    } catch {
        copyError.value = 'No connection: what the copy would do could not be read.'
    }
}

const copyLabel = computed(() => (copyPlan.value ? `Create ${shifts(copyPlan.value.create)}` : 'Create shifts'))
const copyBlocked = computed(() => {
    if (!copyPlan.value) return copyError.value ? 'Nothing can be copied.' : 'Working out what the copy would do.'
    return copyPlan.value.create === 0 ? 'There is nothing to copy into this week.' : null
})

const confirmCopy = () => {
    if (!copyPlan.value || copyPlan.value.create === 0 || copying.value) return

    copying.value = true
    router.post('/guards/schedule/copy', { client: copyPlan.value.client, week: copyPlan.value.week, fingerprint: copyPlan.value.fingerprint }, {
        preserveScroll: true,
        onSuccess: () => {
            if (!page.props.errors?.copy) copyOpen.value = false
        },
        onError: (errors) => {
            copyError.value = errors.copy ?? Object.values(errors)[0] ?? 'The copy could not be made.'
        },
        onFinish: () => {
            copying.value = false
        },
    })
}

/*
 * ---- a guard's claim on an open shift (M35 §1) ----
 *
 * A guard asks for an open shift from the Guard App, and the classic Roster has answered those since 26 Sep (G3). The
 * Roster tab opens this screen now, so they are answered here too, through the same two routes: Approve puts that
 * guard on the shift — the same checks as choosing him in the panel — and declines any other claim on it; Decline
 * leaves it open. Both tell the guard's phone, so both ask first, naming who is told (DR-075, `lib/claimQuestion.js`).
 */
const answering = ref(null)
const claimError = ref('')

/** The request being asked about, and the answer: `{ claim, verb }`, or null. */
const claimAsk = ref(null)
const claimWords = computed(() => (claimAsk.value === null ? null : claimQuestion(claimAsk.value.claim, claimAsk.value.verb, props.claimsWaiting)))

const askClaim = (claim, verb) => {
    if (!props.canWrite || answering.value !== null) return

    claimError.value = ''
    claimAsk.value = { claim, verb }
}

const keepClaim = () => {
    claimAsk.value = null
    claimError.value = ''
}

// A refusal stays in the question's amber band and the question stays open (Hassam, 12 October); it closes when the
// request is answered, and what was done is the shell's toast.
const answerClaim = () => {
    const ask = claimAsk.value

    if (ask === null || !props.canWrite || answering.value !== null) return

    answering.value = ask.claim.id
    claimError.value = ''

    router.post('/guards/roster/claims/' + ask.claim.id + '/' + ask.verb, {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (!page.props.errors?.claim) claimAsk.value = null
        },
        onError: (errors) => {
            claimError.value = errors.claim ?? Object.values(errors)[0] ?? 'That request could not be answered.'
        },
        onFinish: () => {
            answering.value = null
        },
    })
}

useLiveReload({ entities: ['shift'] })
</script>

<template>
    <Head title="Schedule" />

    <GeminiConsole title="Guard workforce">
        <!-- The board's own topbar button, where the classic roster has its "Post open shift". -->
        <template v-if="client && rows.length" #actions>
            <button
                type="button"
                class="btn-primary-sm sch-add"
                :disabled="!canWrite"
                :title="canWrite ? 'Add a shift at this client, with its guard or open for any guard to ask for' : writeBlockedReason"
                @click="addShift"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Add shift</span>
            </button>
        </template>

        <Subnav active="schedule" />


        <EmptyState
            v-if="!client"
            variant="first-use"
            title="No client to plan for"
            body="Your role reaches no client yet. A client appears here once it is set up in Clients, or once you are given one of its sites."
        />

        <template v-else>
            <div class="sch-top">
                <label class="sch-client" for="sch-client">
                    <span>Client</span>
                    <select id="sch-client" :value="client.id" @change="chooseClient">
                        <option v-for="c in realClients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        <optgroup v-if="internalClients.length" label="Internal, not a client">
                            <option v-for="c in internalClients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </optgroup>
                    </select>
                </label>
            </div>

            <div class="sch-week" role="navigation" aria-label="Which week">
                <Link :href="href({ week: week.previous })" class="text-link-sm" preserve-scroll>← Previous week</Link>
                <span class="sch-week-label">{{ week.label }}</span>
                <Link :href="href({ week: week.next })" class="text-link-sm" preserve-scroll>Next week →</Link>
                <Link v-if="!week.isThisWeek" :href="href({ week: week.this })" class="btn-outline-sm sch-this-week" preserve-scroll><span>This week</span></Link>
            </div>
            <p class="sch-zone">{{ sentence }}</p>

            <EmptyState
                v-if="rows.length === 0"
                variant="first-use"
                title="No gates at this client yet"
                body="Add the client's gates in Places, then add shifts here. Each shift shows on its day, with its time and its guard."
            />

            <!-- Leave waiting for a decision (§8.2), with the shifts each would leave without a guard. -->
            <section v-if="leaveWaiting.length" class="sch-leave-waiting" aria-labelledby="sch-leave-h">
                <h2 id="sch-leave-h" class="sch-h">Leave waiting for a decision</h2>
                <ul>
                    <li v-for="request in leaveWaiting" :key="request.id" class="sch-leave-row">
                        <span class="sch-leave-what">
                            <strong>{{ request.guard.name }}</strong> · {{ request.subject }} · {{ request.days }}
                            <span class="sch-muted"> · {{ request.shifts.length === 0 ? 'no shifts in those days' : request.shifts.length === 1 ? '1 shift in those days' : `${request.shifts.length} shifts in those days` }}</span>
                        </span>
                        <span v-if="canDecideLeave" class="sch-leave-acts">
                            <button type="button" class="btn-primary-sm" @click="openLeave(request, 'approved')"><span>Approve</span></button>
                            <button type="button" class="btn-outline-sm" @click="openLeave(request, 'denied')"><span>Decline</span></button>
                        </span>
                    </li>
                </ul>
                <p v-if="!canDecideLeave" class="sch-muted">Deciding leave is for a role with Dispatch and Guard workforce update access.</p>
            </section>

            <!-- Claims waiting (M35 §1): a guard has asked for an open shift from the Guard App. Said in this screen's words (M30 §9). -->
            <section v-if="claimsWaiting.length" class="sch-leave-waiting" aria-labelledby="sch-claims-h">
                <h2 id="sch-claims-h" class="sch-h">Waiting for your answer — a guard has asked for a shift that needs a guard</h2>
                <ul>
                    <li v-for="claim in claimsWaiting" :key="claim.id" class="sch-leave-row">
                        <span class="sch-leave-what">
                            <strong>{{ claim.guard }}</strong> · {{ claim.post }} · {{ claim.window }}
                            <span class="sch-muted"> · {{ claim.estate }} · asked {{ claim.claimed }}</span>
                        </span>
                        <span v-if="canWrite" class="sch-leave-acts">
                            <button
                                type="button"
                                class="btn-primary-sm"
                                :disabled="answering !== null"
                                :title="`Put ${claim.guard} on this shift. Anyone else who asked for it is told no; each answer reaches the guard's phone.`"
                                @click="askClaim(claim, 'approve')"
                            >
                                <span>{{ answering === claim.id ? 'Answering…' : 'Approve' }}</span>
                            </button>
                            <button
                                type="button"
                                class="btn-outline-sm"
                                :disabled="answering !== null"
                                title="Answer no. The shift still needs a guard, and any other guard can ask for it."
                                @click="askClaim(claim, 'decline')"
                            >
                                <span>Decline</span>
                            </button>
                        </span>
                    </li>
                </ul>
                <p v-if="!canWrite" class="sch-muted">{{ writeBlockedReason }}</p>
            </section>

            <ConsoleDialog
                :open="claimWords !== null"
                :title="claimWords?.title ?? ''"
                :tone="claimWords?.tone ?? 'primary'"
                :confirm-label="claimWords?.confirm ?? 'Approve'"
                busy-label="Answering…"
                :busy="answering !== null"
                :error="claimError || null"
                @confirm="answerClaim"
                @cancel="keepClaim"
            >
                <template v-if="claimWords">
                    <p v-for="line in claimWords.lines" :key="line" class="sch-leave-says">{{ line }}</p>
                </template>
            </ConsoleDialog>

            <ConsoleDialog
                :open="deciding !== null"
                :title="leaveTitle"
                :tone="deciding?.decision === 'denied' ? 'danger' : 'primary'"
                :confirm-label="deciding?.decision === 'denied' ? 'Decline leave' : 'Approve leave'"
                busy-label="Saving…"
                :busy="leaveBusy"
                :confirm-blocked-reason="leaveBlocked"
                :error="leaveError"
                @confirm="confirmLeave"
                @cancel="deciding = null"
            >
                <template v-if="deciding?.decision === 'approved'">
                    <template v-if="deciding.request.shifts.length">
                        <p class="sch-leave-says">{{ deciding.request.guard.name }} is on {{ deciding.request.shifts.length === 1 ? '1 shift' : `${deciding.request.shifts.length} shifts` }} in those days:</p>
                        <ul class="sch-leave-shifts">
                            <li v-for="s in deciding.request.shifts" :key="s.id">{{ s.text }}</li>
                        </ul>
                        <fieldset class="sch-leave-choice">
                            <legend class="sch-visually-hidden">What happens to the shifts</legend>
                            <label><input v-model="leaveChoice" type="radio" value="take_off" name="sch-leave" /> Take {{ deciding.request.guard.name }} off {{ deciding.request.shifts.length === 1 ? 'that shift and mark it' : `those ${deciding.request.shifts.length} shifts and mark them` }} "Needs a guard"</label>
                            <label><input v-model="leaveChoice" type="radio" value="keep" name="sch-leave" /> {{ deciding.request.shifts.length === 1 ? 'Leave that shift as it is, I will sort it out' : 'Leave the shifts as they are, I will sort them out' }}</label>
                        </fieldset>
                    </template>
                    <p v-else>{{ deciding.request.guard.name }} has no shifts in those days.</p>
                </template>
                <label v-else class="sch-leave-note">
                    <span>Why not? {{ deciding?.request.guard.name }} is told.</span>
                    <textarea v-model="leaveNote" rows="3" maxlength="500"></textarea>
                </label>
            </ConsoleDialog>

            <!-- The one line a move asks in (§4.3), or says why it sprang back. -->
            <Teleport to="body" :disabled="!canTeleport">
                <div v-if="move" class="sch-move" :class="{ 'sch-move-no': move.refused }" :style="moveAt" role="status" aria-live="polite">
                    <span v-if="move.refused" class="sch-move-text"><span aria-hidden="true">✗</span> {{ move.refused }}</span>
                    <span v-else class="sch-move-text">{{ move.checking ? 'Checking the move…' : move.sentence }}</span>
                    <button v-if="!move.refused" type="button" class="btn-primary-sm" :disabled="move.checking" @click="confirmMove"><span>Move</span></button>
                    <button type="button" class="btn-outline-sm" @click="move = null"><span>{{ move.refused ? 'Close' : 'Cancel' }}</span></button>
                </div>
            </Teleport>

            <div v-if="rows.length" ref="frame" class="sch-frame">
                <div class="roster-grid sch-grid">
                    <div class="roster-head-row sch-row">
                        <div>Gate</div>
                        <div v-for="day in days" :key="day.date" :class="{ 'sch-today': day.today }">
                            <span class="sch-weekday">{{ day.weekday }} {{ day.day }}</span>
                            <span v-if="day.today" class="sch-today-word">Today</span>
                        </div>
                    </div>
                    <div v-for="row in rows" :key="row.id" class="roster-row2 sch-row">
                        <div class="roster-post sch-post">
                            <span>{{ row.name }}</span>
                            <span v-if="row.also_called" class="sch-second">{{ row.also_called }}</span>
                        </div>
                        <div
                            v-for="(cell, i) in row.cells"
                            :key="i"
                            class="roster-cell sch-cell"
                            :class="{ 'sch-today-cell': days[i]?.today, 'sch-drop': dropKey === `${row.id}|${days[i].date}` }"
                            @dragover="overCell(row, days[i], $event)"
                            @dragleave="dropKey = null"
                            @drop="dropOn(row, days[i], $event)"
                        >
                            <div
                                v-for="block in cell"
                                :key="block.id"
                                class="shift-chip sch-block"
                                :class="[tone(block), { 'sch-ended': block.state === 'ended', 'sch-spring': springing === block.id }]"
                                :title="`${row.name} · ${block.time} · ${block.guard ? block.guard.name : who(block)}`"
                                :draggable="canDrag(block) ? 'true' : 'false'"
                                @dragstart="startDrag(block, row, days[i], $event)"
                                @dragend="endDrag"
                                role="button"
                                tabindex="0"
                                :aria-label="`${row.name}, ${days[i].weekday} ${days[i].day} ${days[i].month}, ${block.time}, ${block.guard ? block.guard.name : who(block)}. Open this shift.`"
                                @click="openShift(block)"
                                @keydown.enter.prevent="openShift(block)"
                                @keydown.space.prevent="openShift(block)"
                            >
                                <span class="sch-time">{{ block.time }}</span>
                                <span>{{ who(block) }}</span>
                                <span v-if="block.state === 'on_duty'" class="status-badge active">Clocked in</span>
                                <span v-if="!block.guard && block.asks && block.state !== 'ended'" class="sch-asks">{{ block.asks }} asked for this shift</span>
                            </div>
                            <button
                                v-if="canAddOn(days[i])"
                                type="button"
                                class="sch-plus"
                                :aria-label="`Add a shift at ${row.name} on ${days[i].weekday} ${days[i].day} ${days[i].month}`"
                                @click="addHere(row, days[i])"
                            >
                                +
                            </button>
                        </div>
                    </div>

                    <!-- Approved leave (§8.1): a soft band across each guard's days. Not a shift; it opens nothing. -->
                    <div v-if="leave.length" class="roster-row2 sch-row">
                        <div class="roster-post sch-post"><span>On leave</span></div>
                        <div class="sch-bands">
                            <span
                                v-for="band in leave"
                                :key="band.id"
                                class="sch-band"
                                :style="{ gridColumn: `${band.from + 1} / ${band.to + 2}` }"
                                :title="`${band.name} is on leave, ${band.days}`"
                            >
                                {{ band.short }} on leave
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="canWrite && rows.length" class="sch-copy">
                <button type="button" class="btn-outline-sm" @click="openCopy"><span>Copy last week into this week</span></button>
            </div>

            <ConsoleDialog
                :open="copyOpen"
                title="Copy last week into this week"
                :confirm-label="copyLabel"
                busy-label="Creating…"
                :busy="copying"
                :confirm-blocked-reason="copyBlocked"
                :error="copyError"
                @confirm="confirmCopy"
                @cancel="copyOpen = false"
            >
                <p v-if="!copyPlan && !copyError">Working out what the copy would do…</p>
                <template v-if="copyPlan">
                    <dl class="sch-copy-weeks">
                        <dt>From</dt>
                        <dd>{{ copyPlan.from.label }}</dd>
                        <dd>{{ shifts(copyPlan.from.shifts) }}</dd>
                        <dt>To</dt>
                        <dd>{{ copyPlan.to.label }}</dd>
                        <dd>{{ shifts(copyPlan.to.shifts) }} already there</dd>
                    </dl>
                    <ul class="sch-copy-lines">
                        <li class="sch-yes"><span aria-hidden="true">✓</span> {{ shifts(copyPlan.create) }} will be created</li>
                        <li v-if="copyPlan.create" class="sch-yes"><span aria-hidden="true">✓</span> {{ copyPlan.keep_guard }} of them keep the same guard</li>
                        <li v-if="copyPlan.was_open" class="sch-warn"><span aria-hidden="true">⚠</span> {{ copyPlan.was_open }} of them had no guard last week, and still need one</li>
                        <li v-if="copyPlan.no_guard" class="sch-warn">
                            <span aria-hidden="true">⚠</span> {{ copyPlan.no_guard }} of them come across with no guard, because:
                            <ul class="sch-copy-why">
                                <li v-for="(why, i) in copyPlan.no_guard_why" :key="i">{{ why }}</li>
                            </ul>
                        </li>
                        <li v-if="copyPlan.skipped_past" class="sch-warn"><span aria-hidden="true">⚠</span> {{ shifts(copyPlan.skipped_past) }} from last week {{ copyPlan.skipped_past === 1 ? 'is' : 'are' }} not copied: {{ copyPlan.skipped_past === 1 ? 'its day' : 'their days' }} this week {{ copyPlan.skipped_past === 1 ? 'has' : 'have' }} passed</li>
                        <li v-if="copyPlan.skipped_taken" class="sch-warn"><span aria-hidden="true">⚠</span> {{ shifts(copyPlan.skipped_taken) }} from last week {{ copyPlan.skipped_taken === 1 ? 'is' : 'are' }} not copied: {{ copyPlan.skipped_taken === 1 ? 'its gate already has' : 'their gates already have' }} a shift then</li>
                        <li v-if="copyPlan.left_alone" class="sch-warn"><span aria-hidden="true">⚠</span> {{ shifts(copyPlan.left_alone) }} already in this week {{ copyPlan.left_alone === 1 ? 'is' : 'are' }} left alone. Nothing is overwritten.</li>
                    </ul>
                </template>
            </ConsoleDialog>

            <div class="kpi-row sch-cards">
                <div v-for="card in cards" :key="card.key" class="kpi-card" :title="card.title">
                    <div class="k-val">{{ card.value }}</div>
                    <div class="k-lbl">{{ card.label }}</div>
                </div>
            </div>

            <ShiftPanel
                v-if="rows.length"
                :open="panelOpen"
                :client="client"
                :zone="zone"
                :posts="posts"
                :start="panelStart"
                :shift-id="panelShiftId"
                :can-write="canWrite"
                @close="panelOpen = false"
            />
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Layout only. Every colour is an existing token, every chip, card, grid and button the board's own; the text
 * colours are the three the console reads in (primary, body, and secondary at the lightest).
 */
.sch-top {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0 0 12px;
}

.sch-client {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-body);
}

/* `align-items`: a customisable select (`appearance: base-select`) is a flex box, and its text otherwise sits high. */
.sch-client select {
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

.sch-week {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px;
}

.sch-week-label {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
}

.sch-zone {
    margin: 6px 0 14px;
    font-size: 12.5px;
    color: var(--text-placeholder);
}

/* The grid fits at 1280 px; narrower, it scrolls inside its own card and the page never does. */
.sch-frame {
    overflow-x: auto;
    margin-bottom: 16px;
}

.sch-grid {
    min-width: 860px;
}

.sch-row {
    grid-template-columns: 140px repeat(7, minmax(0, 1fr));
}

/*
 * Today: its heading filled, and its column drawn between two lines down the grid. Not filled below the heading: the
 * day chip is navy-100, and a navy-100 column would hide every chip in it.
 */
.sch-today {
    background: var(--navy-200);
    box-shadow: inset 2px 0 0 var(--navy-300), inset -2px 0 0 var(--navy-300);
}

.sch-weekday {
    display: block;
}

.sch-today-word {
    display: block;
    font-size: 9.5px;
    color: var(--text-body);
}

.sch-today-cell {
    box-shadow: inset 2px 0 0 var(--navy-300), inset -2px 0 0 var(--navy-300);
}

.sch-post {
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 2px;
}

.sch-second {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-placeholder);
}

.sch-cell {
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    gap: 6px;
    min-height: 64px;
}

.sch-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    line-height: 1.3;
    cursor: pointer;
}

.sch-block:focus-visible {
    outline: 2px solid var(--navy-600);
    outline-offset: 2px;
}

.sch-block[draggable='true'] {
    cursor: grab;
}

/* The cell a dragged shift would land in. */
.sch-drop {
    box-shadow: inset 0 0 0 2px var(--navy-600);
}

/* A move that breaks a rule: the block springs back where it was. */
.sch-spring {
    animation: sch-spring 0.4s ease;
}

@keyframes sch-spring {
    0% {
        transform: translateX(-5px);
    }
    40% {
        transform: translateX(5px);
    }
    70% {
        transform: translateX(-2px);
    }
    100% {
        transform: none;
    }
}

/*
 * The one line a move asks in: the card style, at the foot of the window over the grid's card (its left and width are
 * the card's, measured when the block is dropped), above the page and under the shift panel and dialogs (1000).
 */
.sch-move {
    position: fixed;
    bottom: 20px;
    left: 24px;
    right: 24px;
    z-index: 50;
    box-sizing: border-box;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border: 1px solid var(--navy-200);
    border-radius: 12px;
    background: var(--white);
    /* The console's open dropdown's shadow (app.css). */
    box-shadow: 0 14px 32px rgba(11, 31, 51, 0.16);
}

.sch-move-text {
    flex: 1;
    min-width: 240px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-primary);
}

.sch-move-no .sch-move-text {
    color: var(--red-700);
}

.sch-move button {
    font-family: inherit;
    cursor: pointer;
}

@media (prefers-reduced-motion: reduce) {
    .sch-spring {
        animation: none;
    }
}

.sch-time {
    font-weight: 600;
}

/*
 * A shift that has ended stays readable, a step back: the secondary text token on a plain card, never faded. One
 * class more than the board's `.shift-chip.day` under its body class, so it is drawn over it.
 */
.sch-cell .shift-chip.sch-block.sch-ended {
    background: var(--white);
    color: var(--text-placeholder);
    border: 1px solid var(--navy-200);
}

.sch-asks {
    font-weight: 600;
    font-size: 9.5px;
}

/* The classic roster's own button declarations (Roster.vue), for the same button in the same place. */
button.btn-primary-sm {
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

a.btn-outline-sm {
    text-decoration: none;
}

/* The cell's own "+": that gate, that day. Shown on hover or focus; always, where there is no hover. */
.sch-plus {
    align-self: center;
    width: 26px;
    height: 22px;
    margin-top: auto;
    border: 1px dashed var(--navy-200);
    border-radius: 6px;
    background: var(--white);
    color: var(--text-body);
    font: inherit;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    opacity: 0;
}

.sch-cell:hover .sch-plus,
.sch-plus:focus-visible {
    opacity: 1;
}

@media (hover: none) {
    .sch-plus {
        opacity: 1;
    }
}

.sch-cards {
    margin-top: 4px;
}

/* Approved leave across the week (§8.1), in the muted style of an ended shift. */
.sch-bands {
    grid-column: 2 / 9;
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    align-content: start;
    gap: 4px 0;
    padding: 7px 0;
}

.sch-band {
    margin: 0 7px;
    padding: 5px 8px;
    border: 1px dashed var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    color: var(--text-placeholder);
    font-size: 10px;
    font-weight: 700;
    text-align: center;
}

/* Leave waiting for a decision (§8.2): the card style, above the grid. */
.sch-leave-waiting {
    margin: 0 0 12px;
    padding: 12px 14px;
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    background: var(--white);
}

.sch-leave-waiting ul {
    list-style: none;
    margin: 0;
    padding: 0;
}

.sch-h {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
}

.sch-leave-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 0;
    border-top: 1px solid var(--navy-100);
    font-size: 12.5px;
    color: var(--text-body);
}

.sch-leave-acts {
    display: flex;
    gap: 8px;
}

.sch-leave-acts button {
    font-family: inherit;
    cursor: pointer;
}

.sch-muted {
    font-size: 12px;
    color: var(--text-placeholder);
}

.sch-leave-says {
    margin: 0 0 6px;
    color: var(--text-primary);
    font-weight: 600;
}

.sch-leave-shifts {
    margin: 0 0 12px;
    padding-left: 18px;
    color: var(--text-body);
}

.sch-leave-choice {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
    padding: 0;
    border: 0;
    color: var(--text-primary);
}

.sch-leave-choice label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.sch-leave-note {
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: var(--text-body);
    font-weight: 600;
}

.sch-leave-note textarea {
    border: 1px solid var(--navy-200);
    border-radius: 10px;
    padding: 8px 10px;
    font: inherit;
    font-weight: 500;
    color: var(--text-primary);
}

.sch-visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.sch-copy {
    display: flex;
    margin: 0 0 16px;
}

.sch-copy button {
    font-family: inherit;
    cursor: pointer;
}

/* The copy's confirm panel (§4.6): the two weeks, then what will happen, a tick or a warning a line. */
.sch-copy-weeks {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 4px 14px;
    margin: 0 0 12px;
    color: var(--text-primary);
}

.sch-copy-weeks dt {
    font-weight: 700;
}

.sch-copy-weeks dd {
    margin: 0;
}

.sch-copy-lines {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: var(--text-body);
}

.sch-yes span {
    color: var(--success-700);
}

.sch-warn > span {
    color: var(--amber-700);
}

.sch-copy-why {
    list-style: none;
    margin: 4px 0 0;
    padding-left: 22px;
    color: var(--text-body);
}
</style>

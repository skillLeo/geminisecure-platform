<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import RequiredMark from '../../../Components/RequiredMark.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { submitDialog } from '../../../lib/dialogSubmit.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { duesTabs } from './tabs'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Charge schedule — board 5's "Charge schedule" tab (12 §2, item 17):
 * "recurring run with preview and reversal".
 *
 * NOT DRAWN ON ANY BOARD: board 5 names the tab. Built from board 5's sheet so
 * it reads as a tab of one module.
 *
 * A SCHEDULE IS THE STANDING DECISION, A RUN IS ONE MONTH OF IT. Each schedule
 * shows the next month it would bill — how many units, what total, due when —
 * BEFORE anything posts, and the post sends those figures back: a register that
 * changed in between is refused rather than billed on a list nobody saw.
 *
 * A WRONG MONTH IS REVERSED, NEVER DELETED. The mirror entry posts, the month's
 * charges leave the ageing, and the run keeps who reversed it and why. Every
 * household billed sees both on its statement, which is what happened.
 *
 * THE RATE CHANGES FROM A MONTH, AND THE PANEL SAYS WHAT IT REACHES BEFORE THE
 * PRESS (M10 §3). A change picks the first month it bills, from the next unposted
 * one onward; months already posted keep the rate they were raised at. The
 * sentence under the fields ("Applies from the November 2026 charge run. 3 units
 * on this schedule. October 2026's charges, already posted, are not changed.")
 * comes from the server for each month offered, so the page never works out on
 * its own what a change touches. The page does only the one sum a new figure
 * needs: units × the amount typed.
 *
 * TWO APPROVALS, TWO FLAGS (M11 B3, PRICE-02). `canSetRates` opens New
 * schedule, Change rate and Stop schedule; `canReverse` opens Reverse on a posted
 * month. They are separate permissions because setting a rate must not carry the
 * power to rewrite a month already billed, and each control says which one it
 * lacks.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    schedules: { type: Array, required: true },
    runs: { type: Array, required: true },
    /** The posted months as the shared table reads them (M32 F5): the newest month first, a page at a time. */
    table: { type: Object, required: true },
    incomeAccounts: { type: Array, required: true },
    phases: { type: Array, required: true },
    canPost: { type: Boolean, required: true },
    canSetRates: { type: Boolean, required: true },
    canReverse: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

const state = useScreenState({
    rows: () => props.schedules.length + props.runs.length,
})

const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

const subnav = computed(() => duesTabs(financePath, 'schedule'))

const RUN_COLUMNS = [
    { key: 'period', label: 'Period', sortable: true },
    { key: 'charge', label: 'Charge', sortable: true },
    { key: 'due', label: 'Due', sortable: true },
    { key: 'units', label: 'Units', sortable: true },
    { key: 'total', label: 'Total', sortable: true, cellClass: 'bal-amt' },
    { key: 'journal_ref', label: 'Entry' },
    { key: 'posted', label: 'Posted', sortable: true },
    { key: 'action', label: '' },
]

const runAttrs = (run) => ({ class: { 'cs-reversed': run.reversed } })

/* ---- defining a schedule ---- */
const defining = ref(false)

const scheduleForm = useForm({
    description: 'Maintenance fee',
    amount: '',
    scope: 'estate',
    phase: props.phases[0] ?? '',
    due_day: 1,
    account_code: props.incomeAccounts[0]?.code ?? '4000',
})

/* In the console's modal since M95 §1: it opened as a panel above the schedules. */
const openDefining = () => {
    if (!props.canSetRates) {
        return
    }

    scheduleForm.clearErrors()
    defining.value = true
}

// From the dialog through the one save (lib/dialogSubmit.js): a refusal keeps it open with what was typed.
const defineSchedule = () =>
    submitDialog(scheduleForm, 'post', financePath('/charge-schedule'), {
        onSuccess: () => {
            defining.value = false
            scheduleForm.reset()
        },
    })


/* ---- posting a month, against its preview ---- */
const posting = ref(null)

/*
 * POSTING A MONTH ASKS FIRST (EST-S-03, M83 §1.2 item 8). A month bills every unit
 * on the schedule as one entry, and it posted on one click. The press now asks,
 * naming the month, the units and the total; only the question's own Post sends it.
 * The question is the console's dialog since M97 (it was a strip beside the button).
 */
const confirming = ref(null)

const units = (count) => Number(count).toLocaleString('en-US')

const confirmingSchedule = computed(() => props.schedules.find((schedule) => schedule.id === confirming.value) ?? null)

/*
 * A REFUSAL STAYS IN ITS QUESTION (Hassam's refusal rule, D-235; seen on live 12 Oct: Reverse with a seven-character
 * reason was refused in a toast while its dialog stood open saying nothing). Each of the three questions below keeps the
 * server's sentence in its own amber band and stays open; only a press the server took closes it.
 */
const postRefusal = ref(null)
const stopRefusal = ref(null)
const reverseRefusal = ref(null)
const firstRefusal = (errors, fallback) => Object.values(errors)[0] ?? fallback

const postRun = (schedule) => {
    posting.value = schedule.id
    postRefusal.value = null

    router.post(
        financePath(`/charge-schedule/${schedule.id}/runs`),
        {
            period: schedule.preview.period,
            expected_count: schedule.preview.count,
            expected_total_minor: schedule.preview.total_minor,
        },
        {
            preserveScroll: true,
            onSuccess: () => (confirming.value = null),
            onError: (errors) => (postRefusal.value = firstRefusal(errors, 'The month was not posted.')),
            onFinish: () => (posting.value = null),
        }
    )
}

/*
 * STOPPING A SCHEDULE ASKS FIRST (M97 pass). It stopped the estate's billing on one click, by a small link beside the
 * schedule's name, with nothing said. Now the console's dialog names it and says what stops and what stands.
 */
const stopping = ref(null)
const stopBusy = ref(false)

const stoppingSchedule = computed(() => props.schedules.find((schedule) => schedule.id === stopping.value) ?? null)

const stop = (schedule) => {
    stopBusy.value = true
    stopRefusal.value = null

    router.post(financePath(`/charge-schedule/${schedule.id}/stop`), {}, {
        preserveScroll: true,
        onSuccess: () => (stopping.value = null),
        onError: (errors) => (stopRefusal.value = firstRefusal(errors, 'The schedule was not stopped.')),
        onFinish: () => (stopBusy.value = false),
    })
}

/* ---- changing the rate, from a month not yet billed ---- */
const rating = ref(null)

const rateForm = useForm({
    amount: '',
    effective_period: '',
    reason: '',
})

/** The schedule whose rate is being changed, so the modal can name it. */
const ratingSchedule = computed(() => props.schedules.find((schedule) => schedule.id === rating.value) ?? null)

const openRate = (schedule) => {
    if (!props.canSetRates) {
        return
    }

    rateForm.clearErrors()
    rateForm.amount = schedule.rate_input
    rateForm.effective_period = schedule.rate_options[0]?.period ?? schedule.next_period
    rateForm.reason = ''
    rating.value = schedule.id
}

const chosenMonth = (schedule) =>
    schedule.rate_options.find((option) => option.period === rateForm.effective_period) ?? schedule.rate_options[0]

const money = (value) =>
    `J$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/**
 * The new figures, before the press: what one unit and the whole schedule would
 * be billed a month. Nothing else is computed here; what the change reaches and
 * leaves alone is the server's sentence, `effect`.
 */
const rateReadBack = (schedule) => {
    const month = chosenMonth(schedule)
    const amount = Number(rateForm.amount)

    if (!month || !(amount > 0)) {
        return 'Type the new amount a unit is billed each month.'
    }

    if (Math.round(amount * 100) === month.current_minor) {
        return `${month.current} a unit is already the rate for ${month.label}. Nothing would change.`
    }

    // In words, with no em dash and no arrow (M53 Law 3; M94 §1.5): what was typed, read back exactly.
    const bills = schedule.units > 0 ? `: ${schedule.units} × ${money(amount)} = ${money(amount * schedule.units)} a month` : ''

    return `${month.current} becomes ${money(amount)} a unit from ${month.label}${bills}.`
}

/** Why Save rate change cannot be pressed yet, said under it (M96 level 2). */
const rateBlocked = (schedule) => (schedule === null || rateReady(schedule) ? null : 'Type a new amount and say why, in at least ten characters.')

const rateReady = (schedule) => {
    const month = chosenMonth(schedule)
    const amount = Number(rateForm.amount)

    return !!month && amount > 0 && Math.round(amount * 100) !== month.current_minor && rateForm.reason.trim().length >= 10
}

const amendRate = (schedule) =>
    submitDialog(rateForm, 'post', financePath(`/charge-schedule/${schedule.id}/rate`), {
        onSuccess: () => (rating.value = null),
    })

/* ---- reversing a month: in the console's dialog since M97, with its reason ---- */
const reversing = ref(null)
const reason = ref('')

const reversingRun = computed(() => props.runs.find((run) => run.id === reversing.value) ?? null)

/*
 * THE RUN BEING REVERSED RIGHT NOW, if one is (bug hunt B020). Reversing a
 * 450-unit month takes over a second, and "Confirm reversal" stayed live while
 * it ran: a second press posted a second mirror entry and credited every
 * household twice. The server now decides under a lock and refuses the second;
 * this keeps the press from being sent at all.
 */
const reverseInFlight = ref(null)

const reverse = (run) => {
    // The server refuses all three too; this keeps a press it would bounce from being sent at all.
    if (!props.canReverse || !run.reversible || reverseInFlight.value !== null) {
        return
    }

    if (reversing.value !== run.id) {
        reversing.value = run.id
        reason.value = ''
        reverseRefusal.value = null

        return
    }

    reverseInFlight.value = run.id
    reverseRefusal.value = null

    router.post(
        financePath(`/charge-runs/${run.id}/reverse`),
        { reason: reason.value },
        {
            preserveScroll: true,
            onSuccess: () => (reversing.value = null),
            onError: (errors) => (reverseRefusal.value = firstRefusal(errors, 'The month was not reversed.')),
            onFinish: () => (reverseInFlight.value = null),
        }
    )
}

/* The server takes a reason of ten characters or more (ChargeSchedules::reverse); the button says so before the press. */
const REVERSE_REASON_MIN = 10

const reverseBlocked = computed(() => {
    const typed = reason.value.trim().length

    if (typed === 0) {
        return 'Say why this month is wrong.'
    }

    return typed < REVERSE_REASON_MIN ? `Say a little more: at least ${REVERSE_REASON_MIN} characters (${typed} so far).` : null
})

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['dues'] })
</script>

<template>
    <Head title="Charge schedule" />

    <EstateConsole title="Charge schedule" :estate-name="estate.name" active="dues_ledger">
        <template #actions>
            <!-- M96 level 3: a role that cannot set what households are billed has no New schedule at all. -->
            <button
                v-if="canSetRates"
                type="button"
                class="btn-outline-sm"
                title="Define a recurring charge. Nothing is billed until a month is posted."
                @click="openDefining"
            >
                <span>New schedule</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <div v-if="item.active" class="subnav-item active" aria-current="page">{{ item.label }}</div>
                <Link v-else :href="item.href" class="subnav-item">{{ item.label }}</Link>
            </template>
        </div>

        <!--
          A new schedule, in the console's modal (M95 §1). Each refusal sits under its own field; one about the
          schedule as a whole sits in the modal. Every other press's refusal is a toast (M95 §2).
        -->
        <ConsoleDialog
            :open="defining"
            title="New charge schedule"
            size="form"
            confirm-label="Save schedule"
            busy-label="Saving…"
            :busy="scheduleForm.processing"
            :dirty="scheduleForm.isDirty"
            discard-title="Discard this schedule?"
            :confirm-blocked-reason="!(Number(scheduleForm.amount) > 0) ? 'Type what each unit is billed.' : null"
            :error="scheduleForm.errors.schedule ?? null"
            @confirm="defineSchedule"
            @cancel="defining = false"
        >
            <p class="cs-head">
                A schedule is the standing decision: what every unit in its scope is billed, and on which day. Nothing is
                billed until a month is posted from its preview.
            </p>
            <div class="cs-fields">
                <div class="cs-field">
                    <label for="cs-desc">Charge</label>
                    <input id="cs-desc" v-model="scheduleForm.description" type="text" maxlength="120" required />
                    <div v-if="scheduleForm.errors.description" class="cs-error">{{ scheduleForm.errors.description }}</div>
                </div>
                <div class="cs-field">
                    <label for="cs-amount">Amount per unit (J$)</label>
                    <input id="cs-amount" v-model="scheduleForm.amount" type="number" min="0.01" step="0.01" required />
                    <div v-if="scheduleForm.errors.amount" class="cs-error">{{ scheduleForm.errors.amount }}</div>
                </div>
                <div class="cs-field">
                    <label for="cs-scope">Who is billed</label>
                    <select id="cs-scope" v-model="scheduleForm.scope">
                        <option value="estate">Every unit in the estate</option>
                        <option value="phase">Every unit in one phase</option>
                    </select>
                    <div v-if="scheduleForm.errors.scope" class="cs-error">{{ scheduleForm.errors.scope }}</div>
                </div>
                <div v-if="scheduleForm.scope === 'phase'" class="cs-field">
                    <label for="cs-phase">Phase</label>
                    <select id="cs-phase" v-model="scheduleForm.phase">
                        <option v-for="phase in phases" :key="phase" :value="phase">{{ phase }}</option>
                    </select>
                    <div v-if="scheduleForm.errors.phase" class="cs-error">{{ scheduleForm.errors.phase }}</div>
                </div>
                <div class="cs-field">
                    <label for="cs-day">Due on day</label>
                    <input id="cs-day" v-model.number="scheduleForm.due_day" type="number" min="1" max="28" required />
                    <div v-if="scheduleForm.errors.due_day" class="cs-error">{{ scheduleForm.errors.due_day }}</div>
                </div>
                <div class="cs-field">
                    <label for="cs-account">Income account</label>
                    <select id="cs-account" v-model="scheduleForm.account_code">
                        <option v-for="account in incomeAccounts" :key="account.code" :value="account.code">
                            {{ account.label }}
                        </option>
                    </select>
                    <div v-if="scheduleForm.errors.account_code" class="cs-error">{{ scheduleForm.errors.account_code }}</div>
                </div>
            </div>
        </ConsoleDialog>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <!-- The fifth state: it had none, so a failed read drew an empty schedule as if nothing were set up. -->
        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The charge schedule could not be loaded"
            body="Nothing has been billed or changed. Reading it again is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The charge schedule is not part of your role's access"
            body="A schedule decides what every household is billed, so only roles that handle dues can open it. Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="No recurring charge is scheduled"
            body="The maintenance fee is set up here once (amount, scope and due day), and each month is then posted from a preview of exactly what it will bill."
        />

        <template v-else>
            <div v-for="schedule in schedules" :key="schedule.id" class="cs-panel">
                <div class="cs-top">
                    <div>
                        <div class="cs-title">{{ schedule.description }}</div>
                        <div class="cs-sub">
                            {{ schedule.amount }} a unit · {{ schedule.scope }} · due on day {{ schedule.due_day }} · income to
                            {{ schedule.account }}
                        </div>
                    </div>
                    <!-- M96 level 3: no Change rate or Stop schedule for a role that cannot set what households are billed. -->
                    <div v-if="schedule.is_active && canSetRates" class="cs-controls">
                        <button
                            type="button"
                            class="text-link-sm"
                            :title="`Change what a unit is billed, from ${schedule.next_label} or later. Months already posted keep their rate.`"
                            @click="openRate(schedule)"
                        >
                            Change rate
                        </button>
                        <button
                            type="button"
                            class="text-link-sm"
                            title="Stop billing from this schedule. Posted months stand."
                            @click="stopping = schedule.id"
                        >
                            Stop schedule
                        </button>
                    </div>
                    <!-- M96: a stopped schedule's state in the one vocabulary, as a badge. -->
                    <span v-else-if="!schedule.is_active" class="cs-stopped" title="Stopped: this schedule bills nobody now.">Inactive</span>
                </div>

                <!-- Changes saved and waiting for their month, in words (no arrow, no em dash). -->
                <p v-for="pending in schedule.pending_rates" :key="pending.period" class="cs-pending">
                    From {{ pending.label }}: {{ pending.from }} becomes {{ pending.to }} a unit<template v-if="pending.bills">, {{ pending.bills }} a month</template>.
                    {{ pending.reason }}<template v-if="pending.by"> ({{ pending.by }})</template>
                </p>

                <div v-if="schedule.preview && schedule.is_active" class="cs-preview">
                    <div>
                        <div class="cs-preview-head">Next: {{ schedule.preview.label }}</div>
                        <div class="cs-sub">
                            {{ units(schedule.preview.count) }} units × {{ schedule.preview.rate }} · {{ schedule.preview.total }} · due
                            {{ schedule.preview.due_label }}
                        </div>
                    </div>
                    <!-- M96 level 3: no Post for a role that cannot post a month; the preview still says what it would bill. -->
                    <button
                        v-if="canPost"
                        type="button"
                        class="btn-primary-sm"
                        :title="`Post ${schedule.preview.label}: bill these ${schedule.preview.count} units, as one entry.`"
                        @click="confirming = schedule.id"
                    >
                        <span>Post {{ schedule.preview.label }}</span>
                    </button>
                </div>
                <p v-else class="cs-sub">{{ schedule.blocked }}</p>
            </div>

            <div class="cs-section">Posted months</div>

            <p v-if="table.total === 0" class="cs-sub">No month has been posted from a schedule yet.</p>

            <DataTable v-else :columns="RUN_COLUMNS" :rows="runs" :table="table" noun="posted months" :row-attrs="runAttrs">
                <template #cell-charge="{ row: run }">{{ run.schedule }}</template>
                <template #cell-due="{ row: run }">{{ run.due_on }}</template>
                <template #cell-units="{ row: run }">{{ units(run.units) }}</template>
                <template #cell-posted="{ row: run }">
                    {{ run.posted }}
                    <div v-if="run.reversal" class="cs-sub">{{ run.reversal }}</div>
                </template>
                <template #cell-action="{ row: run }">
                    <!--
                      LATEST FIRST (bug hunt B021). Only the latest month
                      standing on a schedule can be reversed: taking one out
                      from under a later month left a month the schedule
                      never billed again. The others say why on the control.
                    -->
                    <!--
                      M96: no Reverse for a role that cannot reverse a month (level 3); a month that is not the latest is
                      greyed with why under it on the screen (level 2). The reason is asked in the console's dialog.
                    -->
                    <span v-if="!run.reversed && canReverse" class="cs-reverse">
                        <button
                            type="button"
                            class="text-link-sm"
                            :disabled="!run.reversible || reverseInFlight !== null"
                            :title="run.reverse_blocked ?? 'Take this whole month back off every household it billed.'"
                            @click="reverse(run)"
                        >
                            {{ reverseInFlight === run.id ? 'Reversing…' : 'Reverse' }}
                        </button>
                        <span v-if="!run.reversible && run.reverse_blocked" class="row-why">{{ run.reverse_blocked }}</span>
                    </span>
                </template>
            </DataTable>
        </template>

        <!--
          THE RATE CHANGE, in the console's modal (M95 §1). The month list starts at the next unposted month, so a month
          already billed is not offered at all; the server refuses one anyway. The read-back is what was typed; the line
          under it is the server's own account of what the change reaches and from when.
        -->
        <ConsoleDialog
            :open="ratingSchedule !== null"
            :title="ratingSchedule ? `Change the rate of ${ratingSchedule.description}` : ''"
            size="form"
            confirm-label="Save rate change"
            busy-label="Saving…"
            :busy="rateForm.processing"
            :dirty="rateForm.isDirty"
            discard-title="Discard this rate change?"
            :confirm-blocked-reason="rateBlocked(ratingSchedule)"
            :error="rateForm.errors.rate ?? null"
            @confirm="ratingSchedule && amendRate(ratingSchedule)"
            @cancel="rating = null"
        >
            <template v-if="ratingSchedule">
                <div class="cs-fields">
                    <div class="cs-field">
                        <label for="cs-rate-amount">New amount per unit (J$)</label>
                        <input id="cs-rate-amount" v-model="rateForm.amount" type="number" min="0.01" step="0.01" required />
                        <div v-if="rateForm.errors.amount" class="cs-error">{{ rateForm.errors.amount }}</div>
                    </div>
                    <div class="cs-field">
                        <label for="cs-rate-month">First month billed at it</label>
                        <select id="cs-rate-month" v-model="rateForm.effective_period">
                            <option v-for="option in ratingSchedule.rate_options" :key="option.period" :value="option.period">
                                {{ option.label }}, now {{ option.current }}
                            </option>
                        </select>
                        <div v-if="rateForm.errors.effective_period" class="cs-error">{{ rateForm.errors.effective_period }}</div>
                    </div>
                    <div class="cs-field cs-field--wide">
                        <label for="cs-rate-reason">Why: printed on the record</label>
                        <input id="cs-rate-reason" v-model="rateForm.reason" type="text" maxlength="300" placeholder="AGM of 20 September voted the new fee" required />
                        <div v-if="rateForm.errors.reason" class="cs-error">{{ rateForm.errors.reason }}</div>
                    </div>
                </div>
                <p class="cs-read">{{ rateReadBack(ratingSchedule) }}</p>
                <p class="cs-sub">{{ chosenMonth(ratingSchedule)?.effect }}</p>
            </template>
        </ConsoleDialog>

        <!-- POSTING A MONTH ASKS FIRST (EST-S-03), naming the month, the units and the total. -->
        <ConsoleDialog
            :open="confirmingSchedule !== null"
            :title="confirmingSchedule?.preview ? `Post ${confirmingSchedule.preview.label}?` : ''"
            confirm-label="Post"
            busy-label="Posting…"
            cancel-label="Not now"
            :busy="posting !== null"
            :error="postRefusal"
            @confirm="confirmingSchedule && postRun(confirmingSchedule)"
            @cancel="confirming = null; postRefusal = null"
        >
            <p v-if="confirmingSchedule?.preview" style="margin: 0">
                {{ units(confirmingSchedule.preview.count) }} units × {{ confirmingSchedule.preview.rate }} =
                {{ confirmingSchedule.preview.total }}, due {{ confirmingSchedule.preview.due_label }}. It posts to the ledger
                as one entry, and every household on the schedule is billed.
            </p>
        </ConsoleDialog>

        <!-- STOPPING A SCHEDULE ASKS FIRST (M97 pass). -->
        <ConsoleDialog
            :open="stoppingSchedule !== null"
            :title="stoppingSchedule ? `Stop ${stoppingSchedule.description}?` : ''"
            tone="danger"
            confirm-label="Stop the schedule"
            busy-label="Stopping…"
            cancel-label="Keep billing"
            :busy="stopBusy"
            :error="stopRefusal"
            @confirm="stoppingSchedule && stop(stoppingSchedule)"
            @cancel="stopping = null; stopRefusal = null"
        >
            <p style="margin: 0">No further month is billed from it. Every month already posted stands.</p>
        </ConsoleDialog>

        <!-- REVERSING A MONTH ASKS WHY, in the console's dialog: it was a box that opened inside the table's row. -->
        <ConsoleDialog
            :open="reversingRun !== null"
            :title="reversingRun ? `Reverse ${reversingRun.period}?` : ''"
            tone="danger"
            size="form"
            confirm-label="Reverse the month"
            busy-label="Reversing…"
            cancel-label="Keep it"
            :busy="reverseInFlight !== null"
            :dirty="reason.trim() !== ''"
            discard-title="Discard the reason you typed?"
            :error="reverseRefusal"
            :confirm-blocked-reason="reverseBlocked"
            @confirm="reversingRun && reverse(reversingRun)"
            @cancel="reversing = null; reverseRefusal = null"
        >
            <p style="margin: 0 0 10px">
                The mirror entry posts, the month's charges leave the ageing, and it is the next month this schedule bills.
            </p>
            <div class="cs-field">
                <label for="cs-reverse-reason">Why is this month wrong? <RequiredMark /></label>
                <input
                    id="cs-reverse-reason"
                    v-model="reason"
                    class="cs-reason"
                    type="text"
                    required
                    :minlength="REVERSE_REASON_MIN"
                    maxlength="300"
                    placeholder="e.g. Posted twice by mistake"
                />
                <div class="cs-hint">At least {{ REVERSE_REASON_MIN }} characters. Every household billed sees it beside the reversal.</div>
            </div>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal for the sheet's tabs and buttons, then AUTHORED: the schedule
 * panels, preview strip and notices, kept to the tokens the boards define.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/* A Reverse that cannot be pressed yet looks it (M53 Law 1): grey, not the live link's colour; why is beside it. */
button.text-link-sm[disabled] {
    color: var(--slate-500);
}

.cs-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.cs-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 820px;
}

.cs-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.cs-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.cs-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.cs-field input,
.cs-field select,
.cs-reason {
    height: 32px;
    border: 1px solid var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    padding: 0 9px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.cs-reason {
    margin-bottom: 4px;
    width: 100%;
}

.cs-hint {
    font-size: 11.5px;
    line-height: 1.45;
    color: var(--slate-600);
}

/* In the modals: the lead above the fields, a wide field across the grid, one column on a phone. */
.cs-head {
    margin: 0 0 12px;
}

.cs-field--wide {
    grid-column: 1 / -1;
}

@media (max-width: 640px) {
    .cs-fields {
        grid-template-columns: 1fr;
    }
}

/* M96 level 2: why Reverse is greyed on a month, under it on the screen. */
.cs-reverse {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
}

.row-why {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-500);
    max-width: 220px;
    line-height: 1.4;
}

/* A stopped schedule is a record no longer in use: Inactive (Hassam's words, 11 October), drawn as the boards draw a state. */
.cs-stopped {
    padding: 3px 9px;
    border: 1px solid var(--navy-200);
    border-radius: 20px;
}

.cs-actions,
.cs-top,
.cs-preview {
    display: flex;
    align-items: center;
    gap: 14px;
}

.cs-top,
.cs-preview {
    justify-content: space-between;
}

.cs-preview {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 12px;
}

.cs-confirm {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.cs-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.cs-preview-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
}

.cs-sub {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
}

.cs-stopped {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-600);
}

.cs-controls {
    display: flex;
    align-items: center;
    gap: 14px;
}

.cs-rate {
    display: flex;
    flex-direction: column;
    gap: 10px;
    border-top: 1px solid var(--navy-100);
    padding-top: 10px;
}

.cs-read {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 9px 12px;
}

.cs-pending {
    font-size: 11px;
    color: var(--navy-800);
    line-height: 1.6;
    margin: 0;
    border-left: 3px solid var(--navy-200);
    padding-left: 9px;
}

.cs-section {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 18px 0 8px;
}

/* The row is the shared table's, so the rule reaches into it. */
:deep(.cs-reversed) td {
    color: var(--slate-500);
}

/* A field's refusal, under its field, in amber (Hassam's three weights: solid red is an open panic alert only). */
.cs-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
    background: var(--amber-100);
    color: var(--amber-700);
}
</style>

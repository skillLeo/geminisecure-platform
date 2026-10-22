<script setup>
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { toast } from '../../../lib/toasts.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Pre-run exceptions — board screen community-admin-14.
 *
 * FOUR BUTTONS, TWO OUTCOMES, AND THE DIFFERENCE IS SOMEBODY'S WAGES. "Enter
 * manually" and "Approve as-is" RESOLVE an exception, leaving the employee in
 * the run. "Exclude" leaves them OUT of it entirely — no payslip, no gross, no
 * net — and that is not the same act however similar the two buttons look. A
 * run that treated exclusion as resolution would pay somebody nothing and
 * report itself complete. `PayrollException` keeps them as separate states and
 * `EstatePayrollTest` asserts they are.
 *
 * "REVIEW HOURS" AND "ENTER MANUALLY" ARE NOT DRAWN (M96 §1: a control nobody can
 * ever press is level 3, and level 3 is not there). Both need a timesheet this
 * platform does not hold. What a manager can do today is decide: accept the hours
 * as logged, or take the person out of the run, and both of those write.
 *
 * THE BANNER COUNTS WHAT IS STILL BLOCKING, not what is on the screen. A run
 * whose last exception was just resolved says so without a reload, which is why
 * the count comes from the server on every render rather than from the row list.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    run: { type: Object, required: true },
    blocking: { type: Number, required: true },
    rows: { type: Array, required: true },
    canCalculate: { type: Boolean, required: true },
    calculateReason: { type: String, default: null },
    /** Calculate needs Payroll update, the route's own gate: a role without it sees no such button (M96 level 3). */
    canCalculateRuns: { type: Boolean, default: false },
    /** M94 §1.1: said before the press, when calculating would leave nobody to approve the run. */
    calculateWarning: { type: String, default: null },
    canResolve: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

// An exception raised or cleared elsewhere moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['staff'] })

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/payroll')[0])
const busy = ref(null)

/**
 * The two acts that actually write, and which button is which.
 *
 * `accept` resolves the exception into the run — the hours stand as logged.
 * `exclude` takes the employee out of it. Both carry a note saying who decided
 * and why, because the reason a technician's 22 overtime hours went through
 * unchallenged is exactly what somebody asks about six months later.
 */
const decide = (row, outcome, note) => {
    busy.value = row.id

    router.post(
        `${base.value}/payroll/exceptions/${row.id}`,
        { outcome, note },
        {
            preserveScroll: true,
            // M97 pass: a refusal here was drawn nowhere, so a refused press looked like a dead button. A toast now.
            onError: (errors) => toast(Object.values(errors)[0] ?? 'The exception was not settled.', { tone: 'error' }),
            onFinish: () => (busy.value = null),
        },
    )
}

/* Calculate, and its refusal said as a toast: like a decision's, it was drawn nowhere on this page. */
const calculate = () => {
    router.post(`${base.value}/payroll/runs/${props.run.slug}/calculate`, {}, {
        preserveScroll: true,
        onError: (errors) => toast(errors.run ?? Object.values(errors)[0] ?? 'The run was not calculated.', { tone: 'error' }),
    })
}

/** Whether this particular button does anything, and what to say if it does not. */
const actionState = (row, action) => {
    if (row.resolved) {
        return { live: false, reason: 'Already resolved.' }
    }

    if (!props.canResolve) {
        return { live: false, reason: props.reasons.resolve }
    }

    if (action.key === 'enter' || action.key === 'review') {
        return {
            live: false,
            reason: 'Both of these need the timesheet itself, and this platform records no hours: staff are '
                + 'paid a monthly rate. What you can decide is whether the run proceeds with this person in it.',
        }
    }

    return { live: true, reason: null }
}

/** The actions a row can ever take: the two that need a timesheet are never drawn (M96 level 3). */
const liveActions = (row) => row.actions.filter((action) => action.key !== 'enter' && action.key !== 'review')

/*
 * EXCLUDING ASKS FIRST (the payroll pass, 11 Oct). It acted on one click: a person taken out of this month's pay, with
 * no payslip, no gross and no net, and nothing on this screen puts them back. The console's dialog names the person
 * and the month; Approve as-is, which keeps them in, stays one click.
 */
const excluding = ref(null)

const runAction = (row, action) => {
    if (action.key === 'exclude') {
        excluding.value = row

        return
    }

    decide(row, 'resolved', 'Hours accepted as logged.')
}

const confirmExclude = () => {
    const row = excluding.value

    if (row === null) {
        return
    }

    excluding.value = null
    decide(row, 'excluded', 'Excluded from this run at the manager’s decision.')
}

/** The person an exception is about: its title is "Name · what is wrong". */
const personOf = (row) => row.title.split(' · ')[0]

/* A read that failed is read again in place; nothing on this screen writes by reloading. */
const retry = () => router.reload({ preserveScroll: true })

/*
 * A RUN WITH NO EXCEPTIONS, SAID FOR ITS OWN STATE (Residents' check, 12 Oct). It said "The run can be calculated." on
 * every such run, a paid one included, right above "This run has already been paid". A paid run says it was paid, a
 * calculated one that it waits for its approver, and only a run still to be calculated says it can be.
 */
const emptyLine = computed(() => {
    if (props.run.status === 'paid') {
        return { title: 'No exceptions were raised on this run', body: 'Nothing held it up, and it has been paid.' }
    }

    if (props.run.status === 'calculated') {
        return { title: 'Nothing is holding this run up', body: 'Every timesheet is in and no figure looks unusual. The run has been calculated and waits for its approver.' }
    }

    return { title: 'Nothing is holding this run up', body: 'Every timesheet is in and no figure looks unusual. The run can be calculated.' }
})
</script>

<template>
    <Head :title="`${run.period} exceptions`" />

    <!-- The way back, in words (M95 §3): it was a bare arrow with its words only on hover. -->
    <EstateConsole :title="`${run.period} exceptions`" :estate-name="estate.name" active="payroll" :back="{ href: `${base}/payroll`, label: 'Back to pay runs' }">

        <!-- Sent back with Request changes: what was asked, for whoever calculates it again (the way out's middle step). -->
        <div v-if="run.sent_back_reason && !state.isLoading.value && !state.isDenied.value && !state.isError.value" class="exc-banner">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 3.5 22 20H2z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                <path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
            </svg>
            <div>
                <div class="eb1">Sent back for changes</div>
                <div class="eb2">What needs changing: {{ run.sent_back_reason }} Put it right, then calculate the run again.</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="2" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="An exception names an employee and what is unresolved about their pay, so it opens only to a role that holds Payroll."
        />

        <!-- The fifth state (rule 19): a read that failed says so, and that trying again is safe. -->
        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The exceptions could not be read"
            body="Nothing was settled or calculated. Reading them again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="emptyLine.title"
            :body="emptyLine.body"
        />

        <template v-else>
            <div v-if="blocking > 0" class="exc-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 3.5 22 20H2z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                    <path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                </svg>
                <div>
                    <div class="eb1">
                        {{ blocking }} exception{{ blocking === 1 ? '' : 's' }} to settle before calculating
                    </div>
                    <div class="eb2">
                        This run can’t proceed to calculation until each item below is resolved or the employee is
                        excluded.
                    </div>
                </div>
            </div>

            <div class="exc-card">
                <div v-for="row in rows" :key="row.id" class="exc-row">
                    <div class="exc-icon">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </div>

                    <div class="exc-txt">
                        <div class="ex1">{{ row.title }}</div>
                        <div class="ex2">{{ row.resolved ? row.resolution_note ?? row.detail : row.detail }}</div>
                    </div>

                    <div class="exc-actions">
                        <!-- M96 level 3: a settled exception shows that it is settled; a role that cannot settle one sees its state. -->
                        <span v-if="row.resolved" class="status-badge paid">Resolved</span>
                        <span v-else-if="!canResolve" class="status-badge exceptions">Action required</span>
                        <template v-else>
                            <button
                                v-for="action in liveActions(row)"
                                :key="action.key"
                                type="button"
                                class="exc-btn"
                                :class="action.primary ? 'resolve' : 'exclude'"
                                :disabled="!actionState(row, action).live || busy === row.id"
                                :title="actionState(row, action).reason ?? undefined"
                                @click="runAction(row, action)"
                            >
                                {{ action.label }}
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <!--
          M96: Calculate is drawn only for a role that holds Payroll update (level 3 otherwise); held back by an unsettled
          exception it is greyed with the reason on the screen (level 2); and when calculating would leave nobody to
          approve the run, that is said here before the press (M94 §1.1, item 2). Outside the list, because a run with
          no exceptions at all is exactly the one to calculate: the empty state said so and had no button.
        -->
        <!-- M96 level 3: a paid run is calculated once, so it shows that, never a greyed Calculate (the payroll pass). -->
        <p v-if="run.status === 'paid' && !state.isLoading.value && !state.isDenied.value && !state.isError.value" class="exc-why">
            {{ calculateReason }}
        </p>
        <template v-else-if="!state.isLoading.value && !state.isDenied.value && !state.isError.value && canCalculateRuns">
            <p v-if="!canCalculate && calculateReason" class="exc-why">{{ calculateReason }}</p>
            <p v-else-if="calculateWarning" class="exc-why exc-warn" role="note">{{ calculateWarning }}</p>
            <div class="exc-foot">
                <button
                    type="button"
                    class="btn-outline-sm"
                    :disabled="!canCalculate"
                    :title="calculateReason ?? undefined"
                    @click="calculate"
                >
                    <span>Continue to calculation</span>
                </button>
            </div>
        </template>

        <!-- Excluding asks first, naming the person and the month (the payroll pass). -->
        <ConsoleDialog
            :open="excluding !== null"
            :title="excluding ? `Exclude ${personOf(excluding)} from ${run.period}?` : ''"
            tone="danger"
            confirm-label="Exclude them"
            cancel-label="Keep them in"
            @confirm="confirmExclude"
            @cancel="excluding = null"
        >
            <p style="margin: 0">
                They get no payslip for {{ run.period }}: no gross, no deductions and no net pay. Nothing on this screen
                puts them back once they are excluded.
            </p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/* Why Calculate is held back, or what pressing it would leave behind (M96 level 2, M94 §1.1). */
.exc-why {
    margin: 16px 0 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--slate-600);
    text-align: right;
}

.exc-warn {
    color: var(--amber-700);
    font-weight: 600;
}

/*
 * Default-removal only. The board draws its back affordance, its four row pills
 * and its footer control as <div>s. `.exc-btn` and `.btn-outline-sm` declare
 * their own faces, and `.btn-outline-sm`'s border IS its variant, so it keeps
 * it — see D-045.
 */
button.exc-btn {
    border: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.exc-btn[disabled],
button.btn-outline-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.5;
}

/*
 * AUTHORED BELOW THIS LINE. The board styles this card and its footer inline
 * rather than with named classes, so the same values are declared here.
 */
.exc-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.exc-foot {
    display: flex;
    justify-content: flex-end;
    margin-top: 18px;
}

/*
 * AMBER, NOT RED (the universal order, rule 20: saturated red is for panic and life safety; an exception to settle is
 * an ordinary warning). The board's sheet draws the banner and the row icons red; this page overrides them, the class
 * doubled so it outranks the sheet's selector.
 */
.exc-banner.exc-banner {
    background: var(--amber-100);
    border-color: rgba(180, 83, 9, 0.25);
}

.exc-banner.exc-banner svg,
.exc-banner.exc-banner .eb1,
.exc-banner.exc-banner .eb2 {
    color: var(--amber-700);
}

.exc-icon.exc-icon {
    background: var(--amber-100);
}

.exc-icon.exc-icon svg {
    color: var(--amber-700);
}
</style>

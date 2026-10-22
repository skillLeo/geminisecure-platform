<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Pay runs — board screen community-admin-13.
 *
 * THE GROSS AND NET COLUMNS ARE THE RUN'S OWN LINES ADDED UP, computed
 * server-side and passed here formatted. Nothing on this page does arithmetic;
 * `EstatePayrollTest` proves the same figures equal the sum of specific posted
 * journal lines for every month that has been paid.
 *
 * AN UNCALCULATED RUN PRINTS AN EM DASH, NOT A ZERO. September has no gross and
 * no net because nobody has worked them out yet. Zero is a figure — it says the
 * estate owes its staff nothing this month — and this run says nothing at all
 * yet. The controller sends null and this page draws the dash.
 *
 * THE STATUS BADGE IS DERIVED, in the one vocabulary (M96, M99): Draft,
 * Action required, Pending, Paid. The stored value stays the lifecycle state.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** The runs as the shared table reads them (M32 F5): newest period first, a page at a time. */
    table: { type: Object, required: true },
    tabs: { type: Array, required: true },
    /** The next period on the payroll calendar, and why it cannot be started if it cannot. */
    calendar: { type: Object, required: true },
    canCreate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * STARTING A RUN IS BUILT (12 §2, Wave 1). The calendar is calendar months,
 * one open at a time, read from the runs themselves; the panel reads the next
 * period back before the press. The permission comes first, then the
 * calendar's own reason — a month still open, or no rate card in force.
 */
const starting = ref(false)
const submitting = ref(false)
const startError = ref(null)

const startBlockedBy = computed(() => {
    if (!props.canCreate) {
        return props.reasons.create
    }

    return props.calendar.blocked_by
})

/* In the console's modal since the M98 sweep (M95 §1): it was a panel above the list. Opened, never toggled. */
const openStart = () => {
    startError.value = null
    starting.value = true
}

const startRun = () => {
    if (startBlockedBy.value !== null) {
        return
    }

    router.post(`${base.value}/payroll/runs`, {}, {
        preserveScroll: true,
        onStart: () => {
            submitting.value = true
        },
        onSuccess: () => {
            starting.value = false
        },
        // A refusal is said inside the modal that asked (M95 §2), in the server's own words.
        onError: (errors) => {
            startError.value = errors.run ?? Object.values(errors)[0] ?? 'The run was not started.'
        },
        onFinish: () => {
            submitting.value = false
        },
    })
}

/*
 * Boards 13, 14 and 15 share the payroll sheet; board 37 and board 40 are drawn
 * in a different one. Confirmed against the sheet that actually defines
 * `.kpi-row` and `.data-table` for these screens rather than assumed from the
 * module — that mistake cost board 38 a whole measurement.
 */
useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

// A run started, approved or paid in another console moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['staff'] })

const page = usePage()

/* A search that matches nothing is not an estate with no runs: it says so and offers the way back (rule 19). */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => (props.table.q ?? '') !== '',
})

/** Everything up to `/payroll`, whichever shape this environment serves. */
const base = computed(() => page.url.split('/payroll')[0])

/* A read that failed is read again in place; nothing on this screen writes by reloading. */
const retry = () => router.reload({ preserveScroll: true })

/**
 * The board's two money formats, and they are two on purpose. A KPI tile
 * abbreviates because it is read at a glance; the table does not, because a
 * treasurer comparing five months needs the figures themselves.
 */
const abbreviated = (minor) => {
    const value = minor / 100

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000).toLocaleString('en-JM')}k`
    }

    return `$${value.toLocaleString('en-JM')}`
}

// To the cent: it dropped them while its name said exact (batch 1, Residents' finding). The tiles above stay abbreviated.
const exact = (minor) =>
    minor === null || minor === undefined
        ? '—'
        : `$${(minor / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const tile = (kpi) => (kpi.value_minor === undefined ? kpi.value : abbreviated(kpi.value_minor))

/** Which badge class the board gives each state. */
const badgeClass = (row) =>
    ({
        exceptions: 'exceptions',
        calculated: 'pending',
        paid: 'paid',
    })[row.status] ?? 'draft'

/**
 * Where a row's link goes. A run with exceptions opens the exceptions screen;
 * everything else opens the run itself. The label the controller sends says
 * which — "Resolve exceptions", "Review & approve", "View" — so the destination
 * and the words a reader clicks can never disagree.
 */
const rowHref = (row) =>
    row.status === 'exceptions'
        ? `${base.value}/payroll/runs/${row.slug}/exceptions`
        : `${base.value}/payroll/runs/${row.slug}`

/* The run list, as the shared table (M32 F5): newest period first unless a heading asks otherwise. */
const COLUMNS = [
    { key: 'period', label: 'Pay period', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'employees', label: 'Employees', sortable: true },
    { key: 'gross', label: 'Gross', sortable: true, cellClass: 'num-cell' },
    { key: 'net', label: 'Net', sortable: true, cellClass: 'num-cell' },
    { key: 'actions', label: '' },
]
</script>

<template>
    <Head title="Payroll & HR" />

    <EstateConsole title="Payroll & HR" :estate-name="estate.name" active="payroll">
        <template #actions>
            <!--
              Built (12 §2, Wave 1): the calendar is calendar months, one open
              at a time, and the next period is read from the runs themselves.
              Inert, with the calendar's own reason, while a month is still
              open or no rate card is in force on the pay date.
            -->
            <!-- M96 level 3: a role that can never start a run has no such button. -->
            <button
                v-if="canCreate"
                type="button"
                class="btn-primary-sm"
                :disabled="startBlockedBy !== null"
                :title="startBlockedBy ?? `Start the ${calendar.label} pay run as a draft.`"
                @click="openStart"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Start new run</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.active" class="subnav-item active" aria-current="page">{{ tab.label }}</div>
                <Link
                    v-else
                    :href="tab.key === 'runs' ? `${base}/payroll` : `${base}/payroll/${tab.key}`"
                    class="subnav-item"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <!-- M96 level 2 (M94 §1.1): why the next month cannot start yet, and what the open one waits on, on the screen. -->
        <p v-if="canCreate && calendar.blocked_by" class="runs-why">{{ calendar.blocked_by }}</p>

        <!--
          The calendar, read back before the press: which month, which days, when it is paid and on which card. In the
          console's modal (M95 §1); its refusal is said inside it.
        -->
        <ConsoleDialog
            :open="starting"
            :title="`Start the ${calendar.label} pay run`"
            :confirm-label="`Start ${calendar.label}`"
            busy-label="Starting…"
            :busy="submitting"
            :confirm-blocked-reason="startBlockedBy"
            :error="startError"
            @confirm="startRun"
            @cancel="starting = false"
        >
            <dl class="runs-calendar">
                <div><dt>Period</dt><dd>{{ calendar.label }}</dd></div>
                <div><dt>Runs</dt><dd>{{ calendar.runs ?? `${calendar.period_start} to ${calendar.period_end}` }}</dd></div>
                <div><dt>Closes and is paid</dt><dd>{{ calendar.pay_date }}</dd></div>
                <div><dt>Rate card</dt><dd>{{ calendar.rate_card ?? 'none in force' }}</dd></div>
            </dl>
            <p class="runs-panel-note">
                Starting it makes a draft prepared by you. Timesheets are checked on the exceptions screen, the payslips are
                calculated after that, and nothing is paid until an approver signs the run off.
            </p>
        </ConsoleDialog>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <svg v-if="kpi.key === 'employees'" viewBox="0 0 24 24" fill="none">
                            <circle cx="9" cy="8" r="3.4" stroke="currentColor" stroke-width="1.7" />
                            <path d="M3 20c0-3.3 2.7-5.4 6-5.4s6 2.1 6 5.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            <path d="M17 11a3 3 0 1 0 0-6M18 20c0-2.6-1-4.3-2.6-5.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'next_pay_date'" viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'last_net'" viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="6" width="20" height="13" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M2 10h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                </div>
                <div class="k-val">{{ tile(kpi) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The pay runs could not be read"
            body="Nothing was started or changed. Reading them again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No pay run matches that search"
            body="Every run is still here; the search hides the rest."
            action-label="Clear the search"
            @action="router.visit(`${base}/payroll`)"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No pay run yet"
            body="Each month’s run appears here once it is started."
        />

        <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" row-key="slug" noun="pay runs">
            <template #cell-status="{ row }">
                <!-- Batch 1 item 10: the status is the way to where it is acted on, the same place the row's action opens. -->
                <Link :href="rowHref(row)" class="status-badge status-link" :class="badgeClass(row)" :title="row.action">{{ row.status_label }}</Link>
            </template>
            <template #cell-gross="{ row }">{{ exact(row.gross_minor) }}</template>
            <template #cell-net="{ row }">{{ exact(row.net_minor) }}</template>
            <template #cell-actions="{ row }">
                <Link :href="rowHref(row)" class="text-link-sm">{{ row.action }}</Link>
            </template>
        </DataTable>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action, its three tabs and
 * its row links as <div>s; here the first is a real button and the rest are
 * anchors, which arrive with a border, a face, the browser's own font and an
 * underline. .btn-primary-sm, .subnav-item and .text-link-sm supply everything
 * visible — and nothing here reaches past those defaults, which is the mistake
 * D-045 records.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

a.subnav-item,
a.text-link-sm {
    text-decoration: none;
}

button.text-link-sm {
    border: 0;
    background: none;
    font-family: inherit;
    line-height: inherit;
    padding: 0;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a list nobody is starting a run
 * from, so it has no calendar read-back and no refusal. Kept to the tokens the
 * boards define and the shapes they already use. The read-back sits in the
 * console's modal since the M98 sweep; the modal is its panel.
 */
.runs-why {
    margin: 0 0 14px;
    padding: 10px 14px;
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    background: var(--white);
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--slate-600);
}

.runs-calendar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
    margin: 0;
}

.runs-calendar dt {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    margin: 0 0 3px;
}

.runs-calendar dd {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--navy-900);
    margin: 0;
}

.runs-panel-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 14px 0 0;
}

/* In the modal on a phone, the four read-back figures two to a row. */
@media (max-width: 640px) {
    .runs-calendar {
        grid-template-columns: repeat(2, 1fr);
    }
}

/*
 * ON A PHONE (Residents' check at 390 px with the new shell, 12 Oct): the four figures stayed four across and pushed the
 * page to 437 px. Two to a row below 640 px, as the maintenance queue's are; doubled class to outrank the board's rule.
 */
@media (max-width: 640px) {
    .kpi-row.kpi-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .kpi-card {
        min-width: 0;
    }
}

/* Batch 1 item 10: a status that leads somewhere reads as one, and keeps its badge's colours. */
.status-link {
    text-decoration: none;
    cursor: pointer;
}

.status-link:hover,
.status-link:focus-visible {
    text-decoration: underline;
}
</style>

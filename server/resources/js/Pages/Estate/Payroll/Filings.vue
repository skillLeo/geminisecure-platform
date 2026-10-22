<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { submitDialog } from '../../../lib/dialogSubmit.js'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Statutory filings — board screen community-admin-16.
 *
 * THE BOARD PRINTS NO FIGURES AND EVERY ROW IS A MOVEMENT OF MONEY. An S01
 * clears the four withholdings a pay run took off four people; filing it is
 * Dr 2100 / Cr 1000 for exactly what that run withheld, and `EstatePayrollTest`
 * asserts the tie component by component rather than in aggregate. Four amounts
 * that sum correctly while two of them are individually wrong is a
 * reconciliation that passes and a filing that is false.
 *
 * SO "FILE" IS AN APPROVE-LEVEL ACT, not an update. It remits to the revenue
 * authority and cannot be unremitted, which is the same shape as releasing a pay
 * run — D-013's split, applied to a different creditor. Since the payroll pass
 * (M96 level 3) a role without it has no File at all, and File asks first.
 *
 * A RETURN WITH NOTHING TO REMIT POSTS NOTHING. The GCT return and the annual
 * P24 carry no deduction breakdown, so filing them records the date and writes
 * no journal: a zero-value entry is noise in a ledger somebody reads in five
 * years.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    tabs: { type: Array, required: true },
    canFile: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

// A filing marked filed elsewhere moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['staff'] })

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/payroll')[0])

/*
 * The badge follows the return's DATES, which the server works out as `state`
 * (bug hunt B037), not the stored status, which nothing moves as a due date
 * passes. Its words are the one vocabulary (M96, M99): Completed, Action
 * required (due soon, or late with Overdue as a flag beside it), Pending.
 */
const badgeClass = (row) =>
    ({
        filed: 'approved',
        due_soon: 'exceptions',
        overdue: 'exceptions',
    })[row.state ?? row.status] ?? 'draft'

/*
 * ONE PRESS, ONE REMITTANCE. Filing posts Dr 2100 / Cr the bank, and the ledger
 * cannot unpost it. A second click while the first was still in flight used to
 * reach the server as a second filing and drive 2100 below nil; the server now
 * refuses it, and the control is inert until the first has answered.
 */
const filing = ref(null)

/*
 * FILING ASKS FIRST (the payroll pass, 11 Oct). It posted the remittance on one click, and a filed return cannot be
 * taken back. The console's dialog names the return and the period, and says what leaves the bank, to the cent, or
 * that nothing does.
 */
const asking = ref(null)

/*
 * A REFUSAL STAYS IN THE DIALOG (the console's dialog rule, `lib/dialogSubmit.js`; the hub, 12 Oct). It closed and
 * toasted, so the return it named left the screen with the reason. Now the dialog stays open on the same return with
 * the server's sentence in its band, and only a filing that went through closes it.
 */
const fileForm = useForm({})

const fileError = computed(() => fileForm.errors.filing ?? Object.values(fileForm.errors)[0] ?? null)

const file = (row) => {
    if (!props.canFile || filing.value !== null || row.file_blocked) {
        return
    }

    fileForm.clearErrors()
    asking.value = row
}

const closeFile = () => {
    asking.value = null
    fileForm.clearErrors()
}

const confirmFile = () => {
    const row = asking.value

    if (row === null) {
        return
    }

    submitDialog(fileForm, 'post', `${base.value}/payroll/filings/${row.id}/file`, {
        close: () => {
            asking.value = null
        },
        onStart: () => {
            filing.value = row.id
        },
        onFinish: () => {
            filing.value = null
        },
    })
}

/** What filing posts, in the console's one way of writing money. */
const money = (minor) => `$${(minor / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const askingPosts = computed(() => (asking.value?.total_minor ?? 0) > 0)

/* A read that failed is read again in place; nothing on this screen writes by reloading. */
const retry = () => router.reload({ preserveScroll: true })
</script>

<template>
    <Head title="Statutory filings" />

    <EstateConsole title="Statutory filings" :estate-name="estate.name" active="payroll">
        <template #actions>
            <Link
                :href="`${base}/payroll/filings/calendar`"
                class="btn-outline-sm"
                title="Every return due in the year ahead."
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                    <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <span>Compliance calendar</span>
            </Link>
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

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="Ask your estate administrator if you need it."
        />

        <!-- The fifth state (rule 19): a read that failed says so, and that trying again is safe. -->
        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The returns could not be read"
            body="Nothing was filed or changed. Reading them again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No returns are on record"
            body="An estate that has run no payroll owes no statutory deductions. Returns appear here once a run has withheld something to remit."
        />

        <div v-else class="filing-card">
            <div v-for="row in rows" :key="row.id" class="filing-row">
                <div class="filing-icon" :class="{ warn: row.warn }">
                    <svg v-if="row.warn" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                        <path d="M12 7.5v5M12 16h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                    </svg>
                    <svg v-else-if="row.code === 'P24'" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                        <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        <path d="M14 3v5h5" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                    </svg>
                </div>

                <div class="filing-txt">
                    <div class="ft1">{{ row.title }}</div>
                    <div class="ft2">{{ row.subtitle }}</div>
                    <!-- M96 level 2: why File is greyed, on the row, never only in its tooltip. -->
                    <div v-if="canFile && row.status !== 'filed' && row.file_blocked" class="ft-why">{{ row.file_blocked_short ?? row.file_blocked }}</div>
                </div>

                <div class="filing-due">{{ row.due }}</div>

                <!--
                  Batch 1 item 10: a return still to file is the way to where it is filed. Its status asks the same File
                  question as the row's button; while its pay run is unapproved, it opens that run, which is what waits.
                  A filed return has nothing left to do, and a role that cannot file reads the state only.
                -->
                <button
                    v-if="canFile && row.status !== 'filed' && !row.file_blocked"
                    type="button"
                    class="status-badge status-link"
                    :class="badgeClass(row)"
                    :disabled="filing !== null"
                    :title="`File ${row.code} for ${row.subtitle}`"
                    @click="file(row)"
                >{{ row.status_label }}<template v-if="row.flag"> · {{ row.flag }}</template></button>
                <Link
                    v-else-if="row.status !== 'filed' && row.file_blocked && row.run_slug"
                    :href="`${base}/payroll/runs/${row.run_slug}`"
                    class="status-badge status-link"
                    :class="badgeClass(row)"
                    :title="row.file_blocked_short ?? row.file_blocked"
                >{{ row.status_label }}<template v-if="row.flag"> · {{ row.flag }}</template></Link>
                <div v-else class="status-badge" :class="badgeClass(row)">{{ row.status_label }}<template v-if="row.flag"> · {{ row.flag }}</template></div>

                <!--
                  Authored: the board draws no action on these rows because it
                  draws them as a record. A register a treasurer cannot file
                  from is a register they have to leave to do the thing it is
                  reminding them about, so the outstanding rows carry the act.
                  M96 level 3 since the payroll pass: a role that can never file
                  has no File; the badge says the return's state.
                -->
                <button
                    v-if="canFile && row.status !== 'filed'"
                    type="button"
                    class="filing-action"
                    :disabled="filing !== null || !!row.file_blocked"
                    :title="row.file_blocked ?? `File ${row.code} for ${row.subtitle}`"
                    @click="file(row)"
                >
                    {{ filing === row.id ? 'Filing…' : 'File' }}
                </button>
            </div>
        </div>

        <!-- Filing asks first, naming the return and saying what it posts (the payroll pass). -->
        <ConsoleDialog
            :open="asking !== null"
            :title="asking ? `File ${asking.code} for ${asking.period ?? asking.subtitle}?` : ''"
            tone="danger"
            confirm-label="File the return"
            busy-label="Filing…"
            cancel-label="Not yet"
            :busy="filing !== null"
            :error="fileError"
            @confirm="confirmFile"
            @cancel="closeFile"
        >
            <p v-if="asking && askingPosts" style="margin: 0">
                It records the return as filed and posts {{ money(asking.total_minor) }} from the bank to the revenue
                authority. A filed return cannot be taken back.
            </p>
            <p v-else-if="asking" style="margin: 0">
                It records the return as filed. Nothing posts: this return remits no money. A filed return cannot be taken
                back.
            </p>
        </ConsoleDialog>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only for the two the board draws as <div>s — its topbar
 * action and its three tabs. `.btn-outline-sm` DECLARES a border and that
 * border IS the variant, so it is left alone: D-045 records a blanket
 * `border: 0` here silently rubbing out a 1.5px outline the board did draw,
 * and surviving several passing measurements afterwards.
 */
a.btn-outline-sm,
a.subnav-item {
    text-decoration: none;
}

/*
 * AUTHORED BELOW THIS LINE. The board's card and rows are its own; the file
 * action is not on it, so it is built from the tokens the board defines rather
 * than from new ones.
 */
.filing-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.filing-action {
    height: 30px;
    padding: 0 13px;
    border: 0;
    border-radius: 8px;
    background: var(--navy-600);
    color: var(--white);
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.filing-action[disabled] {
    background: var(--navy-100);
    color: var(--navy-700);
    cursor: not-allowed;
}

/* Why File is greyed, on the row (M96 level 2). */
.ft-why {
    margin-top: 3px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--amber-700);
}

/* Batch 1 item 10: a status that leads somewhere reads as one, and keeps its badge's colours; the File status is a
   button, so the browser's own border and font come off it. */
.status-link {
    border: 0;
    font: inherit;
    text-decoration: none;
    cursor: pointer;
}

.status-link:hover,
.status-link:focus-visible {
    text-decoration: underline;
}

.status-link:disabled {
    cursor: default;
    text-decoration: none;
}
</style>

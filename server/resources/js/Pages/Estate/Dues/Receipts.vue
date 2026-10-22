<script setup>
import { computed, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { toast } from '../../../lib/toasts.js'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { duesTabs } from './tabs'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * The receipt register — board 5's Receipts tab, built to the ruling.
 *
 * NOT DRAWN ON ANY BOARD: board 5 names the tab and draws the arrears list
 * under it. The register borrows board 5's own shapes — the KPI row, the chip
 * row's sheet, the data table — so it reads as the fourth tab of one module
 * rather than as a screen of its own.
 *
 * THE NUMBER IS THE POINT. `{PREFIX}-R-{00001}`, sequential per estate, never
 * reused, allocated when a payment posts and never before. The sequence keeps
 * a high-water mark, and every number up to it that no receipt carries is
 * drawn here as a GAP, in its place in the order — so a missing receipt is a
 * row somebody has to look at rather than a number nobody notices is absent.
 *
 * NOT ONE FIGURE HERE IS A HOUSEHOLD'S BALANCE. A receipt is an amount the
 * estate took, which is a fact about the receipt; what a household still owes
 * is the arrears list's and the unit ledger's.
 *
 * TWO DATES PER RECEIPT, AND THE GAP BETWEEN THEM IS SHOWN. Received is the
 * day the money arrived and entered is the day it was keyed; cash taken on
 * Friday and keyed on Monday is late paperwork, not a late payment, and a
 * treasurer reconciling a week's takings needs to see which it was.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    prefix: { type: String, required: true },
    /** Where this estate's sequence starts — 1, or where an earlier numbering left off. */
    first_no: { type: Number, required: true },
    last_no: { type: Number, required: true },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** Numbers allocated that no receipt carries — every one in the sequence, which the tile counts. */
    gaps: { type: Array, required: true },

    /*
     * M11 A2. The register is paged in sequence order, and each gap belongs to
     * exactly one page — the run of the sequence it falls in. These are the gaps
     * this page draws in place; none while a search narrows the rows, because a
     * searched list is not a run of the sequence.
     */
    page_gaps: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    /** Whether this page is the sequence itself — its own order, unsearched — where a gap has a place (M32 F5). */
    in_sequence: { type: Boolean, default: true },
    /** The shared table's state (M32 F5, `TableQuery::meta`): its order, its columns, and this page's place. */
    table: { type: Object, required: true },
    canRecord: { type: Boolean, required: true },
    canExport: { type: Boolean, required: true },
    exportBlockedReason: { type: String, required: true },
    /** Whether this reader may have a receipt's PDF: the Payments module's read, which a role built with Dues & ledger alone lacks. */
    canReceipt: { type: Boolean, default: false },

    /*
     * M10 §5. Every receipt listed with its amount is the estate's takings. For
     * a reader who may not have the estate's position the server sends each
     * row's amount as null, no month tile, and this sentence. The sequence, the
     * gaps and each receipt's own PDF stay — that is what the office works from.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * All six now (M11 A2): a search that matched nothing is empty-filtered — "no
 * receipt matches" — and not empty, which would say the estate has issued none.
 */
const state = useScreenState({
    rows: () => props.rows.length + props.page_gaps.length,
    filtered: () => props.search !== '',
})

/** This URL with one thing changed — the page, or the search. Page 1 says nothing the bare URL does not. */
const href = (changes) => {
    const merged = { q: props.search, ...keptByTable(props.table), page: props.table?.page ?? 1, ...changes }
    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== undefined && value !== '' && !(key === 'page' && value === 1)) {
            query.set(key, String(value))
        }
    }

    const suffix = query.toString()

    return financePath(`/receipts${suffix === '' ? '' : `?${suffix}`}`)
}

/** What the topbar search carries with it (M32 F5): the order and the page size, and never the page. */
const searchParams = computed(() => keptByTable(props.table))

const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

/*
 * THE PRINTED RECEIPT (12 §1). Asked for rather than produced: it is rendered
 * by a worker, kept seven years, and a second press inside the window hands
 * back the same document rather than issuing a second receipt for one payment.
 */
const receiptForm = useForm({})

const askForReceipt = (row) => {
    receiptForm.post(`${root.value}/finance/payments/${row.id}/receipt`, { preserveScroll: true })
}

/* A receipt that could not be asked for says so, as a toast (M95 §2): it was a banner over the register. */
watch(
    () => page.props.errors?.document,
    (refusal) => {
        if (refusal) {
            toast(refusal, { tone: 'error', key: 'receipt-refused' })
        }
    },
    { immediate: true },
)

/* The module's own tabs, as board 5 draws them — all built. See ./tabs.js. */
const subnav = computed(() => duesTabs(financePath, 'receipts'))

/**
 * Receipts and gaps in one order — the sequence's — newest first.
 *
 * A gap is placed by its number, between the receipts on either side of it,
 * because that is where somebody looking for it will look. The receipts are
 * sorted by their own number; a legacy receipt that is not in the format has
 * no number and sorts last, by date. In any other order (M32 F5) — or under a
 * search — the page is not a run of the sequence, the server sends no gap, and
 * the rows stand in the order they came.
 */
const numberOf = (receiptNo) => {
    const match = /-R-(\d+)$/.exec(receiptNo)

    return match ? Number(match[1]) : null
}

const sequence = computed(() => {
    const receipts = props.rows.map((row) => ({ ...row, key: `r-${row.id}`, kind: 'receipt', number: numberOf(row.receipt_no) }))

    if (!props.in_sequence || props.page_gaps.length === 0) {
        return receipts
    }

    const items = [...receipts, ...props.page_gaps.map((gap) => ({ key: `gap-${gap.number}`, kind: 'gap', number: gap.number, gap }))]

    return items.sort((a, b) => (b.number ?? -1) - (a.number ?? -1))
})

/*
 * THE TABLE (M32 F5): sorted and paged by the server over the whole sequence. The amount sorts only for a reader who
 * may read it — the server offers it to nobody else, and its column is not drawn for them (M10 §5).
 */
const columns = computed(() => [
    { key: 'number', label: 'Receipt', sortable: true },
    { key: 'received', label: 'Received', sortable: true },
    { key: 'entered', label: 'Entered', sortable: true },
    { key: 'unit', label: 'Unit', sortable: true },
    { key: 'method', label: 'Method', sortable: true },
    { key: 'reference', label: 'Reference' },
    ...(props.positionWithheld ? [] : [{ key: 'amount', label: 'Amount', sortable: true, cellClass: 'bal-amt' }]),
    { key: 'status', label: 'Status', sortable: true },
    { key: 'entry', label: 'Entry' },
    // M96 level 3: a reader the download would refuse has no column of buttons that refuse.
    ...(props.canReceipt ? [{ key: 'document', label: 'Document' }] : []),
])

/** A gap is a row of its own, amber because it is the one row on this register that is a finding rather than a fact (rule 20). */
const gapRow = (item) => (item.kind === 'gap' ? 'gap-row' : null)
const gapAttrs = (item) => (item.kind === 'gap' ? { class: 'rcpt-gap', title: item.gap.note } : {})

const noun = computed(() => (props.table.total === 1 ? 'receipt' : 'receipts'))

/*
 * A receipt's state in the money words (Hassam, 11 October): a payment in the books is Paid, green; one a reversing
 * entry took back out is Void, an outline in grey. What each means stays on hover.
 */
const RECEIPT_WORD = { recorded: 'Paid', reversed: 'Void' }

const RECEIPT_MEANING = {
    recorded: 'The payment is in the books.',
    reversed: 'A reversing entry took this payment back out. Its number stays used.',
}

const receiptWord = (status) => RECEIPT_WORD[status] ?? status

const statusStyle = (status) =>
    status === 'recorded'
        ? 'background:var(--success-100);color:var(--success-700);'
        : 'background:transparent;border:1px solid var(--navy-200);color:var(--slate-600);'

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['dues'] })
</script>

<template>
    <Head title="Receipts" />

    <EstateConsole
        title="Receipts"
        :estate-name="estate.name"
        active="dues_ledger"
        search-placeholder="Search receipt, lot or reference…"
        :search-route="financePath('/receipts')"
        :search-value="search"
        :search-params="searchParams"
    >
        <template #actions>
            <!--
              A receipt export leaves the estate as a file naming who paid
              what. The estate records that it left, who took it and how many
              rows it held — and the file is the WHOLE sequence, not this
              screen's recent page, because a short file is one the office
              reconciles against and finds short with nothing saying it was cut.
              A role that may not take the file has no Export at all (M96 level 3), as on the arrears list.
            -->
            <a
                v-if="canExport"
                :href="financePath('/receipts/export')"
                class="btn-outline-sm"
                title="Download every receipt as a CSV. The estate records that it left, who took it and how many rows it held."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </a>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <div v-if="item.active" class="subnav-item active" aria-current="page">{{ item.label }}</div>
                <Link v-else :href="item.href" class="subnav-item">{{ item.label }}</Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="8" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The receipts could not be loaded"
            body="No receipt has been issued or changed, and none has been lost. Reading them again is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Receipts are not part of your role's access"
            body="Receipts name who paid what and when, so only roles that handle dues can open them. Ask your estate administrator if you need it."
        />

        <template v-else>
            <!--
              Three cards on board 5's KPI sheet. `total` is the navy card, and
              the other two take the neutral ground rather than an ageing colour
              — a count of receipts is not a bucket of arrears.
            -->
            <!-- As many columns as the server sent cards: two where the month's takings are withheld. -->
            <div class="age-kpi-row rcpt-kpis" :style="{ gridTemplateColumns: `repeat(${kpis.length}, 1fr)` }">
                <div v-for="card in kpis" :key="card.key" class="age-kpi" :class="card.key === 'issued' ? 'total' : 'plain'">
                    <div class="av">{{ card.value }}</div>
                    <div class="al">{{ card.label }}</div>
                </div>
            </div>

            <p class="rcpt-note">
                Numbered <strong>{{ prefix }}-R-{{ String(first_no).padStart(5, '0') }}</strong> upward, one sequence
                for this estate, allocated when a payment posts and never reused. The next receipt will be
                <strong>{{ prefix }}-R-{{ String(last_no + 1).padStart(5, '0') }}</strong>. A number that no receipt
                carries is drawn below as a gap, in its place in the register's own order, newest number first.
                <template v-if="!canRecord">Payments are recorded from a unit's ledger by the roles that take payments.</template>
                <template v-else>Payments are recorded from a unit's ledger. Open one from the arrears list.</template>
            </p>

            <p v-if="positionWithheld" class="rcpt-note">{{ positionReason }}</p>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No receipt matches"
                body="Nothing in the register has that receipt number, lot or bank reference. Every receipt the estate has issued is still in it. Clear the search to page through them all."
                action-label="Clear the search"
                @action="router.visit(href({ q: '', page: 1 }))"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No receipt has been issued yet"
                body="Nothing has been paid to this estate, so no number has been allocated. The first payment recorded on any unit's ledger takes the first number of the sequence, and every one after it the next."
            />

            <!--
              Every receipt is on some page (M11 A2), in whichever order the headings ask for, sorted and paged by the
              server over the whole sequence (M32 F5). A page holds at most 200 receipts and its own gaps, so the
              long-table window it once needed (M11 A3) has nothing left to do here.
            -->
            <DataTable
                v-else
                :columns="columns"
                :rows="sequence"
                :table="table"
                :noun="noun"
                row-key="key"
                :row-slot="gapRow"
                :row-attrs="gapAttrs"
            >
                <!-- A gap, in its place: its number, and across every other column what it means. -->
                <template #gap-row="{ row, span }">
                    <td>{{ row.gap.receipt_no }}</td>
                    <td :colspan="span - 1">Gap. {{ row.gap.note }}</td>
                </template>

                <template #cell-number="{ row }"><span class="rcpt-no">{{ row.receipt_no }}</span></template>
                <template #cell-received="{ row }">{{ row.received_on }}</template>
                <template #cell-entered="{ row }">
                    {{ row.entered_on }}
                    <span
                        v-if="row.entered_late"
                        class="rcpt-late"
                        title="Entered on a later day than the money arrived: late paperwork, not a late payment."
                    >
                        late entry
                    </span>
                </template>
                <template #cell-unit="{ row }">
                    <Link :href="financePath(`/units/${row.unit_id}`)" class="text-link-sm">{{ row.unit }}</Link>
                </template>
                <template #cell-method="{ row }">
                    {{ row.method_label }}
                    <!-- A test card's payment (M12 §2.6; D-179): in the books against 1090, never in the bank. -->
                    <span
                        v-if="row.is_demonstration"
                        class="rcpt-demo"
                        title="Paid with a test card in the Resident App. No money moved, and none of it reaches the bank."
                    >
                        demonstration
                    </span>
                </template>
                <template #cell-reference="{ row }">{{ row.reference ?? '' }}</template>
                <template #cell-amount="{ row }">{{ row.amount }}</template>
                <template #cell-status="{ row }">
                    <div class="age-badge" :style="statusStyle(row.status)" :title="RECEIPT_MEANING[row.status] ?? ''">{{ receiptWord(row.status) }}</div>
                </template>

                <!--
                  THE ENTRY'S REFERENCE, AS TEXT (bug hunt B030). It used to be an Inertia link to records/journals/{id},
                  which is an isolation probe answering the raw journal header as JSON. No screen shows a journal's lines
                  yet, so the reference is what the office reads and quotes, and nothing pretends to open it.
                -->
                <template #cell-entry="{ row }">
                    <span
                        v-if="row.journal_ref"
                        class="rcpt-entry"
                        title="The ledger entry this payment posted."
                        >{{ row.journal_ref }}</span
                    >
                </template>

                <!--
                  AUTHORED COLUMN. The printed receipt (12 §1) is server-rendered and queued, so this asks for one rather
                  than producing it — and a second press inside the window hands back the same document. The console's
                  shell waits for it and starts the download (DocumentHandOver).
                -->
                <template #cell-document="{ row }">
                    <button
                        type="button"
                        class="text-link-sm"
                        :disabled="receiptForm.processing"
                        title="Download this receipt as a PDF."
                        @click="askForReceipt(row)"
                    >
                        Download receipt
                    </button>
                </template>
            </DataTable>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal, as board 5's own page makes it: the sheet draws tabs and row
 * actions as <div>s, and a real button or link arrives with the browser's own
 * chrome. .btn-outline-sm keeps its border — it is the sheet's.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button[disabled] {
    cursor: not-allowed;
}

/* A download is a navigation, so the export is an <a>; only the UA underline
 * comes off, because .btn-outline-sm's border IS its variant (D-045). */
a.btn-outline-sm {
    text-decoration: none;
}

/*
 * AUTHORED BELOW THIS LINE. Board 5 draws five ageing cards; this register has
 * three counts, so the grid narrows and the two non-navy cards take a neutral
 * ground the sheet has no class for.
 */
.rcpt-kpis {
    grid-template-columns: repeat(3, 1fr);
}

.age-kpi.plain {
    background: var(--white);
    border: 1px solid var(--navy-100);
}

.age-kpi.plain .av {
    color: var(--navy-900);
}

.age-kpi.plain .al {
    color: var(--slate-500);
}

.rcpt-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 16px;
    max-width: 820px;
}

.rcpt-no {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    color: var(--navy-800);
}

/* The entry's reference is read, not opened: plain figures, no link colour — and one line, never broken at its hyphens. */
.rcpt-entry {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.rcpt-late {
    display: inline-block;
    margin-left: 6px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    background: var(--amber-100);
    color: var(--amber-700);
}

.rcpt-demo {
    display: inline-block;
    margin-left: 6px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    background: var(--navy-100);
    color: var(--navy-700);
}

/* The gap row is drawn inside the shared table, whose rows are its own: reached through it. Amber, a finding, never red (rule 20). */
:deep(.rcpt-gap) td {
    background: var(--amber-100);
    color: var(--amber-700);
    font-weight: 600;
}

/* The pager is the shared table's (M32 F5, Components/DataTable.vue). */
</style>

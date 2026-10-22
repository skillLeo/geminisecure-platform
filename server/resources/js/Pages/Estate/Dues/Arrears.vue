<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import InfoButton from '../../../Components/InfoButton.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useWireframe } from '../../../composables/useWireframe'
import { useScreenState } from '../../../composables/useScreenState'
import { duesTabs } from './tabs'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Arrears command centre — board screen community-admin-05.
 *
 * DOM and class names are the board's. Every figure arrives already summed from
 * posted journal lines, so nothing on this page adds anything up: the total
 * outstanding card is the sum of the four ageing buckets beside it, and it is
 * derived once, in the ledger, where a test can re-sum it in raw SQL. A page
 * that recomputed it in JavaScript would agree with the accounts until the day
 * it did not.
 *
 * THE CHIPS FILTER IN THE QUERY, NOT IN THE BROWSER. Filtering the rows already
 * sent would leave the ageing cards reporting the whole estate above a list
 * reporting one phase, and a screen that computes its total and its list in two
 * places eventually reports two different numbers. The chips are links, so a
 * filtered view is a URL a treasurer can bookmark, share and reload.
 *
 * `reasons.restrict` is deliberately unused. Restriction stops a household's
 * guest passes at the gate, and the reason the server sends says exactly why it
 * is not a list action — it is never applied without the household in front of
 * you — so it belongs on the unit ledger, board 6. The board draws no restrict
 * control here either.
 *
 * A PAGE OF THE REGISTER, NOT THE REGISTER (M11 A2). Phoenix Park has 163 units
 * in arrears and this screen used to receive every one of them in one payload.
 * It now receives twenty-five, and the search, the sort and the page are the
 * server's, applied in SQL over the whole register — so "sorted by balance"
 * means the largest debts on the estate, not the largest of the rows already
 * sent. Every link on the screen is this URL with one thing changed, the same
 * rule the residents register keeps. The export is still the whole register.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** Five cards in board order: total first, then current, 30, 60, 90+. */
    ageing: { type: Array, required: true },
    phases: { type: Array, required: true },
    filters: { type: Object, required: true },
    rows: { type: Array, required: true },

    /** How many units the whole register holds under these filters, not how many are on this page. */
    total: { type: Number, default: 0 },

    /**
     * The shared table's state (M32 F5, `TableQuery::meta`): its order, the columns the server will order by — so this
     * page cannot invent one — and which page this is. Null when nothing may be shown.
     */
    table: { type: Object, default: null },
    canCharge: { type: Boolean, required: true },
    canExport: { type: Boolean, required: true },
    exportBlockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },

    /*
     * M10 §5. A list of which households are behind is about what they pay,
     * with or without its figures. For a reader who may not have the estate's
     * position the server sends no rows, no strip, no phases and no filters —
     * only this flag and a sentence naming no household and no figure — and
     * the page draws its denied state in the server's words. Not the first-use
     * "Nobody is in arrears", which would be a false statement about the estate.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },
})

/*
 * The estate boards are ten stylesheets that disagree with each other, so each
 * one is scoped to its own body class and a screen names the board it
 * reproduces rather than inheriting a house sheet.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/finance/arrears. The URL that served this page already carries
 * whichever shape this environment uses, so every link below is built by
 * cutting it at /finance — a hard-coded root would be correct in exactly one of
 * the two.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/finance')))

const financePath = (suffix) => `${root.value}/finance${suffix}`

/*
 * THE FILTERS TRAVEL WITH THE EXPORT, built from the props rather than read off
 * the address bar: the server decided what this screen is showing, and a file
 * that says "arrears" while holding one phase is one somebody will read as the
 * estate's whole position. The audit entry's scope names which.
 */
const exportHref = computed(() => {
    const query = new URLSearchParams()

    if (props.filters.phase) {
        query.set('phase', props.filters.phase)
    }

    if (props.filters.overdue) {
        query.set('overdue', '1')
    }

    const suffix = query.toString()

    return financePath(`/arrears/export${suffix === '' ? '' : `?${suffix}`}`)
})

/*
 * EVERY LINK ON THIS SCREEN IS THIS URL WITH ONE THING CHANGED (M11 A2).
 *
 * Phase, 90+, search, sort, direction and page all live in the query string, so
 * the register is linkable and correct under the back button — the rule the
 * residents register keeps. Building each link from the current filters is what
 * stops a chip silently dropping the search, or a sort header dropping the phase.
 * A direction with nothing to sort, and page 1, say nothing the bare URL does not,
 * so they are left out.
 */
const href = (changes) => {
    const merged = {
        phase: props.filters.phase ?? '',
        overdue: props.filters.overdue ? '1' : '',
        q: props.filters.search ?? '',
        ...keptByTable(props.table),
        page: props.table?.page ?? 1,
        ...changes,
    }

    if (!merged.sort) {
        merged.dir = ''
    }

    if (merged.page === 1) {
        merged.page = ''
    }

    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== undefined && value !== '' && value !== false) {
            query.set(key, String(value))
        }
    }

    const suffix = query.toString()

    return financePath(`/arrears${suffix === '' ? '' : `?${suffix}`}`)
}

/*
 * Phase and ageing are independent filters, so each chip carries the other's
 * current value rather than clearing it: "Phase 2" from a 90+ view means 90+ in
 * Phase 2, which is the question somebody chasing a phase actually has. Back to
 * page 1, because page 4 of "all phases" is not page 4 of "Phase 2".
 */
const chipHref = (phase, overdue) => href({ phase, overdue: overdue ? '1' : '', page: 1 })

/** The filters the topbar search carries with it: every one but the query itself, and never the page. */
const searchParams = computed(() => {
    const params = { ...keptByTable(props.table) }

    if (props.filters.phase) {
        params.phase = props.filters.phase
    }

    if (props.filters.overdue) {
        params.overdue = '1'
    }

    return params
})

/*
 * Sortable where the server sorts, in SQL over the whole register, and plain text where it does not (M32 F5). The
 * household and reminder columns are read per row, so there is nothing to ORDER BY — and a heading that sorted only the
 * twenty-five rows on the page would say one thing and do another.
 */
const COLUMNS = [
    { key: 'household', label: 'Household' },
    { key: 'unit', label: 'Unit', sortable: true },
    { key: 'balance', label: 'Balance', sortable: true, cellClass: 'bal-amt' },
    { key: 'ageing', label: 'Ageing', sortable: true },
    { key: 'last_payment', label: 'Last payment', sortable: true },
    { key: 'last_reminder', label: 'Last reminder' },
    { key: 'actions', label: '' },
]

const noun = computed(() => `unit${props.table?.total === 1 ? '' : 's'} in arrears`)

/*
 * The board's own inline style on the last chip, kept verbatim — it is what
 * right-aligns the chip and makes it red. Inline declarations outrank
 * `.f-chip.active`, so a chip wearing them could never show that its filter is
 * engaged, and the board only ever draws it off. When it is on, the two colour
 * declarations stand down and the board's active pill shows through;
 * margin-left stays either way, because it is layout rather than state.
 */
const overdueChipStyle = computed(() =>
    props.filters.overdue
        ? { marginLeft: 'auto' }
        : { marginLeft: 'auto', background: 'var(--amber-100)', color: 'var(--amber-700)' } // red is urgent only (M12 §4.4)
)

/*
 * Money is formatted here, from integer cents, because the server's job is to
 * keep the figure exact and the page's is to draw it the way the board draws
 * it — and the board draws the same kind of number two different ways.
 *
 * The estate's ledger is denominated in JMD and the board prints a bare $,
 * which is how Jamaica writes its own currency domestically. Reproduced rather
 * than corrected to "J$".
 */
const dollars = (minor) => Math.round(minor / 100)

/**
 * The table's money: to the cent, comma-grouped (the money rule: every figure ties to the ledger).
 *
 * A unit balance is read as the sum a household is about to be asked for, so it is never abbreviated ("$12k" is not an
 * answer to "what do I owe") and, since the dues pass after batch two, never rounded either: it rounded to the dollar,
 * so $12,400.50 read $12,401 beside a ledger that said otherwise. Written from whole minor units, split into dollars and
 * cents rather than divided into a fraction; a credit reads with its minus first.
 */
const amount = (minor) => {
    const whole = Math.abs(minor)

    return `${minor < 0 ? '-' : ''}$${Math.trunc(whole / 100).toLocaleString('en-US')}.${String(whole % 100).padStart(2, '0')}`
}

/**
 * The ageing cards' money: abbreviated, as the board draws it — $1.84M, $612k.
 *
 * Not an inconsistency with the table: these five cards carry the estate's
 * whole receivable at 21px across a five-column grid, and they are read as
 * magnitudes rather than as amounts anybody will be invoiced. Two significant
 * decimals on millions and none on thousands is the board's rule, not a
 * rounding convenience.
 */
const abbreviated = (minor) => {
    const value = dollars(minor)

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000).toLocaleString('en-US')}k`
    }

    // Under a thousand nothing is abbreviated, so it carries its cents (Hassam, 12 Oct): $72.50, never $73.
    return `$${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render and stop agreeing with
 * the props behind it.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filters.phase !== '' || props.filters.overdue || (props.filters.search ?? '') !== '',
})

/* Board 5's four tabs — all built (12 §2, items 17 and 18). See ./tabs.js. */
const subnav = computed(() => duesTabs(financePath, 'arrears'))

/*
 * Reading the arrears and adding to what a household owes are separate
 * permissions, by platform invariant rather than estate preference: a committee
 * member may read this list all day and may not post to it. So New charge and
 * Export are there only for a role that may use them (M96 level 3): a greyed
 * button a role can never press was a control that only refused.
 */

/** The board's own em dash, for a fact that is absent rather than zero. */
const NEVER = '—'

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['dues'] })
</script>

<template>
    <Head title="Dues & ledger" />

    <EstateConsole
        title="Dues & ledger"
        :estate-name="estate.name"
        active="dues_ledger"
        search-placeholder="Search unit or resident…"
        :search-route="positionWithheld ? null : financePath('/arrears')"
        :search-value="filters.search ?? ''"
        :search-params="searchParams"
    >
        <template #actions>
            <!--
              An arrears export leaves the estate as a file naming who owes
              what, and this system cannot recall it. What it records is that
              the file left, who took it and what was in it — and the filters
              travel with the link, because a file that says "arrears" and
              holds one phase is one somebody will read as the whole position.
            -->
            <a
                v-if="canExport"
                :href="exportHref"
                class="btn-outline-sm"
                title="Download the register as a CSV. The estate records that it left, who took it and how many rows it held."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </a>

            <Link v-if="canCharge" :href="financePath('/charges/new')" class="btn-primary-sm" title="Raise a charge against one unit, a whole phase or the estate.">
                <BoardIcon name="plus" :stroke="2" />
                <span>New charge</span>
            </Link>
        </template>

        <div class="subnav">
            <Link
                v-for="item in subnav"
                :key="item.label"
                :href="item.href"
                class="subnav-item"
                :class="{ active: item.active }"
            >
                {{ item.label }}
            </Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="7" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The arrears could not be read"
            body="No balance has changed and nothing has been written off. The ledger is intact; this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <!--
          The cards are below this branch on purpose. Somebody refused Dues &
          ledger must not be told the estate's receivable by a summary left
          standing over the refusal.
        -->
        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The arrears are not yours to see"
            body="It shows what every household owes, so only roles that handle dues can open it. Ask your estate administrator if you need it."
        />

        <!--
          M10 §5: a reader who holds the ledger and not the estate's position.
          The server sent nothing to draw — no strip, no chips, no rows — so the
          denied state stands alone, above everything the other branches draw.
        -->
        <EmptyState
            v-else-if="positionWithheld"
            variant="denied"
            title="Who is in arrears is not part of your role's access"
            :body="positionReason"
        />

        <template v-else>
            <!--
              The cards and the chips stand in every remaining state. The cards
              are the CHOSEN PHASE's receivable — the whole estate's under "All
              phases" (the client, 26 Sep) — and do not follow "90+ days only" or
              the search, so hiding them over an empty filtered list would suggest
              the arrears had gone away; hiding the chips would take away the
              control that produced the empty screen.

              The modifier class IS the bucket key — total, current, d30, d60,
              d90 — and the order is the server's, which is the board's.
            -->
            <div class="age-kpi-row">
                <div v-for="card in ageing" :key="card.key" class="age-kpi" :class="card.key">
                    <div class="av">{{ abbreviated(card.value_minor) }}</div>
                    <!-- Which phase the figure is, on the total: a strip that follows the chips must say so. -->
                    <!-- M32 F12: 90 days is where the arrears restriction begins, so its `i` stands on that card. -->
                    <div class="al">{{ card.label }}<template v-if="card.key === 'total' && filters.phase"> · {{ filters.phase }}</template><InfoButton v-if="card.key === 'd90'" topic="delinquency" /></div>
                </div>
            </div>

            <div class="filter-row">
                <Link
                    :href="chipHref('', filters.overdue)"
                    class="f-chip"
                    :class="{ active: filters.phase === '' }"
                >
                    All phases
                </Link>
                <Link
                    v-for="phase in phases"
                    :key="phase"
                    :href="chipHref(phase, filters.overdue)"
                    class="f-chip"
                    :class="{ active: filters.phase === phase }"
                >
                    {{ phase }}
                </Link>

                <Link
                    :href="chipHref(filters.phase, !filters.overdue)"
                    class="f-chip"
                    :class="{ active: filters.overdue }"
                    :style="overdueChipStyle"
                >
                    90+ days only
                </Link>
            </div>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No unit matches this filter"
                body="Units are in arrears elsewhere in the estate, and the ageing above counts them, but none of them matches this phase, this search or the 90+ filter. Nothing has been lost."
                action-label="Show every unit"
                @action="router.visit(href({ phase: '', overdue: '', q: '', page: 1 }))"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nobody is in arrears"
                body="Every unit's ledger is settled: not one household carries a balance. This list holds only units that owe the estate money, so it empties as they pay and fills again on the next charge run."
            />

            <!--
              A page of the register (M11 A2), sorted and paged in SQL over the whole of it (M32 F5). A page holds at
              most 200 units, so the long-table window it once needed for 2,000 (M11 A3) has nothing left to do here.
            -->
            <DataTable v-else-if="table" :columns="COLUMNS" :rows="rows" :table="table" :noun="noun">
                <template #cell-household="{ row }">
                    <div class="res-cell">
                        <div class="res-avatar">{{ row.initials }}</div>
                        <div>
                            <div class="res-name">{{ row.resident }}</div>
                            <div class="res-sub">{{ row.household }}</div>
                        </div>
                    </div>
                </template>
                <template #cell-balance="{ row }">{{ amount(row.balance_minor) }}</template>
                <template #cell-ageing="{ row }">
                    <div class="age-badge" :class="row.bucket">{{ row.bucket_label }}</div>
                </template>

                <!-- A unit that has never paid is a different fact from one that paid long ago, and the board draws the
                     first as an em dash rather than an empty cell. -->
                <template #cell-last_payment="{ row }">{{ row.last_payment ?? NEVER }}</template>

                <!-- The last reminder sent to this household, read from board 8's dunning log. The board's em dash for
                     one nobody has reminded. -->
                <template #cell-last_reminder="{ row }">
                    {{ row.last_reminder ?? NEVER }}
                    <!--
                      THE FLAG IN FORCE (13 A4). A household the committee has protected is still in arrears and still on
                      this list; the pill is what stops a treasurer chasing it by hand without knowing. The board draws no
                      flagged household, so seeded screens render unchanged.
                    -->
                    <div v-if="row.flag" class="flag-pill" :title="`${row.flag.headline}. Automated reminders are suppressed; the balance is unchanged.`">
                        {{ row.flag.label }}
                    </div>
                </template>

                <template #cell-actions="{ row }">
                    <div class="row-actions">
                        <Link :href="financePath(`/units/${row.id}`)" class="text-link-sm">View ledger</Link>
                    </div>
                </template>
            </DataTable>
        </template>
    </EstateConsole>
</template>

<style scoped>
/* The pager and the sortable headings are the shared table's (M32 F5, Components/DataTable.vue). */

/* Authored (13 A4): amber, because a flag is a condition and not a failure. */
.flag-pill {
    display: inline-block;
    margin-top: 3px;
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--amber-700);
    background: var(--amber-100);
}

/*
 * Default-removal only. The board draws its sub-navigation, its topbar actions,
 * its filter chips and its row links as <div>s; here they are real links and
 * real buttons, and a <button> arrives wearing the system UI font and a border
 * and face of the browser's own. app.css already takes the UA's underline and
 * blue off every anchor on a board page, so nothing is needed for the links.
 *
 * Not one declaration below introduces a colour, a size or a spacing: each of
 * them subtracts something the board never had.
 */
button {
    font: inherit;
}

/*
 * .btn-outline-sm declares its own border and its own white; .btn-primary-sm
 * declares a background and no border at all, so only that one needs the UA
 * border taken off.
 */
button.btn-primary-sm {
    border: 0;
}


button[disabled] {
    cursor: not-allowed;
}

/* The export is an <a> for whoever may take it — a download is a navigation,
 * not a form post — and .btn-outline-sm's border IS its variant, so only the
 * underline the UA adds comes off. See D-045. */
a.btn-outline-sm {
    text-decoration: none;
}
</style>

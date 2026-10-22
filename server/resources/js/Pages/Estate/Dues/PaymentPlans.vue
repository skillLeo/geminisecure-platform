<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { keptByTable } from '../../../lib/tableParams'
import { duesTabs } from './tabs'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Payment plans register — board 5's "Payment plans" tab (12 §2, item 18).
 *
 * NOT DRAWN ON ANY BOARD: board 5 names the tab. The register borrows board 5's
 * own shapes — the subnav, the data table, the ageing badges — so it reads as a
 * tab of one module.
 *
 * A REGISTER, NOT A SECOND PLAN SCREEN. A plan is drawn up, agreed and activated
 * on its household's own screen, board 7, and every row here opens it. Nothing
 * on this page writes.
 *
 * PROGRESS IS COUNTED FROM THE INSTALMENTS. "3 of 6 met · 1 missed" is read off
 * the schedule each time, so a missed month cannot hide behind a status nobody
 * updated.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, required: true },

    /*
     * M10 §5. A household on a plan is a household in arrears, so for a reader
     * who may not have the estate's position the server sends no rows, no
     * counts and this sentence, and the page draws its denied state — not the
     * first-use "No payment plan has been drawn up", which would be false.
     */
    positionWithheld: { type: Boolean, default: false },
    positionReason: { type: String, default: '' },
    /** The register as the shared table reads it (M32 F5); null where it is withheld. */
    table: { type: Object, default: null },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

const state = useScreenState({
    rows: () => props.table?.total ?? props.rows.length,
    filtered: () => props.filter !== '',
})

const COLUMNS = [
    { key: 'reference', label: 'Plan', sortable: true },
    { key: 'unit', label: 'Unit', sortable: true },
    { key: 'household', label: 'Household', sortable: true },
    { key: 'total', label: 'Total', sortable: true, cellClass: 'bal-amt' },
    { key: 'progress', label: 'Progress', sortable: true },
    { key: 'next', label: 'Next instalment', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'open', label: '' },
]

const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

const subnav = computed(() => duesTabs(financePath, 'plans'))

/*
 * A plan's state in the workflow words (Hassam, 11 October): a plan being paid off is In progress, a defaulted one
 * Failed. The filter keys stay the server's; only what is read changes, and the server's word stays on hover.
 */
const PLAN_WORD = { active: 'In progress', draft: 'Draft', defaulted: 'Failed', completed: 'Completed', cancelled: 'Cancelled' }

const planWord = (status, label) => PLAN_WORD[status] ?? label ?? status

const chips = computed(() => [
    { key: '', label: `All (${Object.values(props.counts).reduce((a, b) => a + b, 0)})` },
    ...['active', 'draft', 'defaulted', 'completed', 'cancelled'].map((key) => ({ key, label: `${PLAN_WORD[key]} (${props.counts[key] ?? 0})` })),
])

/* A chip keeps the order and the page size and starts again at page one (M32 F5). */
const chipHref = (key) => {
    const query = new URLSearchParams({ ...(key === '' ? {} : { status: key }), ...keptByTable(props.table) }).toString()

    return financePath(`/payment-plans${query ? `?${query}` : ''}`)
}

/*
 * Board 5's own ageing badge colours, read as standing rather than as age — except red, which is urgent and
 * life-safety only (M12 §4.4, D-185): a defaulted plan is the amber that needs attention, and a draft, which needs
 * none, is neutral.
 */
const badgeStyle = (status) =>
    ({
        active: 'background:var(--success-100);color:var(--success-700);',
        defaulted: 'background:var(--amber-100);color:var(--amber-700);',
    })[status] ?? 'background:var(--navy-100);color:var(--slate-600);'

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['dues'] })
</script>

<template>
    <Head title="Payment plans" />

    <EstateConsole title="Payment plans" :estate-name="estate.name" active="dues_ledger">
        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <div v-if="item.active" class="subnav-item active" aria-current="page">{{ item.label }}</div>
                <Link v-else :href="item.href" class="subnav-item">{{ item.label }}</Link>
            </template>
        </div>

        <!-- "Defaulted (3)" counts households; none is sent where the rows are not. -->
        <div v-if="!positionWithheld" class="pp-chips">
            <Link
                v-for="chip in chips"
                :key="chip.key"
                :href="chipHref(chip.key)"
                class="pp-chip"
                :class="{ on: filter === chip.key }"
            >
                {{ chip.label }}
            </Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="8" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The payment plans could not be loaded"
            body="No plan has changed. Reading them again is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payment plans are not part of your role's access"
            body="A plan says what a household owes and how it is paying it off, so only roles that handle dues can open it. Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="positionWithheld"
            variant="denied"
            title="Which households are on a plan is not part of your role's access"
            :body="positionReason"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No plan in this state"
            body="Every other plan is still on the register. Show them all to see the rest."
            action-label="Show every plan"
            @action="router.get(chipHref(''))"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No payment plan has been drawn up"
            body="A plan is drawn up from a household's ledger, under “Place on payment plan”, and appears here from its first draft."
        />

        <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" noun="plans">
            <template #cell-reference="{ row }"><span class="pp-ref">{{ row.reference }}</span></template>
            <template #cell-unit="{ row }">
                <Link :href="financePath(`/units/${row.unit_id}`)" class="text-link-sm">{{ row.unit }}</Link>
            </template>
            <template #cell-progress="{ row }">
                <span :class="{ 'pp-missed': row.missed > 0 }">{{ row.progress }}</span>
            </template>
            <template #cell-status="{ row }">
                <div class="age-badge" :style="badgeStyle(row.status)" :title="row.status_label">{{ planWord(row.status, row.status_label) }}</div>
            </template>
            <template #cell-open="{ row }">
                <Link
                    :href="financePath(`/units/${row.unit_id}/payment-plan`)"
                    class="text-link-sm"
                    title="Open this household's plan."
                >
                    Open
                </Link>
            </template>
        </DataTable>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal for the sheet's tabs and links, then AUTHORED: the status
 * chips and the missed-instalment emphasis, kept to the tokens the boards define.
 */
a {
    text-decoration: none;
}

.pp-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.pp-chip {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-700);
    background: var(--white);
    border: 1px solid var(--navy-200);
    border-radius: 20px;
    padding: 5px 12px;
}

.pp-chip.on {
    background: var(--navy-600);
    border-color: var(--navy-600);
    color: var(--white);
}

.pp-ref {
    font-weight: 700;
    color: var(--navy-800);
    font-variant-numeric: tabular-nums;
}

/* A missed instalment needs attention, not an alarm: amber (rule 20). */
.pp-missed {
    color: var(--amber-700);
    font-weight: 600;
}
</style>

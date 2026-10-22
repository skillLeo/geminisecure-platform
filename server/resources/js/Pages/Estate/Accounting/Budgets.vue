<script setup>
import { computed } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { toast } from '../../../lib/toasts.js'

/**
 * A MONTH'S BUDGET, ACCOUNT BY ACCOUNT — M84 §3.3, behind FEATURE_BUDGETS.
 *
 * Every open income and expense account, with the amount budgeted for the month. One Save for the month. An empty
 * amount means no budget for that account. Saving posts nothing: Budget vs Actual (Reports) sets these beside the
 * ledger. An amount that is not money is refused under its own account.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    period: { type: String, required: true },
    label: { type: String, required: true },
    rows: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const page = usePage()
const base = computed(() => page.url.split('/accounting')[0])

const amountText = (minor) => (minor === null ? '' : (minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))

const form = useForm({
    period: props.period,
    amounts: Object.fromEntries(props.rows.map((row) => [row.id, amountText(row.amount_minor)])),
})

// A month the server refuses is a message top right that stays until closed (M95 §2); it was drawn nowhere.
const chooseMonth = (event) => {
    router.get(`${base.value}/accounting/budgets`, { period: event.target.value }, {
        preserveScroll: true,
        onError: (errors) => {
            toast(errors.period ?? Object.values(errors)[0] ?? 'That month could not be opened.', { tone: 'error' })
        },
    })
}

// A refused amount keeps every amount typed: Inertia's post keeps the page's state by default (core 3.7).
const save = () => {
    form.post(`${base.value}/accounting/budgets`, { preserveScroll: true })
}

// Loading, and a first use with no open income or expense account to budget, as the chart draws them.
const state = useScreenState({
    rows: () => props.rows.length,
})

// An edit page: never re-read under somebody's typing.
useLiveKind('form')
</script>

<template>
    <Head title="Budgets" />

    <!--
      M95 §3: opened from the chart's Budgets tab, and drawn without the tab row, so the way back to the chart, in
      words. It was a plain "Chart of accounts" link.
    -->
    <EstateConsole
        title="Accounting"
        :estate-name="estate.name"
        active="accounting"
        :back="{ href: `${base}/accounting/chart-of-accounts`, label: 'Back to chart of accounts' }"
    >
        <div class="bud-head">
            <h2 class="bud-title">Budget for {{ label }}</h2>
            <label for="bud-month" class="bud-month-label">Month</label>
            <input id="bud-month" type="month" :value="period" @change="chooseMonth" />
        </div>


        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="Nothing to budget yet"
            body="A budget is set for each open income and expense account, and the chart has none open."
        />

        <form v-else @submit.prevent="save">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Account</th>
                        <th>Type</th>
                        <th class="num-cell">Budget</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.code }}</td>
                        <td>{{ row.name }}</td>
                        <td>{{ row.type }}</td>
                        <td class="num-cell">
                            <input
                                v-model="form.amounts[row.id]"
                                type="text"
                                inputmode="decimal"
                                class="bud-amount"
                                :class="{ 'bud-amount-error': form.errors[`amounts.${row.id}`] }"
                                :aria-label="`Budget for ${row.code} ${row.name}`"
                                :disabled="!canEdit"
                                placeholder="0.00"
                            />
                            <div v-if="form.errors[`amounts.${row.id}`]" class="bud-error">{{ form.errors[`amounts.${row.id}`] }}</div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- M96 level 3: a role that can never set a budget has no Save at all, only why the amounts are read only. -->
            <div class="bud-actions">
                <button
                    v-if="canEdit"
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="form.processing"
                    title="Save this month's budget."
                >
                    <span>{{ form.processing ? 'Saving…' : 'Save budget' }}</span>
                </button>
                <span v-else class="bud-why">{{ blockedReason }}</span>
            </div>
        </form>
    </EstateConsole>
</template>

<style scoped>
.bud-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}

.bud-title {
    margin: 0 12px 0 0;
    font-size: 18px;
    color: var(--text-primary);
}

.bud-month-label {
    font-size: 12px;
    font-weight: 700;
}

/*
 * The month and each amount in the console's field (Hassam, 12 Oct: on live both were the browser's bare boxes). The
 * same height, edge, corner and amber focus as the console's other fields; tokens only.
 */
.bud-head input[type='month'],
.bud-amount {
    -webkit-appearance: none;
    appearance: none;
    height: 38px;
    padding: 0 12px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    background: var(--white);
    color: var(--navy-900);
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
}

.bud-head input[type='month']:focus,
.bud-amount:focus {
    outline: none;
    border-color: var(--amber-500);
    box-shadow: 0 0 0 3px rgba(255, 182, 39, 0.15);
}

.bud-amount {
    width: 140px;
    max-width: 100%;
    text-align: right;
}

.bud-amount:disabled {
    background: var(--navy-100);
    color: var(--slate-600);
}

.data-table td.num-cell {
    text-align: right;
}

@media (max-width: 1023px) {
    form > .data-table {
        display: block;
        overflow-x: auto;
    }

    .bud-amount {
        width: 110px;
    }
}

/* Amber, not red: saturated red is kept for panic and life safety alone. */
.bud-amount-error {
    border-color: var(--amber-700);
}

.bud-error {
    margin-top: 4px;
    font-size: 11px;
    font-weight: 600;
    color: var(--amber-700);
    text-align: right;
}

/* Greyed means greyed (Law 1), as the shared modal greys its own button. */
button[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

button.btn-primary-sm[disabled] {
    color: var(--white);
}

.bud-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
}

.bud-why {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-body);
}
</style>

<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import { toast } from '../../../lib/toasts.js'

/**
 * ONE ACCOUNT'S ENTRIES — M84 §3.1, behind FEATURE_ACCOUNT_DRILLDOWN. READ ONLY: nothing on this page writes.
 *
 * Date, journal reference, memo, debit, credit and the running balance, in the order the books were written. A
 * reference opens the journal that posted the line. The balance brought forward opens the list and the balance carried
 * out closes it, so a running balance is never a sum of only the rows on the screen. The entry that took the account
 * below zero is marked, because that is the one a reader of a negative balance is looking for.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    account: { type: Object, required: true },
    filters: { type: Object, required: true },
    opening_minor: { type: Number, required: true },
    /** The balance this page starts from: the opening on page one, the opening and every earlier page after it. */
    carried_minor: { type: Number, required: true },
    closing_minor: { type: Number, required: true },
    rows: { type: Array, required: true },
    page: { type: Number, required: true },
    last_page: { type: Number, required: true },
    total: { type: Number, required: true },
})

useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const inertiaPage = usePage()
const base = computed(() => inertiaPage.url.split('/accounting')[0])

/** "$1,234.56", "-$100.00": two decimals, always. */
const money = (minor) => `${minor < 0 ? '-' : ''}$${(Math.abs(minor) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

const here = computed(() => `${base.value}/accounting/chart-of-accounts/${props.account.id}`)

// A refused date is a message top right that stays until closed (M95 §2); it was a red line under the dates.
const apply = (page = 1) => {
    router.get(here.value, { ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}), ...(page > 1 ? { page } : {}) }, {
        preserveScroll: true,
        // Refused dates stay in their fields to be corrected (Hassam, 11 Oct).
        preserveState: 'errors',
        onError: (errors) => {
            toast(errors.to ?? errors.from ?? Object.values(errors)[0] ?? 'Those dates could not be used.', { tone: 'error' })
        },
    })
}

/** Whether the reader chose dates: an account nothing has posted to reads differently from dates that hold nothing. */
const filtered = computed(() => /[?&](from|to)=/.test(inertiaPage.url))

const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => filtered.value,
})

// Moves by itself, as the chart it opens from does: a posting anywhere re-reads the entries and the balance.
useLiveReload({ entities: ['accounting', 'dues'] })
</script>

<template>
    <Head :title="`${account.code} ${account.name}`" />

    <!-- M95 §3: opened from an account on the chart, so the way back to it, in words. It was a plain link. -->
    <EstateConsole
        title="Accounting"
        :estate-name="estate.name"
        active="accounting"
        :back="{ href: `${base}/accounting/chart-of-accounts`, label: 'Back to chart of accounts' }"
    >
        <div class="acct-head">
            <h2 class="acct-title">{{ account.code }} {{ account.name }}</h2>
            <span class="acct-type">{{ account.type }}</span>
        </div>

        <form class="acct-filters" @submit.prevent="apply()">
            <label for="acct-from">From</label>
            <input id="acct-from" v-model="from" type="date" />
            <label for="acct-to">To</label>
            <input id="acct-to" v-model="to" type="date" />
            <button type="submit" class="btn-outline-sm" title="Show the entries between these dates.">Show</button>
        </form>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="6" />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Journal</th>
                    <th>Memo</th>
                    <th class="num-cell">Debit</th>
                    <th class="num-cell">Credit</th>
                    <th class="num-cell">Balance</th>
                </tr>
            </thead>
            <tbody>
                <!--
                  Where this page's running balance starts. On a later page that is the opening and every earlier page,
                  not the opening alone: page two read "Brought forward $100.00" over a first row of "$550.00".
                -->
                <tr class="acct-carry">
                    <td colspan="5">Brought forward</td>
                    <td class="num-cell" :class="{ 'acct-negative': carried_minor < 0 }">{{ money(carried_minor) }}</td>
                </tr>
                <!-- Nothing ever posted, and dates that hold nothing, are two different answers. -->
                <tr v-if="rows.length === 0">
                    <td colspan="6" class="acct-empty">{{ filtered ? 'No entries in these dates. Try a wider period.' : 'Nothing has posted to this account yet.' }}</td>
                </tr>
                <tr v-for="row in rows" :key="row.line_id" :class="{ 'acct-below': row.below_zero }">
                    <td>{{ row.date }}</td>
                    <td><Link :href="`${base}/accounting/journals/${row.reference}`" :title="`Open journal ${row.reference}.`">{{ row.reference }}</Link></td>
                    <td>
                        {{ row.memo }}
                        <span v-if="row.below_zero" class="acct-below-tag">Below zero from here</span>
                    </td>
                    <td class="num-cell">{{ row.debit_minor ? money(row.debit_minor) : '' }}</td>
                    <td class="num-cell">{{ row.credit_minor ? money(row.credit_minor) : '' }}</td>
                    <td class="num-cell" :class="{ 'acct-negative': row.balance_minor < 0 }">{{ money(row.balance_minor) }}</td>
                </tr>
                <tr v-if="page === last_page" class="acct-carry">
                    <td colspan="5">Carried forward</td>
                    <td class="num-cell" :class="{ 'acct-negative': closing_minor < 0 }">{{ money(closing_minor) }}</td>
                </tr>
            </tbody>
        </table>

        <div v-if="last_page > 1" class="acct-pages">
            <!-- Greyed on the first and the last page (level 2); "Page 1 of 3" between them is the reason, on the screen. -->
            <button type="button" class="btn-outline-sm" :disabled="page <= 1" title="Show earlier entries." @click="apply(page - 1)">Earlier</button>
            <span>Page {{ page }} of {{ last_page }} · {{ total }} entries</span>
            <button type="button" class="btn-outline-sm" :disabled="page >= last_page" title="Show later entries." @click="apply(page + 1)">Later</button>
        </div>
    </EstateConsole>
</template>

<style scoped>
.acct-head {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 12px;
}

.acct-title {
    margin: 0;
    font-size: 18px;
    color: var(--text-primary);
}

.acct-type {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-body);
}

.acct-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    font-size: 12px;
    font-weight: 600;
}

.acct-carry td {
    font-weight: 700;
}

.acct-empty {
    color: var(--text-body);
}

/* Amber, not red: saturated red is kept for panic and life safety alone. */
.acct-negative {
    color: var(--amber-700);
}

.acct-below-tag {
    display: inline-block;
    margin-left: 8px;
    font-size: 11px;
    font-weight: 700;
    color: var(--amber-700);
}

/* Greyed means greyed (Law 1), as the shared modal greys its own button. */
button[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

.acct-pages {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
    font-size: 12px;
    font-weight: 600;
}
</style>

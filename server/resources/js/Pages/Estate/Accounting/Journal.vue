<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * ONE JOURNAL — M84 §3.1, reached from a line of an account, behind FEATURE_ACCOUNT_DRILLDOWN. READ ONLY.
 *
 * Its date, reference, memo, who posted it and what made it (its source, in words), then every line with its account.
 * A journal is never edited; a correction is a reversal, which names the journal it reverses.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    journal: { type: Object, required: true },
    lines: { type: Array, required: true },
})

useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const inertiaPage = usePage()
const base = computed(() => inertiaPage.url.split('/accounting')[0])

/** "$1,234.56": two decimals, always. */
const money = (minor) => `$${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

// The totals are the sum of the lines drawn above them, nothing else, so they tie by construction.
const debits = computed(() => props.lines.reduce((sum, line) => sum + line.debit_minor, 0))
const credits = computed(() => props.lines.reduce((sum, line) => sum + line.credit_minor, 0))

// Loading draws the console's skeleton. A posted journal always has its lines; a reader without the balances is
// answered 404 by the route before this page exists.
const state = useScreenState({
    rows: () => props.lines.length,
})

// Moves by itself, as every accounting page does.
useLiveReload({ entities: ['accounting'] })
</script>

<template>
    <Head :title="`Journal ${journal.reference}`" />

    <!--
      M95 §3: opened from a line of an account, or from the journal it reverses, so the way back in words. It goes to
      the chart, the one list every way in shares; it was a plain "Chart of accounts" link.
    -->
    <EstateConsole
        title="Accounting"
        :estate-name="estate.name"
        active="accounting"
        :back="{ href: `${base}/accounting/chart-of-accounts`, label: 'Back to chart of accounts' }"
    >
        <h2 class="jrn-title">Journal {{ journal.reference }}</h2>

        <dl class="jrn-facts">
            <div><dt>Date</dt><dd>{{ journal.date }}</dd></div>
            <div><dt>Made by</dt><dd>{{ journal.source }}</dd></div>
            <div v-if="journal.posted_by"><dt>Posted by</dt><dd>{{ journal.posted_by }}</dd></div>
            <div v-if="journal.reverses"><dt>Reverses</dt><dd><Link :href="`${base}/accounting/journals/${journal.reverses}`">{{ journal.reverses }}</Link></dd></div>
            <div class="jrn-memo"><dt>Memo</dt><dd>{{ journal.memo }}</dd></div>
        </dl>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Memo</th>
                    <th class="num-cell">Debit</th>
                    <th class="num-cell">Credit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, i) in lines" :key="i">
                    <td>{{ line.account }}</td>
                    <td>{{ line.memo }}</td>
                    <td class="num-cell">{{ line.debit_minor ? money(line.debit_minor) : '' }}</td>
                    <td class="num-cell">{{ line.credit_minor ? money(line.credit_minor) : '' }}</td>
                </tr>
                <tr class="jrn-total">
                    <td colspan="2">Total</td>
                    <td class="num-cell">{{ money(debits) }}</td>
                    <td class="num-cell">{{ money(credits) }}</td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
.jrn-title {
    margin: 0 0 12px;
    font-size: 18px;
    color: var(--text-primary);
}

.jrn-facts {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 24px;
    margin: 0 0 16px;
    font-size: 12px;
}

.jrn-facts dt {
    font-weight: 700;
    color: var(--text-body);
}

.jrn-facts dd {
    margin: 2px 0 0;
    font-weight: 600;
    color: var(--text-primary);
}

.jrn-memo {
    flex-basis: 100%;
}

.jrn-total td {
    font-weight: 700;
}
</style>

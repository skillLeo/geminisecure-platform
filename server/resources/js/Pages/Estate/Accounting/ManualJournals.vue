<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * THE JOURNALS WRITTEN BY HAND — M84 §3.4, behind FEATURE_MANUAL_JOURNALS. Drafts and posted, newest first; a row opens
 * its journal. A posted one is never edited: a correction is a reversal.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    drafts: { type: Array, required: true },
    canCreate: { type: Boolean, required: true },
})

useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const page = usePage()
const base = computed(() => page.url.split('/accounting')[0])

// Loading and a first use with nothing written, as the chart of accounts draws them. A role without Accounting is
// refused by the route before this page exists.
const state = useScreenState({
    rows: () => props.drafts.length,
})

const money = (minor) => `$${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

// Moves by itself, as every accounting page does.
useLiveReload({ entities: ['accounting'] })
</script>

<template>
    <Head title="Manual journals" />

    <!--
      M95 §3: opened from the chart's Manual journals tab, and drawn without the tab row, so the way back to the chart,
      in words, through the shell. It was a plain "Chart of accounts" link above the table.
    -->
    <EstateConsole
        title="Accounting"
        :estate-name="estate.name"
        active="accounting"
        :back="{ href: `${base}/accounting/chart-of-accounts`, label: 'Back to chart of accounts' }"
    >
        <template #actions>
            <!-- M96 level 3: a role that can never write a journal has no New journal at all. -->
            <Link v-if="canCreate" :href="`${base}/accounting/manual-journals/new`" class="btn-primary-sm" title="Write a new journal.">New journal</Link>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="5" />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="No manual journals yet"
            body="A journal written by hand appears here, as a draft until it is posted."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Memo</th>
                    <th class="num-cell">Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="draft in drafts" :key="draft.id">
                    <td>{{ draft.date }}</td>
                    <td>{{ draft.reference }}</td>
                    <td><Link :href="`${base}/accounting/manual-journals/${draft.id}`">{{ draft.memo }}</Link></td>
                    <td class="num-cell">{{ money(draft.total_minor) }}</td>
                    <!-- The status leads to the journal it is the state of: the draft, where it is posted, or the posted entry. -->
                    <td>
                        <Link :href="draft.status === 'Posted' ? `${base}/accounting/journals/${draft.journal_reference}` : `${base}/accounting/manual-journals/${draft.id}`">
                            {{ draft.status === 'Posted' ? `Posted as ${draft.journal_reference}` : draft.difference_minor === 0 ? 'Draft, balanced' : 'Draft, not balanced' }}
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

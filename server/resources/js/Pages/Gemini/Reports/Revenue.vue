<script setup>
import { Head } from '@inertiajs/vue3'
import ReportShell from './ReportShell.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// A report is a figure worked out at a moment: "As of …", and Refresh works it out again (M18H §7.2).
useLiveKind('snapshot')

/**
 * Revenue by tier — board screen super-admin-38.
 *
 * THE DONUT AND THE TABLE ARE ONE DATASET SHOWN TWICE, so they cannot
 * disagree: the ring's stops, the legend's percentages and the table's "% of
 * MRR" column all come from the same arithmetic, computed once in the service.
 * A donut drawn from one query and a table from another is how a screen ends
 * up contradicting itself about what a tier earns.
 *
 * The ring is a conic-gradient, which is the board's own device — no chart
 * library, no SVG arcs.
 *
 * A client not billing shows "Not billing" rather than a zero. Zero and
 * not-billing-yet are different facts and only one of them is a problem.
 */
const props = defineProps({
    total: { type: String, required: true },
    totalCompact: { type: String, required: true },
    tiers: { type: Array, required: true },
    gradient: { type: String, required: true },
    clients: { type: Array, required: true },
    exportHref: { type: String, default: null },
    exportDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.clients.length,
})
</script>

<template>
    <Head title="Revenue by tier" />

    <ReportShell
        title="Revenue by tier"
        exportable
        :export-href="exportHref"
        :export-disabled-reason="exportDisabledReason"
    >
        <div class="panel" style="margin-bottom: 16px">
            <div class="panel-head">
                <h3>Current split · {{ total }} MRR</h3>
            </div>

            <EmptyState
                v-if="tiers.length === 0"
                variant="first-use"
                title="Nothing billing yet"
                body="The split appears once a client is on a tier and invoicing."
            />

            <div v-else class="donut-wrap">
                <!-- The ring's stops come from the same figures the legend
                     prints, so they cannot drift apart. -->
                <div class="donut" :style="{ background: gradient }">
                    <div class="donut-center">
                        <div class="dc1">{{ totalCompact }}</div>
                        <div class="dc2">Total MRR</div>
                    </div>
                </div>
                <div class="donut-legend">
                    <div v-for="tier in tiers" :key="tier.key" class="dl-row">
                        <div class="dl-dot" :class="tier.key"></div>
                        <div class="dl-name">{{ tier.name }}</div>
                        <div class="dl-pct">{{ tier.percent }}%</div>
                        <div class="dl-val">{{ tier.amount }}</div>
                    </div>
                </div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="5" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No clients yet"
            body="Each client's contribution to recurring revenue appears here once they are onboarded."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Tier</th>
                    <th>Units</th>
                    <th>Contribution</th>
                    <th>% of MRR</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in clients" :key="i">
                    <td>{{ row.client }}</td>
                    <td>
                        <div v-if="row.tier_key" class="tier-badge" :class="row.tier_key">{{ row.tier }}</div>
                        <template v-else>{{ row.tier }}</template>
                    </td>
                    <td>{{ row.units }}</td>
                    <td class="num-cell">{{ row.contribution }}</td>
                    <td class="num-cell">{{ row.share }}</td>
                </tr>
            </tbody>
        </table>
    </ReportShell>
</template>

<style scoped>
/*
 * The per-guard add-on's legend dot (Hassam, 12 October: every total ties to its rows). The add-on is the split's last
 * line now, so the legend's amounts are the total; the board draws dots for the three tiers only, and this one is the
 * ring's own slate for that slice (`RevenueReports::donutGradient()`).
 */
.dl-dot.guard_addon {
    background: var(--slate-300);
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct: the legend's amounts ran off the right, the page 460 px wide). The ring above,
 * its legend below, the legend free to take the whole width.
 */
@media (max-width: 640px) {
    .donut-wrap.donut-wrap {
        flex-wrap: wrap;
        justify-content: center;
        gap: 18px;
    }

    .donut-legend.donut-legend {
        flex: 1 1 100%;
        min-width: 0;
    }
}
</style>

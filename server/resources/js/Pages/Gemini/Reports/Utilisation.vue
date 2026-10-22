<script setup>
import { Head } from '@inertiajs/vue3'
import ReportShell from './ReportShell.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// A report is a figure worked out at a moment: "As of …", and Refresh works it out again (M18H §7.2).
useLiveKind('snapshot')

/**
 * Guard utilization — board screen super-admin-40.
 *
 * THE GAP IS THE REPORT. Contracted comes from the subscription, deployed from
 * the guards table, and a client paying for four guards but staffed by three
 * is an unfilled contract that is invisible from either number alone. That is
 * the only reason this screen exists.
 *
 * A guard on leave or suspended counts as NOT on active duty. A post nobody is
 * standing is unstaffed whatever the reason, and a fill rate that counted
 * absent guards would report a gate as covered while it is empty.
 *
 * Two cases the board words differently, and both are real states rather than
 * degenerate ones: a client managing their own security contracts nothing, and
 * a client mid-onboarding has contracted guards who have not started. Neither
 * is a zero to be shown as a failure.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
})

const state = useScreenState({
    rows: () => props.rows.length,
})
</script>

<template>
    <Head title="Guard utilization" />

    <ReportShell title="Guard utilization">
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="kpi.icon === 'check' ? 3 : 1.7" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3>Fill rate by client</h3>
            </div>

            <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No clients yet"
                body="Fill rate compares the guards a client contracts against the guards actually posted there. It appears once a client is onboarded."
            />

            <div v-for="(row, i) in rows" v-else :key="i" class="util-row">
                <div class="util-top">
                    <span class="un">{{ row.client }}</span>
                    <span class="uv">{{ row.detail }}</span>
                </div>
                <div class="util-track">
                    <!-- The muted track is the board's own inline style. A
                         client contracting nothing has no bar to fill; nothing
                         is 100% staffed of nothing. -->
                    <i :style="row.muted ? `width:${row.percent}%;background:var(--navy-200);` : `width:${row.percent}%`"></i>
                </div>
            </div>
        </div>
    </ReportShell>
</template>

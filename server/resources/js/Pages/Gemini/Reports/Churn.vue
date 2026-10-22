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
 * Churn and retention — board screen super-admin-39.
 *
 * CHURN IS MEASURED ON CANCELLED SUBSCRIPTIONS, not on estate status. An
 * estate suspended for non-payment has not left — it is being chased — and
 * counting it as churn would make a collections problem look like a retention
 * one, which are two different conversations with two different people.
 *
 * THE EMPTY PANEL IS THE FINDING. When nothing has ever been cancelled the
 * board draws a filled empty-state under the tenure list saying so, and that
 * is the report rather than a missing section. It is replaced by real rows the
 * first time a client leaves.
 *
 * This board draws no Export control, so none is rendered — not even a
 * disabled one. Adding a control the design does not have is the opposite of
 * reproducing it.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    tenures: { type: Array, required: true },
    cancellations: { type: Array, default: null },
})

const state = useScreenState({
    rows: () => props.tenures.length,
})
</script>

<template>
    <Head title="Churn &amp; retention" />

    <ReportShell title="Churn &amp; retention">
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="1.7" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3>Client tenure</h3>
            </div>

            <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="3" />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No clients yet"
                body="Tenure is counted from the day a client's subscription starts. It appears here once one does."
            />

            <template v-else>
                <div v-for="(row, i) in tenures" :key="i" class="retain-row">
                    <div class="retain-icon">
                        <BoardIcon name="check-circle" :stroke="2" />
                    </div>
                    <div class="retain-txt">
                        <div class="rt1">{{ row.client }}</div>
                        <div class="rt2">{{ row.detail }}</div>
                    </div>
                    <div class="retain-badge">Active</div>
                </div>

                <!--
                  Nothing cancelled. The board spends a panel on saying so,
                  because "no churn" is the single most important thing this
                  report can report and a blank space does not say it.
                -->
                <div v-if="cancellations === null" class="empty-state">
                    <BoardIcon name="check-circle" :stroke="1.8" />
                    <div class="es1">No cancellations on record</div>
                    <div class="es2">
                        Every client that has onboarded to GeminiSecure is still active. This panel will show
                        cancellation reasons here if that changes.
                    </div>
                </div>

                <div v-for="(row, i) in cancellations ?? []" :key="'c' + i" class="retain-row">
                    <div class="retain-icon">
                        <BoardIcon name="x-circle" :stroke="2" />
                    </div>
                    <div class="retain-txt">
                        <div class="rt1">{{ row.client }}</div>
                        <div class="rt2">{{ row.detail }}</div>
                    </div>
                </div>
            </template>
        </div>
    </ReportShell>
</template>

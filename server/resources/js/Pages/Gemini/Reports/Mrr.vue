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
 * MRR trend — board screen super-admin-37.
 *
 * THE BARS ARE HISTORY, NOT A PROJECTION BACKWARDS. Each column is what that
 * month's invoices actually totalled, so a client who joined in May does not
 * appear in April. Replaying today's subscriptions over past months would draw
 * a flat line and call it growth.
 *
 * The chart is the board's own markup — CSS-height divs, not a chart library.
 * A hand-drawn bar chart is what the design specifies and substituting
 * chart.js would change every pixel of it.
 *
 * The annotation on a bar names the event that moved it. Where a month has two,
 * the bar shows the first and the panel beside it lists them all: stacking
 * labels on a 40px column makes both unreadable.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    bars: { type: Array, required: true },
    events: { type: Array, required: true },
    exportHref: { type: String, default: null },
    exportDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.bars.length,
})
</script>

<template>
    <Head title="MRR trend" />

    <ReportShell
        title="MRR trend"
        exportable
        :export-href="exportHref"
        :export-disabled-reason="exportDisabledReason"
    >
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="1.7" />
                    </div>
                    <!-- The board only ever drew growth, so only the `up`
                         variant exists in its stylesheet. A fall still has to
                         be readable, so it drops the modifier rather than
                         wearing a class that says the opposite. -->
                    <div v-if="kpi.trend" class="kpi-trend" :class="{ up: !kpi.trend.startsWith('-') }">
                        {{ kpi.trend }}
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 18px">
            <div class="panel">
                <div class="panel-head">
                    <h3>Platform MRR, last 6 months</h3>
                </div>

                <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nothing invoiced yet"
                    body="The trend is built from what each month was actually invoiced. It appears once the first billing period closes."
                />

                <div v-else class="chart-area">
                    <!-- The month's exact total on the column, the figure the export carries; the label is the chart's compression. -->
                    <div v-for="(bar, i) in bars" :key="i" class="chart-bar-col" :title="`${bar.month}: ${bar.exact} invoiced`">
                        <div class="chart-bar-val">{{ bar.value }}</div>
                        <div
                            class="chart-bar"
                            :style="
                                bar.annotation
                                    ? `height:${bar.height}%;position:relative;`
                                    : `height:${bar.height}%`
                            "
                        >
                            <div v-if="bar.annotation" class="chart-annot">{{ bar.annotation }}</div>
                        </div>
                        <div class="chart-bar-lbl">{{ bar.label }}</div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>Contributing events</h3>
                </div>

                <EmptyState
                    v-if="events.length === 0"
                    variant="first-use"
                    title="No events yet"
                    body="Onboardings and guard contracts are what move recurring revenue. They are listed here as they happen."
                />

                <div v-for="(event, i) in events" :key="i" class="event-row">
                    <div class="e-icon">
                        <BoardIcon :name="event.icon" :stroke="1.6" />
                    </div>
                    <div class="e-txt">
                        <div class="et1">{{ event.title }}</div>
                        <div class="et2">{{ event.detail }}</div>
                    </div>
                </div>
            </div>
        </div>
    </ReportShell>
</template>

<style scoped>
/*
 * ON A PHONE (seen at 390 px, 12 Oct: a tile cut at the edge, and a bar's annotation, drawn on one line, running 565 px
 * wide). Tiles two to a row; the annotation wraps over the bar it is about.
 */
@media (max-width: 640px) {
    .kpi-row.kpi-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .chart-annot.chart-annot {
        white-space: normal;
        width: max-content;
        max-width: 110px;
        text-align: center;
    }
}
</style>

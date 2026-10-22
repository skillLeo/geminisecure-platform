<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveReload } from '../../../composables/useLiveReload.js'

/**
 * Payroll overview — board screen super-admin-28.
 *
 * DOM and class names are the board's: a tab strip, one exception banner, four
 * KPI cards and the run table, in that order and nothing else.
 *
 * The board draws every control as a <div>. Here the four tabs are Links, and
 * the run table is the shared one (M32 F5): every heading sorts the whole set,
 * newest period first until one is asked for, a page at a time.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    /** The one alert the board has room for, or null when nothing is wrong. */
    banner: { type: Object, default: null },
    runs: { type: Array, required: true },
    filters: { type: Object, required: true },
    /** The runs as the shared table reads them (M32 F5): newest period first, a page at a time. */
    table: { type: Object, required: true },
})

/* Sorting and paging are the server's, in the URL (M32 F5): a sorted view can be linked, reloaded and shared. */
const COLUMNS = [
    { key: 'period', label: 'Period', sortable: true },
    { key: 'employees', label: 'Employees', sortable: true },
    { key: 'gross', label: 'Gross', sortable: true, cellClass: 'num-cell' },
    { key: 'net', label: 'Net pay', sortable: true, cellClass: 'num-cell' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'action', label: '' },
]

const searchParams = computed(() => keptByTable(props.table))
const rowOpens = (run) => ({ onClick: (event) => openRun(run, event), style: 'cursor:pointer' })

/*
 * The whole row opens the run, not only the link at the end of it.
 *
 * Guarded twice: a click that landed on the link is left to the link, and a
 * click that ends a text selection is someone reading a figure, not navigating.
 */
const openRun = (run, event) => {
    if (event.target.closest('a, button')) {
        return
    }

    if (window.getSelection()?.toString()) {
        return
    }

    router.visit(`/payroll/${run.id}`)
}

const clearSearch = () => router.get('/payroll', {}, { preserveScroll: true })

/* The five states (rule 19): this page drew only its populated and its two empty ones. */
const state = useScreenState({
    rows: () => props.runs.length,
    filtered: () => !!props.filters.q,
})

/* A read that failed is read again in place; nothing on this screen writes by reloading. */
const retry = () => router.reload({ preserveScroll: true })

/* The banner and the tiles belong to runs on the screen, never above a loading, refused or failed page. */
const showsRuns = computed(() => !state.isLoading.value && !state.isDenied.value && !state.isError.value)

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['payroll'] })
</script>

<template>
    <Head title="Payroll & accounting" />

    <GeminiConsole title="Payroll & accounting" search-route="/payroll" :search-value="filters.q" :search-params="searchParams">
        <!-- The four tabs in the board's own order. -->
        <div class="subnav">
            <Link href="/payroll" class="subnav-item active">Pay runs</Link>
            <Link href="/guards" class="subnav-item" title="The people paid here are the guards: this opens Guard workforce.">Employees</Link>
            <Link href="/payroll/filings" class="subnav-item">Statutory filings</Link>
            <!-- Built since this tab was drawn inert: the rate table is its own
                 screen, and the other two payroll tabs already link it. -->
            <Link href="/payroll/rates" class="subnav-item">Rate table</Link>
        </div>

        <div v-if="banner && showsRuns" class="exception-banner">
            <BoardIcon name="warning" />
            <div>
                <div class="eb1">{{ banner.title }}</div>
                <div class="eb2">{{ banner.detail }}</div>
            </div>
        </div>

        <div v-if="showsRuns" class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Your role does not reach payroll and accounting"
            body="Ask a platform administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The pay runs could not be read"
            body="Nothing was approved or changed. Reading them again is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="`No pay run matches “${filters.q}”`"
            body="Every other run is still here. Clear the search to see them all."
            action-label="Clear search"
            @action="clearSearch"
        />

        <!-- In sentences, with no em dash (Law 3): it read "reproduces exactly — to the cent — years later". -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No payroll runs yet"
            body="Each run is calculated on the statutory rates in force for its period and records them, so it can be reproduced to the cent years later."
        />

        <DataTable v-else :columns="COLUMNS" :rows="runs" :table="table" noun="pay runs" :row-attrs="rowOpens">
            <template #cell-status="{ row: run }">
                <!-- Batch 1 item 10: the status opens the run, where it is reviewed and approved. -->
                <Link
                    :href="`/payroll/${run.id}`"
                    class="status-badge status-link"
                    :class="run.badge_class"
                    :title="run.blocked_reason || run.action_label"
                >{{ run.badge_label }}</Link>
            </template>
            <template #cell-action="{ row: run }">
                <Link :href="`/payroll/${run.id}`" class="text-link-sm">{{ run.action_label }}</Link>
            </template>
        </DataTable>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and every rule takes a browser default back off
 * rather than adding a style of its own.
 *
 * The board draws the tabs and the row action as <div>s, which is free for a
 * still image. They are a link and real buttons here, so the UA's link
 * underline and the button's own face, border and font would otherwise show
 * through and change the pixels. .subnav-item and .text-link-sm already state
 * everything else — size, weight, colour, padding, radius — so nothing below
 * restates any of it.
 */
a.subnav-item,
.text-link-sm {
    text-decoration: none;
}

/*
 * AMBER, NOT RED (the universal order, rule 20: saturated red is for panic and life safety; a run's exceptions are an
 * ordinary warning). The board's sheet grounds the banner red; app.css already makes its words amber. The class is
 * doubled so it outranks the sheet's selector.
 */
.exception-banner.exception-banner {
    background: var(--amber-100);
    border-color: rgba(180, 83, 9, 0.25);
}

/* Batch 1 item 10: a status that leads somewhere reads as one, and keeps its badge's colours. */
.status-link {
    text-decoration: none;
    cursor: pointer;
}

.status-link:hover,
.status-link:focus-visible {
    text-decoration: underline;
}
</style>

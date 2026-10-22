<script setup>
import { computed, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWindowedRows } from '../../../composables/useWindowedRows'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveKind } from '../../../composables/useLiveKind'
import { toast } from '../../../lib/toasts.js'

/**
 * One report, run — board screen community-admin-30.
 *
 * THE PERIOD IS ON THE PAGE, ALWAYS. "A report with an unstated period is the
 * defect that surfaces at an audit" is the P&L card's own reason on the
 * catalogue, and it is the rule for all of them: the window is drawn under the
 * title, it is what the reader chose, and it goes into the audit scope of the
 * export. A figure without its window is a figure somebody will quote.
 *
 * THE NOTE IS PART OF THE REPORT AND NOT A FOOTNOTE. Each one says something a
 * reader would otherwise get wrong — that a P&L is by posting date and not due
 * date, that an ageing is a position today and the chosen dates do not narrow
 * it, that turnout is a count and nothing finer. Those are the sentences that
 * stop a correct report being read incorrectly.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    key: { type: String, required: true },
    title: { type: String, required: true },
    period: { type: String, required: true },
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    summary: { type: Array, required: true },
    note: { type: String, default: null },
    from: { type: String, required: true },
    to: { type: String, required: true },
    /** M83 item 3: false on the two reports the dates do not narrow (Arrears Ageing, Election Turnout): no From and To. */
    periodApplies: { type: Boolean, default: true },
    catalogueHref: { type: String, required: true },
    path: { type: String, required: true },
    exportHref: { type: String, required: true },
})

useWireframe('community-admin-08-reports-unit-claims-and-notices')

// A report is a figure worked out at a moment: "As of …", and Refresh works it out again (M18H §7.2).
useLiveKind('snapshot')

/*
 * A PERIOD THE SERVER REFUSED arrives as `errors.period` — a date the calendar
 * does not have, or one that ends before it begins — and the page it arrives on
 * is this report over the period it states instead. The sentence says both, so
 * nobody reads this year's figures as the answer to the dates they typed.
 */
const page = usePage()

/*
 * It is a message top right that stays until closed (M95 §2), where it was a red banner. Said from the page's props, not
 * a visit's onError, because a refused export or a typed address lands here as a fresh page with no visit to answer.
 */
watch(
    () => page.props.errors,
    (errors) => {
        if (errors?.period) {
            toast(errors.period, { tone: 'error' })
        }
    },
    { immediate: true },
)

const state = useScreenState({
    rows: () => props.rows.length,
})

/*
 * The arrears ageing is one row per unit that owes anything, so on a 2,000-lot
 * estate it can be 2,000 rows. Past 250 only those on screen are drawn (M11 A3);
 * at or below it — 163 rows for the ageing on the production-sized copy of
 * Phoenix Park today, the longest of its six reports — the table is unchanged.
 * See useWindowedRows.
 */
const reportRows = useWindowedRows(() => props.rows)

const retry = () => router.reload()

/*
 * The two date boxes, apart from the period the report was run over (`from` and `to`, the server's): what is being
 * typed is not yet what the figures, the empty sentence or the export are of.
 */
const fromInput = ref(props.from)
const toInput = ref(props.to)

/*
 * M96 LEVELS. Run is greyed while the dates cannot make a period (one missing, or the end before the start), with the
 * reason beside it; the server refuses both anyway, and a button that only answers with a refusal is the thing Law 1
 * forbids.
 */
const periodProblem = computed(() => {
    if (!fromInput.value || !toInput.value) {
        return 'Choose a date at each end first.'
    }

    return fromInput.value > toInput.value ? 'The period ends before it begins.' : null
})

const rerun = () => {
    if (periodProblem.value !== null) {
        return
    }

    router.get(props.path, { from: fromInput.value, to: toInput.value }, { preserveScroll: true })
}
</script>

<template>
    <Head :title="title" />

    <!-- M95 §3: opened from a card on the report list, so the way back to it, in words; it was a bare round arrow. -->
    <EstateConsole :title="title" :estate-name="estate.name" active="reports" :back="{ href: catalogueHref, label: 'Back to reports' }">

        <template #actions>
            <!--
              M96: Export at its level. The file is this report over the period on the screen (its address carries the
              server's dates, never the boxes being edited), so it holds what is drawn here. A report with nothing in its
              period has nothing to put in a file: Export is greyed and says why, rather than handing over a file with no
              rows in it (Hassam, 12 October: a report that exports nothing is worse than one that does not export).
            -->
            <a
                v-if="rows.length"
                :href="exportHref"
                class="btn-outline-sm"
                title="Download this report as a CSV."
            >
                <span>Export</span>
            </a>
            <button
                v-else
                type="button"
                class="btn-outline-sm"
                disabled
                title="Nothing falls in this period, so there is nothing to export."
            >
                <span>Export</span>
            </button>
        </template>

        <!--
          The period, first and unmissable. It is what the reader chose and what
          every figure below is of.
        -->
        <div class="rp-period">
            <div class="rp-window">{{ period }}</div>

            <!-- M83 item 3: only where the dates narrow the report; the window above says what the others cover. -->
            <form v-if="periodApplies" class="rp-dates" @submit.prevent="rerun">
                <label for="rp-from">From</label>
                <input id="rp-from" v-model="fromInput" type="date" />
                <label for="rp-to">To</label>
                <input id="rp-to" v-model="toInput" type="date" />
                <button
                    type="submit"
                    class="btn-outline-sm"
                    :disabled="periodProblem !== null"
                    :title="periodProblem ?? 'Run this report again over the period chosen.'"
                >
                    <span>Run</span>
                </button>
                <span v-if="periodProblem" class="rp-why">{{ periodProblem }}</span>
            </form>
        </div>

        <div v-if="summary.length" class="rp-summary">
            <div v-for="item in summary" :key="item.label" class="rp-card">
                <div class="rp-label">{{ item.label }}</div>
                <div class="rp-value">{{ item.value }}</div>
            </div>
        </div>

        <p v-if="note" class="rp-note">{{ note }}</p>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The report could not be run"
            body="Nothing on this screen writes anything, so nothing has changed and running it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          A report with no rows over a chosen period is a real answer, and a
          different one from a report that failed. The period is in the sentence
          because it is the reason: the period it was run over, not the dates
          being typed. The ageing is a position as at today, which no wider
          period changes, so it says that instead.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="filtered"
            :title="periodApplies ? 'Nothing falls in this period' : 'Nothing to show'"
            :body="periodApplies ? `No rows between ${from} and ${to}. Try a wider period.` : `${period}.`"
        />

        <table v-else class="data-table" :aria-rowcount="reportRows.rowCount">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column">{{ column }}</th>
                </tr>
            </thead>
            <tbody :ref="reportRows.bind">
                <!-- Stand-ins for the rows above and below the screen; only past 250 rows. -->
                <tr v-if="reportRows.before" data-window-spacer="before" aria-hidden="true">
                    <td :colspan="columns.length" :style="reportRows.spacerStyle(reportRows.before)"></td>
                </tr>
                <!-- Keyed by the row's place in the whole report, not in what is drawn. -->
                <tr v-for="(row, index) in reportRows.visible" :key="reportRows.offset + index" v-bind="reportRows.rowAttrs(index)">
                    <td v-for="(cell, i) in row" :key="i">{{ cell }}</td>
                </tr>
                <tr v-if="reportRows.after" data-window-spacer="after" aria-hidden="true">
                    <td :colspan="columns.length" :style="reportRows.spacerStyle(reportRows.after)"></td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. Board 30 is the catalogue's result screen and this sheet gives it a
 * table and nothing else, so the period strip, the summary cards and the note
 * are authored — kept to the tokens the boards define.
 */
a.btn-outline-sm {
    text-decoration: none;
}

button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

/* Greyed means greyed (M96 level 2), as the shared modal greys its own confirm. */
button.btn-outline-sm[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

/* Why Run is greyed, beside it, where a touch screen can read it too. */
.rp-why {
    font-size: 10.5px;
    line-height: 1.4;
    color: var(--slate-500);
}

.rp-period {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--navy-100);
    border-radius: 12px;
    padding: 11px 15px;
    margin-bottom: 14px;
}

.rp-window {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.rp-dates {
    display: flex;
    align-items: center;
    gap: 8px;
}

.rp-dates label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.rp-dates input {
    height: 30px;
    border: 1px solid var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    padding: 0 9px;
    font: inherit;
    font-size: 11.5px;
    color: var(--navy-900);
}

.rp-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 11px;
    margin-bottom: 14px;
}

.rp-card {
    flex: 1 1 150px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 11px 14px;
}

.rp-label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.rp-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.4;
}

.rp-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 14px;
    max-width: 840px;
}

/*
 * EVERY CELL OF A ROW STARTS ON ONE LINE (M71 E2E-M10). A cell is centred in its row by default, so a long account name
 * that wrapped to two lines left its amount half a line below the name's first. On the first line's baseline, each row
 * reads across as one line.
 */
.data-table td {
    vertical-align: baseline;
}
</style>

<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BillingTabs from './BillingTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useLiveReload } from '../../../composables/useLiveReload'

// An invoice issued, paid or credited, or a client's subscription changed, moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['invoice', 'client'] })

/**
 * Billing Overview — board screen super-admin-32.
 *
 * DOM and class names are the board's: the subnav, the four KPI cards, and one
 * data table whose rows are each estate's billing position. Where the board
 * draws a control as a <div>, this renders a real <button> or <Link>.
 *
 * Two of the three tabs are deliberately inert. Plans is board screen 34 and
 * Payment methods is board screen 35; neither is built, and payment methods in
 * particular has no central record at all because card capture sits behind an
 * adapter that does not exist (D-023). Both say so on hover rather than
 * swallowing a click.
 *
 * A row's action always leads somewhere: a raised invoice opens board 33, and a
 * period not yet invoiced opens its preview, which says it is a projection.
 *
 * Note for anyone extending this screen: an invoice status shown here gates
 * BILLING only. Dunning and suspension never restrict entry, a safety function
 * or a resident — access is never withheld over a billing dispute.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    search: { type: String, default: '' },
    /** The rows as the shared table reads them (M32 F5): newest period first, sorted and paged by the server. */
    table: { type: Object, required: true },
})

const COLUMNS = [
    { key: 'client', label: 'Client', sortable: true },
    { key: 'period', label: 'Period', sortable: true },
    { key: 'amount', label: 'Amount', sortable: true, cellClass: 'num-cell' },
    { key: 'due', label: 'Due date', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'action', label: '' },
]

const searchParams = computed(() => keptByTable(props.table))
</script>

<template>
    <Head title="Billing &amp; subscriptions" />

    <GeminiConsole
        title="Billing &amp; subscriptions"
        search-route="/billing"
        :search-value="search"
        :search-params="searchParams"
    >
        <BillingTabs active="invoices" />

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <!-- .alert appears only when there really is money overdue; drawn amber below (rule 20). -->
                    <div class="kpi-icon" :class="{ alert: kpi.alert }">
                        <BoardIcon :name="kpi.icon" :stroke="1.7" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <EmptyState
            v-if="rows.length === 0 && search === ''"
            variant="first-use"
            title="Nothing to bill yet"
            body="An invoice is raised per estate, per period, priced by unit count against the estate's plan. The first one appears here as soon as an estate is on a subscription."
        />

        <EmptyState
            v-else-if="rows.length === 0"
            variant="filtered"
            :title="`No invoices match “${search}”`"
            body="Every other invoice is still here. Clear the search to go back to the current billing position."
        />

        <DataTable v-else :columns="COLUMNS" :rows="rows" :table="table" row-key="key" :noun="search ? 'invoices' : 'rows'">
            <template #cell-client="{ row }">
                <div class="client-cell">
                    <div class="client-avatar">{{ row.initials }}</div>
                    <div class="client-name">{{ row.estate }}</div>
                </div>
            </template>
            <template #cell-due="{ row }">{{ row.due_on }}</template>
            <!-- The status leads to the invoice or the preview, where it is acted on (batch 1, item 10). -->
            <template #cell-status="{ row }">
                <Link :href="row.href" class="status-link" :title="`${row.action}: ${row.estate}.`">
                    <span class="status-badge" :class="row.status">{{ row.status_label }}</span>
                </Link>
            </template>
            <template #cell-action="{ row }">
                <!--
                  A raised invoice opens; a period not yet billed opens
                  its preview, which says it is a projection and not a
                  document (12 §2, item 44).
                -->
                <Link :href="row.href" class="text-link-sm">{{ row.action }}</Link>
            </template>
        </DataTable>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only CSS in this file, and the only kind allowed: a reset.
 *
 * The board draws the tabs and the row action as <div>s. They are real <button>
 * and <a> elements here, which means the browser's own control defaults —
 * border, background colour, font family, line height, text alignment, link
 * underline, inline-block flow — show through and move the pixels. Every rule
 * below takes one of those defaults back off so that the board's own
 * .subnav-item and .text-link-sm rules are what is seen. Nothing here adds
 * styling of its own.
 *
 * Element selectors, not the class on its own, on purpose: `.subnav-item.active`
 * sets a white background, and a bare `.subnav-item { background: transparent }`
 * would out-specify it once the scoped attribute is added and repaint the
 * selected tab.
 *
 * Padding is absent from this list because the board's own stylesheet already
 * zeroes it on everything, and .subnav-item then puts its own back.
 */
/* A status that leads somewhere: the badge itself, drawn as a link on hover and focus (batch 1, item 10). */
.status-link {
    display: inline-block;
}

.status-link:hover .status-badge,
.status-link:focus-visible .status-badge {
    text-decoration: underline;
}

button.subnav-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

a.subnav-item {
    text-decoration: none;
}

button.text-link-sm {
    /* display:block restores the board's <div> flow. A button is inline-block,
       which sits on a baseline and would add descender space to the row. */
    display: block;
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

/* Same reason as the button above: the board's element is a <div>, and an
 * anchor is inline. .text-link-sm supplies the colour and weight. */
a.text-link-sm {
    display: block;
    text-decoration: none;
}

/*
 * Disabled, and honest about it — but not dimmed. The board draws these at full
 * colour and the fidelity of this screen is measured against it, so the state is
 * carried by the disabled attribute, the title, and the cursor rather than by
 * repainting pixels the design already decided.
 */
button.subnav-item[disabled],
button.text-link-sm[disabled] {
    cursor: not-allowed;
}

/*
 * Money overdue is a thing to chase, not an emergency: amber, where the board's own .alert is red (rule 20, Hassam,
 * 11 Oct: saturated red is kept for panic and life safety). Only this page's icon changes; the board sheet stays.
 */
.kpi-row .kpi-card .kpi-icon.alert {
    background: var(--amber-100);
}

/* As specific as it must be: seen on the local dev server, the board's own rule kept the icon red over `.kpi-icon.alert`. */
.kpi-row .kpi-card .kpi-icon.alert :deep(svg) {
    color: var(--amber-700);
}

/*
 * ON A PHONE (seen at 390 px, 12 Oct: the page ran 477 px wide with a tile cut at its edge). The Gemini shell carries
 * none of the estate shell's phone rules, so the tiles fall to two to a row here, in the page's own style. Doubled
 * class: the board's sheet names the row once, and this must outrank it.
 */
@media (max-width: 640px) {
    .kpi-row.kpi-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>

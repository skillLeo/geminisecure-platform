<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BillingTabs from './BillingTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveKind } from '../../../composables/useLiveKind'

// The platform's payment methods are settings: an editor, never re-read under somebody's changes (M18H §7.2).
useLiveKind('form')

/**
 * Payment methods — board screen super-admin-35.
 *
 * How each client settles their platform invoices. D-023 makes this necessary
 * rather than decorative: manual recording is the day-one path, and a payment
 * cannot be matched to a client without knowing which account it came from.
 *
 * DRIVEN FROM THE CLIENT LIST, not the methods table. A client with nothing on
 * file is the row that matters most — "Not yet on file · Needed before
 * go-live" — and listing only the methods that exist would silently drop
 * exactly the client an operator needs to chase.
 *
 * NO CARD DATA REACHES THIS SCREEN because none exists to reach it. The schema
 * holds an institution name and four characters; there is no PAN column, no
 * expiry and no token. When card capture arrives it arrives behind the
 * PaymentGateway interface with a gateway-side token, and the token stays with
 * the gateway.
 *
 * Both row controls are inert. Offering to add a card would be offering a
 * capability the platform does not have.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    /** Every client as the shared table reads them (M32 F5): by name, a page at a time. */
    table: { type: Object, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.table.total,
})

const COLUMNS = [
    { key: 'client', label: 'Client', sortable: true },
    { key: 'method', label: 'Method', sortable: true },
    { key: 'detail', label: 'Details' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'action', label: '' },
]
</script>

<template>
    <Head title="Payment methods" />

    <GeminiConsole title="Billing &amp; subscriptions">
        <BillingTabs active="payment-methods" />

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="5" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No clients yet"
            body="Settlement details are recorded per client. They can be entered once an estate is onboarded."
        />

        <template v-else>
        <!-- M96 level 2: why every row's Add and Edit is greyed, once, above the table, readable without hovering. -->
        <p class="pm-why">{{ writeDisabledReason }}</p>

        <DataTable :columns="COLUMNS" :rows="rows" :table="table" noun="clients">
            <template #cell-client="{ row }">
                <div class="client-cell">
                    <div class="client-avatar">{{ row.initials }}</div>
                    <div class="client-name">{{ row.estate }}</div>
                </div>
            </template>
            <template #cell-method="{ row }">
                <div class="method-cell">
                    <div class="method-icon">
                        <BoardIcon name="billing" :stroke="1.6" />
                    </div>
                    {{ row.method }}
                </div>
            </template>
            <template #cell-status="{ row }">
                <div class="status-badge" :class="row.status">{{ row.status_label }}</div>
            </template>
            <template #cell-action="{ row }">
                <button type="button" class="text-link-sm" disabled :title="`${row.action} this client's payment details.`">
                    {{ row.action }}
                </button>
            </template>
        </DataTable>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the row action as a <div>; as a real
 * <button> it arrives inline-block on a baseline with a border, a background
 * and Arial. .text-link-sm supplies the colour and weight.
 */
button.text-link-sm {
    display: block;
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

/* Inert, it looks it (Law 1), and why is said once above the table (M96 level 2), not only on hover. */
button.text-link-sm[disabled] {
    color: var(--slate-500);
    opacity: 0.55;
    cursor: not-allowed;
}

.pm-why {
    margin: 0 0 12px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--slate-600);
}
</style>

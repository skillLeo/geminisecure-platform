<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import DataTable from './DataTable.vue'
import { keptByTable } from '../lib/tableParams'

/**
 * EVERY EMAIL SENT, AND WHAT BECAME OF IT (M18G §7) — to whom, what kind, when, and the outcome, searchable by address.
 * The first place to look when somebody says "I never got the code". Drawn on Platform settings → Email (the Director)
 * and on Access & audit log → Emails (the Director and the Operations Manager).
 *
 * "Accepted" is the mail server taking it, never "delivered": a bounce comes back later to the sending mailbox, and
 * this relay reports none to the platform.
 *
 * The whole sixty days, a page at a time, on the shared table (M32 F5): the address searched for is the table's `q`,
 * and a search keeps the order and the page size and starts again at page one.
 */
const props = defineProps({
    sent: { type: Array, required: true },
    address: { type: String, default: '' },
    /** The log as the shared table reads it. */
    table: { type: Object, default: null },
    /** The page this list lives on, which a search reloads. */
    path: { type: String, required: true },
})

const COLUMNS = [
    { key: 'at', label: 'When', sortable: true },
    { key: 'recipient', label: 'To', sortable: true },
    { key: 'type', label: 'What', sortable: true },
    { key: 'outcome', label: 'Outcome', sortable: true },
]

const typed = ref(props.address)
const search = () =>
    router.get(props.path, { ...keptByTable(props.table), ...(typed.value.trim() === '' ? {} : { q: typed.value.trim() }) }, { preserveScroll: true, preserveState: true })
const clear = () => {
    typed.value = ''
    router.get(props.path, keptByTable(props.table), { preserveScroll: true, preserveState: true })
}
</script>

<template>
    <div class="re-panel">
        <div class="re-section">Recent emails</div>
        <p class="re-head">
            Every email the platform handed to the mail server in the last sixty days, newest first, on UTC. “Accepted” means
            the mail server took it — not that it reached an inbox. An address with a typo (…@gmail.comm) is accepted and then
            bounces: search for the address the person gave you, and look at what it was actually sent to.
        </p>
        <form class="re-search" role="search" @submit.prevent="search">
            <label for="re-address" class="re-search-label">Search by address</label>
            <input id="re-address" v-model="typed" type="search" inputmode="email" autocomplete="off" placeholder="e.g. andrea@gmail.com" />
            <button type="submit" class="re-btn">Search</button>
            <button v-if="address" type="button" class="re-btn-outline" @click="clear">Show all</button>
        </form>

        <p v-if="(table?.total ?? sent.length) === 0" class="re-note">{{ address ? `Nothing sent to an address containing “${address}” in the last sixty days.` : 'Nothing has been sent yet.' }}</p>

        <DataTable v-else :columns="COLUMNS" :rows="sent" :table="table" noun="emails">
            <template #cell-at="{ row }"><span class="re-when">{{ row.at }}</span></template>
            <template #cell-recipient="{ row }"><span class="re-to">{{ row.recipient }}</span></template>
            <template #cell-type="{ row }">
                {{ row.type }}
                <span class="re-sub">{{ row.subject }}</span>
            </template>
            <template #cell-outcome="{ row }">
                <!-- not_sent (M35 §5): addressed to a person a seeder invented, so never handed to the mail server; "To" names the row. -->
                <span v-if="row.outcome === 'not_sent'" class="re-quiet">Not sent — sample data, not a person</span>
                <span v-else :class="row.outcome === 'accepted' ? 're-ok' : 're-bad'">{{ row.outcome === 'accepted' ? 'Accepted by the mail server' : 'Failed' }}</span>
                <span v-if="row.reason" class="re-sub">{{ row.reason }}</span>
            </template>
        </DataTable>
    </div>
</template>

<style scoped>
.re-panel {
    background: var(--white);
    border-radius: 16px;
    padding: 18px 20px;
    margin-top: 16px;
}
.re-section {
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
}
.re-head,
.re-note {
    margin: 6px 0 10px;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--text-body);
}
.re-search {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 4px 0 12px;
}
.re-search-label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-primary);
}
.re-search input {
    flex: 1 1 260px;
    min-height: 40px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid var(--navy-200);
    font-size: 13px;
    color: var(--text-primary);
}
.re-search input::placeholder {
    color: var(--text-placeholder);
}
.re-btn {
    min-height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    border: 0;
    background: var(--navy-600);
    color: var(--text-on-dark);
    font-weight: 600;
    cursor: pointer;
}
.re-btn-outline {
    min-height: 40px;
    padding: 0 14px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-600);
    background: transparent;
    color: var(--navy-600);
    font-weight: 600;
    cursor: pointer;
}
.re-when {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    color: var(--text-body);
}
.re-to {
    word-break: break-all;
}
.re-sub {
    display: block;
    color: var(--text-body);
}
.re-ok {
    font-weight: 600;
    color: var(--success-700);
}
.re-bad {
    font-weight: 600;
    color: var(--red-700);
}
.re-quiet {
    font-weight: 600;
    color: var(--text-body);
}
</style>

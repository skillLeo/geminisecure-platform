<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useLiveReload } from '../../../composables/useLiveReload'

// A client added, its plan changed or an invoice paid moves this list by itself (M18H §7.2).
useLiveReload({ entities: ['client', 'invoice'] })

/**
 * Client Directory — board screen super-admin-04.
 *
 * DOM and class names are the board's. Where the board draws a control as a
 * <div> — the filter chips, the column headings, the "Open" link — this renders
 * a real <a> that changes the URL, because the query string is where the
 * directory's state actually lives: a filtered, sorted, paged view is something
 * a person can bookmark and send to a colleague.
 *
 * Nothing is filtered, sorted or paged here. All of it happens in SQL, so a
 * viewer scoped to assigned sites cannot page past their scope, and the count
 * in the pager is the real one rather than the size of one page.
 */
/*
 * THE TIER AND MRR COLUMNS ARE DRAWN FROM `columns`, NOT ASSUMED (M10 §5). For a
 * role without Billing & subscriptions the server builds neither heading, sends
 * no tier or MRR on any row and offers no "At risk" chip, so each of those is
 * rendered only where its entry arrived. A heading drawn over cells the server
 * never filled would read as a broken table rather than as a column this reader
 * does not have — and a chip without an href would be a control that does nothing.
 */
const props = defineProps({
    clients: { type: Array, required: true },
    /** Each chip's toggle URL and whether it is currently on. */
    filters: { type: Object, required: true },
    /** The directory as the shared table reads it (M32 F5): its order, its columns and this page's place. */
    table: { type: Object, required: true },
    /** Whether the Tier and MRR columns are this reader's (M10 §5). */
    moneyColumns: { type: Boolean, default: false },
    total: { type: Number, required: true },
    isFiltered: { type: Boolean, required: true },
    search: { type: String, default: '' },
    /*
     * Whether "Onboard new client" leads anywhere for this viewer, and why not when it
     * does not. Asked by the server of the same permissions the form's route checks, so
     * a role that cannot take on a client is shown the reason rather than a 403.
     */
    canCreate: { type: Boolean, required: true },
    createBlockedReason: { type: String, default: null },
})

/**
 * The whole row opens the client, not only the "Open" cell.
 *
 * The link stays because it is the keyboard and screen-reader route in; this
 * only adds the larger mouse target a table row is expected to be.
 */
const open = (client) => router.visit(client.href)

/*
 * THE DIRECTORY, AS THE SHARED TABLE (M32 F5): sorted and paged in SQL, name order by default. The Tier and MRR columns
 * only where they are this reader's — a heading drawn over cells the server never filled would read as a broken table.
 */
const columns = computed(() => [
    { key: 'name', label: 'Client', sortable: true },
    { key: 'units', label: 'Units', sortable: true },
    ...(props.moneyColumns ? [{ key: 'tier', label: 'Tier', sortable: true }] : []),
    { key: 'guards', label: 'Guards', sortable: true },
    ...(props.moneyColumns ? [{ key: 'mrr', label: 'MRR', sortable: true, cellClass: 'num-cell' }] : []),
    { key: 'status', label: 'Status', sortable: true },
    { key: 'open', label: '' },
])

/** The chips and the order go with a search; the page does not. */
const searchParams = computed(() => {
    const status = Object.entries(props.filters).filter(([key, chip]) => key !== 'all' && chip.active).map(([key]) => key)

    return { ...keptByTable(props.table), ...(status.length ? { status } : {}) }
})

const openOnClick = (client) => ({ onClick: () => open(client), style: 'cursor:pointer' })
</script>

<template>
    <Head title="Clients" />

    <GeminiConsole
        title="Clients"
        board="super-admin-01-login-dashboard-and-activity"
        search-route="/clients"
        :search-value="search"
        :search-params="searchParams"
    >
        <!--
          The board's own topbar button. A live link only where the form will open;
          a role that cannot take on a client gets the inert twin and the reason,
          rather than a link that answers 403.
        -->
        <template #actions>
            <Link v-if="canCreate" href="/clients/new" class="btn-primary-sm">
                <BoardIcon name="plus" :stroke="2" />
                <span>Onboard new client</span>
            </Link>
            <button v-else type="button" class="btn-primary-sm" disabled :title="createBlockedReason">
                <BoardIcon name="plus" :stroke="2" />
                <span>Onboard new client</span>
            </button>
        </template>

        <div class="filter-row">
            <Link :href="filters.all.href" class="f-chip" :class="{ active: filters.all.active }">All clients</Link>
            <Link :href="filters.active.href" class="f-chip" :class="{ active: filters.active.active }">Active</Link>
            <!-- prettier-ignore -->
            <Link :href="filters.onboarding.href" class="f-chip" :class="{ active: filters.onboarding.active }">Onboarding</Link>
            <!-- prettier-ignore -->
            <Link v-if="filters['at-risk']" :href="filters['at-risk'].href" class="f-chip" :class="{ active: filters['at-risk'].active }">At risk</Link>
        </div>

        <EmptyState
            v-if="total === 0 && !isFiltered"
            variant="first-use"
            title="No clients yet"
            :body="
                moneyColumns
                    ? 'An estate becomes a client when it is provisioned its own database and put on a plan. It appears here from that moment, with its units, tier and deployed guards.'
                    : 'An estate becomes a client when it is provisioned its own database and put on a plan. It appears here from that moment, with its units and deployed guards.'
            "
        />

        <EmptyState
            v-else-if="total === 0"
            variant="filtered"
            title="No clients match those filters"
            body="Every other client is still here. Clearing the search and the status chips brings the full directory back."
            action-label="Clear filters"
            @action="router.visit('/clients')"
        />

        <!-- The whole row opens the client, not only the "Open" cell; the link stays as the keyboard's way in. -->
        <DataTable v-else :columns="columns" :rows="clients" :table="table" noun="clients" :row-attrs="openOnClick">
            <template #cell-name="{ row: client }">
                <div class="client-cell">
                    <div class="client-avatar">{{ client.initials }}</div>
                    <div>
                        <div class="client-name">{{ client.name }}</div>
                        <div class="client-sub">{{ client.sub }}</div>
                    </div>
                </div>
            </template>
            <template #cell-tier="{ row: client }">
                <div v-if="client.tierKey" class="tier-badge" :class="client.tierKey">{{ client.tierLabel }}</div>
                <template v-else>—</template>
            </template>
            <template #cell-status="{ row: client }">
                <div class="status-badge" :class="client.statusBadge">{{ client.statusLabel }}</div>
            </template>
            <template #cell-open="{ row: client }">
                <Link :href="client.href" class="text-link-sm" @click.stop>Open</Link>
            </template>
        </DataTable>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every line of it removes a browser
 * default rather than adding a style.
 *
 * The board draws the chips, the column headings and the "Open" cell as <div>s.
 * All three are real anchors here, and an anchor arrives underlined — and, where
 * the board sets no colour of its own, in the browser's link blue. Those two
 * defaults would change the pixels, so they are taken back off. Nothing sets
 * colour on the chips or on .text-link-sm: the board already does, and a rule
 * here would out-specify it.
 */
.filter-row a {
    text-decoration: none;
}

.data-table tbody a {
    text-decoration: none;
}

.data-table thead th a {
    text-decoration: none;
    color: inherit;
}

/* The topbar action. The board draws it as a <div>; as a link it arrives
 * underlined, and .btn-primary-sm sets its own colour. */
a.btn-primary-sm {
    text-decoration: none;
}

/* Its inert twin, for a role that cannot take on a client: a button brings the
 * browser's border and font, and a not-allowed cursor says it will not act.
 * Colour and background stay the board's .btn-primary-sm (see Guards/Index). */
button.btn-primary-sm {
    border: 0;
    font-family: inherit;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}
</style>

<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { keptByTable } from '../../../lib/tableParams'
import { useLiveReload } from '../../../composables/useLiveReload.js'
import Subnav from '../Operations/Subnav.vue'

/**
 * Guard directory — board screen super-admin-18.
 *
 * DOM and class names are the board's. The board draws every control on this
 * screen as a <div>: the sub-navigation, the four KPI cards, the client chips,
 * the column headings and the row action. Each is a real control here, and
 * because a filter, a sort and a row that opens a profile all change the URL,
 * each is a link rather than a button — the whole state of this table is in
 * the query string, and nothing is filtered in the browser.
 *
 * The four screens behind Roster, Standing orders, Gate activity and Incidents
 * are not built. Those items are disabled and say so on hover rather than
 * looking live and swallowing the click.
 */
const props = defineProps({
    guards: { type: Array, required: true },
    /** The directory as the shared table reads it (M32 F5): its order, its columns and this page's place. */
    table: { type: Object, required: true },
    summary: { type: Object, required: true },
    clients: { type: Array, required: true },
    filters: { type: Object, required: true },
    scoped: { type: Boolean, default: false },
    /** Whether the viewer may open board 22, the add-guard form. */
    canCreate: { type: Boolean, default: false },
    /**
     * Whether each officer's phone can ring like a call, by guard id (the guard ring
     * §9.2) — the server's reading of the phone's own last report, in a word.
     */
    callStyle: { type: Object, default: () => ({}) },
})

/**
 * Every link on this screen is this URL with one thing changed.
 *
 * Built from the server's own normalised filters rather than from
 * window.location, so a value the server rejected — a made-up sort key, a
 * status that does not exist — cannot survive by riding along in the next
 * link the page draws.
 */
const href = (changes) => {
    const merged = { ...props.filters, ...keptByTable(props.table), page: props.table.page, ...changes }

    // Page 1 is noise rather than state: it says nothing the bare URL does not already say.
    if (Number(merged.page) <= 1) {
        merged.page = null
    }

    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value))
        }
    }

    const string = query.toString()

    return string ? `/guards?${string}` : '/guards'
}

/*
 * The module's one sub-navigation (Operations/Subnav.vue). This screen kept a copy of its own, and the copy had no
 * "On duty" — so the duty board could not be reached from the module's front page (M12 §4.10). Supervisors
 * (Q-M28, D-M129: a register nothing links to is not visible) is in the shared list now.
 */

const filtered = computed(
    () => Boolean(props.filters.q || props.filters.client || props.filters.status || props.filters.employment || props.filters.licence),
)

/**
 * The KPI cards are the status filter.
 *
 * The board draws no chip for status and no selected state for a card, so this
 * is a drill-down rather than a toggle: a card narrows the list to what it
 * counts, and Total guards puts it back. The counts themselves are never
 * narrowed — a set that shrank as you filtered would leave nothing to return
 * to — so the card that is currently applied is marked with aria-current and
 * says so on hover, which is as much state as the board's classes can carry.
 */
const kpis = computed(() => [
    {
        key: 'total',
        icon: 'guards',
        stroke: 1.7,
        value: props.summary.total,
        label: 'Total guards',
        href: href({ status: null, licence: null, page: null }),
        current: !props.filters.status && !props.filters.licence,
        title: 'Show every guard on the roster',
    },
    {
        key: 'active',
        icon: 'check',
        stroke: 3,
        value: props.summary.active,
        label: 'Active on post',
        href: href({ status: 'active', licence: null, page: null }),
        current: props.filters.status === 'active',
        title: 'Show only guards active on post',
    },
    {
        key: 'leave',
        icon: 'clock',
        stroke: 1.7,
        value: props.summary.on_leave,
        label: 'On leave',
        href: href({ status: 'on_leave', licence: null, page: null }),
        current: props.filters.status === 'on_leave',
        title: 'Show only guards on leave',
    },
    {
        key: 'expired',
        icon: 'alert',
        stroke: 1.7,
        warn: true,
        value: props.summary.licence_expired,
        label: 'Licence expired',
        href: href({ licence: 'expired', status: null, page: null }),
        current: props.filters.licence === 'expired',
        title: 'Show only guards whose PSRA licence has lapsed',
    },
])

/*
 * THE DIRECTORY, AS THE SHARED TABLE (M32 F5): the roster's own order — the order guards joined — unless a heading asks
 * for another, sorted and paged in SQL.
 */
const COLUMNS = [
    { key: 'name', label: 'Guard', sortable: true },
    { key: 'psra', label: 'PSRA number', sortable: true },
    { key: 'client', label: 'Client', sortable: true },
    { key: 'post', label: 'Post', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    // Authored (the guard ring §9.2): not a sort key, the phone's own report.
    { key: 'call', label: 'Call-style alerts' },
    { key: 'action', label: '' },
]

/** The filters and the order go with a search; the page does not. */
const searchParams = computed(() => {
    const { q, ...rest } = props.filters
    void q

    return { ...Object.fromEntries(Object.entries(rest).filter(([, value]) => value)), ...keptByTable(props.table) }
})

const rowOpens = (guard) => ({ onClick: (event) => open(event, guard), style: 'cursor:pointer' })

/** Clicking a row's employment type filters to it; clicking it again clears it. */
const employmentHref = (guard) =>
    href({
        employment: props.filters.employment === guard.employment_key ? null : guard.employment_key,
        page: null,
    })

/**
 * The whole row opens the profile, not only the View link.
 *
 * The links inside the row have their own destinations, so a click that landed
 * on one is left alone rather than being hijacked by the row underneath it.
 */
const open = (event, guard) => {
    if (event.target.closest('a')) {
        return
    }

    router.get(`/guards/${guard.id}`)
}

const clearFilters = () => router.get('/guards')

// Moves by itself (M11 §2, D-175): a change to what this page draws, made anywhere, re-reads it.
useLiveReload({ entities: ['shift','request','incident'] })
</script>

<template>
    <Head title="Guard workforce" />

    <GeminiConsole
        title="Guard workforce"
        search-route="/guards"
        :search-value="filters.q ?? ''"
        search-placeholder="Search guards…"
        :search-params="searchParams"
    >
        <!--
          The board's own topbar button, and a real link since board 22 was
          built: `guards/new` is the form, behind Guard workforce create on both
          halves. A role that may only read the roster gets the inert twin and
          the reason, rather than a link that answers 403.
        -->
        <template #actions>
            <Link v-if="canCreate" href="/guards/new" class="btn-primary-sm">
                <BoardIcon name="plus" :stroke="2" />
                <span>Add guard</span>
            </Link>
            <button
                v-else
                type="button"
                class="btn-primary-sm"
                disabled
                title="Adding a guard creates an employee, so it needs Guard workforce create access. You are able to read this roster."
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Add guard</span>
            </button>
        </template>

        <Subnav active="directory" />

        <div class="kpi-row">
            <Link
                v-for="kpi in kpis"
                :key="kpi.key"
                :href="kpi.href"
                :title="kpi.title"
                :aria-current="kpi.current ? 'true' : undefined"
                class="kpi-card"
                :class="{ warn: kpi.warn }"
            >
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </Link>
        </div>

        <div class="filter-row">
            <Link
                :href="href({ client: null, page: null })"
                class="f-chip"
                :class="{ active: !filters.client }"
                :title="scoped ? 'Your role sees guards at your assigned sites only' : 'Show guards at every client'"
            >
                All clients
            </Link>
            <Link
                v-for="client in clients"
                :key="client.id"
                :href="href({ client: client.id, page: null })"
                class="f-chip"
                :class="{ active: filters.client === client.id }"
            >
                {{ client.name }}
            </Link>
        </div>

        <EmptyState
            v-if="guards.length === 0 && !filtered"
            variant="first-use"
            title="No guards on the roster yet"
            body="Guards are Gemini Security employees, added centrally and then posted to a client. Once added they appear here with their PSRA licence and current post."
        />

        <EmptyState
            v-else-if="guards.length === 0"
            variant="filtered"
            title="No guards match these filters"
            body="Every other guard is still on the roster. Clearing the search, the client and the status filter brings them back."
            action-label="Clear filters"
            @action="clearFilters"
        />

        <!-- The whole row opens the profile; a link inside it keeps its own destination (`open`). -->
        <DataTable v-else :columns="COLUMNS" :rows="guards" :table="table" noun="guards" :row-attrs="rowOpens">
            <template #cell-name="{ row: guard }">
                <div class="res-cell">
                    <div class="res-avatar">{{ guard.initials }}</div>
                    <div>
                        <div class="res-name">{{ guard.name }}</div>
                        <Link
                            :href="employmentHref(guard)"
                            class="res-sub"
                            :title="`Show only ${guard.employment_type.toLowerCase()} guards`"
                        >
                            {{ guard.employment_type }}
                        </Link>
                    </div>
                </div>
            </template>
            <template #cell-psra="{ row: guard }">{{ guard.psra_number }}</template>
            <template #cell-client="{ row: guard }">{{ guard.estate }}</template>
            <template #cell-status="{ row: guard }">
                <div class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</div>
            </template>
            <template #cell-call="{ row: guard }">{{ callStyle[guard.id]?.short ?? 'No phone' }}</template>
            <template #cell-action="{ row: guard }">
                <Link :href="`/guards/${guard.id}`" class="text-link-sm">{{ guard.action }}</Link>
            </template>
        </DataTable>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and it authors nothing.
 *
 * The board draws its controls as <div>s. Real <a>s and <button>s arrive with
 * styling of the browser's own — a link colour and an underline, an inline box
 * where the board had a block, a button's border, face and font — and these
 * rules take exactly those back off again. Every visible property still comes
 * from the board's own .subnav-item, .kpi-card, .f-chip and .res-sub rules.
 *
 * Specificity is why these are written the way they are. A scoped element
 * selector carries an attribute of its own and so outranks a single class:
 * `a { color: inherit }` would quietly beat .f-chip and .text-link-sm, and
 * `button { background: none }` would beat .btn-primary-sm's navy. So colour
 * and background are removed only where the board declares neither and the
 * browser's own would otherwise show — everywhere else the board's rule
 * already wins against the browser and is left alone.
 */
a {
    text-decoration: none;
}

thead a {
    color: inherit;
    display: block;
}

.res-sub {
    display: block;
}

button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}

button.btn-primary-sm {
    border: 0;
}
</style>

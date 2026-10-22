<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { ESTATE_ACTIVITY, useLiveReload } from '../../../composables/useLiveReload'

/**
 * The full activity log — the dashboard panel's "See all" (12 §2, Wave 2).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. The dashboard
 * draws a five-row panel with a "See all" beside it; this is what that link
 * leads to, built in the dashboard's own board CSS so the two read as one
 * console rather than as a page that came from somewhere else.
 *
 * EVERY ROW IS READ FROM THE TABLE THAT OWNS IT. A payment, an incident, a
 * verification, a closed ticket, a booking and a dunning notice — merged by
 * their own timestamps and never copied into a log table of their own. A
 * category with nothing to show is simply absent; there is no invented row.
 *
 * PAGED, NOT INFINITE. The link on each end is a real GET with a page number,
 * so a reader can bookmark where they were and the back button does what it
 * says. The server reads a little deeper than one page of each table before
 * merging, which is what keeps a log fast on the estate running longest.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    page: { type: Number, required: true },
    hasMore: { type: Boolean, required: true },
    dashboardHref: { type: String, required: true },
    path: { type: String, required: true },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// Everything the estate does lands here, so every kind of change re-reads it (M18H §7.2).
useLiveReload({ entities: ESTATE_ACTIVITY })

/*
 * Five of the six. `empty-filtered` cannot arise from the data — this log has
 * no filter that could have excluded anything — and page 2 of an estate with
 * one page is not an empty filter either, it is a page past the end, which the
 * pager below simply does not offer.
 */
const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

const pageHref = (n) => `${props.path}?page=${n}`

const range = computed(() => {
    const first = (props.page - 1) * 25 + 1

    return props.rows.length === 0 ? '' : `${first}–${first + props.rows.length - 1}`
})
</script>

<template>
    <Head title="Activity" />

    <EstateConsole
        title="Activity"
        :estate-name="estate.name"
        active="dashboard"
        :back="{ href: dashboardHref, label: 'Back to the dashboard' }"
    >

        <SkeletonRows v-if="state.isLoading.value" :rows="10" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This estate's activity is not part of your access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The activity log could not be loaded"
            body="Nothing has changed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          TRUE FOR EVERY READER, which "nothing has happened in this estate" was
          not. Each row is shown only to a role that may read the record it
          summarises — a payment needs Payments, an incident needs Security
          (B049) — so an empty log can mean the estate has done nothing, or that
          everything it has done is in a module this role does not hold. The
          sentence says what is true either way. A page past the end is a third
          case, and says so.
        -->
        <EmptyState
            v-else-if="(state.isEmpty.value || state.isEmptyFiltered.value) && page > 1"
            variant="first-use"
            title="The log does not go back this far"
            body="Every row is on an earlier page."
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="No activity yet"
            body="Payments, incidents, closed tickets, bookings and reminders appear here as they happen."
        />

        <template v-else>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>What happened</th>
                        <th>Detail</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index">
                        <td>
                            <span :class="{ 'act-amber': row.amber }">{{ row.title }}</span>
                        </td>
                        <td>{{ row.detail }}</td>
                        <td :title="row.at">{{ row.when }}</td>
                    </tr>
                </tbody>
            </table>

            <!--
              Each end only where there is somewhere to go (M96 level 3; the final pass, 12 Oct): a greyed "Newer" on the
              first page had no reason beside it, and none is needed for a page that does not exist.
            -->
            <div class="act-pager">
                <Link v-if="page > 1" :href="pageHref(page - 1)" class="act-page" title="Show newer activity.">
                    Newer
                </Link>

                <span class="act-range">{{ range }}</span>

                <Link v-if="hasMore" :href="pageHref(page + 1)" class="act-page" title="Show older activity.">Older</Link>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen — see the head of the script. The table
 * is the boards' own `.data-table`; only the pager and the amber title need
 * rules, and both are kept to tokens the boards define.
 */
.act-amber {
    color: var(--amber-700);
    font-weight: 600;
}

.act-pager {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 16px;
}

.act-page {
    font-size: 12px;
    font-weight: 700;
    color: var(--amber-600);
    text-decoration: none;
}


.act-range {
    font-size: 11px;
    color: var(--slate-500);
}
</style>

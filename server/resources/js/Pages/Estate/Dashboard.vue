<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../Layouts/EstateConsole.vue'
import EmptyState from '../../Components/EmptyState.vue'
import SkeletonRows from '../../Components/SkeletonRows.vue'
import { useScreenState } from '../../composables/useScreenState'
import { useWireframe } from '../../composables/useWireframe'
import { ESTATE_ACTIVITY, useLiveReload } from '../../composables/useLiveReload'

/**
 * Dashboard — board screen community-admin-02.
 *
 * EVERY FIGURE HERE ALREADY HAD A HOME. The five KPI tiles, the phase bars and
 * the activity feed are all composed server-side from `Residents`, `Dues`,
 * `Maintenance` and the `Meeting` register — see `DashboardController`. This
 * page formats what it is given; it does not add anything up.
 *
 * TWO CONTENT RESIDUALS, drawn as the data says rather than as the board says:
 * the board's "441 Occupied units, 98%" cannot be reproduced — this estate is
 * seeded 450 units and 433 occupied households (D-056's class of residual) —
 * and the board's "↑ 4%" trend chip on Outstanding dues needs a prior-period
 * snapshot nothing in this schema stores, so it is simply absent. Both are
 * recorded in DECISIONS.md rather than invented here.
 *
 * MONEY ARRIVES AS MINOR UNITS AND IS FORMATTED HERE, exactly as Arrears.vue's
 * ageing cards format theirs — $1.84M, $412k — because it is the same kind of
 * figure read at the same kind of glance, not a resident's own invoice.
 *
 * AND FOR SOME READERS IT DOES NOT ARRIVE AT ALL (M10 §5). The estate's total
 * arrears and its phase bars are the committee's picture; for a role that may
 * not read them the server sends no `dues` tile, an empty `arrears.phases` and
 * `arrears.positionWithheld`, and the chart is not drawn at all.
 *
 * EVERYTHING HERE IS THE READER'S OWN, OR ABSENT (M98, Hassam, 12 October). The
 * server sends a tile only to a role that holds its module (Total units goes to
 * every role: a count is not the module's data), and a quick action or "View
 * ledger" only to a role its page would let in; this page draws what it is sent
 * and never a greyed twin with a reason, and no tile is a link, so nobody is sent
 * to a page that refuses them. With no chart, the panel is the reader's quick actions;
 * with no chart and no action, Recent activity takes the whole width.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    kpis: { type: Array, required: true },
    arrears: { type: Object, required: true },
    activity: { type: Object, required: true },
    quickActions: { type: Object, required: true },
    notifications: { type: Object, required: true },

    /*
     * WHEN THE ESTATE-WIDE FIGURES WERE COMPUTED (M11 A2). The tiles and the bars
     * are kept between page loads rather than summed from the whole ledger every
     * time, and recomputed the moment a charge, a payment, a lot or a ticket moves
     * them; the screen says when each was computed, in the server's words. The
     * position line is null for a reader the figures are withheld from.
     */
    computed: { type: Object, default: null },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

// The estate at a glance: any change anywhere in it, or a tile's count moving, re-reads the dashboard (M18H §7.2).
useLiveReload({ entities: [...ESTATE_ACTIVITY, 'tile'], wait: 1000 })

/*
 * The one collection on this screen that can genuinely be empty — a brand
 * new estate with no payment, incident, verification, closed ticket or
 * booking to report yet, or a role whose modules withhold every row the feed
 * has. The KPI tiles and the arrears bars stay meaningful either way, so only
 * the activity panel's own emptiness drives `rows`; the other five states are
 * still forceable with `?_state=` for review, as every screen's have to be.
 *
 * AND ONLY THE ACTIVITY PANEL ANSWERS TO IT (B105). The empty state used to sit
 * in the same v-if chain as loading, denied and failed, so an empty feed took
 * the tiles, the arrears panel and every quick action with it — "Add resident"
 * and "New charge" gone on the day a new estate needs them first, under a
 * sentence saying "the figures above are still real" with no figures above it.
 * Loading, denied and failed still replace the whole screen; empty replaces the
 * activity list and nothing else.
 */
const state = useScreenState({
    rows: () => props.activity.rows.length,
})

const retry = () => router.reload()

/** The board's own abbreviation: $1.84M, $412k, $72 — two decimals on
 * millions, none on thousands, from integer minor units. */
const abbreviated = (minor) => {
    const value = Math.round(minor / 100)

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000).toLocaleString('en-US')}k`
    }

    // Under a thousand nothing is abbreviated, so it carries its cents (Hassam, 12 Oct): $72.50, never $73.
    return `$${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

/** Whether the reader holds any of the three quick actions. */
const hasActions = computed(() => Object.values(props.quickActions).some((action) => action.href))

/** The left panel: the arrears chart, or what the reader can do, or nothing when it would be empty. */
const leftPanel = computed(() => !props.arrears.positionWithheld || hasActions.value || Boolean(props.arrears.ledgerHref))

/** The KPI row, with the one figure that needs formatting formatted. */
const kpis = computed(() =>
    props.kpis.map((kpi) => ({
        ...kpi,
        display: kpi.key === 'dues' ? abbreviated(kpi.value_minor) : kpi.value,
    }))
)

const ICONS = {
    payment: '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
    incident:
        '<path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><path d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z" stroke="currentColor" stroke-width="1.7"/>',
    residents:
        '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/>',
    maintenance:
        '<path d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
    facility:
        '<rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
}
</script>

<template>
    <Head :title="estate.name" />

    <EstateConsole
        title="Dashboard"
        :estate-name="estate.name"
        active="dashboard"
        search-placeholder="Search residents, units, tickets…"
        profile
    >
        <template #actions>
            <!--
              The board draws a megaphone with a badge. The badge counts what
              THIS viewer has not seen, of the items their own module
              permissions let them see — a notification is a summary of a
              record, and a role that may not read the record may not read the
              summary either.
            -->
            <Link
                :href="notifications.href"
                class="top-icon-btn"
                :title="`${notifications.count} unread`"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linejoin="round"
                    />
                    <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <div v-if="notifications.count > 0" class="top-badge">{{ notifications.count }}</div>
            </Link>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This dashboard is not part of your role's access"
            body="Ask your estate administrator if you need it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The dashboard could not be loaded"
            body="Nothing has changed, and trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="kpi-row">
                <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                    <div class="k-top">
                        <div class="kpi-icon">
                            <svg v-if="kpi.key === 'units'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M3 21h18M5 21V7l7-4 7 4v14"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else-if="kpi.key === 'occupied'" viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7" />
                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                            </svg>
                            <svg v-else-if="kpi.key === 'dues'" viewBox="0 0 24 24" fill="none">
                                <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
                                <path d="M2 10h20" stroke="currentColor" stroke-width="1.7" />
                            </svg>
                            <svg v-else-if="kpi.key === 'maintenance'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none">
                                <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                                <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                        </div>

                        <!-- Only "Occupied units" carries a trend, and it is
                             a real derived percentage — the board's own "↑
                             4%" on Outstanding dues would need a prior-period
                             snapshot nothing stores, so that one is absent
                             rather than invented. -->
                        <div v-if="kpi.trend !== undefined && kpi.trend !== null" class="kpi-trend up">
                            {{ kpi.trend }}%
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.display }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <p v-if="computed?.structure" class="computed-at">{{ computed.structure }}</p>

            <div class="main-grid" :class="{ 'main-grid--solo': !leftPanel }">
                <div v-if="leftPanel" class="panel" :class="{ 'panel--actions': arrears.positionWithheld }">
                    <div class="panel-head">
                        <h3>{{ arrears.positionWithheld ? 'Quick actions' : 'Arrears by phase' }}</h3>
                        <Link
                            v-if="arrears.ledgerHref"
                            :href="arrears.ledgerHref"
                            class="panel-link"
                            title="Open the arrears register."
                        >
                            View ledger
                        </Link>
                    </div>

                    <!--
                      M10 §5. Not an empty chart and not a row of $0 bars: for a
                      reader the figures are withheld from, they were never sent
                      and nothing is drawn in their place. "View ledger" above
                      stays for a reader who may open the register (the Admin
                      Assistant finds a lot there), and is absent for the rest.
                    -->
                    <div v-if="!arrears.positionWithheld" class="bar-chart">
                        <div v-for="bar in arrears.phases" :key="bar.key" class="bar-item">
                            <div
                                class="bar"
                                :class="{ over: bar.over }"
                                :style="{ height: bar.height_pct + '%', position: 'relative' }"
                            >
                                <span class="bar-val">{{ abbreviated(bar.value_minor) }}</span>
                            </div>
                            <div class="bar-lbl">{{ bar.label }}</div>
                        </div>
                    </div>

                    <p v-if="!arrears.positionWithheld && computed?.position" class="computed-at">{{ computed.position }}</p>

                    <div v-if="hasActions" class="qa-row" :class="{ 'qa-row--alone': arrears.positionWithheld }">
                        <!--
                          Each action is a link for whoever holds the gate its
                          page's save carries, and absent for everybody else.
                        -->
                        <Link v-if="quickActions.notice.href" :href="quickActions.notice.href" class="qa-btn">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linejoin="round"
                                />
                                <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            <span>Post a notice</span>
                        </Link>

                        <Link v-if="quickActions.addResident.href" :href="quickActions.addResident.href" class="qa-btn">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7" />
                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                <path d="M19 8v6M22 11h-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            <span>Add resident</span>
                        </Link>

                        <Link v-if="quickActions.newCharge.href" :href="quickActions.newCharge.href" class="qa-btn">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            </svg>
                            <span>New charge</span>
                        </Link>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <h3>Recent activity</h3>
                        <Link
                            :href="activity.seeAllHref"
                            class="panel-link"
                            title="Open the full activity log."
                        >
                            See all
                        </Link>
                    </div>

                    <!--
                      Zero rows is this panel's own state, and the panel's alone
                      (B105). The sentence is true for every reader: a new estate
                      has nothing on record, and a role may be withheld every row
                      there is — a payment needs Payments and an incident needs
                      Security — and the tiles beside this panel are read from
                      their own records either way.
                    -->
                    <EmptyState
                        v-if="state.isEmpty.value"
                        variant="first-use"
                        title="No activity yet"
                        body="Payments, incidents and bookings appear here as they happen."
                    />

                    <!--
                      No fixed row count: five real events or fewer, per the
                      controller's own rule against inventing a row to fill
                      the gap.
                    -->
                    <template v-else>
                        <div v-for="(row, index) in activity.rows" :key="index" class="activity-row">
                            <div class="a-icon" :class="{ amber: row.amber }">
                                <svg viewBox="0 0 24 24" fill="none" v-html="ICONS[row.icon]"></svg>
                            </div>
                            <div class="a-txt">
                                <div class="at1">{{ row.title }}</div>
                                <div class="at2">{{ row.subtitle }}</div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the topbar bell, the two panel-head
 * links and every quick action as <div>/<a>; here they are real links, and an
 * anchor arrives underlined. Nothing below introduces a colour, a size or a
 * spacing the board does not already declare.
 */
a.top-icon-btn {
    border: 0;
    padding: 0;
    text-decoration: none;
}

/*
 * Two classes on each, because the board's own rules are scoped to the body's board class and outrank one (seen on
 * the dev server, 12 October: Recent activity stayed in the narrow column with the right one empty).
 */

/* With no chart above them, the reader's quick actions sit straight under the panel's heading, as tall as they are. */
.qa-row.qa-row--alone {
    margin-top: 0;
}

.panel.panel--actions {
    align-self: start;
}

/* No chart and no quick action for this reader: Recent activity takes the whole width. */
.main-grid.main-grid--solo {
    grid-template-columns: 1fr;
}

a.panel-link {
    font-size: 12px;
    color: var(--amber-600);
    font-weight: 700;
    text-decoration: none;
}

/* When the estate-wide figures were computed (M11 A2): a caption, not a control. */
.computed-at {
    font-size: 10.5px;
    color: var(--slate-500);
    margin: 6px 2px 0;
}
</style>

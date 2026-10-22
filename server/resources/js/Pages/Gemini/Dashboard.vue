<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../Components/BoardIcon.vue'
import EmptyState from '../../Components/EmptyState.vue'
import { GEMINI_ACTIVITY, useLiveReload } from '../../composables/useLiveReload'

// The platform at a glance: any change it counts, or a tile's count moving, re-reads the dashboard (M18H §7.2).
useLiveReload({ entities: [...GEMINI_ACTIVITY, 'tile'], wait: 1000 })

/**
 * Platform Dashboard — board screen super-admin-02.
 *
 * DOM and class names are the board's. The inline grid style on the two-panel
 * row is the board's too, kept inline rather than promoted to a class,
 * because the board's stylesheet defines no class for it and inventing one
 * would be authoring CSS the design does not have.
 */
defineProps({
    kpis: { type: Array, required: true },
    /**
     * ABSENT for a viewer who may not read platform revenue (M10 §5), not sent empty: an empty
     * list is what a platform with no active subscription sends, and drawing "No active
     * subscriptions" to a reader who is only being kept from the figures would be a false
     * statement about the business. `withheld_reason` is the switch.
     */
    tiers: { type: Array, default: () => [] },
    /** Why the revenue panel is withheld, in a sentence naming no figure — or null. */
    withheld_reason: { type: String, default: null },
    /**
     * True when every figure here is the viewer's assigned sites' rather than the platform's
     * (D-015). The MRR card's own label says so; this lets the tier panel say it too.
     */
    sites_only: { type: Boolean, default: false },
    activity: { type: Array, required: true },
    /** How many notification-centre items this viewer has not seen. */
    unread: { type: Number, required: true },
    /**
     * When the KPI cards' figures were taken (M11 A2). They are platform-wide totals kept for a
     * few minutes rather than summed on every load, so the page says how old they are.
     */
    figures_computed_at: { type: Object, required: true },
})

const page = usePage()
const user = computed(() => page.props.auth.user)

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase()
)

const menuOpen = ref(false)
const signOut = () => router.post('/logout')

const closeOnOutside = (event) => {
    if (!event.target.closest('.top-profile')) {
        menuOpen.value = false
    }
}

onMounted(() => document.addEventListener('click', closeOnOutside))
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutside))
</script>

<template>
    <Head title="Dashboard" />

    <GeminiConsole
        title="Dashboard"
        board="super-admin-01-login-dashboard-and-activity"
        search-disabled-reason="There is no search across modules. Search clients from the client directory and guards from the guard directory, each on its own screen."
    >
        <!--
          This board is the ONLY one of the forty-five that draws a
          notification bell and a profile chip in the top bar, so they are
          passed from here rather than built into the layout.
        -->
        <template #actions>
            <!--
              The notification centre (12 §2, item 41). The board draws the
              bell with no badge, so the count is on the label and the title
              rather than painted on — a figure the design never drew.
            -->
            <Link
                href="/notifications"
                class="top-icon-btn"
                :title="unread > 0 ? `${unread} notification${unread === 1 ? '' : 's'} not yet seen` : 'Notifications — nothing unseen'"
                :aria-label="unread > 0 ? `Notifications, ${unread} unseen` : 'Notifications'"
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
            </Link>

            <!--
              The chevron promises a menu, so it opens one. Sign-out also
              lives on the sidebar footer, which every board draws — this is
              the second way to reach it, not the only one.
            -->
            <button type="button" class="top-profile" :aria-expanded="menuOpen" aria-haspopup="menu" @click="menuOpen = !menuOpen">
                <div class="tp-avatar">{{ initials }}</div>
                <div>
                    <div class="tp-name">{{ user?.name }}</div>
                    <div class="tp-role">{{ user?.role_label }}</div>
                </div>
                <svg viewBox="0 0 24 24" fill="none">
                    <polyline
                        points="6 9 12 15 18 9"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

                <div v-if="menuOpen" class="tp-menu" role="menu">
                    <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                </div>
            </button>
        </template>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="1.7" />
                    </div>
                    <div v-if="kpi.trend" class="kpi-trend up">{{ kpi.trend }}</div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <p class="kpi-computed-at">
            <time :datetime="figures_computed_at.iso">{{ figures_computed_at.label }}</time>
        </p>

        <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px">
            <!--
              M10 §5. For a role without Billing & subscriptions the panel keeps
              its place in the grid and says, in the board's own denied state,
              that the figures are not theirs. The heading names the module
              rather than the measure, and there is no "View billing" link: it
              would open a 403.
            -->
            <div v-if="withheld_reason" class="panel">
                <div class="panel-head">
                    <h3>Billing &amp; subscriptions</h3>
                </div>

                <EmptyState variant="denied" title="Not part of your role's access" :body="withheld_reason" />
            </div>

            <!--
              For a reader confined to their assigned sites the bars are their
              sites' tiers, and the heading and the empty state say so: under
              the board's own heading they would read as the platform's, beside
              a "View billing" that opens the platform's figures.
            -->
            <div v-else class="panel">
                <div class="panel-head">
                    <h3>{{ sites_only ? 'MRR by subscription tier at your sites' : 'MRR by subscription tier' }}</h3>
                    <Link href="/billing">View billing</Link>
                </div>

                <EmptyState
                    v-if="tiers.length === 0"
                    variant="first-use"
                    :title="sites_only ? 'No active subscriptions at your sites' : 'No active subscriptions'"
                    body="Tier revenue appears once a client is on a plan."
                />

                <div v-for="tier in tiers" :key="tier.key" class="tier-bar-row">
                    <div class="tier-dot" :class="tier.key"></div>
                    <div class="tier-lbl">{{ tier.label }}</div>
                    <div class="tier-track">
                        <i :class="tier.key" :style="{ width: tier.percent + '%' }"></i>
                    </div>
                    <div class="tier-val">{{ tier.amount }}</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>Recent activity</h3>
                    <!-- The full feed, not the audit log. Those are different
                         records with different rules. -->
                    <Link href="/dashboard/activity">See all</Link>
                </div>

                <EmptyState
                    v-if="activity.length === 0"
                    variant="first-use"
                    title="Nothing yet"
                    :body="
                        withheld_reason
                            ? 'Onboarding and deployment events appear here as they happen.'
                            : 'Onboarding, billing and deployment events appear here as they happen.'
                    "
                />

                <div v-for="(event, i) in activity" :key="i" class="activity-row">
                    <div class="a-icon">
                        <BoardIcon :name="event.icon" :stroke="1.6" />
                    </div>
                    <div class="a-txt">
                        <div class="at1">{{ event.title }}</div>
                        <div class="at2">{{ event.meta }}</div>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only, for the two board <div>s that had to become real
 * controls. The board's own .top-icon-btn and .top-profile rules supply
 * everything visual; these take off what the browser adds to a <button>.
 */
.top-icon-btn,
.top-profile {
    border: 0;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

a.top-icon-btn {
    text-decoration: none;
}

.top-profile {
    position: relative;
}

.tp-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 20;
    min-width: 150px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(11, 31, 51, 0.16);
    padding: 6px;
}

.tp-menu button {
    display: block;
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: var(--navy-900);
    text-align: left;
    padding: 8px 10px;
    border-radius: 7px;
    cursor: pointer;
}

.tp-menu button:hover {
    background: var(--navy-100);
}

/*
 * When the cards' figures were taken (M11 A2). Not on the board: the board's figures were
 * summed on every load, and these are kept for a few minutes, so the page has to say how old
 * they are. Set small and quiet under the row it describes.
 */
.kpi-computed-at {
    margin: -8px 0 16px;
    font-size: 12px;
    color: var(--slate-500);
    text-align: right;
}
</style>

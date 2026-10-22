<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import DataTable from '../../../Components/DataTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * Billing & subscription — board screen community-admin-40.
 *
 * EVERY FIGURE IS READ FROM THE CENTRAL SUBSCRIPTION, NOT COPIED INTO THE
 * ESTATE. A second copy of a plan price is a second number that can disagree
 * with the one Gemini actually bills against — and the estate's screen is the
 * one a treasurer would trust, so the drift would be invisible until an invoice
 * arrived for a different figure. `Settings::billingBoard()` reaches the central
 * connection explicitly, the same way permissions do under D-012.
 *
 * NOTHING ON THIS SCREEN IS EDITABLE, and that is a boundary rather than a gap.
 * An estate changing its own plan from its own console is not a settings screen,
 * it is a discount button. Board 40 draws no control that would, and no route
 * exists.
 *
 * EACH ROW IS THE WHOLE INVOICE (BIL-B-05): the subscription and the guard
 * add-on, the figure the emailed PDF carries. The hero card says what the
 * subscription alone costs a month, and the second panel says the guards are a
 * line on the same invoice.
 *
 * THE FIVE STATES (the Settings pass, Hassam's closing order, 12 Oct): a read that failed is said as one, where it
 * drew the page with nothing on it; the two monthly figures say "a month" in words, where they had a slash.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    subscription: { type: Object, default: null },
    invoices: { type: Array, required: true },
    /** Every invoice as the shared table reads them (M32 F5); null before there is a subscription. */
    table: { type: Object, default: null },
})

/*
 * THE BADGE FOLLOWS THE INVOICE'S STANDING (BIL-B-04): paid is the board's green, overdue the console's overdue
 * treatment (D-185: red stays for urgent and life-safety), anything else a plain pill. It was green whatever it said,
 * "Issued" on a bill past its date included.
 */
const BADGE = { paid: 'active', overdue: 'overdue' }

const INVOICE_COLUMNS = [
    { key: 'number', label: 'Invoice', sortable: true },
    { key: 'period', label: 'Period', sortable: true },
    { key: 'amount', label: 'Amount', sortable: true, cellClass: 'num-cell' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'view', label: '' },
]

/*
 * Board 40 is drawn in the employees-details-and-billing sheet beside boards 37
 * and 38, not in the settings sheet its six siblings use. Checked against the
 * sheet that defines `.hero-card` and `.info-panel` rather than assumed from the
 * module.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

// Gemini issuing or marking an invoice paid lands here by itself (M18H §7.2).
useLiveReload({ entities: ['invoice'] })

/**
 * An estate with no subscription is a real state, not an error.
 *
 * Ocean View is mid-onboarding on every board that draws it: the plan is agreed
 * at the end of onboarding, so a client can legitimately reach this screen
 * before there is anything to show. `empty` is that, and the copy says which.
 */
const state = useScreenState({
    rows: () => (props.subscription === null ? 0 : 1),
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Billing &amp; subscription" />

    <EstateConsole title="Billing &amp; subscription" :estate-name="estate.name" active="settings">
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="What this estate pays for the platform is kept in Settings, and your role does not include Settings."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="Billing could not be read"
            body="The subscription and its invoices did not answer. Nothing has been charged or changed. Trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <div v-else class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div>
                <EmptyState
                    v-if="state.isEmpty.value"
                    variant="first-use"
                    title="No plan has been activated yet"
                    body="A subscription is agreed at the end of onboarding, so there is nothing to bill against until Gemini activates this estate's plan. Nothing has been charged."
                />

                <template v-else>
                    <div class="hero-card">
                        <div class="hero-top">
                            <div>
                                <div class="hero-name">{{ subscription.plan_name }}</div>
                                <div class="hero-sub">
                                    {{ subscription.unit_count }} units &middot; {{ subscription.billing_cadence }}
                                </div>
                            </div>
                        </div>
                        <div class="hero-stats">
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.price_per_unit }}</div>
                                <div class="hs-l">Per unit a month</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.monthly_total }}</div>
                                <div class="hs-l">Subscription a month</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.next_invoice }}</div>
                                <div class="hs-l">Next invoice</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.contract_renewal }}</div>
                                <div class="hs-l">Renewal</div>
                            </div>
                        </div>
                    </div>

                    <div class="bl-panels">
                        <div class="info-panel">
                            <div class="info-panel-head">What’s included on {{ subscription.plan_name.split(' ')[0] }}</div>
                            <div class="cl-txt">{{ subscription.included_blurb }}</div>
                        </div>
                        <div class="info-panel">
                            <div class="info-panel-head">Security services</div>
                            <div class="cl-txt">{{ subscription.security_services_note }}</div>
                        </div>
                    </div>

                    <div style="margin-top: 16px">
                        <DataTable :columns="INVOICE_COLUMNS" :rows="invoices" :table="table" row-key="number" noun="invoices">
                            <template #cell-status="{ row: invoice }">
                                <div class="status-badge" :class="BADGE[invoice.status_tone]">{{ invoice.status_label }}</div>
                            </template>
                            <template #cell-view="{ row: invoice }">
                                <!-- The invoice as issued, every line of it, with any credit notes and its PDF. -->
                                <Link
                                    :href="invoice.view_href"
                                    class="text-link-sm"
                                    title="The invoice as issued, line by line, with any credit notes and its PDF."
                                >
                                    View
                                </Link>
                            </template>
                        </DataTable>
                    </div>
                </template>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its seven nav items and its three row
 * links as <div>s; here they are anchors, which arrive with an underline.
 */
a.settings-nav-item {
    text-decoration: none;
    display: block;
}

a.text-link-sm {
    text-decoration: none;
}

/* The two panels under the plan, side by side on a desk. It was an inline grid a narrow screen could not reach. */
.bl-panels {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}

/*
 * A panel's words. The board reuses .cl-txt here, which it sizes only inside a cross link, so these two sentences came out
 * at the body's 16 px, larger than anything else on the page. The size and colour of the boards' other panel text.
 */
.info-panel .cl-txt {
    font-size: 12.5px;
    line-height: 1.6;
    color: var(--slate-600);
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct). The board keeps the section list beside the page, 200 px of
 * a 390 px screen, and every Settings page ran past the window. Below 760 px the list sits above the page as one row
 * that scrolls sideways, and the page takes the full width. Doubled classes outrank the board's own rules.
 *
 * Here the plan's four figures also go two to a row, and the two panels one above the other.
 */
@media (max-width: 760px) {
    .settings-layout.settings-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
    }

    .settings-nav.settings-nav {
        flex-direction: row;
        overflow-x: auto;
    }

    .settings-nav-item.settings-nav-item {
        flex: 0 0 auto;
        width: auto;
        white-space: nowrap;
    }

    .hero-stats.hero-stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 16px;
    }

    .bl-panels {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>

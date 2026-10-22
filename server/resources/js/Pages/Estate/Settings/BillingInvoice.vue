<script setup>
import { Head, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { useLiveReload } from '../../../composables/useLiveReload'

/**
 * One of this estate's invoices, itemised — board 40's "View" (12 §2, Wave 4).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 40's own sheet so the table and the invoice behind a row read as one.
 *
 * THE INVOICE AS ISSUED, EVERY LINE OF IT, the same total board 40's table
 * shows (BIL-B-05). The subscription lines are marked, so the estate is never
 * shown a second version of a document it already holds.
 *
 * CREDIT NOTES ARE SHOWN, NOT NETTED AWAY. What was invoiced stays what was
 * invoiced; the credits and what remains payable sit beside it.
 *
 * Nothing here writes. The PDF is a download, and like every export it is on
 * the estate's audit record.
 *
 * THE SETTINGS PASS (Hassam's closing order, 12 Oct): the way back is the console's back control in words, "Back to
 * Billing & subscription" (it was a bare arrow); a reconciliation warning is amber, not red; the note under the lines
 * says what is true (it said the billing page showed only part of an invoice, where each of its rows is the whole
 * invoice) without its em dashes; and the page has its states.
 */
/*
 * Both links arrive built from the estate's root — its own hostname in
 * production, /estate/{tenant} in local — so neither is written here.
 */
defineProps({
    estate: { type: Object, required: true },
    invoice: { type: Object, required: true },
    pdfHref: { type: String, required: true },
    billingHref: { type: String, required: true },
})

useWireframe('community-admin-10-payroll-employees-details-and-billing')

// The badge follows the invoice's standing, as the billing page's does (BIL-B-04; D-185 for the overdue treatment).
const BADGE = { paid: 'active', overdue: 'overdue' }

// The invoice paid, credited or voided by Gemini re-reads itself (M18H §7.2).
useLiveReload({ entities: ['invoice'] })

/* One invoice: there is no empty or filtered state, only loading, denied and a read that failed. */
const state = useScreenState({ rows: () => 1 })

const retry = () => router.reload()
</script>

<template>
    <Head :title="`Invoice ${invoice.number}`" />

    <EstateConsole
        :title="`Invoice ${invoice.number}`"
        :estate-name="estate.name"
        active="settings"
        :back="{ href: billingHref, label: 'Back to Billing & subscription' }"
    >
        <template #actions>
            <a :href="pdfHref" class="btn-outline-sm" title="Download this invoice as the PDF Gemini sends.">
                <span>Download PDF</span>
            </a>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="This estate’s invoices are kept in Settings, and your role does not include Settings."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The invoice could not be read"
            body="It did not answer. Nothing has been charged or changed. Trying again is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="hero-card">
                <div class="hero-top">
                    <div>
                        <div class="hero-name">{{ invoice.period }}</div>
                        <div class="hero-sub">{{ invoice.settlement }}</div>
                    </div>
                    <div class="status-badge" :class="BADGE[invoice.status_tone]">{{ invoice.status_label }}</div>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <div class="hs-v">{{ invoice.total }}</div>
                        <div class="hs-l">Invoiced</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hs-v">{{ invoice.subscription_total }}</div>
                        <div class="hs-l">Subscription portion</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hs-v">{{ invoice.credited }}</div>
                        <div class="hs-l">Credited</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hs-v">{{ invoice.payable }}</div>
                        <div class="hs-l">Payable</div>
                    </div>
                </div>
            </div>

            <table class="data-table" style="margin-top: 16px">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Detail</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in invoice.lines" :key="index">
                        <td>
                            {{ line.description }}
                            <span v-if="line.is_subscription" class="bi-tag">Subscription</span>
                        </td>
                        <td>{{ line.detail }}</td>
                        <td class="num-cell">{{ line.amount }}</td>
                    </tr>
                </tbody>
            </table>

            <p class="bi-note">
                The subscription lines are marked. Any other line, such as the guard staffing add-on, is priced under
                Gemini Security’s separate labour services agreement, and is here because it is on the invoice you were
                sent.
            </p>

            <p v-if="invoice.reconciliation" class="bi-note bi-warn">{{ invoice.reconciliation }}</p>

            <template v-if="invoice.has_credits">
                <div class="info-panel-head" style="margin-top: 18px">Credit notes against this invoice</div>

                <table class="data-table" style="margin-top: 8px">
                    <thead>
                        <tr>
                            <th>Note</th>
                            <th>Reason</th>
                            <th>Issued</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="note in invoice.notes" :key="note.reference">
                            <td>{{ note.reference }}</td>
                            <td>{{ note.reason }}</td>
                            <td>{{ note.issued_on }}</td>
                            <td class="num-cell">{{ note.amount }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. The hero, table and panel heading are
 * board 40's own classes; only the subscription tag and the notes need rules,
 * kept to the tokens the boards define.
 */
a.btn-outline-sm {
    text-decoration: none;
}

.bi-tag {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    font-weight: 700;
    border-radius: 20px;
    padding: 2px 8px;
    background: var(--navy-100);
    color: var(--navy-700);
}

.bi-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 12px 0 0;
    max-width: 840px;
}

/* A total that does not reconcile is a warning: amber, never red (Hassam's red rule, 11 Oct). */
.bi-warn {
    color: var(--amber-700);
    font-weight: 600;
}

/*
 * ON A PHONE (checked at 390 px with the new shell, 12 Oct): the invoice's four figures stayed four across and pushed the
 * page to 463 px. Two to a row below 640 px; doubled class to outrank the board's rule.
 */
@media (max-width: 640px) {
    .hero-stats.hero-stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 16px;
    }
}
</style>

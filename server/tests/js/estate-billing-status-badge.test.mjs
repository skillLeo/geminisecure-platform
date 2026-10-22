/**
 * The client portal's invoice badge follows the invoice's standing (BIL-B-04, M91 Block 1).
 *
 *   `Pages/Estate/Settings/Billing.vue` and `BillingInvoice.vue` drew every status in the board's green "active" pill,
 *   "Issued" on a bill past its date included. The badge now takes the server's tone: paid is green, overdue the
 *   console's overdue treatment (D-185), anything else a plain pill.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Billing } = await import('../../resources/js/Pages/Estate/Settings/Billing.vue')
const { default: BillingInvoice } = await import('../../resources/js/Pages/Estate/Settings/BillingInvoice.vue')

const ROW = (overrides) => ({
    number: 'FT-INV-202610', period: 'October 2026', amount: '$770,000.00', status: 'issued',
    status_label: 'Overdue', status_tone: 'overdue', view_href: '/settings/billing/invoices/1', ...overrides,
})

const shell = (url) =>
    resetInertia({ auth: { user: { id: 91001, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, url)

const badgeOf = (view, label) => view.all(byClass('status-badge')).find((el) => textOf(el).trim() === label) ?? null

describe('the client portal invoice badge follows the invoice (BIL-B-04)', () => {
    it('draws an overdue invoice in the overdue treatment, a paid one green and a due one plain, on the billing page', async () => {
        shell('/settings/billing')
        const view = await mount(Billing, {
            estate: { name: 'Phoenix Park' },
            sections: [{ key: 'billing', label: 'Billing & subscription', href: '/settings/billing', active: true, pending: false }],
            subscription: {
                plan_name: 'Premium', unit_count: 2000, billing_cadence: 'Billed monthly in advance', price_per_unit: '$340',
                monthly_total: '$680,000', next_invoice: 'Nov 1', contract_renewal: 'Jan 2027', included_blurb: 'Everything.', security_services_note: 'Guards.',
            },
            invoices: [
                ROW({}),
                ROW({ number: 'FT-INV-202611', period: 'November 2026', status_label: 'Unpaid', status_tone: 'due', view_href: '/settings/billing/invoices/2' }),
                ROW({ number: 'FT-INV-202609', period: 'September 2026', status: 'paid', status_label: 'Paid', status_tone: 'paid', view_href: '/settings/billing/invoices/3' }),
            ],
            table: { prefix: '', sort: null, dir: null, default: { sort: 'period', dir: 'desc', per: 25 }, sortable: ['number', 'period', 'amount', 'status'], page: 1, last: 1, per: 25, sizes: [25, 50, 100], from: 1, to: 3, total: 3, q: null },
        })
        await flush(nextTick)
        try {
            const overdue = badgeOf(view, 'Overdue')
            // "Unpaid", the money list's word since Hassam's ruling of 11 Oct (it read "Due").
            const due = badgeOf(view, 'Unpaid')
            const paid = badgeOf(view, 'Paid')

            assert.ok(overdue && due && paid, 'each invoice has its badge')
            assert.ok(byClass('overdue')(overdue), 'an overdue invoice wears the overdue treatment')
            assert.ok(!byClass('active')(overdue), 'and never the green pill')
            assert.ok(byClass('active')(paid), 'a paid invoice is green')
            assert.ok(!byClass('active')(due) && !byClass('overdue')(due), 'a due invoice is a plain pill')
        } finally {
            view.unmount()
        }
    })

    it('draws the itemised view\'s badge the same way', async () => {
        shell('/settings/billing/invoices/1')
        const view = await mount(BillingInvoice, {
            estate: { name: 'Phoenix Park' },
            invoice: {
                id: 1, number: 'FT-INV-202610', reference: 'FT-INV-202610', period: 'October 2026', due_on: 'October 15, 2026', settlement: 'DUE OCT 15, 2026',
                status: 'issued', status_label: 'Overdue', status_tone: 'overdue',
                lines: [{ description: 'Premium subscription', detail: '2,000 × $340.00', amount: '$680,000.00', is_subscription: true }],
                total: '$770,000.00', subscription_total: '$680,000.00', reconciliation: null, notes: [], credited: '$0.00', payable: '$770,000.00', has_credits: false,
            },
            pdfHref: '/settings/billing/invoices/1/pdf',
            billingHref: '/settings/billing',
        })
        await flush(nextTick)
        try {
            const badge = badgeOf(view, 'Overdue')
            assert.ok(badge, 'the badge is drawn')
            assert.ok(byClass('overdue')(badge) && !byClass('active')(badge), 'overdue, not green')
        } finally {
            view.unmount()
        }
    })
})

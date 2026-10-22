/**
 * Resending an invoice asks first — `Pages/Gemini/Billing/Invoice.vue` (DR-074, Part 7's rule).
 *
 *   "Resend to client" emailed the estate's billing readers at the first press. A control that starts contact confirms
 *   first, naming who is told, counted from the server: the question names the invoice, how many people and their
 *   addresses (`resendTo`, the same selection the resend makes), and only the dialog's own button sends. When nobody may
 *   read the invoice, Resend is off before the press, with the server's own reason (`resendRefusal`).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

const { default: Invoice } = await import('../../resources/js/Pages/Gemini/Billing/Invoice.vue')

const mounted = []

afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
    visits.length = 0
})

const INVOICE = {
    id: 12,
    reference: 'PPV-INV-202609',
    client: 'Phoenix Park Village 1',
    tenant_id: 'phoenixpark',
    clientHref: '/clients/phoenixpark',
    period: 'Sep 2026',
    periodLabel: 'September 2026',
    amount: '$171,000.00',
    total: '$171,000.00',
    total_minor: 17_100_000,
    due_on: 'September 15, 2026',
    status: 'issued',
    statusLabel: 'Issued',
    settlement: 'Due September 15, 2026',
    lines: [{ description: 'Subscription', detail: '450 × $340.00', amount: '$153,000.00', total_minor: 15_300_000 }],
    reconciliation: null,
}

const open = async (over = {}) => {
    resetInertia(geminiShared({ auth: { user: { id: 78, name: 'Dora Director', role_label: 'Director' } }, errors: {}, flash: {} }), '/billing/invoices/12')
    const view = await mount(Invoice, {
        invoice: INVOICE,
        notes: [],
        credited_minor: 0,
        sends: [],
        canResend: true,
        resendDisabledReason: 'Sending an invoice needs Billing access.',
        canCredit: true,
        creditDisabledReason: '',
        resendTo: ['dahlia@example.com', 'everton@example.com'],
        resendRefusal: null,
        ...over,
    })
    mounted.push(view)
    await flush(nextTick)

    const resendButton = () => view.one((el) => el.tag === 'button' && /Resend to client/.test(textOf(el)))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /^Email /.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim()))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, resendButton, asking, inAsk, posts }
}

describe('resending an invoice asks first (DR-074)', () => {
    it('names the invoice, how many people and their addresses, and sends nothing on the first press', async () => {
        const { view, resendButton, asking, inAsk, posts } = await open()
        await view.click(resendButton())

        assert.equal(posts().length, 0, 'nothing is sent on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Email PPV-INV-202609 to 2 people at Phoenix Park Village 1?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /To dahlia@example\.com and everton@example\.com, the estate accounts that can open its billing\./)
        assert.match(body, /Nobody else is told\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Resend invoice')

        await view.click(inAsk('cd-confirm'))
        assert.equal(posts().length, 1)
        assert.equal(posts()[0].url, '/billing/invoices/12/resend')
    })

    // M98 (M96 level 2): the server's reason is written under the greyed Resend, not only in its tooltip. Yes/no checks
    // only: a failing assert prints its values, and an element prints the whole rendered tree.
    it('is off before the press when nobody may read the invoice, with the server\'s reason on the page, and asks nothing', async () => {
        const why = 'Nobody at Phoenix Park Village 1 who may read its billing has an active account with an address, so there is nobody to send it to.'
        const { view, resendButton, asking, posts } = await open({ resendTo: [], resendRefusal: why })

        assert.ok(resendButton().attrs.disabled, 'Resend is off')
        assert.equal(view.all(byClass('inv-why')).some((el) => textOf(el).trim() === why), true, 'the reason is on the page')
        await view.click(resendButton())
        assert.equal(asking() === undefined, true, 'no question opens')
        assert.equal(posts().length, 0)
    })

    it('has no Resend and no credit note for a role that may never use either (M96 level 3)', async () => {
        const { view } = await open({ canResend: false, canCredit: false })

        assert.equal(view.all((el) => el.tag === 'button' && /Resend to client|Issue credit note/.test(textOf(el))).length, 0)
    })
})

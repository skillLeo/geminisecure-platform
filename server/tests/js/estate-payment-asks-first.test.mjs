/**
 * A manual payment asks first — `Pages/Estate/Dues/UnitLedger.vue` (M87 §1.5, Sunday step 5).
 *
 *   "Record payment" posted on one press: a receipt number taken and the household's ledger credited, with nothing
 *   between a mistyped amount and the books. Now the press asks, as Post charge does (DR-006), naming the amount
 *   ("$12,400.00", Law 5), the lot, the household by name, how and when the money came ("10 Oct 2026") and what posting
 *   does. Only the question's own "Record payment" posts, once, the payment as typed. "Change it" posts nothing and goes
 *   back to the form as it was. An amount the server would refuse still goes straight to it, so its own sentence lands
 *   on the field.
 *
 *   Since the M98 sweep the form and its question are one console dialog (M95 §1): the payment's details, "Check the
 *   payment", then the question in the same dialog. Elements are compared as booleans: a failing assert on a rendered
 *   element serialises the whole Vue tree.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: UnitLedger } = await import('../../resources/js/Pages/Estate/Dues/UnitLedger.vue')

const UNIT = {
    id: 47,
    reference: 'Lot 47',
    phase: 'Phase 1',
    household: 'Bennett household',
    resident: 'Marcia Bennett',
    title: 'Lot 47 · Marcia Bennett',
    meta: 'Phase 1 · Lot 47 · Bennett household',
}

const PROPS = {
    estate: { name: 'Phoenix Park' },
    unit: UNIT,
    balance_minor: 1_240_000,
    bucket: 'current',
    bucket_label: 'Current',
    strip: [],
    statement: [],
    table: { total: 0, current: 1, last: 1, from: null, to: null, sort: 'date', dir: 'desc' },
    canRecord: true,
    today: '2026-10-10',
    flag: null,
    flagKinds: {},
    canFlag: false,
    flagBlockedReason: 'Your role cannot flag a household.',
    documents: [],
    canReverse: false,
}

const FORM_TITLE = 'Record a payment against Lot 47'

let user = 87000

const open = async (overrides = {}) => {
    resetInertia({ auth: { user: { id: ++user, name: 'Tracey Reid', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {} }, '/estate/phoenixpark/finance/units/47')
    const view = await mount(UnitLedger, { ...PROPS, ...overrides })
    await flush(nextTick)

    const button = (label) => view.all((el) => el.tag === 'button' && textOf(el).trim() === label)[0] ?? null
    const input = (id) => view.one((el) => (el.tag === 'input' || el.tag === 'select') && el.attrs.id === id)
    const dialog = () => view.one((el) => el.attrs?.role === 'dialog')
    const inDialog = (cls) => findAll(dialog(), byClass(cls))[0]
    const title = () => textOf(inDialog('cd-title')).trim()
    const posts = () => visits.filter((v) => v.method === 'post')

    // The stack's primary opens the dialog on the payment's details; the day starts at the estate's today.
    await view.click(button('Record manual payment'))

    const press = async (amount = '12400') => {
        await view.type(input('pay-amount'), amount)
        await view.click(inDialog('cd-confirm'))
        await nextTick()
    }

    return { view, input, dialog, inDialog, title, posts, press }
}

describe('a manual payment asks before it posts (M87 §1.5)', () => {
    it('names the amount, the lot, the household, how and when it came and what it does, and posts nothing', async () => {
        const { dialog, inDialog, title, posts, press } = await open()
        assert.equal(title(), FORM_TITLE, 'the dialog opens on the payment')
        await press()

        assert.equal(posts().length, 0, 'one press posted a payment')
        assert.equal(title(), 'Record $12,400.00 against Lot 47?')
        const body = textOf(inDialog('cd-body'))
        assert.match(body, /Marcia Bennett's household\. Cash, received 10 Oct 2026\./)
        assert.match(body, /This credits their ledger straight away and issues the next receipt number\./)
        assert.doesNotMatch(textOf(dialog()), /—/, 'no em dash in what it asks (Law 3)')
        assert.equal(textOf(inDialog('cd-confirm')).trim(), 'Record payment')
        assert.equal(textOf(inDialog('cd-cancel')).trim(), 'Change it')
    })

    it('Change it goes back to the form, posts nothing and keeps what was typed', async () => {
        const { view, input, inDialog, title, posts, press } = await open()
        await press()
        await view.click(inDialog('cd-cancel'))

        assert.equal(title(), FORM_TITLE, 'back on the payment, not closed')
        assert.equal(posts().length, 0, 'nothing was posted')
        assert.equal(input('pay-amount').value ?? input('pay-amount').attrs.value, '12400', 'the amount is still there to correct')
    })

    it("the question's Record payment posts once, the payment as typed", async () => {
        const { view, inDialog, posts, press } = await open()
        await press()
        assert.equal(posts().length, 0, 'nothing before the question is answered')
        assert.ok(inDialog('cd-confirm') !== undefined, 'the dialog asks')
        await view.click(inDialog('cd-confirm'))

        assert.equal(posts().length, 1, 'one post')
        const [post] = posts()
        assert.equal(post.url, '/estate/phoenixpark/finance/units/47/payments')
        assert.deepEqual(post.data, { method: 'cash', amount: '12400', received_on: '2026-10-10', reference: '' })
    })

    it('names a cheque by its number, and the cents as typed', async () => {
        const { view, input, inDialog, title, press } = await open()
        const method = input('pay-method')
        for (const option of method.options) {
            option.selected = option.value === 'cheque'
        }
        fire(method, 'change')
        await nextTick()
        await view.type(input('pay-reference'), '000123')
        await press('950.5')

        assert.equal(title(), 'Record $950.50 against Lot 47?')
        assert.match(textOf(inDialog('cd-body')), /Cheque 000123, received 10 Oct 2026\./)
    })

    it('says so when nobody is on record at the lot, and never names a household that is not there', async () => {
        const { inDialog, press } = await open({ unit: { ...UNIT, household: 'Vacant unit', resident: 'No resident on record' } })
        await press()

        const body = textOf(inDialog('cd-body'))
        assert.match(body, /No household is on record at Lot 47\. Cash, received 10 Oct 2026\./)
        assert.match(body, /This credits Lot 47's ledger straight away and issues the next receipt number\./)
        assert.doesNotMatch(body, /'s household/)
    })

    it('sends an amount the server would refuse straight to it, so its refusal lands on the field and nothing posts', async () => {
        const { title, posts, press } = await open()
        await press('12,400')

        assert.equal(title(), FORM_TITLE, 'no question over a figure that cannot post')
        assert.equal(posts().length, 1, 'the server is asked, and refuses it')
    })
})

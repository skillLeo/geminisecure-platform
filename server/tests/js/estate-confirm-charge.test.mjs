/**
 * Post charge asks first — `Pages/Estate/Dues/NewCharge.vue` (M62 B1, DR-006).
 *
 *   A single charge lands on a household's ledger and in its app the moment it posts. So pressing Post charge on one
 *   unit opens a confirmation that names the amount ("$4,500.00", Law 5), the household by name (never a lot number
 *   alone) and the due date ("25 Oct 2026"), and posts nothing. Cancel posts nothing. Only the dialog's own
 *   "Post charge" posts, once, the charge as typed. A whole phase keeps its own two presses (the list is its question).
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: NewCharge } = await import('../../resources/js/Pages/Estate/Dues/NewCharge.vue')

const PROPS = {
    estate: { name: 'Phoenix Park' },
    unit: { id: 142, reference: 'Lot 142', phase: 'Phase 1', resident: 'Marcia Bennett' },
    currentBalanceMinor: 0,
    accounts: [{ code: '4000', label: '4000 · Maintenance Fee Income' }],
    types: [{ value: 'maintenance', label: 'Maintenance fee' }],
    canPost: true,
    blockedReason: '',
    phases: ['Phase 1', 'Phase 2'],
    bulkPreview: null,
    previewToken: null,
}

let user = 61000

const open = async (overrides = {}) => {
    // `errors` as Inertia always shares it: the page reads the server's refusals off it.
    resetInertia({ auth: { user: { id: ++user, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {} }, '/finance/charges/new')
    const view = await mount(NewCharge, { ...PROPS, ...overrides })
    await flush(nextTick)

    const input = (id) => view.one((el) => el.tag === 'input' && el.attrs.id === id)
    const form = view.one((el) => el.tag === 'form' && byClass('form-panel')(el))
    const dialog = () => view.one((el) => el.attrs?.role === 'dialog')
    const inDialog = (cls) => findAll(dialog(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    const fill = async () => {
        await view.type(input('amount'), '4500')
        await view.type(input('due_on'), '2026-10-25')
        await view.type(input('description'), 'October maintenance')
    }

    const press = async () => {
        fire(form, 'submit')
        await nextTick()
    }

    return { view, dialog, inDialog, posts, fill, press }
}

describe('Post charge on one unit asks first (DR-006)', () => {
    it('names the amount, the household and the due date, and posts nothing', async () => {
        const { dialog, inDialog, posts, fill, press } = await open()
        await fill()
        await press()

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.ok(dialog(), 'a confirmation opens')
        assert.equal(textOf(inDialog('cd-title')).trim(), 'Post $4,500.00 to Lot 142?')
        const body = textOf(inDialog('cd-body'))
        assert.match(body, /Marcia Bennett's household\./)
        assert.match(body, /Due 25 Oct 2026\./)
        assert.match(body, /This appears on their ledger and in their app straight away\./)
        assert.equal(textOf(inDialog('cd-confirm')).trim(), 'Post charge')
        assert.equal(textOf(inDialog('cd-cancel')).trim(), 'Cancel')
    })

    it('Cancel closes it and posts nothing', async () => {
        const { view, dialog, inDialog, posts, fill, press } = await open()
        await fill()
        await press()
        await view.click(inDialog('cd-cancel'))

        assert.equal(dialog(), null, 'the confirmation is gone')
        assert.equal(posts().length, 0, 'nothing was posted')
    })

    it("the dialog's Post charge posts once, the charge as typed", async () => {
        const { view, inDialog, posts, fill, press } = await open()
        await fill()
        await press()
        assert.equal(posts().length, 0, 'nothing before the dialog is answered')
        assert.ok(inDialog('cd-confirm'), 'the dialog asks')
        await view.click(inDialog('cd-confirm'))

        assert.equal(posts().length, 1, 'one post')
        const [post] = posts()
        assert.match(post.url, /\/finance\/charges$/)
        assert.equal(post.data.unit, 'Lot 142')
        assert.equal(post.data.amount, '4500.00')
        assert.equal(post.data.due_on, '2026-10-25')
    })

    it('says so when nobody is on record at the unit, and never names a household that is not there', async () => {
        const { inDialog, fill, press } = await open({ unit: { id: 9, reference: 'Lot 9', phase: 'Phase 1', resident: 'No resident on record' } })
        await fill()
        await press()

        const body = textOf(inDialog('cd-body'))
        assert.match(body, /No household is on record at Lot 9\./)
        assert.doesNotMatch(body, /'s household/)
    })

    it('"Show me who this reaches" does what it says: filled in, the server\'s list is drawn, nothing posted yet (DR-025)', async () => {
        const { view } = await open({
            bulkPreview: { description: 'October maintenance', amount_label: '$4,500.00', count: 242, phase: 'Phase 2', total_label: '$1,089,000.00', units: [{ id: 357, reference: 'Lot 357' }], more: 241 },
            previewToken: 'tok',
        })
        const text = view.text()

        // Step two is the console's dialog since the M98 sweep (M95 §1): the count is in its title, the rest under it.
        assert.match(text, /Post \$4,500\.00 to 242 units\?/)
        assert.match(text, /October maintenance: \$4,500\.00 to each unit in Phase 2, \$1,089,000\.00 in total\. Each gets its own charge, and all of them post together or none does\. Nothing has been posted yet\./)
        assert.doesNotMatch(text.slice(text.indexOf('October maintenance'), text.indexOf('Nothing has been posted yet.')), /—/, 'no em dash in the list it draws (Law 3)')
        assert.match(text, /and 241 more: every unit in Phase 2/)
        assert.doesNotMatch(text.slice(text.indexOf('and 241 more'), text.indexOf('every unit in Phase 2') + 25), /—/)
        assert.ok(view.one((el) => el.tag === 'button' && /Post to all 242 units/.test(textOf(el))), 'and the button that bills them, as the second press')
    })

    it('a whole phase keeps its own two presses: the first draws the list and asks nothing more', async () => {
        const { view, dialog, posts, fill, press } = await open()
        const phaseChip = view.one((el) => el.tag === 'button' && /Whole phase/.test(textOf(el)))
        await view.click(phaseChip)
        await fill()
        await press()

        assert.equal(dialog(), null)
        assert.equal(posts().length, 1)
        assert.match(posts()[0].url, /\/finance\/charges\/preview$/)
    })
})

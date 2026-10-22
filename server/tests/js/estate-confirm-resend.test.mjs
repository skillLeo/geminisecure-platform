/**
 * The register's Resend asks first — `Pages/Estate/Residents/Index.vue` (DR-070, Part 7's rule; step 3 of Sunday's walk).
 *
 *   On the households view a pending household's invite line carried a Resend that emailed at the first press ("not
 *   destructive, so no confirmation"). A control that starts contact confirms first, naming how many and by what
 *   channel: the question names the person and the address, and only the dialog's own button sends.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Index } = await import('../../resources/js/Pages/Estate/Residents/Index.vue')

const ROW = {
    id: 12, initials: 'MB', name: 'Marcia Bennett', sample: false, email: 'marcia@example.com', standing: { tone: null, label: 'Hidden' },
    verification: 'pending', verification_label: 'Pending', last_active: null, unit_slug: 'lot-47', household_param: null, unit: 'Phase 1 · Lot 47',
}

const open = async () => {
    resetInertia({ auth: { user: { id: 69101, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/residents?view=households')
    const view = await mount(Index, {
        estate: { name: 'Phoenix Park' },
        view: 'households',
        pending: { count: 0, title: '', body: '' },
        phase_chips: [],
        filters: { phase: '', search: '' },
        rows: [ROW],
        total: 1,
        shown: 1,
        table: { q: '', total: 1, page: 1, per: 25, last: 1, from: 1, to: 1, sort: null, dir: null, default: { sort: 'lot', dir: 'asc' }, sortable: [] },
        money_visible: false,
        money_reason: null,
        canCreate: true,
        canReview: true,
        blockedReason: '',
        inviteStates: { 12: { resident_id: 9, status: 'expired', line: 'The invite lapsed without being used.', can_resend: true, resend_after: null } },
    })
    await flush(nextTick)

    const resendButton = () => view.one((el) => el.tag === 'button' && byClass('reg-resend')(el))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /invite to/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d)))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, resendButton, asking, inAsk, posts }
}

describe('the register\'s Resend asks first (DR-070)', () => {
    it('names the person and the address, and sends nothing on the first press', async () => {
        const { view, resendButton, asking, inAsk, posts } = await open()
        assert.ok(resendButton(), 'the Resend is drawn')
        await view.click(resendButton())

        assert.equal(posts().length, 0, 'nothing is sent on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Resend the invite to Marcia Bennett?')
        assert.match(textOf(inAsk('cd-body')), /An email to marcia@example\.com, with the link to join the Resident App\. Nobody else is told\./)
    })

    it('Cancel sends nothing; the dialog\'s own button sends once, for that person', async () => {
        const first = await open()
        await first.view.click(first.resendButton())
        await first.view.click(first.inAsk('cd-cancel'))
        assert.equal(first.asking(), undefined)
        assert.equal(first.posts().length, 0)

        const second = await open()
        await second.view.click(second.resendButton())
        await second.view.click(second.inAsk('cd-confirm'))
        assert.equal(second.posts().length, 1)
        assert.match(second.posts()[0].url, /\/residents\/lot-47\/people\/9\/invite$/)
    })

    it('the invite line has no em dash before Resend (Law 3)', async () => {
        const { view } = await open()

        assert.doesNotMatch(textOf(view.one(byClass('reg-invite'))), /—/)
    })
})

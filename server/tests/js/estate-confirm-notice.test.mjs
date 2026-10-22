/**
 * Post notice asks first — `Pages/Estate/Governance/Notices.vue` (DR-055; Hassam, 10 Oct: "it is a pattern").
 *
 *   A notice is pushed to every resident it is for the moment it is posted ("New notice from your estate"). So Post
 *   notice opens a confirmation naming how many residents and by what channel, the count the server sends with each
 *   audience (`audiences[].size`), and posts nothing; Cancel posts nothing; only the dialog's own "Post notice" posts.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Notices } = await import('../../resources/js/Pages/Estate/Governance/Notices.vue')

const AUDIENCES = [
    { key: 'estate', phase: null, label: 'Everybody on the estate', size: 1234 },
    { key: 'phase', phase: 'Phase 2', label: 'Phase 2 only', size: 310 },
]

let user = 66000

const open = async () => {
    resetInertia({ auth: { user: { id: ++user, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/notices')
    const view = await mount(Notices, {
        estate: { name: 'Phoenix Park' },
        rows: [],
        table: { q: '', total: 0, page: 1, per: 20, last_page: 1, sort: 'posted', dir: 'desc' },
        audiences: AUDIENCES,
        kinds: [{ key: 'general', label: 'General' }, { key: 'urgent', label: 'Urgent' }],
        canPost: true,
        electionYear: 2026,
        reasons: { post: '' },
    })
    await flush(nextTick)

    const field = (id) => view.one((el) => el.attrs?.id === id)
    const asking = () => view.all((el) => el.attrs?.role === 'dialog')[0]
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    const write = async () => {
        await view.type(field('notice-title'), 'Water interruption')
        await view.type(field('notice-body'), 'The supply is off tomorrow from 9 AM to 3 PM.')
    }

    const press = async () => view.click(view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Post notice' && byClass('stack-btn')(el)))

    return { view, field, asking, inAsk, posts, write, press }
}

describe('Post notice asks first (DR-055)', () => {
    it('names how many residents and by what channel, from the server, and posts nothing', async () => {
        const { asking, inAsk, posts, write, press } = await open()
        await write()
        await press()

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.ok(asking(), 'a confirmation opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Post this notice to 1,234 residents?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /Water interruption · Everybody on the estate\./)
        assert.match(body, /They are told in the app, and by email where they asked for it\. You cannot unsend this\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Post notice')
    })

    it("names the phase's own count when the notice is for one phase", async () => {
        const { field, inAsk, write, press } = await open()
        await write()
        const audience = field('notice-audience')
        audience.value = '1'
        fire(audience, 'change')
        await nextTick()
        await press()

        assert.equal(textOf(inAsk('cd-title')).trim(), 'Post this notice to 310 residents?')
        assert.match(textOf(inAsk('cd-body')), /Water interruption · Phase 2 only\./)
    })

    it('Cancel closes it and posts nothing', async () => {
        const { view, asking, inAsk, posts, write, press } = await open()
        await write()
        await press()
        await view.click(inAsk('cd-cancel'))

        assert.equal(asking(), undefined)
        assert.equal(posts().length, 0)
    })

    it("the dialog's Post notice posts once, the notice as written", async () => {
        const { view, inAsk, posts, write, press } = await open()
        await write()
        await press()
        assert.equal(posts().length, 0)
        assert.ok(inAsk('cd-confirm'))
        await view.click(inAsk('cd-confirm'))

        assert.equal(posts().length, 1)
        assert.match(posts()[0].url, /\/governance\/notices$/)
        assert.equal(posts()[0].data.title, 'Water interruption')
        assert.equal(posts()[0].data.audience_scope, 'estate')
    })
})

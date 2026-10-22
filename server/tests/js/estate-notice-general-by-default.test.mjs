/**
 * A notice is General unless somebody chooses Urgent (NOT-1, register DR-090).
 *
 *   `Pages/Estate/Governance/Notices.vue`: "Post a notice" opened with Urgent chosen, so a notice nobody thought about
 *   went to the estate as Urgent. It opens on General; Urgent is a press, and what is posted says which was chosen.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Notices } = await import('../../resources/js/Pages/Estate/Governance/Notices.vue')

const open = async () => {
    resetInertia({ auth: { user: { id: 91108, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/notices')
    const view = await mount(Notices, {
        estate: { name: 'Phoenix Park' },
        rows: [],
        table: { q: '', total: 0, page: 1, per: 20, last_page: 1, sort: 'posted', dir: 'desc' },
        audiences: [{ key: 'estate', phase: null, label: 'Everybody on the estate', size: 1234 }],
        kinds: [{ key: 'general', label: 'General' }, { key: 'urgent', label: 'Urgent' }],
        canPost: true,
        electionYear: 2026,
        reasons: { post: '' },
    })
    await flush(nextTick)

    const kind = (label) => view.one((el) => el.tag === 'button' && byClass('seg-item')(el) && textOf(el).trim() === label)

    return { view, kind }
}

describe('a notice is General unless chosen Urgent (NOT-1)', () => {
    it('opens with General chosen, not Urgent', async () => {
        const { view, kind } = await open()
        try {
            assert.ok(byClass('active')(kind('General')), 'General is chosen')
            assert.ok(!byClass('active')(kind('Urgent')), 'Urgent is not')
        } finally {
            view.unmount()
        }
    })

    it('posts General when nobody chose, and Urgent only when pressed', async () => {
        const { view, kind } = await open()
        try {
            const field = (id) => view.one((el) => el.attrs?.id === id)
            const post = async () => {
                visits.length = 0
                await view.click(view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Post notice' && byClass('stack-btn')(el)))
                await view.click(view.all((el) => el.attrs?.role === 'dialog')[0] && view.one((el) => byClass('cd-confirm')(el)))
                return visits.find((v) => v.method === 'post')
            }

            await view.type(field('notice-title'), 'Water interruption')
            await view.type(field('notice-body'), 'The supply is off tomorrow from 9 AM to 3 PM.')

            assert.equal((await post())?.data?.kind, 'general', 'nobody chose: General')

            await view.click(kind('Urgent'))

            assert.equal((await post())?.data?.kind, 'urgent', 'Urgent, pressed')
        } finally {
            view.unmount()
        }
    })
})

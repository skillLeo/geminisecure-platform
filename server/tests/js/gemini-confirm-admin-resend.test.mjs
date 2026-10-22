/**
 * Resending a platform admin's invitation asks first — `Pages/Gemini/Settings/Admins.vue` (DR-077, Part 7's rule).
 *
 *   An open invitation's Resend emailed the person a fresh link at the first press ("not destructive, so it asks
 *   nothing first"). A control that starts contact confirms first, naming who is told and by what channel: the question
 *   names the address and the role, says the earlier link stops working once the new one goes, and only the dialog's
 *   own button sends.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

const { default: Admins } = await import('../../resources/js/Pages/Gemini/Settings/Admins.vue')

// The page keeps a one-second clock for Resend's countdown: every page mounted here is unmounted after its test.
const mounted = []

afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
})

const INVITATION = {
    id: 4,
    email: 'ops.lead@example.com',
    role: 'Operations Manager',
    invited_by: 'Dora Director',
    expires: 'Oct 22',
    expired: false,
    delivery: 'Emailed Oct 8, 4:10 PM',
    delivery_state: 'sent',
    resend_after: null,
    last_try: 'sent',
}

const open = async () => {
    resetInertia(geminiShared({ auth: { user: { id: 78, name: 'Dora Director', role_label: 'Director' } }, errors: {}, flash: {} }), '/settings/admins')
    const view = await mount(Admins, {
        tabs: [{ key: 'admins', label: 'Platform admins', href: '/settings/admins', active: true }],
        people: [{ id: 78, name: 'Dora Director', email: 'dora@example.com', role: 'platform.director', role_label: 'Director', site_names: 'All sites', status: 'active', sites: [], reason: 'You cannot change your own account.' }],
        invitations: [INVITATION],
        roles: [{ name: 'platform.operations_manager', label: 'Operations Manager', is_system: true, scoped: false }],
        clients: [],
        canInvite: true,
        canManage: true,
        canRemove: true,
        writeDisabledReason: '',
        removeDisabledReason: '',
    })
    mounted.push(view)
    await flush(nextTick)

    const resendButton = () => view.one((el) => el.tag === 'button' && /^Resend$/.test(textOf(el).trim()))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /invitation to/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d)))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, resendButton, asking, inAsk, posts }
}

describe('resending a platform admin\'s invitation asks first (DR-077)', () => {
    it('names the address, the role and what becomes of the earlier link, and sends nothing on the first press', async () => {
        const { view, resendButton, asking, inAsk, posts } = await open()
        assert.ok(resendButton(), 'the Resend is drawn')
        await view.click(resendButton())

        assert.equal(posts().length, 0, 'nothing is sent on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Resend the invitation to ops.lead@example.com?')
        assert.match(textOf(inAsk('cd-body')), /An email to ops\.lead@example\.com, with a fresh link to join the Gemini Console as Operations Manager\. Once it goes, the earlier link stops working\. Nobody else is told\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Resend invitation')
    })

    it('Cancel sends nothing; the dialog\'s own button sends once, for that invitation', async () => {
        const first = await open()
        await first.view.click(first.resendButton())
        await first.view.click(first.inAsk('cd-cancel'))
        assert.equal(first.asking(), undefined)
        assert.equal(first.posts().length, 0)

        const second = await open()
        await second.view.click(second.resendButton())
        await second.view.click(second.inAsk('cd-confirm'))
        assert.equal(second.posts().length, 1)
        assert.match(second.posts()[0].url, /\/settings\/admins\/invitations\/4\/resend$/)
    })
})

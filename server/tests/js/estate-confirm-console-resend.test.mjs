/**
 * Resending a console invitation asks first — `Pages/Estate/Settings/Users.vue` (DR-071, Part 7's rule).
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
import { flush } from './support/alerts.mjs'

const { default: Users } = await import('../../resources/js/Pages/Estate/Settings/Users.vue')

/*
 * The page keeps a one-second clock for Resend's countdown. Every page mounted here is unmounted after its test, pass
 * or fail: a failed assertion that skipped the unmount left the clock running and the test process never ended.
 */
const mounted = []

afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
})

const INVITATION = {
    id: 3,
    email: 'new.secretary@example.com',
    role_label: 'Secretary',
    invited_by: 'Dahlia Reid',
    invited_on: 'Oct 8',
    expires_on: 'Oct 22',
    expired: false,
    delivery: 'Emailed Oct 8, 4:10 PM',
    delivery_state: 'sent',
    resend_after: null,
    last_try: 'sent',
}

const open = async () => {
    resetInertia({ auth: { user: { id: 69101, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/settings/users')
    const view = await mount(Users, {
        estate: { name: 'Phoenix Park' },
        sections: [{ key: 'users', label: 'Users and roles', active: true, href: '/settings/users' }],
        rows: [{
            id: 5, initials: 'DR', name: 'Dahlia Reid', email: 'dahlia@phoenixpark.test', role: 'estate.community_super_admin', role_badge: 'Community Super Admin',
            role_label: 'Community Super Admin', is_owner: false, is_administrator: true, module_list: ['Dashboard', 'Residents'], modules: 'All modules',
            status: 'active', status_label: 'Active', manage_refusal: null, remove_refusal: null,
        }],
        removed: [],
        owner_role: null,
        roles: [{ name: 'estate.secretary', label: 'Secretary', modules: ['Residents'], module_list: ['Residents'], hand_out_refusal: null }],
        invitations: [INVITATION],
        canInvite: true,
        blockedReason: '',
        canManage: true,
        manageBlockedReason: '',
        ownerReason: '',
        canAdminister: true,
    })
    mounted.push(view)
    await flush(nextTick)

    const resendButton = () => view.one((el) => el.tag === 'button' && /^Resend$/.test(textOf(el).trim()))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /invitation to/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d)))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, resendButton, asking, inAsk, posts }
}

describe('resending a console invitation asks first (DR-071)', () => {
    it('names the address, the role and what becomes of the earlier link, and sends nothing on the first press', async () => {
        const { view, resendButton, asking, inAsk, posts } = await open()
        assert.ok(resendButton(), 'the Resend is drawn')
        await view.click(resendButton())

        assert.equal(posts().length, 0, 'nothing is sent on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Resend the invitation to new.secretary@example.com?')
        assert.match(textOf(inAsk('cd-body')), /An email to new\.secretary@example\.com, with a fresh link to join the console as Secretary\. Once it goes, the earlier link stops working\. Nobody else is told\./)
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
        assert.match(second.posts()[0].url, /\/settings\/users\/invitations\/3\/resend$/)
    })
})

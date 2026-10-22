/**
 * Sending a client contact's invitation asks first — `Pages/Gemini/Clients/Show.vue` (DR-114, Part 7's rule).
 *
 *   The client page's "Send invitation" and "Resend invitation" emailed the estate's contact at the first press ("not
 *   destructive, so it asks nothing first"). A control that starts contact confirms first, naming who is told and by
 *   what channel: the question names the person and the address, and only the dialog's own button sends.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

const { default: Client } = await import('../../resources/js/Pages/Gemini/Clients/Show.vue')

// The page keeps a one-second clock for the resend countdown: every page mounted here is unmounted after its test.
const mounted = []

afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
    visits.length = 0
})

const ESTATE = {
    id: 'oceanview',
    lifecycle: 'onboarding',
    withheld_reason: null,
    name: 'Ocean View',
    subtitle: 'Onboarding since Oct 1',
    tierLabel: null,
    statusLabel: 'Onboarding',
    stats: [],
    subscriptionHead: 'Plan as prepared',
    subscription: [],
    invoices: [],
    contacts: [],
    guards: [],
    onboarding: { checklist: [{ label: 'Estate admin invited', value: 'Sent', note: null }], primaryContact: [{ label: 'Name', value: 'Joan Grant' }], blockedReason: null },
}

const INVITE = {
    name: 'Joan Grant',
    email: 'joan@example.com',
    invitation_id: 9,
    delivery: 'Emailed Oct 8, 4:10 PM',
    delivery_state: 'sent',
    resend_after: null,
    last_try: 'sent',
    expires_on: 'Oct 22, 2026',
    action: 'Resend invitation',
}

const open = async (invite = INVITE) => {
    resetInertia(geminiShared({ auth: { user: { id: 78, name: 'Dora Director', role_label: 'Director' } }, errors: {}, flash: {} }), '/clients/oceanview')
    const view = await mount(Client, {
        estate: ESTATE,
        canViewBilling: true,
        canManage: true,
        guardsBlockedReason: null,
        messageBlockedReason: null,
        contactInvitation: invite,
        invitationBlockedReason: null,
        timezone: null,
    })
    mounted.push(view)
    await flush(nextTick)

    const sendButton = () => view.one((el) => el.tag === 'button' && /^(Send|Resend) invitation$/.test(textOf(el).trim()))
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /invitation to/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d)))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, sendButton, asking, inAsk, posts }
}

describe('sending a client contact\'s invitation asks first (DR-114)', () => {
    it('a resend names the person, the address and the earlier link, and sends nothing on the first press', async () => {
        const { view, sendButton, asking, inAsk, posts } = await open()
        assert.ok(sendButton(), 'the button is drawn')
        await view.click(sendButton())

        assert.equal(posts().length, 0, 'nothing is sent on the first press')
        assert.ok(asking(), 'a question opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Resend the invitation to Joan Grant?')
        assert.match(textOf(inAsk('cd-body')), /An email to joan@example\.com, with a fresh link to join Ocean View's console\. Once it goes, the earlier link stops working\. Nobody else is told\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Resend invitation')
    })

    it('a first invitation says so; Cancel sends nothing, and the dialog\'s own button sends once', async () => {
        const first = await open({ ...INVITE, invitation_id: null, delivery: 'Not sent yet', delivery_state: 'none', last_try: null, expires_on: null, action: 'Send invitation' })
        await first.view.click(first.sendButton())

        assert.equal(textOf(first.inAsk('cd-title')).trim(), 'Send the invitation to Joan Grant?')
        assert.match(textOf(first.inAsk('cd-body')), /An email to joan@example\.com, with a link to join Ocean View's console\. It names you and stands fourteen days\. Nobody else is told\./)
        await first.view.click(first.inAsk('cd-cancel'))
        assert.equal(first.asking(), undefined)
        assert.equal(first.posts().length, 0)

        await first.view.click(first.sendButton())
        await first.view.click(first.inAsk('cd-confirm'))
        assert.equal(first.posts().length, 1)
        assert.match(first.posts()[0].url, /\/clients\/oceanview\/contact-invitation\/resend$/)
    })
})

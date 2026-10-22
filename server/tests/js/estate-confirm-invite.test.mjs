/**
 * Send invite asks first — `Pages/Estate/Residents/Show.vue` (DR-069, Part 7's rule; step 3 of Sunday's walk).
 *
 *   "Send invite" emailed the person at the first press. A control that starts contact confirms first, naming how many
 *   and by what channel: the question names the person and the address the email goes to, and only the dialog's own
 *   button sends. Cancel sends nothing.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Show } = await import('../../resources/js/Pages/Estate/Residents/Show.vue')

const PERSON = {
    id: 5, full_name: 'Marcia Bennett', panel_name: 'Marcia Bennett', email: 'marcia@example.com', phone: '+1 876 555 0147', relationship: 'owner',
    is_primary: true, is_pending: true, status_label: 'Pending', alerts_to_primary: false, app_access: { state: 'none', label: 'No app yet' },
    access_level: 'full', active: true, moved_in_on: null, sample: false, invite: { status: 'none' },
}

const open = async (person = PERSON) => {
    resetInertia({ auth: { user: { id: 69001, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/residents/lot-47')
    const view = await mount(Show, {
        estate: { name: 'Phoenix Park' },
        resident: { household_id: 1, household_name: 'Bennett household', name: 'Marcia Bennett', sample: false, sub: 'Lot 47 · Phase 1', unit_id: 47, unit_reference: 'Lot 47', unit_slug: 'lot-47', verification: 'pending', verification_label: 'Pending' },
        standing: {},
        stats: [],
        // The page is "populated" by its members (useScreenState): one row, as the household's member list draws it.
        members: [{ label: 'Marcia Bennett', value: 'Owner · Pending', sample: false, focus: null, scroll: null }],
        contact: [],
        linked: [],
        canViewLedger: false,
        canEdit: true,
        editBlockedReason: '',
        people: [person],
        reasons: {},
        canEndAppAccess: false,
        appAccessBlockedReason: '',
        canInvite: true,
        inviteBlockedReason: '',
        unitId: 47,
    })
    await flush(nextTick)

    const press = () => {
        const button = view.one((el) => el.tag === 'button' && byClass('invite-btn')(el))
        assert.ok(button, `the invite button is drawn; the page reads: ${view.text().slice(0, 400)}`)

        return view.click(button)
    }
    const asking = () => view.all((el) => el.attrs?.role === 'dialog').find((d) => /invite to/.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d)))
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, press, asking, inAsk, posts }
}

describe('Send invite asks first (DR-069)', () => {
    it('names the person and the address, and sends nothing on the first press', async () => {
        const { view, press, asking, inAsk, posts } = await open()
        try {
            await press()

            assert.equal(posts().length, 0, 'nothing is sent on the first press')
            assert.ok(asking(), 'a question opens')
            assert.equal(textOf(inAsk('cd-title')).trim(), 'Send the invite to Marcia Bennett?')
            assert.match(textOf(inAsk('cd-body')), /An email to marcia@example\.com, with the link to join the Resident App\. Nobody else is told\./)
            assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Send invite')
        } finally {
            view.unmount()
        }
    })

    it('Cancel sends nothing; the dialog\'s own button sends once', async () => {
        const first = await open()
        try {
            await first.press()
            await first.view.click(first.inAsk('cd-cancel'))
            assert.equal(first.asking(), undefined)
            assert.equal(first.posts().length, 0)
        } finally {
            first.view.unmount()
        }

        const second = await open()
        try {
            await second.press()
            await second.view.click(second.inAsk('cd-confirm'))
            assert.equal(second.posts().length, 1)
            assert.match(second.posts()[0].url, /\/residents\/lot-47\/people\/5\/invite$/)
        } finally {
            second.view.unmount()
        }
    })

    it('a resend says so', async () => {
        const { view, press, inAsk } = await open({ ...PERSON, invite: { status: 'sent', email: 'marcia@example.com', sent_at: '2026-10-09T12:00:00Z' } })
        try {
            await press()

            assert.equal(textOf(inAsk('cd-title')).trim(), 'Resend the invite to Marcia Bennett?')
            assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Resend invite')
        } finally {
            view.unmount()
        }
    })
})

/**
 * A household, after the M98 pass (`Pages/Estate/Residents/Show.vue`).
 *
 *   Edit details, End app access and Remove household open the console's modal, never a panel between the hero and the
 *   household (M95 §1), each greyed button saying why on the screen; a refused invite is said in its dialog (Hassam,
 *   12 Oct; it was a toast, M95 §2); the way back
 *   to the register is in words (M95 §3); Message household, which is not built, is off the screen, and a role that can
 *   never edit, invite or change a level has none of those controls (M96 level 3); End app access waits with its reason
 *   on the card while nobody has an app account (level 2); a verified household reads Active (M96 §2).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Show } = await import('../../resources/js/Pages/Estate/Residents/Show.vue')
const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')

const PERSON = {
    id: 5, full_name: 'Ricardo Hall', panel_name: 'Ricardo Hall', email: 'ricardo@example.com', phone: '+1 876 555 0147', relationship: 'spouse',
    is_primary: false, is_pending: true, status_label: 'Pending', alerts_to_primary: false, app_access: { state: 'none', label: 'No app yet' },
    access_level: 'member', active: true, moved_in_on: '2024-03-01', sample: false, invite: { status: 'none' },
}

const WITH_APP = { ...PERSON, id: 6, full_name: 'Dahlia Hall', panel_name: 'Dahlia Hall', is_pending: false, app_access: { state: 'active', label: 'Uses the Resident App', can_end: true, can_restore: false } }

const LEVELS = [
    { value: 'member', label: 'Member', sentence: 'Everything the household can do.', may: { household: true, dues: true } },
    { value: 'helper', label: 'Helper', sentence: 'The gate only.', may: { approvals: true } },
]

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 98201, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/residents/lot-9')
    const view = await mount(Show, {
        estate: { name: 'Phoenix Park' },
        resident: { household_id: 1, household_name: 'Hall household', name: 'Ricardo Hall', sample: false, sub: 'Lot 9 · Phase 1', unit_id: 9, unit_reference: 'Lot 9', unit_slug: 'lot-9', verification: 'verified', verification_label: 'Verified' },
        standing: {},
        stats: [],
        members: [{ label: 'Ricardo Hall', value: 'Spouse · Verified', sample: false, focus: null, scroll: null }],
        contact: [],
        linked: [],
        canViewLedger: false,
        canEdit: true,
        editBlockedReason: 'Correcting a record is not part of your role.',
        people: [PERSON],
        reasons: { message: 'Messaging a household is not built yet.' },
        canEndAppAccess: true,
        canRemoveHousehold: true,
        appAccessBlockedReason: 'Ending app access is not part of your role.',
        canInvite: true,
        inviteBlockedReason: 'Inviting is not part of your role.',
        accessLevels: LEVELS,
        canChangeLevel: true,
        levelBlockedReason: 'Changing a level is not part of your role.',
        levelsOpen: true,
        levelsClosedReason: '',
        unitId: 9,
        ...props,
    })
    await flush(nextTick)

    return view
}

const button = (view, words) => view.all((el) => el.tag === 'button' && textOf(el).trim() === words)[0] ?? null
const field = (view, id) => view.one((el) => el.attrs?.id === id)

afterEach(() => resetToasts())

describe('A household, after the M98 pass', () => {
    it('opens Edit details in the console\'s modal, saying why Save waits when the name is cleared', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Edit details'))

            assert.ok(view.one(byClass('res-panel')) === null, 'no panel on the page')
            assert.equal(textOf(view.one(byClass('cd-title'))), "Correct Ricardo Hall's record")
            assert.ok(view.one(byClass('cd-why')) === null, 'nothing stands in the way yet')

            await view.type(field(view, 'rp-name'), '')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'A resident has a name.')
        } finally {
            view.unmount()
        }
    })

    it('asks for the lot and a reason before removing the household, and says which is missing', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Remove household'))

            assert.equal(textOf(view.one(byClass('cd-title'))), 'Remove Hall household from Lot 9?')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Type the lot exactly: Lot 9.')

            await view.type(field(view, 'rm-lot'), 'lot 9')
            await view.type(field(view, 'rm-reason'), 'Sold')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Say why in a sentence.')

            await view.type(field(view, 'rm-reason'), 'Sold the property; keys handed over on the 14th')
            await view.click(view.one(byClass('cd-confirm')))
            await nextTick()
            assert.equal(visits.at(-1).url, '/residents/lot-9/household/remove')
        } finally {
            view.unmount()
        }
    })

    // The hub's finding, 12 Oct: with one app account the household's End app access asked the question that person's
    // own End access asks. The household's is for choosing among several; one person's is on their own row.
    it('opens End app access to choose among several, leaves one person to their own row, and waits with its reason for none', async () => {
        const WITH_APP_TOO = { ...WITH_APP, id: 7, full_name: 'Marcus Hall', panel_name: 'Marcus Hall' }
        const several = await open({ people: [PERSON, WITH_APP, WITH_APP_TOO] })
        try {
            await several.click(button(several, 'End app access'))
            assert.equal(textOf(several.one(byClass('cd-title'))), "End Dahlia Hall's Resident App access?")
            assert.equal(several.all(byClass('res-person')).length, 2, 'a choice of the two with an account')
            assert.equal(textOf(several.one(byClass('cd-why'))), 'Say why, in a few words at least.')
        } finally {
            several.unmount()
        }

        const one = await open({ people: [PERSON, WITH_APP] })
        try {
            assert.ok(button(one, 'End app access') === null, 'one way to ask, on the person\'s own row')
            await one.click(button(one, 'End access'))
            assert.equal(textOf(one.one(byClass('cd-title'))), "End Dahlia Hall's Resident App access?")
        } finally {
            one.unmount()
        }

        const none = await open()
        try {
            assert.equal(button(none, 'End app access').attrs.disabled, true, 'greyed')
            assert.match(textOf(none.one(byClass('res-why'))), /Nobody in this household has a Resident App account/)
        } finally {
            none.unmount()
        }
    })

    // Hassam, 12 Oct: a refusal of an open dialog is said inside it, the dialog staying open; never a toast behind it.
    it('says a refused invite inside its dialog, which stays open, and never as a toast', async () => {
        const view = await open()
        try {
            await view.click(view.one((el) => el.tag === 'button' && byClass('invite-btn')(el)))
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ invite: 'That address already has an account at another lot.' })
            await nextTick()

            assert.ok(view.one(byClass('cd-panel')) !== null, 'the dialog is still open')
            assert.equal(textOf(view.one(byClass('cd-error'))).trim(), 'That address already has an account at another lot.')
            assert.ok(view.one(byClass('invite-error')) === null, 'no line on the page')
            assert.ok(!toasts.some((each) => each.tone === 'error'), 'no toast while the dialog is open')
        } finally {
            view.unmount()
        }
    })

    it('goes back to the register in words, and reads a verified household as Active', async () => {
        const view = await open()
        try {
            const back = view.one((el) => el.tag === 'a' && textOf(el) === 'Back to residents')
            assert.ok(back)
            assert.equal(back.attrs.href, '/residents')
            assert.equal(textOf(view.one(byClass('res-verification'))), 'Active')
        } finally {
            view.unmount()
        }
    })

    it('offers no control that can never work', async () => {
        const view = await open({ canEdit: false, canInvite: false, canChangeLevel: false, canRemoveHousehold: false, canEndAppAccess: false })
        try {
            assert.ok(button(view, 'Message household') === null, 'Message household is not built, so it is not drawn')
            assert.ok(button(view, 'Edit details') === null, 'no Edit details')
            assert.ok(button(view, 'Remove household') === null, 'no Remove household')
            assert.ok(button(view, 'End app access') === null, 'no End app access')
            assert.ok(view.one((el) => el.tag === 'button' && byClass('invite-btn')(el)) === null, 'no Send invite')
            assert.ok(view.one((el) => el.tag === 'select' && byClass('level-select')(el)) === null, 'no level select')
            assert.equal(textOf(view.one(byClass('level-fixed'))), 'Member', 'the level said as a fact')
        } finally {
            view.unmount()
        }
    })
})

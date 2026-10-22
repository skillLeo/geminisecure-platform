/**
 * The household's edit form says why a save was refused, for every field it sends — `Pages/Estate/Residents/Show.vue`
 * (M80 §2.1, EST-B-03a, DR-119).
 *
 *   It sends seven fields and drew a refusal for three of them: the name, the email and the phone. A refusal for the
 *   relationship, the move-in date, the primary contact or "no email" came back and nothing on the page said so: the
 *   form sat open, nothing had saved, and the office could believe it had. Every refusal the server returns is drawn
 *   in the form now, whatever field it names.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Show } = await import('../../resources/js/Pages/Estate/Residents/Show.vue')

const PERSON = {
    id: 5, full_name: 'Ricardo Hall', panel_name: 'Ricardo Hall', email: 'ricardo@example.com', phone: '+1 876 555 0147', relationship: 'spouse',
    is_primary: false, is_pending: false, status_label: 'Verified', alerts_to_primary: false, app_access: { state: 'none', label: 'No app yet' },
    access_level: 'full', active: true, moved_in_on: '2024-03-01', sample: false, invite: { status: 'none' },
}

const open = async () => {
    resetInertia({ auth: { user: { id: 80001, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/residents/lot-9')
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
        editBlockedReason: '',
        people: [PERSON],
        reasons: {},
        canEndAppAccess: false,
        appAccessBlockedReason: '',
        unitId: 9,
    })
    await flush(nextTick)

    const edit = view.one((el) => el.tag === 'button' && /^Correct a person's name/.test(el.attrs?.title ?? ''))
    assert.ok(edit, `the edit button is drawn; the page reads: ${view.text().slice(0, 300)}`)
    await view.click(edit)

    // The edit form is the console's modal since the M98 pass (M95 §1); Save details is its own button.
    const form = view.one((el) => el.attrs?.role === 'dialog')
    assert.ok(form, 'the edit form opens')

    /** Save, and answer it as the server does when it refuses one field. */
    const refuse = async (field, message) => {
        await view.click(view.one(byClass('cd-confirm')))
        await nextTick()
        const save = visits.filter((v) => v.method === 'post').at(-1)
        assert.match(save.url, /\/residents\/lot-9\/people\/5$/, 'it saves the person being edited')
        save.options.onError?.({ [field]: message })
        await nextTick()
    }
    const errors = () => view.all((el) => byClass('res-error')(el)).map((el) => textOf(el).trim())

    return { view, refuse, errors }
}

const REFUSALS = {
    full_name: 'Give the person a name.',
    email: 'That does not look like an email address.',
    phone: 'That does not look like a phone number.',
    relationship: 'Say how this person is related to the household.',
    moved_in_on: 'The move-in date is not a date.',
    is_primary: 'Say whether this person is the primary contact.',
    no_email: 'Say whether this person has an email.',
}

describe('the household edit form says why a save was refused (EST-B-03a)', () => {
    for (const [field, message] of Object.entries(REFUSALS)) {
        it(`draws a refusal for ${field}`, async () => {
            const { view, refuse, errors } = await open()
            try {
                await refuse(field, message)
                assert.ok(errors().includes(message), `"${message}" is on the page; the form shows: ${JSON.stringify(errors())}`)
            } finally {
                view.unmount()
            }
        })
    }

    it('draws a refusal for a field it does not send as well, so none can go unsaid', async () => {
        const { view, refuse, errors } = await open()
        try {
            await refuse('household', 'That person is not on this household.')
            assert.ok(errors().includes('That person is not on this household.'), JSON.stringify(errors()))
        } finally {
            view.unmount()
        }
    })
})

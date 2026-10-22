/**
 * Add a person says why a save was refused, for every field it sends — `Pages/Estate/Residents/New.vue` (M80 §2.1,
 * EST-B-03a, the second form checked the same way as the household's edit form).
 *
 *   The route checks ten fields and the form drew a refusal under seven of them. A refusal for "no email", the
 *   verification or the biometric consent came back and nothing on the page said so: ticking "no email" with "Resident
 *   verifies in the app" chosen is refused ("Somebody with no email cannot be sent an invite"), and the form sat there,
 *   saying nothing, with nobody added. The seven stay under their own fields; every other refusal is drawn above Save.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: NewResident } = await import('../../resources/js/Pages/Estate/Residents/New.vue')

let user = 80400

const open = async () => {
    resetInertia({ auth: { user: { id: ++user, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null }, '/residents/new')
    const view = await mount(NewResident, {
        estate: { name: 'Phoenix Park' },
        phases: [{ value: 'Phase 1', label: 'Phase 1' }],
        lot: { phase: 'Phase 1', typed: '', resolved: null, suggestions: ['Lot 1'], more: false, placeholder: 'Lot 1' },
        verification: [
            { value: 'invite', label: 'Resident verifies in the app' },
            { value: 'manual', label: 'Mark verified now' },
        ],
        default_verification: 'invite',
        biometric_consent: { default: false, label: 'Consent', note: 'Off.' },
        preview: { head: 'What happens next', template: '{first} is put on the register at {lot} as "Pending".' },
        canCreate: true,
        blockedReason: '',
    })
    await flush(nextTick)

    // Everything the server asks of a person, so Save can be pressed at all (Law 1: it waits until then, 12 Oct).
    for (const [id, value] of [['lot', 'Lot 1'], ['full_name', 'Owen Clarke'], ['phone', '876 555 0147'], ['email', 'owen@example.com']]) {
        await view.type(view.one((el) => el.attrs?.id === id), value)
    }

    const form = view.one((el) => el.tag === 'form' && byClass('form-panel')(el))
    assert.ok(form, 'the form is drawn')

    /** Save, and answer it as the server does when it refuses one field. */
    const refuse = async (field, message) => {
        fire(form, 'submit')
        await nextTick()
        const save = visits.filter((v) => v.method === 'post').at(-1)
        assert.ok(save, 'Save sends the form')
        save.options.onError?.({ [field]: message })
        await nextTick()
    }
    const errors = () => view.all((el) => byClass('new-resident-error')(el)).map((el) => textOf(el).trim())

    return { view, refuse, errors }
}

const UNDRAWN = {
    no_email: 'Say whether this person has an email.',
    verification: 'Somebody with no email cannot be sent an invite. Vouch for them instead.',
    biometric_consent: 'Say whether this person agrees to biometric enrolment.',
    household: 'That lot has no household to join.',
}

const DRAWN = {
    phase: 'Choose a phase.',
    lot: 'That lot is not on the site plan.',
    household_choice: 'Choose what this person is.',
    household_id: 'Choose which household this person joins.',
    full_name: 'Give the person a name.',
    email: 'That does not look like an email address.',
    phone: 'That does not look like a phone number.',
}

describe('Add a person says why a save was refused (EST-B-03a)', () => {
    for (const [field, message] of Object.entries(UNDRAWN)) {
        it(`draws a refusal for ${field}`, async () => {
            const { view, refuse, errors } = await open()
            try {
                await refuse(field, message)
                assert.ok(errors().includes(message), `"${message}" is on the page; it shows: ${JSON.stringify(errors())}`)
            } finally {
                view.unmount()
            }
        })
    }

    for (const [field, message] of Object.entries(DRAWN)) {
        it(`still draws ${field}'s refusal once, under its own field`, async () => {
            const { view, refuse, errors } = await open()
            try {
                await refuse(field, message)
                assert.equal(errors().filter((e) => e === message).length, 1, JSON.stringify(errors()))
            } finally {
                view.unmount()
            }
        })
    }
})

/**
 * Add resident, after the M98 pass (`Pages/Estate/Residents/New.vue`).
 *
 *   Add resident is its own page, so its form stays one; the way back to the register is in words (M95 §3); a save
 *   waiting on the household question is greyed with why said under it, not only on hover (M96 level 2); a role that
 *   can never add a resident is told so in place of the button (level 3); the notes under the lot say it without an
 *   em dash.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: NewResident } = await import('../../resources/js/Pages/Estate/Residents/New.vue')

const OCCUPIED = {
    unit: 'Lot 413',
    households: [{ id: 101, name: 'Lot 413 household', primary: { name: 'Owen Grant', email: 'owen@example.com', sample: false }, others: 0, sample: false }],
    balance_line: 'Adding someone does not change what the lot owes.',
}

const answer = (occupied = null, over = {}) => ({
    phase: 'Phase 2', typed: 'Lot 413', resolved: 'Lot 413', suggestions: ['Lot 413'], more: false, placeholder: 'Lot 401', question: null, occupied, ...over,
})

const open = async ({ canCreate = true, lot = answer() } = {}) => {
    resetInertia({ auth: { user: { id: 98301, name: 'Paula Manager', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/residents/new?phase=Phase%202&lot=Lot%20413')
    const view = await mount(NewResident, {
        estate: { name: 'Phoenix Park' },
        phases: [{ value: 'Phase 2', label: 'Phase 2' }],
        lot,
        verification: [{ value: 'invite', label: 'Resident verifies in the app' }, { value: 'manual', label: 'Mark verified now' }],
        default_verification: 'invite',
        biometric_consent: { default: false, label: 'Consent', note: 'Off.' },
        preview: { head: 'What happens next', template: '{first} is put on the register at {lot} as "Pending".' },
        household_choices: [{ value: 'member', label: 'A member of that household' }, { value: 'separate', label: 'A new, separate household at this lot' }],
        canCreate,
        blockedReason: 'Adding a resident is not part of your role.',
    })
    await flush(nextTick)

    return view
}

const addButton = (view) => view.all((el) => el.tag === 'button' && /Add resident/.test(textOf(el)))[0] ?? null

describe('Add resident, after the M98 pass', () => {
    it('goes back to the register in words', async () => {
        const view = await open()
        try {
            const back = view.one((el) => el.tag === 'a' && textOf(el) === 'Back to residents')
            assert.ok(back)
            assert.equal(back.attrs.href, '/residents')
        } finally {
            view.unmount()
        }
    })

    it('says under Add resident why it waits on the household question', async () => {
        const view = await open({ lot: answer(OCCUPIED) })
        try {
            await view.type(view.one((el) => el.attrs?.id === 'lot'), 'Lot 413')
            await nextTick()
            assert.equal(addButton(view).attrs.disabled, true, 'greyed')
            assert.equal(textOf(view.one(byClass('new-resident-why'))), 'Lot 413 already has a household. Choose what this person is before saving.')
        } finally {
            view.unmount()
        }
    })

    it('tells a role that can never add a resident so, in place of the button', async () => {
        const view = await open({ canCreate: false })
        try {
            assert.ok(addButton(view) === null, 'no Add resident')
            assert.equal(textOf(view.one(byClass('new-resident-why'))), 'Adding a resident is not part of your role.')
        } finally {
            view.unmount()
        }
    })

    it('says the notes under the lot without an em dash', async () => {
        const view = await open({ lot: answer(null, { resolved: null, more: true, suggestions: ['Lot 41', 'Lot 410'] }) })
        try {
            await view.type(view.one((el) => el.attrs?.id === 'lot'), 'Lot 413')
            await nextTick()
            const notes = view.all(byClass('new-resident-note')).map(textOf)
            assert.ok(notes.length > 0, 'a note is drawn')
            for (const note of notes) {
                assert.ok(!note.includes('—'), `"${note}" has no em dash`)
            }
        } finally {
            view.unmount()
        }
    })
})

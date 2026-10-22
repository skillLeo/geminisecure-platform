/**
 * Add resident at an occupied lot — `Pages/Estate/Residents/New.vue` (M32 F1).
 *
 *   The lot typed has a household with people in it: the form says who is there, whether that is the demonstration's
 *   placeholder, the balance in the words the viewer may read, and asks what this person is. Nothing is posted until
 *   it is answered; at a lot of two households, a member or a new primary is asked which household as well. The
 *   server refuses the same (M32RegisterTest); this is the browser's half.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, RAISED } from './support/alerts.mjs'

const { default: NewResident } = await import('../../resources/js/Pages/Estate/Residents/New.vue')

let user = 35000

const HOUSEHOLD = (over = {}) => ({
    id: 101,
    name: 'Lot 413 household',
    primary: { name: 'Lot 413 householder', email: 'lot413@phoenixpark.test', sample: true },
    others: 0,
    sample: true,
    ...over,
})

const answer = (occupied) => ({
    phase: 'Phase 2',
    typed: 'Lot 413',
    resolved: 'Lot 413',
    suggestions: ['Lot 413'],
    more: false,
    placeholder: 'Lot 401',
    question: null,
    occupied,
})

const CHOICES = [
    { value: 'member', label: 'A member of that household' },
    { value: 'primary', label: 'The household\'s primary resident — the person on record now becomes a member' },
    { value: 'separate', label: 'A new, separate household at this lot' },
]

const open = async (lot) => {
    resetInertia({ auth: { user: { id: ++user, name: 'Paula Manager', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null }, '/estate/phoenixpark/residents/new?phase=Phase%202&lot=Lot%20413')

    const view = await mount(NewResident, {
        estate: { name: 'Phoenix Park' },
        phases: [{ value: 'Phase 2', label: 'Phase 2' }],
        lot,
        verification: [
            { value: 'invite', label: 'Resident verifies in the app' },
            { value: 'manual', label: 'Mark verified now' },
        ],
        default_verification: 'invite',
        biometric_consent: { default: false, label: 'Consent', note: 'Off.' },
        preview: { head: 'What happens next', template: '{first} is put on the register at {lot} as "Pending".' },
        household_choices: CHOICES,
        canCreate: true,
        blockedReason: '',
    })

    await flush(nextTick)

    const input = (id) => view.one((el) => el.tag === 'input' && el.attrs.id === id)

    return {
        view,
        panel: () => view.one(byClass('occupied-panel')),
        radio: (name, value) => view.one((el) => el.tag === 'input' && el.attrs.type === 'radio' && el.attrs.name === name && el.attrs.value === value),
        fill: async () => {
            await view.type(input('full_name'), 'Hassam Example')
            await view.type(input('email'), 'hassam.example@example.com')
            await view.type(input('phone'), '876 555 0771')
        },
        submit: async () => {
            fire(view.one((el) => el.tag === 'form'), 'submit')
            await nextTick()
        },
        posts: () => visits.filter((visit) => visit.method === 'post'),
    }
}

const choose = async (page, el) => {
    fire(el, 'change')
    await nextTick()
}

beforeEach(() => {
    socket.reset()
    visits.length = 0
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED })
})

afterEach(() => {
    mock.timers.reset()
})

describe('M32 F1 — an occupied lot asks before anybody is joined to the household on it', () => {
    it('says who is there, that it is a sample, and the balance; and posts nothing until the person is placed', async () => {
        const page = await open(answer({ unit: 'Lot 413', households: [HOUSEHOLD()], balance_line: 'Balance on this household: $6,200.00. Adding someone does not change it.' }))
        await page.fill()

        const panel = textOf(page.panel())
        assert.match(panel, /Lot 413 already has a household/)
        // The panel's own elements, read as the reader reads them one after the other.
        assert.match(panel, /Lot 413 householder\s*Primary\s*lot413@phoenixpark\.test\s*sample/)
        assert.match(panel, /\(and 0 other members\)/)
        assert.match(panel, /Balance on this household: \$6,200\.00\. Adding someone does not change it\./)
        assert.doesNotMatch(panel, /Which household\?/, 'one household needs no second question')

        await page.submit()
        assert.equal(page.posts().length, 0, 'not without an answer')

        await choose(page, page.radio('household_choice', 'member'))
        await page.submit()

        assert.equal(page.posts().length, 1)
        assert.equal(page.posts()[0].data.household_choice, 'member')
        assert.equal(page.posts()[0].data.household_id, null, 'the one household is the server\'s to find')

        page.view.unmount()
    })

    it('at a lot of two households, asks which one a member joins, and sends it', async () => {
        const second = HOUSEHOLD({ id: 202, name: 'Morgan household', primary: { name: 'Shauna Morgan', email: 'shauna.morgan@example.com', sample: false }, sample: false })
        const page = await open(answer({ unit: 'Lot 413', households: [HOUSEHOLD(), second], balance_line: 'Balance on this lot: $6,200.00. Adding someone does not change it.' }))
        await page.fill()

        assert.match(textOf(page.panel()), /Lot 413 already has 2 households/)

        // "Separate" needs no household; "member" does.
        await choose(page, page.radio('household_choice', 'separate'))
        assert.doesNotMatch(textOf(page.panel()), /Which household\?/)

        await choose(page, page.radio('household_choice', 'member'))
        assert.match(textOf(page.panel()), /Which household\?\s*Lot 413 household · Lot 413 householder\s*Morgan household · Shauna Morgan/)

        await page.submit()
        assert.equal(page.posts().length, 0, 'not until the household is said')

        await choose(page, page.radio('household_id', 202))
        await page.submit()

        assert.equal(page.posts().length, 1)
        assert.equal(page.posts()[0].data.household_choice, 'member')
        assert.equal(page.posts()[0].data.household_id, 202)

        page.view.unmount()
    })

    it('forgets the answer when the lot changes to one nobody is filed at', async () => {
        const page = await open(answer({ unit: 'Lot 413', households: [HOUSEHOLD()], balance_line: 'Balance on this household: $6,200.00. Adding someone does not change it.' }))
        await choose(page, page.radio('household_choice', 'primary'))

        page.view.props.lot = { ...answer(null), typed: 'Lot 413', resolved: 'Lot 413' }
        await nextTick()
        await nextTick()

        assert.equal(page.panel(), null, 'an empty lot asks nothing')
        await page.fill()
        await page.submit()

        assert.equal(page.posts().length, 1)
        assert.equal(page.posts()[0].data.household_choice, '', 'the choice made at the occupied lot is not carried')

        page.view.unmount()
    })
})

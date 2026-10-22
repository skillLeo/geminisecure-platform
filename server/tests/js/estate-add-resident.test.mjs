/**
 * Add resident — `Pages/Estate/Residents/New.vue` (board 34).
 *
 *   M11 A2  the page is no longer sent every phase's units (2,000 at Phoenix Park) to
 *           resolve the unit field against. It asks the register as the phase and the
 *           unit change — a partial reload of `lot` — and names only the unit the
 *           register's own answer names (B041's rule, kept). An answer to an earlier
 *           keystroke is never shown as the answer to what is typed now: it is set
 *           aside and the question is asked again.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, RAISED } from './support/alerts.mjs'

const { default: NewResident } = await import('../../resources/js/Pages/Estate/Residents/New.vue')

let user = 34000

const answer = (overrides = {}) => ({
    phase: 'Phase 2',
    typed: '',
    resolved: null,
    suggestions: ['Lot 1', 'Lot 2', 'Lot 3'],
    more: true,
    placeholder: 'Lot 1',
    ...overrides,
})

const PHASES = [
    { value: 'Phase 1', label: 'Phase 1' },
    { value: 'Phase 2', label: 'Phase 2' },
]

const open = async (lot = answer(), phases = PHASES) => {
    // The console's furniture with no panic watch, so nothing but the unit field is on the move.
    resetInertia({ auth: { user: { id: ++user, name: 'Paula Manager', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null }, '/estate/phoenixpark/residents/new')

    const view = await mount(NewResident, {
        estate: { name: 'Phoenix Park' },
        phases,
        lot,
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

    return {
        view,
        field: view.one((el) => el.tag === 'input' && el.attrs.id === 'lot'),
        phase: view.one((el) => el.tag === 'select' && el.attrs.id === 'phase'),
        name: view.one((el) => el.tag === 'input' && el.attrs.id === 'full_name'),
        preview: () => textOf(view.one(byClass('preview-note'))),
        suggestions: () => view.all((el) => el.tag === 'option' && el.parent?.tag === 'datalist').map((el) => el.attrs.value),
        notes: () => view.all(byClass('new-resident-note')).map((el) => textOf(el)),
    }
}

const lookups = () => visits.filter((visit) => visit.method === 'reload')

/*
 * Answer one lookup as the server and Inertia do: the answer is the register's reading of
 * the fields AFTER Laravel's TrimStrings, it carries back the number the lookup was sent
 * with, the page's props are replaced, and only then is the visit's onSuccess called.
 */
const reply = async (lookup, page, overrides = {}) => {
    const number = lookup.options.headers?.['X-Lot-Question']

    page.view.props.lot = answer({ question: number === undefined ? null : Number(number), ...overrides })
    lookup.options.onSuccess?.()
    await nextTick()
}

/** Choose a phase as a browser would: mark its option selected, then the select changes. */
const choosePhase = async (page, value) => {
    for (const option of page.phase.options) {
        option.selected = option.value === value
    }

    fire(page.phase, 'change')
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

describe('M11 A2 — the unit field asks the register rather than carrying the estate', () => {
    it('opens on the answer it was sent, and asks nothing until something changes', async () => {
        const page = await open()

        mock.timers.tick(1000)

        assert.equal(lookups().length, 0, 'an answer that matches the fields is not asked again')
        assert.deepEqual(page.suggestions(), ['Lot 1', 'Lot 2', 'Lot 3'])
        assert.equal(page.field.attrs.placeholder, 'e.g. Lot 1', 'the phase\'s own first unit shows how the estate writes one, as an example (batch 1 item 3)')

        page.view.unmount()
    })

    it('asks once, a pause after the typing stops, for the chosen phase and what is typed — and only for `lot`', async () => {
        const page = await open()

        await page.view.type(page.field, '4')
        mock.timers.tick(100)
        await page.view.type(page.field, '47')

        assert.equal(lookups().length, 0, 'not per keystroke')

        mock.timers.tick(250)

        assert.equal(lookups().length, 1)
        assert.deepEqual(lookups()[0].options.only, ['lot'])
        assert.deepEqual(lookups()[0].options.data, { phase: 'Phase 2', lot: '47' })

        page.view.unmount()
    })

    it('names the unit the register named, and nothing before it has answered', async () => {
        const page = await open()

        await page.view.type(page.name, 'Simone Barrett')
        await page.view.type(page.field, '47')

        assert.match(page.preview(), /at their unit as/, 'no answer yet: no address is promised')

        mock.timers.tick(250)
        page.view.props.lot = answer({ typed: '47', resolved: 'Lot 47', suggestions: ['Lot 47', 'Lot 147', 'Lot 247'], more: false })
        lookups()[0].options.onSuccess?.()
        await nextTick()

        assert.match(page.preview(), /Simone is put on the register at Lot 47 as/)
        assert.deepEqual(page.suggestions(), ['Lot 47', 'Lot 147', 'Lot 247'])
        assert.deepEqual(page.notes(), [])

        page.view.unmount()
    })

    it('sets a late answer to an earlier keystroke aside, and asks again for what is typed now', async () => {
        const page = await open()

        await page.view.type(page.field, '4')
        mock.timers.tick(250)
        await page.view.type(page.field, '47')

        // The answer to "4" lands after "47" was typed.
        page.view.props.lot = answer({ typed: '4', resolved: 'Lot 4', suggestions: ['Lot 4', 'Lot 14'] })
        lookups()[0].options.onSuccess?.()
        await nextTick()

        assert.match(page.preview(), /at their unit as/, 'Lot 4 is not the answer to "47"')

        mock.timers.tick(250)

        assert.deepEqual(lookups().at(-1).options.data, { phase: 'Phase 2', lot: '47' }, 'asked again for what the field says')

        page.view.unmount()
    })

    it('says a unit is not on the phase\'s list only once the register has said so, and asks for more of it', async () => {
        const page = await open()

        await page.view.type(page.field, 'Lot 9')

        assert.deepEqual(page.notes(), [], 'nothing is refused before the register answers')

        mock.timers.tick(250)
        page.view.props.lot = answer({ typed: 'Lot 9', resolved: null, suggestions: ['Lot 90', 'Lot 91'], more: true })
        await nextTick()

        assert.deepEqual(page.notes(), [
            'No unit “Lot 9” is on Phase 2’s list. Type the unit as the estate writes it.',
            'The first 2 matches are suggested. Type more of the unit to narrow them.',
        ])

        page.view.unmount()
    })

    it('asks about the new phase when the phase changes, and suggests nothing from the old one', async () => {
        const page = await open()

        // Choose Phase 1 as a browser would: mark its option selected, then the select changes.
        for (const option of page.phase.options) {
            option.selected = option.value === 'Phase 1'
        }

        fire(page.phase, 'change')
        await nextTick()

        assert.deepEqual(page.suggestions(), [], 'Phase 2\'s units are not offered for Phase 1')

        mock.timers.tick(250)

        assert.deepEqual(lookups().at(-1).options.data, { phase: 'Phase 1', lot: undefined })

        page.view.unmount()
    })
})

/*
 * The fix-up of M11 A2's unit field: the page told an answer was current by comparing the
 * server's `typed` with its own `form.lot.trim()`. Laravel's TrimStrings strips characters
 * off the ends that JavaScript's trim() keeps, so a lot pasted with one never matched: the
 * page asked again every 250 ms for as long as it was open, and never named the unit.
 */
describe('M11 A2 — an answer is matched to its own lookup, and a mismatch can never loop', () => {
    // Str::INVISIBLE_CHARACTERS that JavaScript's trim() does not remove.
    const invisible = {
        'a zero-width space': '​',
        'a left-to-right mark': '‎',
        'a soft hyphen': '­',
        'a word joiner': '⁠',
    }

    for (const [name, character] of Object.entries(invisible)) {
        for (const [where, typed] of [['after', `Lot 47${character}`], ['before', `${character}Lot 47`]]) {
            it(`names the unit for a lot pasted with ${name} ${where} it, and asks once`, async () => {
                const page = await open()

                assert.notEqual(typed.trim(), 'Lot 47', 'JavaScript\'s trim() keeps it — the premise of this test')

                await page.view.type(page.name, 'Simone Barrett')
                await page.view.type(page.field, typed)
                mock.timers.tick(250)

                assert.equal(lookups().length, 1)
                assert.deepEqual(lookups()[0].options.data, { phase: 'Phase 2', lot: typed }, 'the field as typed; the server trims it')

                // The server read "Lot 47" — TrimStrings took the character off — and resolved it.
                await reply(lookups()[0], page, { typed: 'Lot 47', resolved: 'Lot 47', suggestions: ['Lot 47'], more: false })

                mock.timers.tick(10_000)
                await nextTick()

                assert.equal(lookups().length, 1, 'the answer to the lookup it sent is current: nothing is asked again')
                assert.match(page.preview(), /Simone is put on the register at Lot 47 as/)
                assert.deepEqual(page.notes(), [])

                page.view.unmount()
            })
        }
    }

    it('quotes a unit the register does not have as the register read it, without the invisible character', async () => {
        const page = await open()

        await page.view.type(page.field, 'Lot 9​')
        mock.timers.tick(250)
        await reply(lookups()[0], page, { typed: 'Lot 9', resolved: null, suggestions: ['Lot 90'], more: false })

        mock.timers.tick(10_000)

        assert.equal(lookups().length, 1)
        assert.deepEqual(page.notes(), ['No unit “Lot 9” is on Phase 2’s list. Type the unit as the estate writes it.'])

        page.view.unmount()
    })

    it('keeps the answer for a phase whose name the server trims, and suggests its units', async () => {
        const page = await open(answer(), [...PHASES, { value: ' Phase 3 ', label: 'Phase 3' }])

        await choosePhase(page, ' Phase 3 ')
        mock.timers.tick(250)

        assert.equal(lookups().length, 1)
        assert.deepEqual(lookups()[0].options.data, { phase: ' Phase 3 ', lot: undefined })

        // TrimStrings hands the server "Phase 3", and its answer names that.
        await reply(lookups()[0], page, { phase: 'Phase 3', suggestions: ['Lot 3001', 'Lot 3002'], more: false, placeholder: 'Lot 3001' })

        mock.timers.tick(10_000)

        assert.equal(lookups().length, 1, 'not asked again')
        assert.deepEqual(page.suggestions(), ['Lot 3001', 'Lot 3002'])
        assert.equal(page.field.attrs.placeholder, 'e.g. Lot 3001')

        page.view.unmount()
    })

    it('sets aside an older answer that lands after the newer one, and asks for what is typed', async () => {
        const page = await open()

        await page.view.type(page.name, 'Simone Barrett')
        await page.view.type(page.field, '4')
        mock.timers.tick(250)
        await page.view.type(page.field, '47')
        mock.timers.tick(250)

        const [toFour, toFortySeven] = lookups()

        assert.equal(lookups().length, 2)

        await reply(toFortySeven, page, { typed: '47', resolved: 'Lot 47' })
        assert.match(page.preview(), /at Lot 47 as/)

        // The answer to "4" comes back last and replaces the page's props.
        await reply(toFour, page, { typed: '4', resolved: 'Lot 4' })

        assert.match(page.preview(), /at their unit as/, 'Lot 4 is not the answer to "47", whichever order they came in')

        mock.timers.tick(250)

        assert.equal(lookups().length, 3)
        assert.deepEqual(lookups()[2].options.data, { phase: 'Phase 2', lot: '47' })

        await reply(lookups()[2], page, { typed: '47', resolved: 'Lot 47' })
        mock.timers.tick(10_000)

        assert.match(page.preview(), /at Lot 47 as/)
        assert.equal(lookups().length, 3)

        page.view.unmount()
    })

    it('asks at most three times for fields that have not changed, whatever the answers say', async () => {
        const page = await open()

        await page.view.type(page.field, '47')

        // Answers the page cannot place: no number of its own, about something else typed.
        for (let i = 0; i < 12; i++) {
            mock.timers.tick(250)
            await reply(lookups().at(-1), page, { question: null, typed: 'Lot 470', resolved: 'Lot 470' })
        }

        mock.timers.tick(10_000)

        assert.equal(lookups().length, 3)
        assert.ok(lookups().every((lookup) => lookup.options.data.lot === '47'))
        assert.doesNotMatch(page.preview(), /Lot 470/, 'an answer about other fields is never shown')

        // Typing again is new fields, and they are asked about.
        await page.view.type(page.field, '48')
        mock.timers.tick(250)

        assert.equal(lookups().length, 4)
        assert.equal(lookups()[3].options.data.lot, '48')

        page.view.unmount()
    })
})

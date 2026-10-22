/**
 * Add resident opens in the console's modal over the register (`Pages/Estate/Residents/Index.vue`; Hassam, 11 Oct, from
 * the client's clicking: "Add resident opens the form inline on the page. It must open in a modal.").
 *
 *   The press opens the modal and asks the register for the form's board (a partial reload of `addResident`), never a
 *   page of its own; the form is the one `/residents/new` draws; the unit is asked about under `add_phase` and `add_lot`,
 *   so the register's own phase filter is untouched; a save posts to the register and keeps the modal when it is
 *   refused, each refusal under its field; the occupied lot's question greys Add resident with why on the screen; a role
 *   that can never add a resident has no Add resident; `?add=1` opens it at once.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: Index } = await import('../../resources/js/Pages/Estate/Residents/Index.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const TABLE = { q: '', total: 1, page: 1, per: 25, last: 1, from: 1, to: 1, sort: null, dir: null, default: { sort: 'lot', dir: 'asc' }, sortable: [] }

const PERSON = {
    id: 1, name: 'Marcia Bennett', initials: 'MB', email: 'marcia@example.com', unit: 'Phase 1 · Lot 12', unit_slug: 'lot-12', household_param: null,
    household: 'Bennett', role: 'primary', role_label: 'Primary', state: 'active', state_label: 'Active', added: 'Oct 1, 2026', sample: false,
}

const BOARD = (lot = {}) => ({
    phases: [{ value: 'Phase 1', label: 'Phase 1' }, { value: 'Phase 2', label: 'Phase 2' }],
    lot: { phase: 'Phase 1', typed: '', resolved: null, suggestions: [], more: false, placeholder: 'Lot 1', question: null, ...lot },
    verification: [{ value: 'invite', label: 'They verify in the app' }, { value: 'manual', label: 'I vouch for them' }],
    default_verification: 'invite',
    household_choices: [{ value: 'member', label: 'A member of this household' }, { value: 'separate', label: 'A new, separate household at this lot' }],
    biometric_consent: { default: false, label: 'They agree to fingerprint entry', note: 'Only the person can agree to it.' },
    preview: { head: 'What happens next', template: '{first} is filed at {lot}.' },
    canCreate: true,
    blockedReason: 'Adding a resident is not part of your role.',
})

const OCCUPIED = {
    unit: 'Lot 12',
    households: [{ id: 7, name: 'Bennett household', primary: { name: 'Marcia Bennett', email: 'marcia@example.com' }, others: 1, sample: false }],
    balance_line: 'This household\'s balance is not shown to your role.',
}

const open = async ({ url = '/residents', ...props } = {}) => {
    resetInertia({ auth: { user: { id: 99401, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {}, phoneExample: { local: '876 555 0147' } }, url)

    return mount(Index, {
        estate: { name: 'Phoenix Park Village 1' },
        view: 'people',
        pending: { count: 0, title: '', body: '' },
        phase_chips: [],
        filters: { phase: '', search: '' },
        rows: [PERSON],
        total: 1,
        shown: 1,
        table: TABLE,
        money_visible: false,
        money_reason: null,
        canCreate: true,
        canReview: true,
        blockedReason: 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',
        inviteStates: {},
        deletedAccounts: 0,
        ...props,
    })
}

const addButton = (view) => view.all((el) => el.tag === 'button' && textOf(el) === 'Add resident' && !byClass('cd-confirm')(el))[0] ?? null
const dialog = (view) => view.all((el) => el.attrs?.role === 'dialog').find((d) => textOf(findAll(d, byClass('cd-title'))[0] ?? d) === 'Add a resident') ?? null
const inDialog = (view, predicate) => findAll(dialog(view), predicate)[0] ?? null
const field = (view, id) => inDialog(view, (el) => el.attrs?.id === id)
const reloads = () => visits.filter((visit) => visit.method === 'reload')
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

afterEach(() => resetToasts())

describe('Add resident opens in the console\'s modal over the register', () => {
    it('opens the modal and asks for the form, never a page of its own', async () => {
        const view = await open()

        try {
            assert.ok(addButton(view) !== null, 'Add resident is drawn for a role that may add one')
            assert.equal(view.all((el) => el.tag === 'a' && /\/residents\/new/.test(el.attrs?.href ?? '')).length, 0, 'no link to the page')

            await view.click(addButton(view))

            assert.ok(dialog(view) !== null, 'the modal opens')
            assert.ok(view.one(byClass('cd-form')) !== null, 'the form size: wider, full screen on a phone')
            assert.deepEqual(reloads().at(-1)?.options.only, ['addResident'], 'the board is asked for, and nothing else')
            assert.equal(visits.filter((visit) => ['get', 'visit'].includes(visit.method)).length, 0, 'no visit to another page')
            assert.equal(textOf(inDialog(view, byClass('add-resident-wait'))), 'Loading the form…')
            assert.equal(textOf(inDialog(view, byClass('cd-why'))), 'The form is still loading.', 'why Add resident waits, on the screen')
        } finally {
            view.unmount()
        }
    })

    it('draws the same form once its board arrives, and asks about the unit under its own names', async () => {
        const view = await open()

        try {
            await view.click(addButton(view))
            view.props.addResident = BOARD()
            await nextTick()

            for (const id of ['phase', 'lot', 'full_name', 'email', 'phone']) {
                assert.ok(field(view, id) !== null, `the ${id} field is in the modal`)
            }

            assert.ok(textOf(dialog(view)).includes('What happens next'), 'the board\'s preview line is under the fields')

            const before = reloads().length
            await view.type(field(view, 'lot'), 'Lot 12')
            await pause(320)

            const lookup = reloads().at(-1)
            assert.equal(reloads().length, before + 1, 'one lookup, a pause after the typing stops')
            assert.deepEqual(lookup.options.only, ['addResident'])
            assert.deepEqual(lookup.options.data, { add_phase: 'Phase 1', add_lot: 'Lot 12' }, 'never the register\'s own phase filter')
        } finally {
            view.unmount()
        }
    })

    it('posts to the register, keeps the modal when the save is refused, and says the refusal under its field', async () => {
        const view = await open()

        try {
            await view.click(addButton(view))
            view.props.addResident = BOARD()
            await nextTick()

            // Everything the server asks of a person, so the save can be pressed (Law 1: it waits until then).
            await view.type(field(view, 'lot'), 'Lot 12')
            await view.type(field(view, 'full_name'), 'Owen Clarke')
            await view.type(field(view, 'phone'), '876 555 0147')
            await view.type(field(view, 'email'), 'owen@example.com')
            await view.click(inDialog(view, byClass('cd-confirm')))

            const save = visits.filter((visit) => visit.method === 'post').at(-1)
            assert.match(save.url, /\/residents$/)
            assert.equal(save.data.full_name, 'Owen Clarke')
            // The router's posts keep the page's state unless told otherwise (@inertiajs/core 3.7.0), so nothing may turn it off.
            assert.notEqual(save.options.preserveState, false, 'a refusal keeps the modal and what was typed')

            save.options.onError({ lot: 'There is no unit "Lot 999" in Phase 1.' })
            await nextTick()

            assert.ok(dialog(view) !== null, 'the modal is still open')
            assert.equal(textOf(inDialog(view, byClass('new-resident-error'))), 'There is no unit "Lot 999" in Phase 1.')
        } finally {
            view.unmount()
        }
    })

    // Accounting's walk, 12 Oct: Add resident was live with nothing typed, and every press of it was refused (Law 1).
    it('greys Add resident until the form can be sent, saying what is still to be typed', async () => {
        const view = await open()

        try {
            await view.click(addButton(view))
            view.props.addResident = BOARD()
            await nextTick()

            assert.equal(inDialog(view, byClass('cd-confirm')).attrs.disabled, true, 'greyed with nothing typed')
            assert.equal(textOf(inDialog(view, byClass('cd-why'))), 'Enter the lot, their full name, a phone number and an email (or tick that they have none) first.')

            await view.type(field(view, 'full_name'), 'Owen Clarke')
            assert.equal(textOf(inDialog(view, byClass('cd-why'))), 'Enter the lot, a phone number and an email (or tick that they have none) first.')
        } finally {
            view.unmount()
        }
    })

    it('greys Add resident with why on the screen while the occupied lot\'s question waits', async () => {
        const view = await open()

        try {
            await view.click(addButton(view))
            view.props.addResident = BOARD({ typed: 'Lot 12', resolved: 'Lot 12', occupied: OCCUPIED })
            await nextTick()
            await view.type(field(view, 'lot'), 'Lot 12')

            assert.equal(inDialog(view, byClass('cd-confirm')).attrs.disabled, true)
            assert.equal(textOf(inDialog(view, byClass('cd-why'))), 'Lot 12 already has a household. Choose what this person is before saving.')
        } finally {
            view.unmount()
        }
    })

    it('has no Add resident for a role that can never add one, and opens at once on ?add=1', async () => {
        const none = await open({ canCreate: false })

        try {
            assert.ok(addButton(none) === null, 'level 3: no button at all')
        } finally {
            none.unmount()
        }

        const view = await open({ url: '/residents?add=1' })

        try {
            assert.ok(dialog(view) !== null, 'the dashboard\'s quick action can open the same modal')
            assert.deepEqual(reloads().at(-1)?.options.only, ['addResident'])
        } finally {
            view.unmount()
        }
    })
})

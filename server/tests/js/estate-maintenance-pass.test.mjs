/**
 * The maintenance queue, after the M97 pass (`Pages/Estate/Facilities/Maintenance.vue`).
 *
 *   New work order and a ticket's priority open the console's modal, never a form above the tiles or a ladder inside
 *   the table (M95 §1); the priority's modal starts at the ticket's own and its greyed Set priority says why; a refused
 *   priority is said in its open modal, not a toast and not a banner (Hassam, 12 Oct); where the priority can never be
 *   changed it is a fact, not a control,
 *   and a role that can never raise a work order or read the vendors has neither control (M96 level 3); every status
 *   badge carries its icon beside its word (M96).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Maintenance } = await import('../../resources/js/Pages/Estate/Facilities/Maintenance.vue')
const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')

const row = (id, over = {}) => ({
    id, number: 1000 + id, ticket: `#${1000 + id} · Gate light out`, title: 'Gate light out', location: 'Main gate', priority: 'medium',
    priority_label: 'Medium', status: 'submitted', status_label: 'Submitted', is_overdue: false, assignee: 'Unassigned', age: '1 day',
    unit: null, photos: 0, ...over,
})

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97005, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
    const view = await mount(Maintenance, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        kpis: [{ key: 'open', value: 2, label: 'Open tickets' }],
        filters: [{ key: 'all', label: 'All' }],
        filter: 'all',
        overdue: 0,
        rows: [row(42), row(43, { status: 'completed', status_label: 'Completed' })],
        table: { q: '', total: 2, page: 1, per: 20, last_page: 1, sort: 'number', dir: 'desc' },
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate: true, canCreate: true, canViewVendors: false, blockedReason: 'Changing a priority needs Facilities update.', createReason: '',
        reasons: { vendors: 'The vendor list needs Facilities vendors access.' }, units: [], unitQuery: '', unitMatchLimit: 20,
        categories: [{ key: 'electrical', label: 'Electrical' }], reportedWithinDays: 30,
        ...props,
    })
    await flush(nextTick)

    return view
}

const dotButton = (view, index) => view.all((el) => el.tag === 'button' && byClass('priority-dot')(el))[index] ?? null
const absent = (el, message) => assert.ok(el === null || el === undefined, message)

afterEach(() => resetToasts())

describe('The maintenance queue, after the M97 pass', () => {
    it('opens New work order in the console\'s modal, never as a form above the tiles', async () => {
        const view = await open()
        try {
            await view.click(view.all((el) => el.tag === 'button' && /New work order/.test(textOf(el)))[0])

            absent(view.one(byClass('wo-panel')), 'no form on the page')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Raise a work order')
            assert.ok(view.one(byClass('cd-form')), 'the form size')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Say what needs doing and where.')
        } finally {
            view.unmount()
        }
    })

    it('asks for a priority in the modal, starting at the ticket\'s own, and posts the one chosen', async () => {
        const view = await open()
        try {
            await view.click(dotButton(view, 0))

            absent(view.one(byClass('triage-panel')), 'no ladder inside the table')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Change the priority of #1042 · Gate light out?')
            assert.equal(textOf(view.one(byClass('cd-why'))), '#1042 · Gate light out is already medium priority. Choose another.')

            await view.click(view.one((el) => el.attrs?.role === 'radio' && textOf(el) === 'High'))
            absent(view.one(byClass('cd-why')), 'a new priority chosen, nothing stands in the way')
            await view.click(view.one(byClass('cd-confirm')))
            await nextTick()

            const post = visits.at(-1)
            assert.equal(post.url, '/facilities/maintenance/1042/priority')
            assert.equal(post.data.priority, 'high')
        } finally {
            view.unmount()
        }
    })

    // Hassam, 12 Oct: a refusal of an open dialog is said inside it, the dialog staying open; never a toast behind it.
    it('says a refused priority inside its open modal, never as a toast or a banner', async () => {
        const view = await open()
        try {
            await view.click(dotButton(view, 0))
            await view.click(view.one((el) => el.attrs?.role === 'radio' && textOf(el) === 'Low'))
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ priority: 'Ticket #1042 was closed a moment ago.' })
            await nextTick()

            absent(view.one(byClass('queue-refusal')), 'no banner')
            assert.ok(view.one(byClass('cd-panel')) !== null, 'the modal is still open')
            assert.equal(textOf(view.one(byClass('cd-error'))).trim(), 'Ticket #1042 was closed a moment ago.')
            assert.ok(!toasts.some((each) => each.tone === 'error'), 'no toast while the modal is open')
        } finally {
            view.unmount()
        }
    })

    it('draws a priority that can never be changed as a fact, and no control a role can never use', async () => {
        const office = await open()
        try {
            assert.equal(office.all((el) => el.tag === 'button' && byClass('priority-dot')(el)).length, 1, 'the closed ticket\'s priority is not a control')
            absent(office.one((el) => el.tag === 'button' && textOf(el).trim() === 'Vendors'), 'no Vendors tab without the vendor list')
        } finally {
            office.unmount()
        }

        const reader = await open({ canUpdate: false, canCreate: false })
        try {
            assert.equal(reader.all((el) => el.tag === 'button' && byClass('priority-dot')(el)).length, 0, 'no priority control at all')
            assert.equal(reader.all((el) => el.tag === 'span' && byClass('priority-dot')(el)).length, 2, 'each priority still said')
            absent(reader.all((el) => el.tag === 'button' && /New work order/.test(textOf(el)))[0], 'no New work order')
        } finally {
            reader.unmount()
        }
    })

    it('draws every status with its icon beside its word', async () => {
        const view = await open()
        try {
            const badges = view.all(byClass('mq-badge'))
            assert.equal(badges.length, 2)
            for (const badge of badges) {
                assert.ok(badge.children.some((el) => el.tag === 'svg'), `${textOf(badge)} has its icon`)
            }
        } finally {
            view.unmount()
        }
    })
})

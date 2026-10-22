/**
 * The queue's Status column names the state, and Overdue sits beside it — `Pages/Estate/Facilities/Maintenance.vue`
 * (M68 §2.3, FAC-3).
 *
 *   Overdue used to be a status of its own, so an overdue ticket read "Overdue" and nobody could tell whether anybody
 *   had started it. The server now sends the state ("Submitted", "Assigned") and the overdue flag apart, and the cell
 *   draws both.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Maintenance } = await import('../../resources/js/Pages/Estate/Facilities/Maintenance.vue')

const ROW = {
    id: 70, number: 1070, ticket: '#1070 — Drain blocked', title: 'Drain blocked', location: 'Lot 12', priority: 'high', priority_label: 'High',
    status: 'submitted', status_label: 'Submitted', is_overdue: true, assignee: 'Unassigned', age: '1 day', unit: 'Lot 12',
}

export const openQueue = async (props = {}) => {
    resetInertia({ auth: { user: { id: 68301, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
    const view = await mount(Maintenance, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        kpis: [
            { key: 'open', value: 16, label: 'Open tickets' },
            { key: 'in_progress', value: 11, label: 'In progress' },
            { key: 'overdue', value: 17, label: 'Overdue' },
            { key: 'avg_resolution', value: 3.2, label: 'Avg resolution' },
        ],
        filters: [{ key: 'all', label: 'All' }, { key: 'open', label: 'Open' }, { key: 'in_progress', label: 'In progress' }, { key: 'completed', label: 'Completed' }],
        filter: 'all',
        overdue: 17,
        rows: [ROW, { ...ROW, id: 71, number: 1071, status: 'progress', status_label: 'Assigned', is_overdue: false, assignee: 'Island Electric Services' }],
        table: { q: '', total: 2, page: 1, per: 20, last_page: 1, sort: 'number', dir: 'desc' },
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate: true,
        canCreate: true,
        canViewVendors: false,
        blockedReason: '',
        createReason: '',
        reasons: {},
        units: [],
        categories: ['Electrical', 'Plumbing'],
        ...props,
    })
    await flush(nextTick)

    return view
}

describe('the overdue badge is a filter (FAC-1)', () => {
    // It was a <div> styled like the chips beside it, and pressing it did nothing.
    const badge = (view) => view.one((el) => byClass('mq-overdue')(el))

    it('is a link to the overdue tickets, keeping the search', async () => {
        const view = await openQueue({ table: { q: 'drain', total: 2, page: 1, per: 20, last_page: 1, sort: 'number', dir: 'desc' } })

        assert.ok(badge(view), 'the badge is drawn')
        assert.equal(badge(view).tag, 'a', 'a link, not a box')
        assert.match(badge(view).attrs.href, /\/facilities\/maintenance\?filter=overdue&q=drain/)
        assert.ok(!byClass('active')(badge(view)))
    })

    it('shows that it is on, and pressing it again shows every ticket', async () => {
        const view = await openQueue({ filter: 'overdue' })

        assert.ok(byClass('active')(badge(view)), 'drawn as the chip that is on')
        assert.doesNotMatch(badge(view).attrs.href, /filter=/, 'its link clears the filter')
        assert.equal(badge(view).attrs['aria-label'], 'Show every ticket again')
        assert.ok(!view.all((el) => byClass('f-chip')(el) && byClass('active')(el) && textOf(el).trim() === 'All').length, 'All is not on')
    })
})

describe('the Status column (FAC-3)', () => {
    it('draws the state, and Overdue beside it, never instead of it', async () => {
        const view = await openQueue()
        const cells = view.all(byClass('mq-status'))

        assert.equal(cells.length, 2, 'one status cell per row')
        const late = findAll(cells[0], byClass('status-badge')).map((badge) => textOf(badge))
        assert.deepEqual(late, ['Submitted', 'Overdue'])

        const onTime = findAll(cells[1], byClass('status-badge')).map((badge) => textOf(badge))
        assert.deepEqual(onTime, ['Assigned'])
    })
})

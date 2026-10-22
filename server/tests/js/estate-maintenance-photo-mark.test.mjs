/**
 * The queue marks a ticket that carries the resident's photos (FAC-5, register DR-079).
 *
 *   `Pages/Estate/Facilities/Maintenance.vue`: eight tickets carried a photo and the list showed nothing of it. A row
 *   with photos now carries a small camera mark beside the ticket, saying how many in one line; a row with none has no
 *   mark at all.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Maintenance } = await import('../../resources/js/Pages/Estate/Facilities/Maintenance.vue')

const row = (id, photos) => ({
    id, number: 1000 + id, ticket: `#${1000 + id} · Wall cracked`, title: 'Wall cracked', location: 'Lot 12', priority: 'medium',
    priority_label: 'Medium', status: 'submitted', status_label: 'Submitted', is_overdue: false, assignee: 'Unassigned', age: '1 day',
    unit: 'Lot 12', photos,
})

describe('the queue marks a ticket with photos (FAC-5)', () => {
    it('draws a camera mark saying how many on a row with photos, and none on a row without', async () => {
        resetInertia({ auth: { user: { id: 91107, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
        const view = await mount(Maintenance, {
            sourceBadge: { source: 'resident', simulated: false },
            estate: { name: 'Phoenix Park' },
            kpis: [{ key: 'open', value: 3, label: 'Open tickets' }],
            filters: [{ key: 'all', label: 'All' }],
            filter: 'all',
            overdue: 0,
            rows: [row(1, 2), row(2, 0), row(3, 1)],
            table: { q: '', total: 3, page: 1, per: 20, last_page: 1, sort: 'number', dir: 'desc' },
            priorities: { high: 'High', medium: 'Medium', low: 'Low' },
            canUpdate: true, canCreate: true, canViewVendors: false, blockedReason: '', createReason: '', reasons: {}, units: [],
            categories: ['Electrical'],
        })
        await flush(nextTick)
        try {
            const marks = view.all((el) => byClass('mq-photo')(el))

            assert.deepEqual(marks.map((mark) => mark.attrs.title), ['2 photos from the resident', '1 photo from the resident'])
            assert.ok(marks.every((mark) => findAll(mark, (el) => el.tag === 'svg').length === 1), 'a camera, drawn')
        } finally {
            view.unmount()
        }
    })
})

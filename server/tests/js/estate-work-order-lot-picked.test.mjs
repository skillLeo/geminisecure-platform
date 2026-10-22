/**
 * The lot on a new work order is one of the matches, or none (FAC-13, register DR-085).
 *
 *   `Pages/Estate/Facilities/Maintenance.vue`: a lot typed in the search and never chosen from the list raised a work
 *   order against no unit, with no word said. Now a typed lot with nothing chosen turns Raise off, the reason under
 *   the field; choosing the lot, or clearing the search for a job that is not a household's, turns it back on.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Maintenance } = await import('../../resources/js/Pages/Estate/Facilities/Maintenance.vue')

const PROPS = {
    estate: { name: 'Phoenix Park' },
    kpis: [], filters: [], filter: 'all', overdue: { count: 0 }, rows: [],
    table: { prefix: '', sort: null, dir: null, default: { sort: 'number', dir: 'desc', per: 25 }, sortable: [], page: 1, last: 1, per: 25, sizes: [25, 50, 100], from: null, to: null, total: 0, q: '' },
    sourceBadge: {}, priorities: { high: 'High', medium: 'Medium', low: 'Low' }, canUpdate: true, canCreate: true, canViewVendors: true,
    blockedReason: '', createReason: '', reasons: {}, units: [], unitQuery: '', unitMatchLimit: 20, categories: ['Plumbing', 'Electrical', 'Other'],
}

const MATCHES = [{ id: 47, label: 'Lot 47 · Phase 1' }, { id: 147, label: 'Lot 147 · Phase 1' }, { id: 1047, label: 'Lot 1047 · Phase 3' }]

const REASON = /Choose the lot from the list, or clear the search/

const open = async () => {
    resetInertia({ auth: { user: { id: 91103, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
    const view = await mount(Maintenance, PROPS)
    await flush(nextTick)
    await view.click(view.all((el) => el.tag === 'button' && /New work order/.test(textOf(el)))[0])

    const input = (id) => view.one((el) => el.attrs?.id === id)
    // Raise is the console's modal's own button since the M97 pass (M95 §1).
    const raise = () => view.one((el) => el.tag === 'button' && byClass('cd-confirm')(el) && /Raise work order/.test(textOf(el)))

    await view.type(input('wo-title'), 'Gate light out')
    await view.type(input('wo-location'), 'Gate')

    // The lot typed and searched: the server answers with three, none of them chosen for the office.
    visits.length = 0
    await view.type(input('wo-unit-search'), '47')
    fire(input('wo-unit-search'), 'change')
    await nextTick()
    const reload = visits.find((v) => v.method === 'reload')
    view.props.units = MATCHES
    view.props.unitQuery = '47'
    reload.options.onSuccess?.()
    await nextTick()

    return { view, input, raise }
}

describe('the lot on a new work order (FAC-13)', () => {
    it('turns Raise off while a typed lot is not chosen, the reason under the field', async () => {
        const { view, input, raise } = await open()
        try {
            assert.equal(input('wo-unit').value, '', 'nothing is chosen for the office among three')
            assert.equal(raise().attrs.disabled, true, 'Raise is off')
            assert.match(String(raise().attrs.title), REASON, 'and says why in one line')
            assert.match(textOf(input('wo-unit').parent), REASON, 'the reason sits under the field')

            const select = input('wo-unit')
            for (const option of select.options) {
                option.selected = String(option.value) === '47'
            }
            fire(select, 'change')
            await nextTick()

            assert.ok(!raise().attrs.disabled, 'the lot chosen, Raise is on')
        } finally {
            view.unmount()
        }
    })

    it('turns Raise back on when the search is cleared, for a job that is not a household\'s', async () => {
        const { view, input, raise } = await open()
        try {
            await view.type(input('wo-unit-search'), '')

            assert.ok(!raise().attrs.disabled, 'no lot typed, none needed')
        } finally {
            view.unmount()
        }
    })

    it('posts nothing on a press while a typed lot is not chosen', async () => {
        const { view, raise } = await open()
        try {
            visits.length = 0
            await view.click(raise())
            await nextTick()

            assert.equal(visits.filter((v) => v.method === 'post').length, 0)
        } finally {
            view.unmount()
        }
    })
})

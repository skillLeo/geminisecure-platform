/**
 * "Reported at" goes back a month, and no further (FAC-12, register DR-084).
 *
 *   `Pages/Estate/Facilities/Maintenance.vue`: the field took 1 January 2020. It now carries the server's bound as its
 *   own `min`, and a time further back turns Raise off with the reason under the field, as a time still to come does.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Maintenance } = await import('../../resources/js/Pages/Estate/Facilities/Maintenance.vue')

const PROPS = {
    estate: { name: 'Phoenix Park' },
    kpis: [], filters: [], filter: 'all', overdue: { count: 0 }, rows: [],
    table: { prefix: '', sort: null, dir: null, default: { sort: 'number', dir: 'desc', per: 25 }, sortable: [], page: 1, last: 1, per: 25, sizes: [25, 50, 100], from: null, to: null, total: 0, q: '' },
    sourceBadge: {}, priorities: { high: 'High', medium: 'Medium', low: 'Low' }, canUpdate: true, canCreate: true, canViewVendors: true,
    blockedReason: '', createReason: '', reasons: {}, units: [], unitQuery: '', unitMatchLimit: 20, reportedWithinDays: 30,
    categories: ['Plumbing', 'Electrical', 'Other'],
}

const local = (date) => {
    const pad = (n) => String(n).padStart(2, '0')

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const DAY = 24 * 60 * 60 * 1000

describe('"Reported at" goes back a month (FAC-12)', () => {
    it('carries the bound as its min, and turns Raise off with the reason for a time further back', async () => {
        resetInertia({ auth: { user: { id: 91105, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
        const view = await mount(Maintenance, PROPS)
        await flush(nextTick)
        try {
            await view.click(view.all((el) => el.tag === 'button' && /New work order/.test(textOf(el)))[0])
            const input = (id) => view.one((el) => el.attrs?.id === id)
            // Raise is the console's modal's own button since the M97 pass (M95 §1).
            const raise = () => view.one((el) => el.tag === 'button' && (el.attrs.class ?? '').split(/\s+/).includes('cd-confirm') && /Raise work order/.test(textOf(el)))

            await view.type(input('wo-title'), 'Standpipe running')
            await view.type(input('wo-location'), 'Back gate')

            assert.equal(input('wo-reported').attrs.min, local(new Date(Date.now() - 30 * DAY)).slice(0, 10) + 'T00:00', 'the field starts thirty days back')

            await view.type(input('wo-reported'), '2020-01-01T10:00')

            assert.equal(raise().attrs.disabled, true, 'Raise is off')
            assert.match(String(raise().attrs.title), /within 30 days/, 'and says why in one line')
            assert.match(textOf(input('wo-reported').parent), /within 30 days/, 'the reason sits under the field')

            await view.type(input('wo-reported'), local(new Date(Date.now() - 2 * DAY)))

            assert.ok(!raise().attrs.disabled, 'two days back is the point of the field')
        } finally {
            view.unmount()
        }
    })
})

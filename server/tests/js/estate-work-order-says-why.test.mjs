/**
 * The new work order panel says why, under the field, and never closes on a refusal (FAC-11, register DR-083).
 *
 *   `Pages/Estate/Facilities/Maintenance.vue`: a "Reported at" later than now and a refusal from the server closed the
 *   panel and created nothing, with no error, the button live throughout. Now a time still to come turns Raise off
 *   with the reason under the field, and a refusal keeps the panel and what was typed (`preserveState`), its words under
 *   the field they are about.
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
    blockedReason: '', createReason: '', reasons: {}, units: [], unitQuery: '', unitMatchLimit: 8, categories: ['Plumbing', 'Electrical', 'Other'],
}

/** "2026-10-11T09:00" for a moment, in the browser's own clock, as a datetime-local field writes it. */
const local = (date) => {
    const pad = (n) => String(n).padStart(2, '0')

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const open = async () => {
    resetInertia({ auth: { user: { id: 91101, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance')
    const view = await mount(Maintenance, PROPS)
    await flush(nextTick)
    await view.click(view.all((el) => el.tag === 'button' && /New work order/.test(textOf(el)))[0])

    const input = (id) => view.one((el) => el.attrs?.id === id)
    // Raise is the console's modal's own button since the M97 pass (M95 §1).
    const raise = () => view.one((el) => el.tag === 'button' && byClass('cd-confirm')(el) && /Raise work order/.test(textOf(el)))
    const fieldOf = (id) => input(id)?.parent ?? null

    await view.type(input('wo-title'), 'Gate lighting')
    await view.type(input('wo-location'), 'Phase 2 visitor parking')

    return { view, input, raise, fieldOf }
}

describe('the new work order panel says why (FAC-11)', () => {
    it('turns Raise off for a time still to come, with the reason under the field', async () => {
        const { view, input, raise, fieldOf } = await open()
        try {
            await view.type(input('wo-reported'), local(new Date(Date.now() + 2 * 60 * 60 * 1000)))

            assert.equal(raise().attrs.disabled, true, 'Raise is off')
            assert.match(String(raise().attrs.title), /later than now/i, 'and says why in one line')
            assert.match(textOf(fieldOf('wo-reported')), /later than now/i, 'the reason sits under the field')
        } finally {
            view.unmount()
        }
    })

    it('keeps the panel and what was typed when the server refuses, its words under the field they are about', async () => {
        const { view, input, raise, fieldOf } = await open()
        try {
            visits.length = 0
            await view.click(raise())
            await nextTick()

            const post = visits.find((v) => v.method === 'post')
            assert.ok(post, 'the work order is posted')
            // The router's posts keep the page's state unless told otherwise (@inertiajs/core 3.7.0); the page stopped
            // saying so itself in the M98 pass, so nothing may turn it off (the test asked for an explicit true until 12 Oct).
            assert.notEqual(post.options.preserveState, false, 'a refusal keeps the panel open and the fields filled')

            post.options.onError({ reported_at: 'A ticket cannot be reported in the future.' })
            await nextTick()

            assert.ok(view.one((el) => el.attrs?.role === 'dialog'), 'the form is still open')
            assert.equal(input('wo-title').value, 'Gate lighting', 'what was typed is still there')
            assert.match(textOf(fieldOf('wo-reported')), /cannot be reported in the future/, 'the refusal sits under its field')
        } finally {
            view.unmount()
        }
    })
})

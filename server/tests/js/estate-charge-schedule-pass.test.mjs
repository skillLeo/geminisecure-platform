/**
 * The charge schedule, after the M97 pass (`Pages/Estate/Dues/ChargeSchedule.vue`).
 *
 *   New schedule and Change rate open the console's modal (M95 §1), the rate read back in words, with no arrow and no
 *   em dash; Stop schedule asks first, where it stopped the estate's billing on one click; a role that cannot set what
 *   households are billed has no New schedule, Change rate or Stop (M96 level 3); a stopped schedule reads Closed (M96
 *   §2; Inactive since Hassam's records words, 11 October); Reverse is not there without the permission, and greyed with
 *   why on a month that is not the latest (level 2). Elements are compared as booleans: a failing assert on a rendered
 *   element serialises the whole Vue tree.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: ChargeSchedule } = await import('../../resources/js/Pages/Estate/Dues/ChargeSchedule.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const SCHEDULE = (over = {}) => ({
    id: 1, description: 'Maintenance fee', amount: '$6,200.00', scope: 'Whole estate', due_day: 1, account: '4000 Maintenance Fee Income',
    is_active: true, next_label: 'November 2026', units: 1999, pending_rates: [], blocked: null,
    rate_options: [{ period: '2026-11', label: 'November 2026', current: 'J$6,200.00', current_minor: 620000, effect: 'From November 2026 on.' }],
    rate_input: '6200.00',
    preview: { period: '2026-11', label: 'November 2026', count: 1999, rate: '$6,200.00', total: '$12,393,800.00', total_minor: 1_239_380_000, due_label: 'Nov 1, 2026' },
    ...over,
})

const RUN = (over = {}) => ({ id: 5, period: 'October 2026', schedule: 'Maintenance fee', due_on: 'Oct 1, 2026', units: 1999, total: '$12,393,800.00', journal_ref: 'JE-1', posted: 'Oct 1, 2026', reversed: false, reversible: true, reverse_blocked: null, reversal: null, ...over })

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97006, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/finance/charge-schedule')

    const view = await mount(ChargeSchedule, {
        estate: { name: 'Phoenix Park Village 1' },
        schedules: [SCHEDULE()],
        runs: [RUN()],
        table: { sort: null, dir: null, default: { sort: 'period', dir: 'desc' }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: 1, to: 1, total: 1, q: null },
        incomeAccounts: [{ code: '4000', label: '4000 · Maintenance Fee Income' }],
        phases: ['Phase 1'],
        canPost: true,
        canSetRates: true,
        canReverse: true,
        reasons: { post: '', rate: '', schedule: '', reverse: '' },
        ...props,
    })
    await nextTick()

    return view
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).trim() === words) ?? null

afterEach(() => resetToasts())

describe('the charge schedule, after the M97 pass', () => {
    it('opens New schedule and Change rate in the console\'s modal, and reads the rate back in words', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'New schedule'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'New charge schedule')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Type what each unit is billed.')
            await view.click(view.one(byClass('cd-close')))

            await view.click(button(view, 'Change rate'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Change the rate of Maintenance fee')
            const read = textOf(view.one(byClass('cs-read')))
            assert.ok(!read.includes('→') && !read.includes('—'), `no arrow and no em dash: ${read}`)
        } finally {
            view.unmount()
        }
    })

    it('asks before stopping a schedule, and stops nothing on the first press', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'Stop schedule'))
            assert.equal(visits.length, 0, 'one click no longer stops the billing')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Stop Maintenance fee?')

            await view.click(button(view, 'Stop the schedule'))
            assert.equal(visits.at(-1).url, '/finance/charge-schedule/1/stop')
        } finally {
            view.unmount()
        }
    })

    it('has no New schedule, Change rate or Stop for a role that cannot set billing, and reads a stopped one as Inactive', async () => {
        const view = await open({ canSetRates: false, schedules: [SCHEDULE(), SCHEDULE({ id: 2, description: 'Water levy', is_active: false, preview: null })] })

        try {
            for (const words of ['New schedule', 'Change rate', 'Stop schedule']) {
                assert.ok(button(view, words) === null, `${words} is not there`)
            }
            assert.equal(textOf(view.one(byClass('cs-stopped'))), 'Inactive')
        } finally {
            view.unmount()
        }
    })

    it('has no Reverse without the permission, and greys it with why on a month that is not the latest', async () => {
        const none = await open({ canReverse: false })

        try {
            assert.ok(button(none, 'Reverse') === null, 'Reverse is not there')
        } finally {
            none.unmount()
        }

        const view = await open({ runs: [RUN({ reversible: false, reverse_blocked: 'Reverse November 2026 first: only the latest month can be reversed.' })] })

        try {
            assert.ok(button(view, 'Reverse').attrs.disabled)
            assert.equal(textOf(view.one(byClass('row-why'))), 'Reverse November 2026 first: only the latest month can be reversed.')
        } finally {
            view.unmount()
        }
    })
})

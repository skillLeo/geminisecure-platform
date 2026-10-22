/**
 * Posting a month asks first: `Pages/Estate/Dues/ChargeSchedule.vue` (EST-S-03, M83 §1.2 item 8).
 *
 *   A month of charges (October 2026 · 2,000 units · $12,400,000.00) posted on one click. Now the press asks, naming
 *   the month, the units and the total, and only the question's own Post sends it. Not now sends nothing. Since the M97
 *   pass the question is the console's dialog (it was a strip beside the button), so it is read from the dialog.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: ChargeSchedule } = await import('../../resources/js/Pages/Estate/Dues/ChargeSchedule.vue')

const schedule = {
    id: 1,
    description: 'Maintenance fee',
    amount: '$6,200.00',
    scope: 'Whole estate',
    due_day: 1,
    account: '4000 Maintenance Fee Income',
    is_active: true,
    next_label: 'October 2026',
    pending_rates: [],
    rate_options: [],
    blocked: null,
    preview: { period: '2026-10', label: 'October 2026', count: 2000, rate: '$6,200.00', total: '$12,400,000.00', total_minor: 1_240_000_000, due_label: 'Oct 1, 2026' },
}

const open = async () => {
    resetInertia({ auth: { user: { id: 7, name: 'Tracey Reid', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {} }, '/estate/phoenixpark/finance/charge-schedule')
    visits.length = 0

    const view = await mount(ChargeSchedule, {
        estate: { name: 'Phoenix Park' },
        schedules: [schedule],
        runs: [],
        table: { total: 0, current: 1, last: 1, from: null, to: null, sort: 'period', dir: 'desc' },
        incomeAccounts: [],
        phases: [],
        canPost: true,
        canSetRates: false,
        canReverse: false,
        reasons: { post: '', rate: '', schedule: '', reverse: '' },
    })
    await nextTick()

    const button = (label) => view.all(byTag('button')).find((el) => textOf(el).trim() === label) ?? null

    return { view, button }
}

describe('a month of charges asks before it posts', () => {
    it('asks, naming the month, the units and the total, and sends nothing on the first press', async () => {
        const page = await open()

        await page.view.click(page.button('Post October 2026'))

        assert.equal(visits.filter((v) => v.method === 'post').length, 0, 'one click posted a month of charges')
        assert.equal(textOf(page.view.one(byClass('cd-title'))), 'Post October 2026?', 'the question names the month')
        const body = textOf(page.view.one(byClass('cd-body')))
        assert.ok(body.includes('2,000 units'), 'and the units')
        assert.ok(body.includes('$12,400,000.00'), 'and the total')
    })

    it('posts the month only from the confirm, with the figures it showed', async () => {
        const page = await open()

        await page.view.click(page.button('Post October 2026'))
        assert.ok(page.button('Post'), 'the question has no Post of its own')
        await page.view.click(page.button('Post'))

        const posts = visits.filter((v) => v.method === 'post')
        assert.equal(posts.length, 1)
        assert.equal(posts[0].url, '/estate/phoenixpark/finance/charge-schedule/1/runs')
        assert.deepEqual(posts[0].data, { period: '2026-10', expected_count: 2000, expected_total_minor: 1_240_000_000 })
    })

    it('sends nothing when the question is answered Not now, and closes it', async () => {
        const page = await open()

        await page.view.click(page.button('Post October 2026'))
        const notNow = page.button('Not now')
        assert.ok(notNow, 'the question can be refused')
        await page.view.click(notNow)

        assert.equal(visits.filter((v) => v.method === 'post').length, 0)
        assert.equal(page.view.one(byClass('cd-title')), null, 'the question is gone')
    })
})

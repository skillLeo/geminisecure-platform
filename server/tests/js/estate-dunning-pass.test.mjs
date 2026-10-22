/**
 * Reminders, after the M97 pass (`Pages/Estate/Dues/Dunning.vue`).
 *
 *   The page says Reminders, never Dunning (M94 §1.5). New template and Reword open the console's modal (M95 §1), and a
 *   new step is sent the day after the ladder's last, where it defaulted to day 0 (M94 §3.4); closing the modal untouched
 *   asks nothing, closing it with words typed asks first. Put in force opens the modal and asks for the resolution. A
 *   role that may not word a reminder has no New template, Reword or Send, and a draft reads Pending where it had a
 *   greyed Put in force (M96 level 3). A reminder's state takes the workflow word (M96 §2), its delivery's own words on
 *   hover. The no-step panel is drawn only when there is no step: it showed under a working ladder whenever no draft was
 *   waiting. Opened from a ledger, the way back to that ledger is the shell's, top left (M95 §3).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: Dunning } = await import('../../resources/js/Pages/Estate/Dues/Dunning.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const STEP = (over = {}) => ({ id: 2, key: 'reminder1', label: 'Reminder 1', stage: 1, channel: 'email', subject: 'Your account is overdue', body: 'Dear {resident_first_name}, {unit_label} owes {balance}.', days_overdue: 30, ...over })

const LADDER = [
    STEP({ id: 1, key: 'day20', label: 'Day 20', stage: 0, days_overdue: 0 }),
    STEP(),
    STEP({ id: 3, key: 'reminder4', label: 'Reminder 4+', stage: 4, days_overdue: 90 }),
]

const DRAFT = { id: 7, target: 'Final demand', stage: 5, channel_label: 'Email', days_overdue: 120, drafted_by: 'Norman Case', drafted_at: 'Oct 10, 2026', resolution_reference: null }

const SEND = (over = {}) => ({ id: 1, unit: '7471', step: 'Reminder 1', channel: 'Email', sent: 'Oct 10', balance_minor: 1_240_000, status: 'Queued', tone: 'sent', ...over })

const TABLE = (total) => ({ sort: null, dir: null, default: { sort: 'sent', dir: 'desc' }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: total ? 1 : null, to: total, total, q: null })

const open = async (props = {}, url = '/finance/dunning') => {
    resetInertia({ auth: { user: { id: 97009, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, url)

    const view = await mount(Dunning, {
        estate: { name: 'Phoenix Park Village 1' },
        sends: [],
        table: TABLE(0),
        templates: LADDER,
        mergeFields: ['{resident_first_name}', '{unit_label}', '{balance}'],
        channels: { email: 'Email' },
        drafts: [],
        canSend: true,
        canAdopt: true,
        adoptBlockedReason: '',
        ...props,
    })
    await nextTick()

    return view
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).trim() === words) ?? null

const field = (view, id) => view.one((el) => el.attrs?.id === id)

afterEach(() => resetToasts())

describe('reminders, after the M97 pass', () => {
    it('says Reminders, and opens New template in the modal on the day after the ladder\'s last step', async () => {
        const view = await open()

        try {
            assert.ok(!/dunning/i.test(view.text()), 'no "Dunning" anywhere a person reads')

            await view.click(button(view, 'New template'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'New reminder step')
            assert.equal(String(field(view, 'dt-days').value), '91', 'sent the day after Reminder 4+, not on day 0')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'A step has a name and a body.')
        } finally {
            view.unmount()
        }
    })

    it('rewords a step in the modal, and asks before discarding only when something was typed', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'Reword this step'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Reword Reminder 1')
            assert.ok(view.one(byClass('cd-why')) === null, 'a step with a name and a body can be saved')

            await view.click(view.one(byClass('cd-close')))
            assert.ok(view.one(byClass('cd-title')) === null, 'closed untouched, it asks nothing')

            await view.click(button(view, 'Reword this step'))
            await view.type(field(view, 'dt-body'), 'Dear {resident_first_name}, please call the office.')
            await view.click(view.one(byClass('cd-close')))
            assert.equal(textOf(view.one(byClass('cd-discard-q'))), 'Discard this wording?')
        } finally {
            view.unmount()
        }
    })

    it('puts a draft in force from the modal, once the resolution is named', async () => {
        const view = await open({ drafts: [DRAFT] })

        try {
            await view.click(button(view, 'Put in force'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Put Final demand in force?')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Name the resolution.')

            await view.type(field(view, 'dr-ref'), 'Res. 2026-14')
            await view.click(view.one(byClass('cd-confirm')))
            assert.equal(visits.at(-1).url, '/finance/dunning/drafts/7/adopt')
            assert.equal(visits.at(-1).data.resolution_reference, 'Res. 2026-14')
        } finally {
            view.unmount()
        }
    })

    it('has no New template, Reword or Send for a role that may not word a reminder, and a draft reads Pending', async () => {
        const sendTo = { unit: { id: 12, reference: 'Lot 7471', title: 'Lot 7471, Marcia Grant' }, steps: [STEP()], suggested: 2, notices: [], flag: null }
        const view = await open({ canSend: false, canAdopt: false, drafts: [DRAFT], sendTo }, '/finance/dunning?unit=12')

        try {
            for (const words of ['New template', 'Reword this step', 'Send this reminder', 'Put in force']) {
                // A boolean, never the element: a failing assert on a rendered element serialises the whole Vue tree.
                assert.ok(button(view, words) === null, `${words} is not there`)
            }
            assert.equal(textOf(view.one(byClass('dun-pending'))), 'Pending')
        } finally {
            view.unmount()
        }
    })

    it('shows each reminder\'s state in the workflow words, with the delivery\'s own words on hover', async () => {
        const sends = [
            SEND({ id: 1, status: 'Queued' }),
            SEND({ id: 2, status: 'Sent' }),
            SEND({ id: 3, status: 'Delivered by email; push not sent' }),
            SEND({ id: 4, status: 'SMS failed', tone: 'failed' }),
            SEND({ id: 5, status: 'Expired, never sent', tone: 'failed' }),
        ]
        const view = await open({ sends, table: TABLE(sends.length) })

        try {
            const pills = view.all(byClass('dun-status'))
            assert.deepEqual(pills.map((pill) => textOf(pill).trim()), ['Pending', 'Submitted', 'Completed', 'Failed', 'Expired'])
            assert.ok(pills[3].attrs.title.startsWith('SMS failed.'), `the failed leg is named on hover: ${pills[3].attrs.title}`)
            assert.ok(view.text().includes('of 5 reminders'), 'the count says reminders, as the page does')
        } finally {
            view.unmount()
        }
    })

    it('draws the no-step panel only when the estate has no step, never under a working ladder', async () => {
        const working = await open({ drafts: [] })

        try {
            assert.ok(!working.text().includes('has no active step'), 'a working ladder with no draft waiting is not told it has no step')
        } finally {
            working.unmount()
        }

        const none = await open({ templates: [] })

        try {
            assert.ok(none.text().includes('No reminder step is set up'))
        } finally {
            none.unmount()
        }
    })

    it('says who may open the log in plain words when a role may not', async () => {
        resetInertia({ auth: { user: { id: 97009, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {}, screenState: 'denied' }, '/finance/dunning')
        const view = await mount(Dunning, { estate: { name: 'Phoenix Park Village 1' }, sends: [], table: TABLE(0), templates: LADDER, mergeFields: [], channels: { email: 'Email' }, drafts: [], canSend: false, canAdopt: false, adoptBlockedReason: '' })

        try {
            const text = view.text()
            assert.ok(text.includes('only roles that handle dues can open it. Ask your estate administrator if you need it.'), text.slice(0, 300))
            assert.ok(!text.includes('role access matrix') && !text.includes('module'), 'no system words')
        } finally {
            view.unmount()
        }
    })

    it('opened from a ledger, says the way back to that ledger first in the bar, and its log in the same words', async () => {
        const notices = [{ id: 9, step: 'Reminder 1', channel: 'Email', sent: 'Oct 9', status: 'Queued', tone: 'sent' }]
        const sendTo = { unit: { id: 12, reference: 'Lot 7471', title: 'Lot 7471, Marcia Grant' }, steps: [STEP()], suggested: 2, notices, flag: null }
        const view = await open({ sendTo }, '/finance/dunning?unit=12')

        try {
            const back = view.one(byClass('back-link'))
            assert.equal(textOf(back).trim(), 'Back to Lot 7471\'s ledger')
            assert.equal(back.attrs.href, '/finance/units/12')
            assert.ok(view.text().includes('Reminder 1 · Email · Oct 9 · Pending'), 'the household\'s own log reads as the estate\'s does')
            assert.ok(!view.text().includes('—'), 'no em dash on the screen')
        } finally {
            view.unmount()
        }
    })
})

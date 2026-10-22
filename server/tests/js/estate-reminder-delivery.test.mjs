/**
 * Reminder delivery's preview on Dunning & reminders — `Pages/Estate/Dues/Dunning.vue` (M84 §3.5).
 *
 *   Sending ships off. While its switch is on the screen shows, in words, that delivery is off and nothing is sent;
 *   each reminder a run would send now, to whom and by which channel, with its words as the log holds them; and how
 *   many older ones would be marked expired. Nothing in the panel sends: it holds no control at all. With its switch
 *   off the server sends no `delivery`, and the panel is absent.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byTag, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Dunning } = await import('../../resources/js/Pages/Estate/Dues/Dunning.vue')

const DELIVERY = {
    enabled: false,
    total: 1,
    would_expire: 12,
    rows: [{
        id: 81, unit: 'Lot 7481', to: 'Delroy Campbell', step: 'Reminder 1', channel: 'Push + Email', queued: 'Oct 10, 9:00 AM',
        legs: [
            { channel: 'Email', to: 'delroy@example.org', note: null },
            { channel: 'Push', to: null, note: 'Push not sent: reminders do not go by push yet' },
        ],
        subject: 'Your account at Phoenix Park is overdue',
        body: 'Dear Delroy,\n\nLot 7481 owes $12,400.00.',
    }],
}

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 84101, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/finance/dunning')
    const view = await mount(Dunning, {
        estate: { name: 'Phoenix Park' },
        sends: [],
        table: null,
        templates: [],
        mergeFields: [],
        channels: { email: 'Email' },
        drafts: [],
        canSend: true,
        canAdopt: false,
        adoptBlockedReason: '',
        ...props,
    })
    await flush(nextTick)
    return view
}

const panel = (view) => view.all((el) => el.tag === 'section' && el.attrs?.['aria-label'] === 'Reminder delivery')[0] ?? null

describe('reminder delivery on Dunning & reminders', () => {
    it('says delivery is off, and shows each reminder, to whom, by which channel, in its own words', async () => {
        const view = await open({ delivery: DELIVERY })
        try {
            const section = panel(view)
            assert.ok(section, 'the panel is drawn')
            const text = textOf(section)
            for (const words of ['Delivery is off. Nothing is sent.', 'Lot 7481', 'Delroy Campbell', 'Reminder 1', 'Email: delroy@example.org', 'Push: Push not sent', 'Your account at Phoenix Park is overdue', 'Lot 7481 owes $12,400.00.', '12 older reminders would be marked expired and never sent.']) {
                assert.ok(text.includes(words), `"${words}" is in the panel: ${text.slice(0, 400)}`)
            }
        } finally {
            view.unmount()
        }
    })

    it('holds no control that could send', async () => {
        const view = await open({ delivery: DELIVERY })
        try {
            const section = panel(view)
            assert.equal(findAll(section, byTag('button')).length, 0)
            assert.equal(findAll(section, byTag('form')).length, 0)
            assert.equal(findAll(section, byTag('a')).length, 0)
        } finally {
            view.unmount()
        }
    })

    it('is absent when the server sends no delivery (its switch off)', async () => {
        const view = await open()
        try {
            assert.equal(panel(view), null)
            assert.ok(!view.text().includes('Reminder delivery'))
        } finally {
            view.unmount()
        }
    })
})

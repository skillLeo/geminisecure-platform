/**
 * The panic queue, after the M98 pass (`Pages/Gemini/Dispatch/Alerts.vue`).
 *
 *   Red is reserved (Hassam, 11 Oct): an open panic keeps its red; an open incident or licence row that nobody has
 *   answered yet is amber, and so is the Resident tag, which was red on every row a resident raised. The five states
 *   (M98 §3.8): loading, empty that says nothing is outstanding, populated, error with Try again, denied with nothing
 *   of the queue. And no em dash in what a dispatcher reads (M53 Law 3).
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared, RAISED } from './support/alerts.mjs'

const SOURCE = new URL('../../resources/js/Pages/Gemini/Dispatch/Alerts.vue', import.meta.url)
const { default: AlertsQueue } = await import('../../resources/js/Pages/Gemini/Dispatch/Alerts.vue')

const ROW = (over = {}) => ({
    key: 'alert-1', href: '/dispatch/alerts/1', icon: 'alert', icon_stroke: 1.7, urgent: false, title: 'Jane Brown: panic button',
    tag: 'Resident', tag_class: 'source-tag resident', meta: 'Phoenix Park · Lot 47 · 2 minutes ago', status: 'Unacknowledged', status_class: 'unack', ...over,
})

const ROWS = [
    ROW({ key: 'alert-1', urgent: true }),
    ROW({ key: 'alert-2', title: 'Marcus Reid: incident report', tag: 'Guard-reported', tag_class: 'source-tag' }),
    ROW({ key: 'guard-9', title: 'Devon Palmer: PSRA compliance', tag: 'Guard workforce', tag_class: 'source-tag' }),
]

let view = null

const open = async (props = {}, shared = {}) => {
    resetInertia(geminiShared(shared), '/dispatch/alerts')
    view = await mount(AlertsQueue, { sections: [], tabs: [], tab: 'all', alerts: ROWS, estateIds: [], sourceBadge: { source: 'devices', simulated: false }, ...props })
    await flush(nextTick)

    return view
}

beforeEach(() => {
    socket.reset()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 60_000 })
})

afterEach(() => {
    view?.unmount()
    view = null
    mock.timers.reset()
})

describe('the panic queue, after the M98 pass', () => {
    it('keeps red for an open panic only: an unanswered incident or licence row is amber', async () => {
        await open()

        const statuses = view.all((el) => (el.attrs?.class ?? '').split(/\s+/).includes('alert-status'))
        assert.equal(statuses.length, 3)
        assert.ok(!statuses[0].attrs.class.includes('not-urgent'), 'the open panic keeps its red')
        assert.ok(statuses[1].attrs.class.includes('not-urgent'), 'an incident nobody has answered is amber')
        assert.ok(statuses[2].attrs.class.includes('not-urgent'), 'and so is a licence row')

        const css = readFileSync(SOURCE, 'utf8')
        assert.match(css, /\.alert-status\.unack\.not-urgent\s*\{[^}]*amber/, 'the amber, in the page\'s own style')
        assert.match(css, /\.source-tag\.resident\s*\{[^}]*amber/, 'the Resident tag in amber, never red')
    })

    it('says no em dash anywhere a dispatcher reads, the title and the empty queue included', async () => {
        await open()
        assert.ok(!view.text().includes('—'), 'the populated queue')
        assert.ok(view.text().includes('Dispatch: active alerts'))
        view.unmount()

        await open({ alerts: [] })
        assert.ok(view.text().includes('No active alerts'), 'empty says nothing is outstanding')
        assert.ok(!view.text().includes('—'), 'the empty queue')
    })

    it('draws loading, a failed read with Try again, and a refused role with nothing of the queue', async () => {
        await open({}, { screenState: 'loading' })
        assert.ok(!view.text().includes('Jane Brown'), 'no rows while loading')
        assert.ok(!view.text().includes('No active alerts'), 'and no empty state')
        view.unmount()

        await open({}, { screenState: 'error' })
        assert.ok(view.text().includes('The alert queue could not be read'))
        assert.equal(view.all((el) => el.tag === 'button' && textOf(el) === 'Try again').length, 1)
        view.unmount()

        await open({}, { screenState: 'denied' })
        assert.ok(view.text().includes('Dispatch is not part of your role'))
        assert.ok(!view.text().includes('Jane Brown'), 'nothing of the queue')
    })
})

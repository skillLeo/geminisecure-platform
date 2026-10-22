/**
 * The panic pop-up, as a reader sees it — `Components/PanicOverlay.vue`.
 *
 *   B090  it never says "Acknowledged" about an alert the control room has not
 *         acknowledged, when an officer took it from the handset first.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { overlayAlert as alertAt, RAISED } from './support/alerts.mjs'

const { default: PanicOverlay } = await import('../../resources/js/Components/PanicOverlay.vue')

beforeEach(() => {
    resetInertia()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 5_000 })
})

afterEach(() => {
    mock.timers.reset()
})

describe('B090 — the console card and an officer who took the alert from the handset', () => {
    it('does not say "Acknowledged" when an officer is on the way and nobody in the control room acknowledged it', async () => {
        const view = await mount(PanicOverlay, {
            variant: 'console',
            blinking: true,
            alert: alertAt({ status: 'responding', enRouteAt: new Date(RAISED + 20_000).toISOString() }),
        })

        const status = view.one(byClass('panic-status'))

        assert.ok(status, 'the console card states where the alert stands')
        assert.doesNotMatch(view.text(), /\bAcknowledged\b/, 'nobody in the control room acknowledged it')
        assert.match(view.text(), /not acknowledged by the control room/i)
        assert.match(view.text(), /an officer is on the way/i)

        // Still drawn as the state that needs somebody: red, like an open alert.
        assert.ok(status.className.split(' ').includes('open'))

        view.unmount()
    })

    it('says the same for "on scene", "dispatched" and plain "responding" without an acknowledgement', async () => {
        for (const step of [
            { onSceneAt: new Date(RAISED + 60_000).toISOString(), says: /an officer is on scene/i },
            { dispatchedAt: new Date(RAISED + 60_000).toISOString(), says: /an officer was dispatched/i },
            { says: /an officer is responding/i },
        ]) {
            const { says, ...columns } = step
            const view = await mount(PanicOverlay, { variant: 'console', blinking: true, alert: alertAt({ status: 'responding', ...columns }) })

            assert.doesNotMatch(view.text(), /\bAcknowledged\b/)
            assert.match(view.text(), /not acknowledged by the control room/i)
            assert.match(view.text(), says)

            view.unmount()
        }
    })

    it('still says "Acknowledged" with the time once the control room did acknowledge it', async () => {
        const view = await mount(PanicOverlay, {
            variant: 'console',
            blinking: true,
            alert: alertAt({
                status: 'responding',
                acknowledgedAt: new Date(RAISED + 40_000).toISOString(),
                enRouteAt: new Date(RAISED + 20_000).toISOString(),
            }),
        })

        assert.match(view.text(), /Acknowledged at .*; an officer is on the way/)
        assert.ok(!view.one(byClass('panic-status')).className.split(' ').includes('open'))

        view.unmount()
    })
})

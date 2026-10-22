/**
 * How long ago the alert was raised — `Components/PanicOverlay.vue`, and the response screen.
 *
 *   B112  "… minutes ago" is the alert's age NOW, not its age when the card opened: a card
 *         left open counts on, on both consoles, and the estate's card says the time it was
 *         raised as well, so its one clue to the alert's age is not the one that can go stale.
 *         The same on the alert's own screen, whose "Fired" was the server's age at the
 *         moment the page was drawn.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { alertScreenProps, overlayAlert as alertAt, flush, geminiShared, RAISED } from './support/alerts.mjs'

const { default: PanicOverlay } = await import('../../resources/js/Components/PanicOverlay.vue')
const { default: AlertScreen } = await import('../../resources/js/Pages/Gemini/Dispatch/Alert.vue')

beforeEach(() => {
    resetInertia()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 5_000 })
})

afterEach(() => {
    mock.timers.reset()
})

describe('B112 — how long ago the alert was raised', () => {
    it('keeps counting while the estate card is left open', async () => {
        const view = await mount(PanicOverlay, { blinking: true, alert: alertAt() })

        assert.match(view.text(), /just now/)

        mock.timers.tick(5 * 60_000)
        await nextTick()

        assert.match(view.text(), /5 minutes ago/, 'the card was left open for five minutes')
        assert.doesNotMatch(view.text(), /just now/)

        mock.timers.tick(20 * 60_000)
        await nextTick()

        assert.match(view.text(), /25 minutes ago/)

        view.unmount()
    })

    it('keeps counting on the console card too', async () => {
        const view = await mount(PanicOverlay, { variant: 'console', blinking: true, alert: alertAt() })

        mock.timers.tick(3 * 60_000)
        await nextTick()

        assert.match(view.text(), /3 minutes ago/)

        view.unmount()
    })

    it('says the time it was raised on the estate card, not only its age', async () => {
        const view = await mount(PanicOverlay, { blinking: true, alert: alertAt() })
        const raised = new Date(RAISED).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })

        assert.ok(view.text().includes(raised), `the estate card names ${raised}: "${view.text()}"`)

        view.unmount()
    })
})

describe('M19A §7 — when it happened AT THE SITE, beside how long ago', () => {
    it('says the site\'s time with its tag, whatever clock the reader\'s machine is on', async () => {
        // Raised 22:40 UTC: 5:40 PM in Kingston, 6:40 PM in New York.
        const kingston = await mount(PanicOverlay, { blinking: true, alert: alertAt({ zone: 'America/Jamaica' }) })
        assert.match(kingston.text(), /5:40 PM JA/)
        assert.match(kingston.text(), /just now/)
        kingston.unmount()

        const newYork = await mount(PanicOverlay, { blinking: true, alert: alertAt({ zone: 'America/New_York' }) })
        assert.match(newYork.text(), /6:40 PM NY/)
        newYork.unmount()
    })
})

describe('B112 — "Fired" on the alert\'s own screen', () => {
    it('counts on while the dispatcher works the incident, on a quiet channel', async () => {
        socket.reset()
        resetInertia(geminiShared(), '/dispatch/alerts/4')

        const view = await mount(AlertScreen, alertScreenProps({ fired: 'just now' }))
        await flush(nextTick)

        socket.setState('connected')
        socket.subscribe('estate.phoenixpark.alerts')
        await flush(nextTick)

        mock.timers.tick(20 * 60_000)
        await nextTick()

        assert.match(view.text(), /20 minutes ago\s*Fired/, 'twenty minutes into the incident')

        view.unmount()
    })
})

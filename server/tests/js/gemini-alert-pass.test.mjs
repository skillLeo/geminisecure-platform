/**
 * One panic alert, after the M98 pass (`Pages/Gemini/Dispatch/Alert.vue`).
 *
 *   The way back says where it goes, "Back to alerts" (M95 §3), and the title has no em dash. Every action at its M96
 *   level: a step already recorded is a badge with its time, never a dead button (level 3); a step that waits on another
 *   is greyed with its reason on the screen (level 2); a closed alert, or a role that may only watch, shows the result
 *   in words instead of a panel of dead buttons. Escalation, which nothing here records yet, says so on the screen.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { alertScreenProps, flush, geminiShared, RAISED } from './support/alerts.mjs'

const { default: AlertScreen } = await import('../../resources/js/Pages/Gemini/Dispatch/Alert.vue')

let view = null

const open = async (actions = {}, alert = {}) => {
    resetInertia(geminiShared(), '/dispatch/alerts/4')
    view = await mount(AlertScreen, alertScreenProps(alert, { may_act: true, settled: false, ...actions }))
    await flush(nextTick)

    return view
}

/** An action of the Actions panel by its words (the page has other buttons that say "dispatch"). */
const button = (words) => view.all((el) => el.tag === 'button' && (el.attrs?.class ?? '').split(/\s+/).includes('action-btn') && textOf(el).includes(words))[0] ?? null
const whys = () => view.all((el) => (el.attrs?.class ?? '').split(/\s+/).includes('action-why')).map(textOf)
const badges = () => view.all((el) => (el.attrs?.class ?? '').split(/\s+/).includes('action-done')).map(textOf)

beforeEach(() => {
    socket.reset()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 60_000 })
})

afterEach(() => {
    view?.unmount()
    view = null
    mock.timers.reset()
})

describe('one panic alert, after the M98 pass', () => {
    it('says the way back in words and titles the page without an em dash', async () => {
        await open()

        const back = view.all((el) => el.tag === 'a' && textOf(el) === 'Back to alerts')[0] ?? null
        assert.equal(back === null, false, 'Back to alerts')
        assert.equal(back.attrs.href, '/dispatch/alerts')
        assert.ok(view.text().includes('Panic button: Jane Brown'), 'the title, with a colon')
        assert.ok(!view.text().includes('Panic button — Jane Brown'))
    })

    it('draws a step already recorded as a badge with its time, never a dead button', async () => {
        await open({
            can_acknowledge: false, acknowledged: true, acknowledge_title: 'Already acknowledged at 10:42 PM', acknowledged_time: '10:42 PM',
            can_dispatch: false, dispatched: true, dispatch_label: 'Dispatched Marcus Reid', dispatch_title: 'Marcus Reid was dispatched at 10:44 PM',
            officer: 'Marcus Reid', dispatched_time: '10:44 PM',
            can_en_route: true, en_route_label: 'Mark Marcus on the way',
        })

        assert.equal(button('Acknowledge') === null, true, 'no Acknowledge once acknowledged')
        assert.equal(button('Dispatch') === null, true, 'no Dispatch once somebody is sent')
        // The badge is the state in the Emergency list's word, then who and when (Hassam, 11 Oct).
        assert.deepEqual(badges(), ['Acknowledged · 10:42 PM', 'Dispatched · Marcus Reid · 10:44 PM'])
        const next = button('Mark Marcus on the way')
        assert.equal(next === null, false, 'the next step is drawn')
        assert.ok(!next.attrs.disabled, 'and it is a working button')
    })

    it('greys a step that waits on another, with its reason on the screen', async () => {
        await open({ can_en_route: false, en_route_title: 'Dispatch an officer first: on the way is recorded for the officer who was sent' })

        assert.ok(button('Mark on the way')?.attrs.disabled, 'greyed')
        assert.ok(whys().includes('Dispatch an officer first: on the way is recorded for the officer who was sent'), 'the reason, without hovering')
    })

    it('shows the result instead of the buttons for a closed alert and for a role that may only watch', async () => {
        await open({ settled: true, can_acknowledge: false, can_resolve: false, can_en_route: false, can_on_scene: false }, { status_label: 'Resolved' })
        assert.equal(button('Resolve') === null, true, 'no Resolve on a closed alert')
        assert.equal(button('Acknowledge') === null, true)
        assert.ok(view.text().includes('This alert is closed.'), 'the result, in words')
        view.unmount()

        await open({ may_act: false, can_acknowledge: false, can_resolve: false, can_en_route: false, can_on_scene: false })
        assert.equal(button('Acknowledge') === null, true, 'no Acknowledge for a role that may only watch')
        assert.ok(view.text().includes('Your role may watch dispatch but not act on an alert.'))
    })

    it('draws a refused step or message in amber: red is the panic\'s alone', async () => {
        const { readFileSync } = await import('node:fs')
        const source = readFileSync(new URL('../../resources/js/Pages/Gemini/Dispatch/Alert.vue', import.meta.url), 'utf8')

        assert.match(source, /\.chat-error \{[^}]*color: var\(--amber-700\)/, 'an ordinary error in amber')
    })

    it('says on the screen that escalation to the police is not recorded here yet', async () => {
        await open()

        // A static `disabled` reads as an empty string, so its presence is what counts.
        assert.notEqual(button('Escalate to police')?.attrs.disabled, undefined, 'still greyed')
        assert.ok(whys().some((why) => why.startsWith('Not recorded here yet')), 'its reason, without hovering')
        assert.ok(!view.text().includes('integration ships'), 'no machinery in what a dispatcher reads')
    })
})

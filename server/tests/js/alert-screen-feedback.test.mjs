/**
 * What the alert's own screen says back — `Pages/Gemini/Dispatch/Alert.vue`.
 *
 *   B115 / B018  "Resolve & classify" never fails in silence: a classification over the
 *                500 characters the alert keeps is caught before it is sent, with the text
 *                kept for shortening, and any refusal the server sends back is shown inside
 *                the question, which stays open; a refused Acknowledge, pressed with no
 *                question open, is a toast that stays. And what the server says on success,
 *                "confirm by radio that they have it", "Sent to Marcus", is a toast that stays
 *                up until it is closed (Hassam, 12 October: the console's messages in three
 *                kinds), where the flash it rode on was never read.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { fire, mount, nextTick, textOf } from './support/render.mjs'
import { page, resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { alertScreenProps, flush, geminiShared, RAISED } from './support/alerts.mjs'

const { default: AlertScreen } = await import('../../resources/js/Pages/Gemini/Dispatch/Alert.vue')
const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')

const officer = { guard_id: 41, shift_id: 9, name: 'Marcus Reid', post: 'Main Gate' }

const open = async (actions = {}, extra = {}) => {
    resetInertia(geminiShared(), '/dispatch/alerts/4')

    const view = await mount(AlertScreen, {
        ...alertScreenProps({}, {
            can_dispatch: true,
            dispatch_candidates: [officer],
            can_message: true,
            ...actions,
        }),
        onDuty: [officer],
        ...extra,
    })

    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el).includes(label))[0] ?? null
const posts = (url) => visits.filter((visit) => visit.method === 'post' && visit.url === url)

let prompts = []

beforeEach(() => {
    socket.reset()
    visits.length = 0
    prompts = []
    resetToasts()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 60_000 })
})

afterEach(() => {
    mock.timers.reset()
    resetToasts()
    delete globalThis.prompt
})

/** Whether a toast of this tone says these words (a yes or no, never an element). */
const toasted = (tone, words) => toasts.some((each) => each.tone === tone && words.test(each.text))

/** The dispatcher's answers to each native prompt, in turn; each question is kept. */
const answering = (...answers) => {
    globalThis.prompt = (question, preset) => {
        prompts.push({ question, preset })

        return answers.shift() ?? null
    }
}

describe('B115 / B018 — "Resolve & classify" never fails in silence', () => {
    /*
     * Resolve & classify is the console's own dialog now (24 September 2026), not a
     * browser prompt: a classification chosen from the server's list and a note. What
     * these three prove is unchanged — nothing over the limit is sent, cancelling sends
     * nothing, and the server's refusal is shown — only the way it is exercised is.
     */
    const CLASSIFICATIONS = [
        { value: 'genuine_medical', label: 'Medical emergency', group: 'Genuine emergency', note_required: false },
        { value: 'genuine_other', label: 'Other emergency', group: 'Genuine emergency', note_required: true },
        { value: 'false_alarm', label: 'Pressed by mistake', group: 'False alarm', note_required: false },
    ]

    const openDialog = async () => {
        const view = await open({ resolve_classifications: CLASSIFICATIONS, resolve_note_max: 500 })

        await view.click(button(view, 'Resolve & classify'))

        return view
    }

    const choose = async (view, value) => {
        const radio = view.all((el) => el.tag === 'input' && el.attrs.type === 'radio' && el.value === value)[0]

        assert.ok(radio, `the dialog offers "${value}"`)
        fire(radio, 'change')
        await nextTick()
    }

    const note = (view) => view.all((el) => el.tag === 'textarea')[0]

    it('opens the console dialog, not a browser prompt, and holds a note over 500 characters back with the text kept to shorten', async () => {
        const view = await openDialog()

        assert.match(view.text(), /Resolve and classify this alert/)
        assert.equal(prompts.length, 0, 'no browser prompt')

        await choose(view, 'genuine_medical')
        await view.type(note(view), 'x'.repeat(600))

        const confirm = button(view, 'Resolve alert')

        assert.equal(confirm.attrs.disabled, true, 'cannot be pressed while the note is over the limit')
        assert.match(confirm.attrs.title, /over 500 characters/)
        assert.match(view.text(), /600 \/ 500/)

        await view.click(confirm)
        assert.equal(posts('/dispatch/alerts/4/resolve').length, 0, 'nothing is sent that the server would refuse')
        assert.equal(note(view).value.length, 600, 'the dispatcher\'s text is kept, to shorten')

        await view.type(note(view), 'y'.repeat(400))
        await view.click(button(view, 'Resolve alert'))

        const sent = posts('/dispatch/alerts/4/resolve')

        assert.equal(sent.length, 1)
        assert.equal(sent[0].data.classification, 'genuine_medical')
        assert.equal(sent[0].data.note.length, 400)

        view.unmount()
    })

    it('sends nothing when the dispatcher cancels, and nothing without a classification', async () => {
        const view = await openDialog()

        await view.type(note(view), 'Resident safe.')
        await view.click(button(view, 'Resolve alert'))
        assert.equal(posts('/dispatch/alerts/4/resolve').length, 0, 'no classification, nothing sent')

        await view.click(button(view, 'Cancel'))
        assert.equal(posts('/dispatch/alerts/4/resolve').length, 0)
        assert.doesNotMatch(view.text(), /Resolve and classify this alert/, 'the dialog closed')

        view.unmount()
    })

    it('shows the server\'s refusal in the dialog when a resolve is refused', async () => {
        const view = await openDialog()

        await choose(view, 'false_alarm')
        await view.type(note(view), 'Resident safe; false alarm by a child.')
        await view.click(button(view, 'Resolve alert'))

        const [call] = posts('/dispatch/alerts/4/resolve')

        assert.ok(call?.options.onError, 'the refusal has somewhere to go')
        assert.equal(call.data.classification, 'false_alarm')

        call.options.onError({ note: 'The note can be up to 500 characters. Shorten it and resolve again.' })
        await nextTick()

        assert.match(view.text(), /Shorten it and resolve again/)
        assert.match(view.text(), /Resolve and classify this alert/, 'the dialog stays open on a refusal')

        view.unmount()
    })

    it('says a refused acknowledgement as a toast that stays, as it is pressed with no question open', async () => {
        const view = await open()

        await view.click(button(view, 'Acknowledge'))

        const [call] = posts('/dispatch/alerts/4/acknowledge')

        assert.ok(call?.options.onError)

        call.options.onError({ progress: 'This alert was closed before it could be acknowledged.' })
        await nextTick()

        assert.equal(toasted('error', /closed before it could be acknowledged/), true, 'an error toast, which stays until it is closed')

        view.unmount()
    })
})

describe('B115 / B018 — what the server says on success is said, and stays up', () => {
    it('says the dispatch confirmation as a toast that stays until it is closed, with its instruction to confirm by radio', async () => {
        const view = await open()

        await view.click(button(view, 'Dispatch Marcus Reid'))
        assert.equal(posts('/dispatch/alerts/4/dispatch').length, 1)

        // The server's answer arrives as the page's flash, as the redirect brings it.
        page.props.flash = {
            success: 'Marcus Reid is dispatched and it is on the record. Their handset is pushed the alert if one is registered; confirm by radio that they have it.',
        }
        await flush(nextTick)

        assert.equal(toasted('success', /confirm by radio that they have it/), true, 'the instruction is said')

        // A success closes itself after five seconds; this one carries an instruction, so it stays.
        mock.timers.tick(10_000)
        await nextTick()
        assert.equal(toasted('success', /confirm by radio that they have it/), true, 'still up after ten seconds')

        view.unmount()
    })

    it('says that a message was sent, and to whom', async () => {
        const view = await open()
        const field = view.one((el) => el.tag === 'input' && el.attrs.type === 'text')

        await view.type(field, 'Go to Lot 47 now.')

        const form = view.one((el) => el.tag === 'form')
        form.handlers.submit({ preventDefault() {} })
        await nextTick()

        assert.equal(posts('/dispatch/alerts/4/message').length, 1)

        page.props.flash = { success: 'Sent to Marcus Reid. It is on their handset and on the record.' }
        await flush(nextTick)

        assert.equal(toasted('success', /^Sent to Marcus Reid/), true)

        view.unmount()
    })
})

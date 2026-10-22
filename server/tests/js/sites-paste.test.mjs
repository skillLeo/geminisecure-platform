/**
 * Placing a post from the message a person at the gate sent — `Pages/Gemini/Operations/Sites.vue` (M25 §3).
 *
 * GATE_POSITIONS.md asks for one line per post, "Back Gate, 18.01234, -76.95678". The Director pastes that line, as it
 * arrived, into the position field, sets the radius and says why; what is saved is exactly the two numbers, the radius
 * and the reason. A line with two positions in it is refused in words, and nothing can be saved from it.
 *
 * The map is drawn offline here (its words, not Leaflet): this is about the field and what Save sends.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

// The map listens for the browser going on and off line; nothing here goes either way.
Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true })
window.addEventListener ??= () => {}
window.removeEventListener ??= () => {}

const { default: Sites } = await import('../../resources/js/Pages/Gemini/Operations/Sites.vue')

const open = async () => {
    resetInertia(geminiShared({ auth: { user: { id: 78, name: 'Dora Director', role_label: 'Director' } }, errors: {} }), '/guards/sites/phoenixpark')

    const view = await mount(Sites, {
        estates: [{ id: 'phoenixpark', name: 'Phoenix Park Village 1' }],
        estate: 'phoenixpark',
        posts: [{ id: 7, name: 'Back Gate', type: 'gate', lat: null, lng: null, radius_m: 50 }],
        checkpoints: [],
        canPlace: true,
        radius: { min: 10, max: 2000, default: 50 },
        site: null,
    })

    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el).trim() === label)[0] ?? null
const input = (view, placeholder) => view.one((el) => el.tag === 'input' && String(el.attrs.placeholder ?? '').startsWith(placeholder))

const paste = async (view, text) => {
    const field = input(view, 'e.g. 18.0179')
    await view.type(field, text)
    fire(field, 'change')
    await nextTick()
}

beforeEach(() => {
    socket.reset()
    visits.length = 0
})

describe('placing a post from the gate\'s message', () => {
    it('takes GATE_POSITIONS.md\'s worked example as pasted and saves exactly its two numbers, the radius and the reason', async () => {
        const view = await open()

        await view.click(button(view, 'Place'))
        assert.match(view.text(), /Placing Back Gate/)

        await paste(view, 'Back Gate, 18.01234, -76.95678')
        assert.doesNotMatch(view.text(), /could not be read|more than one position/)

        await view.type(view.one((el) => el.tag === 'input' && el.attrs.type === 'number'), '30')
        await view.type(input(view, 'e.g. surveyed on site'), 'position sent from the gate, 2 October')

        // Vue drops a false `disabled` altogether.
        assert.ok(!button(view, 'Save position').attrs.disabled, 'Save position is still disabled')

        fire(view.one((el) => el.tag === 'form'), 'submit')
        await nextTick()

        const sent = visits.filter((v) => v.method === 'post')
        assert.equal(sent.length, 1)
        assert.equal(sent[0].url, '/guards/sites/posts/7')
        assert.deepEqual(sent[0].data, { lat: 18.01234, lng: -76.95678, radius_m: 30, reason: 'position sent from the gate, 2 October' })
    })

    it('refuses a pasted line with two positions in it, in words, and nothing can be saved', async () => {
        const view = await open()

        await view.click(button(view, 'Place'))
        await paste(view, 'Back Gate 18.01234, -76.95678 and Main Gate 18.02000, -76.96000')

        assert.match(view.text(), /That holds more than one position\. Paste the two numbers for this post only\./)
        assert.equal(button(view, 'Save position').attrs.disabled, true)
        assert.equal(visits.filter((v) => v.method === 'post').length, 0)
    })
})

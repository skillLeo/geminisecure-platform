/**
 * Places, steps 1 and 2 — `Pages/Gemini/Operations/Places.vue`, `lib/placesView.js` (M30 §7, §13).
 *
 * A click on the map and pasted numbers store the same value. The map never opens on the world. The place search
 * waits half a second after typing, asks at once on Enter, and shows the place without placing a pin. A gate's status
 * says the fact, then what follows from it on this server.
 *
 * The map is drawn offline here (no Leaflet in Node): what it would say to the page (`pick`) is raised on it through
 * Vue's own handle on the component, as a click on the map would raise it.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared } from './support/alerts.mjs'
import { isPlaced, openingView } from '../../resources/js/lib/placesView.js'

Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true })
window.addEventListener ??= () => {}
window.removeEventListener ??= () => {}

let searched = null
const asked = []

globalThis.fetch = async (url) => {
    asked.push(url)

    return { ok: true, json: async () => searched }
}

const { default: Places } = await import('../../resources/js/Pages/Gemini/Operations/Places.vue')

const KINGSTON = { lat: 17.997, lng: -76.7936, zoom: 12 }

const PROPS = (over = {}) => ({
    clients: [{ id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false }],
    client: { id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false },
    step: 'gates',
    canWrite: true,
    limits: { gate: { min: 10, max: 200, default: 50 }, estate: { min: 50, max: 2000, default: 400 } },
    kingston: KINGSTON,
    fenceRule: { good_accuracy_m: 50, poor_accuracy_m: 200, max_margin_m: 50, margin_per_accuracy: 2 },
    unfencedStarts: true,
    estate: { name: 'Phoenix Park Village 1', where: 'Portmore, St Catherine', lat: null, lng: null, radius_m: null, supervisor_phone: null },
    gates: [
        { id: 7, name: 'Main Gate', also_called: 'Gate 1', label: 'Main Gate (Gate 1)', type: 'gate', lat: 18.0063, lng: -76.7442, radius_m: 80 },
        { id: 8, name: 'Back Gate', also_called: null, label: 'Back Gate', type: 'gate', lat: null, lng: null, radius_m: 10 },
    ],
    ...over,
})

const open = async (over = {}) => {
    resetInertia(geminiShared({ errors: {} }), '/guards/places?client=phoenixpark&step=gates')
    const view = await mount(Places, PROPS(over))
    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el) === label)[0] ?? null
const map = (view) => view.one((el) => el.tag === 'figure').__vueParentComponent
const gateRow = (view, name) => view.all((el) => el.tag === 'li' && el.className.includes('pl-gate')).find((li) => textOf(li).includes(name))

beforeEach(() => {
    socket.reset()
    visits.length = 0
    asked.length = 0
    searched = { results: [], message: null }
})

describe('where the Places map opens', () => {
    it('opens close in on one thing placed, on all of them for several, and on Kingston for none: never the world', () => {
        assert.deepEqual(openingView([{ lat: 18.0063, lng: -76.7442 }], KINGSTON), { center: [18.0063, -76.7442], zoom: 17 })
        assert.deepEqual(openingView([{ lat: 18, lng: -76.7 }, { lat: 18.01, lng: -76.71 }], KINGSTON), { bounds: [[18, -76.7], [18.01, -76.71]] })
        assert.deepEqual(openingView([{ lat: null, lng: null }], KINGSTON), { center: [17.997, -76.7936], zoom: 12 })
        // An unplaced checkpoint is not at 0, 0, and neither is an emptied field.
        assert.equal(isPlaced({ lat: null, lng: null }), false)
        assert.equal(isPlaced({ lat: 0, lng: 0 }), false)
        assert.equal(isPlaced({ lat: '', lng: '' }), false)
        assert.equal(isPlaced({ lat: '18.0063', lng: '-76.7442' }), true)
    })
})

describe('Places, the gates', () => {
    it('stores the same value from a click on the map as from the same numbers pasted', async () => {
        const view = await open()

        // A click: "Set its location", then the map says where.
        await view.click(button(view, 'Set its location'))
        map(view).emit('pick', { lat: 17.96123451, lng: -76.88212341 })
        await nextTick()
        await view.click(button(view, 'Save'))
        const clicked = visits.at(-1)
        assert.equal(clicked.url, '/guards/places/gate/8')
        view.unmount()

        // The same numbers, pasted as a person sends them.
        const again = await open()
        await again.click(gateRow(again, 'Back Gate').children.find((el) => el.tag === 'button'))
        const field = again.one((el) => el.tag === 'input' && el.attrs.placeholder === 'e.g. 17.9612, -76.8821')
        await again.type(field, 'Back Gate: 17.96123451, -76.88212341')
        fire(field, 'change')
        await nextTick()
        await again.click(button(again, 'Save'))
        const pasted = visits.at(-1)

        assert.deepEqual(pasted.data, clicked.data)
        assert.deepEqual(clicked.data, { lat: 17.9612345, lng: -76.8821234, radius_m: 50 })
        again.unmount()
    })

    it('draws the selected gate\'s circle live as its slider moves, and saves the new radius', async () => {
        const view = await open()

        const slider = view.one((el) => el.tag === 'input' && el.attrs.type === 'range')
        slider.value = '120'
        fire(slider, 'input', { target: slider })
        await nextTick()

        assert.deepEqual(map(view).props.circles, [{ id: 7, lat: 18.0063, lng: -76.7442, radiusM: 120, selected: true }])
        assert.match(view.text(), /120 m/)

        await view.click(button(view, 'Save'))
        assert.deepEqual(visits.at(-1).data, { lat: 18.0063, lng: -76.7442, radius_m: 120 })
        view.unmount()
    })

    it('adds a gate asking only its name, then places it where the map is clicked', async () => {
        const view = await open()

        await view.click(button(view, '+ Add gate'))
        await view.type(view.one((el) => el.tag === 'input' && el.attrs.placeholder === 'e.g. Back Gate'), 'Phase 5 Gate')
        await view.click(button(view, 'Place it on the map'))
        assert.match(view.text(), /Click the map where Phase 5 Gate is\./)
        assert.equal(map(view).props.picking, true)

        map(view).emit('pick', { lat: 17.95, lng: -76.87 })
        await nextTick()
        await view.click(button(view, 'Add Phase 5 Gate'))

        assert.equal(visits.at(-1).url, '/guards/places/phoenixpark/gates')
        assert.deepEqual(visits.at(-1).data, { name: 'Phase 5 Gate', lat: 17.95, lng: -76.87, radius_m: 50 })
        view.unmount()
    })

    it('says what a gate with no location means on this server', async () => {
        const demo = await open()
        assert.match(textOf(gateRow(demo, 'Back Gate')), /No location yet ?A guard here can clock in from anywhere, and the office is told\./)
        demo.unmount()

        const live = await open({ unfencedStarts: false })
        assert.match(textOf(gateRow(live, 'Back Gate')), /A guard here cannot clock in without a supervisor's PIN until it has one\./)
        live.unmount()

        const fenced = await open({ estate: { ...PROPS().estate, lat: 17.96, lng: -76.88, radius_m: 400 } })
        assert.match(textOf(gateRow(fenced, 'Back Gate')), /A guard here is checked against the estate's area instead\./)
        assert.match(textOf(gateRow(fenced, 'Main Gate')), /Location set · clock in within 80 m/)
        fenced.unmount()
    })
})

describe('Places, the patrol', () => {
    const STOPS = [
        { id: 31, label: 'Pool deck', phase: 'Phase 2', sequence: 1, post_id: null, lat: 18.007, lng: -76.745, radius_m: 25 },
        { id: 32, label: 'Basketball court', phase: null, sequence: 2, post_id: null, lat: null, lng: null, radius_m: 25 },
        { id: 33, label: 'Church gate', phase: null, sequence: 3, post_id: null, lat: 18.008, lng: -76.746, radius_m: 25 },
    ]
    const patrol = (over = {}) => open({ step: 'patrol', checkpoints: STOPS, patrolStart: 7, patrolInterval: { minutes: 15, set: false, default: 15, min: 5, max: 240 }, phases: ['Phase 2'], ...over })
    const stops = (view) => view.all((el) => el.tag === 'li' && el.className.split(/\s+/).includes('pl-stop'))

    it('draws the route as numbered pins joined in order, from the gate the patrol starts at', async () => {
        const view = await patrol()

        assert.deepEqual(map(view).props.pins.map((p) => [p.number, p.title]), [['S', 'Main Gate: the patrol starts here'], [1, '1. Pool deck'], [3, '3. Church gate']])
        assert.deepEqual(map(view).props.route, [{ lat: 18.0063, lng: -76.7442 }, { lat: 18.007, lng: -76.745 }, { lat: 18.008, lng: -76.746 }])
        view.unmount()
    })

    it('never repeats the gate\'s name on a checkpoint, and gives each row one button', async () => {
        const view = await patrol()

        for (const row of stops(view)) {
            assert.ok(!textOf(row).includes('Main Gate'), textOf(row))
            assert.equal(row.children[0].children.filter((el) => el.tag === 'button' && el.className.includes('btn-outline-sm')).length, 1)
        }
        assert.deepEqual(stops(view).map((row) => textOf(row.children[0])), ['⠿1Pool deck Phase 2 · ✓ location setMove on the map', '⠿2Basketball court no location yetSet its location', '⠿3Church gate ✓ location setMove on the map'])
        view.unmount()
    })

    it('reorders by dragging a row onto another, and sends the whole new order', async () => {
        const view = await patrol()
        const [pool, , church] = stops(view)

        fire(pool, 'dragstart', { dataTransfer: { setData() {}, effectAllowed: '' } })
        fire(church, 'dragover')
        fire(church, 'drop')
        await nextTick()

        assert.equal(visits.at(-1).url, '/guards/places/phoenixpark/route')
        assert.deepEqual(visits.at(-1).data, { order: [32, 33, 31] })
        view.unmount()
    })

    it('places many from the installers\' messages, showing each line as it was read, and sends only the clean ones', async () => {
        const view = await patrol()

        await view.click(button(view, 'Place from messages'))
        await view.type(view.one((el) => el.tag === 'textarea' && el.attrs.placeholder === 'Basketball court, 18.01234, -76.95678'), 'Basketball court, 18.0052, -76.7461\nThe moon, 1, 2')
        await nextTick()

        assert.match(view.text(), /✓ Basketball court · 18\.00520, -76\.74610/)
        assert.match(view.text(), /✗ The moon, 1, 2:/)
        // Shown on the map where it would go, before anything is saved.
        assert.ok(map(view).props.pins.some((p) => p.id === 'stop:32' && p.lat === 18.0052))

        await view.click(button(view, 'Place 1 checkpoint'))
        assert.equal(visits.at(-1).url, '/guards/places/phoenixpark/checkpoints/place')
        assert.deepEqual(visits.at(-1).data, { places: [{ id: 32, lat: 18.0052, lng: -76.7461 }] })
        view.unmount()
    })

    it('prints every tag from one link, and one tag from inside its own row', async () => {
        const view = await patrol()

        assert.equal(view.one((el) => el.tag === 'a' && textOf(el) === 'Print all tags').attrs.href, '/guards/sites/phoenixpark/tags')
        assert.equal(view.all((el) => el.tag === 'a' && textOf(el) === 'Print its tag').length, 0, 'not on every row')

        await view.click(view.all((el) => el.tag === 'button' && textOf(el).startsWith('Basketball court'))[0])
        assert.equal(view.one((el) => el.tag === 'a' && textOf(el) === 'Print its tag').attrs.href, '/guards/sites/checkpoints/32/tag')
        view.unmount()
    })
})

describe('Places, finding a place', () => {
    it('waits half a second after typing, asks at once on Enter, and shows the place without placing a pin', async () => {
        searched = { results: [{ name: 'Portmore, Saint Catherine, Jamaica', lat: 17.95, lng: -76.88, bbox: [17.9, 18.0, -76.95, -76.85] }], message: null }
        const view = await open()
        const box = view.one((el) => el.tag === 'input' && el.attrs.type === 'search')

        await view.type(box, 'Portmore')
        assert.equal(asked.length, 0, 'not on the keystroke')
        await new Promise((resolve) => setTimeout(resolve, 560))
        await flush(nextTick)
        assert.equal(asked.at(-1), '/guards/places/search?q=Portmore')

        await view.click(button(view, 'Portmore, Saint Catherine, Jamaica'))
        assert.deepEqual({ ...map(view).props.flyTo, key: undefined }, { lat: 17.95, lng: -76.88, bbox: [17.9, 18.0, -76.95, -76.85], key: undefined })
        assert.equal(visits.length, 0, 'finding a place places nothing')

        await view.type(box, 'Spanish Town')
        fire(box, 'keydown', { key: 'Enter' })
        await flush(nextTick)
        assert.equal(asked.at(-1), '/guards/places/search?q=Spanish+Town')
        view.unmount()
    })

    it('says in one line when search cannot answer, and the map still places by a click', async () => {
        searched = { results: [], message: 'Place search is not answering right now. Click the map where it is, or paste its numbers.' }
        const view = await open()
        const box = view.one((el) => el.tag === 'input' && el.attrs.type === 'search')

        await view.type(box, 'Kingston')
        fire(box, 'keydown', { key: 'Enter' })
        await flush(nextTick)
        assert.match(view.text(), /Place search is not answering right now\. Click the map where it is, or paste its numbers\./)

        await view.click(button(view, 'Set its location'))
        map(view).emit('pick', { lat: 18.01, lng: -76.75 })
        await nextTick()
        await view.click(button(view, 'Save'))
        assert.deepEqual(visits.at(-1).data, { lat: 18.01, lng: -76.75, radius_m: 50 })
        view.unmount()
    })
})

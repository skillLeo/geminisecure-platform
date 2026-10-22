/**
 * The long-table window — `resources/js/composables/useWindowedRows.js` (M11 A3).
 *
 * What a browser does with it (every row reachable by scroll and by Tab, the whole table
 * printed, the page as tall as the whole table) is walked in a browser; see BASELINE.md's
 * M11 A3 section. Held here without one: a table of 250 rows or fewer is handed back exactly
 * as it came, and the column sample of a longer one takes, beyond the window, every field's
 * widest row, each once.
 */
import { after, before, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { h, toRaw } from 'vue'
import { mount } from './support/render.mjs'

const { useWindowedRows, widestRows, WINDOW_ABOVE, OVERSCAN, SAMPLE, FULLEST } = await import('../../resources/js/composables/useWindowedRows.js')

const saved = {}

before(() => {
    // A browser always has these; the in-memory renderer's window does not.
    for (const key of ['innerHeight', 'requestAnimationFrame', 'cancelAnimationFrame']) {
        saved[key] = globalThis[key]
    }

    globalThis.innerHeight = 900
    globalThis.requestAnimationFrame = () => 1
    globalThis.cancelAnimationFrame = () => {}
})

after(() => {
    for (const [key, value] of Object.entries(saved)) {
        globalThis[key] = value
    }
})

/** Mount a component that uses the window over `rows`, and hand back what it returned. */
async function windowOver(rows) {
    let held = null
    const Probe = {
        props: { rows: { type: Array, required: true } },
        setup(props) {
            held = useWindowedRows(() => props.rows)

            return () => h('div')
        },
    }
    const view = await mount(Probe, { rows })

    return { w: held, view }
}

const rowsOf = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, resident: `Resident ${i + 1}`, flag: null }))

describe('widestRows', () => {
    /** More plain rows than FULLEST, so which rows are named is the fields' doing, not the count's. */
    const plain = (n) => Array.from({ length: n }, (_, i) => ({ name: 'n', note: 'n', row: { reference: 'r' }, cells: ['c', 'c'], flag: false, gone: null, id: 1 + (i % 9) }))

    it('names, for every field, the row whose text in it is longest — nested fields and array cells too', () => {
        const list = plain(FULLEST + 40)
        list[45].name = 'Bartholomew'
        list[50].row = { reference: 'R-300000' }
        list[55].cells = ['c', 'a long cell']

        const named = widestRows(list)

        for (const index of [45, 50, 55]) {
            assert.ok(named.includes(index), `row ${index} holds the longest text in one field`)
        }
    })

    it('also names the rows with the most text in all: one cell can draw two fields side by side', () => {
        const list = plain(FULLEST + 40)
        list[10].name = 'Longest name'
        list[20].note = 'Longest note'
        // Longest in neither field, and the fullest row of all.
        list[58].name = 'A long name'
        list[58].note = 'A long note'

        const named = widestRows(list)

        assert.ok(named.includes(10) && named.includes(20))
        assert.ok(named.includes(58), 'the row carrying the most text is sampled although no field of it is the longest')
        assert.ok(named.length <= FULLEST + 8, `each row once, and no more than the fields and the fullest (${named.length})`)
        assert.deepEqual(named, [...named].sort((a, b) => a - b))
    })

    it('ignores what draws no text, keeps the earlier of equal rows, and names each row once', () => {
        assert.deepEqual(widestRows([{ a: 'xx', b: true, c: null }, { a: 'yy', b: false, c: undefined }]), [0, 1], 'both are among the fullest')
        assert.deepEqual(widestRows(plain(FULLEST + 5)).length, FULLEST, 'ties go to the earlier rows, the same every time')
        assert.deepEqual(widestRows([]), [])
    })
})

describe('useWindowedRows', () => {
    it(`hands a table of ${WINDOW_ABOVE} rows back exactly as it came: every row, no attributes, no stand-ins`, async () => {
        const rows = rowsOf(WINDOW_ABOVE)
        const { w, view } = await windowOver(rows)

        assert.equal(w.windowed, false)
        assert.equal(toRaw(w.visible), toRaw(rows), 'the very array the page was given')
        assert.deepEqual(w.rowAttrs(0), {})
        assert.equal(w.before, 0)
        assert.equal(w.after, 0)
        assert.equal(w.rowCount, null)

        view.unmount()
    })

    it('windows a longer one, and samples beyond the window: evenly, plus each field\'s widest row, once each', async () => {
        const rows = rowsOf(2000)
        rows[1500].resident = 'Resident with by far the longest name on the whole register'
        rows[40].flag = 'the widest flag, but inside the first screen'

        const { w, view } = await windowOver(rows)
        const firstEnd = Math.ceil(900 / 56) + 2 * OVERSCAN
        const drawn = toRaw(w.visible)
        const firstScreen = drawn.slice(0, firstEnd)
        const sample = drawn.slice(firstEnd)
        const ids = drawn.map((row) => row.id)

        assert.equal(w.windowed, true)
        assert.equal(w.rowCount, 2001)
        assert.deepEqual(firstScreen.map((row) => row.id), rows.slice(0, firstEnd).map((row) => row.id), 'the first screen and its overscan, in order')
        assert.equal(new Set(ids).size, ids.length, 'no row is drawn twice, so no key is used twice')
        assert.ok(sample.every((row) => row.id > firstEnd), 'the sample is only rows beyond the window')
        assert.ok(sample.some((row) => row.id === 1501), 'the row with the longest name is in the sample')
        assert.ok(sample.length >= SAMPLE && sample.length <= SAMPLE + 3 + FULLEST, `the even spread and the widest rows (${sample.length})`)
        assert.deepEqual(w.rowAttrs(0), { 'data-window-row': 0, 'aria-rowindex': 2 })
        assert.deepEqual(w.rowAttrs(firstEnd), { 'data-window-sample': '', 'aria-hidden': 'true' }, 'a sampled row is marked, and hidden from a screen reader')

        view.unmount()
    })
})

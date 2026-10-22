import { computed, onBeforeUnmount, reactive, ref, toRaw, watch } from 'vue'

/** The row count above which a table is windowed. At or below it, every row is drawn, as before. */
export const WINDOW_ABOVE = 250

/** Rows drawn beyond each edge of the screen, so a fast scroll or a Tab lands on a row that exists. */
export const OVERSCAN = 30

/** Rows spread evenly down the whole list, laid out once with the first screen to size the columns. */
export const SAMPLE = 60

/** What a row is taken to measure before any has been drawn: a board data-table row is 56 to 58px. */
const FIRST_GUESS = 56

/** The nearest ancestor that scrolls, or the window. The consoles scroll the page itself. */
function scrollerOf(element) {
    for (let node = element?.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
        const { overflowY } = getComputedStyle(node)

        if ((overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'overlay') && node.scrollHeight > node.clientHeight) {
            return node
        }
    }

    return window
}

/** How deep into a row's nested objects `widestRows` looks: a receipt register item is { kind, number, row: { … } }. */
const FIELD_DEPTH = 3

/** How many of the rows carrying the most text in all are sampled as well: see `widestRows`. */
export const FULLEST = 20

/**
 * The rows the column sample must not miss, by index. A column is as wide as its widest cell,
 * and a cell's width is mostly its text's, so:
 *
 *   - for every field the rows carry — `resident`, `row.reference`, a report's column 3 — the
 *     row whose text in that field is the longest;
 *   - and the FULLEST rows with the most text in all, because one cell can draw two fields
 *     side by side: the arrears register's "Last reminder" is the reminder's date AND the
 *     flag's pill, and the widest such cell (measured at 2,000 rows) was on a row that was the
 *     longest in neither field alone.
 *
 * Read once per list, not per scroll, and through `toRaw`, so that reading every field of
 * every row neither subscribes the sample to each of them nor pays a reactive proxy for each.
 */
export function widestRows(list) {
    const best = new Map()
    const totals = []

    const visit = (value, field, index, depth) => {
        if (value === null || value === undefined || typeof value === 'boolean') {
            return
        }

        if (typeof value === 'object') {
            if (depth < FIELD_DEPTH) {
                const raw = toRaw(value)

                for (const key of Object.keys(raw)) {
                    visit(raw[key], `${field}.${key}`, index, depth + 1)
                }
            }

            return
        }

        const length = String(value).length
        const held = best.get(field)

        totals[index] += length

        if (held === undefined || length > held.length) {
            best.set(field, { length, index })
        }
    }

    toRaw(list).forEach((item, index) => {
        totals[index] = 0
        visit(item, '', index, 0)
    })

    // The fullest rows; on a tie, the earlier row, so the choice is the same every time.
    const fullest = totals
        .map((total, index) => ({ total, index }))
        .sort((a, b) => b.total - a.total || a.index - b.index)
        .slice(0, FULLEST)
        .map((row) => row.index)

    return [...new Set([...best.values()].map((held) => held.index).concat(fullest))].sort((a, b) => a - b)
}

/** The part of the screen the scroller shows, in viewport coordinates. */
function viewportOf(scroller) {
    if (scroller === window) {
        return { top: 0, bottom: window.innerHeight }
    }

    const rect = scroller.getBoundingClientRect()

    return { top: Math.max(0, rect.top), bottom: Math.min(window.innerHeight, rect.bottom) }
}

/**
 * A table long enough to hurt draws only the rows on screen (M11 A3).
 *
 * WHY. The arrears register lists every unit that owes the estate anything, and
 * nothing bounds that but the estate: the morning after a charge run it can be
 * every unit billed, up to 2,000 on a 2,000-lot estate. The receipt register
 * draws its last 200 receipts and every gap in the sequence, however many; the
 * arrears ageing report is one row per unit that owes; the maintenance queue
 * only grows. A browser laying out thousands of rows spends seconds on it before
 * the page answers a click — measured, not guessed: the arrears register at
 * 2,000 rows, whole, kept the main thread blocked for 17 seconds with the CPU
 * slowed to a mid-range laptop's. The figures are in BASELINE.md's M11 A3 section.
 *
 * AT OR BELOW `WINDOW_ABOVE` ROWS NOTHING CHANGES. `visible` is then the very
 * array the page was given, there are no spacer rows and no extra attributes,
 * and the table is the one it always was, element for element. That is every
 * table on every seeded estate today: 163 households in arrears render whole.
 *
 * ABOVE IT, the rows on screen and `OVERSCAN` either side of them are drawn,
 * and one spacer row above and one below stand in for the rest at their
 * height, so the page is as tall as the whole table, the scrollbar says where
 * you are, and the page scrolls as it did. Each row is measured once drawn and
 * the measurement kept, so a spacer grows by exactly the height of the rows it
 * replaces and nothing on screen moves as they come and go; a row not yet drawn
 * is taken to be the average of those that have been.
 *
 * THE COLUMNS. A table sized to its content sizes each column to every row in
 * it, so a table of only the rows on screen would re-size its columns each time
 * a longer name scrolled in, and would size them for the wrong rows: the
 * receipt register's first screen is all receipts, and its gap rows — one cell
 * across nine columns — change how the browser shares the width out. So on the
 * first frame a sample from across the whole list is drawn after the first
 * screen, the columns are measured with it in and held at those widths, and the
 * sample is taken out again — all before the browser paints, so nobody sees it.
 * The sample is `SAMPLE` rows spread evenly down the list AND the rows most
 * likely to hold a column's widest cell (`widestRows`): an even spread alone
 * missed it on the arrears register at 2,000 rows, whose "Last reminder" column
 * came out 84 px narrower than the whole table draws it. A sample is still not
 * every row, so BASELINE.md's M11 A3 section measures every column of all four
 * tables against the whole table.
 *
 * WHAT A WINDOWED TABLE CANNOT DO, said plainly because a screen must not
 * pretend otherwise. The browser's own find (Ctrl+F) finds only the rows that
 * are drawn; the register's Export, the whole list as a file, is the way to
 * search all of it. Printing is not affected: the whole table is drawn for the
 * printer and windowed again afterwards. Tab still walks every row — the next
 * row is always drawn, and the browser scrolls it into view, which draws the
 * one after it.
 *
 * In the page:
 *
 *     const arrearsRows = useWindowedRows(() => props.rows)
 *
 *     <table class="data-table" :aria-rowcount="arrearsRows.rowCount">
 *       <tbody :ref="arrearsRows.bind">
 *         <tr v-if="arrearsRows.before" data-window-spacer="before" aria-hidden="true">
 *           <td :colspan="7" :style="arrearsRows.spacerStyle(arrearsRows.before)"></td>
 *         </tr>
 *         <tr v-for="(row, i) in arrearsRows.visible" :key="row.id" v-bind="arrearsRows.rowAttrs(i)">…</tr>
 *         <tr v-if="arrearsRows.after" data-window-spacer="after" aria-hidden="true">
 *           <td :colspan="7" :style="arrearsRows.spacerStyle(arrearsRows.after)"></td>
 *         </tr>
 *
 * One ITEM may draw more than one <tr> — a maintenance ticket's triage form is
 * a second row under it. Only the item's first row carries `rowAttrs`, and an
 * item is measured from its first row to the next item's, so the rows under it
 * count as its height.
 *
 * @param {() => Array} source  the rows, as a function so it follows the props
 * @param {object} [options]
 * @param {number} [options.above]  the row count above which the table is windowed
 */
export function useWindowedRows(source, options = {}) {
    const above = options.above ?? WINDOW_ABOVE

    const items = computed(() => source() ?? [])
    const printing = ref(false)
    const windowed = computed(() => items.value.length > above && !printing.value)

    const body = ref(null)
    const start = ref(0)
    const end = ref(0)

    /* True until the columns have been measured with the sample in. */
    const sampling = ref(false)

    /* Bumped whenever a kept height changes, so the spacers are summed again. */
    const measured = ref(0)

    /*
     * Measured heights by row index; 0 means not drawn yet. Plain rather than
     * reactive: `measured` is the one signal the spacers follow.
     */
    let heights = new Float64Array(0)
    let total = 0
    let count = 0
    let guess = FIRST_GUESS
    let scroller = null
    let frame = 0
    let frozen = null

    const heightAt = (index) => heights[index] || guess

    const sum = (from, to) => {
        let px = 0

        for (let index = from; index < to; index++) {
            px += heightAt(index)
        }

        return px
    }

    const firstEnd = (length) => Math.min(length, Math.ceil((typeof window === 'undefined' ? 900 : window.innerHeight) / FIRST_GUESS) + 2 * OVERSCAN)

    const forget = (length) => {
        heights = new Float64Array(length)
        total = 0
        count = 0
        measured.value++
    }

    /* The rows the column sample must not miss — once per list, and only once a list is windowed. */
    const widest = computed(() => widestRows(items.value))

    /**
     * The column sample: rows beyond the window, evenly spaced, and every field's widest row
     * among them. Only rows beyond the window, each once — those in it are already drawn, and a
     * row drawn twice would be two elements with one key.
     */
    const sample = computed(() => {
        const list = items.value
        const length = list.length
        const from = end.value
        const take = Math.min(SAMPLE, length - from)
        const indices = new Set()

        for (let k = 0; k < take; k++) {
            indices.add(from + Math.floor(((k + 0.5) * (length - from)) / take))
        }

        for (const index of widest.value) {
            if (index >= from) {
                indices.add(index)
            }
        }

        return [...indices].sort((a, b) => a - b).map((index) => list[index])
    })

    /** Measure the columns with the sample drawn, hold them, and take the sample out. */
    const freeze = () => {
        const table = body.value?.closest('table')
        const cells = table?.tHead?.rows[0]?.cells

        if (frozen || !cells || cells.length === 0) {
            return
        }

        const widths = Array.from(cells, (cell) => cell.getBoundingClientRect().width)

        Array.from(cells).forEach((cell, i) => {
            cell.style.width = `${widths[i]}px`
        })
        table.style.tableLayout = 'fixed'
        frozen = { table, cells: Array.from(cells) }
    }

    const unfreeze = () => {
        if (!frozen) {
            return
        }

        frozen.table.style.tableLayout = ''
        frozen.cells.forEach((cell) => {
            cell.style.width = ''
        })
        frozen = null
    }

    /*
     * Runs straight after Vue has put the first frame (window and sample) in the
     * DOM and before the browser paints it: reading a width lays the table out
     * there and then, and taking the sample out is one more render in the same
     * microtask checkpoint.
     */
    const sizeColumns = () => {
        // Not before the table is in the document: a detached table has no widths to read.
        if (!windowed.value || !sampling.value || !body.value?.isConnected) {
            return
        }

        freeze()
        sampling.value = false

        /*
         * Widths read while the web fonts are still arriving are the fallback
         * face's. The whole table would re-flow when they land, so these are
         * measured again then, once.
         */
        if (typeof document !== 'undefined' && document.fonts && document.fonts.status === 'loading') {
            const list = items.value

            document.fonts.ready.then(() => {
                if (items.value === list && windowed.value) {
                    resized()
                }
            })
        }
    }

    /** Read the drawn rows' heights into the kept ones. */
    const measure = () => {
        const tbody = body.value

        if (!windowed.value || !tbody || sampling.value) {
            return
        }

        const rows = tbody.querySelectorAll(':scope > tr[data-window-row]')

        if (rows.length === 0) {
            return
        }

        const tops = Array.from(rows, (row) => row.getBoundingClientRect().top)
        const spacerAfter = tbody.querySelector(':scope > tr[data-window-spacer="after"]')
        const bottom = spacerAfter ? spacerAfter.getBoundingClientRect().top : tbody.getBoundingClientRect().bottom
        let changed = false

        rows.forEach((row, k) => {
            const index = Number(row.dataset.windowRow)
            const height = (k + 1 < rows.length ? tops[k + 1] : bottom) - tops[k]

            if (!(height > 0) || !(index >= 0) || index >= heights.length || Math.abs(heights[index] - height) <= 0.5) {
                return
            }

            if (heights[index]) {
                total -= heights[index]
            } else {
                count++
            }

            heights[index] = height
            total += height
            changed = true
        })

        if (changed) {
            guess = total / count
            measured.value++
        }
    }

    /** Which rows the screen shows now, plus the overscan either side. */
    const place = () => {
        const tbody = body.value
        const length = items.value.length

        if (!windowed.value || !tbody || !scroller || sampling.value) {
            return
        }

        const top = tbody.getBoundingClientRect().top
        const view = viewportOf(scroller)
        const from = view.top - top
        const to = view.bottom - top
        let y = 0
        let index = 0

        while (index < length && y + heightAt(index) <= from) {
            y += heightAt(index)
            index++
        }

        const first = Math.min(index, length - 1)

        while (index < length && y < to) {
            y += heightAt(index)
            index++
        }

        const nextStart = Math.max(0, first - OVERSCAN)
        const nextEnd = Math.min(length, Math.max(index, first + 1) + OVERSCAN)

        if (nextStart !== start.value) {
            start.value = nextStart
        }

        if (nextEnd !== end.value) {
            end.value = nextEnd
        }
    }

    const update = () => {
        frame = 0

        if (!windowed.value) {
            unfreeze()

            return
        }

        listen()
        measure()
        place()
    }

    const schedule = () => {
        if (!frame && typeof window !== 'undefined') {
            frame = window.requestAnimationFrame(update)
        }
    }

    /* A different width wraps text differently, so every kept height and column width may be wrong. */
    const resized = () => {
        unfreeze()
        forget(items.value.length)
        sampling.value = windowed.value
        schedule()
    }

    /*
     * Printing draws every row. The flag flips inside `beforeprint`, and Vue's
     * update runs in the microtask checkpoint straight after that listener,
     * before the browser lays the page out for paper. The columns are let go at
     * once as well: widths held for a 1440px screen would not fit a sheet.
     */
    const beforePrint = () => {
        unfreeze()
        printing.value = true
    }

    const afterPrint = () => {
        printing.value = false
        sampling.value = windowed.value
    }

    let listening = false

    /* Once the table is in the document, so the scroller found is the one it is really in. */
    const listen = () => {
        if (listening || typeof window === 'undefined' || !body.value?.isConnected) {
            return
        }

        listening = true
        scroller = scrollerOf(body.value)
        scroller.addEventListener('scroll', schedule, { passive: true })
        window.addEventListener('resize', resized, { passive: true })
        window.addEventListener('beforeprint', beforePrint)
        window.addEventListener('afterprint', afterPrint)
    }

    const stop = () => {
        if (!listening) {
            return
        }

        listening = false
        scroller?.removeEventListener('scroll', schedule)
        window.removeEventListener('resize', resized)
        window.removeEventListener('beforeprint', beforePrint)
        window.removeEventListener('afterprint', afterPrint)

        if (frame) {
            window.cancelAnimationFrame(frame)
            frame = 0
        }
    }

    /* A new list, from a filter or a reload, is new rows at every index: forget the old heights, keep the average. */
    const restart = (length) => {
        unfreeze()
        forget(length)
        start.value = 0
        end.value = firstEnd(length)
        sampling.value = length > above
    }

    restart(items.value.length)

    watch(
        () => items.value,
        (list) => {
            restart(list.length)
            schedule()
        }
    )

    /* The columns are sized as soon as the DOM has the sample in it. */
    watch([sampling, windowed, body], sizeColumns, { flush: 'post' })

    /* Every change to what is drawn is measured once the DOM has it. */
    watch([start, end, measured, windowed, sampling], schedule, { flush: 'post' })

    onBeforeUnmount(stop)

    const visible = computed(() => {
        if (!windowed.value) {
            return items.value
        }

        const slice = items.value.slice(start.value, end.value)

        return sampling.value ? slice.concat(sample.value) : slice
    })

    const before = computed(() => {
        void measured.value

        return windowed.value ? Math.round(sum(0, start.value) * 100) / 100 : 0
    })

    const after = computed(() => {
        void measured.value

        return windowed.value ? Math.round(sum(end.value, items.value.length) * 100) / 100 : 0
    })

    return reactive({
        windowed,
        visible,
        before,
        after,

        /** Where `visible` starts in the whole list. */
        offset: computed(() => (windowed.value ? start.value : 0)),

        /** For the <table>: how many rows it has, header included; only while some are not drawn. */
        rowCount: computed(() => (windowed.value ? items.value.length + 1 : null)),

        /** The function ref for the <tbody>. */
        bind: (element) => {
            body.value = element

            if (element) {
                sizeColumns()
                schedule()
            }
        },

        /**
         * The attributes an item's first row carries: its index, and its row
         * number for a screen reader. Nothing at all when the table is whole. A
         * sampled row, drawn only for the first frame's column widths, is marked
         * as one and hidden from a screen reader.
         */
        rowAttrs: (i) => {
            if (!windowed.value) {
                return {}
            }

            if (start.value + i >= end.value) {
                return { 'data-window-sample': '', 'aria-hidden': 'true' }
            }

            return { 'data-window-row': start.value + i, 'aria-rowindex': start.value + i + 2 }
        },

        /**
         * A spacer cell's style. Inline, so no board rule can give it padding
         * or a border and make it taller than the rows it stands in for.
         */
        spacerStyle: (px) => ({ height: `${px}px`, padding: '0', border: '0' }),
    })
}

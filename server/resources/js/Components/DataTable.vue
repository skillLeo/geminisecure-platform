<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * EVERY TABLE IN BOTH CONSOLES, ONE COMPONENT (M32 F5).
 *
 * The table is the page's own `.data-table`, styled by the sheet it sits in; this adds what no table had: a heading
 * that sorts (once ascending, twice descending, three times back to the table's own order), the arrow that says which
 * way, and the pager under it — "Showing 1 to 25 of 1,998", the page numbers, previous and next, and 25, 50, 100 or
 * 200 to a page. The server orders and pages the whole set (`App\Support\TableQuery`); every control here is a link to
 * the same address with one word changed, so the search and every filter on the page stay as they are, a link can be
 * sent, and a refresh keeps the place.
 *
 * AT 1280 THE PAGE DOES NOT SCROLL SIDEWAYS: a table wider than its card scrolls inside it.
 *
 *   <DataTable :columns="[{ key: 'name', label: 'Name', sortable: true }, …]" :rows="rows" :table="table">
 *       <template #cell-name="{ row }">…</template>
 *       <template #empty>…</template>
 *   </DataTable>
 */
const props = defineProps({
    /**
     * `{ key, label, sortable?, class?, cellClass?, title? }` in the order they are drawn. `class` is the heading's and
     * the cells'; `cellClass`, when given, is the cells' alone — a board's style for a figure, not for its heading.
     */
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** `TableQuery::meta()`. */
    table: { type: Object, required: true },
    rowKey: { type: String, default: 'id' },
    /** What the rows are called in the pager's sentence: "Showing 1 to 25 of 1,998 residents". */
    noun: { type: String, default: '' },
    caption: { type: String, default: '' },
    /** A row's own attributes — a class, a title — as `(row) => ({ … })`. */
    rowAttrs: { type: Function, default: null },
    /**
     * A row that is not the table's columns — a gap in a sequence, drawn across them — as `(row) => slotName | null`.
     * The named slot draws the row's cells itself, and is handed `{ row, span }`: how many columns it has to fill.
     */
    rowSlot: { type: Function, default: null },
    /**
     * Whether a row has a row of its own beneath it — a panel opened under the one it belongs to — as `(row) => bool`.
     * The `after-row` slot draws it, handed `{ row, span }`.
     */
    rowAfter: { type: Function, default: null },
})

const page = usePage()

/** Where this table's words sit in the address: `sort`, or `gate_sort` on a page that carries more than one table. */
const word = (name) => `${props.table.prefix ?? ''}${name}`

/** This page's address with some of the table's words changed; a null removes one. */
const hrefWith = (changes) => {
    const url = new URL(page.url, 'http://local')

    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === undefined || value === '') {
            url.searchParams.delete(word(key))
        } else {
            url.searchParams.set(word(key), String(value))
        }
    }

    // Page 1 is what the address means without one.
    if (url.searchParams.get(word('page')) === '1') url.searchParams.delete(word('page'))

    const query = url.searchParams.toString()

    return url.pathname + (query ? `?${query}` : '')
}

const orderedBy = computed(() => props.table.sort ?? props.table.default?.sort ?? null)
const orderedDir = computed(() => props.table.dir ?? props.table.default?.dir ?? 'asc')
const sortable = (column) => column.sortable && (props.table.sortable ?? []).includes(column.key)

/**
 * Once ascending, twice descending, a third time the table's own order again. A table's default order counts as the
 * first click on its column, so from it the next one reverses and the one after clears.
 */
const flip = (dir) => (dir === 'asc' ? 'desc' : 'asc')
const sortedAs = (column, dir) => hrefWith({ sort: column.key, dir, page: null })
const tableOwnOrder = () => hrefWith({ sort: null, dir: null, page: null })

const sortHref = (column) => {
    // Another column: this one, ascending.
    if (orderedBy.value !== column.key) {
        return sortedAs(column, 'asc')
    }

    // The table's own order is this column, and nothing was asked: that order was the first click, so reverse it.
    if (props.table.sort === null || props.table.sort === undefined) {
        return sortedAs(column, flip(orderedDir.value))
    }

    // The default column, asked for: the same way as its default reverses it; reversed already goes back.
    if (props.table.default?.sort === column.key) {
        return props.table.dir === props.table.default.dir ? sortedAs(column, flip(props.table.dir)) : tableOwnOrder()
    }

    // Any other column: ascending, then descending, then the table's own order.
    return props.table.dir === 'asc' ? sortedAs(column, 'desc') : tableOwnOrder()
}

const ariaSort = (column) => (orderedBy.value !== column.key ? 'none' : orderedDir.value === 'asc' ? 'ascending' : 'descending')

const sortTitle = (column) =>
    orderedBy.value === column.key
        ? `Sorted by ${column.label.toLowerCase()}, ${orderedDir.value === 'asc' ? 'ascending' : 'descending'}. Click to change.`
        : `Sort by ${column.label.toLowerCase()}`

/** The page numbers to draw: the first, the last, and two either side of this one, with a gap where some are left out. */
const pages = computed(() => {
    const last = props.table.last ?? 1
    const here = props.table.page ?? 1
    const wanted = new Set([1, last, here - 2, here - 1, here, here + 1, here + 2].filter((n) => n >= 1 && n <= last))
    const sorted = [...wanted].sort((a, b) => a - b)
    const drawn = []

    sorted.forEach((n, i) => {
        if (i > 0 && n - sorted[i - 1] > 1) drawn.push({ gap: true, key: `gap-${n}` })
        drawn.push({ gap: false, n, key: `page-${n}` })
    })

    return drawn
})

const count = (n) => Number(n ?? 0).toLocaleString('en-US')

const showing = computed(() =>
    props.table.total === 0 || props.table.from === null
        ? `Nothing to show${props.table.page > 1 ? ' on this page' : ''}. ${count(props.table.total)} in all.`
        : `Showing ${count(props.table.from)} to ${count(props.table.to)} of ${count(props.table.total)}${props.noun ? ` ${props.noun}` : ''}`
)

/** The page sizes a table offers. */
const sizes = computed(() => props.table.sizes ?? [25, 50, 100, 200])

/**
 * "Rows per page" only where there is a page to size (M53 §4.11): three rows under 25 / 50 / 100 / 200 is four
 * buttons that do nothing. Shown once the table holds more rows than its smallest page.
 */
const offersSizes = computed(() => (props.table.total ?? 0) > Math.min(...sizes.value))

/** A new page size keeps the first row on screen on screen: the page it falls on at the new size. */
const sizeHref = (size) => {
    const first = props.table.from ?? 1

    return hrefWith({ per: size, page: Math.floor((first - 1) / size) + 1 })
}
</script>

<template>
    <div class="dt">
        <div class="dt-scroll">
            <table class="data-table">
                <caption v-if="caption" class="dt-caption">{{ caption }}</caption>
                <thead>
                    <tr>
                        <th v-for="column in columns" :key="column.key" :class="column.class" :aria-sort="sortable(column) ? ariaSort(column) : undefined" :title="column.title">
                            <Link v-if="sortable(column)" :href="sortHref(column)" class="dt-sort" :title="sortTitle(column)" preserve-scroll>
                                <span>{{ column.label }}</span>
                                <svg v-if="orderedBy === column.key" class="dt-arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <polyline
                                        :points="orderedDir === 'asc' ? '6 15 12 9 18 15' : '6 9 12 15 18 9'"
                                        stroke="currentColor"
                                        stroke-width="2.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </Link>
                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row[rowKey]">
                        <tr v-bind="rowAttrs ? rowAttrs(row) : {}">
                            <slot v-if="rowSlot && rowSlot(row)" :name="rowSlot(row)" :row="row" :span="columns.length" />
                            <template v-else>
                                <td v-for="column in columns" :key="column.key" :class="column.cellClass ?? column.class">
                                    <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] ?? '—' }}</slot>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="rowAfter && rowAfter(row)" class="dt-after">
                            <slot name="after-row" :row="row" :span="columns.length" />
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <slot v-if="rows.length === 0" name="empty" />

        <nav class="dt-pager" aria-label="Pages">
            <span class="dt-showing">{{ showing }}</span>

            <span v-if="(table.last ?? 1) > 1" class="dt-pages">
                <Link v-if="table.page > 1" :href="hrefWith({ page: table.page - 1 })" class="dt-page" rel="prev" preserve-scroll>Previous</Link>
                <template v-for="item in pages" :key="item.key">
                    <span v-if="item.gap" class="dt-gap" aria-hidden="true">…</span>
                    <Link
                        v-else
                        :href="hrefWith({ page: item.n })"
                        class="dt-page"
                        :class="{ active: item.n === table.page }"
                        :aria-current="item.n === table.page ? 'page' : undefined"
                        preserve-scroll
                    >{{ item.n }}</Link>
                </template>
                <Link v-if="table.page < table.last" :href="hrefWith({ page: table.page + 1 })" class="dt-page" rel="next" preserve-scroll>Next</Link>
            </span>

            <span v-if="offersSizes" class="dt-sizes" role="group" aria-label="Rows per page">
                <span>Rows per page</span>
                <Link
                    v-for="size in sizes"
                    :key="size"
                    :href="sizeHref(size)"
                    class="dt-page"
                    :class="{ active: size === table.per }"
                    :aria-current="size === table.per ? 'true' : undefined"
                    preserve-scroll
                >{{ size }}</Link>
            </span>
        </nav>
    </div>
</template>

<style scoped>
/* The page's own card holds it; a table wider than the card scrolls here, never the page (§8.2). */
.dt-scroll {
    max-width: 100%;
    overflow-x: auto;
}

.dt-caption {
    caption-side: top;
    text-align: left;
    padding: 0 0 8px;
    font-size: 12px;
    color: var(--slate-600);
}

.dt-sort {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: inherit;
    text-decoration: none;
}

.dt-arrow {
    width: 12px;
    height: 12px;
    flex: 0 0 auto;
}

.dt-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px 18px;
    margin-top: 14px;
    font-size: 12px;
    color: var(--slate-600);
}

.dt-pages,
.dt-sizes {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

.dt-sizes {
    margin-left: auto;
}

/* The filter chips' own look (`.f-chip`), so a page number is a control the consoles already draw. */
.dt-page {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-600);
    background: var(--navy-100);
    padding: 6px 11px;
    border-radius: 20px;
    text-decoration: none;
}

.dt-page.active {
    background: var(--navy-600);
    color: var(--white);
}

.dt-gap {
    color: var(--slate-600);
}
</style>

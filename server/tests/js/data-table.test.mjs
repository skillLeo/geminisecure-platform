/**
 * The one table component (M32 F5) — `Components/DataTable.vue`.
 *
 *   A heading sorts once ascending, twice descending, and a third time goes back to the table's own order; the sorted
 *   column shows which way. Every control is this page's address with one word changed, so the search and every
 *   filter stay. The pager says where in the whole set this page is, and a new page size keeps the first row in sight.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: DataTable } = await import('../../resources/js/Components/DataTable.vue')

const COLUMNS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'lot', label: 'Lot', sortable: true },
    { key: 'added', label: 'Added', sortable: true },
    { key: 'note', label: 'Note' },
]

const TABLE = (over = {}) => ({
    sort: null,
    dir: null,
    default: { sort: 'added', dir: 'desc' },
    sortable: ['name', 'lot', 'added'],
    page: 2,
    last: 80,
    per: 25,
    sizes: [25, 50, 100, 200],
    from: 26,
    to: 50,
    total: 1998,
    q: 'soft',
    ...over,
})

const ROWS = [
    { id: 1, name: 'Hassam Example', lot: 'Lot 413', added: 'Oct 3, 2026', note: 'x' },
    { id: 2, name: 'Shauna Morgan', lot: 'Lot 414', added: 'Oct 2, 2026', note: null },
]

const open = async (table, url = '/estate/phoenixpark/residents?q=soft&phase=Phase%202&page=2') => {
    resetInertia({}, url)

    return mount(DataTable, { columns: COLUMNS, rows: ROWS, table, noun: 'residents' })
}

const heading = (view, label) => view.one((el) => el.tag === 'th' && textOf(el).startsWith(label))
const linkIn = (el) => (el.children ?? []).find((child) => child.tag === 'a') ?? null
const query = (href) => Object.fromEntries(new URL(href, 'http://local').searchParams)

describe('M32 F5 — one table, sorted and paged by the server, its state in the address', () => {
    it('shows the table\'s own order on its column, and from there a click reverses it, keeping the search and the filters', async () => {
        const view = await open(TABLE())

        assert.equal(heading(view, 'Added').attrs['aria-sort'], 'descending', 'the default order is shown, not hidden')
        assert.ok(view.one(byClass('dt-arrow')), 'with an arrow')
        assert.equal(heading(view, 'Name').attrs['aria-sort'], 'none')
        assert.equal(linkIn(heading(view, 'Note')), null, 'a column with nothing to sort by is plain text')

        assert.deepEqual(query(linkIn(heading(view, 'Added')).attrs.href), { q: 'soft', phase: 'Phase 2', sort: 'added', dir: 'asc' })
        assert.deepEqual(query(linkIn(heading(view, 'Name')).attrs.href), { q: 'soft', phase: 'Phase 2', sort: 'name', dir: 'asc' }, 'sorting starts again on page 1')

        view.unmount()
    })

    it('goes ascending, descending, and back to the table\'s own order on a column', async () => {
        const asc = await open(TABLE({ sort: 'name', dir: 'asc' }))
        assert.equal(heading(asc, 'Name').attrs['aria-sort'], 'ascending')
        assert.deepEqual(query(linkIn(heading(asc, 'Name')).attrs.href), { q: 'soft', phase: 'Phase 2', sort: 'name', dir: 'desc' })
        asc.unmount()

        const desc = await open(TABLE({ sort: 'name', dir: 'desc' }))
        assert.deepEqual(query(linkIn(heading(desc, 'Name')).attrs.href), { q: 'soft', phase: 'Phase 2' }, 'the third click clears the sort')
        desc.unmount()

        // The default column, reversed by its first click: the next goes back to the table's own order.
        const reversed = await open(TABLE({ sort: 'added', dir: 'asc' }))
        assert.deepEqual(query(linkIn(heading(reversed, 'Added')).attrs.href), { q: 'soft', phase: 'Phase 2' })
        reversed.unmount()
    })

    it('says where in the whole set this page is, and pages by number, previous and next', async () => {
        const view = await open(TABLE())
        const pager = textOf(view.one(byClass('dt-pager')))

        assert.match(pager, /Showing 26 to 50 of 1,998 residents/)

        const pages = view.all((el) => el.tag === 'a' && el.className.includes('dt-page') && el.parent?.className === 'dt-pages')
        assert.deepEqual(pages.map((el) => textOf(el)), ['Previous', '1', '2', '3', '4', '80', 'Next'])
        assert.equal(pages.find((el) => textOf(el) === '2').attrs['aria-current'], 'page')
        assert.deepEqual(query(pages.find((el) => textOf(el) === 'Next').attrs.href), { q: 'soft', phase: 'Phase 2', page: '3' })
        assert.deepEqual(query(pages.find((el) => textOf(el) === 'Previous').attrs.href), { q: 'soft', phase: 'Phase 2' }, 'page 1 is the address without one')
        assert.ok(view.one((el) => el.className === 'dt-gap'), 'the pages left out are marked')

        view.unmount()
    })

    it('keeps the first row in sight when the page size changes', async () => {
        const view = await open(TABLE({ page: 6, from: 126, to: 150 }), '/estate/phoenixpark/residents?q=soft&page=6')
        const size = (n) => view.one((el) => el.tag === 'a' && el.parent?.className === 'dt-sizes' && textOf(el) === String(n))

        // Row 126 is on page 3 at 50, page 2 at 100, page 1 at 200.
        assert.deepEqual(query(size(50).attrs.href), { q: 'soft', page: '3', per: '50' })
        assert.deepEqual(query(size(100).attrs.href), { q: 'soft', page: '2', per: '100' })
        assert.deepEqual(query(size(200).attrs.href), { q: 'soft', per: '200' })
        assert.equal(size(25).attrs['aria-current'], 'true')

        view.unmount()
    })

    it('says a page with nothing on it is empty, and how many there are in all', async () => {
        const view = await open(TABLE({ page: 99, from: null, to: null }))

        // No em dash (M53 Law 3): two short sentences.
        assert.match(textOf(view.one(byClass('dt-pager'))), /Nothing to show on this page\. 1,998 in all\./)
        view.unmount()
    })

    it('offers no page sizes when every row already fits on the smallest page (M53 §4.11)', async () => {
        const view = await open(TABLE({ total: 3, from: 1, to: 3, page: 1, last: 1 }))

        assert.equal(view.all((el) => el.className === 'dt-sizes').length, 0, 'no "Rows per page" under three rows')
        view.unmount()
    })
})

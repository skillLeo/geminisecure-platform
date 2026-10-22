/**
 * What a search or a filter keeps of a table (M32 F5) — `lib/tableParams.js`.
 *
 *   A new search keeps the table's order and its page size and starts again at page one; the page it was on belongs
 *   to the old search. A page carrying more than one table writes each table's words under its own prefix.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { keptByTable } from '../../resources/js/lib/tableParams.js'

describe('M32 F5 — what a search or a filter keeps of a table', () => {
    it('keeps the order and a page size that is not the table\'s own, and never the page', () => {
        assert.deepEqual(keptByTable({ sort: 'name', dir: 'desc', page: 3, per: 50, default: { per: 25 } }), { sort: 'name', dir: 'desc', per: 50 })
        assert.deepEqual(keptByTable({ sort: null, dir: null, page: 4, per: 25, default: { per: 25 } }), {}, 'the table\'s own order and size say nothing')
        assert.deepEqual(keptByTable({ sort: null, page: 2, per: 50, default: { per: 50 } }), {}, 'a register read fifty to a page by default says nothing about it')
        assert.deepEqual(keptByTable({ sort: 'lot', page: 2, per: 25, default: { per: 25 } }), { sort: 'lot', dir: 'asc' }, 'an order without a direction is ascending')
    })

    it('writes a table\'s words under its own prefix on a page that carries more than one', () => {
        assert.deepEqual(keptByTable({ prefix: 'gate_', sort: 'when', dir: 'asc', page: 2, per: 100, default: { per: 25 } }), {
            gate_sort: 'when',
            gate_dir: 'asc',
            gate_per: 100,
        })
    })

    it('keeps nothing for a table that is not there', () => {
        assert.deepEqual(keptByTable(null), {})
        assert.deepEqual(keptByTable(undefined), {})
    })
})

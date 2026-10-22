import { addOlderPage, mergeById, olderCursor, olderFor, type OlderPages } from '../olderPages'

type Row = { id: number; title?: string }
const rows = (...ids: number[]): Row[] => ids.map((id) => ({ id }))

describe('a long list, a page at a time', () => {
  it('asks with the first page\'s cursor, then with each page\'s own, and stops when the server says nothing is older', () => {
    expect(olderCursor(null, 'c1')).toBe('c1')
    expect(olderCursor(null, null)).toBeNull()
    expect(olderCursor(null, undefined)).toBeNull() // an answer from a server that does not page yet
    expect(olderCursor(null, '  ')).toBeNull()

    const one = addOlderPage({ firstNext: 'c1', first: rows(9, 8), older: null, asked: 'c1', page: { items: rows(7, 6), next_before: 'c2' } })
    expect(one).toEqual({ after: 'c1', items: rows(7, 6), next: 'c2' })
    expect(olderCursor(one, 'c1')).toBe('c2')

    const two = addOlderPage({ firstNext: 'c1', first: rows(9, 8), older: one, asked: 'c2', page: { items: rows(5), next_before: null } })
    expect(two).toEqual({ after: 'c1', items: rows(7, 6, 5), next: null })
    expect(olderCursor(two, 'c1')).toBeNull()
  })

  it('never draws a row twice: a page that overlaps what is already held adds only what is new', () => {
    const older: OlderPages<Row> = { after: 'c1', items: rows(7, 6), next: 'c2' }
    expect(addOlderPage({ firstNext: 'c1', first: rows(9, 8), older, asked: 'c2', page: { items: rows(8, 6, 5), next_before: 'c3' } }).items).toEqual(rows(7, 6, 5))
    expect(mergeById(rows(9, 8), rows(8, 7))).toEqual(rows(9, 8, 7))
    // The first page's copy is the fresher one, and wins.
    expect(mergeById([{ id: 8, title: 'new' }], [{ id: 8, title: 'old' }])).toEqual([{ id: 8, title: 'new' }])
  })

  it('a page that brings nothing new ENDS the chain: "Show older" is never a button that does nothing', () => {
    // A server that ignored `before` answers with the first page again, cursor and all.
    expect(addOlderPage({ firstNext: 'c1', first: rows(9, 8), older: null, asked: 'c1', page: { items: rows(9, 8), next_before: 'c1' } })).toEqual({ after: 'c1', items: [], next: null })
    // New rows, but a cursor that did not move: what came is kept, and the chain ends.
    expect(addOlderPage({ firstNext: 'c1', first: rows(9), older: null, asked: 'c1', page: { items: rows(8), next_before: 'c1' } })).toEqual({ after: 'c1', items: rows(8), next: null })
    expect(addOlderPage({ firstNext: 'c1', first: rows(9), older: null, asked: 'c1', page: { items: 'nope' as never } })).toEqual({ after: 'c1', items: [], next: null })
  })

  it('a saved chain counts only for the first page it was started from: a new row at the top moves the end, and the chain is fetched again', () => {
    const saved: OlderPages<Row> = { after: 'c1', items: rows(7, 6), next: null }
    expect(olderFor(saved, 'c1')).toBe(saved)
    expect(olderFor(saved, 'c1-moved')).toBeNull()
    expect(olderFor(saved, null)).toBeNull()
    expect(olderFor(null, 'c1')).toBeNull()
    expect(olderFor({ after: 'c1', items: 'broken' as never, next: null }, 'c1')).toBeNull()
  })
})

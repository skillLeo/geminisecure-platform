/**
 * A LONG LIST, A PAGE AT A TIME — the phone's half of the API's `before` / `next_before`.
 *
 * The server's long lists (a household's requests, the estate's meetings) answer with their newest page
 * and a `next_before`; sending that back as `before` gets the page before it. The FIRST page is the one
 * every other screen already reads and caches. What is fetched beyond it is kept as ONE chain:
 *
 *   after   the first page's `next_before` the chain was started from
 *   items   everything fetched beyond the first page, in the server's order
 *   next    where the next older page starts — null once the server has said there is nothing older
 *
 * Three things it must get right:
 *   - THE CHAIN BELONGS TO ONE FIRST PAGE. When a new row arrives at the top, the first page ends somewhere
 *     else and rows slide off its end. A chain started from the OLD end would leave a gap nobody could see,
 *     so a chain whose `after` is not today's `next_before` is not used (`olderFor`); it is fetched again.
 *   - NOTHING IS DRAWN TWICE. Pages are merged by id.
 *   - A BUTTON THAT DOES NOTHING IS A DEAD CONTROL. A page that brings nothing new — a server that ignored
 *     the cursor, a cursor that did not move — ends the chain instead of offering "Show older" for ever.
 *
 * Pure: no React, no storage. The caller keeps the chain (useOfflineCache) and does the asking.
 */

type WithId = { id: number | string }

export type OlderPages<T> = { after: string; items: T[]; next: string | null }

/** What the server's page looks like to this file. */
export type PageAnswer<T> = { items: readonly T[]; next_before?: string | null }

const usable = (cursor: string | null | undefined): cursor is string => typeof cursor === 'string' && cursor.trim() !== ''

/** The saved chain — only if it was started from where TODAY's first page ends. */
export function olderFor<T>(saved: OlderPages<T> | null | undefined, firstNext: string | null | undefined): OlderPages<T> | null {
  return usable(firstNext) && saved && saved.after === firstNext && Array.isArray(saved.items) ? saved : null
}

/** The cursor to ask with next — or null when there is nothing older to ask for. */
export function olderCursor<T>(older: OlderPages<T> | null, firstNext: string | null | undefined): string | null {
  if (older) return usable(older.next) ? older.next : null
  return usable(firstNext) ? firstNext : null
}

/** The first page and the chain as one list: the server's order, each row once (the first page's copy wins — it is the fresher). */
export function mergeById<T extends WithId>(first: readonly T[], older: readonly T[]): T[] {
  const seen = new Set(first.map((row) => String(row.id)))
  const out = [...first]
  for (const row of older) {
    const key = String(row.id)
    if (seen.has(key)) continue
    seen.add(key)
    out.push(row)
  }
  return out
}

/** The chain after one more page has arrived. `asked` is the cursor that page was asked for with. */
export function addOlderPage<T extends WithId>(input: { firstNext: string; first: readonly T[]; older: OlderPages<T> | null; asked: string; page: PageAnswer<T> }): OlderPages<T> {
  const held = input.older?.items ?? []
  const known = new Set([...input.first, ...held].map((row) => String(row.id)))
  const fresh = (Array.isArray(input.page.items) ? input.page.items : []).filter((row) => !known.has(String(row.id)))
  // Nothing new came: the chain ends here, whatever cursor came with it.
  if (fresh.length === 0) return { after: input.firstNext, items: held, next: null }
  const next = usable(input.page.next_before) && input.page.next_before !== input.asked ? input.page.next_before : null
  return { after: input.firstNext, items: [...held, ...fresh], next }
}

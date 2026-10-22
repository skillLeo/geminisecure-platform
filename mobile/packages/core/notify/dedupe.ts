/**
 * One banner for one thing — pure, so it can be tested.
 *
 * Once the server pushes the same event can reach an OPEN app twice: the remote push arrives,
 * and a few seconds or minutes later the local digest notices the same change in its own lists. Whichever
 * came first is shown; the other is dropped. "The same thing" is the payload's (type, id) — exactly what
 * the server coalesces on — inside a window a little longer than the slowest list refresh (20 minutes in
 * data-light mode).
 *
 * AND NEVER ONE BANNER FOR TWO THINGS (B134). One alert is several events under one (type, id): the raise,
 * and minutes later `kind: dispatched` — "You are dispatched", the one push built to tell an officer they were
 * sent. A ticket or a booking moves more than once. Collapsing on (type, id) alone dropped the dispatch for
 * thirty minutes after the local raise banner, and a ticket's "Completed." after its "Assigned". So:
 *
 *   - the KIND is part of the key when the payload has one (the server and the local digest carry the same
 *     kinds: the alert's kind on a raise, `dispatched`, a request's kind);
 *   - and when both sides say what they will show, the WORDS decide too: the local digest writes exactly the
 *     server's title and body for the same event (a ticket's number and new status, a booking's answer), so
 *     the same words are the same event, and different words are a different one, which is shown.
 *
 *   - A second REMOTE push for the same (type, id) is NOT dropped here: the server has already decided it
 *     is news (its own coalescing window passed, or it is an emergency). Only local-after-remote and
 *     remote-after-local are duplicates.
 *   - A payload without a usable type and id is never deduped: better two banners than none. When the words
 *     of either side are not known, the key alone decides, as it always did.
 *
 * Nothing is kept but the key, a time and the words, in memory; it is cleared at sign-out with the rest.
 */

import type { Payload } from './links'

export const DEDUPE_WINDOW_MS = 30 * 60_000

const SLUG = /^[A-Za-z0-9_-]{1,40}$/

/** `type:id`, or `type:id:kind` when the payload has a kind — or null when the payload cannot be told apart from another of its type. */
export function dedupeKey(payload: Payload | null | undefined): string | null {
  if (!payload || typeof payload.type !== 'string' || !SLUG.test(payload.type)) return null
  const id = typeof payload.id === 'number' && Number.isFinite(payload.id) ? String(Math.trunc(payload.id)) : typeof payload.id === 'string' && SLUG.test(payload.id) ? payload.id : null
  if (id === null) return null
  const kind = typeof payload.kind === 'string' && SLUG.test(payload.kind) ? payload.kind : null
  return kind === null ? `${payload.type}:${id}` : `${payload.type}:${id}:${kind}`
}

/** What a banner says. Either half may be missing; only strings are read. */
export type Words = { title?: unknown; body?: unknown } | null | undefined

const wordsOf = (words: Words): string | null => {
  const title = typeof words?.title === 'string' ? words.title.trim() : ''
  const body = typeof words?.body === 'string' ? words.body.trim() : ''
  return title || body ? `${title}\n${body}` : null
}

export type Dedupe = {
  /** A REMOTE push arrived while the app is open. True = show its banner; false = the local notifier already showed this same event. */
  remoteArrived(payload: Payload | null | undefined, words?: Words): boolean
  /** The local notifier wants to raise a banner. True = go ahead (and it is remembered); false = a push already showed this same event. */
  localAllowed(payload: Payload | null | undefined, words?: Words): boolean
  clear(): void
}

type Seen = { at: number; words: string | null }

export function createDedupe(clock: () => number = Date.now, windowMs: number = DEDUPE_WINDOW_MS): Dedupe {
  const local = new Map<string, Seen>()
  const remote = new Map<string, Seen>()

  /** The other side showed THIS event inside the window: the same key, and the same words when both are known. */
  const sameEvent = (seen: Map<string, Seen>, key: string, words: string | null): boolean => {
    const at = seen.get(key)
    if (at === undefined) return false
    if (clock() - at.at > windowMs) {
      seen.delete(key)
      return false
    }
    return at.words === null || words === null || at.words === words
  }

  const prune = (seen: Map<string, Seen>): void => {
    if (seen.size < 200) return
    const now = clock()
    for (const [key, at] of seen) if (now - at.at > windowMs) seen.delete(key)
  }

  return {
    remoteArrived(payload, words) {
      const key = dedupeKey(payload)
      if (key === null) return true
      const said = wordsOf(words)
      if (sameEvent(local, key, said)) return false
      prune(remote)
      remote.set(key, { at: clock(), words: said })
      return true
    },
    localAllowed(payload, words) {
      const key = dedupeKey(payload)
      if (key === null) return true
      const said = wordsOf(words)
      if (sameEvent(remote, key, said)) return false
      prune(local)
      local.set(key, { at: clock(), words: said })
      return true
    },
    clear() {
      local.clear()
      remote.clear()
    },
  }
}

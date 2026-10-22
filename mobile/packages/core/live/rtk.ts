/**
 * LIVE → THE RTK QUERY CACHE (DESIGN §8.4): change what is cached in place, or refetch one record — never a
 * whole list, never a reload.
 *
 *   patchEverywhere(endpoint, id, row | null)   every cached ARGUMENT of a read, patched: the row merged into
 *                                               the list item or record whose id it is, or removed from every
 *                                               list when `row` is null. The notifier and the screen ask for
 *                                               visitor passes with different arguments: `undefined` and `{}`
 *                                               share one entry (api/baseApi.ts `saysNothing`), `{status:
 *                                               'active'}` is another, and a patch to one alone left the other
 *                                               stale (the maps' cache-key split)
 *   invalidateRecord(cap, id)                   refetches the reads that provide {type: cap, id} — the one
 *                                               record (the generator gives every show endpoint that tag)
 *   perRecord(key, run)                         one refetch per record at a time, and one more if another event
 *                                               for it landed meanwhile: a stale answer never overwrites a newer
 *                                               one (§2.2)
 *   resyncMounted()                             a reset (§5.5): everything cached is invalidated ONCE. What a
 *                                               mounted screen shows is refetched; what no screen shows is
 *                                               dropped, so nothing stale is shown when it next mounts
 *
 * A patch never ADDS a row to a list: whether a new row belongs under a list's filter is the server's call,
 * so a handler invalidates that capability's LIST tag instead.
 */

import type { TagType } from '../api/generated/tags'
import { baseApi } from '../api/baseApi'
import type { guardApi } from '../api/generated/guardApi'
import type { residentApi } from '../api/generated/residentApi'
import type { LiveStore } from './configure'

/** Every read either app can make, by its generated name. */
export type LiveQueryName = keyof typeof guardApi.endpoints | keyof typeof residentApi.endpoints

let bound: LiveStore | null = null

/** The store the helpers below act on; startLive binds it, so they act only while live runs for a session. */
export function bindLiveStore(store: LiveStore | null): void {
  bound = store
}

function storeOrThrow(): LiveStore {
  if (!bound) throw new Error('live: configureLive({ store }) must run before the cache helpers are used')
  return bound
}

export type PatchOptions = {
  /** The id field of a row. Default `id`. */
  key?: string
  /** Where a cached answer keeps its rows. Default: `items` when it is an array, or the answer when it is one. */
  rows?: (data: unknown) => unknown[] | undefined
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)

const defaultRows = (data: unknown): unknown[] | undefined => (Array.isArray(data) ? data : isRecord(data) && Array.isArray(data.items) ? data.items : undefined)

type Util = {
  selectCachedArgsForQuery: (state: unknown, name: string) => unknown[]
  updateQueryData: (name: string, arg: unknown, recipe: (draft: unknown) => void, updateProvided?: boolean) => unknown
  invalidateTags: (tags: unknown[]) => unknown
}
const util = baseApi.util as unknown as Util

/**
 * Patches row `id` in every cached argument of `endpoint`. Returns how many cache entries it changed.
 * `row` is merged into what is cached (a list item keeps the fields the record read does not return).
 */
export function patchEverywhere(endpoint: LiveQueryName, id: string | number, row: Record<string, unknown> | null, options: PatchOptions = {}): number {
  const store = storeOrThrow()
  const key = options.key ?? 'id'
  const rowsOf = options.rows ?? defaultRows
  const wanted = String(id)
  let changed = 0
  for (const arg of util.selectCachedArgsForQuery(store.getState(), endpoint)) {
    let hit = false
    store.dispatch(
      util.updateQueryData(endpoint, arg, (draft) => {
        const rows = rowsOf(draft)
        if (rows) {
          for (let i = rows.length - 1; i >= 0; i--) {
            const item = rows[i]
            if (!isRecord(item) || String(item[key]) !== wanted) continue
            hit = true
            if (row === null) rows.splice(i, 1)
            else Object.assign(item, row)
          }
          return
        }
        // A single record: merged when it is this one. Removing a record is a refetch (invalidateRecord), not a patch.
        if (row !== null && isRecord(draft) && String(draft[key]) === wanted) {
          hit = true
          Object.assign(draft, row)
        }
      }) as never,
    )
    if (hit) changed += 1
  }
  return changed
}

/** Refetches the one record: every read that provides `{type: cap, id}`. */
export function invalidateRecord(cap: TagType, id: string | number): void {
  storeOrThrow().dispatch(util.invalidateTags([{ type: cap, id: String(id) }]) as never)
}

/**
 * Refetches every read of one kind — the record, the lists it sits in, and the single reads beside them (the guard's
 * own shift, the household's balance). For a `{entity}.changed` reference (M11 §2, D-175): the phone does not know
 * which of its reads show that record, so every mounted one of that kind is asked again. One dispatch; RTK refetches
 * what a screen is subscribed to and drops the rest.
 */
export function invalidateKind(cap: TagType): void {
  storeOrThrow().dispatch(util.invalidateTags([cap]) as never)
}

/**
 * A reset: every tag any cached read provides, invalidated in ONE dispatch. Returns how many tags that was.
 * RTK refetches the entries a screen is subscribed to and drops the rest.
 */
export function resyncMounted(): number {
  const store = storeOrThrow()
  const state = store.getState() as Record<string, { provided?: { keys?: Record<string, Array<{ type: string; id?: string | number }>> } } | undefined>
  const keys = state[baseApi.reducerPath]?.provided?.keys ?? {}
  const seen = new Map<string, { type: string; id?: string | number }>()
  for (const tags of Object.values(keys)) {
    for (const tag of tags ?? []) seen.set(`${tag.type}\u0000${tag.id ?? ''}`, tag.id === undefined ? { type: tag.type } : { type: tag.type, id: tag.id })
  }
  if (seen.size) store.dispatch(util.invalidateTags([...seen.values()]) as never)
  return seen.size
}

const running = new Map<string, { again: boolean; done: Promise<void> }>()

/**
 * Runs `run` for record `key`, one at a time. Asked again while it runs: it runs ONCE more afterwards, however
 * many times it was asked, so the last answer to land is always from after the last event.
 */
export function perRecord(key: string, run: () => Promise<unknown>): Promise<void> {
  const current = running.get(key)
  if (current) {
    current.again = true
    return current.done
  }
  const entry: { again: boolean; done: Promise<void> } = { again: false, done: Promise.resolve() }
  running.set(key, entry) // before the first run starts, so a run that asks again is counted, not doubled
  entry.done = (async () => {
    try {
      do {
        entry.again = false
        await Promise.resolve().then(run).catch(() => undefined)
      } while (entry.again)
    } finally {
      running.delete(key)
    }
  })()
  return entry.done
}

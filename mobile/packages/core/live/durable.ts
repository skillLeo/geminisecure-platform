/**
 * THE STORES THAT OUTLIVE THE APP (DESIGN §5.2, §5.5).
 *
 * Live handlers update a few things kept on disk — the guard's kept site alerts, kept resident reports and
 * `duress.active`, the resident's `panic.active`, the gate's revoked list. The cursor that says how far those
 * handlers got lives in `live.cursors`, apart from the store, so a crash between applying an event and
 * writing the store can leave the cursor AHEAD of what was saved, and nothing else would ever correct it
 * (`sync.pull` drops a closed alert instead of reporting it closed).
 *
 * So each such store registers a REVALIDATOR, and it runs:
 *
 *   on start     every time live starts for a session — the app's launch with a session, and every
 *                sign-in — once the phone is in the foreground and online
 *   after reset  of a channel that FEEDS the store (`feeds`; every channel when it does not say), when
 *                the events it missed are gone from the log
 *
 * and NEVER on an ordinary event: that is what the handlers are for.
 *
 * A STORE THAT KEEPS ITS OWN CURSOR (§5.2 — the gate's revoked list keeps the `estate.{t}.passes` cursor IN
 * the same record, written in the same write) says so with `cursor`: live starts that channel from the
 * store's cursor instead of its own, so the cursor can never be ahead of what the phone saved, and a reset
 * hands the revalidator the head to save with the refreshed set.
 */

import type { ChannelCursors, Heads } from './cursors'

export type RevalidateReason = 'start' | 'reset'

export type RevalidateContext = {
  reason: RevalidateReason
  /** The channel that was reset (absent on start). */
  channel?: string
  /** The reset's head, per stream — the cursor to save with the revalidated state (absent on start). */
  head?: Heads
}

export type DurableStore = {
  /** For reports and tests, e.g. `alerts.kept`. Registering the same name again replaces it. */
  name: string
  revalidate: (context: RevalidateContext) => void | Promise<void>
  /** Which channels feed this store. Omitted: every channel does. */
  feeds?: (channel: string) => boolean
  /** Where this store is up to on `channel`, when it keeps that cursor itself. `null`: live's own cursor. */
  cursor?: (channel: string) => ChannelCursors | null
}

const stores = new Map<string, DurableStore>()

/** Returns the unregister. */
export function registerDurableStore(store: DurableStore): () => void {
  stores.set(store.name, store)
  return () => {
    if (stores.get(store.name) === store) stores.delete(store.name)
  }
}

export const durableStoreNames = (): string[] => [...stores.keys()].sort()

/** Runs every revalidator this reason applies to, one after another. One that fails does not stop the rest. */
export async function revalidateDurableStores(context: RevalidateContext): Promise<string[]> {
  const ran: string[] = []
  for (const store of [...stores.values()]) {
    if (context.reason === 'reset' && context.channel !== undefined && store.feeds && !store.feeds(context.channel)) continue
    ran.push(store.name)
    try {
      await store.revalidate(context)
    } catch {
      // The store stays as it was; its next start or reset tries again. Nothing about it is logged.
    }
  }
  return ran
}

/** The cursor a store keeps for `channel`, if one does. The first store that answers wins. */
export function durableCursor(channel: string): ChannelCursors | null {
  for (const store of stores.values()) {
    if (!store.cursor) continue
    if (store.feeds && !store.feeds(channel)) continue
    try {
      const cursor = store.cursor(channel)
      if (cursor) return cursor
    } catch {
      // A store that cannot say is treated as keeping none.
    }
  }
  return null
}

/** For tests. */
export function forgetDurableStores(): void {
  stores.clear()
}

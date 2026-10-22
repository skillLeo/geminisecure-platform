/**
 * THE BANNERS THIS PHONE SHOWED IN THE LAST FEW MINUTES, KEPT ACROSS A RESTART (bug hunt B056).
 *
 * `sync.pull`'s cursor now overlaps by ten seconds, so a record written in the same second as a pull is never
 * lost — which means every pull sends the last ten seconds again, on purpose. Within one run of the app the
 * repeat was already dropped: a banner's key is shown once. But that record lived in memory, so after the app was
 * killed and reopened, the first pull's repeat of an alert raised just before would buzz the guard a second time.
 *
 * So each key is kept on the phone with the moment it was shown, for ten minutes — far longer than the overlap,
 * far shorter than anything a person would want to be told twice. Within a run a key is never shown again, as
 * before. Only keys and times are kept, never the words; the store is cleared with everything else at sign-out.
 */

import { getStorage, jsonStore } from '../lib/storage'

export const SHOWN_KEEP_MS = 10 * 60_000
const MAX_KEPT = 300

const box = () => jsonStore<Record<string, number>>(getStorage(), 'notify.shown', 'v1')

export type RecentlyShown = { has: (key: string) => boolean; add: (key: string) => void; delete: (key: string) => void; clear: () => void }

export function recentlyShown(clock: () => number = () => Date.now()): RecentlyShown {
  const shown = new Map<string, number>()
  let loaded = false

  // Read once, on first use — by then the store has been read from disk (a banner needs a session).
  const load = () => {
    if (loaded) return
    loaded = true
    try {
      const cutoff = clock() - SHOWN_KEEP_MS
      for (const [key, at] of Object.entries(box().get() ?? {})) if (typeof at === 'number' && at >= cutoff) shown.set(key, at)
    } catch {
      // Nothing readable is nothing remembered: at worst, one banner twice.
    }
  }

  const save = () => {
    try {
      const cutoff = clock() - SHOWN_KEEP_MS
      const keep = [...shown].filter(([, at]) => at >= cutoff).sort((a, b) => b[1] - a[1]).slice(0, MAX_KEPT)
      box().set(Object.fromEntries(keep))
    } catch {
      // The banner matters more than the record of it.
    }
  }

  return {
    has: (key) => {
      load()
      return shown.has(key)
    },
    add: (key) => {
      load()
      shown.set(key, clock())
      save()
    },
    delete: (key) => {
      load()
      shown.delete(key)
      save()
    },
    clear: () => {
      shown.clear()
      loaded = true
      try {
        box().remove()
      } catch {
        // gone with the store
      }
    },
  }
}

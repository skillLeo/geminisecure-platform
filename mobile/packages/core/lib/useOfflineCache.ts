/**
 * Show the last good answer from disk before — and without — the network.
 *
 *   const { data } = useShiftsMeCurrentQuery()
 *   const shift = useOfflineCache('shift.current', data)
 *
 * The key is versioned, so an old shape is ignored rather than mis-read. Never a token,
 * a password or card data — and on a guard handset never anything the allowlist dropped,
 * because what is cached is what came out of the API layer.
 *
 * Two things it must get right (both found by review, 2026-09-20):
 *   - THE KEY CAN CHANGE while the screen stays mounted (My Hours steps from week to week: `shifts.me:<monday>`).
 *     What was saved under the OLD key must never be handed back for the new one — last week's hours drawn
 *     under this week's dates. The saved copy is re-read whenever the key changes.
 *   - A TAB STAYS MOUNTED FOR DAYS. What it falls back to is the newest thing it saved, not a snapshot taken
 *     when it first mounted (which may have been before anything was saved at all).
 */
import { useEffect, useMemo, useState } from 'react'
import { getStorage, jsonStore } from './storage'

const CACHE_VERSION = 'v1'

const boxOf = <T>(key: string) => jsonStore<T>(getStorage(), `cache:${key}`, CACHE_VERSION)

/**
 * The same saved copy, outside a screen: the reads a session keeps warm before their screens are opened (the
 * Resident App's offline/warmCaches.ts) are saved here, under the key the screen itself reads.
 */
export const saveOfflineCache = <T>(key: string, value: T): void => boxOf<T>(key).set(value)
export const readOfflineCache = <T>(key: string): T | undefined => boxOf<T>(key).get() ?? undefined

export function useOfflineCache<T>(key: string, data: T | undefined): T | undefined {
  const box = useMemo(() => boxOf<T>(key), [key])
  const [saved, setSaved] = useState<{ key: string; value: T | null }>(() => ({ key, value: box.get() }))

  // Read synchronously on the very render the key changes, so not even one frame shows the old key's copy.
  const current = saved.key === key ? saved.value : box.get()

  useEffect(() => {
    if (data !== undefined) {
      box.set(data)
      setSaved({ key, value: data })
    } else if (saved.key !== key) {
      setSaved({ key, value: box.get() })
    }
    // `saved` is read, not watched: this runs when the key or the answer changes, and only then.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, data, box])

  return data ?? current ?? undefined
}

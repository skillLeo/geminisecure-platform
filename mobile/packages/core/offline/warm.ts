/**
 * M11 §1.1 — WHAT A SCREEN DRAWS WITH NO SIGNAL IS ON THE PHONE BEFORE IT IS FIRST OPENED. Both apps.
 *
 * Every screen saves the last good answer it was shown (lib/useOfflineCache), but only once it has been opened with
 * a connection. The M11 §1.7 airplane test (26 Sep) signed in, stayed on the first screen, lost signal, and found
 * "That didn't load" on the rest. So each app lists the reads its offline screens draw from, and this asks them one
 * after another, a few seconds after a session starts and when the phone is back online, and saves each answer under
 * the SAME key its screen reads, from the same endpoint with the same argument, in the same form. What is saved is
 * exactly what the screen would have saved itself; each app's test holds every row to its screen's own hook.
 *
 *   ONLY IN THE FOREGROUND      the background task sends the queue; it does not browse.
 *   ONLY FOR WHOM IT WAS ASKED  `whoNow` names the person (and, for an officer, the site): a session that ends or
 *                               changes hands part-way saves nothing more, and a new one is saved at once.
 *   ONLY WHAT THEY READ         `allowed` asks the person's level, so what the server would refuse is not asked.
 */

import { AppState } from 'react-native'
import { now } from '../fidelity'
import { saveOfflineCache } from '../lib/useOfflineCache'

export type WarmRow<A extends string = string> = {
  /** The key the screen reads its saved copy under. */
  key: string
  /** The generated endpoint the screen's query hook calls (`use<Endpoint>Query`). */
  endpoint: string
  /** Which argument the screen passes, as the app names it (`argOf` makes it). */
  arg: A
  /** Saved WITH the moment it was fetched, `{ value, savedAt }` (the Resident App's Dues screens). */
  stamped?: true
  /** The screen also keeps the answer's fetch time, in milliseconds, under this key. */
  at?: string
}

type Reader = { initiate: (arg: unknown, opts: { subscribe: boolean }) => unknown }
type Answer = { data?: unknown; fulfilledTimeStamp?: number }

export type WarmerOptions<A extends string> = {
  rows: readonly WarmRow<A>[]
  /** The app's generated endpoints (`residentApi.endpoints`). */
  endpoints: () => Record<string, Reader | undefined>
  dispatch: (action: unknown) => unknown
  /** Who is signed in, active and online (and where, for an officer) — or null, and nothing is asked. */
  whoNow: () => string | null
  /** The row's argument, or null when it cannot be made yet (no household, no shift). */
  argOf: (row: WarmRow<A>) => { value: unknown } | null
  /** Whether this person reads the row at all. */
  allowed?: (row: WarmRow<A>) => boolean
}

/** After the first screen's own reads, so a cold start is not slowed by these. */
export const WARM_AFTER_MS = 4_000
/** A connection that comes and goes asks again at most this often, for the same person. */
export const WARM_EVERY_MS = 5 * 60_000

export type Warmer = {
  /** Every row this person reads, asked and saved now. Answers how many were saved. */
  warm: () => Promise<number>
  /** At a session's start, on reconnect, when an officer's site changes. Once per person per WARM_EVERY_MS. */
  schedule: (delay?: number) => void
  /** Tests only. */
  reset: () => void
}

export function createWarmer<A extends string>(options: WarmerOptions<A>): Warmer {
  const who = (): string | null => (AppState.currentState === 'active' ? options.whoNow() : null)

  async function warm(): Promise<number> {
    const asking = who()
    if (asking === null) return 0
    let saved = 0
    for (const row of options.rows) {
      if (who() !== asking) break
      if (options.allowed && !options.allowed(row)) continue
      const endpoint = options.endpoints()[row.endpoint]
      const arg = options.argOf(row)
      if (!endpoint || !arg) continue
      const answer = (await options.dispatch(endpoint.initiate(arg.value, { subscribe: false }))) as Answer
      // Signed out (the store wiped) or somebody else signed in while it was asked: it is not written back.
      if (answer.data === undefined || who() !== asking) continue
      // The moment it was fetched, kept as the instant it is (the screens read its age on the site's clock).
      saveOfflineCache(row.key, row.stamped ? { value: answer.data, savedAt: new Date(answer.fulfilledTimeStamp ?? now().getTime()).toISOString() } : answer.data)
      if (row.at) saveOfflineCache(row.at, answer.fulfilledTimeStamp ?? now().getTime())
      saved++
    }
    return saved
  }

  let timer: ReturnType<typeof setTimeout> | null = null
  let last: { who: string; at: number } | null = null

  function schedule(delay: number = WARM_AFTER_MS): void {
    if (timer) return
    timer = setTimeout(() => {
      timer = null
      let asking: string | null
      try {
        asking = who()
      } catch {
        return // no store yet (a screen tested on its own): nothing to warm, and nothing thrown from a timer
      }
      if (asking === null) return
      if (last && last.who === asking && Date.now() - last.at < WARM_EVERY_MS) return
      last = { who: asking, at: Date.now() }
      void warm().catch(() => 0)
    }, delay)
  }

  function reset(): void {
    if (timer) clearTimeout(timer)
    timer = null
    last = null
  }

  return { warm, schedule, reset }
}

/**
 * The Guard App's reads kept warm (M11 §1.1; the engine is core's offline/warm.ts).
 *
 * Every tab saved its copy only once it had been opened with signal, so an officer who clocked in, stayed on the
 * dashboard and walked into a dead spot found the tour, the standing orders and the rest missing — and Patrol cannot
 * take a scan without its checkpoints. These are the reads the offline tabs draw from, each under its tab's own key,
 * with the fetch time beside it where the tab keeps one (__tests__/warmCaches.test.ts holds every row to its tab):
 *
 *   THE SHIFT'S SITE            the tour and the orders are the site's: asked only while a shift is saved, and asked
 *                               again at once when the site changes (a new shift is a new person to warm for).
 *   NOT WHAT THE DASHBOARD READS the current shift itself; nor the gate's pass knowledge (gate/knowledge.ts pulls it)
 *                               nor payslips (kept in the secure store by their own screen, never here).
 */

import type { Api } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { isFidelity } from '@gs/core/fidelity'
import { readOfflineCache } from '@gs/core/lib'
import { createWarmer, type WarmRow } from '@gs/core/offline'
import { store } from '@/store'

export { WARM_AFTER_MS, WARM_EVERY_MS } from '@gs/core/offline'

/** The argument the tab passes: none, or `{ site }` — the saved shift's site. */
type Arg = 'none' | 'site'

export const WARM_READS: readonly WarmRow<Arg>[] = [
  { key: 'patrol.checkpoints', endpoint: 'patrolCheckpoints', arg: 'site', at: 'patrol.checkpoints.at' },
  { key: 'orders.current', endpoint: 'ordersCurrent', arg: 'site' },
  { key: 'presence.summary', endpoint: 'presenceSummary', arg: 'none' },
  { key: 'messages.index', endpoint: 'messagesIndex', arg: 'none' },
  { key: 'incidents.me', endpoint: 'incidentsMe', arg: 'none' },
  { key: 'requests.index', endpoint: 'requestsIndex', arg: 'none' },
  { key: 'guards.me', endpoint: 'guardsMe', arg: 'none', at: 'guards.me.at' },
]

const siteOf = () => readOfflineCache<Api.ShiftsMeCurrentResponse>('shift.current')?.shift?.site?.id

const warmer = createWarmer<Arg>({
  rows: WARM_READS,
  endpoints: () => guardApi.endpoints as never,
  dispatch: (action) => store.dispatch(action as never),
  whoNow: () => {
    const s = store.getState()
    if (isFidelity || s.session.status !== 'active' || !s.network.online) return null
    const who = s.session.principal?.reference
    return who ? `${who}@${siteOf() ?? 'no-shift'}` : null
  },
  argOf: (row) => {
    if (row.arg === 'none') return { value: undefined }
    const site = siteOf()
    return site === undefined ? null : { value: { site } }
  },
})

export const warmCaches = warmer.warm
export const scheduleWarm = warmer.schedule
export const resetWarm = warmer.reset

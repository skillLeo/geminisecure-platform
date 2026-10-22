/**
 * The Resident App's reads kept warm (M11 §1.1; the engine is core's offline/warm.ts).
 *
 * A resident who signed in, stayed on Home and then lost signal found "That didn't load" on Visitors: nothing had
 * ever been saved there (the M11 §1.7 airplane test, 26 Sep). These are the reads the offline screens draw from,
 * each under its screen's own key and in its screen's own form (__tests__/warmCaches.test.ts holds every row to its
 * screen):
 *
 *   ONLY WHAT THIS LEVEL READS  `me.show.access`, as the screens ask it: what the server would refuse is not asked.
 *   NOT WHAT HOME ALONE READS   the balance card, today's passes, notices, the panic contacts: Home is the first
 *                               screen of every session and saves them itself. The household is asked first all the
 *                               same (shared with Home's own read): the Dues rows need its id. Nor the e-Pass:
 *                               EpassKeeper keeps it, on its own clock.
 */

import type { Api } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { isFidelity } from '@gs/core/fidelity'
import { readOfflineCache } from '@gs/core/lib'
import { createWarmer, type WarmRow } from '@gs/core/offline'
import { mayFrom, type MayKey } from '@/features/access/access'
import { store } from '@/store'

export { WARM_AFTER_MS, WARM_EVERY_MS } from '@gs/core/offline'

/** The argument the screen passes: none, `{}`, or `{ household }` — the household's id, as saved. */
type Arg = 'none' | 'empty' | 'household'
export type WarmRead = WarmRow<Arg> & { may: MayKey }

export const WARM_READS: readonly WarmRead[] = [
  { key: 'me.household', endpoint: 'meHousehold', arg: 'none', may: 'household' },
  { key: 'passes.all', endpoint: 'visitorPassesIndex', arg: 'empty', may: 'visitor_passes' },
  { key: 'dues.balance.stamped', endpoint: 'householdsBalance', arg: 'household', stamped: true, may: 'dues' },
  { key: 'dues.invoices', endpoint: 'invoicesIndex', arg: 'none', stamped: true, may: 'dues' },
  { key: 'dues.statement', endpoint: 'householdsStatement', arg: 'household', stamped: true, may: 'dues' },
  { key: 'tickets.all', endpoint: 'ticketsIndex', arg: 'none', may: 'tickets' },
  { key: 'meetings.index', endpoint: 'meetingsIndex', arg: 'none', may: 'meetings' },
  { key: 'elections.index', endpoint: 'electionsIndex', arg: 'none', may: 'elections' },
  { key: 'amenities.index', endpoint: 'amenitiesIndex', arg: 'none', may: 'bookings' },
  { key: 'bookings.index', endpoint: 'bookingsIndex', arg: 'none', may: 'bookings' },
  { key: 'members.index', endpoint: 'membersIndex', arg: 'none', may: 'household' },
  { key: 'vehicles.index', endpoint: 'vehiclesIndex', arg: 'none', may: 'household' },
  { key: 'contacts.index', endpoint: 'contactsIndex', arg: 'none', may: 'household' },
]

const byKey = new Map(WARM_READS.map((r) => [r.key, r]))

const warmer = createWarmer<Arg>({
  rows: WARM_READS,
  endpoints: () => residentApi.endpoints as never,
  dispatch: (action) => store.dispatch(action as never),
  whoNow: () => {
    const s = store.getState()
    if (isFidelity || s.session.status !== 'active' || !s.network.online) return null
    const id = s.session.principal?.id
    return id === undefined ? null : String(id)
  },
  argOf: (row) => {
    if (row.arg === 'none') return { value: undefined }
    if (row.arg === 'empty') return { value: {} }
    const id = readOfflineCache<Api.MeHouseholdResponse>('me.household')?.household?.id
    return id === undefined ? null : { value: { household: id } }
  },
  allowed: (row) => {
    const read = byKey.get(row.key)
    return !read || mayFrom(readOfflineCache<Api.MeShowResponse>('me.show')?.access ?? null, read.may)
  },
})

export const warmCaches = warmer.warm
export const scheduleWarm = warmer.schedule
export const resetWarm = warmer.reset

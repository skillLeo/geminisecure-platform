/**
 * The Guard App's one "what changed?" call — sync.pull with a cursor kept on the phone.
 *
 * Everything that needs to know about change reads the SAME pull: the alertness watcher (a check to
 * answer now), the notifier (a message, new orders, a roster change, a decision), and the screens
 * (their caches are told to refresh). The first pull on a phone starts from NOW: a guard who has just
 * enrolled is not told about last week.
 *
 * The pull is read-only on the server. A failed pull changes nothing — the cursor only moves when the
 * server answered, so nothing is skipped across a gap in signal.
 */

import { AppState } from 'react-native'
import { now } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import type { Api } from '@gs/core/api'
import { dateShort, getStorage, jsonStore, time12 } from '@gs/core/lib'
import { CATEGORY, guardDigest, show } from '@gs/core/notify'
import { notificationPrefs, type Category } from '@/features/more/prefs'
import { rememberReports } from '@/features/residentreports/residentReports'
import { rememberAlerts } from '@/features/sitealert/siteAlert'
import { store } from '@/store'

const cursor = () => jsonStore<{ since: string }>(getStorage(), 'notify.cursor', 'v1')

/** Where the guard is looking right now — set by the notifier component, so a banner never announces what is on screen. */
let looking = ''
export const setLooking = (pathname: string): void => { looking = pathname }

const onScreen = (type: string): boolean =>
  AppState.currentState === 'active' && ((type === 'message' && looking.startsWith('/messages/dispatch')) || (type === 'broadcast' && looking.startsWith('/messages/broadcast')) || (type === 'alertness_check' && looking.startsWith('/alertness')) || (type === 'order_published' && looking.startsWith('/orders')) || (type === 'resident_report' && looking.startsWith('/report/')))

export async function pullDeltas(): Promise<Api.SyncPullResponse | null> {
  const since = cursor().get()?.since ?? now().toISOString()
  const result = await store.dispatch(guardApi.endpoints.syncPull.initiate({ since }, { forceRefetch: true, subscribe: false }))
  const data = result.data
  if (!data) return null
  cursor().set({ since: data.next_since ?? since })

  // The screens learn first: their lists are refetched the next time they are looked at.
  const stale: ('messages' | 'orders' | 'shifts' | 'requests')[] = []
  if (data.messages?.length) stale.push('messages')
  if (data.orders?.length) stale.push('orders')
  if (data.shifts?.length || data.claims?.length) stale.push('shifts')
  if (data.requests?.length) stale.push('requests')
  if (stale.length) store.dispatch(guardApi.util.invalidateTags(stale as never))

  // What a resident says is happening NOW is kept on the phone before the banner goes up, so the tap —
  // or a tap on the server's push for the same report — opens what they said.
  rememberReports(data.resident_reports, now())
  // And an alert at the site, so the alert screen has something to show before its own read lands (DRILL included).
  rememberAlerts(data.alerts, now())

  for (const note of guardDigest(data, now(), (iso) => `${dateShort(iso)}, ${time12(iso)}`)) {
    if (onScreen(note.type)) continue
    const category = CATEGORY[note.type]
    const allowed = category === null || notificationPrefs.allows(category as Category)
    await show({ key: note.key, title: note.title, body: note.body, data: note.data, urgent: note.urgent }, allowed)
  }
  return data
}

/**
 * Notifications and links for the Resident App — mounted once, in the root layout.
 *
 * A CLOSED app is reached by the server's push: this component keeps the server told where this
 * phone is (usePushRegistration) and creates the Android channels the pushes name. A push opened from the
 * lock screen takes the same road as a local banner — type + id → one of the app's own screens.
 *
 * There is no push in Expo Go and the Resident API has no "what changed?" call, so while a resident is
 * signed in and the app is OPEN, this component ALSO keeps the app's own lists fresh and compares them
 * with a small snapshot from last time (packages/core/notify/residentDigest.ts) — and the runtime drops
 * whichever of the two, push or local banner, arrives second for the same thing:
 *
 *   SOMEONE AT THE GATE   me.household every 15 s, on every screen — a walk-up request lives about two
 *                         minutes, and the resident must hear about it wherever they are in the app.
 *                         Never slowed by data-light mode, never switched off.
 *   EVERYTHING ELSE       notices, tickets, payments, passes, bookings every 5 minutes (20 in data-light
 *                         mode), shown only if this phone's notification settings allow that category.
 *
 * The hooks share the screens' caches, so nothing is fetched twice. A TAP opens the screen the type
 * names (never a URL from the payload) — and only for a signed-in resident. It never asks for
 * permission: the Permissions screen and Notification settings do that.
 *
 * THE FIVE-MINUTE LISTS WAIT FOR THE FIRST SCREEN (M11 A4). At launch they used to go out in the same
 * instant as everything Home needs — tickets, payments, every pass and every booking, four requests Home
 * does not draw — and compete with it for the connection and the server. They start SLOW_START_MS after
 * the session is active instead. Nothing a resident must hear about waits: the gate (me.household) is
 * asked at once, as before, and notices are Home's own list anyway. A change to one of the four is
 * announced up to that much later, on lists that are only asked every five minutes.
 */

import { useEffect, useRef, useState } from 'react'
import { AppState } from 'react-native'
import { router, usePathname } from 'expo-router'
import { isFidelity, now } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { flags, getStorage, jsonStore, pollEvery, useDataLight } from '@gs/core/lib'
import { CATEGORY, configureNotifications, onOpen, residentDigest, show, usePushRegistration, type Snapshot } from '@gs/core/notify'
import { store, useAppSelector } from '@/store'
import { allows } from './prefs'
import { mutedCategories, pushRegistration, quietReport } from './pushRegistration'

const GATE_MS = 15_000
const SLOW_MS = 300_000
/** How long after the session is active the five-minute lists are first asked for. */
export const SLOW_START_MS = 5_000
const snapshot = () => jsonStore<Snapshot>(getStorage(), 'notify.snapshot', 'v1')

/** False until `ms` after `active` last became true; false again whenever it is not. */
function useWarm(active: boolean, ms: number): boolean {
  const [warm, setWarm] = useState(false)
  useEffect(() => {
    if (!active) {
      setWarm(false)
      return
    }
    const timer = setTimeout(() => setWarm(true), ms)
    return () => clearTimeout(timer)
  }, [active, ms])
  return warm
}

export function ResidentNotifier() {
  const active = useAppSelector((s) => s.session.status === 'active')
  const online = useAppSelector((s) => s.network.online)
  const pathname = usePathname()
  const light = useDataLight()
  const looking = useRef(pathname)
  looking.current = pathname
  const warm = useWarm(active && !isFidelity, SLOW_START_MS)

  const skip = isFidelity || !active || !online
  const slow = { skip: skip || !warm, pollingInterval: pollEvery(SLOW_MS, light) }
  // The gate, at once and every 15 s — never held back.
  const household = residentApi.useMeHouseholdQuery(undefined, { skip, pollingInterval: GATE_MS })
  const notices = residentApi.useNoticesIndexQuery(undefined, slow)
  const tickets = residentApi.useTicketsIndexQuery(undefined, slow)
  const payments = residentApi.usePaymentsIndexQuery(undefined, slow)
  const passes = residentApi.useVisitorPassesIndexQuery(undefined, slow)
  const bookings = residentApi.useBookingsIndexQuery(undefined, { ...slow, skip: slow.skip || !flags.amenityBookings })

  // Where the server reaches this phone when the app is CLOSED. A PENDING account registers too: "your
  // account is approved" is the push it is waiting for. Never blocks; silent in Expo Go, where the polling below
  // stays the only notifier.
  const accountId = useAppSelector((s) => (s.session.status === 'active' || s.session.status === 'pending' ? s.session.principal?.id ?? null : null))
  /*
   * REVOKED ONLY, AND NOT `expired` (M6, Q-M42). `registration.revoked()` gives the device
   * token back to the OS and nothing more is delivered to this install — right when the estate
   * has withdrawn access, wrong when the idle window merely closed. A resident whose phone
   * signed itself out overnight must still be woken by a visitor at their gate; the API token
   * row is still there, so the server can still reach them.
   */
  const revoked = useAppSelector((s) => s.session.status === 'signedOut' && s.session.endedReason === 'revoked')
  /*
   * A READ-ONLY look round the demonstration community registers NOTHING. Registering is a
   * write, and a demonstration session has no write abilities — so it would be refused. More to
   * the point, it would ask an App Store reviewer for permission to send notifications to a phone
   * that has nothing to be notified about, which is the kind of prompt that gets an app rejected.
   */
  const demo = useAppSelector((s) => s.session.demo)
  usePushRegistration({ registration: pushRegistration, owner: accountId === null ? null : `resident:${accountId}`, online, muted: mutedCategories, quiet: quietReport, revoked, disabled: isFidelity || demo })

  useEffect(() => {
    if (isFidelity) return
    void configureNotifications('resident')
    return onOpen('resident', (path) => {
      // A tap that LAUNCHED the app arrives while the session is still booting: wait for the answer, then decide.
      const decide = (): boolean => {
        const status = store.getState().session.status
        if (status === 'booting') return false
        if (status === 'active') router.push(path as never)
        return true
      }
      if (decide()) return
      const stop = store.subscribe(() => { if (decide()) stop() })
    })
  }, [])

  useEffect(() => {
    if (skip) return
    const { notes, next } = residentDigest(snapshot().get() ?? null, {
      approvals: household.data?.pending_approvals,
      notices: notices.data?.items,
      tickets: tickets.data?.items,
      payments: payments.data?.items,
      passes: passes.data?.items,
      bookings: bookings.data?.items,
    }, now())
    snapshot().set(next)
    for (const note of notes) {
      // Never a banner for what is already on the screen.
      const path = looking.current
      const onScreen = AppState.currentState === 'active' && ((note.type === 'approval_request' && path.startsWith('/visitors/approval')) || (note.type === 'notice' && path.startsWith('/notices/')))
      if (onScreen) continue
      const category = CATEGORY[note.type]
      void show({ key: note.key, title: note.title, body: note.body, data: note.data, urgent: note.urgent }, category === null || allows(category))
    }
  }, [skip, household.data, notices.data, tickets.data, payments.data, passes.data, bookings.data])

  return null
}

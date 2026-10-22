/**
 * The JS half of the call-style ring — mounted once, in the root layout, after GuardNotifier (GUARD_RING_DESIGN §8.2).
 * It shows nothing. The ring itself does not need it: a killed phone wakes and rings natively. While the app runs it
 *
 *   1  tells the module where the server is (`apiUrl()`, the app's one API address);
 *   2  keeps the ring QUIET while the guard is under their own live duress (R14): nothing may ring loudly on a phone
 *      whose guard pressed a silent alarm. The module keeps it on disk, so it holds with the app killed, and it ends
 *      by itself an hour after the last refresh. It is set only from a signed-in session, where the duress record can
 *      be read truthfully, and it is CLEARED only when the record says, beyond doubt, that there is no live alert;
 *   3  sends the answers and receipts still waiting on the phone, when signed in and online;
 *   4  shows the alert whose ring screen was opened, when the guard comes back to the app — only for a signed-in
 *      guard, the rule GuardNotifier keeps for a tap on a notification;
 *   5  on sign-out, and when the office takes the handset back (`revoked`), stops every ring and forgets them. NOT on
 *      `expired`: an idle guard is still rung (the store puts live rings back after clearing the tray);
 *   6  reads the readiness again every time the app comes to the front, and tells the push registration when it
 *      changed — a guard who switched full-screen alerts off in Settings must not stay "ready" on the console.
 *
 * Nothing here imports a sound module, and useDuress.ts is only READ (silentDuress.test.ts).
 */

import { useEffect, useRef } from 'react'
import { AppState } from 'react-native'
import { router, usePathname } from 'expo-router'
import { isFidelity } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { apiUrl } from '@gs/core/lib'
import { nudgePushRegistration } from '@gs/core/notify'
import { isLive, useActiveDuress, type ActiveDuress } from '@/features/duress/useDuress'
import { store, useAppSelector } from '@/store'
import { GuardRing } from './native'
import { serverReport } from './readiness'

/** How long one refresh of quiet lasts, and how often it is looked at again while signed in. */
export const QUIET_MS = 60 * 60_000
export const QUIET_CHECK_MS = 60_000

/**
 * What the ring should be told about the guard's own duress:
 *   quiet  a live alert — ring nothing
 *   clear  certainly none — ring as normal
 *   leave  cannot be told for sure (a held alert whose queue is not read yet): leave quiet as it is. It ends by
 *          itself, and a silent alarm betrayed by a loud ring is the one mistake that cannot be taken back.
 */
export function quietFor(duress: ActiveDuress | null): 'quiet' | 'clear' | 'leave' {
  if (duress === null) return 'clear'
  if (isLive(duress)) return 'quiet'
  return duress.alert ? 'clear' : 'leave'
}

let lastReport: string | null = null

/** Reads readiness and, when the report differs from the last one seen, asks the push registration to look again. */
export async function rereadReadiness(): Promise<void> {
  if (!GuardRing) return
  try {
    const report = JSON.stringify(serverReport(await GuardRing.readiness()) ?? null)
    if (lastReport !== null && report !== lastReport) nudgePushRegistration()
    lastReport = report
  } catch {
    // a phone that cannot say is not a reason to register again
  }
}

/** For tests. */
export function forgetReadiness(): void {
  lastReport = null
}

export function RingBridge(): null {
  const status = useAppSelector((s) => s.session.status)
  const endedReason = useAppSelector((s) => s.session.endedReason)
  const online = useAppSelector((s) => s.network.online)
  const duress = useActiveDuress()
  const pathname = usePathname()
  const where = useRef(pathname)
  where.current = pathname
  const signedIn = status === 'active'
  const off = isFidelity || GuardRing === null

  // 1 — where the server is. apiUrl() throws when the build has no address: the ring then cannot answer, and says so.
  useEffect(() => {
    if (off) return
    try {
      void GuardRing!.configure(apiUrl()).catch(() => undefined)
    } catch {
      // no API address in this build
    }
  }, [off])

  // 2 — quiet during the guard's own duress. Only from a signed-in session.
  const want = signedIn ? quietFor(duress) : 'leave'
  useEffect(() => {
    if (off || !signedIn) return
    const apply = () => {
      const verdict = quietFor(duress)
      if (verdict === 'quiet') void GuardRing!.setQuiet(Date.now() + QUIET_MS).catch(() => undefined)
      else if (verdict === 'clear') void GuardRing!.setQuiet(null).catch(() => undefined)
    }
    apply()
    const timer = setInterval(apply, QUIET_CHECK_MS)
    return () => clearInterval(timer)
  }, [off, signedIn, want, duress])

  // 3 — what is still waiting on the phone.
  useEffect(() => {
    if (off || !signedIn || !online) return
    void GuardRing!.flushPending().catch(() => undefined)
  }, [off, signedIn, online])

  // 4 and 6 — coming back to the app.
  useEffect(() => {
    if (off) return
    const show = (id: string | null) => {
      if (!id || store.getState().session.status !== 'active') return
      if (where.current === `/alert/${id}`) return
      router.push(`/alert/${id}` as never)
    }
    const look = () => {
      void GuardRing!.consumeLaunch().then(show).catch(() => undefined)
      void rereadReadiness()
      if (store.getState().session.status === 'active' && store.getState().network.online) void GuardRing!.flushPending().catch(() => undefined)
    }
    look()
    const app = AppState.addEventListener('change', (state) => { if (state === 'active') look() })
    const events = GuardRing!.addListener('onRingEvent', (event) => {
      if (event.type === 'opened') show(event.id)
      if (event.type === 'stopped' || event.type === 'answered') store.dispatch(guardApi.util.invalidateTags(['alerts']))
    })
    return () => { app.remove(); events.remove() }
  }, [off])

  // 5 — sign-out and revoked: nothing of this guard's rings stays on the phone. Expired is NOT one of them.
  const gone = status === 'signedOut' && (endedReason === 'revoked' || endedReason === 'signed_out')
  useEffect(() => {
    if (off || !gone) return
    void (async () => {
      await GuardRing!.stopAll().catch(() => undefined)
      await GuardRing!.forget().catch(() => undefined)
    })()
  }, [off, gone])

  return null
}

/**
 * The one place the session is booted and guarded — mounted once, in the root layout.
 *
 *   - Boots on launch WHATEVER route opened the app (the splash, a notification, a link),
 *     so no screen ever sits on `booting`. The phone's binding record is read first (M32 F3),
 *     so the splash knows where a phone with no session goes.
 *   - A handset with no session can see the splash, signing back in, enrolment and the app's own
 *     Privacy Policy and Terms of Use (M24 §5.2: they are on the phone, not the website), nothing
 *     else. A session that ends mid-use — the 30-minute rule, a 401 — lands a phone that is still
 *     the guard's on signing back in (a fingerprint, never a code), and any other on enrolment,
 *     which says why. Their unsent work stays in the outbox either way.
 *   - A session that is active starts the offline runtime: the queue is adopted for this
 *     guard and drained on every reconnect. And a phone signed in by an EARLIER version, which
 *     has no binding record, is given one from the session it already has — so the first time
 *     its session ends, it too asks for a fingerprint and not a code.
 *
 * Fidelity mode boots nothing: the harness puts each screen in its board's state itself.
 */

import { useEffect } from 'react'
import { useSegments } from 'expo-router'
import { enterApp, isFidelity } from '@gs/core'
import { bootSession, noSession } from '@gs/core/auth'
import { binding } from '@/features/enrolment/binding'
import { startOfflineRuntime, watchNetwork } from '@/offline/runtime'
import { useAppDispatch, useAppSelector } from '@/store'
import { signedOutRoute } from './nextRoute'

const OPEN_TO_ALL = new Set<string | undefined>([undefined, 'enrol', 'unlock', 'lifted', 'privacy-policy', 'terms'])

export function SessionGate() {
  const dispatch = useAppDispatch()
  const status = useAppSelector((s) => s.session.status)
  const principal = useAppSelector((s) => s.session.principal)
  const segments = useSegments()
  const first = segments[0] as string | undefined

  useEffect(() => {
    if (isFidelity) return
    // A boot failure must never strand the guard on a splash: no readable session is no session.
    void binding
      .hydrate()
      .then(() => bootSession('guard', dispatch))
      .catch(() => dispatch(noSession()))
    watchNetwork()
  }, [dispatch])

  useEffect(() => {
    if (isFidelity) return
    if (status === 'active') void startOfflineRuntime()
    // Nothing of the session is left mounted underneath (enterApp): a screen left there went on polling with no token.
    if (status === 'signedOut' && !OPEN_TO_ALL.has(first)) enterApp(signedOutRoute() as never)
  }, [status, first])

  // A phone signed in by an earlier version has no binding record yet: it is given one from the session it holds.
  useEffect(() => {
    if (isFidelity || status !== 'active' || !principal || !binding.isHydrated() || binding.peek() !== null) return
    void binding.set({ guardId: principal.id, name: principal.name, reference: principal.reference, siteName: principal.siteName, boundAt: new Date().toISOString() }).catch(() => undefined)
  }, [status, principal])

  return null
}

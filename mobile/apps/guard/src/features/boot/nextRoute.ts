/**
 * Where the splash hands over — decided from disk alone, so it is the same with no signal.
 *
 *   no session, phone still bound  signing back in with a fingerprint (M32 F3) — never a code
 *   no session, no binding         enrolment: a new phone, a cleared one, or one taken out of service
 *   first-run setup interrupted    the step it stopped at
 *   on duty                        Home
 *   otherwise                      Clock In — the board's "auto-advances to clock-in", which now has a way Home
 *
 * ON DUTY INCLUDES A CLOCK-IN THIS PHONE HAS NOT SENT YET (B127). A guard who clocked in with no signal and then
 * restarted the app was sent back here with the cached shift still `rostered`, and asked to clock in again. The
 * queue on disk is read directly — never through the outbox, which must not be loaded before the session
 * starts (offline/runtime.ts).
 */

import { getStorage, jsonStore } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import type { SessionStatus } from '@gs/core/auth'
import { OUTBOX_PREFIX } from '@gs/core/offline'
import { binding } from '@/features/enrolment/binding'
import { onboarding } from '@/features/enrolment/onboardingState'

/** A clock-in captured on this phone that has not reached the server yet. */
export function clockInWaitingOnPhone(): boolean {
  try {
    const raw = getStorage().getString(`${OUTBOX_PREFIX}v1`)
    const ops: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(ops) && ops.some((o: { endpoint?: string; state?: string } | null) => o?.endpoint === 'shifts.clock_in' && (o.state === 'pending' || o.state === 'syncing'))
  } catch {
    return false
  }
}

/** Where a phone with no session goes: back in with a fingerprint while it is still somebody's, else enrolment. */
export const signedOutRoute = (): string => (binding.peek() !== null ? '/unlock' : '/enrol')

export function nextRoute(status: SessionStatus): string {
  if (status !== 'active') return signedOutRoute()
  if (!onboarding.isFinished()) return `/onboarding/${onboarding.nextStep()}`
  const last = jsonStore<Api.ShiftsMeCurrentResponse>(getStorage(), 'cache:shift.current', 'v1').get()
  return last?.shift?.status === 'on_duty' || clockInWaitingOnPhone() ? '/(tabs)' : '/clock-in'
}

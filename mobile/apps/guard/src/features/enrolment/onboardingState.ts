/**
 * Where the guard is in first-run setup — display facts and ticks, never a secret and
 * never anything biometric. `identity` records only WHETHER the phone's own check was
 * passed during setup; the fingerprint or face itself never reaches the app at all.
 *
 * It lives in the key-value store, so a revoked session (a rebind) clears it and the
 * next enrolment walks the guard through setup again on whichever handset they hold.
 *
 * TWO OF THESE FIELDS ARE A PERMISSION GATE, not a display fact (step 4, setupPermissions.ts):
 *   notificationsAskedAt  the phone has been asked to allow alerts — once per enrolment, never again
 *   locationDisclosedAt   the guard has READ what this app reads a position for and tapped through it.
 *                         No file in this app may put the operating system's LOCATION prompt up before
 *                         this is set (`locationDisclosed()`); until then they may only look at the
 *                         permission the phone already holds. Google's prominent-disclosure rule and
 *                         Apple's review guidance both want the sentence first, in the app, with an action to continue.
 */

import { getStorage, jsonStore } from '@gs/core/lib'
import type { Answer } from './setupPermissions'

export type OnboardingProgress = {
  /** The estate this handset was enrolled to — the id the site-scoped endpoints take. */
  site: { id: number | string; name: string } | null
  detailsConfirmedAt: string | null
  identity: 'confirmed' | 'unavailable' | 'not_confirmed' | null
  kitAcknowledgedAt: string | null
  /** Step 4: the phone was asked about alerts. What it answered, and when it was put. */
  notifications: Answer | null
  notificationsAskedAt: string | null
  /** Step 4: the location disclosure was shown and passed. The key that unlocks the OS location prompt. */
  locationDisclosedAt: string | null
  finishedAt: string | null
}

const EMPTY: OnboardingProgress = {
  site: null,
  detailsConfirmedAt: null,
  identity: null,
  kitAcknowledgedAt: null,
  notifications: null,
  notificationsAskedAt: null,
  locationDisclosedAt: null,
  finishedAt: null,
}

const box = () => jsonStore<Partial<OnboardingProgress>>(getStorage(), 'onboarding', 'v1')

export const onboarding = {
  /** Merged over EMPTY, so a record written by an older build answers `null` for a field it never had. */
  get: (): OnboardingProgress => ({ ...EMPTY, ...(box().get() ?? {}) }),
  update(patch: Partial<OnboardingProgress>): OnboardingProgress {
    const next = { ...onboarding.get(), ...patch }
    box().set(next)
    return next
  },
  /** Setup from the beginning — a phone that is becoming another officer's (openSession.ts, M32 F3). */
  reset(): void {
    box().remove()
  },
  /**
   * Setup is done only when the guard has been through all four steps. A handset that finished the
   * three-step setup of an earlier build has no answer for step 4, so it is walked through that one
   * step once — which is how the location disclosure reaches a guard who is already enrolled.
   */
  isFinished(): boolean {
    const p = onboarding.get()
    return p.finishedAt !== null && p.notificationsAskedAt !== null && p.locationDisclosedAt !== null
  },
  /** The first step not yet done — where a guard who closed the app mid-setup comes back to. */
  nextStep(): 1 | 2 | 3 | 4 {
    const p = onboarding.get()
    return p.detailsConfirmedAt === null ? 1 : p.identity === null ? 2 : p.kitAcknowledgedAt === null ? 3 : 4
  },
  /**
   * May this app put the phone's LOCATION prompt up yet? Only after the guard has read the disclosure
   * in setup. Every file that asks for the foreground location permission checks this first; when it is
   * false they read the permission the phone already holds instead, and no dialog appears.
   */
  locationDisclosed: (): boolean => onboarding.get().locationDisclosedAt !== null,
}

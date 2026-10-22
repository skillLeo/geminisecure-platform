/**
 * A TOKEN IN HAND — from an enrolment code, an approved move, or a fingerprint after a sign-out (M32 F3). One place,
 * so every way in leaves the phone the same: the token in the secure store and nowhere else, whose phone it is (the
 * principal, and the binding the next sign-out keeps), the estate, the session started, the queue adopted.
 *
 * THE SAME OFFICER KEEPS THEIR SETUP; ANYBODY ELSE WALKS THROUGH IT. Signing back in after the 30-minute sign-out is
 * the same guard on the same phone, so the four setup steps are not asked again. A code entered on a phone that was
 * somebody else's — or whose binding is gone — starts setup from the beginning, because the location disclosure and
 * the alerts question are each officer's own to answer (setupPermissions.ts).
 */

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit'
import { getStorage, jsonStore } from '@gs/core'
import { PRINCIPAL_KEY, sessionRestored, tokenStorage, type Principal } from '@gs/core/auth'
import { startOfflineRuntime } from '@/offline/runtime'
import { binding } from './binding'
import { onboarding } from './onboardingState'

export type BoundTo = {
  guard: { id: number | string; name: string; employee_number: string }
  site: { id: number | string; name: string }
  /** The server's reviewer flag (enrolment and sign-in both send it); false for every real guard. */
  reviewer?: boolean
}

export async function openSession(dispatch: ThunkDispatch<unknown, unknown, UnknownAction>, token: string, { guard, site, reviewer }: BoundTo): Promise<void> {
  const previous = binding.peek()
  await tokenStorage.set('guard', token)
  const principal: Principal = { id: Number(guard.id), name: guard.name, siteName: site.name, reference: guard.employee_number }
  await jsonStore<{ principal: Principal; pending: boolean }>(getStorage(), PRINCIPAL_KEY, 'v1').setDurable({ principal, pending: false })

  const same = previous?.guardId === principal.id
  if (!same) onboarding.reset()
  onboarding.update({ site })
  await binding.set({
    guardId: principal.id,
    name: principal.name,
    reference: principal.reference,
    siteName: principal.siteName,
    boundAt: same && previous ? previous.boundAt : new Date().toISOString(),
    reviewer: reviewer === true,
  })

  dispatch(sessionRestored({ principal }))
  // Anything this guard captured before the session ended is theirs again; anyone else's is discarded.
  await startOfflineRuntime()
}

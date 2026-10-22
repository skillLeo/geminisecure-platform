/**
 * Signing out of a guard handset — deliberate, and said honestly.
 *
 * IT IS A REAL SIGN-OUT NOW (M6, Q-M42). It used to be a local act only: the phone deleted
 * its own copy of the token and the row on the server lived for ever, so a token ever
 * copied off a handset kept working. The order below is the order it has to be in —
 *
 *   1. drain        whatever is waiting gets one last chance to go, while the token still works
 *   2. push         de-register this handset, while the token still works
 *   3. the server   DELETE /session destroys the token. LAST, because everything above needs it
 *   4. the phone    clearEverything: the secure store, the key-value store, the caches, the queue
 *
 * THE PHONE STAYS THIS GUARD'S (M32 F3). A sign-out ends the session; the binding — the phone's own key, its install
 * id and its binding record, all in the secure store — is kept, so coming back is the guard's fingerprint (or a
 * supervisor's PIN) on the unlock screen, never a code. The screen says so before the person taps. "Remove this app
 * from this phone" (Closing your account) is the other thing: it is told `removeFromPhone`, this phone remembers that
 * the guard removed it, and setting it up again takes a code from a supervisor — which that screen says.
 *
 *   - It is refused while a duress alert is active: the alert's screen is the guard's line to help.
 *   - Whatever is waiting is given one last chance to go (drain) before anything is deleted; what
 *     still cannot be sent is COUNTED for the guard, who decides with the number in front of them.
 *   - With no signal the server cannot be told. The guard is signed out of the PHONE anyway —
 *     they asked to be — and the screen says beforehand that the session on the server ends by
 *     itself within the idle window. What it never does is claim the server was told.
 *   - It is asked in a ConfirmSheet (More, Delete my account), done ONCE however fast it is pressed,
 *     and a sign-out that did not finish says so in the sheet instead of doing nothing.
 */

import { useCallback, useRef, useState } from 'react'
import { router } from 'expo-router'
import { guardApi } from '@gs/core/api/guard'
import { clearEverything, endServerSession, signedOut } from '@gs/core/auth'
import { clearNotifications } from '@gs/core/notify'
import { signedOutRoute } from '@/features/boot/nextRoute'
import { isLive, useActiveDuress } from '@/features/duress/useDuress'
import { REMOVED_BY_YOU, binding } from '@/features/enrolment/binding'
import { pushRegistration } from '@/features/notify/pushRegistration'
import { flush as flushPositions, stopDuty } from '@/features/tracking/dutyTracker'
import { drainNow, forgetQueue } from '@/offline/runtime'
import { useAppDispatch, useAppSelector } from '@/store'

/** What sign-out says while a SILENT duress is live: nothing a bystander could read an alert into (B130). */
export const SILENT_BLOCK = 'Signing out is not available right now. Try again in a few minutes.'

const SIGN_OUT_UNFINISHED = 'Signing out did not finish, so you are still signed in on this phone. Try again.'

export function useSignOut({ removeFromPhone = false }: { removeFromPhone?: boolean } = {}) {
  const dispatch = useAppDispatch()
  const duress = useActiveDuress()
  const online = useAppSelector((s) => s.network.online)
  const unsent = useAppSelector((s) => s.outbox.ops.filter((op) => op.state === 'pending' || op.state === 'syncing').length)
  const [asking, setAsking] = useState(false)
  const [working, setWorking] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  // Once, however fast it is pressed: `working` is a render behind a second tap in the same moment.
  const leaving = useRef(false)

  // The alert, not the leftover record of one (B124): a closed or refused alert no longer refuses sign-out for good.
  const live = isLive(duress)

  const confirm = useCallback(async () => {
    if (working || live) return
    if (leaving.current) return
    leaving.current = true
    setWorking(true)
    setProblem(null)
    try {
      await drainNow().catch(() => undefined)
      // The positions taken on duty get their last chance too; then the service stops and nothing of it stays.
      await flushPositions({ force: true }).catch(() => undefined)
      await stopDuty().catch(() => undefined)
      // While the API token still exists: the server stops pushing this guard's alerts to this handset.
      // Best effort and never queued — with no signal the phone gives up its device token instead. It never throws.
      await pushRegistration.signOut()
      await clearNotifications()
      /*
       * AND THE TOKEN ITSELF, on the server. Last of the three network acts, because it is
       * the one that makes the other two impossible. Bounded and never retried; a phone with
       * no signal is signed out of itself regardless and the session times out on its own.
       */
      await endServerSession(() => dispatch(guardApi.endpoints.authSignOut.initiate(undefined, { track: false })))
      await clearEverything('guard', dispatch, 'signed_out')
      forgetQueue() // the queue's copy in memory goes with the one on disk, so "deleted" is true
      // Removed from this phone by the guard's own choice: the phone keeps that it was, and asks for a code next time.
      if (removeFromPhone) await binding.markRemoved({ message: REMOVED_BY_YOU, at: new Date().toISOString() })
      dispatch(signedOut())
      // Still this guard's phone: signing back in with a fingerprint. Removed: enrolment, which says why.
      router.replace(signedOutRoute() as never)
    } catch {
      // Said in the sheet, never swallowed: the guard is still signed in, and can try again.
      leaving.current = false
      setProblem(SIGN_OUT_UNFINISHED)
    } finally {
      setWorking(false)
    }
  }, [working, live, dispatch, removeFromPhone])

  return {
    asking,
    working,
    /*
     * A SILENT alert is never named (B130). The person who wants this phone signed out may be the one the guard
     * raised it about, and a red "You have an active duress alert" told them exactly what had happened. Refused
     * all the same — signing out clears the queue, and a held alert must not go with it — in words that say nothing.
     */
    blocked: !live ? null : duress?.mode === 'silent' ? SILENT_BLOCK : 'You have an active duress alert. Cancel it, or wait for the control room to close it, before signing out.',
    unsent,
    online,
    /** Signing out did not finish — shown inside the sheet, which stays open. */
    problem,
    ask: () => {
      setProblem(null)
      setAsking(true)
    },
    // Not while it is on its way: closing the question would hide what became of it.
    dismiss: () => {
      if (leaving.current) return
      setAsking(false)
    },
    confirm: () => void confirm(),
  }
}

/**
 * WHEN the phone tells the server where to reach it — the moments, as a hook each app's notifier
 * mounts once. WHAT happens at each moment is `registration.ts`; this file only decides when to look.
 *
 *   a session becomes usable           register (never awaited by boot — the app is already on screen)
 *   the connection comes back          …if the last try failed for lack of one
 *   permission is granted later        the screen that asked nudges; a return from the phone's Settings
 *                                      is caught by the app coming to the foreground
 *   a notification switch changes      the prefs nudge — the server has to learn what this phone muted
 *   the OS rolls the device token      register the new one
 *   the server revoked the session     give the device token up and forget
 *
 * Sign-out is NOT here: it must happen BEFORE the API token is dropped, so each app's sign-out path calls
 * `registration.signOut()` itself.
 *
 * In Expo Go, on the web and without an EAS project id, every one of these ends at "no token" without a
 * sound, and the local notifier goes on doing the job while the app is open.
 */

import { useEffect, useRef } from 'react'
import { AppState } from 'react-native'
import { onPushRegistrationNudge, type PushRegistration, type QuietReport, type SyncOutcome } from './registration'
import { onPushTokenChange } from './runtime'

export type PushRegistrationInput = {
  registration: PushRegistration
  /** "guard:41" · "resident:7" — null while nobody is signed in. */
  owner: string | null
  online: boolean
  /** The categories switched off on this phone, read at the moment of registering. */
  muted: () => string[]
  /** The Resident App only: this phone's quiet hours, read at the moment of registering. */
  quiet?: () => QuietReport
  /** The session just ended because the SERVER ended it (a 401). */
  revoked: boolean
  /** Board-fidelity runs and tests: do nothing at all. */
  disabled?: boolean
}

export function usePushRegistration({ registration, owner, online, muted, quiet, revoked, disabled = false }: PushRegistrationInput): void {
  const lastOutcome = useRef<SyncOutcome | null>(null)
  const latest = useRef({ owner, online, muted, quiet })
  latest.current = { owner, online, muted, quiet }

  useEffect(() => {
    if (disabled) return
    const look = (): void => {
      const now = latest.current
      if (!now.owner || !now.online) return
      void registration.sync(now.owner, now.muted(), now.quiet?.()).then((outcome) => { lastOutcome.current = outcome }).catch(() => undefined)
    }

    look() // the session is usable, or the connection just came back

    const stopNudge = onPushRegistrationNudge(look)
    const stopRotation = onPushTokenChange(look)
    // Back from the phone's Settings, where notifications may just have been allowed. Only then: asking Expo
    // for the token is a network call, and it is not made on every return to the app.
    const appState = AppState.addEventListener('change', (state) => { if (state === 'active' && (lastOutcome.current === 'not_permitted' || lastOutcome.current === 'failed')) look() })

    return () => { stopNudge(); stopRotation(); appState.remove() }
  }, [registration, owner, online, disabled])

  useEffect(() => {
    if (!disabled && revoked) void registration.revoked()
  }, [registration, revoked, disabled])
}

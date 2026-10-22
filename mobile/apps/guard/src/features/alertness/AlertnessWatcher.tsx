/**
 * Finds the alertness check the server has issued, and brings the guard to it — mounted once, in the
 * root layout, beside the SessionGate.
 *
 * There is no push in Expo Go, so while a guard is signed in and the app is in front the watcher asks
 * sync.pull every 20 s (a check lives two minutes). The pull is read-only and asks for nothing older
 * than "now", so it carries almost nothing but the pending checks. A development build with remote
 * push wakes the same code from the notification (DEV_BUILD_REQUIRED.md).
 *
 * Its answer is the ONLY list of open checks on the phone (`openChecks`): the dashboard's NEXT CHECK
 * reads it too, instead of sending a second, seven-day pull of its own every minute (M11 A4).
 *
 * It NEVER covers a duress alert or a report being written — the check waits (see alertness.ts).
 */

import { useEffect, useRef, useSyncExternalStore } from 'react'
import { AppState } from 'react-native'
import { router, usePathname } from 'expo-router'
import { isFidelity, now } from '@gs/core'
import { isLive, useActiveDuress } from '@/features/duress/useDuress'
import { pullDeltas } from '@/features/notify/poller'
import { store, useAppSelector } from '@/store'
import { POLL_MS, clockOffset, mayPresent, pickCheck } from './alertness'
import { consent, openChecks } from './state'

async function pull(): Promise<void> {
  // The app's ONE "what changed?" call (features/notify/poller.ts): it also raises this phone's notifications.
  const data = await pullDeltas()
  if (!data) return // no signal, or refused: nothing is known, so nothing is shown
  openChecks.set({ checks: data.alertness_checks ?? [], offsetMs: clockOffset(data.server_time, now()), pulledAt: now().getTime() })
}

let inFlight: Promise<void> | null = null

/**
 * One pull at a time, whoever asks — the watcher's timer, or a guard pulling the dashboard down to
 * refresh it. A second ask while one is on the wire waits for that one instead of sending another.
 * Nothing is asked without a session: a pull with no token would only earn a 401.
 */
export function pullChecks(): Promise<void> {
  if (store.getState().session.status !== 'active') return Promise.resolve()
  inFlight ??= pull().catch(() => undefined).finally(() => { inFlight = null })
  return inFlight
}

export function AlertnessWatcher() {
  const active = useAppSelector((s) => s.session.status === 'active')
  const online = useAppSelector((s) => s.network.online)
  const pathname = usePathname()
  const duress = useActiveDuress()
  const open = useSyncExternalStore(openChecks.subscribe, openChecks.get, openChecks.get)
  const presented = useRef(new Set<string>())

  useEffect(() => {
    if (isFidelity || !active || !online) return
    const tick = () => {
      if (inFlight || AppState.currentState !== 'active') return
      void pullChecks()
    }
    tick()
    const timer = setInterval(tick, POLL_MS)
    const sub = AppState.addEventListener('change', (state) => { if (state === 'active') tick() })
    return () => { clearInterval(timer); sub.remove() }
  }, [active, online])

  useEffect(() => {
    if (isFidelity || !active) return
    const check = pickCheck(open.checks, now(), open.offsetMs)
    if (!check || presented.current.has(String(check.check_id))) return
    // Held back only while the alert is LIVE: a closed or refused one used to hold every check back, recorded as missed (B124).
    const verdict = mayPresent({ consent: consent.get(), duressActive: isLive(duress), pathname, alreadyOnScreen: pathname.startsWith('/alertness') })
    if (!verdict.present) return
    // Brought to the guard ONCE: if they step away from it, it is theirs to come back to (More → Alertness check), not a loop.
    presented.current.add(String(check.check_id))
    router.push('/alertness' as never)
  }, [active, open, pathname, duress])

  return null
}

/**
 * ON-DUTY SHARING, KEPT IN STEP WITH THE SHIFT (M11 §3) — mounted once, in the root layout.
 *
 * While a guard is signed in it:
 *   - reads `tracking.status` (the flag, the policy, the officer's answer, how to sample) and keeps it on the phone
 *     for the background task, which has no store to ask;
 *   - makes the phone agree with the shift: a shift that ended elsewhere stops the service, a shift under way whose
 *     service is not running (a reboot ends one) starts it again — only with the officer's consent and permission;
 *   - sends what is waiting when the app comes to the front and when the signal comes back.
 *
 * It never asks for a permission and never shows anything: the consent screen asks, after its words.
 * Nothing in fidelity mode.
 */

import { useEffect, useRef } from 'react'
import { AppState } from 'react-native'
import { isFidelity, useOfflineCache } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { useAppSelector } from '@/store'
import { flush, reconcileDuty, screenShown } from './dutyTracker'
import { trackingStore } from './trackingStore'

export function TrackingRuntime(): null {
  const active = useAppSelector((s) => s.session.status === 'active')
  const owner = useAppSelector((s) => s.session.principal?.reference ?? null)
  const online = useAppSelector((s) => s.network.online)
  const clockInWaiting = useAppSelector((s) => s.outbox.ops.some((o) => o.endpoint === 'shifts.clock_in' && (o.state === 'pending' || o.state === 'syncing')))
  const skip = isFidelity || !active

  const status = guardApi.useTrackingStatusQuery(undefined, { skip, refetchOnReconnect: true, refetchOnFocus: true })
  const shiftQuery = guardApi.useShiftsMeCurrentQuery(undefined, { skip })
  const shift = useOfflineCache('shift.current', shiftQuery.data)?.shift ?? null

  // This run has a screen: work Android runs with none no longer needs to ask the officer to open the app (D-214).
  useEffect(() => screenShown(), [])

  // The server's answer, kept for the task — for THIS officer only.
  useEffect(() => {
    if (!skip && owner && status.data) trackingStore.setStatus(owner, status.data)
  }, [skip, owner, status.data])

  // The phone agrees with the shift.
  const shiftId = shift?.id ?? null
  const onDuty = !!shift && shift.actual_end === null && (shift.status === 'on_duty' || clockInWaiting)
  // When the server last answered for the shift. A shift from the cache alone was read before this session: it may
  // start sharing (a reboot mid-shift) but never ends it (reconcileDuty's readAtMs).
  const readAtMs = shiftQuery.fulfilledTimeStamp ?? 0
  // Opening the app is the app coming to the front too (M22 §1.1): after an update or a restart there is no "came
  // forward" event, and the platform's restored request had no foreground service behind it. The first reconcile of
  // this launch on duty re-arms, once.
  const armedThisLaunch = useRef(false)
  useEffect(() => {
    if (skip) return
    const rearm = !armedThisLaunch.current && onDuty && owner !== null
    if (rearm) armedThisLaunch.current = true
    void reconcileDuty({ owner, shiftId, onDuty, readAtMs, rearm }).catch(() => undefined)
  }, [skip, owner, shiftId, onDuty, readAtMs, status.data])

  // What is waiting goes when the signal returns and when the app comes to the front.
  useEffect(() => {
    if (!skip && online) void flush({ force: true })
  }, [skip, online])
  // Coming back to the front also re-arms the service (M21 §1.2): what the platform dropped while the app was away
  // is asked for again, rather than trusted to still be running because the task is registered.
  useEffect(() => {
    if (skip) return
    const sub = AppState.addEventListener('change', (next) => {
      if (next !== 'active') return
      void flush({ force: true })
      void reconcileDuty({ owner, shiftId, onDuty, readAtMs, rearm: true }).catch(() => undefined)
    })
    return () => sub.remove()
  }, [skip, owner, shiftId, onDuty, readAtMs])

  return null
}

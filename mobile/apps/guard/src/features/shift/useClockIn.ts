/**
 * Everything the clock-in screen decides, in one place — boards guard-app-02 (ready),
 * -37 (outside the geofence), -38 (mock location flagged), -39 (biometric failed).
 *
 * The four checks of a shift start, and what each failure does:
 *   geofence            the ROSTERED POST's fence, else the estate's (M12 §1; useGeofence). Outside → the post,
 *                       the distance and the way to go, a fresh fix, a call to the supervisor, and the supervisor's
 *                       PIN (`outside_geofence`). No position established — refused, off, too inaccurate — said as
 *                       exactly that, with the way to put it right and the PIN (`position_unconfirmed`). No fence
 *                       configured: allowed, and the server marks it for the office.
 *   device binding      the token IS the binding: a rebound handset's token is revoked (401 → enrolment).
 *   location integrity  mocked → clock-in still allowed, flagged for the Head of Security (the server records, never refuses).
 *   identity            OS biometrics; 3 attempts, then the SUPERVISOR OVERRIDE. Nothing biometric is stored or sent.
 *
 * AN UNVERIFIED CLOCK-IN IS NEVER SILENTLY ACCEPTED. A phone with no fingerprint or face set up used
 * to start the shift anyway, marked `manual`. It no longer does: the ordinary path is blocked and the guard is
 * routed to the supervisor override — the same place three failed matches lead. A GUARD IS NEVER STRANDED:
 * that route is always offered the moment the ordinary one closes, and the duress button stays on this screen.
 *
 * NO SIGNAL NEVER STRANDS ANYBODY. The fence saved with the shift is judged on the phone, and a CONFIRMED
 * clock-in is captured on the phone first (outbox) with the fix and the time it actually happened, then sent
 * when it can be — the server judges it again when it arrives. Location refused or switched off is not "no
 * signal": it is said as what it is, with a button to put it right.
 */

import { useCallback, useEffect, useState } from 'react'
import { Linking, Platform } from 'react-native'
import { router } from 'expo-router'
import * as LocalAuthentication from 'expo-local-authentication'
import { deviceRun, fidelityScreenId, isFidelity, shiftName, steadyMs, time12, useOfflineCache, type Fence } from '@gs/core'
import type { Api } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { clockInFixtures } from '@/fidelity/fixtures'
import { startDuty } from '@/features/tracking/dutyTracker'
import { submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { MAX_ATTEMPTS, identityPrompt, identityStep, locationReasonOf, type Biometrics, type OverrideReason } from './override'
import { openLocationSettings, useGeofence, type Geo } from './useGeofence'

export { MAX_ATTEMPTS } from './override'
export type { Geo } from './useGeofence'
export type Shift = NonNullable<Api.ShiftsMeCurrentResponse['shift']>
export type Preflight = Api.ShiftsPreflightResponse

export type ClockInModel = {
  status: 'loading' | 'no_shift' | 'ready' | 'error'
  errorMessage: string | null
  who: string
  where: string
  when: string
  postName: string
  geo: Geo
  mocked: boolean
  supervisor: string | null
  bio: { attempt: number; failed: boolean; locked: boolean }
  /** The phone has no fingerprint or face set up: it cannot confirm anybody, so it starts nothing on its own. */
  noBiometrics: boolean
  /**
   * The SERVER said the phone's PASSCODE is an acceptable confirmation — true only for an
   * app-store reviewer's demonstration account, never for a real guard, and never from
   * a build flag. The screen says "passcode" rather than "sensor" when it is set.
   */
  passcodeIdentity: boolean
  /** Set when the ordinary path is closed and a supervisor's PIN is the way to start the shift. */
  override: OverrideReason | null
  busy: boolean
  canClockIn: boolean
  blockedReason: string | null
  online: boolean
  /** The ordinary start is closed because of WHERE the phone is (or cannot say) — not who holds it. */
  locationBlocked: boolean
  /** The estate's supervisor number, or null when none is set (then the control room is messaged instead). */
  supervisorPhone: string | null
  callSupervisor: () => void
  /** Location refused, or switched off: the settings screen that puts it right. */
  fixLocation: (() => void) | null
  recheck: () => void
  /** Retry and pull-to-refresh: the SHIFT asked for again, then the position (B131). */
  refresh: () => void
  clockIn: () => void
}

/** Can this phone check a fingerprint or a face at all? Asked of the OS; the answer is a yes or a no, nothing more. */
async function biometricsOf(): Promise<Biometrics> {
  if (Platform.OS === 'web') return 'none'
  try {
    return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync()) ? 'available' : 'none'
  } catch {
    return 'none'
  }
}

export function useClockIn(onDone: () => void): ClockInModel {
  const fixture = isFidelity ? clockInFixtures[fidelityScreenId() ?? ''] ?? null : null
  const principal = useAppSelector((s) => s.session.principal)
  const online = useAppSelector((s) => s.network.online)

  const query = guardApi.useShiftsMeCurrentQuery(undefined, { skip: !!fixture, pollingInterval: 60_000 })
  const cached = useOfflineCache('shift.current', query.data)
  const shift: Shift | null = fixture ? fixture.shift : (cached?.shift ?? null)

  // The fence cached with the shift at the last sync (M12 §1.4): what the phone judges against with no signal.
  const fence = (shift as { fence?: Fence | null } | null)?.fence ?? null
  const { geo, mocked, passcodeIdentity, lastFix, supervisorPhone: askedPhone, recheck } = useGeofence({ shiftId: shift?.id, online, fixture, fence })
  const supervisorPhone = askedPhone ?? (shift as { supervisor_phone?: string | null } | null)?.supervisor_phone ?? null
  const [attempt, setAttempt] = useState(fixture ? fixture.attempt : 0)
  const [biometrics, setBiometrics] = useState<Biometrics>(fixture ? 'available' : 'checking')
  const [busy, setBusy] = useState(false)

  // Asked when the screen opens, not only at the tap: a phone that cannot confirm anybody says so up front.
  useEffect(() => {
    if (fixture) return
    let alive = true
    void biometricsOf().then((found) => { if (alive) setBiometrics(found) })
    return () => { alive = false }
  }, [fixture])

  const step = identityStep(biometrics, attempt, passcodeIdentity)
  const locked = attempt >= MAX_ATTEMPTS
  // Where the phone is closes the ordinary start: outside, not established, or location refused / switched off.
  const locationReason = locationReasonOf(geo)
  const locationBlocked = locationReason !== null
  const checking = geo.state === 'checking'
  const outside = locationBlocked

  const clockIn = useCallback(async () => {
    if (!shift || busy || outside || checking || fixture || step.step !== 'confirm') return
    setBusy(true)
    try {
      /*
       * The fingerprint or face may have been removed since the screen opened: ask again, and
       * never assume. The ONE exception is an app-store reviewer's demonstration account, which
       * the SERVER has said may confirm with the phone's passcode instead — on that
       * account a handset with nothing enrolled is meant to reach the prompt, not be turned
       * back from it.
       *
       * AND THE PROMPT ITSELF ACCEPTS ONLY WHAT THAT ANSWER ALLOWS (`identityPrompt`, override.ts).
       * The passcode fallback used to be open on every handset, which let anybody holding the phone
       * and six digits start the shift — recorded as `app`, unflagged, unreported, and never reaching
       * the three-strikes route to the supervisor. It is now open for the reviewer's account alone.
       */
      if (!passcodeIdentity && (await biometricsOf()) !== 'available') return setBiometrics('none')
      const result = await LocalAuthentication.authenticateAsync(identityPrompt(passcodeIdentity))
      if (!result.success) return setAttempt((n) => n + 1)

      // Captured on the phone FIRST, with the time it happened; sent now if there is signal, later if not.
      // Only ever reached with the identity CONFIRMED by the phone — there is no unconfirmed start from here.
      await submit({
        endpoint: 'shifts.clock_in',
        params: { shift: shift.id },
        // No `method`: a handset no longer labels a start. THIS endpoint is the confirmed start;
        // the start the phone could not confirm is `shifts.clock_in_override`, and the server labels both.
        // The fix the start is judged on, and this run of the app and its steady clock at the tap (M12 §1.4).
        body: {
          ...(lastFix.current.latitude === null || lastFix.current.longitude === null ? null : { latitude: lastFix.current.latitude, longitude: lastFix.current.longitude }),
          ...(lastFix.current.accuracyM === null ? null : { accuracy_m: lastFix.current.accuracyM }),
          mock_location: lastFix.current.mocked,
          captured_run: deviceRun(),
          ...(steadyMs() === null ? null : { captured_mono_ms: steadyMs() }),
        },
        label: `Clock-in · ${shift.post.name}`,
        kind: 'clock',
      })
      // On-duty sharing starts with the shift — only if the officer agreed and the phone allows it (dutyTracker).
      if (principal?.reference) void startDuty({ owner: principal.reference, shiftId: shift.id }).catch(() => undefined)
      onDone()
    } finally {
      setBusy(false)
    }
  }, [shift, busy, outside, checking, fixture, step.step, passcodeIdentity, lastFix, onDone, principal?.reference])

  const status: ClockInModel['status'] = shift ? 'ready' : query.isLoading ? 'loading' : query.error && !cached ? 'error' : 'no_shift'

  // Retry and pull-to-refresh ran the geofence check alone (B131): a shift that had failed to load stayed failed,
  // and the guard's only way on was the minute-long poll. The shift is asked for again, then the position.
  const refetchShift = query.refetch
  const refresh = useCallback(() => {
    if (!fixture) void Promise.resolve(refetchShift()).catch(() => undefined)
    recheck()
  }, [fixture, refetchShift, recheck])

  return {
    status,
    errorMessage: query.error && 'message' in query.error ? String(query.error.message) : null,
    who: fixture ? fixture.who : `${(principal?.name ?? '').toUpperCase()}${principal?.reference ? ` · ${principal.reference}` : ''}`,
    where: shift ? `${shift.site.name}: ${shift.post.name}` : '',
    when: shift ? `${shiftName(shift.rostered_start)} · ${time12(shift.rostered_start)} – ${time12(shift.rostered_end)}` : '',
    postName: shift?.post.name ?? '',
    geo,
    mocked,
    supervisor: fixture ? fixture.supervisor : null,
    bio: { attempt, failed: attempt > 0 && !locked, locked },
    // A phone with nothing enrolled can still confirm a REVIEWER's demonstration account, with
    // its passcode — so it is not "cannot confirm anybody" and the sensor is offered.
    noBiometrics: biometrics === 'none' && !passcodeIdentity,
    passcodeIdentity,
    // Where the phone is comes first: a supervisor's PIN then vouches for where the guard is (M12 §1.3). A PIN can only
    // be checked by the server, so with no signal the route is not offered and the screen says why.
    // (A board draws outside the geofence with Clock in disabled and no PIN route — guard-app-37 — so a fixture keeps that.)
    override: !shift ? null : locationReason !== null ? (online && !fixture ? locationReason : null) : step.step === 'override' ? step.reason : null,
    busy,
    canClockIn: !!shift && !outside && !checking && step.step === 'confirm',
    locationBlocked,
    supervisorPhone,
    callSupervisor: () => {
      if (supervisorPhone) void Linking.openURL(`tel:${supervisorPhone.replace(/[^\d+]/g, '')}`).catch(() => undefined)
      else router.push('/messages/dispatch' as never)
    },
    fixLocation: geo.state === 'unavailable' && (geo.why === 'permission' || geo.why === 'services_off') ? () => openLocationSettings(geo.why as 'permission' | 'services_off') : null,
    blockedReason: geo.state === 'outside'
      ? `You are outside the ${shift?.post.name ?? 'post'} geofence. Move closer to the gate and try again.`
      : geo.state === 'poor_fix'
        ? 'Your position could not be established. Step into open sky and try again.'
      : geo.state === 'unavailable' && geo.why === 'permission'
        ? 'This app is not allowed to use your location, so where you are cannot be checked. Allow it in Settings.'
      : geo.state === 'unavailable' && geo.why === 'services_off'
        ? 'Location is switched off on this phone, so where you are cannot be checked. Turn it on.'
      : geo.state === 'unavailable' && geo.why === 'no_fix'
        ? 'No position came from this phone in time. Step into open sky and try again.'
      : checking
        ? 'Finding your position.'
      : step.step === 'wait'
        ? 'Checking what this phone can use to confirm it is you.'
        : step.step === 'override'
          ? step.reason === 'no_biometrics'
            ? 'This phone has no fingerprint or face set up, so it cannot confirm it is you. Ask your supervisor to start this shift with their PIN.'
            : 'Identity could not be confirmed after 3 attempts. Ask your supervisor to start this shift with their PIN.'
          : null,
    online,
    recheck,
    refresh,
    clockIn: () => void clockIn(),
  }
}

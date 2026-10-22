/**
 * Everything the clock-out screen decides, in one place — board guard-app-20.
 *
 *   running over    shown only once now() is past the rostered end; N = whole minutes over.
 *   shift summary   presence.summary: HOURS on post, checkpoints, incidents. Never pay.
 *   geofence        a fresh fix + shifts.preflight, the same way the clock-in checks it. ADVISORY here:
 *                   the clock-out endpoint takes no distance, the server refuses nothing on distance
 *                   (D-033), and a guard who cannot end a shift is a worse record than one who ended
 *                   it a few metres out. Outside / not checked is SHOWN honestly and never blocks.
 *   handover note   optional, max 500 (the API's limit); read by whoever relieves the post.
 *   identity        OS biometrics; 3 failed attempts, then the supervisor route. A cancelled prompt
 *                   is not a failed match. Nothing biometric is stored or sent. A phone with no
 *                   fingerprint or face set up (or the web build) cannot check anyone: the screen
 *                   SAYS so instead of promising a check it will not make, and the clock-out still goes.
 *   unsynced        GD-20's purpose, kept without its block (D-M164): a safety-critical record (an alert, an
 *                   incident report) still waiting to sync is NAMED, and the clock-out is saved BEHIND it. The
 *                   queue sends in the order things were saved and stops at the first that fails, so the
 *                   office always has the report before it hears the officer left — and an officer in a dead
 *                   spot is never kept on post by a report that cannot send (M11 §1.2, §1.7).
 *   sync state      a clock-out captured on this phone is shown as Pending sync / Syncing / Confirmed;
 *                   one the server REFUSED is shown as Not accepted with the server's sentence, and the
 *                   guard may try again (the refused record stays in the sync queue — append-only).
 *   403             the shift cannot be read on this handset → a plain explanation, no retry loop.
 *
 * The clock-out is captured on the phone FIRST (outbox), ordered AFTER a clock-in for the
 * same shift that is still waiting, so a whole shift worked with no signal replays in order.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Platform } from 'react-native'
import * as LocalAuthentication from 'expo-local-authentication'
import * as Location from 'expo-location'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, useFormState, useWatch, type Control } from 'react-hook-form'
import { z } from 'zod'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError, type Api } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { deviceUid } from '@gs/core/crypto'
import { SILENT_KIND } from '@gs/core/offline'
import { clockOutFixtures } from '@/fidelity/clockout'
// Setup's location disclosure. Nothing here may put the phone's location dialog up before it was read.
import { onboarding } from '@/features/enrolment/onboardingState'
import { identityPrompt } from '@/features/shift/override'
import { endDuty } from '@/features/tracking/dutyTracker'
import { outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'

export type Shift = NonNullable<Api.ShiftsMeCurrentResponse['shift']>

/** presence.summary, narrowed: the generated types infer two of the counts as strings. */
export type Summary = { shiftId: string | null; onPostMinutes: number; checkpointsScanned: number; checkpointsTotal: number; incidentsFiled: number }

export type Geo =
  | { state: 'checking' }
  | { state: 'unavailable'; why: 'permission' | 'no_fix' | 'offline' | 'not_surveyed' | 'unchecked' }
  | { state: 'inside'; boundaryM: number }
  | { state: 'outside'; boundaryM: number }

export type ClockOutFixture = { shift: Shift; summary: Summary; geo: Geo; supervisor: string | null; note: string }

export const NOTE_MAX = 500
export const MAX_ATTEMPTS = 3

const noteSchema = z.object({
  handover_note: z.string().trim().max(NOTE_MAX, `Keep the handover note to ${NOTE_MAX} characters or fewer.`),
})
export type NoteForm = z.infer<typeof noteSchema>

/** Records that must reach the office before the clock-out does (Build Spec GD-20; the queue's order sees to it, D-M164). */
const SAFETY_CRITICAL: readonly string[] = ['duress.store', 'alerts.store', 'incidents.store']

/** A cancelled prompt is not a face or finger that failed to match. */
const NOT_A_MISMATCH: readonly LocalAuthentication.LocalAuthenticationError[] = ['user_cancel', 'system_cancel', 'app_cancel']

export type ClockOutModel = {
  /** `denied`: the server answered 403 and nothing is saved on the phone — an explanation, not a retry. */
  status: 'loading' | 'not_on_duty' | 'clocked_out' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  postName: string
  /** Whole minutes past the rostered end, or 0 when the shift is not over. */
  overMinutes: number
  /** Only the board knows one: no endpoint names a supervisor or notifies one of an overrun. */
  supervisor: string | null
  summary: { state: 'loading' | 'ready' | 'unavailable'; onPost: string; checkpoints: string; incidents: string; fromCache: boolean }
  geo: Geo
  note: { control: Control<NoteForm>; added: boolean; length: number; error: string | null; open: boolean; toggle: () => void; close: () => void }
  /** `available`: null until the phone has answered; false when it has no biometrics to ask (or on web). */
  bio: { attempt: number; failed: boolean; locked: boolean; available: boolean | null }
  /** Labels of the unsynced safety-critical records that block the clock-out. */
  blockers: string[]
  /** The clock-in for this shift is still on the phone; the clock-out will be sent after it. */
  clockInWaiting: boolean
  /** Where a clock-out already captured on this phone stands. */
  captured: 'pending' | 'syncing' | 'confirmed' | null
  /** The server's sentence when the last clock-out for this shift was refused ("Not accepted"). */
  refusal: string | null
  /** The shift shown is the copy saved on this phone — the server has not answered this time. */
  shiftFromCache: boolean
  busy: boolean
  canClockOut: boolean
  blockedReason: string | null
  online: boolean
  refreshing: boolean
  refresh: () => void
  clockOut: () => void
}

const same = (a: unknown, b: unknown) => String(a) === String(b)
const last = <T,>(items: T[]): T | undefined => items[items.length - 1]

function narrow(s: Api.PresenceSummaryResponse): Summary {
  return {
    shiftId: s.shift_id === null ? null : String(s.shift_id),
    onPostMinutes: Number(s.on_post_minutes) || 0,
    checkpointsScanned: Number(s.checkpoints_scanned) || 0,
    checkpointsTotal: Number(s.checkpoints_total) || 0,
    incidentsFiled: Number(s.incidents_filed) || 0,
  }
}

export function useClockOut(onDone: () => void): ClockOutModel {
  const fixture = isFidelity ? clockOutFixtures[fidelityScreenId() ?? ''] ?? null : null
  const online = useAppSelector((s) => s.network.online)
  const outboxView = useAppSelector((s) => s.outbox.ops)

  const shiftQuery = guardApi.useShiftsMeCurrentQuery(undefined, { skip: !!fixture })
  const cachedShift = useOfflineCache('shift.current', shiftQuery.data)
  const shift: Shift | null = fixture ? fixture.shift : (cachedShift?.shift ?? null)
  const shiftId = shift?.id

  const summaryQuery = guardApi.usePresenceSummaryQuery(undefined, { skip: !!fixture })
  const cachedSummary = useOfflineCache('presence.summary', summaryQuery.data)

  const [preflight] = guardApi.useShiftsPreflightMutation()
  const [geo, setGeo] = useState<Geo>(fixture ? fixture.geo : { state: 'checking' })
  const [attempt, setAttempt] = useState(0)
  const [busy, setBusy] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const [noteOpen, setNoteOpen] = useState(false)
  const [tick, setTick] = useState(0)
  const [bioAvailable, setBioAvailable] = useState<boolean | null>(fixture ? true : null)
  // State lands a render late; two fast taps must not capture two clock-outs.
  const inFlight = useRef(false)

  const form = useForm<NoteForm>({ resolver: zodResolver(noteSchema), mode: 'onChange', defaultValues: { handover_note: fixture ? fixture.note : '' } })
  const noteValue = useWatch({ control: form.control, name: 'handover_note' }) ?? ''
  const noteErrors = useFormState({ control: form.control }).errors

  // The store's view of the outbox carries no path parameters, so the shift is matched on the
  // outbox itself; the store's list is what makes this re-run when the queue changes.
  const queue = useMemo(() => {
    const none = { clockInWaitingId: null as string | null, startedOnPhone: false, captured: null as ClockOutModel['captured'], refusal: null as string | null, blockers: [] as string[] }
    if (fixture || shiftId === undefined) return none
    const all = outbox.all()
    const live = (state: string) => state === 'pending' || state === 'syncing'
    const mine = all.filter((o) => same(o.params.shift, shiftId))
    const clockIns = mine.filter((o) => o.endpoint === 'shifts.clock_in')
    const out = last(mine.filter((o) => o.endpoint === 'shifts.clock_out'))
    return {
      clockInWaitingId: last(clockIns.filter((o) => live(o.state)))?.id ?? null,
      startedOnPhone: clockIns.some((o) => o.state !== 'refused'),
      // A refused clock-out has had its answer (it is in the sync queue as "Not accepted"); the guard may try again.
      captured: out && out.state !== 'refused' ? out.state : null,
      // …and is TOLD it was refused, in the server's own sentence — never a silent return to "Clock out".
      refusal: out && out.state === 'refused' ? (out.error?.message ?? 'The office did not accept it.') : null,
      // A SILENT duress is never named on any screen (a coercer reads screens too): it is still held and sent, it just never appears here.
      blockers: all.filter((o) => SAFETY_CRITICAL.includes(o.endpoint) && live(o.state) && o.kind !== SILENT_KIND).map((o) => o.label),
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fixture, shiftId, outboxView])

  // On duty: the server says so, or the clock-in is on this phone and the server has not heard yet.
  const onDuty = !!shift && shift.actual_end === null && (shift.status === 'on_duty' || ((shift.status === 'rostered' || shift.status === 'open') && queue.startedOnPhone))

  const check = useCallback(async () => {
    if (fixture || shiftId === undefined || !onDuty) return
    // Nothing about position is sent with a clock-out, so with no signal there is nothing a fix could be checked against.
    if (!online) return setGeo({ state: 'unavailable', why: 'offline' })
    setGeo({ state: 'checking' })
    try {
      // The dialog only after setup's disclosure said what a position is read for (useGeofence.ts
      // carries the full reason). Before that this only LOOKS at the permission and no dialog appears.
      const perm = onboarding.locationDisclosed() ? await Location.requestForegroundPermissionsAsync() : await Location.getForegroundPermissionsAsync()
      if (!perm.granted) return setGeo({ state: 'unavailable', why: 'permission' })
      const fix = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
      const answer = await preflight({
        shift: shiftId,
        latitude: fix.coords.latitude,
        longitude: fix.coords.longitude,
        accuracy_m: fix.coords.accuracy === null ? undefined : Math.round(fix.coords.accuracy),
        mock_location: fix.mocked === true,
        device_uid: await deviceUid(),
      })
      if ('error' in answer || !answer.data) {
        // "No signal" is only said when that is what happened; a server that ANSWERED no is not a dead zone.
        const e = 'error' in answer ? answer.error : null
        const unreachable = !isApiError(e) || e.status === 'network' || e.status === 'timeout'
        return setGeo({ state: 'unavailable', why: unreachable ? 'offline' : 'unchecked' })
      }
      // Only the geofence facts are read. `allowed` also folds in the CLOCK-IN window, which has nothing to say about leaving.
      const p = answer.data
      const distance = typeof p.distance_m === 'number' ? p.distance_m : Number(p.distance_m)
      if (p.within_geofence === null) return setGeo({ state: 'unavailable', why: 'not_surveyed' })
      if (Number.isNaN(distance)) return setGeo({ state: 'unavailable', why: 'no_fix' })
      setGeo({ state: p.within_geofence ? 'inside' : 'outside', boundaryM: Math.abs(Number(p.radius_m) - distance) })
    } catch {
      setGeo({ state: 'unavailable', why: 'no_fix' })
    }
  }, [fixture, shiftId, onDuty, online, preflight])

  useEffect(() => {
    void check()
  }, [check])

  // Asked up front so the screen never promises an identity check the phone cannot make.
  useEffect(() => {
    if (fixture) return
    let alive = true
    void (async () => {
      try {
        const ok = Platform.OS !== 'web' && (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync())
        if (alive) setBioAvailable(ok)
      } catch {
        if (alive) setBioAvailable(false)
      }
    })()
    return () => {
      alive = false
    }
  }, [fixture])

  // The banner counts up while the screen is open.
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setTick((n) => n + 1), 30_000)
    return () => clearInterval(timer)
  }, [])

  const rosteredEnd = shift?.rostered_end
  const overMinutes = useMemo(() => {
    if (!rosteredEnd) return 0
    const ms = now().getTime() - new Date(rosteredEnd).getTime()
    return ms > 0 ? Math.floor(ms / 60_000) : 0
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rosteredEnd, tick])

  const refetchShift = shiftQuery.refetch
  const refetchSummary = summaryQuery.refetch
  const refresh = useCallback(async () => {
    if (fixture) return
    setRefreshing(true)
    try {
      await Promise.all([check(), refetchShift(), refetchSummary()])
    } finally {
      setRefreshing(false)
    }
  }, [fixture, check, refetchShift, refetchSummary])

  const locked = attempt >= MAX_ATTEMPTS

  const clockOut = useCallback(async () => {
    if (!shift || !onDuty || busy || locked || fixture || inFlight.current) return
    inFlight.current = true
    setBusy(true)
    let finished = false
    try {
      if (!(await form.trigger())) return setNoteOpen(true)
      // Asked again at the press: a fingerprint may have been added or removed since the screen opened.
      const available = Platform.OS !== 'web' && (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync())
      setBioAvailable(available)
      if (available) {
        /*
         * THROUGH THE ONE RULE, like every other guard-app identity check — see `identityPrompt`. The phone's
         * passcode is not the guard's identity: it is six digits a colleague can be told, and clocking OUT with
         * it ends somebody else's shift and closes their paid hours. `false`, not the shift's `passcode_identity`,
         * and nobody is stranded by it: a handset with no fingerprint or face never reaches this line at all
         * (`available` is false three lines up and the check is skipped), so an app-store reviewer clocks out
         * either with their own fingerprint or with no prompt — never with a code somebody could pass on.
         */
        const result = await LocalAuthentication.authenticateAsync(identityPrompt(false, 'Confirm your identity to clock out'))
        if (!result.success) {
          if (!NOT_A_MISMATCH.includes(result.error)) setAttempt((n) => n + 1)
          return
        }
      }

      const note = form.getValues('handover_note').trim()
      // Captured on the phone FIRST, with the time it happened; sent after the clock-in it follows — and, being saved
      // last, after every report and alert still waiting (the queue keeps its order; D-M164).
      await submit({
        endpoint: 'shifts.clock_out',
        params: { shift: shift.id },
        body: note ? { handover_note: note } : {},
        label: `Clock-out · ${shift.post.name}`,
        kind: 'clock',
        after: queue.clockInWaitingId,
      })
      // Clock out and sharing stops: the last fix of the shift, then the service ends (dutyTracker.endDuty).
      void endDuty().catch(() => undefined)
      onDone()
      finished = true
    } finally {
      // After a capture the screen is leaving; staying "busy" keeps a second tap from landing on the way out.
      if (!finished) {
        inFlight.current = false
        setBusy(false)
      }
    }
  }, [shift, onDuty, busy, locked, fixture, form, queue.clockInWaitingId, onDone])

  // A saved summary is only this shift's if it says so; yesterday's hours are not today's.
  const fresh = summaryQuery.data
  const saved = !fresh && cachedSummary && shiftId !== undefined && same(cachedSummary.shift_id, shiftId) ? cachedSummary : undefined
  const summary: Summary | null = fixture ? fixture.summary : fresh ? narrow(fresh) : saved ? narrow(saved) : null

  const shiftError = shiftQuery.error
  const denied = !!shiftError && 'status' in shiftError && shiftError.status === 403
  const status: ClockOutModel['status'] = !shift
    ? shiftQuery.isLoading ? 'loading' : shiftQuery.error && !cachedShift ? (denied ? 'denied' : 'error') : 'not_on_duty'
    : busy ? 'ready' : queue.captured ? 'clocked_out' : onDuty ? 'ready' : 'not_on_duty'

  return {
    status,
    errorMessage: denied ? 'This handset is not allowed to read your shifts. Ask the office to check your enrolment.' : shiftError && 'message' in shiftError ? String(shiftError.message) : null,
    postName: shift?.post.name ?? '',
    overMinutes,
    supervisor: fixture ? fixture.supervisor : null,
    summary: {
      state: summary ? 'ready' : summaryQuery.isLoading ? 'loading' : 'unavailable',
      onPost: summary ? `${(summary.onPostMinutes / 60).toFixed(1)}h` : '—',
      checkpoints: summary ? `${summary.checkpointsScanned}/${summary.checkpointsTotal}` : '—',
      incidents: summary ? String(summary.incidentsFiled) : '—',
      fromCache: !fixture && !fresh && !!saved,
    },
    geo,
    note: {
      control: form.control,
      added: noteValue.trim().length > 0,
      length: noteValue.length,
      error: noteErrors.handover_note?.message ?? null,
      open: noteOpen,
      toggle: () => setNoteOpen((v) => !v),
      close: () => setNoteOpen(false),
    },
    bio: { attempt, failed: attempt > 0 && !locked, locked, available: bioAvailable },
    blockers: queue.blockers,
    clockInWaiting: queue.clockInWaitingId !== null,
    captured: queue.captured,
    refusal: queue.refusal,
    shiftFromCache: !fixture && !shiftQuery.data && !shiftQuery.isLoading && !!cachedShift,
    busy,
    canClockOut: !!shift && onDuty && !locked,
    blockedReason: locked ? 'Identity could not be confirmed after 3 attempts. Contact your supervisor to clock you out.' : null,
    online,
    refreshing,
    refresh: () => void refresh(),
    clockOut: () => void clockOut(),
  }
}

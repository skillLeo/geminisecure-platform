/**
 * The supervisor PIN override at clock-in — everything the screen decides.
 *
 *   "A guard is never stranded, and an unverified clock-in is never silently accepted."
 *
 * WHO, AND WHERE (M12 §1.3). The same geofence check the clock-in screen runs is run again here, from the
 * server's own answer (`shifts.preflight`), and it decides what the override is RECORDED as: outside the
 * rostered post's fence it is `outside_geofence`, with no position `position_unconfirmed` — whatever the link
 * that opened this screen said — so the Head of Security is told the true reason with the distance. A mocked
 * location does not block either path; it is sent, recorded and flagged, and the screen says so before the PIN
 * is typed.
 *
 * THE PIN NEVER RESTS ON THIS PHONE. It is held in React state while it is being typed and is wiped on
 * every answer, when the screen is left and when the app goes to the background. It is never written to
 * storage, never put in the outbox, never logged, and never in a URL: the endpoint is a POST, online only
 * (`NO_RETRY` — a refusal is never resent).
 *
 * NOTHING FROM A BIOMETRIC CHECK LEAVES THE PHONE (invariant 14). What is sent is `reason` — the single
 * word for WHY the phone could not confirm anybody (`biometric_failed` / `no_biometrics`) — and never a
 * score, a template, an image or an attempt count.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { AppState } from 'react-native'
import { shiftName, time12, useOfflineCache, type Fence } from '@gs/core'
import { isApiError, type ApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { useAppSelector } from '@/store'
import { cleanPin, locationReasonOf, overrideBlocked, overrideBody, refusalOf, validateOverride, type FieldErrors, type OverrideFields, type OverrideReason } from './override'
import { useGeofence, type Geo } from './useGeofence'
import type { Shift } from './useClockIn'

export type OverrideModel = {
  status: 'loading' | 'no_shift' | 'ready' | 'error' | 'forbidden'
  /** Why the ordinary path is closed, in the guard's words. */
  reason: OverrideReason
  where: string
  when: string
  postName: string
  geo: Geo
  mocked: boolean
  online: boolean
  /** Set when the shift could not be read at all. */
  errorMessage: string | null
  fields: OverrideFields
  fieldErrors: FieldErrors
  /** The server's one sentence for every kind of "no", or the phone's honest sentence for no signal. */
  problem: string | null
  /** The shift already has a start (a lost answer, or somebody started it another way). */
  alreadyStarted: boolean
  busy: boolean
  /** True only when a PIN can actually be checked: a shift, a connection, and the position worked out. */
  canSubmit: boolean
  blockedReason: string | null
  /**
   * The SERVER's word, for the app-store reviewer's demonstration account alone. No supervisor
   * holds a PIN for that account, so the screen draws NO FORM — it says so and sends them back to the
   * clock-in screen, where the phone's own passcode is an accepted confirmation. False for every real
   * guard, and no build flag sets it.
   */
  passcodeIdentity: boolean
  setSupervisor: (text: string) => void
  setPin: (text: string) => void
  recheck: () => void
  /** Retry and pull-to-refresh: the SHIFT asked for again, then the position (B131). */
  refresh: () => void
  submit: () => void
}

const EMPTY: OverrideFields = { supervisor: '', pin: '' }

export function useOverride(asked: OverrideReason, onStarted: () => void): OverrideModel {
  const online = useAppSelector((s) => s.network.online)
  const query = guardApi.useShiftsMeCurrentQuery(undefined, { pollingInterval: 60_000 })
  const cached = useOfflineCache('shift.current', query.data)
  const forbidden = isApiError(query.error) && query.error.status === 403
  const shift: Shift | null = forbidden ? null : (cached?.shift ?? null)

  const fence = (shift as { fence?: Fence | null } | null)?.fence ?? null
  const { geo, mocked, passcodeIdentity, lastFix, recheck } = useGeofence({ shiftId: shift?.id, online, fixture: null, fence })
  // Where the phone is NOW decides what the override is recorded as; the link's reason stands only when it is not in question.
  const reason: OverrideReason = locationReasonOf(geo) ?? asked
  /*
   * `forget` is not decoration. The data layer keeps the arguments of the last mutation against its cache
   * entry, and those arguments are the PIN: clearing the field would leave it sitting in memory behind the
   * screen until it happened to be unmounted. It is dropped on every answer, with the field.
   */
  const [start, { reset: forget }] = guardApi.useShiftsClockInOverrideMutation()

  const [fields, setFields] = useState<OverrideFields>(EMPTY)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<string | null>(null)
  const [alreadyStarted, setAlreadyStarted] = useState(false)
  const [busy, setBusy] = useState(false)
  const sending = useRef(false)

  // The PIN is wiped when the app is no longer in front, and again when the screen is left.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active') setFields((f) => ({ ...f, pin: '' }))
    })
    return () => {
      sub.remove()
      setFields(EMPTY)
    }
  }, [])

  const outside = geo.state === 'outside'
  const started = shift?.actual_start != null

  // The one rule, in one pure place (override.ts): the override replaces the identity check and nothing else.
  const blockedReason = overrideBlocked({
    hasShift: !!shift,
    alreadyStarted: started,
    outside,
    checking: geo.state === 'checking',
    online,
    postName: shift?.post.name ?? '',
    passcodeIdentity,
  })

  const canSubmit = blockedReason === null && !busy

  const submit = useCallback(async () => {
    if (!shift || !canSubmit || sending.current) return
    const errors = validateOverride(fields)
    setFieldErrors(errors)
    setProblem(null)
    setAlreadyStarted(false)
    if (Object.keys(errors).length > 0) return

    sending.current = true
    setBusy(true)
    const body = overrideBody(reason, fields, lastFix.current)
    try {
      const answer = await start({ shift: shift.id, ...body })
      if ('error' in answer && answer.error) {
        const unreachable: ApiError = { status: 'network', code: 'network', message: '', fieldErrors: {}, retryAfter: null }
        const refusal = refusalOf(isApiError(answer.error) ? answer.error : unreachable)
        setProblem(refusal.message)
        setFieldErrors(refusal.fields)
        setAlreadyStarted(refusal.alreadyStarted)
        return
      }
      onStarted()
    } finally {
      // Whatever the answer was: the PIN is gone from this phone before anything else happens —
      // out of the field, and out of the data layer's record of what was sent.
      setFields((f) => ({ supervisor: f.supervisor, pin: '' }))
      forget()
      sending.current = false
      setBusy(false)
    }
  }, [shift, canSubmit, fields, reason, lastFix, start, forget, onStarted])

  // As on Clock In (B131): Retry and pull-to-refresh reload the shift, not only the position.
  const refetchShift = query.refetch
  const refresh = useCallback(() => {
    void Promise.resolve(refetchShift()).catch(() => undefined)
    recheck()
  }, [refetchShift, recheck])

  const status: OverrideModel['status'] = forbidden
    ? 'forbidden'
    : shift
      ? 'ready'
      : query.isLoading
        ? 'loading'
        : query.error && !cached
          ? 'error'
          : 'no_shift'

  return {
    status,
    reason,
    where: shift ? `${shift.site.name}: ${shift.post.name}` : '',
    // Every time this app shows is the SITE's, by its zone name — never the handset's idea of it.
    when: shift ? `${shiftName(shift.rostered_start)} · ${time12(shift.rostered_start)} – ${time12(shift.rostered_end)}` : '',
    postName: shift?.post.name ?? '',
    geo,
    mocked,
    online,
    errorMessage: forbidden
      ? 'This handset is not allowed to read your shift. Contact your supervisor.'
      : isApiError(query.error)
        ? query.error.message
        : null,
    fields,
    fieldErrors,
    problem,
    // From the server's 409, or from the roster this phone already holds: either way there is nothing to override.
    alreadyStarted: alreadyStarted || started,
    busy,
    canSubmit,
    blockedReason,
    passcodeIdentity,
    setSupervisor: (text: string) => setFields((f) => ({ ...f, supervisor: text })),
    setPin: (text: string) => setFields((f) => ({ ...f, pin: cleanPin(text) })),
    recheck,
    refresh,
    submit: () => void submit(),
  }
}

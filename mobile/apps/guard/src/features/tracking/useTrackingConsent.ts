/**
 * The officer's answer to the location policy (M11 §3.2) — everything the consent screen decides.
 *
 *   agree    the answer goes to the server FIRST (nothing is shared until it has it), then the phone's own two
 *            dialogs — "while using the app", then "all the time". Only this, a tap on Agree after the words,
 *            raises them. With "all the time" refused nothing is shared, and the screen says so and offers the
 *            phone's settings. On duty already, sharing starts at once, without a clock-in fix.
 *   refuse   recorded as the officer's answer. An HR matter, not a crash: the app goes on working, the control room
 *            reads "position not shared", and anything running stops.
 *
 * ONLINE ONLY. An answer given with no signal could not reach the server, which keeps nothing without it — so the
 * buttons say so rather than pretend.
 */

import { useCallback, useEffect, useState } from 'react'
import { Linking } from 'react-native'
import { useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { time12, withClockTag } from '@gs/core/lib'
import { useAppSelector } from '@/store'
import { askPermissions, reconcileDuty, stopSharing } from './dutyTracker'
import { trackingStore } from './trackingStore'
import { POLICY_VERSION, consentNeeded, mayShare, policyOutOfDate, policyText, recordingLine, type RecordingLine, type TrackingStatus } from './trackingPolicy'

export type ConsentModel = {
  status: 'loading' | 'error' | 'off' | 'out_of_date' | 'ready'
  errorMessage: string | null
  online: boolean
  /** The officer's current answer, in words, or null when they have not answered these words. */
  answer: string | null
  sections: { heading: string; body: string }[]
  needed: boolean
  busy: boolean
  /** After Agree: the phone does not allow location all the time, so nothing is shared. */
  permissionProblem: string | null
  canOpenSettings: boolean
  problem: string | null
  /** On duty and sharing: when a position was last recorded and sent, or that none has been (M22 §1.1). */
  recording: RecordingLine | null
  agree: () => void
  refuse: () => void
  openSettings: () => void
  retry: () => void
}

const answerWords = (status: TrackingStatus | undefined): string | null => {
  const c = status?.consent
  if (!c || c.policy_version !== status?.policy_version) return null
  return c.decision === 'granted' ? 'You agreed: your location is shared with the control room while you are on duty.' : 'You said no: your location is not shared. The control room sees “position not shared”.'
}

export function useTrackingConsent(onDone: () => void): ConsentModel {
  const online = useAppSelector((s) => s.network.online)
  const owner = useAppSelector((s) => s.session.principal?.reference ?? null)
  const clockInWaiting = useAppSelector((s) => s.outbox.ops.some((o) => o.endpoint === 'shifts.clock_in' && (o.state === 'pending' || o.state === 'syncing')))
  const shiftQuery = guardApi.useShiftsMeCurrentQuery(undefined)
  const shift = useOfflineCache('shift.current', shiftQuery.data)?.shift ?? null
  const onDuty = !!shift && shift.actual_end === null && (shift.status === 'on_duty' || clockInWaiting)
  const query = guardApi.useTrackingStatusQuery(undefined)
  const [decide] = guardApi.useTrackingConsentMutation()
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [permissionProblem, setPermissionProblem] = useState<string | null>(null)
  const [canOpenSettings, setCanOpenSettings] = useState(false)

  const status = query.data

  // What the phone has recorded this shift, read again every thirty seconds while the screen is open (M22 §1.1).
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000)
    return () => clearInterval(timer)
  }, [])
  const duty = trackingStore.duty()
  const lastTaken = trackingStore.recent().at(-1)?.captured_at ?? null
  const lastSent = trackingStore.lastSentAt()
  const recording = recordingLine({
    sharing: onDuty && duty !== null && mayShare(status),
    startedMs: duty ? Date.parse(duty.startedAt) : null,
    lastTakenMs: lastTaken === null ? null : Date.parse(lastTaken),
    lastSentMs: lastSent === null ? null : Date.parse(lastSent),
    nowMs: now,
    clock: (ms) => withClockTag(time12(new Date(ms))),
  })

  const answer = useCallback(
    async (decision: 'granted' | 'refused') => {
      if (busy) return
      if (!online) return setProblem('No connection. Your answer has to reach the office before anything changes: connect, then answer.')
      setBusy(true)
      setProblem(null)
      setPermissionProblem(null)
      try {
        const result = await decide({ decision, policy_version: POLICY_VERSION })
        if ('error' in result && result.error) {
          const e = result.error
          return setProblem(isApiError(e) ? e.message : 'Your answer could not be sent. Try again.')
        }
        if (owner && result.data) trackingStore.setStatus(owner, result.data)

        if (decision === 'refused') {
          await stopSharing()
          return onDone()
        }

        const allowed = await askPermissions()
        if (!allowed.background) {
          // The phone's settings are the way on either way: Android shows "all the time" only there from version 11.
          setCanOpenSettings(true)
          return setPermissionProblem(
            allowed.foreground
              ? 'Your answer is recorded, but this phone allows your location only while the app is open, so nothing is shared. Choose “Allow all the time” for GeminiSecure Guard in the phone’s settings.'
              : 'Your answer is recorded, but this phone does not allow the app your location, so nothing is shared. Allow it in the phone’s settings, and choose “Allow all the time”.',
          )
        }
        // On duty already: sharing starts now, without a clock-in fix — it is not a clock-in.
        if (owner) await reconcileDuty({ owner, shiftId: shift?.id ?? null, onDuty }).catch(() => undefined)
        onDone()
      } catch {
        setProblem('Your answer could not be sent. Try again.')
      } finally {
        setBusy(false)
      }
    },
    [busy, online, decide, owner, onDone, shift?.id, onDuty],
  )

  const state: ConsentModel['status'] = status
    ? !status.enabled
      ? 'off'
      : policyOutOfDate(status)
        ? 'out_of_date'
        : 'ready'
    : query.error
      ? 'error'
      : 'loading'

  return {
    status: state,
    errorMessage: isApiError(query.error) ? query.error.message : query.error ? 'The location policy could not be loaded.' : null,
    online,
    answer: answerWords(status),
    sections: policyText(status?.retention_days),
    needed: consentNeeded(status),
    busy,
    permissionProblem,
    canOpenSettings,
    problem,
    recording,
    agree: () => void answer('granted'),
    refuse: () => void answer('refused'),
    openSettings: () => void Linking.openSettings().catch(() => undefined),
    retry: () => void query.refetch(),
  }
}

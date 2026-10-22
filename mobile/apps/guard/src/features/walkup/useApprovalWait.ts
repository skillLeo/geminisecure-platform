/**
 * Waiting on the household — board guard-app-06.
 *
 *   POLL        gate.approvals.show every 3 s, until the server gives its last word (approved ·
 *               denied · expired) or refuses the question (a 4xx is an answer, never retried).
 *   COUNTDOWN   a real one, to the server's `respond_by`, ticking each second on now() (which
 *               fidelity freezes) and corrected for a handset clock that is wrong.
 *   NEVER HANGS approved → admit on the approval · denied → record the denial · expired → the
 *               estate's policy: admit on the guard's own decision, or deny. With no signal the
 *               count keeps running from the `respond_by` the phone already holds, and at zero the
 *               request is treated as expired — exactly as the server will read it.
 *   THE GUARD MAY STOP WAITING at any time ("Follow standing gate policy now") and decide.
 *
 * What the guard typed (purpose, plate) rides along as route params: the poll does not return
 * them. No photo exists anywhere in this flow.
 */

import { useEffect, useMemo, useState } from 'react'
import * as Haptics from 'expo-haptics'
import { fidelityScreenId, isFidelity, now, type IconName } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { waitingFixture } from '@/fidelity/walkup'
import { useAppSelector } from '@/store'
import { HOUSEHOLD_DECLINED, useGateRecord, type Recorded } from './useGateRecord'
import type { WaitingParams } from './useWalkUp'
import { approvalIdOf, countdown, isTerminal, ringOffset, subjectOf, waitCopy, waitView, type Countdown, type WaitCopy, type WaitView } from './walkup'

const POLL_MS = 3_000

/** Said at once when the server reports that nobody in the household can answer in the app. */
export const CANNOT_ANSWER = "Nobody in this household can answer in the app: call them, or apply the estate's policy."

export type ApprovalWaitModel = {
  status: 'loading' | 'missing' | 'not_found' | 'forbidden' | 'error' | 'ready'
  errorMessage: string | null
  view: WaitView
  copy: WaitCopy
  count: Countdown
  ringOffset: number
  lines: { icon: IconName; text: string }[]
  standingNote: string
  /** The poll is not getting through; the count carries on from what the phone holds. */
  noSignal: boolean
  /**
   * Nobody in this household can answer in the app (the server's `household_can_answer: false`): the sentence is
   * shown at once, the policy is offered straight away, and no wait is started.
   */
  cannotAnswer: string | null
  /** The guard chose not to wait any longer. */
  deciding: boolean
  decideNow: () => void
  keepWaiting: () => void
  busy: boolean
  saveError: string | null
  recorded: Recorded | null
  /**
   * The household's visitors are held at the gate (M21 §1.7) — the server's `access_restricted`, or the form's until it
   * has answered. Approved or not, admitting is an override, with a reason; nothing says why.
   */
  restricted: boolean
  admit: () => void
  admitOnOwnDecision: () => void
  /** Admit against the gate's advice, with the guard's reason (at least 5 characters). */
  admitOnOverride: (reason: string) => void
  deny: () => void
  /** Deny a restricted household's visitor, approved or not — the gate held them. */
  denyRestricted: () => void
  retry: () => void
}

/** The fewest characters the server takes for an override's reason. */
export const OVERRIDE_REASON_MIN = 5

const first = (value: string | string[] | undefined): string => (Array.isArray(value) ? (value[0] ?? '') : (value ?? '')).trim()

export function useApprovalWait(params: Partial<Record<keyof WaitingParams, string | string[]>>): ApprovalWaitModel {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-06' ? waitingFixture : null
  const online = useAppSelector((s) => s.network.online)
  const siteName = useAppSelector((s) => s.session.principal?.siteName ?? null)

  const approvalId = fixture ? approvalIdOf(fixture.approval.approval_id) : approvalIdOf(first(params.id))
  const record = useGateRecord()
  const acted = record.recorded !== null
  const cannotAnswer = !fixture && first(params.cannotAnswer) === '1'

  // Nobody can answer: there is nothing to wait for, so nothing is asked again and nothing counts down.
  const [polling, setPolling] = useState(!cannotAnswer)
  const query = guardApi.useGateApprovalsShowQuery({ approval: approvalId ?? 0 }, { skip: !!fixture || approvalId === null, pollingInterval: polling && !acted ? POLL_MS : 0 })
  const approval = fixture ? fixture.approval : query.data
  const serverStatus = approval?.status ?? null

  const error = fixture ? undefined : query.error
  const refused = isApiError(error) && typeof error.status === 'number' && error.status >= 400 && error.status < 500
  useEffect(() => {
    if (isTerminal(serverStatus) || refused) setPolling(false)
  }, [serverStatus, refused])

  // Signal is back: ask straight away rather than on the next beat.
  const refetch = query.refetch
  useEffect(() => {
    if (!fixture && online && approvalId !== null && polling && !acted) void refetch()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online])

  // ── the count
  const requestedAt = approval?.requested_at ?? (first(params.requestedAt) || null)
  const respondBy = approval?.respond_by ?? (first(params.respondBy) || null)
  const skewMs = Number(first(params.skew)) || 0
  const running = !fixture && !cannotAnswer && !isTerminal(serverStatus) && !acted
  const [nowMs, setNowMs] = useState(() => now().getTime())
  useEffect(() => {
    if (!running) return
    const timer = setInterval(() => setNowMs(now().getTime()), 1_000)
    return () => clearInterval(timer)
  }, [running])

  const count = useMemo(() => countdown({ requestedAt, respondBy, nowMs, skewMs }), [requestedAt, respondBy, nowMs, skewMs])
  const view = waitView(serverStatus, count)

  // A gloved hand in the dark feels the answer arrive.
  useEffect(() => {
    if (isFidelity || view === 'waiting') return
    void Haptics.notificationAsync(view === 'approved' ? Haptics.NotificationFeedbackType.Success : view === 'denied' ? Haptics.NotificationFeedbackType.Error : Haptics.NotificationFeedbackType.Warning).catch(() => undefined)
  }, [view])

  const [wantsToDecide, setWantsToDecide] = useState(cannotAnswer)

  // ── who and where: the server's record once it has answered, what the form sent until then
  const visitor = approval?.visitor_name ?? first(params.visitor)
  const unit = approval?.unit ?? first(params.unit)
  const household = (approval ? approval.household : first(params.household)) || null
  const purpose = fixture ? fixture.purpose : first(params.purpose)
  const plate = first(params.plate)
  const subject = subjectOf(visitor, unit)
  const restricted = !fixture && (approval ? 'access_restricted' in approval && approval.access_restricted === true : first(params.restricted) === '1')

  const copy = waitCopy(view, { household, respondedBy: approval?.responded_by_name ?? null, status: serverStatus, guidance: approval?.guidance ?? null })

  const known = !!approval || (respondBy !== null && visitor.length > 0)
  const status: ApprovalWaitModel['status'] = fixture ? 'ready'
    : approvalId === null ? 'missing'
    : known ? 'ready'
    : isApiError(error) && error.status === 404 ? 'not_found'
    : isApiError(error) && error.status === 403 ? 'forbidden'
    : error ? 'error'
    : 'loading'

  const unreachable = isApiError(error) && (error.status === 'network' || error.status === 'timeout' || (typeof error.status === 'number' && error.status >= 500))

  return {
    status,
    errorMessage: isApiError(error) ? error.message : null,
    view,
    copy: fixture ? { ...copy, title: fixture.title, body: fixture.body } : copy,
    count,
    ringOffset: ringOffset(view === 'waiting' ? count.fraction : 1),
    lines: [
      { icon: 'tab-profile', text: `${visitor}${purpose ? ` · ${purpose}` : ''}` },
      { icon: 'veh-row', text: fixture ? fixture.vehicle : plate ? `Vehicle · ${plate}` : 'No vehicle plate given' },
      { icon: 'tab-profile', text: `Sent to ${unit}${household ? ` · ${household}` : ''}` },
    ],
    standingNote: fixture ? fixture.standingNote : `If nobody answers, ${siteName ?? 'the estate'}'s standing order for unannounced visitors applies.`,
    noSignal: !fixture && !cannotAnswer && !isTerminal(serverStatus) && !acted && (!online || unreachable),
    cannotAnswer: cannotAnswer ? CANNOT_ANSWER : null,
    deciding: wantsToDecide && view === 'waiting',
    decideNow: () => setWantsToDecide(true),
    keepWaiting: () => setWantsToDecide(false),
    busy: record.busy,
    saveError: record.saveError,
    recorded: record.recorded,
    restricted,
    admit: () => {
      // A restricted household's approval is not an admission (the server would refuse it): that is the override.
      if (fixture || view !== 'approved' || approvalId === null || restricted) return
      record.admitOnApproval(approvalId, visitor, subject)
    },
    admitOnOverride: (reason) => {
      if (fixture || reason.trim().length < OVERRIDE_REASON_MIN) return
      record.admitOnOverride(visitor, subject, reason)
    },
    admitOnOwnDecision: () => {
      if (fixture || view === 'approved' || view === 'denied') return
      record.admitOnOwnDecision(visitor, subject)
    },
    deny: () => {
      if (fixture || view === 'approved') return
      // After the household's "no", the gate log says it was theirs — not "Guard decision" (M22, found on the emulator).
      record.deny(visitor, subject, view === 'denied' ? HOUSEHOLD_DECLINED : undefined)
    },
    denyRestricted: () => {
      if (fixture || !restricted) return
      record.deny(visitor, subject)
    },
    retry: () => void refetch(),
  }
}

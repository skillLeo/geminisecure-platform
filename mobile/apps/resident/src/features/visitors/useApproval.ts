/**
 * Answering a guard who is holding a visitor at the gate — board resident-app-25.
 *
 * The request arrives on `me.household.pending_approvals` (live only — never from a cache: a
 * cached request could already be answered, or expired). The resident has until `respond_by`;
 * after that the estate's policy applies and the guard decides.
 *
 * ONLINE ONLY, by the server's own rule: "a late answer is no answer". An answer is never
 * queued. With no connection the screen says so and tells the resident what still works:
 * phone the gatehouse.
 *
 * WHAT THE SERVER DOES NOT HAVE: a photo of the visitor (none is stored — there is no ruling on
 * keeping images of the public), and a description of the vehicle beyond its plate. The board's photo
 * panel is drawn as the board draws it, an outline of a person, and never holds a picture; the card's
 * lines are what the guard typed.
 *
 * Fidelity mode swaps in the board's own moment here, and nowhere else: its visitor, its 01:24 with the
 * ring drawn three-quarters full, and a phone that is online.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import * as Haptics from 'expo-haptics'
import { isFidelity, now } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { approvalFixture } from '@/fidelity/visitors'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { useAppSelector } from '@/store'

const POLL_MS = 4000

export type ApprovalModel = {
  status: 'loading' | 'waiting' | 'answering' | 'approved' | 'denied' | 'expired' | 'gone'
  unit: string
  visitor: string
  purpose: string | null
  vehicle: string | null
  guard: string
  /** Which gate: "Main Gate". */
  post: string
  secondsLeft: number
  /** 0–1, for the ring: the time left of the three minutes (the board draws its own three-quarters). */
  fraction: number
  clock: string
  estate: string
  /** An answer cannot be sent with no connection, and is never kept for later. */
  online: boolean
  error: string | null
  /** Approved, but the household's visitors are held at the gate (the server's `visitors_held`): the guard cannot just let them in. */
  held: boolean
  answer: (decision: 'approve' | 'deny') => void
}

const WINDOW_SECONDS = 180

/**
 * SAID INSTEAD OF "LETTING THEM IN" (M22, found on the emulator). A household whose visitors are held at the gate is
 * not lifted by its own yes (D-213): the guard denies, or admits on the estate's authority. Where to find out why is
 * the household's own Dues page; nothing here says how much.
 */
export const HELD_LINE = "The guard has your answer, but your household's visitors are held at the gate for now, so the guard cannot just let them in. The estate office can tell you why: see Dues."

export function useApproval(id: string | undefined): ApprovalModel {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const principal = useAppSelector((s) => s.session.principal)
  const household = residentApi.useMeHouseholdQuery(undefined, { skip: isFidelity, pollingInterval: POLL_MS, refetchOnMountOrArgChange: true })
  const [respond] = residentApi.useApprovalsRespondMutation()

  const request = useMemo(() => (household.data?.pending_approvals ?? []).find((p) => String(p.id) === String(id)) ?? null, [household.data, id])
  // The request is remembered for the life of this screen, so an answer (which removes it from the list) still has something to show.
  const [seen, setSeen] = useState(request)
  useEffect(() => {
    if (request) setSeen(request)
  }, [request])

  const [outcome, setOutcome] = useState<'approved' | 'denied' | null>(null)
  const [held, setHeld] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [tick, setTick] = useState(0)
  useEffect(() => {
    if (isFidelity || outcome) return
    const timer = setInterval(() => setTick((n) => n + 1), 1000)
    return () => clearInterval(timer)
  }, [outcome])

  const secondsLeft = useMemo(() => {
    if (isFidelity) return approvalFixture.secondsLeft
    return seen ? Math.max(0, Math.ceil((new Date(seen.respond_by).getTime() - now().getTime()) / 1000)) : 0
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [seen, tick])

  const answer = useCallback(
    async (decision: 'approve' | 'deny') => {
      if (isFidelity || busy || !seen || outcome) return
      if (refusal) return setError(refusal)
      setBusy(true)
      setError(null)
      try {
        const result = await respond({ approval: seen.id, decision })
        if ('error' in result && result.error) {
          const e = result.error as ApiError
          if (e.code === 'approval_expired') return setError('The three minutes ran out before your answer arrived. The guard is following the estate’s gate policy: phone the gatehouse if you need to.')
          if (e.code === 'approval_decided') return setError('Someone in your household has already answered this request.')
          return setError(e.status === 'network' || e.status === 'timeout' ? "Your answer could not be sent: there's no connection. It is NOT saved for later: a late answer is no answer. Phone the gatehouse now." : e.message)
        }
        void Haptics.notificationAsync(decision === 'approve' ? Haptics.NotificationFeedbackType.Success : Haptics.NotificationFeedbackType.Warning).catch(() => undefined)
        setHeld('data' in result && result.data?.visitors_held === true)
        setOutcome(decision === 'approve' ? 'approved' : 'denied')
      } finally {
        setBusy(false)
      }
    },
    [busy, seen, outcome, respond, refusal],
  )

  const f = approvalFixture
  const status: ApprovalModel['status'] = isFidelity
    ? 'waiting'
    : outcome ?? (busy ? 'answering' : !seen ? (household.isLoading ? 'loading' : 'gone') : secondsLeft === 0 ? 'expired' : 'waiting')

  return {
    status,
    unit: isFidelity ? f.unit : (principal?.reference?.split(' · ').pop() ?? 'your home'),
    visitor: isFidelity ? f.visitor : (seen?.visitor_name ?? ''),
    purpose: isFidelity ? f.purpose : (seen?.purpose ?? null),
    vehicle: isFidelity ? f.vehicle : (seen?.vehicle_plate ?? null),
    guard: isFidelity ? f.guard : (seen?.guard_name ?? ''),
    post: isFidelity ? f.post : (seen?.post_name ?? 'the gate'),
    secondsLeft,
    fraction: isFidelity ? f.ringFraction : Math.min(1, secondsLeft / WINDOW_SECONDS),
    clock: `${String(Math.floor(secondsLeft / 60)).padStart(2, '0')}:${String(secondsLeft % 60).padStart(2, '0')}`,
    estate: isFidelity ? f.estate : (principal?.siteName ?? 'your estate'),
    online: isFidelity || online,
    error,
    held,
    answer: (decision) => void answer(decision),
  }
}

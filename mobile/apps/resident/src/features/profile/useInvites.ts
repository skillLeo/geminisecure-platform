/**
 * SENDING, RESENDING AND CANCELLING A PENDING MEMBER'S INVITE TO THE RESIDENT APP — the primary resident only.
 *
 * ONLINE ONLY (the contract's line for `members.invite` and `members.invite_cancel`): whether the invite went IS
 * the answer, so a press is never saved to send later. One press sends once; the answer — the member, with their
 * invite as it now stands — is drawn at once (the Resend countdown starts from it), and the household list is
 * read again (both mutations invalidate it).
 *
 * CANCELLING is asked first, in a ConfirmSheet over the screen ("Cancel Soft Dev's invite?", sheets.ts), driven by
 * confirmFlow: sent once, a refusal stays in the sheet (`cancelProblem`), and it closes only when it is cancelled.
 *
 * THE TIMER is the server's (1, then 2, then 5 minutes — `resend_after`). The phone counts it down and the
 * button unlocks when it runs out; a press the server still refuses (a phone whose clock runs ahead) comes back
 * with the server's own sentence, and the list is read again so the countdown is the server's once more.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import * as Haptics from 'expo-haptics'
import { useConfirmFlow } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { useAppSelector } from '@/store'
import { INVITE_OFFLINE, resendWait, type Invite, type Member } from './household'
import { CANCEL_INVITE_NO_ANSWER, CANCEL_INVITE_OFFLINE } from './sheets'

export type InviteControl = {
  online: boolean
  /** The phone's clock — a tick a second while a Resend is counting down. For `inviteActions` and `inviteLine`. */
  now: number
  /** The member with their invite as last answered here, when that is newer than the list. */
  withAnswer: <M extends Pick<Member, 'id' | 'invite'>>(member: M) => M
  /** Whose invite is on the wire, and doing what. */
  working: { id: string; what: 'send' | 'cancel' } | null
  /** What went wrong, beside the member it is about. */
  problem: { id: string; text: string } | null
  send: (member: Pick<Member, 'id'>) => void
  /** Cancelling is asked first, in a ConfirmSheet (confirmFlow), then confirmed: whose invite the sheet is about. */
  asking: Pick<Member, 'id' | 'full_name'> | null
  /** Why the cancel did not go — the server's sentence, or that it could not be asked — shown INSIDE that sheet. */
  cancelProblem: string | null
  ask: (member: Pick<Member, 'id' | 'full_name'>) => void
  keep: () => void
  confirmCancel: () => void
}

const SENT_NOT_KNOWN = 'The app could not tell whether that went through. Pull down to refresh, then try again if it still says so.'

/** The server's sentence, or the plain reason it could not be asked. */
const sentenceOf = (error: unknown): string =>
  isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? INVITE_OFFLINE : error.message) : SENT_NOT_KNOWN

/**
 * `shown` — the members whose invites are on screen (so the clock ticks only while one is counting down);
 * `listFetchedAt` — when the list they came from was last answered (RTK's `fulfilledTimeStamp`), so an answer
 * given here is drawn until the list has caught up with it, and never after. A screen with no list passes 0.
 */
export function useInvites({ shown, listFetchedAt }: { shown: readonly Pick<Member, 'id' | 'invite'>[]; listFetchedAt: number | undefined }): InviteControl {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const [sendInvite] = residentApi.useMembersInviteMutation()
  const [cancelInvite] = residentApi.useMembersInviteCancelMutation()
  const [answers, setAnswers] = useState<Record<string, { invite: Invite; at: number }>>({})
  const [working, setWorking] = useState<InviteControl['working']>(null)
  const [problem, setProblem] = useState<InviteControl['problem']>(null)

  const withAnswer = useCallback(<M extends Pick<Member, 'id' | 'invite'>>(member: M): M => {
    const answer = answers[String(member.id)]
    return answer && answer.at > (listFetchedAt ?? 0) ? { ...member, invite: answer.invite } : member
  }, [answers, listFetchedAt])

  // The clock runs only while something on screen is counting down; when the last wait runs out, it stops.
  const counting = useMemo(() => shown.some((member) => resendWait(withAnswer(member).invite, Date.now()) > 0), [shown, withAnswer])
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    setNow(Date.now())
    if (!counting) return
    const timer = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(timer)
  }, [counting])

  const answered = (id: string, invite: Invite) => setAnswers((was) => ({ ...was, [id]: { invite, at: Date.now() } }))

  const send = useCallback(async (member: Pick<Member, 'id'>) => {
    const id = String(member.id)
    if (working) return
    if (refusal) return setProblem({ id, text: refusal })
    if (!online) return setProblem({ id, text: INVITE_OFFLINE })
    setProblem(null)
    setWorking({ id, what: 'send' })
    try {
      const answer = await sendInvite({ member: id })
      if ('error' in answer && answer.error) return setProblem({ id, text: sentenceOf(answer.error) })
      if (!answer.data) return setProblem({ id, text: SENT_NOT_KNOWN })
      answered(id, answer.data.invite)
      // "Sent" only when the mail server accepted it — the answer says which.
      if (answer.data.invite.status === 'sent') void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
    } finally {
      setWorking(null)
    }
  }, [working, refusal, online, sendInvite])

  // Cancelling: asked in a sheet, sent ONCE, and a refusal stays in the sheet in the server's words. Its words are
  // about CANCELLING: offline it says that cancelling needs a connection, and no answer is said as not knowing.
  const cancelling = useConfirmFlow<Pick<Member, 'id' | 'full_name'>>({
    send: async (member) => {
      const id = String(member.id)
      if (refusal) return { ok: false, message: refusal }
      if (!online) return { ok: false, message: CANCEL_INVITE_OFFLINE }
      const answer = await cancelInvite({ member: id })
      if ('error' in answer && answer.error) {
        const error = answer.error
        return { ok: false, message: isApiError(error) && (error.status === 'network' || error.status === 'timeout') ? CANCEL_INVITE_NO_ANSWER : sentenceOf(error) }
      }
      if (answer.data) answered(id, answer.data.invite)
      return { ok: true }
    },
    failed: SENT_NOT_KNOWN,
  })
  // The sheet is about `asking`; a cancel closed on while it runs is still on its way, and still holds the controls.
  const cancelTarget = cancelling.open ? cancelling.target : null
  const cancelRunning = cancelling.working ? cancelling.target : null

  return {
    online,
    now,
    withAnswer,
    // While a cancel is on its way — in sight or not — nothing else about invites is pressed.
    working: cancelRunning ? { id: String(cancelRunning.id), what: 'cancel' } : working,
    problem,
    send: (member) => {
      if (cancelling.working) return
      void send(member)
    },
    asking: cancelTarget,
    cancelProblem: cancelling.problem,
    ask: (member) => {
      if (working) return
      setProblem(null)
      cancelling.ask(member)
    },
    keep: cancelling.cancel,
    confirmCancel: cancelling.confirm,
  }
}

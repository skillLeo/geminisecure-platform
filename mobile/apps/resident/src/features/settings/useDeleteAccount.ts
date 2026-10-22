/**
 * Deleting the account, for real.
 *
 * ONLINE ONLY, NEVER QUEUED. An account deletion that happened "later, when there is a signal"
 * would make the confirmation screen a lie, and would leave the person signed in meanwhile. So it
 * goes straight out, once, while the person watches — and if it cannot go, the screen says so.
 *
 * The moment the server says it is done, the phone is emptied the same way signing out empties it
 * (`closeSession`: the token, the caches, the queue) and the app goes to a screen OUTSIDE the
 * session that carries the reference. It is done in that order on purpose — a phone left holding a
 * dead token would have its next background read answered 401 and would announce "you were signed
 * out" over the confirmation. What was waiting to be sent goes with everything else, so it is
 * counted for the person BEFORE they confirm: they are entitled to know what will never arrive.
 */

import { useCallback, useState } from 'react'
import { router } from 'expo-router'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { money, moneySign } from '@gs/core/lib'
import { closeSession } from '@/features/auth/session'
import { useAppDispatch, useAppSelector } from '@/store'
import { CONFIRMATION, DEFAULT_WINDOWS, NEEDS_CONNECTION, confirmationTyped, debtLine, deleteProblem, restoreLine } from './deleteAccount'

export type DeleteAccountModel = {
  typed: string
  setTyped: (next: string) => void
  /** The person has typed the word exactly. */
  ready: boolean
  online: boolean
  working: boolean
  /** Acts saved on this phone that have not reached the estate, and will never arrive now. */
  unsent: number
  /** The server's own sentence when it refused, or why it could not be reached. */
  problem: string | null
  /**
   * This is a "Explore a demo community" session: there is no account of the person's to
   * delete, and the token has no write ability to do it with. The screen says so BEFORE they type,
   * rather than letting them type the word and reading a token error.
   */
  demo: boolean
  /** "Changed your mind?" — this estate's windows, in words (M18H §2.1). */
  restore: string
  /** What the home owes, and that it does not go away — null when it owes nothing, or it cannot be read. */
  debt: string | null
  confirm: () => void
}

export function useDeleteAccount(): DeleteAccountModel {
  const dispatch = useAppDispatch()
  const online = useAppSelector((s) => s.network.online)
  const demo = useAppSelector((s) => s.session.demo)
  const unsent = useAppSelector((s) => s.outbox.ops.filter((op) => op.state === 'pending' || op.state === 'syncing').length)
  const [deleteAccount, sending] = residentApi.useMeDeleteAccountMutation()

  // The estate's own windows, and what the home owes — both said BEFORE the word is typed (M18H §2.1).
  const me = residentApi.useMeShowQuery(undefined, { skip: demo })
  const householdId = me.data?.household?.id
  const balance = residentApi.useHouseholdsBalanceQuery({ household: householdId ?? '' }, { skip: demo || householdId === undefined || householdId === null })
  const windows = me.data?.account_deletion ?? DEFAULT_WINDOWS
  const owed = balance.data && moneySign(balance.data.balance) > 0 ? money(balance.data.balance) : null
  const [typed, setTyped] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [gone, setGone] = useState(false)

  const confirm = useCallback(async () => {
    if (sending.isLoading || gone) return
    if (!confirmationTyped(typed)) return
    if (!online) return setProblem(NEEDS_CONNECTION)
    setProblem(null)

    const answer = await deleteAccount({ confirm: CONFIRMATION })

    if ('error' in answer && answer.error) {
      const error = answer.error
      const known = isApiError(error)
      return setProblem(
        deleteProblem({
          demo,
          offline: known && (error.status === 'network' || error.status === 'timeout'),
          code: known ? error.code : null,
          message: known ? error.message : '',
        }),
      )
    }

    // Done on the server. Now the phone — and then a screen outside the session, carrying the reference and the dates.
    setGone(true)
    const reference = answer.data?.reference ?? ''
    const until = answer.data?.restore_until ?? ''
    const permanent = answer.data?.permanent_on ?? ''
    await closeSession(dispatch).catch(() => undefined)
    router.replace(`/(auth)/deleted?reference=${encodeURIComponent(reference)}&until=${encodeURIComponent(until)}&permanent=${encodeURIComponent(permanent)}` as never)
  }, [sending.isLoading, gone, typed, online, demo, deleteAccount, dispatch])

  return {
    typed,
    setTyped: (next: string) => {
      setProblem(null)
      setTyped(next)
    },
    ready: confirmationTyped(typed),
    online,
    working: sending.isLoading || gone,
    unsent,
    problem,
    demo,
    restore: restoreLine(windows),
    debt: debtLine(owed),
    confirm: () => void confirm(),
  }
}

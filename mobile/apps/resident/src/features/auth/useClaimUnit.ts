/**
 * Claiming a unit — the step between proving an email address and having an account that opens.
 *
 * A new account is `pending`: its token reaches only `GET /me` and this claim. The resident says
 * which unit is theirs; the ESTATE approves it on its claims screen, and that approval is the
 * only thing that links an account to a home (D-099 — never a matched email or phone).
 *
 *   ONLINE ONLY   the contract says so. With no connection the button says why; nothing is
 *                 queued and nothing is pretended.
 *   422           the server's sentence lands on the field it is about.
 *   409           `already_linked`: the account is already active — `/me` is read so the session
 *                 activates, and the app opens.
 *   a claim that is still pending is returned by the server AS IT IS, so a second form would
 *   change nothing: `/me` is read first and a resident with a claim in review is sent to the
 *   holding screen instead of being shown a form that cannot do anything.
 *   a REJECTED claim may be followed by a new one (the server creates it) — the earlier reason
 *   stays on the screen while they fill the form in again.
 */

import { useCallback, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, type Control } from 'react-hook-form'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useAppDispatch, useAppSelector } from '@/store'
import { claimBody, claimFieldErrors, claimSchema, claimView, type ClaimForm } from './claim'
import { refreshFromMe } from './session'
import { signInDraft } from './useSignIn'

export type ClaimOutcome = 'submitted' | 'already_linked'

export type ClaimUnitModel = {
  /** `denied`: the server answered 403 — the estate withdrew this account's access. */
  status: 'loading' | 'ready' | 'in_review' | 'denied'
  deniedMessage: string | null
  estateName: string | null
  /** Why the estate turned the last claim down, when that is why the resident is here again. */
  earlierRejection: { unit: string; reason: string | null } | null
  control: Control<ClaimForm>
  problem: string | null
  busy: boolean
  online: boolean
  submit: () => void
}

export function useClaimUnit(onDone: (outcome: ClaimOutcome) => void): ClaimUnitModel {
  const dispatch = useAppDispatch()
  const principal = useAppSelector((s) => s.session.principal)
  const sessionStatus = useAppSelector((s) => s.session.status)
  const online = useAppSelector((s) => s.network.online)

  const signedIn = sessionStatus === 'pending' || sessionStatus === 'active'
  const meQuery = residentApi.useMeShowQuery(undefined, { skip: !signedIn, refetchOnMountOrArgChange: true })
  const [fetchMe] = residentApi.useLazyMeShowQuery()
  const [claimUnit] = residentApi.useAuthClaimUnitMutation()
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const form = useForm<ClaimForm>({
    resolver: zodResolver(claimSchema),
    mode: 'onTouched',
    defaultValues: { full_name: signInDraft.get().fullName || principal?.name || '', lot: '', phase: '', phone: '', relationship: null },
  })

  const submit = useCallback(
    async (values: ClaimForm) => {
      if (busy) return
      setProblem(null)
      if (!online) return setProblem('No connection. A claim goes straight to your estate, so it needs one: what you typed stays here; try again when you are back online.')
      setBusy(true)
      try {
        const answer = await claimUnit(claimBody(values))
        if ('error' in answer && answer.error) {
          const e = answer.error
          if (!isApiError(e)) return setProblem('Something unexpected happened. Please try again.')
          if (e.code === 'already_linked') {
            // Already active on a unit: read the account so the session opens, then go in.
            const me = await fetchMe()
            if (me.data) await refreshFromMe(me.data, principal, true, dispatch)
            return onDone('already_linked')
          }
          if (e.status === 422) {
            const { fields, rest } = claimFieldErrors(e.fieldErrors)
            for (const [name, message] of Object.entries(fields)) form.setError(name as keyof ClaimForm, { type: 'server', message })
            return setProblem(rest ?? (Object.keys(fields).length ? null : e.message))
          }
          if (e.status === 'network' || e.status === 'timeout') return setProblem("The server can't be reached right now. Your claim was NOT sent: check your connection and try again.")
          return setProblem(e.status === 429 ? `Too many tries. ${e.retryAfter ? `Wait ${e.retryAfter} seconds` : 'Wait a minute'} and try again.` : e.message)
        }
        signInDraft.clear()
        onDone('submitted')
      } finally {
        setBusy(false)
      }
    },
    [busy, online, claimUnit, fetchMe, principal, dispatch, form, onDone],
  )

  const me = meQuery.data
  const view = me ? claimView(me) : null
  const meError = meQuery.error
  const denied = isApiError(meError) && meError.status === 403

  return {
    status: denied ? 'denied' : view && view.kind !== 'none' && view.kind !== 'rejected' ? 'in_review' : signedIn && meQuery.isLoading ? 'loading' : 'ready',
    deniedMessage: denied && isApiError(meError) ? meError.message : null,
    estateName: me?.estate.name ?? principal?.siteName ?? null,
    earlierRejection: view?.kind === 'rejected' ? { unit: view.claim.submitted_unit, reason: view.reason } : null,
    control: form.control,
    problem,
    busy,
    online,
    submit: () => void form.handleSubmit(submit)(),
  }
}

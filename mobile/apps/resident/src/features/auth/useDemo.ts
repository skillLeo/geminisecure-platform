/**
 * "EXPLORE A DEMO COMMUNITY" — the way into the demonstration estate.
 *
 * WHY IT EXISTS. The Resident App is login-walled and has no public sign-up: an App Store or
 * Play reviewer downloads it, is asked for a community and an email address, has neither, and
 * rejects it under Guideline 2.1 without seeing the product at all. This is the door. It
 * doubles as the sales demonstration the estate's committee is shown before they buy.
 *
 * WHAT IT IS NOT. It is not a mock, a fixture or a build flag: it is a real session against a
 * real estate that happens to hold invented data. The server hands back the Resident App's
 * abilities with every `:write` struck out, so nothing in the session can change anything —
 * in the demonstration estate or anywhere else. Tenant isolation applies to that estate
 * exactly as to a real one.
 *
 * THE CONTROL IS OFFERED ONLY WHEN THERE IS ONE. `estates.index` says which community is the
 * demonstration; with none listed, `demo.enter` would answer 404 every time and the button
 * would be a dead control.
 */

import { useCallback, useMemo, useState } from 'react'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useAppDispatch, useAppSelector } from '@/store'
import { demoRefusal } from './demoWrites'
import { demoEstate, estateOptions } from './estates'
import { openDemoSession } from './session'

/**
 * THE ONE LINE EVERY WRITE IN THIS APP BEGINS WITH.
 *
 *     const refusal = useDemoRefusal()
 *     …
 *     if (refusal) return setProblem(refusal)
 *
 * Null for a real resident and for the full demonstration account, so nothing about an ordinary
 * write changes. A sentence for the READ-ONLY "Explore a demo community" session, and returning
 * it stops the act BEFORE anything is queued or sent — which is what keeps the outbox from
 * telling a reviewer their report was saved when it can never arrive. `demoWrites.ts` has the
 * reasoning and the words; `__tests__/demoWrites.test.ts` holds every write hook to using this.
 */
export function useDemoRefusal(): string | null {
  return demoRefusal(useAppSelector((s) => s.session.demo))
}

export type DemoOutcome = { ok: true } | { ok: false; message: string }

/** The demonstration community this platform offers, or null. Drawn from the pick-list's own call. */
export function useDemoCommunity(): { estate: { id: string; name: string } | null; loading: boolean } {
  const list = residentApi.useEstatesIndexQuery(undefined)
  const estate = useMemo(() => demoEstate(estateOptions(list.data?.items)), [list.data])

  return { estate: estate ? { id: estate.id, name: estate.name } : null, loading: list.isLoading }
}

export function useEnterDemo() {
  const dispatch = useAppDispatch()
  const [enter] = residentApi.useDemoEnterMutation()
  const [busy, setBusy] = useState(false)

  const start = useCallback(async (): Promise<DemoOutcome> => {
    setBusy(true)
    try {
      const answer = await enter()
      if ('error' in answer && answer.error) {
        const e = answer.error as ApiError
        if (e.code === 'demo_unavailable') return { ok: false, message: 'There is no demonstration community to show at the moment. Log in with your own estate instead.' }
        if (e.status === 'network' || e.status === 'timeout') return { ok: false, message: "You're offline, or the server can't be reached. The demonstration is live data and needs a connection." }
        return { ok: false, message: e.status === 429 ? `Too many tries. ${e.retryAfter ? `Wait ${e.retryAfter} seconds` : 'Wait a minute'} and try again.` : e.message }
      }
      if (!answer.data) return { ok: false, message: 'The server gave an answer this version of the app does not understand.' }
      await openDemoSession(answer.data, dispatch)
      return { ok: true }
    } finally {
      setBusy(false)
    }
  }, [enter, dispatch])

  return { start, busy }
}

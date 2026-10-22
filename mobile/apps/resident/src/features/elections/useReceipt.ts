/**
 * The receipt — board resident-app-16.
 *
 * It shows ONLY what the server returns for a receipt: that the household's paper is in, and (straight
 * after casting) the DAY. It cannot be linked to the choices because it never has them: this file
 * and its screen do not import the marks, take no route param but the election id, and are told
 * nothing by the review screen except the server's own answer (receiptNote.ts).
 *
 * THE BOARD draws a receipt CODE, a time of casting and "Verify my vote". The server has none of
 * them, on purpose: a paper is stored with nothing that links it to a household, so there is
 * nothing a code could look up, and the day is recorded but never the time. So, live, the card
 * keeps the board's words — "YOUR RECEIPT CODE", "Cast today · save this to verify your vote was
 * counted" with the server's day — and draws no code; the board's code exists only in the fidelity
 * fixture. The words that promise a code are reported to the lead as untrue in live use.
 *
 * Opened cold (or after an answer was lost) it asks `elections.eligibility` — online only — and
 * reads `has_voted` and `voted_on` (the day; the receipt is a confirmation with a timestamp — the
 * server keeps a date and never a time, because a time is an ordinal). It never sends anything.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { isFidelity, now, siteDayIso } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { receiptFixture } from '@/fidelity/elections'
import { useAppSelector } from '@/store'
import { dayLabel } from './paper'
import { receiptNote } from './receiptNote'

export type ReceiptModel = {
  status: 'checking' | 'offline' | 'error' | 'missing' | 'denied' | 'in' | 'none'
  message: string | null
  retry: () => void
  body: string
  boxLabel: string
  /** The receipt code: the board's own in fidelity mode; live there is none to show. */
  boxValue: string | null
  boxCaption: string
  /** `none` only: the poll is open and the household may vote, so the way on is the ballot. */
  mayVote: boolean
}

/** "Cast today at 11:02 AM · save this to verify your vote was counted" — the board's line, with the server's DAY and no time. */
function castLine(votedOn: string | null): string {
  if (!votedOn) return 'Save this to verify your vote was counted'
  const day = votedOn === siteDayIso(now()) ? 'today' : votedOn === siteDayIso(now(), -1) ? 'yesterday' : `on ${dayLabel(votedOn) ?? votedOn}`
  return `Cast ${day} · save this to verify your vote was counted`
}

export function useReceipt(id: string | undefined): ReceiptModel {
  const principal = useAppSelector((s) => s.session.principal)
  const online = useAppSelector((s) => s.network.online)
  // Read once for this visit and forgotten when the screen leaves: a later visit asks the server.
  const [note] = useState(() => receiptNote.for(id))
  useEffect(() => () => receiptNote.clear(), [])
  const skip = isFidelity || !id

  const since = useRef(0)
  if (since.current === 0) since.current = Date.now()

  // Straight after casting the server's own answer is in hand; otherwise ask — fresh, never from an earlier visit.
  const eligibility = residentApi.useElectionsEligibilityQuery({ ballot: id ?? '' }, { skip: skip || note !== null, refetchOnMountOrArgChange: true, refetchOnReconnect: true })
  const index = residentApi.useElectionsIndexQuery(undefined, { skip })

  const refetch = eligibility.refetch
  const retry = useCallback(() => {
    if (!skip && note === null) void refetch()
  }, [skip, note, refetch])

  const blank = { message: null, retry, body: '', boxLabel: '', boxValue: null, boxCaption: '', mayVote: false }

  if (isFidelity) return { ...blank, status: 'in', body: receiptFixture.body, boxLabel: receiptFixture.boxLabel, boxValue: receiptFixture.boxValue, boxCaption: receiptFixture.boxCaption }
  if (!id) return { ...blank, status: 'missing' }

  const unit = principal?.reference?.split(' · ').pop() ?? 'your household'
  const title = (index.data?.items ?? []).find((e) => String(e.id) === id)?.title ?? 'this election'
  const recorded = (votedOn: string | null): ReceiptModel => ({
    ...blank,
    status: 'in',
    body: `One vote recorded for ${unit} in ${title}.`,
    boxLabel: 'YOUR RECEIPT CODE',
    boxValue: null,
    boxCaption: castLine(votedOn),
  })

  if (note) return recorded(note.votedOn)

  const error = eligibility.isError ? (eligibility.error as ApiError) : null
  const fresh = eligibility.data !== undefined && (eligibility.fulfilledTimeStamp ?? 0) >= since.current && String(eligibility.data.ballot_id) === id

  if (error && !(fresh && (error.status === 'network' || error.status === 'timeout'))) {
    if (error.status === 'network' || error.status === 'timeout') return { ...blank, status: 'offline' }
    if (error.status === 404) return { ...blank, status: 'missing' }
    if (error.status === 403) return { ...blank, status: 'denied' }
    return { ...blank, status: 'error', message: error.message }
  }
  // The phone knows it has no connection: say so now, not after the request and its retries give up.
  if ((!fresh || !eligibility.data) && !online) return { ...blank, status: 'offline' }
  if (!fresh || !eligibility.data) return { ...blank, status: 'checking' }

  // Opened later, the server still knows the DAY (the receipt row's own date) — and only the day.
  if (eligibility.data.has_voted === true) return recorded(eligibility.data.voted_on ?? null)
  return { ...blank, status: 'none', body: `No vote is recorded for ${unit} in ${title}.`, mayVote: eligibility.data.eligible === true && eligibility.data.voting_open === true }
}

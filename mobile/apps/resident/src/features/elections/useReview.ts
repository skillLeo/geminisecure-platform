/**
 * Review and cast — board resident-app-34. MOUNTED ONLY BEHIND AN OPEN GATE.
 *
 * THE SEND. `elections.ballot` goes ONCE, ONLINE ONLY, straight through the generated mutation
 * (which the API layer builds with `maxRetries: 0`). It never goes near the offline queue, is
 * never repeated by the app — not after a failure, not after a refusal — and its body is never
 * kept: the marks leave memory (`ballotBox.take()`) in the same breath as they are handed to the
 * request, the mutation's own state is reset the moment the answer is in, and nothing about the
 * body is printed, hashed or reported.
 *
 * WHEN THE ANSWER IS LOST (no signal mid-send, a timeout, a 5xx) the app does not know whether
 * the vote arrived — and says exactly that. It then asks `elections.eligibility` again
 * (`has_voted`) instead of sending again: the receipt if the paper is in; if it is not, the
 * household marks a fresh paper themselves.
 *
 * WHAT BOARD 34 SAYS, LIVE: "FINAL REVIEW · LOT 47" with the household's lot; a label per ballot, "Ballot A · <kind>
 * <year>" (the server names no ballot within a paper) with its "Edit Ballot A"; and the note, "This submits Lot 47's one
 * and only vote for both ballots. You'll get a receipt code to verify it was counted." — the board's words, though no
 * receipt code exists (reported to the lead); "for this ballot" when the paper has no second page.
 */

import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import * as Haptics from 'expo-haptics'
import { isFidelity } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { reviewFixture } from '@/fidelity/elections'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { useAppSelector } from '@/store'
import { ballotBox } from './ballotBox'
import { BLANK_PAPER, UNCONFIRMED, WIPED_GATE_SHUT, castFailureOf, markedLabels, optionIdsOf, pagesOf, paperOf, shortNames, type PaperPosition } from './paper'
import { receiptNote } from './receiptNote'
import type { GateModel } from './useBallotGate'

export type ReviewRow = { key: string; position: string; value: string; blank: boolean }
export type ReviewGroup = { key: string; heading: string; edit: string; page: 'ballot-a' | 'ballot-b'; rows: ReviewRow[] }

export type ReviewModel = {
  status: 'loading' | 'error' | 'missing' | 'empty' | 'ready' | 'sending' | 'unconfirmed' | 'not-received' | 'refused'
  message: string | null
  retry: () => void
  eyebrow: string
  groups: ReviewGroup[]
  note: string
  wipedNote: string | null
  problem: string | null
  online: boolean
  checking: boolean
  cast: () => void
  checkAgain: () => void
}

const NO_CONNECTION = 'No connection. A vote is never saved for later: it is sent only while you watch. Connect, then send it.'
const STILL_UNCONFIRMED = 'The server still cannot be reached, so we still do not know whether your vote arrived. Do not mark another paper yet: connect, then check again.'
const WIPED_BACKGROUND = 'For privacy, your marks were cleared when you left the app. Please mark your paper again.'
/** Reached when the review comes back after a send that did not end in a receipt: the gate is open, so the server has no paper. */
const EARLIER_NOT_COUNTED = 'Your earlier paper was not counted: the server has no vote from this household. Marks are never kept on this phone, so please mark your paper again.'

const rowOf = (p: PaperPosition, marks: ReturnType<typeof ballotBox.marks>): ReviewRow => {
  const labels = markedLabels(p, marks)
  return { key: p.key, position: p.seats > 1 ? `${p.name} (${p.seats})` : p.name, value: labels.length > 0 ? shortNames(labels) : 'Left blank', blank: labels.length === 0 }
}

export function useReview(id: string, recheck: (options?: { settle?: boolean }) => Promise<GateModel>, goReceipt: () => void): ReviewModel {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const principal = useAppSelector((s) => s.session.principal)
  const index = residentApi.useElectionsIndexQuery(undefined, { skip: isFidelity })
  const [sendPaper, sending] = residentApi.useElectionsBallotMutation()
  const marks = useSyncExternalStore(ballotBox.subscribe, ballotBox.marks, ballotBox.marks)

  const [phase, setPhase] = useState<'idle' | 'sending' | 'unconfirmed' | 'not-received' | 'refused'>('idle')
  const [problem, setProblem] = useState<string | null>(null)
  const [checking, setChecking] = useState(false)
  // One paper, one send: a second tap while the first is in the air does nothing.
  const inTheAir = useRef(false)

  const election = useMemo(() => (index.data?.items ?? []).find((e) => String(e.id) === id) ?? null, [index.data, id])
  const paper = useMemo(() => (election ? paperOf(election) : []), [election])

  /** Ask whether the paper is in — never send it again. */
  const confirm = useCallback(async (justLost = false) => {
    setChecking(true)
    try {
      // Straight after a LOST answer the server may still be writing the paper it did receive: "no paper yet" is
      // not yet "no paper", so the gate asks twice, a few seconds apart, before it is believed (useBallotGate).
      // Asking sends nothing. (A second paper would be refused as already_voted anyway; this is so the household
      // is not told something untrue first.)
      const gate = await recheck({ settle: justLost })
      if (gate.state === 'voted') return goReceipt()
      if (gate.state === 'open') {
        setPhase('not-received')
        setProblem('Your vote was NOT received: the server has no paper from your household. Your marks were cleared for privacy, so please mark your paper again.')
      }
      // Still no answer: say so, so "Check again" is never a button that seems to do nothing. (`recheck` reads
      // ONLY an answer fulfilled after it asked — a failed re-ask still carries the answer from BEFORE the
      // paper went, and that one must never be read as "the server has no paper".)
      if (gate.state === 'offline' || gate.state === 'checking') setProblem(STILL_UNCONFIRMED)
      // Any other answer (ineligible, closed, an error) is drawn by the gate's own holding screen, which
      // replaces this review — ReviewScreen keeps what is known about the paper above it (aftermathNote).
    } finally {
      setChecking(false)
    }
  }, [recheck, goReceipt])

  // The hook's `reset` closes over the request it knew about when it was made, so the one captured
  // by `cast` BEFORE the send knows of no request and would do nothing. The ref is the current one.
  const forget = useRef(sending.reset)
  forget.current = sending.reset

  // Another election's marks never reach this review (the ballot pages do the same).
  useEffect(() => {
    if (!isFidelity) ballotBox.open(id)
  }, [id])

  // "No connection" stops being true the moment there is one.
  useEffect(() => {
    if (online) setProblem((now) => (now === NO_CONNECTION ? null : now))
  }, [online])

  const cast = useCallback(async () => {
    if (isFidelity || inTheAir.current || phase !== 'idle') return
    if (!online) return setProblem(NO_CONNECTION)
    if (optionIdsOf(paper, ballotBox.marks()).length === 0) return setProblem(BLANK_PAPER)
    /*
     * BEFORE `ballotBox.take()`, which is a destructive read. The paper is marked, the
     * review is read, and only the casting is refused — but it has to be refused HERE, because
     * one line further down the marks leave memory for good and a refusal after that would have
     * wiped the reviewer's paper and told them nothing happened.
     */
    if (refusal) return setProblem(refusal)

    inTheAir.current = true
    setProblem(null)
    setPhase('sending')
    try {
      // The marks leave memory HERE. From this line on the only copy is the request itself.
      const request = sendPaper({ ballot: id, option_ids: optionIdsOf(paper, ballotBox.take()) })
      const answer = await request
      // …and the request's own record of what was sent goes the moment the answer is in — from the
      // api slice (`request.reset()`) and from the mutation hook's state (`forget`). Whatever the answer.
      request.reset()
      forget.current()

      if ('error' in answer && answer.error) {
        const failure = castFailureOf(answer.error as ApiError)
        if (failure.kind === 'voted') return goReceipt()
        if (failure.kind === 'unconfirmed') {
          setPhase('unconfirmed')
          setProblem(UNCONFIRMED)
          return void confirm(true)
        }
        setPhase('refused')
        setProblem(failure.kind === 'ineligible' ? null : failure.message)
        // Not eligible / poll closed: the gate asks again and draws its own holding screen.
        if (failure.kind !== 'refused') void recheck()
        return
      }

      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined)
      receiptNote.set({ ballot: id, votedOn: answer.data?.voted_on ?? null })
      goReceipt()
    } finally {
      inTheAir.current = false
    }
  }, [phase, online, paper, id, sendPaper, confirm, recheck, goReceipt, refusal])

  const retry = useCallback(() => {
    if (!isFidelity) void index.refetch()
  }, [index])

  const shared = { message: null, retry, problem, online, checking, cast: () => void cast(), checkAgain: () => void confirm() }

  if (isFidelity) {
    const f = reviewFixture
    return {
      ...shared,
      status: 'ready',
      eyebrow: f.eyebrow,
      groups: f.groups.map((g) => ({ key: g.key, heading: g.heading, edit: g.edit, page: g.key === 'a' ? 'ballot-a' : 'ballot-b', rows: g.rows.map((r) => ({ ...r, blank: false })) })),
      note: f.note,
      wipedNote: null,
    }
  }

  const unit = principal?.reference?.split(' · ').pop() ?? null
  const empty = { eyebrow: '', groups: [], note: '', wipedNote: null }

  if (phase !== 'idle') return { ...shared, ...empty, status: phase }

  if (!election) {
    if (index.isLoading || index.isUninitialized || index.isFetching) return { ...shared, ...empty, status: 'loading' }
    if (index.isError) {
      const e = index.error as ApiError
      return { ...shared, ...empty, status: 'error', message: e.status === 'network' || e.status === 'timeout' ? 'The ballot could not be loaded: there is no connection. Voting needs one.' : e.message }
    }
    return { ...shared, ...empty, status: 'missing' }
  }

  const wipedNote = ballotBox.lastWipe() === 'background' ? WIPED_BACKGROUND : ballotBox.lastWipe() === 'sent' ? EARLIER_NOT_COUNTED : ballotBox.lastWipe() === 'gate-shut' ? WIPED_GATE_SHUT : null
  if (optionIdsOf(paper, marks).length === 0) return { ...shared, ...empty, status: 'empty', wipedNote }

  const pages = pagesOf(paper)
  const kind = `${election.kind === 'resolution' ? 'Resolution' : 'Election'} ${election.year}`
  const groups: ReviewGroup[] = [{ key: 'a', heading: `Ballot A · ${kind}`, edit: 'Edit Ballot A', page: 'ballot-a', rows: pages.a.map((p) => rowOf(p, marks)) }]
  if (pages.two) groups.push({ key: 'b', heading: `Ballot B · ${kind}`, edit: 'Edit Ballot B', page: 'ballot-b', rows: [...pages.b, ...pages.rest].map((p) => rowOf(p, marks)) })

  return {
    ...shared,
    status: 'ready',
    eyebrow: unit ? `FINAL REVIEW · ${unit.toUpperCase()}` : 'FINAL REVIEW',
    groups,
    note: `This submits ${unit ? `${unit}'s` : "your household's"} one and only vote for ${pages.two ? 'both ballots' : 'this ballot'}. You'll get a receipt code to verify it was counted.`,
    wipedNote: null,
  }
}

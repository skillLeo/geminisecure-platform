/**
 * One page of the household's paper — boards resident-app-15 (page A) and -24 (page B).
 *
 * MOUNTED ONLY BEHIND AN OPEN GATE (useBallotGate): this hook is what fetches the paper, so for an
 * ineligible household, a household that has voted, or a closed poll, the paper is never even
 * asked for.
 *
 * The paper is read live (`elections.index`) — voting is online only, so there is no cache here.
 * The marks live in memory (ballotBox.ts) and nowhere else; this hook only reads them to draw
 * the radios and boxes.
 *
 * WHAT THE BOARDS SAY, LIVE. The boards draw two ballots ("A" and "B") cast together; the server has one paper per
 * election, laid over the two pages (paper.ts). Each line is the board's, with the live data in its place:
 *   title     page A "Community election"; page B "Phase 2 election" — the household's own phase
 *   tag       "BALLOT A · COMMUNITY EXECUTIVE" → "BALLOT A · ELECTION 2026" (the server names no ballot within a paper)
 *   heading   the election's title, on both pages
 *   line      page A "Voting as Lot 47 · one vote per household · closes Sun 28 Sep, 6:00 PM"; page B "Only Phase 2
 *             households vote here · closes …" with the household's phase (page A's line when the session names
 *             none) — the estate's clock, no tag
 *   note      "Only one household member can vote per ballot. Andrea Fletcher is Lot 47's designated voter for this
 *             election." — the signed-in person and their lot
 * The server designates no voter and scopes no page to a phase: those two lines are the boards', drawn as they are,
 * and reported to the lead. In fidelity mode, the boards' own sample (fidelity/elections.ts).
 */

import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react'
import * as Haptics from 'expo-haptics'
import { isFidelity } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { ballotAFixture, ballotBFixture } from '@/fidelity/elections'
import { useAppSelector } from '@/store'
import { ballotBox } from './ballotBox'
import { WIPED_GATE_SHUT, closesShort, continueLabel, initials, markedLabels, pagesOf, paperOf, placeOf, seatRule, shortNames, type Marks, type PaperPosition } from './paper'

export type OptionView = { id: number; label: string; initials: string; sub: string | null; marked: boolean }
export type PositionView = { key: string; name: string; rule: string; multi: boolean; options: OptionView[]; chosen: string | null }

export type BallotPageModel = {
  status: 'loading' | 'error' | 'missing' | 'empty' | 'no-second-page' | 'ready'
  message: string | null
  retry: () => void
  eyebrow: string
  heading: string
  sub: string
  expanded: PositionView[]
  compact: PositionView[]
  openKey: string | null
  toggleOpen: (key: string) => void
  mark: (positionKey: string, optionId: number) => void
  /** A mark refused because the position already has as many as it has seats: which position, and why nothing moved. */
  limit: { key: string; text: string } | null
  wipedNote: string | null
  note: string | null
  cta: string
  next: 'ballot-b' | 'review'
}

type SubLines = Readonly<Record<number, string>>

const viewOf = (p: PaperPosition, marks: Marks, subs: SubLines): PositionView => {
  const mine = marks[p.key] ?? []
  const labels = markedLabels(p, marks)
  return {
    key: p.key,
    name: p.name,
    rule: seatRule(p.seats),
    multi: p.seats > 1,
    options: p.options.map((o) => ({ id: o.id, label: o.label, initials: initials(o.label), sub: subs[o.id] ?? null, marked: mine.includes(o.id) })),
    chosen: labels.length > 0 ? shortNames(labels) : null,
  }
}

/**
 * The bar's title: board 15's "Community election"; board 24's "Phase 2 election", with the household's own phase.
 * It needs no paper, so the screen asks for it before the gate opens.
 */
export function useBallotTitle(page: 'a' | 'b'): string {
  const phase = placeOf(useAppSelector((s) => s.session.principal)?.reference).phase
  if (isFidelity) return (page === 'a' ? ballotAFixture : ballotBFixture).appBar
  return page === 'b' && phase ? `${phase} election` : 'Community election'
}

export function useBallotPage(id: string, page: 'a' | 'b'): BallotPageModel {
  const principal = useAppSelector((s) => s.session.principal)
  const index = residentApi.useElectionsIndexQuery(undefined, { skip: isFidelity, refetchOnMountOrArgChange: true })
  const marks = useSyncExternalStore(ballotBox.subscribe, ballotBox.marks, ballotBox.marks)
  const [openKey, setOpenKey] = useState<string | null>(null)
  const [limit, setLimit] = useState<{ key: string; text: string } | null>(null)

  useEffect(() => {
    if (!isFidelity) ballotBox.open(id)
  }, [id])

  const election = useMemo(() => (index.data?.items ?? []).find((e) => String(e.id) === id) ?? null, [index.data, id])
  const paper = useMemo(() => (election ? paperOf(election) : []), [election])
  const pages = useMemo(() => pagesOf(paper), [paper])

  const mark = useCallback(
    (positionKey: string, optionId: number) => {
      if (isFidelity) return
      const position = paper.find((p) => p.key === positionKey)
      if (!position) return
      const refused = ballotBox.mark(position, optionId)
      void Haptics.selectionAsync().catch(() => undefined)
      setLimit(refused === 'full' ? { key: position.key, text: `${position.name}: you can choose up to ${position.seats}. Unmark one to choose another.` } : null)
    },
    [paper],
  )

  const toggleOpen = useCallback((key: string) => setOpenKey((now) => (now === key ? null : key)), [])
  const retry = useCallback(() => {
    if (!isFidelity) void index.refetch()
  }, [index])

  const shared = { message: null, retry, openKey, toggleOpen, mark, limit }

  if (isFidelity) {
    const f = page === 'a' ? ballotAFixture : ballotBFixture
    const subs: Record<number, string> = {}
    for (const p of f.expanded) for (const o of p.options) subs[o.id] = o.sub
    const view = (p: (typeof f.expanded)[number]) => viewOf({ key: p.key, name: p.name, seats: p.seats, options: p.options.map((o) => ({ id: o.id, label: o.label })) }, f.marks, subs)
    return { ...shared, status: 'ready', eyebrow: f.eyebrow, heading: f.heading, sub: f.sub, expanded: f.expanded.map(view), compact: f.compact.map(view), wipedNote: null, note: f.note, cta: f.cta, next: page === 'a' ? 'ballot-b' : 'review' }
  }

  const empty = { eyebrow: '', heading: '', sub: '', expanded: [], compact: [], wipedNote: null, note: null, cta: '', next: 'review' as const }

  if (!election) {
    // Still asking — including a re-ask over a saved list that does not have this election (it may have
    // opened since): "No such election" is only said once the server has answered.
    if (index.isLoading || index.isUninitialized || index.isFetching) return { ...shared, ...empty, status: 'loading' }
    if (index.isError) {
      const e = index.error as ApiError
      return { ...shared, ...empty, status: 'error', message: e.status === 'network' || e.status === 'timeout' ? 'The ballot could not be loaded: there is no connection. Voting needs one.' : e.message }
    }
    return { ...shared, ...empty, status: 'missing' }
  }

  // An election the office has opened before putting any position or option on it: there is
  // nothing to mark, and a "Review & submit" button over an empty page would lead nowhere.
  if (paper.length === 0) return { ...shared, ...empty, status: 'empty', heading: election.title }

  if (page === 'b' && !pages.two) return { ...shared, ...empty, status: 'no-second-page' }

  const { unit, phase } = placeOf(principal?.reference)
  const household = unit ?? 'your household'
  const name = principal?.name?.trim() ?? ''
  const closes = closesShort(election.closes_at)
  const until = closes ? ` · closes ${closes}` : ''
  const kind = election.kind === 'resolution' ? 'RESOLUTION' : 'ELECTION'
  const open = page === 'a' ? pages.a : pages.b
  const rest = page === 'a' ? [] : pages.rest
  const after = page === 'a' ? [...pages.b, ...pages.rest] : []

  return {
    ...shared,
    status: 'ready',
    eyebrow: `BALLOT ${page === 'a' ? 'A' : 'B'} · ${kind} ${election.year}`,
    heading: election.title,
    sub: page === 'b' && phase ? `Only ${phase} households vote here${until}` : `Voting as ${household} · one vote per household${until}`,
    expanded: open.map((p) => viewOf(p, marks, {})),
    compact: rest.map((p) => viewOf(p, marks, {})),
    wipedNote: !ballotBox.isEmpty() ? null : ballotBox.lastWipe() === 'background' ? 'For privacy, your marks were cleared when you left the app. Please mark your paper again.' : ballotBox.lastWipe() === 'gate-shut' ? WIPED_GATE_SHUT : null,
    note: page === 'a' ? `Only one household member can vote per ballot.${name ? ` ${name} is ${household}'s designated voter for this ${election.kind}.` : ''}` : null,
    cta: page === 'a' ? continueLabel(after) : 'Review & submit vote',
    next: page === 'a' && pages.two ? 'ballot-b' : 'review',
  }
}

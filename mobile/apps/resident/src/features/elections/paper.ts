/**
 * The voting screens' pure logic — no React, no UI kit, nothing that keeps anything — so it is
 * unit-tested without a phone.
 *
 * THE SERVER OWNS THE VOTE. Who may vote, whether the poll is open, whether a paper is valid and
 * who won are all the server's answers; nothing here decides them. What is here is presentation:
 * how one paper is laid over two pages, how a mark is toggled, what a refusal means for the
 * screen, how a published count is written out.
 *
 * THE BOARDS draw two ballots ("A" and "B") cast together. The server has ONE paper per election
 * (`positions[]`, or loose `options[]` for a resolution), cast in ONE request. So:
 *   page A   the paper's first two positions, open
 *   page B   the next two, open — and every position after that as a "Remaining positions" row
 *            that opens in place, exactly as board 24 draws it
 */

import { dayTimeTag, yourTimeLine } from '@gs/core/lib'
import type { Api, ApiError } from '@gs/core/api'

export type Election = Api.ElectionsIndexResponse['items'][number]
export type PaperOption = { id: number; label: string }
export type PaperPosition = { key: string; name: string; seats: number; options: PaperOption[] }
/** Position key → the option ids marked for it. Lives in memory only (ballotBox.ts). */
export type Marks = Readonly<Record<string, readonly number[]>>

const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/* ---- the paper ------------------------------------------------------------------------------ */

type RawOption = { id: number | string; label: string }
const clean = (options: readonly RawOption[] | null | undefined): PaperOption[] =>
  (options ?? []).map((o) => ({ id: Number(o.id), label: String(o.label ?? '').trim() })).filter((o) => Number.isInteger(o.id) && o.label !== '')

/** The server's paper, normalised. A resolution's loose options become one question with one mark. */
export function paperOf(election: Pick<Election, 'title' | 'question' | 'positions' | 'options'>): PaperPosition[] {
  const positions = (election.positions ?? [])
    .map((p) => ({ key: `p${p.id}`, name: p.name, seats: Math.max(1, Math.floor(Number(p.seat_count)) || 1), options: clean(p.options) }))
    .filter((p) => p.options.length > 0)
  const loose = clean(election.options)
  return loose.length > 0 ? [...positions, { key: 'question', name: election.question ?? election.title, seats: 1, options: loose }] : positions
}

export const OPEN_PER_PAGE = 2

export type Pages = { a: PaperPosition[]; b: PaperPosition[]; rest: PaperPosition[]; two: boolean }

export function pagesOf(paper: readonly PaperPosition[]): Pages {
  return { a: paper.slice(0, OPEN_PER_PAGE), b: paper.slice(OPEN_PER_PAGE, OPEN_PER_PAGE * 2), rest: paper.slice(OPEN_PER_PAGE * 2), two: paper.length > OPEN_PER_PAGE }
}

/** "Choose 1" · "Choose up to 3" */
export const seatRule = (seats: number): string => (seats <= 1 ? 'Choose 1' : `Choose up to ${seats}`)

/** "Dwayne Robinson" → "DR" */
export function initials(label: string): string {
  const words = label.trim().split(/\s+/).filter(Boolean)
  const first = words[0]?.[0] ?? ''
  const last = words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : ''
  return `${first}${last}`.toUpperCase()
}

/** Page A's button: board 15 reads "Continue — Secretary, Treasurer & more". */
export function continueLabel(after: readonly PaperPosition[]): string {
  if (after.length === 0) return 'Review & submit vote'
  const label = `Continue: ${after.slice(0, 2).map((p) => p.name).join(', ')}${after.length > 2 ? ' & more' : ''}`
  return label.length <= 40 ? label : 'Continue to the next positions'
}

/** "28 Sep 2026, 6:00 PM": in the site’s zone, in the one form (M98, 12 Oct 2026; it read "Sun 28 Sep, 6:00 PM JA"). */
export function closesLabel(iso: string | null | undefined): string | null {
  if (!iso || Number.isNaN(new Date(iso).getTime())) return null
  return dayTimeTag(iso)
}

/** "28 Sep 2026, 6:00 PM": the close where boards 15 and 24 write it, the estate's clock, in the one form (M98). */
export function closesShort(iso: string | null | undefined): string | null {
  if (!iso || Number.isNaN(new Date(iso).getTime())) return null
  return dayTimeTag(iso)
}

/** "Phase 2 · Lot 47" (the session's reference) → the lot, and the phase when the reference names one. */
export function placeOf(reference: string | null | undefined): { unit: string | null; phase: string | null } {
  const parts = (reference ?? '').split(' · ').map((part) => part.trim()).filter(Boolean)
  return { unit: parts[parts.length - 1] ?? null, phase: parts.find((part) => /^phase\b/i.test(part)) ?? null }
}

/** Under an election's closing time, the viewer's own clock when it differs (M19A §4.3): a vote is acted on. */
export const closesYourTime = (iso: string | null | undefined): string | null => (iso && !Number.isNaN(new Date(iso).getTime()) ? yourTimeLine(iso) : null)

/** "2026-09-19" → "19 Sep 2026" (M98's one form). A DATE has no time zone: read as written, never through `new Date()`. */
export function dayLabel(date: string | null | undefined): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date ?? '')
  const month = m ? MONTHS_SHORT[Number(m[2]) - 1] : undefined
  return m && month ? `${Number(m[3])} ${month} ${m[1]}` : null
}

/* ---- marking -------------------------------------------------------------------------------- */

export type MarkResult = { marks: Marks; refused: 'full' | null }

/**
 * One tap on one option. A marked option is unmarked (a position may be left blank — only a wholly
 * blank paper is refused). One seat behaves as a radio. More seats: a mark past the seat count is
 * REFUSED here, because an over-marked paper is a spoiled paper and the household is told before
 * it is cast, not after.
 */
export function toggleMark(marks: Marks, position: Pick<PaperPosition, 'key' | 'seats' | 'options'>, optionId: number): MarkResult {
  if (!position.options.some((o) => o.id === optionId)) return { marks, refused: null }
  const current = marks[position.key] ?? []
  if (current.includes(optionId)) {
    const left = current.filter((id) => id !== optionId)
    const { [position.key]: _gone, ...others } = marks
    return { marks: left.length > 0 ? { ...others, [position.key]: left } : others, refused: null }
  }
  if (position.seats <= 1) return { marks: { ...marks, [position.key]: [optionId] }, refused: null }
  if (current.length >= position.seats) return { marks, refused: 'full' }
  return { marks: { ...marks, [position.key]: [...current, optionId] }, refused: null }
}

/** The labels marked for a position, in the PAPER's order (never the order they were tapped in). */
export function markedLabels(position: PaperPosition, marks: Marks): string[] {
  const mine = marks[position.key] ?? []
  return position.options.filter((o) => mine.includes(o.id)).map((o) => o.label)
}

/** One name in full; several as the board writes them: "M. Palmer, A. Thompson". */
export function shortNames(labels: readonly string[]): string {
  if (labels.length <= 1) return labels[0] ?? ''
  return labels
    .map((label) => {
      const words = label.trim().split(/\s+/)
      return words.length > 1 ? `${words[0]![0]}. ${words.slice(1).join(' ')}` : label
    })
    .join(', ')
}

export const unmarked = (paper: readonly PaperPosition[], marks: Marks): PaperPosition[] => paper.filter((p) => (marks[p.key] ?? []).length === 0)

/**
 * The request's `option_ids`: only ids that are on THIS paper, at most each position's seat count,
 * in the paper's own order — so the list carries nothing but the choice (not the order of marking).
 * The caller sends it and lets go of it; it is never kept, logged or hashed.
 */
export function optionIdsOf(paper: readonly PaperPosition[], marks: Marks): number[] {
  return paper.flatMap((p) => {
    const mine = marks[p.key] ?? []
    return p.options.filter((o) => mine.includes(o.id)).slice(0, p.seats).map((o) => o.id)
  })
}

export const BLANK_PAPER = 'Nothing is marked yet. A blank paper is not a vote: mark at least one choice first.'

/* ---- the gate: eligibility before anything is drawn -------------------------------------------- */

/** `why` — the server's sentence on why this person holds the household's vote (M21 §1.3), or null from an older server. */
export type Gate = { state: 'voted' } | { state: 'ineligible'; reason: string | null } | { state: 'closed' } | { state: 'open'; checkedOn: string | null; why: string | null }

type EligibilityRead = Pick<Api.ElectionsEligibilityResponse, 'eligible' | 'reason' | 'voting_open' | 'has_voted' | 'checked_on'> & { why?: string | null }

/** Read, never computed. A paper already in wins over everything: it is a fact, and it cannot be re-cast. */
export function gateOf(e: EligibilityRead): Gate {
  if (e.has_voted === true) return { state: 'voted' }
  if (e.eligible !== true) return { state: 'ineligible', reason: typeof e.reason === 'string' && e.reason.trim() !== '' ? e.reason.trim() : null }
  if (e.voting_open !== true) return { state: 'closed' }
  return { state: 'open', checkedOn: e.checked_on ?? null, why: typeof e.why === 'string' && e.why.trim() !== '' ? e.why.trim() : null }
}

export const NOT_OPEN_TO_HOUSEHOLD = 'Voting is not open to this household for this election. Contact the estate office.'

/** Every state a marking screen can be in before — or instead of — the paper. */
export type GateModel =
  | { state: 'checking' }
  | { state: 'offline' }
  | { state: 'missing' }
  | { state: 'denied' }
  | { state: 'error'; message: string }
  | Gate

export const lostConnection = (e: Pick<ApiError, 'status'>): boolean => e.status === 'network' || e.status === 'timeout'

export function gateOfError(e: Pick<ApiError, 'status' | 'message'>): GateModel {
  if (lostConnection(e)) return { state: 'offline' }
  if (e.status === 404) return { state: 'missing' }
  if (e.status === 403) return { state: 'denied' }
  return { state: 'error', message: e.message }
}

/**
 * The server's answer is a settled "no paper for this household now" — not a connection that comes
 * and goes. No ballot is drawn then, and no marks are kept in memory behind the holding screen.
 */
export const gateShut = (state: GateModel['state']): boolean => state === 'ineligible' || state === 'voted' || state === 'closed' || state === 'denied' || state === 'missing'

export const WIPED_GATE_SHUT = 'Your marks were cleared while voting was not open to this household. Please mark your paper again.'

/** What asking the question AGAIN came back with (the result of RTK Query's `refetch()`). */
export type Reasked = {
  data?: Pick<Api.ElectionsEligibilityResponse, 'ballot_id' | 'eligible' | 'reason' | 'voting_open' | 'has_voted' | 'checked_on'> & { why?: string | null }
  error?: unknown
  isError?: boolean
  fulfilledTimeStamp?: number
}

/**
 * The gate a RE-ASKED question gives. A refetch that FAILS still carries the last good answer in
 * `data` (RTK Query keeps it), and after a lost send that answer is the one from BEFORE the paper
 * went: "has_voted: false". Reading it would tell a household "your vote was NOT received" when
 * nothing at all is known. So the failure is read first, and `data` counts only if it was
 * fulfilled after the question was asked, for this ballot.
 */
export function recheckedGate(answer: Reasked, askedAt: number, ballot: string): GateModel {
  if (answer.isError === true || answer.error !== undefined) {
    const e = answer.error as Partial<Pick<ApiError, 'status' | 'message'>> | undefined
    return e && e.status !== undefined ? gateOfError({ status: e.status, message: e.message ?? '' }) : { state: 'checking' }
  }
  const data = answer.data
  if (data === undefined || (answer.fulfilledTimeStamp ?? 0) < askedAt || String(data.ballot_id) !== ballot) return { state: 'checking' }
  return gateOf(data)
}

/* ---- what a failed cast means ------------------------------------------------------------------ */

export type CastFailure =
  /** The household's paper is already in: the receipt, never the choices. */
  | { kind: 'voted' }
  | { kind: 'ineligible' }
  | { kind: 'closed'; message: string }
  /** A refusal with the server's sentence. Final: never sent again by the app. */
  | { kind: 'refused'; message: string }
  /** The answer was lost. The app does NOT know whether the vote arrived, and says so. */
  | { kind: 'unconfirmed' }

export const UNCONFIRMED = 'We could not confirm your vote was received: check the receipt screen before trying again.'

/**
 * A send that did not end in a receipt can outlive the review that made it: the question "did it
 * arrive?" may itself fail (the server is down), or the answer may be "not open" / "not eligible",
 * and then a holding screen REPLACES the review. That screen still owes the household what is and
 * is not known about the paper they just handed over.
 */
export type Aftermath = 'lost' | 'refused' | null

export const NOT_RECEIVED = 'Your vote was NOT received: the server has no paper from your household.'

export function aftermathNote(aftermath: Aftermath, gate: GateModel['state']): string | null {
  // Open: the review itself says it. Voted: the receipt says it.
  if (aftermath === null || gate === 'open' || gate === 'voted') return null
  if (aftermath === 'refused') return 'Your vote was not accepted, and nothing was counted. The app never sends a refused paper again.'
  // `has_voted` is read before anything else (gateOf), so "not eligible" and "not open" also say: no paper is in.
  if (gate === 'ineligible' || gate === 'closed') return NOT_RECEIVED
  if (gate === 'missing' || gate === 'denied') return 'We could not confirm your vote was received, and the app will not send it again. Ask the estate office whether your household’s paper is in.'
  return 'We could not confirm your vote was received, and the app will not send it again by itself. Trying again here only ASKS the server whether your paper is in: it sends nothing.'
}

export function castFailureOf(e: Pick<ApiError, 'status' | 'code' | 'message'>): CastFailure {
  if (e.code === 'already_voted') return { kind: 'voted' }
  if (e.code === 'not_eligible') return { kind: 'ineligible' }
  if (e.code === 'poll_closed') return { kind: 'closed', message: e.message }
  if (e.status === 'network' || e.status === 'timeout' || (typeof e.status === 'number' && e.status >= 500)) return { kind: 'unconfirmed' }
  return { kind: 'refused', message: e.message }
}

/* ---- published results ------------------------------------------------------------------------- */

export type ResultRow = { id: string; label: string; initials: string; sub: string | null; votes: string; share: string | null; crown: boolean }
export type ResultGroup = { key: string; heading: string; rows: ResultRow[] }
export type ResultsView = {
  badge: string
  title: string
  certified: string | null
  turnoutCast: string
  turnoutRest: string
  turnoutPercent: number
  quorum: string | null
  statement: string | null
  groups: ResultGroup[]
}

/** The generated types call the counts strings; the server sends numbers. Either is read, neither is trusted blindly. */
const countOf = (v: unknown): number | null => {
  const n = typeof v === 'number' ? v : typeof v === 'string' && v.trim() !== '' ? Number(v) : NaN
  return Number.isFinite(n) && n >= 0 ? Math.floor(n) : null
}
const written = (n: number | null): string => (n === null ? '—' : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','))

type RawResultOption = { id: number | string; label: string; votes: unknown }

/**
 * Counts as the server certified them, most marks first (a tie keeps the paper's order).
 * NO WINNER IS NAMED HERE: the server's result names none, and ties, quorum and the estate's rules
 * are not the app's to settle. Who was elected is the returning officer's `outcome_statement`.
 */
export function resultsView(r: Api.ElectionsResultsResponse): ResultsView {
  const cast = countOf(r.turnout?.cast)
  const eligible = countOf(r.turnout?.eligible)
  const required = countOf(r.quorum?.required)
  const percent = countOf(r.turnout?.percent)

  const rowsOf = (options: readonly RawResultOption[] | null | undefined): ResultRow[] =>
    (options ?? [])
      .map((o, order) => ({ o, order, n: countOf(o.votes) }))
      .sort((x, y) => (y.n ?? -1) - (x.n ?? -1) || x.order - y.order)
      .map(({ o, n }) => ({
        id: String(o.id),
        label: o.label,
        initials: initials(o.label),
        sub: null,
        votes: written(n),
        share: n !== null && cast !== null && cast > 0 ? `${Math.round((n / cast) * 100)}%` : null,
        crown: false,
      }))

  const groups: ResultGroup[] = (r.positions ?? []).map((p) => ({ key: `p${p.id}`, heading: Number(p.seat_count) > 1 ? `${p.name} · ${p.seat_count} seats` : p.name, rows: rowsOf(p.options) }))
  if ((r.options ?? []).length > 0) groups.push({ key: 'question', heading: r.question ?? r.title, rows: rowsOf(r.options) })

  const stamp = r.certified_at || r.published_at
  return {
    badge: r.certified_at ? 'CERTIFIED BY RETURNING OFFICER' : 'PUBLISHED RESULT',
    title: r.title,
    certified: stamp && !Number.isNaN(new Date(stamp).getTime()) ? `${r.certified_at ? 'Certified' : 'Published'} ${dayTimeTag(stamp)}` : null,
    turnoutCast: written(cast),
    turnoutRest: ` of ${written(eligible)} households`,
    turnoutPercent: Math.min(100, percent ?? 0),
    quorum: required === null ? null : r.quorum?.met === true ? `Quorum met: ${written(required)} households were needed` : `Quorum was not met: ${written(required)} households were needed`,
    statement: typeof r.outcome_statement === 'string' && r.outcome_statement.trim() !== '' ? r.outcome_statement.trim() : null,
    groups: groups.filter((g) => g.rows.length > 0),
  }
}

/**
 * "Certified 28 Sep 2026, 8:02 PM" — board 28's stamp, on the estate's clock in the one form (M98) ("Published …" for a
 * result published but not certified).
 */
export function certifiedShort(r: Pick<Api.ElectionsResultsResponse, 'certified_at' | 'published_at'>): string | null {
  const stamp = r.certified_at || r.published_at
  if (!stamp || Number.isNaN(new Date(stamp).getTime())) return null
  return `${r.certified_at ? 'Certified' : 'Published'} ${dayTimeTag(stamp)}`
}

export const RESULTS_NOT_YET = 'Results are published after voting closes.'

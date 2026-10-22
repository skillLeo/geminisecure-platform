/**
 * The voting screens' logic, held to the rules of the voting hard-stop:
 *   - the gate is READ from the server's answer, never computed, and "voted" wins over everything;
 *   - a mark past the seat count is refused before the paper is cast (an over-marked paper is spoiled);
 *   - the request carries only ids that are on THIS paper, in the paper's order — not the order of marking;
 *   - a lost answer is "unconfirmed" (never "failed, send again"); a refusal is final;
 *   - the results name no winner the server did not name.
 */
import type { Api } from '@gs/core/api'
import {
  BLANK_PAPER,
  NOT_RECEIVED,
  UNCONFIRMED,
  aftermathNote,
  castFailureOf,
  closesLabel,
  continueLabel,
  dayLabel,
  gateOf,
  gateOfError,
  gateShut,
  initials,
  markedLabels,
  optionIdsOf,
  pagesOf,
  paperOf,
  recheckedGate,
  resultsView,
  seatRule,
  shortNames,
  toggleMark,
  unmarked,
  type Marks,
  type PaperPosition,
} from '../paper'

const chairman: PaperPosition = { key: 'p1', name: 'Chairman', seats: 1, options: [{ id: 101, label: 'Dwayne Robinson' }, { id: 102, label: 'Sonia Campbell' }, { id: 103, label: 'Keith Walters' }] }
const vice: PaperPosition = { key: 'p2', name: 'Vice Chairman', seats: 3, options: [{ id: 111, label: 'Michelle Palmer' }, { id: 112, label: 'Andre Thompson' }, { id: 113, label: 'Rachel Bennett' }, { id: 114, label: 'Owen Grant' }] }
const paper = [chairman, vice]

const eligibility = (over: Partial<Api.ElectionsEligibilityResponse>): Api.ElectionsEligibilityResponse => ({ ballot_id: 5, eligible: true, reason: null, checked_on: '2026-09-19', voting_open: true, has_voted: false, voted_on: null, why: null, ...over })

describe('the gate: eligibility before anything is drawn', () => {
  it('opens only for an eligible household, an open poll and no paper in', () => {
    expect(gateOf(eligibility({}))).toEqual({ state: 'open', checkedOn: '2026-09-19', why: null })
  })

  it('carries the server’s sentence on why this person holds the vote, as it was said (M21 §1.3)', () => {
    const why = 'You hold the vote for Fletcher household at Lot 47 as a Member of the household.'
    expect(gateOf(eligibility({ why: `  ${why} ` }))).toEqual({ state: 'open', checkedOn: '2026-09-19', why })
    expect(gateOf(eligibility({ why: '   ' }))).toEqual({ state: 'open', checkedOn: '2026-09-19', why: null })
  })

  it('an ineligible household gets the holding state, with the server’s reason and nothing more', () => {
    expect(gateOf(eligibility({ eligible: false, reason: 'arrears >90 days' }))).toEqual({ state: 'ineligible', reason: 'arrears >90 days' })
    expect(gateOf(eligibility({ eligible: false, reason: '  ' }))).toEqual({ state: 'ineligible', reason: null })
  })

  it('a paper already in wins over everything: the receipt, never the choices', () => {
    expect(gateOf(eligibility({ has_voted: true }))).toEqual({ state: 'voted' })
    expect(gateOf(eligibility({ has_voted: true, eligible: false, voting_open: false }))).toEqual({ state: 'voted' })
  })

  it('a closed poll is closed; ineligible is said before closed', () => {
    expect(gateOf(eligibility({ voting_open: false }))).toEqual({ state: 'closed' })
    expect(gateOf(eligibility({ voting_open: false, eligible: false }))).toMatchObject({ state: 'ineligible' })
  })

  it('anything that is not a plain `true` does not open the gate', () => {
    expect(gateOf(eligibility({ eligible: 'yes' as unknown as boolean })).state).toBe('ineligible')
    expect(gateOf(eligibility({ voting_open: 1 as unknown as boolean })).state).toBe('closed')
  })
})

describe('the paper', () => {
  const election = {
    title: 'Phoenix Park Village 1: 2026 Election',
    question: null,
    positions: [
      { id: 1, name: 'Chairman', seat_count: 1, options: [{ id: '101', label: 'Dwayne Robinson' }, { id: 102, label: ' Sonia Campbell ' }] },
      { id: 2, name: 'Empty seat', seat_count: 1, options: [] },
      { id: 3, name: 'Vice Chairman', seat_count: 3, options: [{ id: 111, label: 'Michelle Palmer' }, { id: 'x', label: 'Not an id' }] },
    ],
    options: [],
  }

  it('normalises ids to integers, drops what cannot be sent, and skips a position with nobody standing', () => {
    expect(paperOf(election)).toEqual([
      { key: 'p1', name: 'Chairman', seats: 1, options: [{ id: 101, label: 'Dwayne Robinson' }, { id: 102, label: 'Sonia Campbell' }] },
      { key: 'p3', name: 'Vice Chairman', seats: 3, options: [{ id: 111, label: 'Michelle Palmer' }] },
    ])
  })

  it('a resolution is one question with one mark', () => {
    expect(paperOf({ title: 'Gate upgrade', question: 'Approve the gate upgrade levy?', positions: [], options: [{ id: 7, label: 'For' }, { id: 8, label: 'Against' }] })).toEqual([
      { key: 'question', name: 'Approve the gate upgrade levy?', seats: 1, options: [{ id: 7, label: 'For' }, { id: 8, label: 'Against' }] },
    ])
  })

  it('lays one paper over two pages: two open, two open, the rest as rows', () => {
    const six = Array.from({ length: 6 }, (_, i): PaperPosition => ({ key: `p${i}`, name: `Position ${i}`, seats: 1, options: [{ id: i, label: 'A B' }] }))
    const pages = pagesOf(six)
    expect([pages.a.length, pages.b.length, pages.rest.length, pages.two]).toEqual([2, 2, 2, true])
    expect(pagesOf(six.slice(0, 2))).toMatchObject({ two: false, b: [], rest: [] })
  })

  it('writes the board’s words', () => {
    expect(seatRule(1)).toBe('Choose 1')
    expect(seatRule(3)).toBe('Choose up to 3')
    expect(initials('Dwayne Robinson')).toBe('DR')
    expect(initials('For')).toBe('F')
    expect(continueLabel([])).toBe('Review & submit vote')
    expect(continueLabel([{ ...chairman, name: 'Secretary' }, { ...chairman, name: 'Treasurer' }, chairman])).toBe('Continue: Secretary, Treasurer & more')
    expect(continueLabel([{ ...chairman, name: 'A very long position name indeed' }, { ...chairman, name: 'Another long one' }])).toBe('Continue to the next positions')
    expect(shortNames(['Dwayne Robinson'])).toBe('Dwayne Robinson')
    expect(shortNames(['Michelle Palmer', 'Andre Thompson'])).toBe('M. Palmer, A. Thompson')
  })

  it('shows the close in the site’s zone, and a DATE as it is written', () => {
    // The one form (M98, the consoles' "9 Oct 2026, 2:53 AM"), no tag.
    expect(closesLabel('2026-09-28T23:00:00Z')).toBe('28 Sep 2026, 6:00 PM')
    expect(closesLabel(null)).toBeNull()
    expect(closesLabel('not a date')).toBeNull()
    expect(dayLabel('2026-09-19')).toBe('19 Sep 2026')
    expect(dayLabel('')).toBeNull()
  })
})

describe('marking', () => {
  it('one seat is a radio: a new mark replaces the old, a second tap clears it', () => {
    let marks: Marks = {}
    marks = toggleMark(marks, chairman, 101).marks
    marks = toggleMark(marks, chairman, 102).marks
    expect(marks).toEqual({ p1: [102] })
    expect(toggleMark(marks, chairman, 102).marks).toEqual({})
  })

  it('refuses a mark past the seat count: told before the paper is cast, not after', () => {
    let marks: Marks = {}
    for (const id of [111, 112, 113]) marks = toggleMark(marks, vice, id).marks
    const fourth = toggleMark(marks, vice, 114)
    expect(fourth.refused).toBe('full')
    expect(fourth.marks).toBe(marks)
    expect(toggleMark(marks, vice, 112).marks).toEqual({ p2: [111, 113] })
  })

  it('ignores an option that is not on this position', () => {
    const result = toggleMark({}, chairman, 999)
    expect(result).toEqual({ marks: {}, refused: null })
  })

  it('never mutates the marks it was given', () => {
    const before: Marks = Object.freeze({ p1: Object.freeze([101]) as readonly number[] })
    expect(() => toggleMark(before, chairman, 102)).not.toThrow()
    expect(before).toEqual({ p1: [101] })
  })

  it('the request carries ids on THIS paper only, within the seats, in the paper’s order, not the order of tapping', () => {
    const marks: Marks = { p2: [113, 111], p1: [102], p9: [555] }
    expect(optionIdsOf(paper, marks)).toEqual([102, 111, 113])
    expect(optionIdsOf(paper, { p1: [101, 102] })).toEqual([101])
    expect(optionIdsOf(paper, { p1: [999] })).toEqual([])
    expect(optionIdsOf(paper, {})).toEqual([])
    expect(BLANK_PAPER).toMatch(/blank paper is not a vote/i)
  })

  it('reads the marks back for the review, and counts what was left blank', () => {
    expect(markedLabels(vice, { p2: [113, 111] })).toEqual(['Michelle Palmer', 'Rachel Bennett'])
    expect(unmarked(paper, { p2: [111] }).map((p) => p.name)).toEqual(['Chairman'])
  })
})

describe('what a failed cast means', () => {
  const e = (status: number | 'network' | 'timeout', code: string, message = 'The server’s sentence.') => ({ status, code, message })

  it('already voted → the receipt; not eligible → the gate; closed → closed', () => {
    expect(castFailureOf(e(409, 'already_voted'))).toEqual({ kind: 'voted' })
    expect(castFailureOf(e(403, 'not_eligible'))).toEqual({ kind: 'ineligible' })
    expect(castFailureOf(e(409, 'poll_closed', 'Voting is not open.'))).toEqual({ kind: 'closed', message: 'Voting is not open.' })
  })

  it('a lost answer is UNCONFIRMED: the app does not claim the vote failed', () => {
    expect(castFailureOf(e('network', 'network_unreachable'))).toEqual({ kind: 'unconfirmed' })
    expect(castFailureOf(e('timeout', 'timeout'))).toEqual({ kind: 'unconfirmed' })
    expect(castFailureOf(e(503, 'server_error'))).toEqual({ kind: 'unconfirmed' })
    expect(UNCONFIRMED).toBe('We could not confirm your vote was received: check the receipt screen before trying again.')
  })

  it('any other refusal is final and carries the server’s sentence', () => {
    expect(castFailureOf(e(422, 'paper_invalid', 'Chairman has 1 seat and this paper marks 2.'))).toEqual({ kind: 'refused', message: 'Chairman has 1 seat and this paper marks 2.' })
    expect(castFailureOf(e(429, 'rate_limited', 'Too many requests.'))).toEqual({ kind: 'refused', message: 'Too many requests.' })
  })
})

describe('asking again after a lost answer, never the answer from BEFORE the paper went', () => {
  const before = eligibility({ has_voted: false })
  const network = { status: 'network' as const, code: 'network_unreachable', message: 'No connection.' }

  it('a refetch that FAILS still carries the old answer as `data`: it is NOT read: nothing is known', () => {
    // Exactly what RTK Query hands back when the re-ask fails: the failure AND the last good answer.
    expect(recheckedGate({ data: before, error: network, isError: true, fulfilledTimeStamp: 1_000 }, 5_000, '5')).toEqual({ state: 'offline' })
    expect(recheckedGate({ data: before, error: { ...network, status: 'timeout' }, isError: true, fulfilledTimeStamp: 1_000 }, 5_000, '5')).toEqual({ state: 'offline' })
    expect(recheckedGate({ data: before, error: { status: 503, code: 'server_error', message: 'The server had a problem.' }, isError: true, fulfilledTimeStamp: 1_000 }, 5_000, '5')).toEqual({ state: 'error', message: 'The server had a problem.' })
    // …and it is never "open", which the review would read as "your vote was NOT received".
    expect(recheckedGate({ data: before, error: network, isError: true, fulfilledTimeStamp: 9_000 }, 5_000, '5').state).not.toBe('open')
  })

  it('an answer fulfilled BEFORE the question was asked, or for another ballot, is no answer', () => {
    expect(recheckedGate({ data: before, fulfilledTimeStamp: 4_999 }, 5_000, '5')).toEqual({ state: 'checking' })
    expect(recheckedGate({ data: before }, 5_000, '5')).toEqual({ state: 'checking' })
    expect(recheckedGate({ data: before, fulfilledTimeStamp: 6_000 }, 5_000, '6')).toEqual({ state: 'checking' })
    expect(recheckedGate({}, 5_000, '5')).toEqual({ state: 'checking' })
    expect(recheckedGate({ isError: true }, 5_000, '5')).toEqual({ state: 'checking' })
  })

  it('only a fresh answer is read: the paper is in → voted; it is not → open', () => {
    expect(recheckedGate({ data: eligibility({ has_voted: true }), fulfilledTimeStamp: 5_000 }, 5_000, '5')).toEqual({ state: 'voted' })
    expect(recheckedGate({ data: before, isError: false, fulfilledTimeStamp: 6_000 }, 5_000, '5')).toEqual({ state: 'open', checkedOn: '2026-09-19', why: null })
  })

  it('reads a failed question: no connection, no such election, no access, the server’s sentence', () => {
    expect(gateOfError({ status: 'network', message: '' })).toEqual({ state: 'offline' })
    expect(gateOfError({ status: 404, message: 'No such election.' })).toEqual({ state: 'missing' })
    expect(gateOfError({ status: 403, message: 'Forbidden.' })).toEqual({ state: 'denied' })
    expect(gateOfError({ status: 500, message: 'The server had a problem.' })).toEqual({ state: 'error', message: 'The server had a problem.' })
  })
})

describe('when the gate shuts, the marks go: a connection that comes and goes does not shut it', () => {
  it('a settled "no" from the server shuts the gate', () => {
    for (const state of ['ineligible', 'voted', 'closed', 'denied', 'missing'] as const) expect(gateShut(state)).toBe(true)
  })

  it('open, still asking, no connection and a server error do not: the household keeps its marks through a blip', () => {
    for (const state of ['open', 'checking', 'offline', 'error'] as const) expect(gateShut(state)).toBe(false)
  })
})

describe('a holding screen that replaces the review after a send still says what is known', () => {
  it('says nothing when no paper was handed over, or when the review / the receipt says it', () => {
    for (const gate of ['checking', 'offline', 'error', 'closed', 'ineligible', 'missing', 'denied', 'open', 'voted'] as const) expect(aftermathNote(null, gate)).toBeNull()
    expect(aftermathNote('lost', 'open')).toBeNull()
    expect(aftermathNote('lost', 'voted')).toBeNull()
  })

  it('a lost answer with no fresh answer since: NOT KNOWN, never "failed", never "send again"', () => {
    for (const gate of ['checking', 'offline', 'error', 'missing', 'denied'] as const) {
      const note = aftermathNote('lost', gate) ?? ''
      expect(note).toMatch(/could not confirm your vote was received/)
      expect(note).toMatch(/will not send it again/)
      expect(note).not.toMatch(/NOT received|was not received/)
    }
  })

  it('"not open" and "not eligible" are read only after has_voted: no paper is in, and that is said', () => {
    expect(aftermathNote('lost', 'closed')).toBe(NOT_RECEIVED)
    expect(aftermathNote('lost', 'ineligible')).toBe(NOT_RECEIVED)
  })

  it('a refusal is final, whatever the gate then says', () => {
    for (const gate of ['closed', 'ineligible', 'error', 'offline'] as const) expect(aftermathNote('refused', gate)).toMatch(/not accepted.*never sends a refused paper again/)
  })
})

describe('published results', () => {
  const results = (over: Partial<Api.ElectionsResultsResponse>): Api.ElectionsResultsResponse =>
    ({
      id: 5, year: '2026', title: 'Phoenix Park Village 1: 2026 Election', kind: 'election', question: null, stage: 'published', stage_label: 'Published', opens_at: null, closes_at: null,
      certified_at: '2026-09-29T01:02:00Z', published_at: '2026-09-29T02:00:00Z', outcome_statement: null,
      turnout: { cast: 318, eligible: 450, percent: 71 }, quorum: { required: 150, met: true },
      positions: [
        { id: 1, name: 'Chairman', seat_count: 1, options: [{ id: 102, label: 'Sonia Campbell', votes: 96 }, { id: 101, label: 'Dwayne Robinson', votes: 187 }] },
        { id: 2, name: 'Vice Chairman', seat_count: 3, options: [{ id: 111, label: 'Michelle Palmer', votes: '201' }, { id: 112, label: 'Andre Thompson', votes: '201' }] },
      ],
      options: [],
      ...over,
    }) as unknown as Api.ElectionsResultsResponse

  it('writes the certified counts, most marks first, with each share of the households that voted', () => {
    const view = resultsView(results({}))
    expect(view).toMatchObject({ badge: 'CERTIFIED BY RETURNING OFFICER', certified: 'Certified 28 Sep 2026, 8:02 PM', turnoutCast: '318', turnoutRest: ' of 450 households', turnoutPercent: 71, quorum: 'Quorum met: 150 households were needed' })
    expect(view.groups[0]).toMatchObject({ heading: 'Chairman', rows: [{ label: 'Dwayne Robinson', initials: 'DR', votes: '187', share: '59%' }, { label: 'Sonia Campbell', votes: '96', share: '30%' }] })
    expect(view.groups[1]?.heading).toBe('Vice Chairman · 3 seats')
  })

  it('names NO winner, not even a clear one, and a tie keeps the paper’s order', () => {
    const view = resultsView(results({}))
    expect(view.groups.flatMap((g) => g.rows).every((r) => r.crown === false)).toBe(true)
    expect(view.groups[1]?.rows.map((r) => r.label)).toEqual(['Michelle Palmer', 'Andre Thompson'])
  })

  it('shows the returning officer’s statement as written, says when quorum was not met, and survives missing figures', () => {
    const view = resultsView(results({ outcome_statement: ' D. Robinson is elected Chairman. ', quorum: { required: '200', met: false }, turnout: { cast: '0', eligible: '450', percent: 0 }, certified_at: null as unknown as string }))
    expect(view.statement).toBe('D. Robinson is elected Chairman.')
    expect(view.quorum).toBe('Quorum was not met: 200 households were needed')
    expect(view.badge).toBe('PUBLISHED RESULT')
    expect(view.certified).toBe('Published 28 Sep 2026, 9:00 PM')
    expect(view.groups[0]?.rows[0]?.share).toBeNull()
  })

  it('a resolution’s options are one group under its question', () => {
    const view = resultsView(results({ positions: [], question: 'Approve the levy?', options: [{ id: 7, label: 'For', votes: '1200' }, { id: 8, label: 'Against', votes: '80' }] }))
    expect(view.groups).toHaveLength(1)
    expect(view.groups[0]).toMatchObject({ heading: 'Approve the levy?', rows: [{ label: 'For', votes: '1,200' }, { label: 'Against', votes: '80' }] })
  })
})

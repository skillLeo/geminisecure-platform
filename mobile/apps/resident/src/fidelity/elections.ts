/**
 * The voting boards' own data (resident-app-15 Ballot A · -24 Ballot B · -34 Review · -16 Receipt ·
 * -28 Results), served only in web fidelity mode. Every use sits behind `isFidelity`.
 *
 * WHAT THE BOARDS DRAW THAT THE SERVER DOES NOT HAVE — it exists here and nowhere else:
 *   - two ballots ("A" and "B") cast together. The server has ONE paper per election; the app
 *     draws that one paper over two pages.
 *   - a line under each candidate ("Phase 1 · 6 years on the committee"). The paper gives a name.
 *   - a named "designated voter". The server says only whether the household may vote.
 *   - a RECEIPT CODE and "Verify my vote". There is none: a paper is stored with nothing that
 *     links it to a household, so there is nothing a code could look up. The server's answer to a
 *     vote is the ballot, `voted: true` and a DATE.
 *   - a time of casting ("11:02 AM"). The server records the day, never the time.
 *   - per-phase turnout bars and a crown on each winner. `elections.results` has counts per
 *     option, turnout and quorum; it names no winner and no phase.
 *
 * The marks below are the board's sample marks. They are not anybody's vote.
 */

type BoardOption = { id: number; label: string; sub: string }
type BoardPosition = { key: string; name: string; seats: number; options: readonly BoardOption[] }

const chairman: BoardPosition = {
  key: 'a1',
  name: 'Chairman',
  seats: 1,
  options: [
    { id: 101, label: 'Dwayne Robinson', sub: 'Phase 1 · 6 years on the committee' },
    { id: 102, label: 'Sonia Campbell', sub: 'Phase 4 · former Treasurer' },
    { id: 103, label: 'Keith Walters', sub: 'Phase 2 · new nominee' },
  ],
}

const viceChairman: BoardPosition = {
  key: 'a2',
  name: 'Vice Chairman',
  seats: 3,
  options: [
    { id: 111, label: 'Michelle Palmer', sub: 'Phase 3' },
    { id: 112, label: 'Andre Thompson', sub: 'Phase 5' },
    { id: 113, label: 'Rachel Bennett', sub: 'Phase 1' },
  ],
}

const phaseLead: BoardPosition = {
  key: 'b1',
  name: 'Phase Lead',
  seats: 1,
  options: [
    { id: 201, label: 'Natalie Wong', sub: 'Lot 12' },
    { id: 202, label: 'Omar Brown', sub: 'Lot 31' },
  ],
}

const assistantPhaseLead: BoardPosition = {
  key: 'b2',
  name: 'Assistant Phase Lead',
  seats: 1,
  options: [
    { id: 211, label: 'Tanya Simms', sub: 'Lot 8' },
    { id: 212, label: 'Ricardo Hall', sub: 'Lot 55' },
  ],
}

export const ballotAFixture = {
  appBar: 'Community election',
  eyebrow: 'BALLOT A · COMMUNITY EXECUTIVE',
  heading: 'Phoenix Park Village 1 — 2026 Election',
  sub: 'Voting as Lot 47 · one vote per household · closes Sun 28 Sep, 6:00 PM',
  expanded: [chairman, viceChairman],
  compact: [] as readonly BoardPosition[],
  marks: { a1: [101], a2: [111, 112] } as Readonly<Record<string, readonly number[]>>,
  note: "Only one household member can vote per ballot. Andrea Fletcher is Lot 47's designated voter for this election.",
  cta: 'Continue — Secretary, Treasurer & more',
} as const

export const ballotBFixture = {
  appBar: 'Phase 2 election',
  eyebrow: 'BALLOT B · PHASE 2 LEADERSHIP',
  heading: 'Phase 2 Leadership — 2026',
  sub: 'Only Phase 2 households vote here · closes Sun 28 Sep, 6:00 PM',
  expanded: [phaseLead, assistantPhaseLead],
  compact: [
    { key: 'b3', name: 'Secretary', seats: 1, options: [] },
    { key: 'b4', name: 'Treasurer', seats: 1, options: [] },
  ] as readonly BoardPosition[],
  marks: { b1: [201], b2: [211] } as Readonly<Record<string, readonly number[]>>,
  note: null,
  cta: 'Review & submit vote',
} as const

export const reviewFixture = {
  eyebrow: 'FINAL REVIEW · LOT 47',
  groups: [
    {
      key: 'a',
      heading: 'Ballot A · Community Executive',
      edit: 'Edit Ballot A',
      rows: [
        { key: 'r1', position: 'Chairman', value: 'Dwayne Robinson' },
        { key: 'r2', position: 'Vice Chairman (3)', value: 'M. Palmer, A. Thompson' },
        { key: 'r3', position: 'Secretary', value: 'Sonia Campbell' },
        { key: 'r4', position: 'Asst. Secretary', value: 'Keith Walters' },
        { key: 'r5', position: 'Treasurer', value: 'Rachel Bennett' },
        { key: 'r6', position: 'Asst. Treasurer', value: 'Patrice Reid' },
        { key: 'r7', position: 'Security Coordinator', value: 'Owen Grant' },
        { key: 'r8', position: 'PRO', value: 'Natalie Wong' },
        { key: 'r9', position: 'Asst. PRO', value: 'Omar Brown' },
        { key: 'r10', position: 'Welfare & Events', value: 'Tanya Simms' },
      ],
    },
    {
      key: 'b',
      heading: 'Ballot B · Phase 2 Leadership',
      edit: 'Edit Ballot B',
      rows: [
        { key: 'r11', position: 'Phase Lead', value: 'Natalie Wong' },
        { key: 'r12', position: 'Asst. Phase Lead', value: 'Tanya Simms' },
        { key: 'r13', position: 'Secretary', value: 'Ricardo Hall' },
        { key: 'r14', position: 'Treasurer', value: 'Omar Brown' },
      ],
    },
  ],
  note: "This submits Lot 47's one and only vote for both ballots. You'll get a receipt code to verify it was counted.",
} as const

export const receiptFixture = {
  body: 'One vote recorded for Lot 47 across both the community executive and Phase 2 leadership ballots.',
  boxLabel: 'YOUR RECEIPT CODE',
  boxValue: '8F3A · C1E0 · 9B77',
  boxCaption: 'Cast today at 11:02 AM · save this to verify your vote was counted',
} as const

export const resultsFixture = {
  badge: 'CERTIFIED BY RETURNING OFFICER',
  title: 'Phoenix Park Village 1 — 2026 Election',
  certified: 'Certified Sep 28, 8:02 PM',
  turnoutCast: '318',
  turnoutRest: ' of 450 households',
  turnoutPercent: 71,
  phases: [
    { label: 'P1 · 80%', percent: 80 },
    { label: 'P2 · 65%', percent: 65 },
    { label: 'P3 · 74%', percent: 74 },
    { label: 'P4 · 58%', percent: 58 },
    { label: 'P5 · 69%', percent: 69 },
  ],
  groups: [
    {
      key: 'g1',
      heading: 'Chairman',
      rows: [{ id: '101', label: 'Dwayne Robinson', initials: 'DR', sub: 'Phase 1', votes: '187', share: '59%', crown: true }],
    },
    {
      key: 'g2',
      heading: 'Vice Chairman · 3 seats',
      rows: [
        { id: '111', label: 'Michelle Palmer', initials: 'MP', sub: 'Phase 3', votes: '201', share: '63%', crown: true },
        { id: '112', label: 'Andre Thompson', initials: 'AT', sub: 'Phase 5', votes: '176', share: '55%', crown: true },
        { id: '113', label: 'Rachel Bennett', initials: 'RB', sub: 'Phase 1', votes: '159', share: '50%', crown: true },
      ],
    },
  ],
} as const

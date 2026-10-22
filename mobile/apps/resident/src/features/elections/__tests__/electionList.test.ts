import { electionRows, type ElectionListItem } from '../electionList'

const item = (over: Partial<ElectionListItem>): ElectionListItem => ({
  id: 1, title: '2026 Election', kind: 'election', voting_open: false, has_voted: false, results_published: false, closes_at: null, ...over,
})

describe('Elections list (Settings → Governance, 8 Oct 2026)', () => {
  it('puts the open vote first and opens the paper, then the receipt, then the results', () => {
    const rows = electionRows([
      item({ id: 1, title: 'Old', results_published: true }),
      item({ id: 2, title: 'Voted', voting_open: true, has_voted: true }),
      item({ id: 3, title: 'Open', voting_open: true, closes_at: '2026-10-15T23:00:00Z' }),
    ])
    expect(rows.map((r) => r.title)).toEqual(['Open', 'Voted', 'Old'])
    expect(rows[0]).toMatchObject({ chip: { label: 'Voting open', tone: 'navy' }, path: '/elections/3/ballot-a' })
    expect(rows[0]!.detail).toMatch(/^Closes /)
    expect(rows[1]).toMatchObject({ chip: { label: 'You voted', tone: 'green' }, detail: 'Open your receipt', path: '/elections/2/receipt' })
    expect(rows[2]).toMatchObject({ chip: { label: 'Results' }, path: '/elections/1/results' })
  })

  it('says where an election stands when there is nothing to open, and opens nothing', () => {
    const [vetting] = electionRows([item({ id: 4, stage: 'candidate_vetting', stage_label: 'Candidate vetting' })])
    expect(vetting).toMatchObject({ chip: { label: 'Candidate vetting' }, detail: 'Voting has not opened yet.', path: null })

    const [closed] = electionRows([item({ id: 5, stage: 'tally' })])
    expect(closed).toMatchObject({ chip: { label: 'Closed' }, path: null })
    expect(closed!.detail).toContain('Voting has closed')

    // Cancelled by the estate (M56 §3): said as that, and nothing to open.
    const [cancelled] = electionRows([item({ id: 7, stage: 'cancelled', stage_label: 'Cancelled' })])
    expect(cancelled).toMatchObject({ chip: { label: 'Cancelled', tone: 'slate' }, detail: 'The estate office cancelled this election.', path: null })
  })

  it('names a resolution as one, and says the whole row to a screen reader without an em dash', () => {
    const [row] = electionRows([item({ id: 6, kind: 'resolution', title: 'Pool hours', voting_open: true })])
    expect(row!.kind).toBe('Resolution')
    expect(row!.label).toBe('Resolution: Pool hours. Voting open. Open now')
    expect(row!.label).not.toContain('—')
  })
})

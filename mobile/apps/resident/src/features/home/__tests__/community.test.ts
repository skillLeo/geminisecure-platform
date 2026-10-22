import { electionRow, meetingRow, openElection, type ElectionItem, type MeetingItem } from '../community'

const AT = new Date('2026-09-04T14:41:00Z')
const election = (over: Partial<ElectionItem>): ElectionItem => ({ id: 3, title: 'Board election 2026', kind: 'election', voting_open: true, has_voted: false, results_published: false, closes_at: '2026-09-10T22:00:00Z', ...over })
const meeting = (over: Partial<MeetingItem>): MeetingItem => ({ id: 8, title: 'Annual General Meeting', starts_at: '2026-09-12T23:00:00Z', status: 'scheduled', my_rsvp: null, ...over })

describe('voting on Home', () => {
  it('is offered only when the SERVER says this household may vote', () => {
    expect(electionRow([election({})], true)).toMatchObject({ title: 'Voting is open', path: '/elections/3/ballot-a' })
    expect(electionRow([election({})], true)!.detail).toBe('Board election 2026 · closes 10 Sep 2026, 5:00 PM')
  })

  it('an INELIGIBLE household sees no invitation at all, not a disabled one, not a reason', () => {
    expect(electionRow([election({})], false)).toBeNull()
  })

  it('nothing is offered on a guess: until the server has answered (or with no signal) there is no row', () => {
    expect(electionRow([election({})], null)).toBeNull()
  })

  it('a resolution is named as one', () => expect(electionRow([election({ kind: 'resolution' })], true)!.title).toBe('A resolution is open for your vote'))

  it('after voting the row opens the RECEIPT; once published, the results', () => {
    expect(electionRow([election({ has_voted: true })], null)).toMatchObject({ title: 'Your household has voted', path: '/elections/3/receipt' })
    expect(electionRow([election({ voting_open: false, has_voted: true, results_published: true })], null)).toMatchObject({ title: 'Election results', path: '/elections/3/results' })
  })

  it('never says HOW anyone voted: the list does not know', () => {
    const row = electionRow([election({ has_voted: true })], null)!
    expect(JSON.stringify(row)).not.toMatch(/voted for|chose|candidate/i)
  })

  it('no election, no row; the one asked about is the open, un-voted one', () => {
    expect(electionRow([], true)).toBeNull()
    expect(openElection([election({ id: 1, has_voted: true }), election({ id: 2 })])?.id).toBe(2)
    expect(openElection([election({ voting_open: false })])).toBeNull()
  })
})

describe('the next meeting on Home', () => {
  it('is the soonest one still ahead, in the site’s zone', () => {
    const row = meetingRow([meeting({ id: 9, starts_at: '2026-10-01T23:00:00Z' }), meeting({})], AT)!
    expect(row).toMatchObject({ key: 'meeting:8', path: '/meetings/8', title: 'Annual General Meeting' })
    expect(row.detail).toBe('12 Sep 2026, 6:00 PM · tell the estate if you are coming')
  })

  it('says the household\'s own RSVP when there is one', () => {
    expect(meetingRow([meeting({ my_rsvp: 'attending' })], AT)!.detail).toMatch(/you are attending/)
  })

  it('a meeting that started within the last three hours is still "now"; older, held or cancelled ones are never offered as the next one', () => {
    expect(meetingRow([meeting({ starts_at: '2026-09-04T13:00:00Z' })], AT)).toMatchObject({ key: 'meeting:8' })
    expect(meetingRow([meeting({ starts_at: '2026-09-04T10:00:00Z' })], AT)).toMatchObject({ key: 'meetings:all' })
    expect(meetingRow([meeting({ status: 'cancelled' }), meeting({ status: 'held' })], AT)).toMatchObject({ key: 'meetings:all' })
  })

  it('the next meeting opens its lobby, with "All meetings" beside it; with nothing ahead the row IS the way in to the past ones', () => {
    expect(meetingRow([meeting({})], AT)).toMatchObject({ path: '/meetings/8', more: { label: 'All meetings', path: '/meetings' } })
    const past = meetingRow([meeting({ status: 'held' })], AT)!
    expect(past).toMatchObject({ key: 'meetings:all', title: 'Meetings', path: '/meetings', detail: 'No meeting is scheduled. See past meetings.' })
    expect(past.more).toBeUndefined()
    // An estate that has never called a meeting has no row at all — there is nothing to open.
    expect(meetingRow([], AT)).toBeNull()
  })

  it('a meeting postponed with no new date is still to come: Home names it, and never says "see past meetings" (M98)', () => {
    const noDate = meeting({ status: 'postponed', date_to_be_set: true, starts_at: '2026-09-01T23:00:00Z' })
    expect(meetingRow([noDate], AT)).toEqual({ key: 'meeting:8', icon: 'input', title: 'Annual General Meeting', detail: 'Postponed · New date to be set', path: '/meetings/8', tone: 'navy-600', more: { label: 'All meetings', path: '/meetings' } })
    // A meeting that has a date is still the next one.
    expect(meetingRow([meeting({ id: 9, status: 'postponed', date_to_be_set: true }), meeting({})], AT)).toMatchObject({ key: 'meeting:8', detail: '12 Sep 2026, 6:00 PM · tell the estate if you are coming' })
    // And a flagged one is never offered with its first day, whatever its status says.
    expect(meetingRow([meeting({ date_to_be_set: true })], AT)!.detail).toBe('Postponed · New date to be set')
  })

  it('"soonest" is judged by the moment, not by how the time was written', () => {
    // 23:00Z on the 12th is EARLIER than 18:30-05:00 on the 12th, though it sorts after it as text.
    expect(meetingRow([meeting({ id: 1, starts_at: '2026-09-12T18:30:00-05:00' }), meeting({ id: 2, starts_at: '2026-09-12T23:00:00Z' })], AT)).toMatchObject({ key: 'meeting:2' })
  })
})

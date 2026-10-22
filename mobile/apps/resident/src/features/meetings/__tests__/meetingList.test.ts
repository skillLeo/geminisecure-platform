/**
 * "All meetings": upcoming first (soonest at the top), then the past (newest first); a status in words on every
 * row; the household's reply said as it really stands; every row opens the existing lobby; and older pages are
 * never offered with no connection.
 */
import { END_OF_LIST, chipOf, isAhead, kindLabel, olderBlocked, replyLine, rowOf, sectionsOf, splitMeetings, whenOf } from '../meetingList'
import type { Meeting, RsvpOp } from '../meetings'

const meeting = (patch: Partial<Meeting> = {}): Meeting => ({
  id: 12,
  type: 'agm',
  title: 'Phoenix Park Village 1: AGM 2026',
  starts_at: '2026-09-27T15:00:00+00:00', // 10:00 AM in Kingston
  venue: 'Community Centre',
  virtual_link: null,
  status: 'scheduled',
  recording_enabled: false,
  agenda: [],
  minutes_available: false,
  my_rsvp: null,
  ...patch,
})

const op = (patch: Partial<RsvpOp> = {}): RsvpOp => ({ id: 'op-1', endpoint: 'meetings.rsvp', params: { meeting: 12 }, body: { response: 'attending' }, state: 'pending', lastTriedAt: null, error: null, result: null, ...patch })

const NOW = new Date('2026-09-20T15:00:00Z')

describe('upcoming first, then the past', () => {
  const items = [
    meeting({ id: 1, starts_at: '2026-03-14T15:00:00Z', status: 'held' }),
    meeting({ id: 2, starts_at: '2026-11-07T15:00:00Z' }),
    meeting({ id: 3, starts_at: '2026-09-27T15:00:00Z' }),
    meeting({ id: 4, starts_at: '2025-09-27T15:00:00Z', status: 'held' }),
    meeting({ id: 5, starts_at: '2026-10-03T15:00:00Z', status: 'cancelled' }),
    meeting({ id: 6, starts_at: '2026-06-01T15:00:00Z', status: 'cancelled' }),
    meeting({ id: 7, starts_at: '2026-09-01T15:00:00Z' }), // still "scheduled": the office never marked it held
    meeting({ id: 8, starts_at: '2026-09-20T14:30:00Z' }), // began half an hour ago
  ]

  it('what is ahead is soonest-first; what is behind is newest-first', () => {
    const { upcoming, past } = splitMeetings(items, NOW)
    expect(upcoming.map((m) => m.id)).toEqual([8, 3, 5, 2])
    expect(past.map((m) => m.id)).toEqual([7, 6, 1, 4])
  })

  it('a meeting cancelled BEFORE its day stays under Upcoming: a resident must see that Saturday is off', () => {
    expect(isAhead(meeting({ starts_at: '2026-10-03T15:00:00Z', status: 'cancelled' }), NOW)).toBe(true)
    expect(isAhead(meeting({ starts_at: '2026-06-01T15:00:00Z', status: 'cancelled' }), NOW)).toBe(false)
    expect(isAhead(meeting({ starts_at: '2026-09-27T15:00:00Z', status: 'held' }), NOW)).toBe(false)
  })

  it('two meetings at the same moment keep one order, whichever way the server sent them', () => {
    const pair = [meeting({ id: 31, starts_at: '2026-11-07T15:00:00Z' }), meeting({ id: 30, starts_at: '2026-11-07T15:00:00Z' })]
    expect(splitMeetings(pair, NOW).upcoming.map((m) => m.id)).toEqual(splitMeetings([...pair].reverse(), NOW).upcoming.map((m) => m.id))
  })

  it('a section with nothing in it is not drawn', () => {
    expect(sectionsOf([], [], NOW)).toEqual([])
    expect(sectionsOf([items[0]!], [], NOW).map((s) => s.title)).toEqual(['Past meetings'])
    expect(sectionsOf(items, [], NOW).map((s) => [s.title, s.rows.length])).toEqual([['Upcoming', 4], ['Past meetings', 4]])
  })
})

describe('a row', () => {
  it('says where the meeting stands, in words', () => {
    expect(chipOf(meeting({ starts_at: '2026-09-27T15:00:00Z' }), NOW)).toEqual({ label: 'Upcoming', tone: 'navy' })
    expect(chipOf(meeting({ starts_at: '2026-09-20T15:20:00Z' }), NOW)).toEqual({ label: 'Starting soon', tone: 'amber' })
    expect(chipOf(meeting({ starts_at: '2026-09-20T14:30:00Z' }), NOW)).toEqual({ label: 'Happening now', tone: 'amber' })
    expect(chipOf(meeting({ starts_at: '2026-09-01T15:00:00Z' }), NOW)).toEqual({ label: 'Date has passed', tone: 'slate' })
    expect(chipOf(meeting({ status: 'held' }), NOW)).toEqual({ label: 'Held', tone: 'green' })
    expect(chipOf(meeting({ status: 'cancelled' }), NOW)).toEqual({ label: 'Cancelled', tone: 'slate' })
  })

  it('names the kind without shouting, and shows one it does not know as written', () => {
    expect(kindLabel('agm')).toBe('Annual general meeting')
    expect(kindLabel('egm')).toBe('Extraordinary general meeting')
    expect(kindLabel('phase')).toBe('Phase meeting')
    expect(kindLabel('town_hall')).toBe('Town hall meeting')
    expect(kindLabel('')).toBe('Meeting')
  })

  it('gives the KINGSTON day and time in the one form, its year every time (M98)', () => {
    expect(whenOf(meeting(), NOW)).toBe('27 Sep 2026, 10:00 AM')
    expect(whenOf(meeting({ starts_at: '2024-09-28T15:00:00Z' }), NOW)).toBe('28 Sep 2024, 10:00 AM')
    // 02:00 UTC on the 28th is still 9:00 PM on the 27th in Kingston.
    expect(whenOf(meeting({ starts_at: '2026-09-28T02:00:00Z' }), NOW)).toBe('27 Sep 2026, 9:00 PM')
  })

  it('opens the existing lobby, and tells a screen reader everything on the row', () => {
    const row = rowOf(meeting({ status: 'held', starts_at: '2026-03-14T15:00:00Z', minutes_available: true, my_rsvp: 'attending' }), [], NOW)
    expect(row.path).toBe('/meetings/12')
    expect(row.minutes).toBe(true)
    expect(row.label).toBe('Phoenix Park Village 1: AGM 2026. Held. 14 Mar 2026, 10:00 AM. Community Centre. Your household will attend. Minutes are ready. Open this meeting')
    // Minutes are only ever promised for a meeting that was held.
    expect(rowOf(meeting({ minutes_available: true }), [], NOW).minutes).toBe(false)
  })
})

describe('postponed with no new date (M98, 12 Oct 2026)', () => {
  const noDate = (patch: Partial<Meeting> = {}): Meeting => meeting({ status: 'postponed', date_to_be_set: true, ...patch })

  it('stays under Upcoming whatever day it was first called for, after every meeting that has a date', () => {
    const items = [
      noDate({ id: 40, starts_at: '2026-09-01T15:00:00Z' }), // first called for a day now gone
      meeting({ id: 41, starts_at: '2026-11-07T15:00:00Z' }),
      meeting({ id: 42, starts_at: '2026-09-27T15:00:00Z' }),
      meeting({ id: 43, starts_at: '2026-03-14T15:00:00Z', status: 'held' }),
    ]
    const { upcoming, past } = splitMeetings(items, NOW)
    expect(upcoming.map((m) => m.id)).toEqual([42, 41, 40])
    expect(past.map((m) => m.id)).toEqual([43])
    expect(isAhead(noDate({ starts_at: '2025-01-01T15:00:00Z' }), NOW)).toBe(true)
  })

  it('says "New date to be set" where the date goes, never the first day, and asks for no reply', () => {
    const row = rowOf(noDate({ starts_at: '2026-09-01T15:00:00Z' }), [], NOW)
    expect(whenOf(noDate(), NOW)).toBe('New date to be set')
    expect(row.when).toBe('New date to be set')
    expect(row.chip).toEqual({ label: 'Postponed', tone: 'amber' })
    expect(row.reply).toBeNull()
    expect(row.label).toBe('Phoenix Park Village 1: AGM 2026. Postponed. New date to be set. Community Centre. Open this meeting')
    expect(row.label).not.toContain('September')
  })

  it('one postponed TO a new date arrives scheduled at it, and the row shows that date', () => {
    expect(whenOf(meeting({ starts_at: '2026-10-04T15:00:00Z', date_to_be_set: false }), NOW)).toBe('4 Oct 2026, 10:00 AM')
  })
})

describe('the household\'s reply, as it really stands', () => {
  it('the server\'s word, plainly', () => {
    expect(replyLine(meeting({ my_rsvp: 'apologies' }), [], NOW)).toBe('Your household sent apologies')
  })

  it('a reply still on this phone says so: it is never passed off as recorded', () => {
    expect(replyLine(meeting(), [op()], NOW)).toBe('Your household will attend · Pending sync')
    expect(replyLine(meeting(), [op({ state: 'syncing' })], NOW)).toBe('Your household will attend · Syncing')
    expect(replyLine(meeting(), [op({ state: 'confirmed', result: { response: 'attending' } })], NOW)).toBe('Your household will attend')
  })

  it('a refused reply is NOT the household\'s: the earlier one stands, or none does', () => {
    const refused = op({ state: 'refused', error: { message: 'This meeting has already started.' } })
    expect(replyLine(meeting({ my_rsvp: 'not_attending' }), [refused], NOW)).toBe('Your household will not attend')
    expect(replyLine(meeting(), [refused], NOW)).toBeNull()
  })

  it('asks for a reply only while one can still be given', () => {
    expect(replyLine(meeting(), [], NOW)).toBe('No reply from your household yet')
    expect(replyLine(meeting({ status: 'held', starts_at: '2026-03-14T15:00:00Z' }), [], NOW)).toBeNull()
    expect(replyLine(meeting({ status: 'cancelled', starts_at: '2026-10-03T15:00:00Z' }), [], NOW)).toBeNull()
  })
})

describe('older meetings', () => {
  it('are fetched from the estate, so with no connection the button says why it cannot be pressed', () => {
    expect(olderBlocked(true)).toBeNull()
    expect(olderBlocked(false)).toMatch(/need a connection/)
    expect(END_OF_LIST).toMatch(/every meeting/)
  })
})

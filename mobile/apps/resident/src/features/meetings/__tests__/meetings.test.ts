/**
 * Meetings — the Kingston date whatever the phone's zone; `next` is the next meeting still to come; the
 * estate's link is opened only when it is a plain https address, only near the start, only while the
 * meeting is scheduled; a recording is announced before joining; a reply the server has not got is never
 * called confirmed, a refused one is never the household's answer, and RSVPs are never called a quorum.
 */
import {
  agendaTime,
  attendingLine,
  dateToBeSet,
  dayLine,
  findMeeting,
  heroWhen,
  joinStateOf,
  linkHost,
  minutesLine,
  minutesView,
  nextMeeting,
  noAgendaLine,
  phaseOf,
  recordingLine,
  roomNote,
  rsvpClosedLine,
  rsvpNote,
  rsvpOpen,
  rsvpStateOf,
  safeLink,
  shouldQueueRsvp,
  staleLine,
  standing,
  statusShort,
  typeLabel,
  whenLine,
  whereOf,
  yourStart,
  type Meeting,
  type RsvpOp,
} from '../meetings'

const meeting = (patch: Partial<Meeting> = {}): Meeting => ({
  id: 12,
  type: 'agm',
  title: 'Phoenix Park Village 1: AGM 2026',
  starts_at: '2026-09-27T15:00:00+00:00', // 10:00 AM in Kingston
  venue: 'Community Centre',
  virtual_link: 'https://meet.example.org/agm-2026',
  status: 'scheduled',
  recording_enabled: false,
  agenda: [],
  minutes_available: false,
  my_rsvp: null,
  ...patch,
})

const op = (patch: Partial<RsvpOp> = {}): RsvpOp => ({
  id: 'op-1',
  endpoint: 'meetings.rsvp',
  params: { meeting: 12 },
  body: { response: 'attending' },
  state: 'pending',
  lastTriedAt: null,
  error: null,
  result: null,
  ...patch,
})

const at = (iso: string) => new Date(iso)

describe('postponed and cancelled, with the office\'s reason (M56 §2.3, §1.2)', () => {
  // The server sends `postponed` and `reason`, which the generated contract does not name yet.
  const postponed = { ...meeting(), status: 'postponed', reason: 'The clubhouse is being repaired.' } as unknown as Meeting
  const now = at('2026-09-20T15:00:00Z')

  it('a postponed meeting is still to come, says why, and takes no reply or join until it has a new date', () => {
    expect(phaseOf(postponed, now)).toBe('postponed')
    expect(standing(postponed, now)).toEqual({ phase: 'postponed', label: 'POSTPONED', line: 'The estate office postponed this meeting. A new date will follow. Reason: The clubhouse is being repaired.' })
    expect(statusShort(postponed, now)).toBe('Postponed')
    expect(rsvpOpen(postponed, now)).toBe(false)
    expect(joinStateOf(postponed, now)).toMatchObject({ canJoin: false, reason: 'This meeting is postponed. A new date will follow.' })
    expect(roomNote(postponed).title).toBe('This meeting is postponed')
    expect(noAgendaLine(postponed)).toBe('The agenda has not been published yet.')
    // Never the "next meeting" on Home: it has no date.
    expect(nextMeeting([postponed], now)).toBeNull()
  })

  it('reads date_to_be_set: "New date to be set" where the date goes, never the day it was first called for (M98)', () => {
    // The real case: postponed on the 9th with no new date, read on the 20th. `starts_at` is still the 9th.
    const noDate = meeting({ status: 'postponed', date_to_be_set: true, starts_at: '2026-09-09T23:00:00Z' })
    expect(dateToBeSet(noDate)).toBe(true)
    expect(heroWhen(noDate, now)).toBe('New date to be set · Community Centre · also online')
    expect(whenLine(noDate, now)).toBe('New date to be set · Community Centre · also online')
    expect(heroWhen(noDate, now)).not.toContain('September')
    expect(yourStart(noDate)).toBeNull()
    expect(rsvpOpen(noDate, now)).toBe(false)
    expect(rsvpClosedLine(noDate)).toBe('This meeting is postponed. Replies open again when it has a new date.')
    // The flag decides even where the status does not say postponed: no date is no date.
    const flagged = meeting({ date_to_be_set: true })
    expect(phaseOf(flagged, now)).toBe('postponed')
    expect(rsvpOpen(flagged, now)).toBe(false)
    expect(nextMeeting([flagged], now)).toBeNull()
  })

  it('a meeting postponed TO a new date arrives scheduled at it, and shows that date (the console\'s postponeTo)', () => {
    const moved = meeting({ starts_at: '2026-10-04T15:00:00Z', date_to_be_set: false })
    expect(dateToBeSet(moved)).toBe(false)
    expect(heroWhen(moved, now)).toBe('4 Oct 2026, 10:00 AM · Community Centre · also online')
    expect(rsvpOpen(moved, now)).toBe(true)
    expect(nextMeeting([moved], now)?.id).toBe(12)
  })

  it('a meeting saved on this phone before the server sent the flag answers by its status', () => {
    expect(dateToBeSet({ status: 'postponed' })).toBe(true)
    expect(dateToBeSet({ status: 'scheduled' })).toBe(false)
    expect(dateToBeSet({ status: 'cancelled', date_to_be_set: null })).toBe(false)
    // The server's word wins over the status when it gives one.
    expect(dateToBeSet({ status: 'postponed', date_to_be_set: false })).toBe(false)
  })

  it('a cancelled meeting says the office\'s reason when it gave one', () => {
    const cancelled = { ...meeting({ status: 'cancelled' }), reason: 'Called off by the committee.' } as unknown as Meeting
    expect(standing(cancelled, now).line).toBe('The estate office cancelled this meeting: Called off by the committee.')
    expect(standing(meeting({ status: 'cancelled' }), now).line).toBe('The estate office cancelled this meeting.')
  })
})

describe('what, when, where', () => {
  it('names the type in words, and shows an unknown one as written', () => {
    expect(typeLabel('agm')).toBe('ANNUAL GENERAL MEETING')
    expect(typeLabel('egm')).toBe('EXTRAORDINARY GENERAL MEETING')
    expect(typeLabel('phase')).toBe('PHASE MEETING')
    expect(typeLabel('town_hall')).toBe('TOWN HALL MEETING')
  })

  it('writes the day on the Kingston calendar, in the one form (M98: the consoles\' "9 Oct 2026")', () => {
    expect(dayLine('2026-09-27T15:00:00+00:00')).toBe('27 Sep 2026')
    // 02:00 UTC on the 28th is still 9 PM on the 27th in Kingston.
    expect(dayLine('2026-09-28T02:00:00+00:00')).toBe('27 Sep 2026')
    expect(dayLine('2027-01-09T15:00:00+00:00', at('2026-09-04T14:41:00Z'))).toBe('9 Jan 2027')
  })

  it('says where without ever printing the link', () => {
    expect(whereOf({ venue: 'Community Centre', virtual_link: 'https://meet.example.org/x' })).toBe('Community Centre · also online')
    expect(whereOf({ venue: 'Community Centre', virtual_link: null })).toBe('Community Centre')
    expect(whereOf({ venue: null, virtual_link: 'https://meet.example.org/x' })).toBe('Online')
    expect(whereOf({ venue: ' ', virtual_link: null })).toBe('Venue to be confirmed')
    expect(whenLine(meeting({ virtual_link: null }))).toBe('27 Sep 2026, 10:00 AM · Community Centre')
  })

  it('keeps an agenda time as the wall-clock time the server stored', () => {
    expect(agendaTime('10:00:00')).toBe('10:00')
    expect(agendaTime('9:05')).toBe('09:05')
    expect(agendaTime(null)).toBeNull()
    expect(agendaTime('after lunch')).toBeNull()
  })
})

describe('which meeting', () => {
  const items = [
    meeting({ id: 1, starts_at: '2026-03-01T15:00:00+00:00', status: 'held' }),
    meeting({ id: 2, starts_at: '2026-10-20T15:00:00+00:00' }),
    meeting({ id: 3, starts_at: '2026-09-27T15:00:00+00:00' }),
    meeting({ id: 4, starts_at: '2026-09-10T15:00:00+00:00', status: 'cancelled' }),
  ]

  it('`next` is the earliest scheduled meeting still to come', () => {
    expect(nextMeeting(items, at('2026-09-04T14:41:00Z'))?.id).toBe(3)
    expect(findMeeting('next', items, at('2026-09-04T14:41:00Z'))?.id).toBe(3)
  })

  it('a meeting under way is still the next meeting; one long past is not', () => {
    expect(nextMeeting(items, at('2026-09-27T16:00:00Z'))?.id).toBe(3)
    expect(nextMeeting(items, at('2026-09-28T16:00:00Z'))?.id).toBe(2)
  })

  it('finds by the server’s id, and returns nothing for an id it does not hold', () => {
    expect(findMeeting('2', items, at('2026-09-04T14:41:00Z'))?.id).toBe(2)
    expect(findMeeting('99', items, at('2026-09-04T14:41:00Z'))).toBeNull()
    expect(findMeeting(undefined, items, at('2026-09-04T14:41:00Z'))).toBeNull()
    expect(findMeeting('next', [items[0]!, items[3]!], at('2026-09-04T14:41:00Z'))).toBeNull()
  })
})

describe('where a meeting stands', () => {
  it('moves from upcoming to soon to underway to overdue with the clock', () => {
    const m = meeting()
    expect(phaseOf(m, at('2026-09-27T14:00:00Z'))).toBe('upcoming')
    expect(phaseOf(m, at('2026-09-27T14:35:00Z'))).toBe('soon')
    expect(phaseOf(m, at('2026-09-27T15:20:00Z'))).toBe('underway')
    expect(phaseOf(m, at('2026-09-27T22:00:00Z'))).toBe('overdue')
  })

  it('the office’s word wins over the clock', () => {
    expect(phaseOf(meeting({ status: 'held' }), at('2026-09-27T14:35:00Z'))).toBe('held')
    expect(phaseOf(meeting({ status: 'cancelled' }), at('2026-09-27T14:35:00Z'))).toBe('cancelled')
  })

  it('says it in plain words', () => {
    expect(standing(meeting(), at('2026-09-27T14:35:00Z')).label).toBe('STARTS IN 25 MINUTES')
    expect(standing(meeting(), at('2026-09-27T15:20:00Z')).line).toBe('Started 20 minutes ago, at 10:00 AM.')
    // "You can join now" is a promise about the LINK, not the clock: it is said only when the link will open.
    expect(standing(meeting(), at('2026-09-27T14:35:00Z')).line).toBe('Starts at 10:00 AM.')
    expect(standing(meeting(), at('2026-09-27T14:35:00Z'), true).line).toBe('Starts at 10:00 AM. You can join now and wait for the chair.')
    expect(standing(meeting(), at('2026-09-04T14:41:00Z')).line).toBe('Starts 27 Sep 2026, 10:00 AM.')
    expect(statusShort(meeting(), at('2026-09-04T14:41:00Z'))).toBe('27 Sep 2026, 10:00 AM')
    expect(statusShort(meeting(), at('2026-09-27T14:35:00Z'))).toBe('Today, 10:00 AM')
    expect(statusShort(meeting({ status: 'held' }), at('2026-09-28T14:41:00Z'))).toBe('Ended')
  })
})

describe('the link', () => {
  it('opens only a plain https address', () => {
    expect(safeLink('https://zoom.us/j/123?pwd=abc')).toBe('https://zoom.us/j/123?pwd=abc')
    expect(safeLink('  https://meet.google.com/abc-defg-hij ')).toBe('https://meet.google.com/abc-defg-hij')
    for (const bad of ['http://zoom.us/j/1', 'zoommtg://zoom.us/join', 'javascript:alert(1)', 'tel:+18765550100', 'intent://x#Intent;end', 'https://zoom.us@evil.example/j/1', 'https://zoom .us', 'https://localhost', '', null, undefined]) {
      expect(safeLink(bad)).toBeNull()
    }
  })

  it('names the site the link leads to', () => {
    expect(linkHost('https://www.Zoom.us/j/123')).toBe('zoom.us')
    expect(linkHost('http://zoom.us')).toBeNull()
  })

  it('is open from half an hour before the start, while scheduled', () => {
    expect(joinStateOf(meeting(), at('2026-09-27T14:35:00Z'))).toEqual({ canJoin: true, url: 'https://meet.example.org/agm-2026', host: 'meet.example.org', reason: null })
    expect(joinStateOf(meeting(), at('2026-09-27T15:20:00Z')).canJoin).toBe(true)
  })

  it('says when it opens, before that', () => {
    const state = joinStateOf(meeting(), at('2026-09-04T14:41:00Z'))
    expect(state.canJoin).toBe(false)
    expect(state.reason).toContain('27 Sep 2026, 9:30 AM')
  })

  it('is never open for a held, cancelled or long-overdue meeting, or without a secure link', () => {
    const near = at('2026-09-27T14:35:00Z')
    expect(joinStateOf(meeting({ status: 'held' }), near).canJoin).toBe(false)
    expect(joinStateOf(meeting({ status: 'cancelled' }), near).canJoin).toBe(false)
    expect(joinStateOf(meeting(), at('2026-09-28T14:35:00Z')).canJoin).toBe(false)
    expect(joinStateOf(meeting({ virtual_link: 'http://meet.example.org/x' }), near).reason).toContain('not a secure')
    expect(joinStateOf(meeting({ virtual_link: null }), near).reason).toContain('in person at Community Centre')
    expect(joinStateOf(meeting({ virtual_link: null, venue: null }), near).reason).toContain('has not added an online link')
  })
})

describe('recording and minutes', () => {
  it('announces a recording before joining, and only when there is one', () => {
    expect(recordingLine(meeting({ recording_enabled: true }))).toContain('will be recorded. If you join')
    expect(recordingLine(meeting({ recording_enabled: true, virtual_link: null }))).toBe('This meeting will be recorded. If you attend, what you say may be in the recording.')
    expect(recordingLine(meeting({ recording_enabled: true, status: 'held' }))).toBe('This meeting was recorded.')
    expect(recordingLine(meeting())).toBeNull()
    expect(recordingLine(meeting({ recording_enabled: true, status: 'cancelled' }))).toBeNull()
  })

  it('says what joining really is, never an in-app room', () => {
    expect(roomNote(meeting()).title).toBe('The meeting opens outside GeminiSecure')
    expect(roomNote(meeting()).body).toContain('in person at Community Centre')
    // A link the app will never open is not promised as one that opens.
    expect(roomNote(meeting({ virtual_link: 'http://meet.example.org/x' })).title).toBe('The online link cannot be opened')
    expect(roomNote(meeting({ virtual_link: 'http://meet.example.org/x' })).body).toContain('still attend in person at Community Centre')
    expect(roomNote(meeting({ virtual_link: 'zoommtg://zoom.us/join', venue: null })).body).not.toContain('in person')
    expect(roomNote(meeting({ virtual_link: null })).title).toBe('This meeting is held in person')
    expect(roomNote(meeting({ virtual_link: null, venue: null })).title).toBe('No online link yet')
    expect(roomNote(meeting({ status: 'held' })).title).toBe('This meeting has ended')
    expect(roomNote(meeting({ status: 'cancelled' })).title).toBe('This meeting was cancelled')
  })

  it('an empty agenda is "not yet" only while the meeting is still to come', () => {
    expect(noAgendaLine(meeting())).toBe('The agenda has not been published yet.')
    expect(noAgendaLine(meeting({ status: 'held' }))).toBe('No agenda was published for this meeting.')
    expect(noAgendaLine(meeting({ status: 'cancelled' }))).toBe('No agenda was published for this meeting.')
  })

  it('says "no connection" only when there is none: a server error is not the resident’s signal', () => {
    expect(staleLine(false, 'The time or the link')).toBe('No connection: this is what was saved on this phone. The time or the link may have changed since.')
    expect(staleLine(true, 'The time or the link')).toBe('This could not be checked with the estate just now: this is what was saved on this phone. The time or the link may have changed since.')
  })

  it('says published minutes are there to be read: no longer "ask the office" (M21 §1.4)', () => {
    expect(minutesLine(meeting({ minutes_available: true }))).toContain('published')
    expect(minutesLine(meeting({ minutes_available: true }))).not.toContain('estate office')
    expect(minutesLine(meeting())).toBeNull()
  })

  it('draws the minutes as the office published them, with when and by whom on the estate’s clock', () => {
    const v = minutesView({ body: '  1. The barrier quotation was approved.\n2. Pool hours unchanged.  ', recorded_by: 'Kayla Morrison', published_at: '2026-09-28T15:00:00Z' }, false)
    expect(v.body).toBe('1. The barrier quotation was approved.\n2. Pool hours unchanged.')
    expect(v.meta).toBe('Published 28 Sep 2026, 10:00 AM · recorded by Kayla Morrison')
    expect(v.note).toBeNull()
    // Not in yet, or not to be had: said, never a blank card.
    expect(minutesView(undefined, false)).toMatchObject({ body: null, note: 'Loading the minutes…' })
    expect(minutesView(undefined, true).note).toContain('could not be loaded')
  })
})

describe('the household’s reply', () => {
  it('closes when the meeting starts, is held or is cancelled: as the server does', () => {
    expect(rsvpOpen(meeting(), at('2026-09-27T14:59:00Z'))).toBe(true)
    expect(rsvpOpen(meeting(), at('2026-09-27T15:00:00Z'))).toBe(false)
    expect(rsvpOpen(meeting({ status: 'cancelled' }), at('2026-09-04T14:41:00Z'))).toBe(false)
  })

  it('with nothing queued, the server’s word stands alone', () => {
    expect(rsvpStateOf(meeting({ my_rsvp: 'apologies' }), [], null)).toEqual({ answer: 'apologies', sync: null, tried: null, message: null, attending: null })
  })

  it('a queued reply is shown as chosen but NOT confirmed', () => {
    const state = rsvpStateOf(meeting(), [op()], null)
    expect(state).toMatchObject({ answer: 'attending', sync: 'pending' })
    expect(rsvpNote(state)).toContain('Saved on this phone')
    expect(rsvpStateOf(meeting(), [op({ state: 'syncing' })], null).sync).toBe('syncing')
  })

  it('the latest reply is the household’s word; another meeting’s reply is not this one’s', () => {
    const ops = [op({ id: 'a', state: 'confirmed', result: { response: 'attending', households_attending: 38 } }), op({ id: 'b', body: { response: 'apologies' } }), op({ id: 'c', params: { meeting: 13 }, body: { response: 'not_attending' } })]
    expect(rsvpStateOf(meeting(), ops, null)).toMatchObject({ answer: 'apologies', sync: 'pending' })
  })

  it('a refused reply is never the household’s answer: the earlier one stands, with the server’s sentence', () => {
    const state = rsvpStateOf(meeting({ my_rsvp: 'apologies' }), [op({ state: 'refused', error: { message: 'This meeting has already started or is no longer scheduled.' } })], null)
    expect(state).toMatchObject({ answer: 'apologies', sync: 'refused', tried: 'attending' })
    expect(rsvpNote(state)).toBe('This meeting has already started or is no longer scheduled. Your earlier reply still stands: your household sent apologies.')
  })

  it('a confirmed reply carries the server’s count of households attending', () => {
    const confirmed = op({ state: 'confirmed', lastTriedAt: '2026-09-04T09:41:00-05:00', result: { response: 'attending', households_attending: 38 } })
    expect(rsvpStateOf(meeting(), [confirmed], null)).toMatchObject({ answer: 'attending', sync: 'confirmed', attending: 38 })
  })

  it('a list fetched after the answer is the newer word', () => {
    const confirmed = op({ state: 'confirmed', lastTriedAt: '2026-09-04T09:41:00-05:00', result: { response: 'attending', households_attending: 38 } })
    const later = new Date('2026-09-04T10:00:00-05:00').getTime()
    expect(rsvpStateOf(meeting({ my_rsvp: 'not_attending' }), [confirmed], later)).toMatchObject({ answer: 'not_attending', sync: null, attending: null })
    expect(rsvpStateOf(meeting({ my_rsvp: 'attending' }), [confirmed], later)).toMatchObject({ answer: 'attending', sync: 'confirmed' })
  })

  it('a list asked for BEFORE the reply was tried cannot know of it: the old reply is never put back on screen', () => {
    const confirmed = op({ state: 'confirmed', lastTriedAt: '2026-09-04T09:41:00-05:00', result: { response: 'apologies', households_attending: 37 }, body: { response: 'apologies' } })
    const earlier = new Date('2026-09-04T09:40:59-05:00').getTime()
    // A pull-to-refresh racing the queue: the list was requested first, and still says `attending`.
    expect(rsvpStateOf(meeting({ my_rsvp: 'attending' }), [confirmed], earlier)).toMatchObject({ answer: 'apologies', sync: 'confirmed', attending: 37 })
    // The phone's saved copy (never requested in this session) does not outrank the server's answer either.
    expect(rsvpStateOf(meeting({ my_rsvp: 'attending' }), [confirmed], null)).toMatchObject({ answer: 'apologies', sync: 'confirmed' })
  })

  it('a second tap on the standing answer is not a second act; a refused one may be tried again', () => {
    expect(shouldQueueRsvp('attending', rsvpStateOf(meeting(), [op()], null))).toBe(false)
    expect(shouldQueueRsvp('apologies', rsvpStateOf(meeting(), [op()], null))).toBe(true)
    expect(shouldQueueRsvp('attending', rsvpStateOf(meeting({ my_rsvp: 'attending' }), [], null))).toBe(false)
    expect(shouldQueueRsvp('attending', rsvpStateOf(meeting(), [op({ state: 'refused', error: { message: 'No.' } })], null))).toBe(true)
  })

  it('counts RSVPs in the server’s number and never calls them a quorum', () => {
    expect(attendingLine(38)).toBe('38 households have said they will attend so far. This is not the quorum: that is counted at the meeting.')
    expect(attendingLine(1)).toContain('1 household has')
    expect(attendingLine(null)).toBeNull()
  })
})

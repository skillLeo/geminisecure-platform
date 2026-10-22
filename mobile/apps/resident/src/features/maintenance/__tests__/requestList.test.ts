/**
 * "All requests": everything the household has sent the estate office, newest first — maintenance requests AND
 * security reports, each told apart the way the rest of the app tells them apart, each opening the screen that
 * already shows it, each with its status in its own screen's words. What is still on this phone says so and
 * opens nothing.
 */
import { STATUS_LABEL as REPORT_SCREEN_LABEL, INCIDENT_KIND } from '../../incidents/incidents'
import { ALL, END_OF_LIST, NEEDS_CONFIRMATION, REPORT_KIND, REPORT_STATUS_LABEL, REQUEST_KIND, allRows, applyFilter, dayOf, filtersFor, homeSummary, isOpenRow, kindOfTicket, olderBlocked, pathOf, sectionsOf, statusWords, tabRows, tabEntry, updatedAt, type ListOp } from '../requestList'
import type { Ticket } from '../tickets'

const AT = new Date('2026-09-04T09:41:00-05:00')

const REPORT_BLOCK = 'Two men trying car doors on Waterloo Close.\n\nReport details:\n- Type: Suspicious activity\n- Written on this phone: Sep 3, 2026, 9:12 PM'

const ticket = (over: Partial<Ticket>): Ticket => ({
  id: 1042, number: '1042', title: 'Gate lighting', category: 'electrical', location: 'Lot 47', description: null, access_permitted: null, preferred_time: null, priority: 'high', status: 'submitted',
  reported_at: '2026-09-02T18:40:00-05:00', technician_name: null, eta_starts_at: null, eta_ends_at: null, resolution: null, closed_at: null, media_count: 0,
  timeline: [{ event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T18:40:00-05:00' }],
  ...over,
})

const op = (over: Partial<ListOp>): ListOp => ({ id: 'op-1', kind: REQUEST_KIND, endpoint: 'tickets.store', state: 'pending', capturedAt: '2026-09-04T09:00:00-05:00', after: null, body: { title: 'Kitchen tap is leaking' }, error: null, result: null, ...over })

describe('two kinds of thing, told apart the way the rest of the app tells them apart', () => {
  it('a ticket is a security report by the MARKER its description carries, never by its category', () => {
    expect(kindOfTicket(ticket({ description: REPORT_BLOCK }))).toBe('report')
    expect(kindOfTicket(ticket({ category: 'security', description: 'The side gate does not latch.' }))).toBe('request')
    expect(kindOfTicket(ticket({ description: null }))).toBe('request')
  })

  it('each opens the screen that already shows it', () => {
    expect(pathOf('request', 1042)).toBe('/maintenance/1042')
    expect(pathOf('report', '77')).toBe('/incident/77')
    const rows = allRows({ items: [ticket({}), ticket({ id: 77, number: '1077', title: 'Suspicious activity', description: REPORT_BLOCK })], ops: [], at: AT })
    expect(rows.map((row) => [row.kind, row.path])).toEqual([['request', '/maintenance/1042'], ['report', '/incident/77']])
  })

  it('an act still on this phone is of the kind of the screen that queued it: the outbox\'s own word', () => {
    expect(REPORT_KIND).toBe(INCIDENT_KIND) // copied from features/incidents, not imported by the app code: held equal here
    const rows = allRows({ items: [], ops: [op({ id: 'a' }), op({ id: 'b', kind: REPORT_KIND, body: { title: 'Loud argument next door' } })], at: AT })
    expect(rows.map((row) => [row.kind, row.kindLabel])).toEqual(expect.arrayContaining([['request', 'Maintenance'], ['report', 'Security report']]))
    // Another feature's acts (a visitor pass, a notice read) are never listed here.
    expect(allRows({ items: [], ops: [op({ kind: 'pass' }), op({ kind: 'ticket', endpoint: 'tickets.media' })], at: AT })).toEqual([])
  })
})

describe('a status in words: the same words the screen it opens uses', () => {
  it('a request speaks of work; a report speaks of being looked into', () => {
    expect(statusWords('request', 'assigned')).toBe('Assigned')
    expect(statusWords('request', 'in_progress')).toBe('In progress')
    expect(statusWords('report', 'acknowledged')).toBe('Received')
    expect(statusWords('report', 'in_progress')).toBe('Being looked into')
    expect(statusWords('report', 'cancelled')).toBe('Closed')
  })

  it('the report words are the Incident Status screen\'s own, word for word', () => {
    expect(REPORT_STATUS_LABEL).toEqual(REPORT_SCREEN_LABEL)
  })

  it('draws the chip and the line: number, day, category', () => {
    const [row] = allRows({ items: [ticket({ status: 'in_progress' })], ops: [], at: AT })
    expect(row).toMatchObject({ title: 'Gate lighting', detail: '#1042 · 2 Sep 2026 · Electrical', chip: { label: 'In progress', tone: 'amber' }, queue: null })
    expect(row!.label).toBe('Maintenance: Gate lighting. In progress. #1042 · 2 Sep 2026 · Electrical. Open it')
  })

  it('names the year every time (M98\'s one form): a list of everything goes back years', () => {
    expect(dayOf('2026-09-02T18:40:00-05:00', AT)).toBe('2 Sep 2026')
    expect(dayOf('2024-12-31T23:30:00-05:00', AT)).toBe('31 Dec 2024')
    // 03:30 UTC on Jan 1 is still Dec 31 in Kingston.
    expect(dayOf('2025-01-01T03:30:00Z', AT)).toBe('31 Dec 2024')
  })

  it('a request\'s state in the consoles\' words (M98): Under review and Closed, never Acknowledged or Verified', () => {
    const [review] = allRows({ items: [ticket({ status: 'acknowledged' })], ops: [], at: AT })
    const [closed] = allRows({ items: [ticket({ status: 'verified' })], ops: [], at: AT })
    expect(review!.chip.label).toBe('Under review')
    expect(closed!.chip.label).toBe('Closed')
  })
})

describe('newest first, nothing twice, nothing lost between "sent" and "listed"', () => {
  it('what is still on this phone heads the list, says so, and opens nothing', () => {
    const rows = allRows({ items: [ticket({})], ops: [op({}), op({ id: 'op-2', state: 'refused', capturedAt: '2026-09-04T08:00:00-05:00', error: { message: 'The title is too short.' } })], at: AT })
    expect(rows.map((row) => row.key)).toEqual(['q:op-1', 'q:op-2', 't:1042'])
    expect(rows[0]).toMatchObject({ chip: { label: 'Pending sync', tone: 'amber' }, path: null, detail: 'Saved on this phone 4 Sep 2026, 9:00 AM, not sent yet' })
    expect(rows[1]).toMatchObject({ chip: { label: 'Not accepted', tone: 'red' }, path: null, detail: 'The title is too short.' })
    expect(rows[0]!.label).not.toMatch(/Open it/)
  })

  it('a request the server has taken is drawn from its own answer until the list carries it, then once, from the list', () => {
    const answer = ticket({ id: 2001, number: '2001', title: 'Kitchen tap is leaking', reported_at: '2026-09-04T09:00:05-05:00' })
    const confirmed = op({ state: 'confirmed', result: answer })
    expect(allRows({ items: [ticket({})], ops: [confirmed], at: AT }).map((row) => row.key)).toEqual(['t:2001', 't:1042'])
    expect(allRows({ items: [answer, ticket({})], ops: [confirmed], at: AT }).map((row) => row.key)).toEqual(['t:2001', 't:1042'])
  })

  it('an answer to a REPORT is a report, whatever it reads like', () => {
    const answer = ticket({ id: 2002, number: '2002', title: 'Loud argument', description: 'no marker here' })
    expect(allRows({ items: [], ops: [op({ kind: REPORT_KIND, state: 'confirmed', result: answer })], at: AT })[0]).toMatchObject({ kind: 'report', path: '/incident/2002' })
  })

  it('orders by when the office received it, and keeps one order for two received in the same second', () => {
    const same = [ticket({ id: 5, number: '5' }), ticket({ id: 6, number: '6' })]
    expect(allRows({ items: same, ops: [], at: AT }).map((row) => row.key)).toEqual(allRows({ items: [...same].reverse(), ops: [], at: AT }).map((row) => row.key))
    const spread = [ticket({ id: 1, number: '1', reported_at: '2025-03-01T10:00:00-05:00' }), ticket({ id: 2, number: '2', reported_at: '2026-09-03T10:00:00-05:00' })]
    expect(allRows({ items: spread, ops: [], at: AT }).map((row) => row.key)).toEqual(['t:2', 't:1'])
  })
})

describe('the chips', () => {
  const mixed = allRows({
    items: [ticket({}), ticket({ id: 2, number: '2', status: 'verified' }), ticket({ id: 77, number: '1077', description: REPORT_BLOCK, status: 'completed' })],
    ops: [op({ state: 'refused', error: { message: 'No.' } })],
    at: AT,
  })

  it('only what has something behind it: the two kinds only when the list holds both; "Still open" only when it cuts the list', () => {
    expect(filtersFor(mixed).map((chip) => chip.label)).toEqual(['All', 'Still open', 'Maintenance', 'Security reports'])
    expect(filtersFor(allRows({ items: [ticket({})], ops: [], at: AT }))).toEqual([ALL])
    expect(filtersFor([])).toEqual([ALL])
  })

  it('"Still open" is what the office has not finished: a refusal was never filed, so it is not open', () => {
    expect(mixed.map(isOpenRow)).toEqual([false, true, false, false])
    expect(isOpenRow(allRows({ items: [], ops: [op({})], at: AT })[0]!)).toBe(true)
    expect(applyFilter(mixed, 'open').map((row) => row.key)).toEqual(['t:1042'])
    expect(applyFilter(mixed, 'report').map((row) => row.key)).toEqual(['t:77'])
    expect(applyFilter(mixed, 'all')).toHaveLength(4)
  })
})

describe('older requests', () => {
  it('are fetched from the estate, so with no connection the button says why it cannot be pressed', () => {
    expect(olderBlocked(true)).toBeNull()
    expect(olderBlocked(false)).toMatch(/need a connection/)
    expect(END_OF_LIST).toMatch(/everything your household has sent/)
  })
})

/*
 * THE HUB, "My requests" (M98, Hassam: "I am unable to track that ... there must be options to view the history or
 * list"): two tabs of one kind each, open first and past after, newest first; the one job waiting on the household
 * stands out; Home's card says what is open, and is not drawn for a household that never filed anything.
 */
describe('My requests: the tabs, the open and the past, and Home\'s card', () => {
  const REPORT = (over: Partial<Ticket>) => ticket({ description: REPORT_BLOCK, ...over })
  const items = [
    ticket({ id: 5, number: '1055', title: 'Leaking tap', status: 'in_progress', reported_at: '2026-09-04T08:00:00-05:00' }),
    ticket({ id: 4, number: '1054', title: 'Gate lamp', status: 'completed', reported_at: '2026-09-03T08:00:00-05:00' }),
    ticket({ id: 3, number: '1053', title: 'Old fence', status: 'verified', reported_at: '2026-08-20T08:00:00-05:00' }),
    REPORT({ id: 9, number: '1059', title: 'Suspicious car', status: 'acknowledged', reported_at: '2026-09-04T07:00:00-05:00' }),
    REPORT({ id: 8, number: '1058', title: 'Noise', status: 'completed', reported_at: '2026-08-30T07:00:00-05:00' }),
  ]
  const rows = allRows({ items, ops: [], at: AT })

  it('a tab holds one kind; open comes first, then past, each newest first', () => {
    const maintenance = sectionsOf(tabRows(rows, 'request'))
    expect(maintenance.map((s) => [s.key, s.title, s.rows.map((r) => r.title)])).toEqual([
      ['open', 'Open · 2', ['Leaking tap', 'Gate lamp']],
      ['past', 'Past', ['Old fence']],
    ])
    const incidents = sectionsOf(tabRows(rows, 'report'))
    expect(incidents.map((s) => [s.key, s.rows.map((r) => r.title)])).toEqual([['open', ['Suspicious car']], ['past', ['Noise']]])
  })

  it('a completed job the household has not answered says so, in amber, and stays OPEN; a completed report does not need an answer', () => {
    const lamp = rows.find((r) => r.title === 'Gate lamp')!
    expect(lamp).toMatchObject({ chip: { label: NEEDS_CONFIRMATION, tone: 'amber' }, needsConfirmation: true })
    expect(isOpenRow(lamp)).toBe(true)
    const noise = rows.find((r) => r.title === 'Noise')!
    expect(noise.needsConfirmation).toBe(false)
    expect(isOpenRow(noise)).toBe(false)
  })

  it('"Updated" is the newest thing the office recorded, else the day it was reported', () => {
    expect(updatedAt(ticket({}))).toBe('2026-09-02T18:40:00-05:00')
    const moved = ticket({ timeline: [{ event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T18:40:00-05:00' }, { event: 'assigned', status: 'assigned', note: null, occurred_at: '2026-09-03T10:00:00-05:00' }] })
    expect(updatedAt(moved)).toBe('2026-09-03T10:00:00-05:00')
  })

  it('Home\'s card: how many are open and the newest one; nothing at all for a household that never filed', () => {
    expect(homeSummary(rows)).toEqual({ line: '3 open · #1055 In progress', open: 3, waitingOnYou: true })
    expect(homeSummary([])).toBeNull()
    const none = allRows({ items: [ticket({ status: 'verified' })], ops: [], at: AT })
    expect(homeSummary(none)).toMatchObject({ line: '1 request filed, none open', open: 0 })
  })
})

describe('the server\'s own status word, when it sends one', () => {
  it('is shown as sent, and the app\'s words stand in when it is absent or blank', () => {
    const withLabel = ticket({ status: 'in_progress', ...({ status_label: 'Work started' } as object) })
    expect(allRows({ items: [withLabel], ops: [], at: AT })[0]!.chip.label).toBe('Work started')
    expect(allRows({ items: [ticket({ status: 'in_progress', ...({ status_label: '  ' } as object) })], ops: [], at: AT })[0]!.chip.label).toBe('In progress')
    expect(allRows({ items: [ticket({ status: 'in_progress' })], ops: [], at: AT })[0]!.chip.label).toBe('In progress')
  })
})

/*
 * HOME'S TWO QUICK ACTIONS OPEN THE LIST FIRST (Hassam, 12 Oct): "before the form appears we first show the list, and by
 * clicking a plus button user be able to see the form."
 */
describe('Maintenance and Report incident open My requests on their own tab, and its plus opens the form', () => {
  it('each tab: where Home sends you, what the plus says, and which form it opens', () => {
    expect(tabEntry('request')).toEqual({ list: '/requests?tab=request', form: '/maintenance/new', plus: 'New maintenance request' })
    expect(tabEntry('report')).toEqual({ list: '/requests?tab=report', form: '/incident/new', plus: 'Report an incident' })
  })
})

/**
 * Maintenance requests — only what the server takes is sent; the board's four chips are filed under the
 * server's categories and the title the server requires is written from the resident's words and the place; entry
 * goes as a NAMED FIELD and a request filed before those columns existed is still read out of its
 * description; the server's timeline sits on the board's six stages; a request the phone has not sent
 * is never called submitted.
 */
import {
  CATEGORIES,
  COMMON_AREA,
  DESCRIPTION_MAX,
  EMPTY_REQUEST,
  INCIDENT_MARKER,
  REQUEST_CHIPS,
  TITLE_MAX,
  TITLE_MIN,
  isIncidentReport,
  requestsOnly,
  SERVER_DESCRIPTION_MAX,
  accessLine,
  appendTranscript,
  categoryLabel,
  etaWindow,
  findTicket,
  initialsOf,
  mediaBody,
  metaLine,
  photosRefused,
  photosWaiting,
  prefillFrom,
  readNotes,
  recentRows,
  refusalToClear,
  requestSchema,
  requestTitle,
  stageRows,
  ticketBody,
  titleFrom,
  visitOf,
  type QueuedOp,
  type RequestForm,
  type Ticket,
} from '../tickets'

const at = new Date('2026-09-04T09:41:00-05:00')
const UNIT = 'Phase 2 · Lot 47'

// A kind and a description by default: both required since M18C ("nothing is optional"). Their own cases are below.
const form = (over: Partial<RequestForm>): RequestForm => ({ ...EMPTY_REQUEST, kind: 'plumbing', description: 'Drips all night.', ...over })

const ticket = (over: Partial<Ticket>): Ticket => ({
  id: 1042, number: '1042', title: 'Gate lighting', category: 'electrical', location: 'Lot 47', description: null, access_permitted: null, preferred_time: null, priority: 'high', status: 'submitted',
  reported_at: '2026-09-02T18:40:00-05:00', technician_name: null, eta_starts_at: null, eta_ends_at: null, resolution: null, closed_at: null, media_count: 0,
  timeline: [{ event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T18:40:00-05:00' }],
  ...over,
})

const op = (over: Partial<QueuedOp>): QueuedOp => ({ id: 'op-1', endpoint: 'tickets.store', state: 'pending', capturedAt: '2026-09-04T09:00:00-05:00', after: null, body: { title: 'Kitchen tap is leaking' }, error: null, result: null, ...over })

describe('the form asks for what the server needs, and nothing the design took away', () => {
  const messages = (values: RequestForm) => {
    const parsed = requestSchema.safeParse(values)
    return parsed.success ? {} : Object.fromEntries(parsed.error.issues.map((issue) => [issue.path.join('.'), issue.message]))
  }

  it('needs one of the chips and a description (M18C), and no title, place or best time', () => {
    expect(messages(form({}))).toEqual({})
    expect(messages(form({ kind: '', description: '' }))).toEqual({
      kind: 'Choose what kind of problem it is.',
      description: 'Say what is wrong: a sentence is enough, or tap the microphone and say it.',
    })
    expect(Object.keys(EMPTY_REQUEST).sort()).toEqual(['allowEntry', 'area', 'description', 'kind', 'priority'])
  })

  it('the chips are the board’s four (M42 §5 #10), and a server category is not a chip', () => {
    expect(REQUEST_CHIPS.map((chip) => chip.label)).toEqual(['Plumbing', 'Electrical', 'Lighting', 'Gate'])
    for (const chip of REQUEST_CHIPS) expect(messages(form({ kind: chip.id }))).toEqual({})
    expect(Object.keys(messages(form({ kind: 'waste' })))).toEqual(['kind'])
  })

  it('a common-area problem needs nothing more: the design has no place field', () => {
    expect(messages(form({ area: 'common' }))).toEqual({})
  })

  it('keeps what the resident types inside the server’s 2000 characters', () => {
    expect(Object.keys(messages(form({ description: 'x'.repeat(DESCRIPTION_MAX + 1) })))).toEqual(['description'])
    expect(DESCRIPTION_MAX).toBeLessThanOrEqual(SERVER_DESCRIPTION_MAX)
    // Nothing is appended to it, so the longest description is sent whole.
    expect(ticketBody(form({ description: 'x'.repeat(DESCRIPTION_MAX), allowEntry: true }), UNIT).description).toBe('x'.repeat(DESCRIPTION_MAX))
  })
})

describe('each chip is filed under one of the server’s categories', () => {
  it('Lighting is electrical work and the gate is the estate’s security equipment: the server has neither category', () => {
    expect(REQUEST_CHIPS.map((chip) => [chip.label, chip.category])).toEqual([['Plumbing', 'plumbing'], ['Electrical', 'electrical'], ['Lighting', 'electrical'], ['Gate', 'security']])
    for (const chip of REQUEST_CHIPS) expect(CATEGORIES.map((cat) => cat.value)).toContain(chip.category)
    expect(ticketBody(form({ kind: 'lighting' }), UNIT).category).toBe('electrical')
    expect(ticketBody(form({ kind: 'gate' }), UNIT).category).toBe('security')
  })

  it('a gate request filed under "security" is still a maintenance request, never a security report', () => {
    const body = ticketBody(form({ kind: 'gate', description: 'The gate arm sticks open.' }), UNIT)
    expect(isIncidentReport({ description: body.description })).toBe(false)
    expect(requestsOnly([{ id: 1, ...body }])).toHaveLength(1)
  })
})

describe('the title the server requires (4–140): what is wrong, then where (M47 §11.5)', () => {
  it('the resident’s first words, a dash, and the place: the unit, or "Common area"', () => {
    expect(ticketBody(form({ kind: 'lighting', description: 'Gate lighting' }), 'Phase 2 visitor parking').title).toBe('Gate lighting: Phase 2 visitor parking')
    expect(ticketBody(form({ kind: 'gate', area: 'common', description: 'The gate arm sticks open. It has done so since Monday.' }), UNIT).title).toBe('The gate arm sticks open: Common area')
    expect(ticketBody(form({ description: 'kitchen tap drips all night' }), UNIT).title).toBe('Kitchen tap drips all night: Phase 2 · Lot 47')
  })

  it('the first clause only, cut near forty characters between words and marked where it was cut', () => {
    const title = titleFrom('The gate light dem nuh work since Tuesday and it dark by the visitor parking at night', 'Common area', 'Lighting')
    expect(title).toBe('The gate light dem nuh work since Tuesday…: Common area')
    expect(titleFrom('Water everywhere, the pipe burst', UNIT, 'Plumbing')).toBe('Water everywhere: Phase 2 · Lot 47')
    expect(titleFrom('Line one\nline two', null, 'Plumbing')).toBe('Line one')
  })

  it('the chip’s word when there are no words, the words alone when the session names no unit: at least four characters', () => {
    expect(titleFrom('   ', UNIT, 'Gate')).toBe('Gate: Phase 2 · Lot 47')
    expect(titleFrom('', null, 'Gate')).toBe('Gate')
    expect(ticketBody(form({ kind: 'gate', description: 'Arm stuck.' }), null).title).toBe('Arm stuck')
    expect(ticketBody(form({ kind: 'gate', description: 'Arm stuck.' }), '   ').title).toBe('Arm stuck')
    expect(titleFrom('ok', null, 'Gate')).toBe('Gate')
    for (const chip of REQUEST_CHIPS) expect(titleFrom('', null, chip.label).length).toBeGreaterThanOrEqual(TITLE_MIN)
  })

  it('spaces are made one, and a long place is cut at 140', () => {
    expect(titleFrom('  Tap   drips ', '  Phase 2   ·  Lot 47 ', 'Plumbing')).toBe('Tap drips: Phase 2 · Lot 47')
    const long = titleFrom('Sparks', 'x'.repeat(300), 'Electrical')
    expect(long.length).toBe(TITLE_MAX)
    expect(long.startsWith('Sparks: x')).toBe(true)
  })
})

describe('entry is a FIELD, and an older request’s lines are still read', () => {
  it('sent by name, never written into the description, and no "best time" any more', () => {
    const body = ticketBody(form({ description: 'Drips all night.', allowEntry: true }), UNIT)
    expect(body).toEqual({ title: 'Drips all night: Phase 2 · Lot 47', category: 'plumbing', description: 'Drips all night.', priority: 'medium', access_permitted: true })
    expect(body).not.toHaveProperty('preferred_time')
    expect(body.description).not.toContain('Notes to the office')
  })

  it('"no" is an answer and is sent as one; a common area is never asked, so it answers nothing', () => {
    expect(ticketBody(form({}), UNIT)).toEqual({ title: 'Drips all night: Phase 2 · Lot 47', category: 'plumbing', description: 'Drips all night.', priority: 'medium', access_permitted: false })
    const common = ticketBody(form({ area: 'common', description: 'Light is out.', allowEntry: true }), UNIT)
    expect(common).not.toHaveProperty('access_permitted')
    expect(common).not.toHaveProperty('preferred_time')
  })

  it('reads a request the phone filed BEFORE the columns existed, and shows the block once, not twice', () => {
    const old = ticket({ description: "Drips all night.\n\nNotes to the office:\n- Entry when I'm not home: allowed\n- Best time to come: weekday mornings" })
    const notes = readNotes(old)
    expect(notes).toEqual({ text: 'Drips all night.', allowEntry: true, bestTime: 'weekday mornings' })
    expect(accessLine(notes)).toBe('Entry with nobody home: yes · Best time: weekday mornings')
  })

  it('the columns win over anything left in the text', () => {
    const both = ticket({ description: "Drips.\n\nNotes to the office:\n- Entry when I'm not home: allowed", access_permitted: false, preferred_time: 'after 4' })
    expect(readNotes(both)).toEqual({ text: 'Drips.', allowEntry: false, bestTime: 'after 4' })
  })

  it('a request that said neither says neither: a silence is not a "no"', () => {
    const quiet = ticket({ description: 'Drips all night.' })
    expect(readNotes(quiet)).toEqual({ text: 'Drips all night.', allowEntry: null, bestTime: null })
    expect(accessLine(readNotes(quiet))).toBeNull()
  })

  it('words the office typed under its own heading are left alone, whole', () => {
    const office = ticket({ description: 'Notes to the office: rang the household twice.' })
    expect(readNotes(office).text).toBe('Notes to the office: rang the household twice.')
    expect(readNotes(office).allowEntry).toBeNull()
  })
})

describe('the body is exactly what tickets.store takes', () => {
  it('inside the unit: no location (the server fills in the unit)', () => {
    expect(ticketBody(form({ description: '  Drips all night.  ' }), UNIT)).toEqual({ title: 'Drips all night: Phase 2 · Lot 47', category: 'plumbing', description: 'Drips all night.', priority: 'medium', access_permitted: false })
  })

  it('a common area is filed as one: left out, the server would file a street light against the unit', () => {
    expect(ticketBody(form({ area: 'common', kind: 'lighting', priority: 'high', description: 'Dark at night.' }), UNIT)).toEqual({
      title: 'Dark at night: Common area', category: 'electrical', description: 'Dark at night.', location: COMMON_AREA, priority: 'high',
    })
  })

  it('"Report it again" chooses the chip and the place a title of this form names, and starts the transcript with any other', () => {
    expect(prefillFrom('Lighting: Phase 2 · Lot 47')).toEqual({ kind: 'lighting' })
    expect(prefillFrom('Gate: Common area')).toEqual({ kind: 'gate', area: 'common' })
    // A request an older build filed joined them with a dash.
    expect(prefillFrom('Lighting — Phase 2 · Lot 47')).toEqual({ kind: 'lighting' })
    expect(prefillFrom('Gate — Common area')).toEqual({ kind: 'gate', area: 'common' })
    expect(prefillFrom('Gate')).toEqual({ kind: 'gate' })
    expect(prefillFrom('  Kitchen   tap is leaking ')).toEqual({ description: 'Kitchen tap is leaking' })
    expect(prefillFrom('Gate lighting: Phase 2 visitor parking')).toEqual({ description: 'Gate lighting: Phase 2 visitor parking' })
    expect(prefillFrom('   ')).toEqual({})
  })

  it('a photo is one multipart field, named by its type', () => {
    expect(mediaBody({ uri: 'file:///a/b.jpg', mime: 'image/jpeg', fileName: null }, 0)).toEqual({ file: { uri: 'file:///a/b.jpg', name: 'request-photo-1.jpg', type: 'image/jpeg' } })
    expect(mediaBody({ uri: 'file:///a/IMG.PNG?x=1', mime: null, fileName: null }, 1).file).toEqual({ uri: 'file:///a/IMG.PNG?x=1', name: 'request-photo-2.png', type: 'image/png' })
    expect(mediaBody({ uri: 'blob:abc', mime: 'application/octet-stream', fileName: null }, 2).file.type).toBe('image/jpeg')
  })

  it('dictation adds to what is written and never replaces it', () => {
    expect(appendTranscript('The tap drips. ', '  all   night ')).toBe('The tap drips. all night')
    expect(appendTranscript('', 'hello')).toBe('hello')
    expect(appendTranscript('kept', '   ')).toBe('kept')
  })
})

describe('the server’s timeline on the board’s six stages', () => {
  const inProgress = ticket({
    status: 'in_progress',
    timeline: [
      { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T18:40:00-05:00' },
      { event: 'acknowledged', status: 'acknowledged', note: null, occurred_at: '2026-09-03T08:15:00-05:00' },
      { event: 'assigned', status: 'assigned', note: null, occurred_at: '2026-09-03T11:20:00-05:00' },
      { event: 'started', status: 'in_progress', note: 'Technician on site', occurred_at: '2026-09-04T09:05:00-05:00' },
    ],
  })

  it('ticks what is behind, marks where it is, leaves the rest for later: in the site’s zone', () => {
    const { rows, updates } = stageRows(inProgress)
    expect(rows.map((row) => [row.label, row.state, row.detail])).toEqual([
      // The consoles' words (M98): Under review, not Acknowledged; Closed, not Verified. Dates in the one form.
      ['Submitted', 'done', '2 Sep 2026, 6:40 PM'],
      ['Under review', 'done', '3 Sep 2026, 8:15 AM'],
      ['Assigned to vendor', 'done', '3 Sep 2026, 11:20 AM'],
      ['In progress', 'current', '4 Sep 2026, 9:05 AM · Technician on site'],
      ['Completed', 'todo', null],
      ['Closed', 'todo', null],
    ])
    expect(updates).toEqual([])
  })

  it('a new request stands on its first stage, dated from the report even with no timeline', () => {
    const { rows } = stageRows(ticket({ timeline: [] }))
    expect(rows[0]).toEqual({ key: 'submitted', label: 'Submitted', state: 'current', detail: '2 Sep 2026, 6:40 PM' })
    expect(rows.slice(1).every((row) => row.state === 'todo')).toBe(true)
  })

  it('a verified request is all ticks', () => {
    expect(stageRows(ticket({ status: 'verified' })).rows.every((row) => row.state === 'done')).toBe(true)
  })

  it('a change of vendor or urgency fills no stage and is still shown', () => {
    const { rows, updates } = stageRows({
      ...inProgress,
      timeline: [
        ...inProgress.timeline.slice(0, 3),
        { event: 'priority_changed', status: 'assigned', note: 'Raised to high', occurred_at: '2026-09-03T12:00:00-05:00' },
        { event: 'reassigned', status: 'assigned', note: 'Reassigned from Island Electric', occurred_at: '2026-09-03T15:00:00-05:00' },
        inProgress.timeline[3]!,
      ],
    })
    // The FIRST assignment keeps the stage's time — a reassignment does not make a request more assigned.
    expect(rows[2]!.detail).toBe('3 Sep 2026, 11:20 AM')
    expect(updates.map((u) => [u.label, u.detail])).toEqual([
      ['Urgency changed', '3 Sep 2026, 12:00 PM · Raised to high'],
      ['Vendor changed', '3 Sep 2026, 3:00 PM · Reassigned from Island Electric'],
    ])
  })

  it('a reopened job is in progress again, with the reason, and the first completion stays on the record', () => {
    const { rows, updates } = stageRows({
      ...inProgress,
      timeline: [
        ...inProgress.timeline,
        { event: 'resolved', status: 'completed', note: 'Bulbs replaced', occurred_at: '2026-09-05T10:00:00-05:00' },
        { event: 'reopened', status: 'in_progress', note: 'Lights out again', occurred_at: '2026-09-07T19:30:00-05:00' },
      ],
    })
    expect(rows[3]).toMatchObject({ state: 'current', detail: '7 Sep 2026, 7:30 PM · Lights out again' })
    expect(rows[4]).toMatchObject({ label: 'Completed', state: 'todo', detail: null })
    expect(updates.map((u) => u.label)).toEqual(['Work started', 'Completed'])
  })

  it('a cancelled request stops where it was, and promises no further stages', () => {
    const { rows } = stageRows(ticket({
      status: 'cancelled', closed_at: '2026-09-03T09:00:00-05:00',
      timeline: [
        { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T18:40:00-05:00' },
        { event: 'acknowledged', status: 'acknowledged', note: null, occurred_at: '2026-09-03T08:15:00-05:00' },
        { event: 'cancelled', status: 'cancelled', note: 'Duplicate of #1040', occurred_at: '2026-09-03T09:00:00-05:00' },
      ],
    }))
    expect(rows.map((row) => [row.label, row.state])).toEqual([['Submitted', 'done'], ['Under review', 'done'], ['Cancelled', 'stopped']])
    expect(rows[2]!.detail).toBe('3 Sep 2026, 9:00 AM · Duplicate of #1040')
  })
})

describe('who is coming, and when: only what the server tells a resident', () => {
  it('writes a window the way the board does, on the estate’s clock with no tag (M98)', () => {
    expect(etaWindow('2026-09-04T14:00:00-05:00', '2026-09-04T16:00:00-05:00', at)).toBe('today, 2:00–4:00 PM')
    expect(etaWindow('2026-09-05T11:30:00-05:00', '2026-09-05T13:00:00-05:00', at)).toBe('tomorrow, 11:30 AM – 1:00 PM')
    expect(etaWindow('2026-09-09T09:00:00-05:00', null, at)).toBe('9 Sep 2026, from 9:00 AM')
    expect(etaWindow(null, '2026-09-04T17:00:00-05:00', at)).toBe('today, by 5:00 PM')
    expect(etaWindow(null, null, at)).toBeNull()
  })

  it('"today" is Kingston’s today even when UTC has moved on', () => {
    expect(etaWindow('2026-09-05T01:00:00Z', '2026-09-05T03:00:00Z', at)).toBe('today, 8:00–10:00 PM')
  })

  it('a name with no window, a window with no name, or nothing at all', () => {
    expect(visitOf(ticket({ technician_name: 'Owen Grant' }), at)).toEqual({ initials: 'OG', name: 'Owen Grant', detail: null })
    expect(visitOf(ticket({ eta_starts_at: '2026-09-04T14:00:00-05:00', eta_ends_at: '2026-09-04T16:00:00-05:00' }), at)).toEqual({ initials: '—', name: 'A technician', detail: 'Expected today, 2:00–4:00 PM' })
    expect(visitOf(ticket({}), at)).toBeNull()
  })

  it('a closed job no longer promises a visit', () => {
    expect(visitOf(ticket({ status: 'completed', technician_name: 'Owen Grant', eta_starts_at: '2026-09-04T14:00:00-05:00', eta_ends_at: '2026-09-04T16:00:00-05:00' }), at)).toEqual({ initials: 'OG', name: 'Owen Grant', detail: null })
  })

  it('labels', () => {
    expect(initialsOf('Island Electric Services')).toBe('IS')
    expect(initialsOf('owen')).toBe('OW')
    expect(requestTitle({ number: '#1042' })).toBe('Request #1042')
    expect(metaLine(ticket({}))).toBe('Submitted 2 Sep 2026 · Electrical · Lot 47')
    expect(metaLine(ticket({ category: null, location: '' }))).toBe('Submitted 2 Sep 2026')
    expect(categoryLabel('street_lights')).toBe('Street lights')
    expect(categoryLabel(null)).toBeNull()
  })
})

describe('your requests: the server’s list plus what this phone has not sent', () => {
  it('an unsent request is on top, said to be on this phone, and opens nothing', () => {
    const rows = recentRows({ items: [ticket({})], ops: [op({}), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1', body: null })] })
    expect(rows[0]).toEqual({ key: 'q:op-1', title: 'Kitchen tap is leaking', detail: 'Saved on this phone 4 Sep 2026, 9:00 AM · 1 photo to follow', queue: 'pending', status: null, ticketId: null, opId: 'op-1' })
    expect(rows[1]).toMatchObject({ key: 't:1042', detail: '#1042 · 2 Sep 2026', queue: null, status: 'submitted', ticketId: '1042', opId: null })
  })

  it('a refusal that was read leaves the queue photos first, and not at all while a photo is still waiting', () => {
    const refused = op({ state: 'refused', error: { message: 'Your unit is not active.' } })
    const photo = (id: string, state: QueuedOp['state']) => op({ id, endpoint: 'tickets.media', after: 'op-1', state, body: null })
    expect(refusalToClear('op-1', [refused])).toEqual(['op-1'])
    expect(refusalToClear('op-1', [refused, photo('m-1', 'refused'), photo('m-2', 'refused'), op({ id: 'other', state: 'pending' })])).toEqual(['m-1', 'm-2', 'op-1'])
    // A photo whose request has left the queue can never be addressed, and the queue stops at it.
    expect(refusalToClear('op-1', [refused, photo('m-1', 'pending')])).toBeNull()
    expect(refusalToClear('op-1', [refused, photo('m-1', 'syncing')])).toBeNull()
  })

  it('only a REFUSED request is ever cleared, never one that is waiting, being sent, or accepted', () => {
    for (const state of ['pending', 'syncing', 'confirmed'] as const) expect(refusalToClear('op-1', [op({ state })])).toBeNull()
    expect(refusalToClear('m-1', [op({ id: 'm-1', endpoint: 'tickets.media', state: 'refused' })])).toBeNull()
    expect(refusalToClear('nope', [op({ state: 'refused' })])).toBeNull()
  })

  it('a refusal carries the server’s sentence', () => {
    const rows = recentRows({ items: [], ops: [op({ state: 'refused', error: { message: 'Your unit is not active.' } })] })
    expect(rows[0]).toMatchObject({ queue: 'refused', detail: 'Your unit is not active.' })
  })

  it('a confirmed request never vanishes between "sent" and "listed", and is never drawn twice', () => {
    const answer = ticket({ id: 2001, number: '2001', title: 'Kitchen tap is leaking', reported_at: '2026-09-04T09:00:00-05:00' })
    const confirmed = op({ state: 'confirmed', result: answer })
    expect(recentRows({ items: [ticket({})], ops: [confirmed] }).map((row) => row.key)).toEqual(['t:2001', 't:1042'])
    expect(recentRows({ items: [answer, ticket({})], ops: [confirmed] }).map((row) => row.key)).toEqual(['t:2001', 't:1042'])
  })

  it('shows the five most recent of the server’s', () => {
    const items = Array.from({ length: 8 }, (_, i) => ticket({ id: i + 1, number: String(i + 1), reported_at: `2026-09-0${i + 1}T10:00:00-05:00` }))
    expect(recentRows({ items, ops: [] }).map((row) => row.ticketId)).toEqual(['8', '7', '6', '5', '4'])
  })

  it('finds a ticket by id in the list, or in the server’s own answer, and nowhere else', () => {
    const answer = ticket({ id: 2001 })
    expect(findTicket('1042', [ticket({})], [])?.id).toBe(1042)
    expect(findTicket('2001', [ticket({})], [null, { id: 2001 }, answer])?.id).toBe(2001)
    expect(findTicket('9', [ticket({})], [answer])).toBeNull()
    expect(findTicket(undefined, [ticket({})], [])).toBeNull()
  })

  it('counts the photos of a ticket that are still on the phone', () => {
    const answer = ticket({ id: 2001 })
    const ops = [op({ state: 'confirmed', result: answer }), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1' }), op({ id: 'm-2', endpoint: 'tickets.media', after: 'op-1', state: 'confirmed' }), op({ id: 'm-3', endpoint: 'tickets.media', after: 'other' })]
    expect(photosWaiting('2001', ops)).toBe(1)
    expect(photosWaiting('1042', ops)).toBe(0)
  })

  it('counts the photos the server said no to: the request stands, and its page says a picture did not go along', () => {
    const answer = ticket({ id: 2001 })
    const ops = [op({ state: 'confirmed', result: answer }), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1', state: 'refused' }), op({ id: 'm-2', endpoint: 'tickets.media', after: 'op-1', state: 'confirmed' }), op({ id: 'm-3', endpoint: 'tickets.media', after: 'other', state: 'refused' })]
    expect(photosRefused('2001', ops)).toBe(1)
    expect(photosWaiting('2001', ops)).toBe(0)
    expect(photosRefused('1042', ops)).toBe(0)
  })
})

// A security report is a ticket too — it belongs to "Your reports", never to "Your requests". The marker is COPIED
// from features/incidents (features do not import each other); this suite imports the other side ONLY to prove the
// two copies agree and that each side's own descriptions land where they should.
describe('a security report is not a maintenance request', () => {
  const incidents = require('../../incidents/incidents') as typeof import('../../incidents/incidents')
  const WRITTEN = new Date('2026-09-04T14:41:00Z')

  it('the two features hold the SAME marker', () => {
    expect(INCIDENT_MARKER.source).toBe(incidents.INCIDENT_MARKER.source)
    expect(INCIDENT_MARKER.flags).toBe(incidents.INCIDENT_MARKER.flags)
  })

  it('what Report incident writes is a report; what this form writes is not', () => {
    const report = incidents.composeDescription({ description: 'Two men trying car doors on Palm Avenue.', type: 'suspicious', when: 'now', whenDetail: '' } as never, WRITTEN)
    const request = ticketBody(form({ area: 'common', kind: 'lighting', description: 'The street light is out.', allowEntry: true }), UNIT).description ?? ''
    expect(isIncidentReport({ description: report })).toBe(true)
    expect(isIncidentReport({ description: request })).toBe(false)
    expect(isIncidentReport({ description: report.replace(/\n/g, '\r\n') })).toBe(true) // as a Windows console saves it
    expect(isIncidentReport({ description: null })).toBe(false)
    expect(isIncidentReport({ description: 'Report details: see photo' })).toBe(false) // the words alone are not the block
  })

  it('a maintenance request the resident filed under the server\'s own "security" category stays a request', () => {
    expect(requestsOnly([{ id: 1, category: 'security', description: 'The gate intercom at Lot 47 crackles.' }])).toHaveLength(1)
  })

  it('never both, never neither: every ticket is in exactly one list', () => {
    const report = incidents.composeDescription({ description: 'Loud argument next door.', type: 'disturbance', when: 'earlier', whenDetail: 'about 9 pm' } as never, WRITTEN)
    const all = [{ id: 1, description: 'Tap drips' }, { id: 2, description: report }, { id: 3, description: null }]
    const here = requestsOnly(all).map((t) => t.id)
    const there = all.filter((t) => incidents.isIncidentReport(t as never)).map((t) => t.id)
    expect(here).toEqual([1, 3])
    expect(there).toEqual([2])
    expect([...here, ...there].sort()).toEqual([1, 2, 3])
  })
})
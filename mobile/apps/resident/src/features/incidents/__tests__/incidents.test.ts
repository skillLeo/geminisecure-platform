/**
 * Security reports — a report is a `security` ticket and nothing else is sent; the title the server requires is
 * written by the app from what the resident said and the place; what the board draws and the server lacks (the type) becomes a
 * plain line in the description and reads back; a report never tells the guards on duty (no `happening_now`);
 * the server's timeline sits on the board's four stages; a report the phone has not sent is never called
 * submitted; a ticket of another category is never opened as an incident.
 */
import {
  DESCRIPTION_MAX,
  DETAILS_HEADING,
  EMPTY_REPORT,
  INCIDENT_MARKER,
  NO_UPDATES,
  INCIDENT_TYPES,
  SERVER_DESCRIPTION_MAX,
  TITLE_MAX,
  TITLE_MIN,
  appendTranscript,
  boardSteps,
  boardUpdates,
  composeDescription,
  etaWindow,
  findIncident,
  handlerOf,
  incidentTitle,
  isIncidentReport,
  mediaBody,
  photosRefused,
  photosWaiting,
  readDescription,
  recentRows,
  refusalToClear,
  reportBody,
  reportHeading,
  reportSchema,
  reportTitle,
  stageRows,
  whenShort,
  type Incident,
  type QueuedOp,
  type ReportForm,
} from '../incidents'

const at = new Date('2026-09-04T09:41:00-05:00')

// What was seen, by default: the one thing the resident must give (its own cases are below).
const form = (over: Partial<ReportForm>): ReportForm => ({ ...EMPTY_REPORT, description: 'Two men sitting inside.', ...over })

/** What this feature closes every report's description with — the marker a report is known by. */
const MARKED = 'Two men inside.\n\nReport details:\n- When: Happening now\n- Written on this phone: Sep 4, 2026, 9:12 AM (estate time)'

const incident = (over: Partial<Incident>): Incident => ({
  id: 337, number: '337', title: 'Grey pickup at Phase 2 playground', category: 'security', location: 'Phase 2 playground', description: MARKED, access_permitted: null, preferred_time: null, priority: 'medium', status: 'submitted',
  reported_at: '2026-09-04T09:12:00-05:00', technician_name: null, eta_starts_at: null, eta_ends_at: null, resolution: null, closed_at: null, media_count: 0,
  timeline: [{ event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-04T09:12:00-05:00' }],
  ...over,
})

const op = (over: Partial<QueuedOp>): QueuedOp => ({ id: 'op-1', endpoint: 'tickets.store', state: 'pending', capturedAt: '2026-09-04T09:12:00-05:00', after: null, body: { title: 'Grey pickup' }, error: null, result: null, ...over })

describe('the form is the board\'s', () => {
  it('offers the board\'s four types, exactly, and opens on the first: as the board draws it', () => {
    expect(INCIDENT_TYPES.map((t) => t.label)).toEqual(['Suspicious activity', 'Theft', 'Noise', 'Trespass'])
    expect(EMPTY_REPORT.type).toBe('suspicious')
    expect(reportSchema.safeParse(form({})).success).toBe(true)
  })

  it('refuses a type that is not on the board', () => {
    for (const type of ['arson', 'other', '']) expect(reportSchema.safeParse(form({ type })).success).toBe(false)
  })

  it('needs what was seen or heard: the one thing the office cannot act without (M18C)', () => {
    const messages = (values: ReportForm) => {
      const parsed = reportSchema.safeParse(values)
      return parsed.success ? {} : Object.fromEntries(parsed.error.issues.map((issue) => [issue.path.join('.'), issue.message]))
    }
    expect(messages(form({ description: '' }))).toEqual({ description: 'Say what you saw or heard: tap here to type it, or tap the microphone.' })
    expect(messages(form({ description: '  ab ' }))).toEqual({ description: 'Say what you saw or heard: tap here to type it, or tap the microphone.' })
    expect(reportSchema.safeParse(form({ description: 'x'.repeat(DESCRIPTION_MAX + 1) })).success).toBe(false)
  })

  it('takes no place (the server files it at the unit) or the resident\'s words, up to the server\'s 120', () => {
    expect(reportSchema.safeParse(form({ place: '' })).success).toBe(true)
    expect(reportSchema.safeParse(form({ place: 'Phase 2 playground' })).success).toBe(true)
    expect(reportSchema.safeParse(form({ place: 'x'.repeat(121) })).success).toBe(false)
  })
})

describe('the title the server requires: the incident, not its type (M47 §12.4)', () => {
  it('is what the resident said, then the place they named, or their words alone when they named none', () => {
    expect(reportTitle(form({ description: 'Grey pickup by the playground. Two men inside.', place: 'Phase 2 playground' }))).toBe('Grey pickup by the playground: Phase 2 playground')
    expect(reportTitle(form({ description: 'Grey pickup by the playground', place: '  Phase 2    playground ' }))).toBe('Grey pickup by the playground: Phase 2 playground')
    expect(reportTitle(form({ type: 'theft', description: 'somebody took the bike from the porch', place: '' }))).toBe('Somebody took the bike from the porch')
    expect(reportTitle(form({ type: 'noise', description: 'Music after midnight', place: '   ' }))).toBe('Music after midnight')
  })

  it('never the chip’s word when there are words, and never longer than about forty of them, cut between words', () => {
    const title = reportTitle(form({ description: 'A grey pickup with no plates has been parked by the Phase 2 playground for over an hour', place: 'Phase 2 playground' }))
    expect(title).toBe('A grey pickup with no plates has been…: Phase 2 playground')
    expect(title).not.toContain('Suspicious activity')
  })

  it('always fits the server: four characters or more, and a long place cut at 140', () => {
    for (const t of INCIDENT_TYPES) expect(reportTitle(form({ type: t.value, description: '' })).length).toBeGreaterThanOrEqual(TITLE_MIN)
    const long = reportTitle(form({ description: 'Gate open', place: 'x'.repeat(120) }))
    expect(long.length).toBeLessThanOrEqual(TITLE_MAX)
    expect(long.startsWith('Gate open: x')).toBe(true)
  })

  it('is the title that is sent', () => {
    expect(reportBody(form({ type: 'trespass', description: 'Somebody climbed the back wall.', place: 'Back wall, Phase 3' }), at).title).toBe('Somebody climbed the back wall: Back wall, Phase 3')
    expect(reportBody(form({ type: 'theft', description: 'Bike gone' }), at).title).toBe('Bike gone')
  })

  it('a report filed before M47, titled with its type, is shown by what the resident said: the chip says the type', () => {
    const old = (title: string) => incident({ title, description: `Grey pickup by the playground, two men inside.\n\n${DETAILS_HEADING}\n- Type: Suspicious activity\n- Written on this phone: Fri, Sep 4, 2026, 9:12 AM Jamaica time` })
    // The server's own old title joins type and place with a dash; read either way.
    expect(incidentTitle(old('Suspicious activity — Phase 2 playground'))).toBe('Grey pickup by the playground: Phase 2 playground')
    expect(incidentTitle(old('Suspicious activity: Phase 2 playground'))).toBe('Grey pickup by the playground: Phase 2 playground')
    expect(incidentTitle(old('Suspicious activity'))).toBe('Grey pickup by the playground')
    // Any other title is the record's, shown as it is.
    expect(incidentTitle(old('Grey pickup at Phase 2 playground'))).toBe('Grey pickup at Phase 2 playground')
    expect(incidentTitle(incident({ title: 'Theft: Lot 9', description: 'No details block.' }))).toBe('Theft: Lot 9')
    expect(recentRows({ items: [old('Suspicious activity — Phase 2 playground')], ops: [] })[0]!.title).toBe('Grey pickup by the playground: Phase 2 playground')
  })
})

describe('what is sent', () => {
  it('is always a security ticket, with only the fields tickets.store takes', () => {
    const body = reportBody(form({ type: 'suspicious', place: '  Phase 2   playground ', description: 'Two men inside.' }), at)
    expect(Object.keys(body).sort()).toEqual(['category', 'description', 'location', 'title'])
    expect(body.category).toBe('security')
    expect(body.location).toBe('Phase 2 playground')
  })

  it('leaves the location out when none was given: the server files it at the unit', () => {
    expect('location' in reportBody(form({}), at)).toBe(false)
  })

  it('never tells the guards on duty: a report is not an alert (the user, 7 October)', () => {
    const pinned = reportBody(form({}), at, { latitude: 18, longitude: -76, accuracyM: 5 }) as Record<string, unknown>
    expect('happening_now' in pinned).toBe(false)
    expect('happening_now' in (reportBody(form({}), at) as Record<string, unknown>)).toBe(false)
  })

  it('claims no seriousness the resident never gave: the server keeps its default', () => {
    expect('priority' in (reportBody(form({}), at) as Record<string, unknown>)).toBe(false)
  })

  it('has no anonymous flag and no type field: the server has neither', () => {
    const body = reportBody(form({ type: 'theft' }), at) as Record<string, unknown>
    for (const key of ['anonymous', 'is_anonymous', 'keep_name_private', 'type', 'occurred_at']) expect(key in body).toBe(false)
  })

  it('sends the pin only when there is one, to seven places', () => {
    const none = reportBody(form({}), at) as Record<string, unknown>
    for (const key of ['latitude', 'longitude', 'accuracy_m']) expect(key in none).toBe(false)

    const pinned = reportBody(form({}), at, { latitude: 18.01791234567, longitude: -76.80994567891, accuracyM: 12 })
    expect(pinned.latitude).toBe(18.0179123)
    expect(pinned.longitude).toBe(-76.8099457)
    expect(pinned.accuracy_m).toBe(12)

    // A phone that gave no accuracy still gives a place: the key is left out rather than sent as a guess.
    expect('accuracy_m' in (reportBody(form({}), at, { latitude: 18, longitude: -76, accuracyM: null }) as Record<string, unknown>)).toBe(false)
  })

  it('writes the type and the moment it was written into the description, and no "When"', () => {
    const text = composeDescription(form({ type: 'suspicious', description: 'Two men inside.' }), at)
    // The estate's clock in the one form, its year included (M98): no tag, no zone's name after the time.
    expect(text).toBe(`Two men inside.\n\n${DETAILS_HEADING}\n- Type: Suspicious activity\n- Written on this phone: 4 Sep 2026, 9:41 AM`)
  })

  it('never cuts the details: the typed text gives way first', () => {
    const text = composeDescription(form({ type: 'noise', description: 'x'.repeat(DESCRIPTION_MAX + 500) }), at)
    expect(text.length).toBeLessThanOrEqual(SERVER_DESCRIPTION_MAX)
    expect(text.endsWith('4 Sep 2026, 9:41 AM')).toBe(true)
  })

  it('reads its own lines back, and leaves anyone else\'s description whole', () => {
    const text = composeDescription(form({ type: 'trespass', description: 'Over the back wall.' }), at)
    expect(readDescription(text)).toEqual({ text: 'Over the back wall.', type: 'Trespass', when: null, written: '4 Sep 2026, 9:41 AM' })
    // A report an earlier build filed, with its "When" line, still reads back as it was written.
    expect(readDescription(`Over the wall.\n\n${DETAILS_HEADING}\n- Type: Trespass\n- When: Before today: Tuesday night\n- Written on this phone: Fri, Sep 4, 2026, 9:41 AM Jamaica time`).when).toBe('Before today: Tuesday night')
    expect(readDescription(`Over the wall.\n\n${DETAILS_HEADING}\n- Written on this phone: Sep 4, 2026, 9:41 AM (estate time)`).written).toBe('Sep 4, 2026, 9:41 AM (estate time)')
    expect(readDescription('Report details: see email')).toEqual({ text: 'Report details: see email', type: null, when: null, written: null })
    expect(readDescription('The light is out.')).toEqual({ text: 'The light is out.', type: null, when: null, written: null })
    expect(readDescription(null).text).toBe('')
  })

  it('appends dictation, never replaces typing', () => {
    expect(appendTranscript('Two men. ', ' in a  pickup ')).toBe('Two men. in a pickup')
    expect(appendTranscript('', 'hello')).toBe('hello')
    expect(appendTranscript('kept', '   ')).toBe('kept')
  })

  it('names a photo without naming the resident', () => {
    expect(mediaBody({ uri: 'file:///cache/IMG_0042.HEIC', mime: null, fileName: 'Marcia at home.heic' }, 1).file).toEqual({ uri: 'file:///cache/IMG_0042.HEIC', name: 'report-photo-2.heic', type: 'image/heic' })
    expect(mediaBody({ uri: 'file:///x', mime: 'image/jpeg', fileName: null }, 0).file.name).toBe('report-photo-1.jpg')
  })
})

describe('the server\'s timeline on the board\'s four stages', () => {
  const working = incident({
    status: 'in_progress',
    timeline: [
      { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-04T09:12:00-05:00' },
      { event: 'acknowledged', status: 'acknowledged', note: 'Property Manager', occurred_at: '2026-09-04T09:13:00-05:00' },
      { event: 'assigned', status: 'assigned', note: null, occurred_at: '2026-09-04T09:14:00-05:00' },
      { event: 'started', status: 'in_progress', note: 'Patrol sent', occurred_at: '2026-09-04T09:15:00-05:00' },
      { event: 'update', status: 'in_progress', note: 'Vehicle gone on arrival.', occurred_at: '2026-09-04T09:24:00-05:00' },
    ],
  })

  // M19A §4.1: what happened today is said in words that belong to no clock.
  it('draws done, current and todo, a recent step as how long ago it was', () => {
    const { rows } = stageRows(working, at)
    expect(rows.map((r) => [r.key, r.state, r.detail])).toEqual([
      ['submitted', 'done', '29 minutes ago'],
      ['received', 'done', '28 minutes ago · Property Manager'],
      ['looking', 'current', '26 minutes ago · Patrol sent'],
      ['resolved', 'todo', null],
    ])
  })

  it('never says "security" or "investigation" of a ticket in the office\'s queue', () => {
    const labels = stageRows(working, at).rows.map((r) => r.label).join(' ')
    expect(labels).not.toMatch(/security|investigation/i)
  })

  it('keeps every other entry as an update, oldest first, in the office\'s words', () => {
    expect(stageRows(working, at).updates).toEqual([
      { key: '2:assigned', text: 'Given to someone to handle', when: '27 minutes ago' },
      { key: '4:update', text: 'Vehicle gone on arrival.', when: '17 minutes ago' },
    ])
  })

  it('marks everything done once resolved, and carries the date on another day', () => {
    const resolved = incident({ status: 'completed', timeline: [...working.timeline, { event: 'resolved', status: 'completed', note: null, occurred_at: '2026-09-05T10:00:00-05:00' }] })
    const { rows } = stageRows(resolved, new Date('2026-09-06T08:00:00-05:00'))
    expect(rows.every((r) => r.state === 'done')).toBe(true)
    expect(rows[3]!.detail).toBe('yesterday at 10:00 AM')
    expect(whenShort('2026-09-05T10:00:00-05:00', new Date('2026-09-05T23:00:00-05:00'))).toBe('13 hours ago')
    expect(whenShort('2026-09-01T10:00:00-05:00', new Date('2026-09-05T23:00:00-05:00'))).toBe('1 Sep 2026, 10:00 AM')
  })

  it('shows a reopened report as being looked into again, with the earlier resolution kept', () => {
    const reopened = incident({
      status: 'in_progress',
      timeline: [...working.timeline.slice(0, 4), { event: 'resolved', status: 'completed', note: 'Nothing found', occurred_at: '2026-09-04T10:00:00-05:00' }, { event: 'reopened', status: 'in_progress', note: 'Seen again', occurred_at: '2026-09-04T11:00:00-05:00' }],
    })
    const { rows, updates } = stageRows(reopened, at)
    // Not yet happened by the test's clock (9:41): its own date and time, never "in an hour".
    expect(rows[2]).toMatchObject({ state: 'current', detail: '4 Sep 2026, 11:00 AM · Seen again' })
    expect(rows[3]!.state).toBe('todo')
    expect(updates.map((u) => u.text)).toContain('Resolved: Nothing found')
  })

  it('stops a cancelled report where it got to', () => {
    const cancelled = incident({ status: 'cancelled', closed_at: '2026-09-04T12:00:00-05:00', timeline: [...working.timeline.slice(0, 2), { event: 'cancelled', status: 'cancelled', note: 'Duplicate', occurred_at: '2026-09-04T12:00:00-05:00' }] })
    expect(stageRows(cancelled, at).rows.map((r) => [r.key, r.state])).toEqual([['submitted', 'done'], ['received', 'done'], ['cancelled', 'stopped']])
  })

  it('says who has it only from what the server gives', () => {
    expect(handlerOf(working, at)).toBeNull()
    expect(handlerOf(incident({ status: 'assigned', technician_name: 'Owen Grant', eta_starts_at: '2026-09-04T14:00:00-05:00', eta_ends_at: '2026-09-04T16:00:00-05:00' }), at)).toEqual({ name: 'Owen Grant', detail: 'Expected today, 2:00–4:00 PM' })
    expect(etaWindow(null, null, at)).toBeNull()
    expect(reportHeading({ number: '#1042' })).toBe('Report #1042')
  })
})

describe('board 27’s own words (M47 §12.5): who took it in, and what security did', () => {
  const DISPATCHER = { kind: 'staff' as const, name: 'Renae Cross', role: 'Dispatcher' }
  const board = incident({
    status: 'in_progress',
    timeline: [
      { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-04T09:12:00-05:00', by: { kind: 'resident', name: 'Andrea Fletcher', role: null } },
      { event: 'acknowledged', status: 'acknowledged', note: null, occurred_at: '2026-09-04T09:13:00-05:00', by: DISPATCHER },
      { event: 'started', status: 'in_progress', note: 'Patrol dispatched to the area', occurred_at: '2026-09-04T09:14:00-05:00', by: DISPATCHER },
      { event: 'note', status: 'in_progress', note: 'Guard patrol dispatched to Phase 2 playground.', occurred_at: '2026-09-04T09:16:00-05:00', by: DISPATCHER },
      { event: 'note', status: 'in_progress', note: "Vehicle no longer present on arrival. Area added to tonight's patrol route.", occurred_at: '2026-09-04T09:24:00-05:00', by: DISPATCHER },
    ],
  })

  it('"Received by security · 9:13 AM · Dispatcher Renae Cross": by role and name, on that step only', () => {
    expect(boardSteps(board).map((step) => [step.title, step.line])).toEqual([
      ['Submitted', '9:12 AM'],
      ['Received by security', '9:13 AM · Dispatcher Renae Cross'],
      ['Under investigation', 'Patrol dispatched to the area'],
      ['Resolved', null],
    ])
  })

  it('a member of staff with no role is named; a server that names nobody leaves the time alone', () => {
    const named = incident({ ...board, timeline: board.timeline.map((entry) => (entry.event === 'acknowledged' ? { ...entry, by: { kind: 'staff' as const, name: 'Renae Cross', role: null } } : entry)) })
    expect(boardSteps(named)[1]!.line).toBe('9:13 AM · Renae Cross')
    const older = incident({ ...board, timeline: board.timeline.map(({ by: _by, ...entry }) => entry) })
    expect(boardSteps(older)[1]!.line).toBe('9:13 AM')
  })

  it('"Updates": the office’s own notes, in its words, each with its time', () => {
    expect(boardUpdates(board)).toEqual([
      // M57 §8: who wrote each, beside its time.
      { key: '3:note', text: 'Guard patrol dispatched to Phase 2 playground.', when: '9:16 AM · Dispatcher Renae Cross' },
      { key: '4:note', text: "Vehicle no longer present on arrival. Area added to tonight's patrol route.", when: '9:24 AM · Dispatcher Renae Cross' },
    ])
  })

  it('an Updates box with nothing in it yet says the report arrived and where news will appear (M57 §8)', () => {
    expect(NO_UPDATES).toEqual(['Your report was sent.', 'The estate office will post updates here.'])
  })
})

describe('"Your reports"', () => {
  const maintenance = incident({ id: 900, number: '900', title: 'Tap leaking', category: 'plumbing', description: "It drips all night.\n\nNotes to the office:\n- Entry when I'm not home: allowed" })
  /** Filed from the MAINTENANCE form under the server's own `security` category: not a report sent from here. */
  const gateArm = incident({ id: 901, number: '901', title: 'Gate arm stuck open', category: 'security', description: 'Has been up since Monday.' })

  it('lists only what this feature filed: told by the marker, never by the category', () => {
    const rows = recentRows({ items: [incident({}), maintenance, gateArm, incident({ id: 902, category: 'security', description: null })], ops: [] })
    expect(rows.map((r) => r.incidentId)).toEqual(['337'])
  })

  it('keeps a report the office re-filed under another category', () => {
    expect(recentRows({ items: [incident({ category: 'other' }), incident({ id: 338, category: null })], ops: [] }).map((r) => r.incidentId).sort()).toEqual(['337', '338'])
  })

  it('knows its own marker: every description it composes, nobody else\'s', () => {
    expect(isIncidentReport({ description: composeDescription(form({}), at) })).toBe(true)
    expect(isIncidentReport({ description: composeDescription(form({ type: 'theft', place: 'Car park, Phase 1', description: 'Bike gone.' }), at) })).toBe(true)
    expect(INCIDENT_MARKER.test(MARKED)).toBe(true)
    // The server may hand the text back with Windows line ends; words the office adds after the block do not unmark it.
    expect(isIncidentReport({ description: MARKED.replace(/\n/g, '\r\n') })).toBe(true)
    expect(isIncidentReport({ description: `${MARKED}\n\nOffice: patrol sent.` })).toBe(true)
    for (const description of [null, '', 'Report details: see email', 'Written on this phone: yesterday', maintenance.description, gateArm.description, 'Report details:\n- Type: Theft']) expect(isIncidentReport({ description })).toBe(false)
  })

  it('never calls an unsent report submitted, and says how many photos follow', () => {
    const rows = recentRows({ items: [], ops: [op({}), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1', body: null })] })
    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({ queue: 'pending', status: null, incidentId: null })
    expect(rows[0]!.detail).toBe('Saved on this phone 4 Sep 2026, 9:12 AM · 1 photo to follow')
  })

  it('shows a refusal with the server\'s sentence', () => {
    const rows = recentRows({ items: [], ops: [op({ state: 'refused', error: { message: 'The title is too short.' } })] })
    expect(rows[0]).toMatchObject({ queue: 'refused', detail: 'The title is too short.' })
  })

  it('draws a confirmed report from the server\'s answer until the list has it, never twice', () => {
    const answer = incident({ id: 501, number: '501' })
    expect(recentRows({ items: [], ops: [op({ state: 'confirmed', result: answer })] }).map((r) => r.incidentId)).toEqual(['501'])
    expect(recentRows({ items: [answer], ops: [op({ state: 'confirmed', result: answer })] })).toHaveLength(1)
  })

  it('lets the list overrule the kept answer once the list carries the ticket', () => {
    const answer = incident({ id: 501, number: '501' })
    // The office rewrote the description: the list's copy has no marker, so it is a maintenance row now — not drawn here from the stale answer.
    expect(recentRows({ items: [{ ...answer, description: 'Rewritten by the office.' }], ops: [op({ state: 'confirmed', result: answer })] })).toHaveLength(0)
  })

  it('counts the photos still waiting behind a report, and the ones that were refused', () => {
    const ops = [op({ state: 'confirmed', result: incident({}) }), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1' }), op({ id: 'm-2', endpoint: 'tickets.media', after: 'op-1', state: 'confirmed' }), op({ id: 'm-3', endpoint: 'tickets.media', after: 'op-1', state: 'refused' })]
    expect(photosWaiting('337', ops)).toBe(1)
    expect(photosRefused('337', ops)).toBe(1)
    expect(photosRefused('338', ops)).toBe(0)
  })

  it('carries the queue act behind an unsent row, and none behind the server\'s', () => {
    expect(recentRows({ items: [incident({})], ops: [op({ state: 'refused' })] }).map((r) => r.opId)).toEqual(['op-1', null])
  })
})

describe('clearing a refusal that has been read', () => {
  it('clears the photos first, then the report: nothing was filed, so no record is removed', () => {
    const ops = [op({ state: 'refused' }), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1', state: 'refused' }), op({ id: 'other', state: 'pending' })]
    expect(refusalToClear('op-1', ops)).toEqual(['m-1', 'op-1'])
  })

  it('never clears a report that is waiting, being sent, or was accepted', () => {
    for (const state of ['pending', 'syncing', 'confirmed'] as const) expect(refusalToClear('op-1', [op({ state })])).toBeNull()
    expect(refusalToClear('nope', [op({ state: 'refused' })])).toBeNull()
    expect(refusalToClear('m-1', [op({ id: 'm-1', endpoint: 'tickets.media', state: 'refused' })])).toBeNull()
  })

  it('clears nothing while a photo of it is still waiting its turn', () => {
    expect(refusalToClear('op-1', [op({ state: 'refused' }), op({ id: 'm-1', endpoint: 'tickets.media', after: 'op-1', state: 'pending' })])).toBeNull()
  })
})

describe('finding one report', () => {
  it('finds it in the list or in the server\'s own answer', () => {
    expect(findIncident('337', [incident({})], [])?.id).toBe(337)
    expect(findIncident('501', [], [incident({ id: 501 })])?.id).toBe(501)
    expect(findIncident(undefined, [incident({})], [])).toBeNull()
  })

  it('never opens a ticket this feature did not file as an incident: whatever its category', () => {
    expect(findIncident('900', [incident({ id: 900, category: 'plumbing', description: 'Tap leaking.' })], [])).toBeNull()
    expect(findIncident('901', [incident({ id: 901, category: 'security', description: 'Gate arm stuck open.' })], [])).toBeNull()
    expect(findIncident('902', [incident({ id: 902, category: 'security', description: null })], [])).toBeNull()
  })

  it('opens a report the office re-filed under another category, and trusts the list over a kept answer', () => {
    expect(findIncident('337', [incident({ category: 'other' })], [])?.id).toBe(337)
    const answer = incident({ id: 501 })
    expect(findIncident('501', [{ ...answer, description: 'Rewritten by the office.' }], [answer])).toBeNull()
  })
})

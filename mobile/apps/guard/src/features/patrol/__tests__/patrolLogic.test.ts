/**
 * Patrol's rules. The ones that matter most: the phone never decides a tag is right (the server
 * does), never counts yesterday's tour as today's, and never hides a refusal behind a tick.
 */
import { NEXT_DETAIL, TAG_MAX, buildTour, dayKey, isSending, isToday, judgeTag, learnedCodes, panelFor, scanBody, toCheckpoints, tourMap, unplacedNote, type Checkpoint, type ScanOp } from '../patrolLogic'

const NOW = new Date('2026-09-04T11:40:00-05:00')
const at = (time: string, day = '2026-09-04') => `${day}T${time}:00-05:00`

const board: Checkpoint[] = [
  { id: 1, label: 'Main Gate', sequence: 1, lastScannedAt: at('11:21') },
  { id: 2, label: 'Generator Room', sequence: 2, lastScannedAt: at('11:25') },
  { id: 3, label: 'Pool Deck', sequence: 3, lastScannedAt: at('11:29') },
  { id: 4, label: 'Phase 1 Boundary', sequence: 4, lastScannedAt: at('11:32') },
  { id: 5, label: 'Playground: Phase 2', sequence: 5, lastScannedAt: null },
  { id: 6, label: 'Service Gate', sequence: 6, lastScannedAt: null },
  { id: 7, label: 'Phase 3 Boundary', sequence: 7, lastScannedAt: null },
  { id: 8, label: 'Club House', sequence: 8, lastScannedAt: null },
  { id: 9, label: 'Community Centre', sequence: 9, lastScannedAt: null },
]

let n = 0
const op = (checkpoint: number | string, state: ScanOp['state'], capturedAt: string, extra: Partial<ScanOp> = {}): ScanOp => ({
  id: `op-${++n}`,
  endpoint: 'patrol.scan',
  params: { checkpoint },
  body: { code: `PH-CP-1-${checkpoint}`, captured_offline: false },
  state,
  attempts: state === 'pending' ? 0 : 1,
  capturedAt,
  error: null,
  result: null,
  ...extra,
})

describe('the list the server sends', () => {
  it('is normalised without reordering: the server’s order is the tour order', () => {
    const list = toCheckpoints({ site_id: 'phoenixpark', items: [{ id: 7, label: 'B', sequence: '2', post_id: null, latitude: null, longitude: null, last_scanned_at: null }, { id: 3, label: 'A', sequence: '1', post_id: 4, latitude: 18.018, longitude: -76.81, last_scanned_at: at('11:21') }], posts: [], tour: { total: '2', scanned_today: '1' } })
    expect(list).toEqual([
      { id: 7, label: 'B', sequence: 2, lastScannedAt: null, latitude: null, longitude: null },
      { id: 3, label: 'A', sequence: 1, lastScannedAt: at('11:21'), latitude: 18.018, longitude: -76.81 },
    ])
    expect(toCheckpoints(undefined)).toEqual([])
  })

  it('knows today in Jamaica, whatever zone the timestamp is written in', () => {
    expect(isToday('2026-09-04T16:21:00Z', NOW)).toBe(true) // 11:21 in Kingston
    expect(isToday('2026-09-05T03:30:00Z', NOW)).toBe(true) // 22:30 on the 4th in Kingston
    expect(isToday('2026-09-05T05:30:00Z', NOW)).toBe(false)
    expect(isToday(at('23:50', '2026-09-03'), NOW)).toBe(false)
    expect(isToday(null, NOW)).toBe(false)
    expect(isToday('not a date', NOW)).toBe(false)
  })
})

/**
 * THE TOUR ON A MAP (M11 §6). Only what the office placed is drawn, in its row's colour; the fence is this guard's
 * own post's; a half-placed tour says how many are missing; nothing placed means no map at all (the board's
 * illustration stays), and nothing here ever reads where the guard is.
 */
describe('the tour’s map', () => {
  const posts = [
    { id: 4, name: 'Main Gate', latitude: 18.0181, longitude: -76.8102, radius_m: 60 },
    { id: 9, name: 'Service Gate', latitude: 18.0203, longitude: -76.8071, radius_m: 40 },
  ]
  const placed = board.map((cp, i) => (i < 6 ? { ...cp, latitude: 18.018 + i / 1000, longitude: -76.81 + i / 1000 } : cp))

  it('pins each placed checkpoint in its row’s colour, titled with its place on the tour', () => {
    const tour = buildTour(placed, [op(6, 'refused', at('11:36'), { error: { code: 'code_mismatch', message: 'That is not this checkpoint’s tag.' } })], NOW)
    const map = tourMap(tour.rows, placed, posts, 4)!
    expect(map.pins.map((p) => [p.title, p.tone])).toEqual([
      ['1. Main Gate', 'ok'],
      ['2. Generator Room', 'ok'],
      ['3. Pool Deck', 'ok'],
      ['4. Phase 1 Boundary', 'ok'],
      ['5. Playground: Phase 2', 'neutral'],
      ['6. Service Gate', 'danger'],
    ])
    expect(map.pins[0]).toMatchObject({ latitude: 18.018, longitude: -76.81 })
    expect(map.pins[5]!.detail).toMatch(/^Not accepted · /)
  })

  it('draws the geofence of the post THIS guard is rostered at, not another post’s', () => {
    const rows = buildTour(placed, [], NOW).rows
    expect(tourMap(rows, placed, posts, 4)!.fence).toEqual({ latitude: 18.0181, longitude: -76.8102, radiusM: 60 })
    expect(tourMap(rows, placed, posts, '9')!.fence).toEqual({ latitude: 18.0203, longitude: -76.8071, radiusM: 40 })
    expect(tourMap(rows, placed, posts, 77)!.fence).toBeNull()
    expect(tourMap(rows, placed, undefined, 4)!.fence).toBeNull()
  })

  it('says how many are not on it, and draws no map of nothing', () => {
    const rows = buildTour(placed, [], NOW).rows
    expect(tourMap(rows, placed, posts, 4)!.unplaced).toBe(3)
    expect(unplacedNote(3)).toBe('3 checkpoints are not on the map yet: the office places them.')
    expect(unplacedNote(1)).toBe('1 checkpoint is not on the map yet: the office places them.')
    expect(unplacedNote(0)).toBeNull()
    expect(tourMap(buildTour(board, [], NOW).rows, board, posts, 4)).toBeNull()
  })

  it('leaves out a position that is not a place on Earth', () => {
    const odd = [{ ...board[0]!, latitude: 91, longitude: 0 }, { ...board[1]!, latitude: Number.NaN, longitude: -76.8 }, { ...board[2]!, latitude: 18.02, longitude: -76.81 }]
    const map = tourMap(buildTour(odd, [], NOW).rows, odd, posts, 4)!
    expect(map.pins.map((p) => p.title)).toEqual(['3. Pool Deck'])
    expect(map.unplaced).toBe(2)
  })
})

describe('tour progress', () => {
  it('draws the board: four of nine scanned, the fifth is next, the rest wait', () => {
    const tour = buildTour(board, [], NOW)
    expect([tour.scanned, tour.total, tour.percent]).toEqual([4, 9, 44])
    expect(tour.nextId).toBe(5)
    expect(tour.allScanned).toBe(false)
    expect(tour.lastScanAt).toBe(at('11:32'))
    expect(tour.rows.map((r) => r.state)).toEqual(['scanned', 'scanned', 'scanned', 'scanned', 'next', 'upcoming', 'upcoming', 'upcoming', 'upcoming'])
    expect(tour.rows[0]?.detail).toBe('Scanned 11:21')
    expect(tour.rows[4]?.detail).toBe(NEXT_DETAIL)
    expect(tour.rows[5]?.detail).toBeNull()
    expect(tour.rows[4]?.position).toBe(5)
  })

  it('is empty without being wrong', () => {
    expect(buildTour([], [], NOW)).toMatchObject({ rows: [], nextId: null, scanned: 0, total: 0, percent: 0, lastScanAt: null, allScanned: false })
  })

  it('does not count a list cached yesterday as today’s tour', () => {
    const stale = board.map((cp) => ({ ...cp, lastScannedAt: cp.lastScannedAt ? at('23:10', '2026-09-03') : null }))
    const tour = buildTour(stale, [op(1, 'confirmed', at('23:10', '2026-09-03'))], NOW)
    expect(tour.scanned).toBe(0)
    expect(tour.nextId).toBe(1)
  })

  it('counts a scan captured on this phone before the server has heard of it, and says so on the row', () => {
    const tour = buildTour(board, [op(5, 'pending', at('11:36'))], NOW)
    expect(tour.scanned).toBe(5)
    expect(tour.nextId).toBe(6)
    expect(tour.rows[4]).toMatchObject({ state: 'scanned', sync: 'pending', detail: 'Scanned 11:36 · Pending sync' })
    expect(buildTour(board, [op(5, 'syncing', at('11:36'))], NOW).rows[4]?.detail).toBe('Scanned 11:36 · Syncing')
    expect(buildTour(board, [op(5, 'confirmed', at('11:36'))], NOW).rows[4]?.detail).toBe('Scanned 11:36 · Confirmed')
  })

  it('lets the server’s later time stand once the list has caught up', () => {
    const caughtUp = board.map((cp) => (cp.id === 5 ? { ...cp, lastScannedAt: at('11:37') } : cp))
    expect(buildTour(caughtUp, [op(5, 'confirmed', at('11:36'))], NOW).rows[4]).toMatchObject({ state: 'scanned', sync: null, detail: 'Scanned 11:37' })
  })

  it('shows a refusal in the server’s sentence, does not count it, and keeps the checkpoint next', () => {
    const refused = op(5, 'refused', at('11:36'), { error: { code: 'code_mismatch', message: 'The tag read does not belong to Playground: Phase 2. Scan the tag at the checkpoint.' } })
    const tour = buildTour(board, [refused], NOW)
    expect(tour.scanned).toBe(4)
    expect(tour.nextId).toBe(5)
    expect(tour.rows[4]).toMatchObject({ state: 'refused', sync: 'refused', detail: 'Not accepted · The tag read does not belong to Playground: Phase 2. Scan the tag at the checkpoint.' })
  })

  it('a good scan after a refusal clears it; a refusal after a good scan is still shown', () => {
    const bad = op(5, 'refused', at('11:36'), { error: { code: 'code_mismatch', message: 'No.' } })
    expect(buildTour(board, [bad, op(5, 'pending', at('11:37'))], NOW).rows[4]?.state).toBe('scanned')
    const later = buildTour(board, [op(1, 'refused', at('11:38'), { error: { code: 'code_mismatch', message: 'No.' } })], NOW)
    expect(later.rows[0]?.state).toBe('refused')
    expect(later.scanned).toBe(4) // Main Gate WAS scanned today, at 11:21
  })

  it('does not let a phone clock that runs behind the server hide a refusal, or a scan still waiting to be sent', () => {
    // The server ticked Main Gate at 11:21 (ITS clock). This phone is ten minutes slow: its 11:15 is after that.
    const no = op(1, 'refused', at('11:15'), { error: { code: 'code_mismatch', message: 'No.' } })
    expect(buildTour(board, [no], NOW).rows[0]).toMatchObject({ state: 'refused', sync: 'refused', detail: 'Not accepted · No.' })
    // A second round, scanned offline: the server cannot know it yet, so it is this phone's news whatever the clocks say.
    expect(buildTour(board, [op(1, 'pending', at('11:15'))], NOW).rows[0]).toMatchObject({ state: 'scanned', sync: 'pending', detail: 'Scanned 11:15 · Pending sync' })
  })

  it('starts again with the day: Jamaica’s midnight, not the phone’s or UTC’s', () => {
    expect(dayKey(new Date('2026-09-05T04:59:00Z'))).toBe(dayKey(NOW)) // 23:59 on the 4th in Kingston
    expect(dayKey(new Date('2026-09-05T05:00:00Z'))).not.toBe(dayKey(NOW))
    const afterMidnight = buildTour(board, [op(5, 'confirmed', at('11:36'))], new Date('2026-09-05T05:01:00Z'))
    expect([afterMidnight.scanned, afterMidnight.nextId]).toEqual([0, 1])
  })

  it('starts the next round at the oldest scan once every checkpoint is done', () => {
    const done = board.map((cp, i) => ({ ...cp, lastScannedAt: at(`10:${10 + i}`) }))
    const tour = buildTour(done, [op(1, 'confirmed', at('11:30'))], NOW)
    expect(tour.allScanned).toBe(true)
    expect(tour.percent).toBe(100)
    expect(tour.nextId).toBe(2)
    expect(tour.rows.every((r) => r.state === 'scanned')).toBe(true)
  })

  it('ignores acts that are not checkpoint scans', () => {
    expect(buildTour(board, [op(5, 'pending', at('11:36'), { endpoint: 'gate.entry' })], NOW).scanned).toBe(4)
  })
})

describe('a tag read', () => {
  it('is not a checkpoint tag when it is empty or longer than the server allows: nothing is recorded', () => {
    expect(judgeTag('   ', 5, board, [])).toEqual({ kind: 'not_a_tag', why: 'empty' })
    expect(judgeTag('x'.repeat(TAG_MAX + 1), 5, board, [])).toEqual({ kind: 'not_a_tag', why: 'too_long' })
    expect(judgeTag('x'.repeat(TAG_MAX), 5, board, []).kind).toBe('record')
  })

  it('is otherwise sent, trimmed and untouched, against the checkpoint the guard is at: the server decides', () => {
    expect(judgeTag(' PH-CP-1-5\n', 5, board, [])).toEqual({ kind: 'record', checkpointId: 5, code: 'PH-CP-1-5', redirectedFrom: null })
    expect(judgeTag('ph-cp-1-5', 5, board, [])).toMatchObject({ code: 'ph-cp-1-5' })
  })

  it('learns whose tag it is only from a scan the server confirmed', () => {
    const ops = [op(1, 'confirmed', at('11:21')), op(2, 'pending', at('11:25')), op(3, 'refused', at('11:29'))]
    expect([...learnedCodes(ops).entries()]).toEqual([['PH-CP-1-1', 1]])
    expect(judgeTag('PH-CP-1-1', 5, board, ops)).toEqual({ kind: 'record', checkpointId: 1, code: 'PH-CP-1-1', redirectedFrom: 5 })
    expect(judgeTag('PH-CP-1-1', 1, board, ops)).toMatchObject({ checkpointId: 1, redirectedFrom: null })
    expect(judgeTag('PH-CP-1-2', 5, board, ops)).toMatchObject({ checkpointId: 5, redirectedFrom: null })
  })

  it('does not redirect to a checkpoint that is no longer on the tour', () => {
    expect(judgeTag('PH-CP-1-44', 5, board, [op(44, 'confirmed', at('11:21'))])).toMatchObject({ checkpointId: 5, redirectedFrom: null })
  })
})

describe('what a scan is sent (M21 §1.1)', () => {
  it('carries the fix on-duty sharing already holds, and nothing when there is none', () => {
    expect(scanBody('GSCP-ABC', false, null)).toEqual({ code: 'GSCP-ABC', captured_offline: false })
    expect(scanBody('GSCP-ABC', true, { latitude: 18.0181, longitude: -76.8095, accuracy_m: 11.6 })).toEqual({ code: 'GSCP-ABC', captured_offline: true, latitude: 18.0181, longitude: -76.8095, accuracy_m: 12 })
    expect(scanBody('GSCP-ABC', false, { latitude: 18.0181, longitude: -76.8095, accuracy_m: null })).toEqual({ code: 'GSCP-ABC', captured_offline: false, latitude: 18.0181, longitude: -76.8095 })
    expect(scanBody('GSCP-ABC', false, { latitude: Number.NaN, longitude: -76.8, accuracy_m: 5 })).toEqual({ code: 'GSCP-ABC', captured_offline: false })
  })
})

describe('the result the guard reads', () => {
  const recorded = { kind: 'recorded', opId: 'x', checkpointId: 5, label: 'Playground: Phase 2', expectedLabel: null } as const

  it('says plainly when nothing was recorded', () => {
    expect(panelFor({ kind: 'not_a_tag', why: 'too_long' }, null, true)).toMatchObject({ tone: 'red', headline: 'NOT A CHECKPOINT TAG', title: 'Nothing was recorded', settled: true })
    expect(panelFor({ kind: 'not_saved' }, null, true)).toMatchObject({ tone: 'red', headline: 'NOT SAVED', settled: true })
  })

  it('never calls a scan confirmed before the server has', () => {
    expect(panelFor(recorded, op(5, 'pending', at('11:36')), false)).toMatchObject({ tone: 'amber', headline: 'SAVED: PENDING SYNC', settled: true })
    expect(panelFor(recorded, op(5, 'pending', at('11:36')), true)).toMatchObject({ tone: 'neutral', settled: false })
    expect(panelFor(recorded, op(5, 'syncing', at('11:36')), true)).toMatchObject({ tone: 'neutral', headline: 'CHECKING THE TAG…', settled: false })
    expect(panelFor(recorded, op(5, 'pending', at('11:36'), { attempts: 2 }), true)).toMatchObject({ tone: 'amber', settled: true })
  })

  it('does not say "sending it now" for ever: a scan queued behind a stuck act is never tried', () => {
    const untried = op(5, 'pending', at('11:36'))
    expect(isSending(untried, true)).toBe(true)
    expect(isSending(untried, false)).toBe(false)
    expect(isSending(op(5, 'pending', at('11:36'), { attempts: 1 }), true)).toBe(false)
    expect(isSending(op(5, 'confirmed', at('11:36')), true)).toBe(false)
    expect(isSending(null, true)).toBe(false)
    const waited = panelFor(recorded, untried, true, true)
    expect(waited).toMatchObject({ tone: 'amber', headline: 'SAVED: PENDING SYNC', settled: true })
    expect(waited.body).toContain('The server has not answered yet')
    expect(panelFor(recorded, op(5, 'syncing', at('11:36')), true, true).settled).toBe(true)
    // Having waited never outranks an answer.
    expect(panelFor(recorded, op(5, 'confirmed', at('11:36')), true, true).tone).toBe('green')
    expect(panelFor(recorded, op(5, 'refused', at('11:36'), { error: { code: 'code_mismatch', message: 'No.' } }), true, true).tone).toBe('red')
  })

  it('confirms with the server’s own count, and passes on its clock warning only for a clock that is AHEAD (B066)', () => {
    const tour = { total: 9, scanned_today: 5 }
    const ok = panelFor(recorded, op(5, 'confirmed', at('11:36'), { result: { tour, clock_skewed: false } }), true)
    expect(ok).toMatchObject({ tone: 'green', headline: 'CHECKPOINT CONFIRMED', title: 'Playground: Phase 2', body: '5 of 9 checkpoints scanned today.' })
    // A clock ten minutes fast: said.
    const fast = { tour, clock_skewed: true, device_time: '2026-09-04T11:46:00-05:00', server_time: '2026-09-04T11:36:00-05:00' }
    expect(panelFor(recorded, op(5, 'confirmed', at('11:36'), { result: fast }), true).body).toContain("clock disagrees with the server's")
    // A scan queued with no signal and synced twenty minutes later — an older server still flags it. Not said.
    const queued = { tour, clock_skewed: true, device_time: '2026-09-04T11:16:00-05:00', server_time: '2026-09-04T11:36:00-05:00' }
    expect(panelFor(recorded, op(5, 'confirmed', at('11:16'), { result: queued }), true).body).toBe('5 of 9 checkpoints scanned today.')
    // A flag with no times to check it against: not said on a guess.
    expect(panelFor(recorded, op(5, 'confirmed', at('11:36'), { result: { tour, clock_skewed: true } }), true).body).toBe('5 of 9 checkpoints scanned today.')
    expect(panelFor(recorded, op(5, 'confirmed', at('11:36')), true).body).toBe('The server has it.')
  })

  it('shows a refusal in the server’s sentence, and what to do about a wrong tag', () => {
    const no = panelFor(recorded, op(5, 'refused', at('11:36'), { error: { code: 'code_mismatch', message: 'The tag read does not belong to Playground: Phase 2. Scan the tag at the checkpoint.' } }), true)
    expect(no.tone).toBe('red')
    expect(no.headline).toBe('NOT ACCEPTED: NOTHING RECORDED')
    expect(no.body).toBe('The tag read does not belong to Playground: Phase 2. Scan the tag at the checkpoint. If you are standing at a different checkpoint, choose it in the list and scan again.')
    expect(panelFor(recorded, op(5, 'refused', at('11:36'), { error: { code: 'not_found', message: 'No such checkpoint at your site.' } }), true).body).toBe('No such checkpoint at your site.')
  })

  it('says the server’s own rules: early is recorded and amber; off shift and outside are refused with what to do (M21 §1.1)', () => {
    const tour = { total: 9, scanned_today: 5 }
    const note = 'Recorded as early: you scanned Playground: Phase 2 6 min ago. The next round here is due at 11:45 AM.'
    const early = panelFor(recorded, op(5, 'confirmed', at('11:36'), { result: { tour, early: true, note } }), true)
    expect(early).toMatchObject({ tone: 'amber', headline: 'RECORDED: EARLY', settled: true })
    expect(early.body).toBe(`5 of 9 checkpoints scanned today. ${note}`)
    // A recorded scan with nothing more to say is green and says nothing more; a note without "early" stays green.
    expect(panelFor(recorded, op(5, 'confirmed', at('11:36'), { result: { tour, early: false, note: null } }), true)).toMatchObject({ tone: 'green', body: '5 of 9 checkpoints scanned today.' })
    const noFix = panelFor(recorded, op(5, 'confirmed', at('11:36'), { result: { tour, early: false, note: 'No position came from this phone, so where you were could not be checked. Keep location on.' } }), true)
    expect(noFix.tone).toBe('green')
    expect(noFix.body).toContain('No position came from this phone')

    const offShift = panelFor(recorded, op(5, 'refused', at('11:36'), { error: { code: 'not_on_shift', message: 'Clock in first.' } }), true)
    expect(offShift.body).toBe('Clock in first. Clock in on the Shift tab, then scan the tag again.')
    const outside = panelFor(recorded, op(5, 'refused', at('11:36'), { error: { code: 'outside_checkpoint', message: 'You are 140m from Playground: Phase 2.' } }), true)
    expect(outside).toMatchObject({ tone: 'red', headline: 'NOT ACCEPTED: NOTHING RECORDED' })
    expect(outside.body).toBe('You are 140m from Playground: Phase 2. Walk to the tag and scan it there.')
  })

  it('says where a recognised tag was recorded when it was not the one expected', () => {
    const moved = panelFor({ ...recorded, checkpointId: 1, label: 'Main Gate', expectedLabel: 'Playground: Phase 2' }, op(1, 'pending', at('11:36')), false)
    expect(moved.title).toBe('Main Gate')
    expect(moved.body).toContain("This is Main Gate's tag, so it was recorded there, not for Playground: Phase 2.")
  })
})

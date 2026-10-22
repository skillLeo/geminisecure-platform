/**
 * The walk-up path's pure logic, held to what the screens promise:
 *
 *   THE WAIT NEVER HANGS   a request nobody answered becomes `expired` on the phone's own count,
 *                          with or without signal; the server's word always wins when there is one.
 *   THE COUNT IS REAL      it counts to the server's `respond_by`, corrected for a wrong handset clock.
 *   THE LOG IS THE GUARD'S only rows recorded under the signed-in guard's name; this phone's unsent
 *                          gate acts on top, marked; a refused one carries the server's sentence.
 */
import type { Api } from '@gs/core/api'
import {
  activityRow, approvalIdOf, clockSkewMs, countdown, countTiles, EXPIRED_GUIDANCE, exitLabel, initialsOf, isLogOfToday, isTerminal, mineOnly, onSiteNow, queuedExitSubject, queuedRows,
  ringOffset, RING_LENGTH, subjectOf, SUBJECT_MAX, waitCopy, waitView, type QueuedAct,
} from '../walkup'

const at = (iso: string) => new Date(iso).getTime()

describe('countdown: real time to respond_by', () => {
  const window = { requestedAt: '2026-09-04T14:40:00Z', respondBy: '2026-09-04T14:43:00Z' }

  it('counts the three minutes down in mm:ss', () => {
    expect(countdown({ ...window, nowMs: at('2026-09-04T14:40:00Z') })).toMatchObject({ known: true, remainingS: 180, totalS: 180, fraction: 1, label: '03:00', over: false })
    expect(countdown({ ...window, nowMs: at('2026-09-04T14:41:36Z') })).toMatchObject({ remainingS: 84, label: '01:24', over: false })
    expect(countdown({ ...window, nowMs: at('2026-09-04T14:42:59.400Z') })).toMatchObject({ remainingS: 1, label: '00:01', over: false })
  })

  it('reaches zero, stays there, and says it is over', () => {
    expect(countdown({ ...window, nowMs: at('2026-09-04T14:43:00Z') })).toMatchObject({ remainingS: 0, fraction: 0, label: '00:00', over: true })
    expect(countdown({ ...window, nowMs: at('2026-09-04T15:30:00Z') })).toMatchObject({ remainingS: 0, fraction: 0, label: '00:00', over: true })
  })

  it('reproduces the board: 84 s left of a 112 s window is a three-quarter ring, offset 82 of 327', () => {
    const c = countdown({ requestedAt: '2026-09-04T09:40:32-05:00', respondBy: '2026-09-04T09:42:24-05:00', nowMs: at('2026-09-04T09:41:00-05:00') })
    expect(c.label).toBe('01:24')
    expect(c.fraction).toBeCloseTo(0.75)
    expect(ringOffset(c.fraction)).toBe(82)
  })

  it('corrects for a handset clock that is wrong', () => {
    // The phone is two minutes SLOW: uncorrected it would give the household five minutes.
    const slow = at('2026-09-04T14:39:00Z')
    expect(countdown({ ...window, nowMs: slow }).remainingS).toBe(240)
    expect(countdown({ ...window, nowMs: slow, skewMs: 120_000 }).remainingS).toBe(120)
  })

  it('falls back to the server’s three minutes when requested_at is unknown or nonsense', () => {
    expect(countdown({ requestedAt: null, respondBy: window.respondBy, nowMs: at('2026-09-04T14:41:30Z') })).toMatchObject({ totalS: 180, remainingS: 90, fraction: 0.5 })
    expect(countdown({ requestedAt: window.respondBy, respondBy: window.requestedAt, nowMs: at('2026-09-04T14:39:00Z') }).totalS).toBe(180)
  })

  it('with no respond_by there is nothing to count, and nothing is "over"', () => {
    expect(countdown({ requestedAt: null, respondBy: null, nowMs: 0 })).toMatchObject({ known: false, over: false, label: '--:--' })
    expect(countdown({ requestedAt: null, respondBy: 'not a date', nowMs: 0 }).known).toBe(false)
  })

  it('draws the ring from full (0) to empty (its whole length), never outside', () => {
    expect(ringOffset(1)).toBe(0)
    expect(ringOffset(0)).toBe(RING_LENGTH)
    expect(ringOffset(7)).toBe(0)
    expect(ringOffset(-1)).toBe(RING_LENGTH)
  })
})

describe('clockSkewMs', () => {
  it('is the server’s clock minus the handset’s', () => {
    expect(clockSkewMs('2026-09-04T14:42:00Z', at('2026-09-04T14:40:00Z'))).toBe(120_000)
    expect(clockSkewMs('2026-09-04T14:40:00Z', at('2026-09-04T14:45:00Z'))).toBe(-300_000)
  })
  it('ignores the request’s own travel time, and an answer with no server time', () => {
    expect(clockSkewMs('2026-09-04T14:40:00.000Z', at('2026-09-04T14:40:01.200Z'))).toBe(0)
    expect(clockSkewMs(undefined, 0)).toBe(0)
    expect(clockSkewMs('nonsense', 0)).toBe(0)
  })
})

describe('waitView: the screen never hangs', () => {
  const running = countdown({ requestedAt: '2026-09-04T14:40:00Z', respondBy: '2026-09-04T14:43:00Z', nowMs: at('2026-09-04T14:41:00Z') })
  const over = countdown({ requestedAt: '2026-09-04T14:40:00Z', respondBy: '2026-09-04T14:43:00Z', nowMs: at('2026-09-04T14:43:01Z') })
  const unknown = countdown({ requestedAt: null, respondBy: null, nowMs: 0 })

  it('the server’s last word wins, whatever the phone’s clock says', () => {
    for (const status of ['approved', 'denied', 'expired'] as const) {
      expect(waitView(status, running)).toBe(status)
      expect(waitView(status, over)).toBe(status)
      expect(isTerminal(status)).toBe(true)
    }
  })

  it('pending and in time → waiting', () => {
    expect(waitView('pending', running)).toBe('waiting')
    expect(isTerminal('pending')).toBe(false)
  })

  it('pending, or no answer at all, with no signal, past respond_by → expired, on the phone’s own count', () => {
    expect(waitView('pending', over)).toBe('expired')
    expect(waitView(null, over)).toBe('expired')
    expect(waitView(undefined, over)).toBe('expired')
  })

  it('does not call a request expired when it has nothing to count to', () => {
    expect(waitView(null, unknown)).toBe('waiting')
  })

  it('a status it does not know is not an answer', () => {
    expect(waitView('escalated', running)).toBe('waiting')
    expect(waitView('escalated', over)).toBe('expired')
  })
})

describe('waitCopy: the server’s guidance, only when it is about the state drawn', () => {
  const facts = { household: 'Fletcher household', respondedBy: 'Andrea Fletcher', status: 'approved', guidance: 'Approved by the household. Record the entry with this approval.' }

  it('approved: green, names who answered, shows the guidance', () => {
    expect(waitCopy('approved', facts)).toEqual({ tone: 'green-400', title: 'Approved by Andrea Fletcher', body: facts.guidance })
  })

  it('denied: red, the server’s sentence', () => {
    expect(waitCopy('denied', { ...facts, status: 'denied', guidance: 'The household declined. Do not admit.' })).toEqual({ tone: 'red-500', title: 'Declined by Andrea Fletcher', body: 'The household declined. Do not admit.' })
  })

  it('expired by the SERVER: amber, the server’s sentence', () => {
    expect(waitCopy('expired', { household: 'Fletcher household', respondedBy: null, status: 'expired', guidance: 'Nobody answered. Follow policy.' })).toEqual({ tone: 'amber-500', title: 'No answer from Fletcher household', body: 'Nobody answered. Follow policy.' })
  })

  it('expired on the PHONE’s count: never shows "Waiting on the household." under a no-answer title', () => {
    const copy = waitCopy('expired', { household: 'Fletcher household', respondedBy: null, status: 'pending', guidance: 'Waiting on the household.' })
    expect(copy.body).toBe(EXPIRED_GUIDANCE)
    expect(copy.tone).toBe('amber-500')
  })

  it('falls back to plain words when names or guidance are missing', () => {
    expect(waitCopy('waiting', { household: null, respondedBy: null, status: null, guidance: null }).title).toBe('Waiting for the household')
    expect(waitCopy('approved', { household: null, respondedBy: '  ', status: 'approved', guidance: ' ' })).toMatchObject({ title: 'Approved by the household', body: 'Approved by the household. Record the entry with this approval.' })
  })

  it('no state’s words mention money or a household’s standing', () => {
    for (const view of ['waiting', 'approved', 'denied', 'expired'] as const) {
      const text = JSON.stringify(waitCopy(view, { household: 'H', respondedBy: null, status: null, guidance: null }))
      expect(text).not.toMatch(/arrears|balance|owing|overdue|paid|\$|restricted/i)
    }
  })
})

describe('what the gate log is told', () => {
  it('names the visitor and where they were going', () => {
    expect(subjectOf('  Kevin   Chambers ', 'Phase 2 · Lot 47')).toBe('Kevin Chambers · Phase 2 · Lot 47')
    expect(subjectOf('Kevin Chambers', '')).toBe('Kevin Chambers')
    expect(subjectOf('Kevin Chambers', null)).toBe('Kevin Chambers')
  })

  it('never exceeds the server’s 120 characters', () => {
    const subject = subjectOf('N'.repeat(160), 'Phase 2 · Lot 47')
    expect(subject.length).toBeLessThanOrEqual(SUBJECT_MAX)
  })

  it('reads an approval id from a route param, and refuses anything that is not one', () => {
    expect(approvalIdOf('3107')).toBe(3107)
    expect(approvalIdOf(' 12 ')).toBe(12)
    expect(approvalIdOf(9)).toBe(9)
    for (const bad of ['', '0', '-4', '12.5', 'abc', '1e3', null, undefined]) expect(approvalIdOf(bad)).toBeNull()
  })
})

const item = (over: Partial<Api.GateActivityResponse['items'][number]>): Api.GateActivityResponse['items'][number] => ({
  id: 1, verdict: 'admit', category: 'Visitor', subject: 'Marcia James · Lot 47', basis: 'QR pass', pass_based: true, guard_name: 'Marcus Whyte', post_name: 'Main Gate',
  occurred_at: '2026-09-04T19:14:00Z', device_time: null, ...over,
})

describe('activityRow', () => {
  it('draws who, on what basis and where, at Jamaica time', () => {
    expect(activityRow(item({}))).toEqual({ key: 'e:1', initials: 'MJ', title: 'Marcia James', sub: 'QR pass · Lot 47', time: '2:14 PM', result: 'admit', resultLabel: 'Admitted', note: null, queued: false })
  })

  it('falls back to the category when the subject names no place, and capitalises the server’s "guard decision"', () => {
    expect(activityRow(item({ subject: 'TropiClean Pest Control', category: 'Contractor', basis: 'guard decision' })).sub).toBe('Guard decision · Contractor')
  })

  it('says every verdict in words: a glyph and a colour are never the only signal', () => {
    expect(activityRow(item({ verdict: 'deny' }))).toMatchObject({ result: 'deny', resultLabel: 'Denied' })
    expect(activityRow(item({ verdict: 'override', basis: 'Override: confirmed by phone' }))).toMatchObject({ result: 'override', resultLabel: 'Admitted on override', sub: 'Override: confirmed by phone · Lot 47' })
    expect(activityRow(item({ verdict: 'exit' }))).toMatchObject({ result: 'exit', resultLabel: 'Exit', sub: 'Exit · QR pass · Lot 47' })
    expect(activityRow(item({ verdict: 'held' }))).toMatchObject({ result: 'other', resultLabel: 'Held' })
  })

  it('never shows an empty name', () => {
    expect(activityRow(item({ subject: '' }))).toMatchObject({ title: 'Not named', initials: 'NN' })
    expect(initialsOf('— 8821')).toBe('8')
    expect(initialsOf('···')).toBe('?')
    expect(initialsOf('TropiClean Pest Control')).toBe('TP')
  })
})

describe('onSiteNow: who is still inside (M54 §2.4)', () => {
  it('every admission and override not matched by a later exit of the same visitor, newest arrival first', () => {
    const log = [
      item({ id: 1, subject: 'Marcia James · Lot 47', occurred_at: '2026-09-04T14:00:00Z' }),
      item({ id: 2, subject: 'Kevin Chambers · Lot 12', verdict: 'override', basis: 'Guard override', occurred_at: '2026-09-04T15:00:00Z' }),
      item({ id: 3, subject: 'Denied Person · Lot 3', verdict: 'deny', occurred_at: '2026-09-04T15:30:00Z' }),
      item({ id: 4, subject: 'marcia  james · Lot 47', verdict: 'exit', occurred_at: '2026-09-04T16:00:00Z' }),
    ]
    expect(onSiteNow(log, []).map((r) => [r.key, r.title, r.sub])).toEqual([['in:2', 'Kevin Chambers', 'In since 10:00 AM · Lot 12']])
  })

  it('a visitor who came twice and left once is still inside once', () => {
    const log = [
      item({ id: 1, occurred_at: '2026-09-04T13:00:00Z' }),
      item({ id: 2, verdict: 'exit', occurred_at: '2026-09-04T14:00:00Z' }),
      item({ id: 3, occurred_at: '2026-09-04T15:00:00Z' }),
    ]
    expect(onSiteNow(log, []).map((r) => r.key)).toEqual(['in:3'])
  })

  it('an exit this phone has queued counts at once, so the row goes the moment it is pressed', () => {
    expect(onSiteNow([item({ id: 1 })], ['Marcia James · Lot 47'])).toEqual([])
    expect(queuedExitSubject(exitLabel('Marcia James · Lot 47'))).toBe('Marcia James · Lot 47')
    expect(queuedExitSubject('Entry · Kevin Chambers')).toBeNull()
  })

  it('keeps what the exit is recorded against: the log\'s own category and subject', () => {
    expect(onSiteNow([item({ category: 'Contractor', subject: 'Owen Grant · Lot 9' })], [])[0]).toMatchObject({ category: 'Contractor', subject: 'Owen Grant · Lot 9' })
  })
})

describe('mineOnly: the guard’s own log', () => {
  const items = [item({ id: 1 }), item({ id: 2, guard_name: 'Devon Palmer' }), item({ id: 3, guard_name: ' marcus  WHYTE ' })]

  it('keeps the rows recorded under the signed-in guard’s name, however it is spaced or cased', () => {
    expect(mineOnly(items, 'Marcus Whyte').map((i) => i.id)).toEqual([1, 3])
  })

  it('with no name to match on, nothing can be called "yours"', () => {
    expect(mineOnly(items, null)).toEqual([])
    expect(mineOnly(items, '  ')).toEqual([])
  })
})

describe('queuedRows: this phone’s unsent gate acts', () => {
  const op = (over: Partial<QueuedAct>): QueuedAct => ({ id: 'a', label: 'Entry · Kevin Chambers', kind: 'gate', state: 'pending', capturedAt: '2026-09-04T18:02:00Z', error: null, ...over })

  it('marks a waiting act Pending sync, newest first, and sends it to the sync queue', () => {
    const rows = queuedRows([op({ id: 'a' }), op({ id: 'b', label: 'Denied · Unknown caller · Lot 12', state: 'syncing', capturedAt: '2026-09-04T18:30:00Z' })])
    expect(rows.map((r) => r.key)).toEqual(['q:b', 'q:a'])
    expect(rows[0]).toMatchObject({ title: 'Unknown caller', sub: 'Syncing · Denied · Lot 12', result: 'syncing', resultLabel: 'Syncing', queued: true, time: '1:30 PM' })
    expect(rows[1]).toMatchObject({ title: 'Kevin Chambers', initials: 'KC', sub: 'Pending sync · Entry', result: 'pending', resultLabel: 'Pending sync', note: null, queued: true })
  })

  it('a refused act is Not accepted, with the server’s own sentence', () => {
    const [row] = queuedRows([op({ state: 'refused', error: { message: 'The household has not approved this visitor.' } })])
    expect(row).toMatchObject({ result: 'refused', resultLabel: 'Not accepted', sub: 'Not accepted · Entry', note: 'The household has not approved this visitor.' })
    expect(queuedRows([op({ state: 'refused' })])[0]!.note).toBe('The server did not accept this.')
  })

  it('leaves out confirmed acts (they are in the server’s log) and anything that is not a gate act', () => {
    expect(queuedRows([op({ state: 'confirmed' }), op({ kind: 'clock', label: 'Clock-in · Main Gate' })])).toEqual([])
  })

  it('keeps a label it cannot split', () => {
    expect(queuedRows([op({ label: 'Gate entry' })])[0]).toMatchObject({ title: 'Gate entry', sub: 'Pending sync' })
  })
})

describe('countTiles: the estate’s totals', () => {
  it('reads the server’s four counts, whether they arrive as numbers or strings', () => {
    const counts = { admitted: 14, exited: '9', overridden: '0', denied: 1 } as unknown as Api.GateActivityResponse['counts']
    expect(countTiles(counts).map((t) => [t.label, t.value, t.tone])).toEqual([
      ['ADMITTED', '14', 'green-400'], ['EXITED', '9', 'white'], ['OVERRIDDEN', '0', 'amber-500'], ['DENIED', '1', 'red-500'],
    ])
  })

  it('with no log there is no count: a dash, never a made-up zero', () => {
    expect(countTiles(null).map((t) => t.value)).toEqual(['—', '—', '—', '—'])
  })
})

describe('isLogOfToday: yesterday’s saved log is not today’s', () => {
  it('compares dates in Jamaica, not in UTC', () => {
    // 02:00 UTC on the 5th is still 9 PM on the 4th in Jamaica.
    expect(isLogOfToday('2026-09-04', new Date('2026-09-05T02:00:00Z'))).toBe(true)
    expect(isLogOfToday('2026-09-05', new Date('2026-09-05T02:00:00Z'))).toBe(false)
    expect(isLogOfToday('2026-09-03', new Date('2026-09-04T14:41:00Z'))).toBe(false)
    expect(isLogOfToday(null, new Date('2026-09-04T14:41:00Z'))).toBe(false)
  })
})

import { readFileSync } from 'node:fs'
import path from 'node:path'
import type { OutboxOp } from '@gs/core/offline'
import { forgetSiteZone, setSiteZone } from '@gs/core/lib'
import { claimRefusal, dayLabel, dayLabelLong, dayOptions, daysBetween, equipmentBody, leaveBody, openShiftCards, relativeDay, requestRows, validateEquipment, validateLeave, type GuardRequest, type LeaveInput, type OpenShift } from '../requests'

// Friday 2026-09-04 09:41 in Jamaica (UTC−5).
const AT = new Date('2026-09-04T14:41:00Z')

// A reason by default: every kind of leave needs one since M18C (its own case is below).
const leave = (over: Partial<LeaveInput> = {}): LeaveInput => ({ type: 'vacation', from: '2026-09-15', to: '2026-09-17', reason: 'Family wedding', certificate: false, ...over })

const op = (id: string, body: Record<string, unknown>, state: OutboxOp['state'] = 'pending', error: OutboxOp['error'] = null): OutboxOp =>
  ({ id, endpoint: 'requests.store', params: {}, body, stamp: { idempotencyKey: id, deviceTime: AT.toISOString() }, label: 'x', kind: 'request', owner: 'GS-9001', after: null, state, attempts: 0, capturedAt: AT.toISOString(), lastTriedAt: null, error, result: null }) as unknown as OutboxOp

const request = (over: Partial<GuardRequest>): GuardRequest => ({ id: 1, kind: 'leave', subject: 'vacation', quantity: null, starts_on: '2026-09-15', ends_on: '2026-09-17', days: '3', reason: null, certificate_attached: false, status: 'pending', decided_at: null, decision_note: null, created_at: '2026-09-01T15:00:00Z', ...over })

describe('dates, in Jamaica', () => {
  it('labels and counts days inclusively', () => {
    expect(dayLabel('2026-09-15')).toBe('15 Sep 2026')
    expect(dayLabelLong('2026-09-15')).toBe('15 Sep 2026')
    expect(daysBetween('2026-09-15', '2026-09-17')).toBe(3)
    expect(daysBetween('2026-09-15', '2026-09-15')).toBe(1)
  })
  it('offers today first: Jamaica\'s today, not UTC\'s', () => {
    expect(dayOptions(new Date('2026-09-05T03:00:00Z'), 2).map((d) => d.value)).toEqual(['2026-09-04', '2026-09-05', '2026-09-06'])
  })
})

describe('asking for leave', () => {
  it('a plain vacation request is valid and sends only what the server takes', () => {
    expect(validateLeave(leave(), AT)).toEqual({})
    expect(leaveBody(leave())).toEqual({ kind: 'leave', subject: 'vacation', starts_on: '2026-09-15', ends_on: '2026-09-17', reason: 'Family wedding' })
  })
  it('refuses a past first day: except sick leave, which is reported after the fact', () => {
    expect(validateLeave(leave({ from: '2026-09-01', to: '2026-09-02' }), AT).from).toBeDefined()
    expect(validateLeave(leave({ type: 'sick', from: '2026-09-01', to: '2026-09-02' }), AT)).toEqual({})
  })
  it('refuses a last day before the first', () => expect(validateLeave(leave({ to: '2026-09-14' }), AT).to).toBeDefined())
  it('every kind of leave needs a reason (M18C): the supervisor decides on it', () => {
    for (const type of ['vacation', 'sick', 'other', 'unpaid'] as const) expect(validateLeave(leave({ type, reason: '  ' }), AT).reason).toBe('Say briefly why: your supervisor decides on it.')
    expect(validateLeave(leave({ type: 'unpaid', reason: 'Moving house' }), AT)).toEqual({})
  })
  it('a certificate is a tick on SICK leave only, never a file, never on other leave', () => {
    expect(leaveBody(leave({ type: 'sick', certificate: true })).certificate_attached).toBe(true)
    expect(leaveBody(leave({ type: 'vacation', certificate: true }))).not.toHaveProperty('certificate_attached')
  })
})

describe('asking for equipment', () => {
  it('needs an item and a reason', () => {
    expect(validateEquipment({ item: null, other: '', quantity: 1, reason: '' })).toMatchObject({ item: expect.any(String), reason: expect.any(String) })
    expect(validateEquipment({ item: 'Torch', other: '', quantity: 1, reason: 'Battery is dead' })).toEqual({})
  })
  it('"Other" must be named, and the name is what is sent', () => {
    expect(validateEquipment({ item: 'Other', other: ' ', quantity: 1, reason: 'x' }).other).toBeDefined()
    expect(equipmentBody({ item: 'Other', other: ' Whistle ', quantity: 2, reason: ' Lost ' })).toEqual({ kind: 'equipment', subject: 'Whistle', quantity: 2, reason: 'Lost' })
  })
  it('keeps the quantity inside what the server takes', () => {
    expect(equipmentBody({ item: 'Radio', other: '', quantity: 0, reason: 'x' }).quantity).toBe(1)
    expect(equipmentBody({ item: 'Radio', other: '', quantity: 99, reason: 'x' }).quantity).toBe(50)
  })
})

describe('the list of what was asked for', () => {
  it('an unsent request is "Pending sync", never the server\'s "Pending"', () => {
    const rows = requestRows([], [op('a', { kind: 'leave', subject: 'sick', starts_on: '2026-09-04', ends_on: '2026-09-04' })], 'leave')
    expect(rows[0]).toMatchObject({ title: 'Sick leave · 4 Sep 2026', chip: { label: 'Pending sync' }, queued: true })
    expect(rows[0]!.detail).toMatch(/not sent yet/)
  })
  it('a refused one carries the server\'s sentence', () => {
    const rows = requestRows([], [op('a', { kind: 'equipment', subject: 'Torch', quantity: 1 }, 'refused', { status: 422, code: 'validation', message: 'The reason is too long.' })], 'equipment')
    expect(rows[0]).toMatchObject({ chip: { label: 'Not accepted', tone: 'red-500' }, note: 'The reason is too long.' })
  })
  it('a confirmed op is dropped: the server\'s row stands for it', () => {
    expect(requestRows([], [op('a', { kind: 'leave', subject: 'sick' }, 'confirmed')], 'leave')).toEqual([])
  })
  it('each list holds only its own kind, the phone\'s first', () => {
    const items = [request({ id: 1 }), request({ id: 2, kind: 'equipment', subject: 'Radio', quantity: '2', starts_on: null, ends_on: null, days: null, status: 'approved', decision_note: 'Collect from stores' })]
    const leaveRows = requestRows(items, [op('a', { kind: 'leave', subject: 'unpaid', starts_on: '2026-10-01', ends_on: '2026-10-02' })], 'leave')
    expect(leaveRows.map((r) => r.key)).toEqual(['qa', 'r1'])
    expect(leaveRows[1]).toMatchObject({ title: 'Vacation leave · 15 Sep 2026 – 17 Sep 2026', chip: { label: 'Pending' } })
    const kit = requestRows(items, [], 'equipment')
    expect(kit).toHaveLength(1)
    expect(kit[0]).toMatchObject({ title: 'Radio ×2', chip: { label: 'Approved', tone: 'green-400' }, note: 'Collect from stores' })
  })
})

describe('open shifts: hours, never pay', () => {
  const shift = (over: Partial<OpenShift>): OpenShift => ({ id: 9, post: { id: 1, name: 'Service Gate' }, site: { id: 1, name: 'Phoenix Park Village 1', timezone: 'America/Jamaica' }, rostered_start: '2026-09-06T00:00:00Z', rostered_end: '2026-09-06T12:00:00Z', hours: '12', claim_status: null as unknown as string, rest_warning: false, ...over })

  it('names the day relative to today in Jamaica', () => {
    expect(relativeDay('2026-09-04T23:00:00Z', AT)).toBe('Today, 4 Sep 2026')
    expect(relativeDay('2026-09-06T00:00:00Z', AT)).toBe('Tomorrow, 5 Sep 2026') // 7 PM on the 5th in Kingston
    expect(relativeDay('2026-09-07T18:00:00Z', AT)).toBe('7 Sep 2026')
  })
  it('a card carries hours and a window, soonest first', () => {
    const cards = openShiftCards([shift({ id: 2, rostered_start: '2026-09-08T12:00:00Z', rostered_end: '2026-09-08T18:30:00Z', hours: '6.5' }), shift({ id: 1 })], AT)
    expect(cards.map((c) => c.id)).toEqual([1, 2])
    expect(cards[0]).toMatchObject({ hours: '12h', window: '7:00 PM–7:00 AM', claim: 'none', restWarning: false })
    expect(cards[1]!.hours).toBe('6.5h')
  })
  /**
   * THE ONE CLOCK — an open shift is the one list whose rows may belong to another site, so the server puts a
   * zone on EVERY row. The suite also runs with TZ=Asia/Tokyo: nothing below can be the handset's clock.
   */
  it('reads each row on ITS OWN site\'s clock, not the session\'s and not the handset\'s', () => {
    // The same instant: 7 PM at a Kingston site, 8 PM at a New York one (September — daylight saving).
    const kingston = openShiftCards([shift({ id: 1 })], AT)[0]!
    const newYork = openShiftCards([shift({ id: 2, site: { id: 2, name: 'Harbour View', timezone: 'America/New_York' } })], AT)[0]!
    expect(kingston).toMatchObject({ day: 'Tomorrow, 5 Sep 2026', window: '7:00 PM–7:00 AM' })
    expect(newYork).toMatchObject({ day: 'Tomorrow, 5 Sep 2026', window: '8:00 PM–8:00 AM' })

    // The session's own site moving does not move a row that named its own.
    setSiteZone('Asia/Tokyo')
    expect(openShiftCards([shift({ id: 1 })], AT)[0]).toMatchObject({ window: '7:00 PM–7:00 AM' })
    // A row with no zone — an older server — falls back to the session's site rather than the handset's.
    expect(openShiftCards([shift({ id: 3, site: { id: 3, name: 'No zone', timezone: '' } })], AT)[0]!.window).toBe('9:00 AM–9:00 PM')
    forgetSiteZone()
  })

  it('shows this guard\'s own claim and the rest warning as sent', () => {
    expect(openShiftCards([shift({ claim_status: 'pending', rest_warning: true })], AT)[0]).toMatchObject({ claim: 'pending', restWarning: true })
  })
  it('no card, in data or on screen, carries a rate or an overtime figure', () => {
    const card = openShiftCards([shift({})], AT)[0]!
    expect(JSON.stringify(card)).not.toMatch(/OT|1\.5|×|\$/)
    const dir = path.resolve(__dirname, '..')
    const live = readFileSync(path.join(dir, 'screens/LeaveDutyScreen.tsx'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    expect(live).not.toMatch(/['"`>][^'"`<]*(\bOT\b|1\.5×|overtime rate|\$\d)/)
  })
  it('a refusal is said in a guard\'s words', () => {
    expect(claimRefusal('shift_not_open', 'x')).toMatch(/Someone else/)
    expect(claimRefusal('shift_started', 'x')).toMatch(/already started/)
    expect(claimRefusal(undefined, 'The server said no.')).toBe('The server said no.')
  })
  it('a claim is never queued and the list is never cached', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'useOpenShifts.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    expect(source).not.toMatch(/submit\(|useOfflineCache|outbox/)
  })
})

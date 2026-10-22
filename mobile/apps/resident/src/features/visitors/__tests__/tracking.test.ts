/**
 * resident-app-08 · WHAT THE VISITORS TAB SAYS, AND ONLY WHAT THE SERVER STANDS BEHIND (M42 §5 #7, M47 §5).
 *
 * The gate tells a resident when, where and by whom a visitor was let in — and nothing about leaving. So:
 *   - On site now is everyone admitted TODAY at the estate, newest first, its badge counted from the real arrival —
 *     then everyone still expected today, as Home lists them (M47 §5.3: "if Home can list Yahya, so can this tab");
 *   - Recent activity says "Entered · Main Gate · by Guard Whyte" at the real time, and never "Exited";
 *   - "Guest of" names the member who issued the pass, not whoever is signed in (M47 §5.5);
 *   - History is the past passes, each saying what became of it and on which day;
 *   - in fidelity mode the hook hands over the design's own sample, word for word.
 */

import { act, renderHook } from '@testing-library/react-native'
import { trackingFixture } from '@/fidelity/visitors'
import { expectedToday } from '@/features/home/homeLogic'
import { groupPasses, type Pass } from '../passes'
import { enteredWords, stayWords, trackingOf, whoOf } from '../tracking'

// 2:59 PM on Wednesday 7 October 2026, at the estate (Kingston, UTC−5) — the moment the board's "45 min" is true.
const AT = new Date('2026-10-07T19:59:00Z')

let mockFidelity = false
jest.mock('@gs/core', () => ({
  get isFidelity() {
    return mockFidelity
  },
  now: () => new Date('2026-10-07T19:59:00Z'),
}))
const mockList = { status: 'ready', errorMessage: null, refreshing: false, refresh: jest.fn(), groups: { now: [], upcoming: [], history: [] } as ReturnType<typeof groupPasses> }
jest.mock('../useVisitors', () => ({ useVisitorList: () => mockList }))

import { useVisitorTracking } from '../useVisitorTracking'

const pass = (id: number, over: Partial<Pass>): Pass => ({
  id,
  pass_id: `p-${id}`,
  category: 'single',
  visitor_name: `Visitor ${id}`,
  visitor_phone: null,
  purpose: 'Family visit',
  vehicle_plate: null,
  no_vehicle: true,
  valid_from: '2026-10-07T13:00:00Z',
  valid_to: '2026-10-08T03:00:00Z',
  single_use: true,
  status: 'active',
  code: `PPV2-${id}`,
  token: 'a.b',
  share_count: 0,
  used_at: null,
  cancelled_at: null,
  last_admitted_at: null,
  last_admitted_post: null,
  last_admitted_by: null,
  host: 'Andrea Fletcher',
  ...over,
})

const PASSES: Pass[] = [
  // Admitted today at 2:14 PM on a single-use pass, at the Main Gate by Delroy Whyte: used.
  pass(1, { visitor_name: 'Marcia James', status: 'used', used_at: '2026-10-07T19:14:00Z', last_admitted_at: '2026-10-07T19:14:00Z', last_admitted_post: 'Main Gate', last_admitted_by: 'Delroy Whyte' }),
  // A contractor let in at 10:02 AM today on a pass that runs all week: still active. The gate said neither where nor who.
  pass(2, { visitor_name: 'TropiClean Pest Control', category: 'contractor', single_use: false, valid_to: '2026-10-10T23:00:00Z', last_admitted_at: '2026-10-07T15:02:00Z' }),
  // A courier admitted YESTERDAY at 4:00 PM: not on site today.
  pass(3, { visitor_name: 'Swift Couriers', category: 'delivery', status: 'used', valid_from: '2026-10-06T19:00:00Z', valid_to: '2026-10-06T23:00:00Z', used_at: '2026-10-06T21:00:00Z', last_admitted_at: '2026-10-06T21:00:00Z' }),
  // Expected this evening, 6:00 to 8:00 PM, and sent by ANOTHER member of the household: not arrived yet.
  pass(4, { visitor_name: 'Keisha Grant', purpose: 'Friend', valid_from: '2026-10-07T23:00:00Z', valid_to: '2026-10-08T01:00:00Z', host: 'Hassam Ahmed' }),
  // Cancelled this morning.
  pass(5, { visitor_name: 'Kevin Chambers', status: 'cancelled', cancelled_at: '2026-10-07T14:00:00Z' }),
  // Ran out on Monday, never used.
  pass(6, { visitor_name: 'Delroy Simms', status: 'expired', valid_from: '2026-10-05T19:00:00Z', valid_to: '2026-10-05T23:00:00Z' }),
  // The resident's own e-pass, admitted today: not a visitor.
  pass(7, { visitor_name: 'Andrea Fletcher', category: 'resident', single_use: false, last_admitted_at: '2026-10-07T12:30:00Z' }),
  // Expected TOMORROW: not today's.
  pass(8, { visitor_name: 'Omar Brown', valid_from: '2026-10-08T15:00:00Z', valid_to: '2026-10-08T17:00:00Z' }),
]

describe('the badge counts from the real arrival, in the board’s words', () => {
  it('"45 min" under the hour, "3h 12m" from it', () => {
    expect(stayWords('2026-10-07T19:14:00Z', AT)).toBe('45 min')
    expect(stayWords('2026-10-07T15:02:00Z', new Date('2026-10-07T18:14:00Z'))).toBe('3h 12m')
    expect(stayWords('2026-10-07T18:59:00Z', AT)).toBe('1h 0m')
    // A gate clock a minute ahead of the phone's never prints a negative stay.
    expect(stayWords('2026-10-07T20:00:00Z', AT)).toBe('0 min')
  })
})

describe('whose visitor it is', () => {
  it('a guest names the host; a contractor or a courier names the pass', () => {
    expect(whoOf({ category: 'single' }, '  Andrea   Fletcher ')).toBe('Guest of Andrea Fletcher')
    expect(whoOf({ category: 'recurring' }, 'Andrea Fletcher')).toBe('Guest of Andrea Fletcher')
    expect(whoOf({ category: 'contractor' }, 'Andrea Fletcher')).toBe('Contractor pass')
    expect(whoOf({ category: 'delivery' }, 'Andrea Fletcher')).toBe('Delivery pass')
    expect(whoOf({ category: 'single' }, ' ')).toBeNull()
  })
})

describe('where they came in, and who let them in', () => {
  it('"Entered · Main Gate · by Guard Whyte", the guard by surname, and only what the gate said', () => {
    expect(enteredWords({ last_admitted_post: 'Main Gate', last_admitted_by: 'Delroy  Whyte ' })).toBe('Entered · Main Gate · by Guard Whyte')
    expect(enteredWords({ last_admitted_post: 'Back Gate', last_admitted_by: null })).toBe('Entered · Back Gate')
    expect(enteredWords({ last_admitted_post: null, last_admitted_by: 'Palmer' })).toBe('Entered · by Guard Palmer')
    // An older server says neither.
    expect(enteredWords({})).toBe('Entered')
  })
})

describe('the three lists, from the household’s passes', () => {
  const t = trackingOf(groupPasses(PASSES, AT), AT)

  it('On site now: everyone admitted today, newest first, then everyone still expected today, not the e-pass, yesterday or tomorrow', () => {
    expect(t.onSite).toEqual([
      { key: 's:1', passId: '1', initials: 'MJ', name: 'Marcia James', line: 'Arrived 2:14 PM · Guest of Andrea Fletcher', badge: '45 min' },
      { key: 's:2', passId: '2', initials: 'TP', name: 'TropiClean Pest Control', line: 'Arrived 10:02 AM · Contractor pass', badge: '4h 57m' },
      { key: 'e:4', passId: '4', initials: 'KG', name: 'Keisha Grant', line: 'Friend', badge: expect.stringMatching(/^6:00 – 8:00 PM/) },
    ])
  })

  it('M47 §5.3, the user’s test: whatever Home shows under "Expected today" is under "On site now"', () => {
    // Home reads the household's ACTIVE passes (visitor_passes.index?status=active); this tab reads them all.
    const home = expectedToday(PASSES.filter((p) => p.status === 'active'), AT)
    expect(home.length).toBeGreaterThan(0)
    for (const v of home) expect(t.onSite.some((card) => card.passId === v.id)).toBe(true)
    // An expected visitor reads exactly as Home draws them: what the visit is for, and the window.
    const keisha = home.find((v) => v.name === 'Keisha Grant')!
    expect(t.onSite.find((card) => card.passId === keisha.id)).toMatchObject({ line: keisha.detail, badge: keisha.when })
  })

  it('a visitor the gate has let in today is not ALSO listed as expected', () => {
    expect(t.onSite.filter((card) => card.passId === '2')).toHaveLength(1)
  })

  it('"Guest of" is the member who sent the pass, never whoever is signed in', () => {
    const sentByHassam = pass(9, { visitor_name: 'Yahya Khan', status: 'used', used_at: '2026-10-07T19:30:00Z', last_admitted_at: '2026-10-07T19:30:00Z', host: 'Hassam Ahmed' })
    expect(trackingOf(groupPasses([sentByHassam], AT), AT).onSite[0]?.line).toBe('Arrived 2:30 PM · Guest of Hassam Ahmed')
  })

  it('Recent activity: an "Entered" row per admission at its real time, with the gate and the guard when the gate said them, never "Exited"', () => {
    expect(t.activity).toEqual([
      { key: 'a:1', passId: '1', entered: true, name: 'Marcia James', line: 'Entered · Main Gate · by Guard Whyte', time: '2:14 PM' },
      { key: 'a:2', passId: '2', entered: true, name: 'TropiClean Pest Control', line: 'Entered', time: '10:02 AM' },
    ])
    for (const row of t.activity) expect(row.line).not.toMatch(/Exited/)
  })

  it('History: the past passes, what became of each, and the day', () => {
    expect(t.history.map((c) => [c.name, c.line, c.badge])).toEqual([
      ['Marcia James', 'Arrived 2:14 PM · Guest of Andrea Fletcher', 'Today'],
      ['Kevin Chambers', 'Cancelled · Guest of Andrea Fletcher', 'Today'],
      ['Swift Couriers', 'Arrived 4:00 PM · Delivery pass', '6 Oct 2026'],
      ['Delroy Simms', 'Not used · Guest of Andrea Fletcher', '5 Oct 2026'],
    ])
  })

  it('a server too old to say who sent a pass: "Guest of" is left out rather than guessed', () => {
    const { host: _, ...older } = PASSES[0]!
    expect(trackingOf(groupPasses([older as Pass], AT), AT).onSite[0]?.line).toBe('Arrived 2:14 PM')
  })

  it('the estate’s day: a visitor let in at 11:30 PM yesterday is not "on site now" at 12:10 AM', () => {
    const late = pass(8, { visitor_name: 'Night Visitor', status: 'used', used_at: '2026-10-08T04:30:00Z', last_admitted_at: '2026-10-08T04:30:00Z' })
    const justAfterMidnight = new Date('2026-10-08T05:10:00Z')
    expect(trackingOf(groupPasses([late], justAfterMidnight), justAfterMidnight).onSite).toEqual([])
  })
})

describe('the hook', () => {
  afterEach(() => {
    mockFidelity = false
  })

  it('in fidelity mode hands over the design’s own sample, word for word, and draws no History', async () => {
    mockFidelity = true
    const { result } = await renderHook(() => useVisitorTracking())
    expect(result.current.status).toBe('ready')
    expect(result.current.onSite.map((c) => [c.initials, c.name, c.line, c.badge])).toEqual(trackingFixture.onSite.map((v) => [v.initials, v.name, v.detail, v.chip]))
    expect(result.current.activity.map((r) => [r.entered, r.name, r.line, r.time])).toEqual(trackingFixture.activity.map((r) => [r.entered, r.name, r.detail, r.time]))
    expect(result.current.onSite[0]?.line).toBe('Arrived 2:14 PM · Guest of Andrea Fletcher')
    expect(result.current.activity[1]).toMatchObject({ entered: false, line: 'Exited · Main Gate · by Guard Palmer', time: '11:48 AM' })
    // The sample is no real pass, so nothing on it opens one.
    expect([...result.current.onSite, ...result.current.activity].every((x) => x.passId === null)).toBe(true)
    expect(result.current.history).toEqual([])
  })

  it('live, it works the lists out from the passes, naming each pass’s own host', async () => {
    mockList.groups = groupPasses(PASSES, AT)
    const { result } = await renderHook(() => useVisitorTracking())
    await act(async () => undefined)
    expect(result.current.onSite.map((c) => c.name)).toEqual(['Marcia James', 'TropiClean Pest Control', 'Keisha Grant'])
    expect(result.current.activity.every((r) => r.entered && r.line.startsWith('Entered'))).toBe(true)
    expect(result.current.history).toHaveLength(4)
    expect(result.current.refresh).toBe(mockList.refresh)
  })
})

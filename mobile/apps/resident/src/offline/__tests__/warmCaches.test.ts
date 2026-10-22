/**
 * WHAT THE SCREENS DRAW WITH NO SIGNAL IS SAVED BEFORE THEY ARE FIRST OPENED (M11 §1.1; offline/warmCaches.ts).
 *
 * The M11 §1.7 airplane test (26 Sep): signed in, Home only, then no signal — and Visitors said "That didn't load",
 * because a screen saves its copy only once it has been opened online. Proved here:
 *   - every row is its screen's own: the same key, from the same query hook, with the same argument, saved in the
 *     same form (the raw answer, or the Dues screens' answer-with-its-moment) — so what is warmed is exactly what
 *     the screen would have saved;
 *   - each read this level takes is asked and saved under its key; what the level does not read is not asked;
 *     the Dues rows are asked for the household saved first, and skipped while there is none;
 *   - nothing is asked offline, signed out, or with the app in the background (the background task only sends);
 *   - a sign-out part-way saves nothing more;
 *   - it waits for the first screen's own reads, and a flapping connection asks again at most every five minutes.
 */

import { readdirSync, readFileSync, statSync } from 'fs'
import { join } from 'path'
import { AppState } from 'react-native'

type Action = { endpoint: string; arg: unknown }
type State = { session: { status: string; demo: boolean; principal: { id: number } | null }; network: { online: boolean } }

const mockAsked: Action[] = []
let mockState: State
let mockOnAsk: (a: Action) => void = () => undefined
let mockHousehold: unknown = { household: { id: 9 } }
const mockFetchedAt = Date.parse('2026-09-26T03:00:00Z')

jest.mock('@gs/core/api/resident', () => {
  const names = ['meHousehold', 'visitorPassesIndex', 'householdsBalance', 'invoicesIndex', 'householdsStatement', 'ticketsIndex', 'meetingsIndex', 'electionsIndex', 'amenitiesIndex', 'bookingsIndex', 'membersIndex', 'vehiclesIndex', 'contactsIndex']
  const endpoints = Object.fromEntries(names.map((name) => [name, { initiate: (arg: unknown) => ({ endpoint: name, arg }) }]))
  return { residentApi: { endpoints } }
})
jest.mock('@/store', () => ({
  store: {
    getState: () => mockState,
    dispatch: (action: Action) => {
      mockAsked.push(action)
      mockOnAsk(action)
      const data = action.endpoint === 'meHousehold' ? mockHousehold : { items: [{ from: action.endpoint }] }
      return Promise.resolve({ data, fulfilledTimeStamp: mockFetchedAt })
    },
  },
}))

import { getStorage, readOfflineCache, saveOfflineCache } from '@gs/core/lib'
import { WARM_AFTER_MS, WARM_EVERY_MS, WARM_READS, resetWarm, scheduleWarm, warmCaches } from '../warmCaches'

let mockAppState = 'active'
Object.defineProperty(AppState, 'currentState', { configurable: true, get: () => mockAppState })

const signedIn = (): State => ({ session: { status: 'active', demo: false, principal: { id: 41 } }, network: { online: true } })
const flush = async () => { for (let i = 0; i < 60; i++) await Promise.resolve() }
const ALL = WARM_READS.map((r) => r.endpoint)

beforeEach(async () => {
  await getStorage().clearAll()
  mockAsked.length = 0
  mockState = signedIn()
  mockAppState = 'active'
  mockOnAsk = () => undefined
  mockHousehold = { household: { id: 9 } }
  resetWarm()
})
afterEach(() => jest.useRealTimers())

describe('every row is its screen’s own', () => {
  const FEATURES = join(__dirname, '..', '..', 'features')
  const files = (dir: string): string[] => readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === '__tests__' ? [] : files(path)
    return /\.tsx?$/.test(name) ? [path] : []
  })
  const sources = files(FEATURES).map((path) => readFileSync(path, 'utf8'))
  const pascal = (name: string) => name[0]!.toUpperCase() + name.slice(1)
  const ARG = { none: 'undefined', empty: '\\{\\}', household: '\\{ household: [^}]+\\}' } as const

  it.each(WARM_READS.map((r) => [r.key, r] as const))('%s', (_key, read) => {
    const hook = new RegExp(`const (\\w+) = residentApi\\.use${pascal(read.endpoint)}Query\\(${ARG[read.arg]},`, 'g')
    // Some screen calls this hook with this argument and saves ITS answer, in this form, under this key.
    const saves = (name: string) => (read.stamped ? `useStamped('${read.key}', ${name})` : `useOfflineCache('${read.key}', ${name}.data)`)
    const held = sources.some((src) => [...src.matchAll(hook)].some((m) => src.includes(saves(m[1]!))))
    expect(held).toBe(true)
  })
})

describe('warmCaches', () => {
  it('asks every read this level takes, one after another, and saves each under its screen’s key, in its form', async () => {
    expect(await warmCaches()).toBe(WARM_READS.length)
    expect(mockAsked.map((a) => a.endpoint)).toEqual(ALL)
    expect(mockAsked.find((a) => a.endpoint === 'visitorPassesIndex')!.arg).toEqual({})
    expect(mockAsked.find((a) => a.endpoint === 'householdsBalance')!.arg).toEqual({ household: 9 })
    expect(mockAsked.find((a) => a.endpoint === 'householdsStatement')!.arg).toEqual({ household: 9 })
    expect(readOfflineCache('passes.all')).toEqual({ items: [{ from: 'visitorPassesIndex' }] })
    // The Dues screens never show a saved figure as current: it is kept with the moment it was fetched.
    expect(readOfflineCache('dues.invoices')).toEqual({ value: { items: [{ from: 'invoicesIndex' }] }, savedAt: '2026-09-26T03:00:00.000Z' })
    for (const read of WARM_READS) expect(readOfflineCache(read.key)).toBeDefined()
  })

  it('does not ask what this level does not read (me.show.access, as the screens ask it)', async () => {
    const may = { household: false, household_edit: false, manage_members: false, visitor_passes: true, approvals: false, dues: false, payments: false, tickets: true, notices: true, meetings: false, elections: false, bookings: false }
    saveOfflineCache('me.show', { access: { may } })
    await warmCaches()
    expect(mockAsked.map((a) => a.endpoint)).toEqual(['visitorPassesIndex', 'ticketsIndex'])
    expect(readOfflineCache('members.index')).toBeUndefined()
  })

  it('with no household saved, the Dues reads that need one are not asked (nothing is asked for household "")', async () => {
    mockHousehold = { household: null }
    await warmCaches()
    expect(mockAsked.map((a) => a.endpoint)).toEqual(ALL.filter((e) => e !== 'householdsBalance' && e !== 'householdsStatement'))
  })

  it.each([
    ['offline', () => { mockState.network.online = false }],
    ['signed out', () => { mockState.session.status = 'signedOut'; mockState.session.principal = null }],
    ['in the background (the background task sends; it does not browse)', () => { mockAppState = 'background' }],
  ])('asks nothing %s', async (_why, make) => {
    make()
    expect(await warmCaches()).toBe(0)
    expect(mockAsked).toEqual([])
  })

  it('a sign-out part-way saves nothing more, not even the answer that was on its way', async () => {
    mockOnAsk = (a) => { if (a.endpoint === 'meetingsIndex') { mockState.session.status = 'signedOut'; mockState.session.principal = null } }
    const upTo = ALL.indexOf('meetingsIndex')
    expect(await warmCaches()).toBe(upTo)
    expect(readOfflineCache('tickets.all')).toBeDefined()
    expect(readOfflineCache('meetings.index')).toBeUndefined()
    expect(mockAsked.map((a) => a.endpoint)).toEqual(ALL.slice(0, upTo + 1))
  })
})

describe('scheduleWarm', () => {
  it('waits for the first screen’s own reads, then asks once; again only after five minutes; a new person at once', async () => {
    jest.useFakeTimers()
    scheduleWarm()
    jest.advanceTimersByTime(WARM_AFTER_MS - 1)
    await flush()
    expect(mockAsked).toEqual([])
    jest.advanceTimersByTime(1)
    await flush()
    expect(mockAsked).toHaveLength(WARM_READS.length)

    // The connection comes and goes: not asked again inside five minutes.
    mockAsked.length = 0
    scheduleWarm()
    jest.advanceTimersByTime(WARM_AFTER_MS)
    await flush()
    expect(mockAsked).toEqual([])

    jest.advanceTimersByTime(WARM_EVERY_MS)
    scheduleWarm()
    jest.advanceTimersByTime(WARM_AFTER_MS)
    await flush()
    expect(mockAsked).toHaveLength(WARM_READS.length)

    // Somebody else signs in on this phone: theirs is saved at once.
    mockAsked.length = 0
    mockState.session.principal = { id: 77 }
    scheduleWarm()
    jest.advanceTimersByTime(WARM_AFTER_MS)
    await flush()
    expect(mockAsked).toHaveLength(WARM_READS.length)
  })
})

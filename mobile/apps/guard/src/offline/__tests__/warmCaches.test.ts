/**
 * WHAT THE TABS DRAW WITH NO SIGNAL IS SAVED BEFORE THEY ARE FIRST OPENED (M11 §1.1; offline/warmCaches.ts).
 *
 * Each tab saved its copy only once it had been opened with signal: an officer who clocked in on the dashboard and
 * walked into a dead spot had no tour, and Patrol cannot take a scan without its checkpoints. Proved here:
 *   - every row is its tab's own: the same key, from the same query hook, with the same argument, and the fetch
 *     time beside it where the tab keeps one;
 *   - the site's rows (the tour, the orders) are asked for the saved shift's site, and not at all with no shift;
 *   - nothing is asked offline, signed out or in the background;
 *   - a shift at another site is warmed at once, however recently the last one was.
 */

import { readdirSync, readFileSync, statSync } from 'fs'
import { join } from 'path'
import { AppState } from 'react-native'

type Action = { endpoint: string; arg: unknown }
type State = { session: { status: string; principal: { reference: string } | null }; network: { online: boolean } }

const mockAsked: Action[] = []
let mockState: State
const mockFetchedAt = Date.parse('2026-09-26T03:00:00Z')

jest.mock('@gs/core/api/guard', () => {
  const names = ['patrolCheckpoints', 'ordersCurrent', 'presenceSummary', 'messagesIndex', 'incidentsMe', 'requestsIndex', 'guardsMe']
  const endpoints = Object.fromEntries(names.map((name) => [name, { initiate: (arg: unknown) => ({ endpoint: name, arg }) }]))
  return { guardApi: { endpoints } }
})
jest.mock('@/store', () => ({
  store: {
    getState: () => mockState,
    dispatch: (action: Action) => {
      mockAsked.push(action)
      return Promise.resolve({ data: { items: [{ from: action.endpoint }] }, fulfilledTimeStamp: mockFetchedAt })
    },
  },
}))

import { getStorage, readOfflineCache, saveOfflineCache } from '@gs/core/lib'
import { WARM_AFTER_MS, WARM_READS, resetWarm, scheduleWarm, warmCaches } from '../warmCaches'

let mockAppState = 'active'
Object.defineProperty(AppState, 'currentState', { configurable: true, get: () => mockAppState })

const onShiftAt = (site: number) => saveOfflineCache('shift.current', { shift: { id: 90, site: { id: site, name: 'Seaview' }, post: { id: 4, name: 'Main Gate' } } })
const flush = async () => { for (let i = 0; i < 60; i++) await Promise.resolve() }
const ALL = WARM_READS.map((r) => r.endpoint)

beforeEach(async () => {
  await getStorage().clearAll()
  mockAsked.length = 0
  mockState = { session: { status: 'active', principal: { reference: 'GS-0412' } }, network: { online: true } }
  mockAppState = 'active'
  resetWarm()
})
afterEach(() => jest.useRealTimers())

describe('every row is its tab’s own', () => {
  const FEATURES = join(__dirname, '..', '..', 'features')
  const files = (dir: string): string[] => readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === '__tests__' ? [] : files(path)
    return /\.tsx?$/.test(name) ? [path] : []
  })
  const sources = files(FEATURES).map((path) => readFileSync(path, 'utf8'))
  const pascal = (name: string) => name[0]!.toUpperCase() + name.slice(1)
  const ARG = { none: 'undefined', site: '\\{ site: [^}]+\\}' } as const

  it.each(WARM_READS.map((r) => [r.key, r] as const))('%s', (_key, row) => {
    const hook = new RegExp(`const (\\w+) = guardApi\\.use${pascal(row.endpoint)}Query\\(${ARG[row.arg]},`, 'g')
    const held = sources.some((src) =>
      [...src.matchAll(hook)].some((m) => {
        const q = m[1]!
        const saves = src.includes(`useOfflineCache('${row.key}', ${q}.data)`)
        const keepsTime = !row.at || src.includes(`useOfflineCache('${row.at}', ${q}.data === undefined ? undefined : ${q}.fulfilledTimeStamp)`)
        return saves && keepsTime
      }),
    )
    expect(held).toBe(true)
  })
})

describe('warmCaches', () => {
  it('on a shift: every row, the tour and the orders for the shift’s site, each saved: the fetch time beside it where the tab keeps one', async () => {
    onShiftAt(12)
    expect(await warmCaches()).toBe(WARM_READS.length)
    expect(mockAsked.map((a) => a.endpoint)).toEqual(ALL)
    expect(mockAsked.find((a) => a.endpoint === 'patrolCheckpoints')!.arg).toEqual({ site: 12 })
    expect(mockAsked.find((a) => a.endpoint === 'ordersCurrent')!.arg).toEqual({ site: 12 })
    expect(readOfflineCache('patrol.checkpoints')).toEqual({ items: [{ from: 'patrolCheckpoints' }] })
    expect(readOfflineCache('patrol.checkpoints.at')).toBe(mockFetchedAt)
    expect(readOfflineCache('guards.me.at')).toBe(mockFetchedAt)
  })

  it('with no shift saved, the site’s rows are not asked (nothing is asked for site "")', async () => {
    await warmCaches()
    expect(mockAsked.map((a) => a.endpoint)).toEqual(ALL.filter((e) => e !== 'patrolCheckpoints' && e !== 'ordersCurrent'))
  })

  it.each([
    ['offline', () => { mockState.network.online = false }],
    ['signed out', () => { mockState.session.status = 'signedOut'; mockState.session.principal = null }],
    ['in the background', () => { mockAppState = 'background' }],
  ])('asks nothing %s', async (_why, make) => {
    onShiftAt(12)
    make()
    expect(await warmCaches()).toBe(0)
    expect(mockAsked).toEqual([])
  })
})

it('a shift at another site is warmed at once: a new tour, however recently the last one was saved', async () => {
  jest.useFakeTimers()
  onShiftAt(12)
  scheduleWarm()
  jest.advanceTimersByTime(WARM_AFTER_MS)
  await flush()
  expect(mockAsked).toHaveLength(WARM_READS.length)

  // The same site a minute later: not again.
  mockAsked.length = 0
  scheduleWarm()
  jest.advanceTimersByTime(WARM_AFTER_MS)
  await flush()
  expect(mockAsked).toEqual([])

  // Clocked in somewhere else: the new site's tour is asked at once.
  onShiftAt(31)
  scheduleWarm()
  jest.advanceTimersByTime(WARM_AFTER_MS)
  await flush()
  expect(mockAsked.find((a) => a.endpoint === 'patrolCheckpoints')!.arg).toEqual({ site: 31 })
})

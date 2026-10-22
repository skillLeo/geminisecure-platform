/**
 * The guard's alert handlers (M11 Part C, DESIGN §2.4 A, §8.4): a raise is one coalesced pull; every other step
 * refetches that one alert, so a resolved alert reads closed on the open screen without a touch.
 */

const mockPull = jest.fn(() => Promise.resolve(null))
const mockInvalidate = jest.fn()

jest.mock('@/features/notify/poller', () => ({ pullDeltas: () => mockPull() }))
jest.mock('@/features/gate/knowledge', () => ({ gateKnowledge: { learnRevoked: jest.fn() } }))
jest.mock('@gs/core/live', () => ({ invalidateRecord: (cap: string, id: string) => mockInvalidate(cap, id) }))

import { alertHandlers, pullSoon, resetAlertHandlers } from '../handlers/alerts'
import { liveHandlers } from '../handlers'
import { RECORD_KINDS } from '../handlers/records'

const env = (t: string, i = '55') => ({ v: 1, s: 'c' as const, ep: '01J9ZC3K', q: 1, p: null, t, e: 'alert', i, m: 'ref' as const, o: null, at: '2026-10-02T14:03:11Z', sim: false })
const ctx = { channel: 'guard.pp.5', via: 'socket' as const, own: false }

const run = async (t: string, i?: string) => {
  // As the client does: the exact type's handlers, then the entity's wildcard ones.
  for (const h of [...(alertHandlers[t] ?? []), ...(alertHandlers['alert.*'] ?? [])]) await h(env(t, i) as never, ctx)
}

beforeEach(() => {
  jest.useFakeTimers()
  mockPull.mockClear()
  mockInvalidate.mockClear()
  resetAlertHandlers()
})

afterEach(() => {
  jest.useRealTimers()
})

it('a raise is one pull, however many arrive within 300 ms', async () => {
  for (let n = 0; n < 5; n++) await run('alert.raised', String(50 + n))
  expect(mockPull).not.toHaveBeenCalled()
  jest.advanceTimersByTime(300)
  expect(mockPull).toHaveBeenCalledTimes(1)
  // A raise is the pull's business: it is never also a one-record refetch.
  expect(mockInvalidate).not.toHaveBeenCalled()
})

it('a raise during a pull asks for one more pull after it, not two at once', async () => {
  let finish: () => void = () => undefined
  mockPull.mockImplementationOnce(() => new Promise((resolve) => { finish = () => resolve(null) }))
  pullSoon()
  jest.advanceTimersByTime(300)
  expect(mockPull).toHaveBeenCalledTimes(1)
  pullSoon()
  jest.advanceTimersByTime(300)
  expect(mockPull).toHaveBeenCalledTimes(1)
  finish()
  await Promise.resolve()
  await Promise.resolve()
  jest.advanceTimersByTime(300)
  expect(mockPull).toHaveBeenCalledTimes(2)
})

it.each(['alert.acknowledged', 'alert.dispatched', 'alert.en_route', 'alert.on_scene', 'alert.resolved', 'alert.false_alarm', 'alert.cancelled', 'alert.reach'])(
  '%s refetches that one alert wherever it is on screen, and pulls nothing',
  async (type) => {
    await run(type, '77')
    expect(mockInvalidate).toHaveBeenCalledWith('alerts', '77')
    jest.advanceTimersByTime(300)
    expect(mockPull).not.toHaveBeenCalled()
  },
)

it('every group is registered: the app hands the client its handlers by type', () => {
  const all = liveHandlers()
  // …and every other record's `.changed` / `.removed` (M11 §2, D-175): handlers/records.ts.
  const records = Object.keys(RECORD_KINDS).flatMap((entity) => [`${entity}.changed`, `${entity}.removed`])
  expect(Object.keys(all).sort()).toEqual(['alert.*', 'alert.raised', 'approval.*', 'gate.*', 'pass.cancelled', 'pass.used', ...records].sort())
})

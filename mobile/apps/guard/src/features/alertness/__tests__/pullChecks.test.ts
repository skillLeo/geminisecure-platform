/**
 * ONE PULL AT A TIME, WHOEVER ASKS (M11 A4).
 *
 * The watcher's 20-second timer and a guard pulling the dashboard down both ask `pullChecks()`. A
 * second ask while one pull is on the wire must share it, not send another; nothing is asked without
 * a session; and what the pull answered is the phone's one list of open checks.
 */

import { pullChecks } from '../AlertnessWatcher'
import { openChecks } from '../state'

let mockStatus = 'active'
let mockResolve: ((value: unknown) => void) | null = null
const mockPullDeltas = jest.fn(() => new Promise((resolve) => { mockResolve = resolve }))

jest.mock('@/features/notify/poller', () => ({ pullDeltas: () => mockPullDeltas() }))
jest.mock('@/features/duress/useDuress', () => ({ useActiveDuress: () => null }))
jest.mock('expo-router', () => ({ router: { push: jest.fn() }, usePathname: () => '/(tabs)' }))
jest.mock('@gs/core', () => ({ isFidelity: false, now: () => new Date('2026-09-20T18:00:00.000Z'), getStorage: jest.requireActual('@gs/core/lib').getStorage, jsonStore: jest.requireActual('@gs/core/lib').jsonStore }))
jest.mock('@/store', () => ({ store: { getState: () => ({ session: { status: mockStatus } }) }, useAppSelector: () => undefined }))

beforeEach(() => {
  mockPullDeltas.mockClear()
  mockResolve = null
  mockStatus = 'active'
  openChecks.set({ checks: [], offsetMs: 0, pulledAt: 0 })
})

it('a second ask while a pull is on the wire shares it: one request, not two', async () => {
  const first = pullChecks()
  const second = pullChecks()
  expect(mockPullDeltas).toHaveBeenCalledTimes(1)
  mockResolve?.({ server_time: '2026-09-20T18:00:05.000Z', alertness_checks: [{ check_id: 3, issued_at: '2026-09-20T17:59:00.000Z', respond_by: '2026-09-20T18:01:00.000Z' }] })
  await Promise.all([first, second])
  expect(openChecks.get().checks.map((c) => c.check_id)).toEqual([3])
  expect(openChecks.get().offsetMs).toBe(5_000)
  expect(openChecks.get().pulledAt).toBeGreaterThan(0)
})

it('once it has answered, the next ask sends a new pull', async () => {
  const first = pullChecks()
  mockResolve?.(null)
  await first
  const next = pullChecks()
  expect(mockPullDeltas).toHaveBeenCalledTimes(2)
  mockResolve?.(null)
  await next
})

it('no session, no pull', async () => {
  mockStatus = 'signedOut'
  await pullChecks()
  expect(mockPullDeltas).not.toHaveBeenCalled()
})

it('a failed pull changes nothing and is not left stuck on the wire', async () => {
  openChecks.set({ checks: [{ check_id: 1, issued_at: 'a', respond_by: 'b' }], offsetMs: 0, pulledAt: 1 })
  mockPullDeltas.mockImplementationOnce(() => Promise.reject(new Error('no signal')))
  await pullChecks()
  expect(openChecks.get().checks).toHaveLength(1)
  const again = pullChecks()
  expect(mockPullDeltas).toHaveBeenCalledTimes(2)
  mockResolve?.(null)
  await again
})

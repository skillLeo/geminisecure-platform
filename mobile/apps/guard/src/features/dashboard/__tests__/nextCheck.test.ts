/**
 * NEXT CHECK COMES FROM THE APP'S ONE PULL — THE DASHBOARD SENDS NONE OF ITS OWN (M11 A4).
 *
 * The dashboard used to mount `sync.pull` with no `since` — seven days of shifts, order versions,
 * messages, revoked passes and decisions — on every visit and again every minute, alongside the
 * alertness watcher's own pull every 20 s, to read ONE field: the open alertness checks. The server
 * sends every open check on every pull whatever `since` says (SyncController::pull, `outcome =
 * pending AND respond_by >= now`, no `since` filter), so the watcher's list is complete. Proved here:
 *   - mounting the dashboard asks for the shift and nothing else — `useSyncPullQuery` is never called;
 *   - before the watcher has pulled this run, NEXT CHECK is the copy kept on the phone (offline too);
 *   - after a pull it is the watcher's list, and the phone's copy follows it;
 *   - a check answered on the alertness screen leaves NEXT CHECK at once;
 *   - pulling the dashboard down refreshes the shift AND asks the watcher's pull, once.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, renderHook } from '@testing-library/react-native'
import { getStorage, jsonStore, timeCompact } from '@gs/core/lib'
import { openChecks } from '@/features/alertness/state'
import { useDashboard } from '../useDashboard'

const NOW = new Date('2026-09-20T18:00:00.000Z')
const SHIFT = {
  id: 41,
  status: 'on_duty',
  on_break: false,
  actual_start: '2026-09-20T12:00:00.000Z',
  actual_end: null,
  rostered_start: '2026-09-20T12:00:00.000Z',
  rostered_end: '2026-09-21T00:00:00.000Z',
  post: { id: 3, name: 'Main Gate' },
  site: { id: 'phoenixpark', name: 'Phoenix Park Village 1' },
  colleagues: [],
  orders_to_acknowledge: 0,
}

const mockSyncPull = jest.fn()
const mockRefetchShift = jest.fn(async () => undefined)
// ONE answer object, as RTK Query hands back the same `data` until it changes (a fresh object per
// render would make every useOfflineCache below save on every render, for ever).
let mockCurrent: unknown = null
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    useShiftsMeCurrentQuery: () => (mockCurrent ??= { data: { shift: mockShift }, fulfilledTimeStamp: 1, isLoading: false, error: undefined, refetch: mockRefetchShift }),
    useSyncPullQuery: (...args: unknown[]) => mockSyncPull(...args),
  },
}))
jest.mock('@gs/core/api', () => ({ isApiError: () => false }))
jest.mock('@gs/core', () => ({ ...jest.requireActual('@gs/core/lib'), isFidelity: false, fidelityScreenId: () => null, now: () => mockNow }))
jest.mock('@/fidelity/dashboard', () => ({ dashboardFixtures: {} }))
const mockPullChecks = jest.fn(async () => undefined)
jest.mock('@/features/alertness/AlertnessWatcher', () => ({ pullChecks: () => mockPullChecks() }))
jest.mock('@/store', () => ({
  useAppSelector: (select: (s: unknown) => unknown) => select({ session: { principal: { name: 'Marcus Whyte', siteName: 'Phoenix Park Village 1' } }, network: { online: true }, outbox: { ops: [], waiting: 0, refused: 0 } }),
}))

// Jest hoists the factories above everything but a `mock`-prefixed binding.
const mockShift = SHIFT
const mockNow = NOW

const check = (id: number, respondBy: string) => ({ check_id: id, issued_at: '2026-09-20T17:58:00.000Z', respond_by: respondBy })
const disk = () => jsonStore<ReturnType<typeof check>[]>(getStorage(), 'cache:dashboard.alertness_checks', 'v1')
const nextCheck = (cells: { value: string; label: string }[] | undefined) => cells?.find((c) => c.label === 'NEXT CHECK')?.value

beforeEach(async () => {
  jest.clearAllMocks()
  await getStorage().clearAll()
  openChecks.set({ checks: [], offsetMs: 0, pulledAt: 0 })
})

describe('the dashboard reads the open checks, it does not pull them', () => {
  it('asks for the shift and nothing else: no pull of its own on mount', async () => {
    const view = await renderHook(() => useDashboard())
    expect(view.result.current.status).toBe('ready')
    expect(mockSyncPull).not.toHaveBeenCalled()
  })

  it('before the watcher has pulled this run, NEXT CHECK is the copy kept on the phone', async () => {
    const kept = check(7, '2026-09-20T18:01:30.000Z')
    disk().set([kept])
    const view = await renderHook(() => useDashboard())
    expect(nextCheck(view.result.current.card?.cells)).toBe(timeCompact(kept.respond_by))
  })

  it('after a pull it is the watcher’s list, and the copy on the phone follows it', async () => {
    disk().set([check(7, '2026-09-20T18:01:30.000Z')])
    const view = await renderHook(() => useDashboard())
    const fresh = check(8, '2026-09-20T18:01:50.000Z')
    await act(async () => {
      openChecks.set({ checks: [fresh], offsetMs: 0, pulledAt: NOW.getTime() })
    })
    expect(nextCheck(view.result.current.card?.cells)).toBe(timeCompact(fresh.respond_by))
    expect(disk().get()).toEqual([fresh])
  })

  it('a check answered on the alertness screen leaves NEXT CHECK at once', async () => {
    const view = await renderHook(() => useDashboard())
    await act(async () => {
      openChecks.set({ checks: [check(9, '2026-09-20T18:01:00.000Z')], offsetMs: 0, pulledAt: NOW.getTime() })
    })
    expect(nextCheck(view.result.current.card?.cells)).toBe(timeCompact('2026-09-20T18:01:00.000Z'))
    await act(async () => {
      openChecks.settle(9)
    })
    expect(nextCheck(view.result.current.card?.cells)).toBe('—')
  })

  it('pulling it down refreshes the shift and asks the one pull: once each', async () => {
    const view = await renderHook(() => useDashboard())
    await act(async () => {
      view.result.current.refresh()
      await Promise.resolve()
    })
    expect(mockRefetchShift).toHaveBeenCalledTimes(1)
    expect(mockPullChecks).toHaveBeenCalledTimes(1)
    expect(mockSyncPull).not.toHaveBeenCalled()
  })

  it('and the source keeps it that way: no sync.pull hook in the dashboard', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'useDashboard.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
    expect(source).not.toMatch(/useSyncPullQuery|syncPull/)
  })
})

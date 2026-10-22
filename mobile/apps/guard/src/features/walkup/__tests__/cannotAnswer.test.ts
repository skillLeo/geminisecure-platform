/**
 * J-GATE-1 — a walk-up to a household nobody in which can answer in the app (household invites, stage 2).
 *
 * The server says so on the request (`household_can_answer: false`). The waiting screen then says it at once —
 * "Nobody in this household can answer in the app — call them, or apply the estate's policy." — offers the policy
 * straight away, and starts no wait: nothing is polled and nothing counts down. Absent (an older server): as today.
 */

import { renderHook } from '@testing-library/react-native'
import { CANNOT_ANSWER, useApprovalWait } from '../useApprovalWait'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/walk-up/waiting', useFocusEffect: jest.fn() }))
jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Success: 's', Error: 'e', Warning: 'w' } }))

const mockPolls: number[] = []
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    useGateApprovalsShowQuery: (_arg: unknown, options: { pollingInterval?: number }) => {
      mockPolls.push(options.pollingInterval ?? 0)
      return { data: undefined, error: undefined, refetch: jest.fn() }
    },
  },
}))
jest.mock('../useGateRecord', () => ({
  useGateRecord: () => ({ recorded: null, busy: false, saveError: null, admitOnApproval: jest.fn(), admitOnOwnDecision: jest.fn(), deny: jest.fn() }),
}))
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select({ network: { online: true }, session: { principal: { siteName: 'Phoenix Park' } } }) }))

const PARAMS = {
  id: '41',
  visitor: 'Marlon Fletcher',
  unit: 'Lot 47',
  household: 'Fletcher household',
  purpose: 'Delivery',
  plate: '',
  requestedAt: new Date(Date.now() - 5_000).toISOString(),
  respondBy: new Date(Date.now() + 175_000).toISOString(),
  skew: '0',
}

beforeEach(() => {
  mockPolls.length = 0
})

it('says at once that nobody can answer, offers the policy, and starts no wait', async () => {
  const { result } = await renderHook(() => useApprovalWait({ ...PARAMS, cannotAnswer: '1' }))

  expect(result.current.cannotAnswer).toBe(CANNOT_ANSWER)
  expect(result.current.cannotAnswer).toBe("Nobody in this household can answer in the app: call them, or apply the estate's policy.")
  expect(result.current.deciding).toBe(true)
  expect(result.current.noSignal).toBe(false)
  // Asked once for the record, never polled.
  expect(mockPolls.every((interval) => interval === 0)).toBe(true)
})

it('waits as today when the server did not say (an older server)', async () => {
  const { result } = await renderHook(() => useApprovalWait(PARAMS))

  expect(result.current.cannotAnswer).toBeNull()
  expect(result.current.deciding).toBe(false)
  expect(mockPolls.some((interval) => interval > 0)).toBe(true)
})

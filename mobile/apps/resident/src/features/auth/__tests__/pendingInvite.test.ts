/**
 * J-AUTH-1 — signing in with an invited address (household invites, stage 2).
 *
 * The invited address, proven by its code, IS the claim: the Pending screen says who invited them and that the
 * estate office confirms invited members with one tap. And a sign-in email the mail server refused is said in the
 * server's own words (`503 mail_unavailable`): nothing was sent, try again in a minute — never "sent".
 */

import { act, renderHook } from '@testing-library/react-native'
import { invitedByLine, invitedPendingWords } from '../claim'
import { useRequestCode } from '../useSignIn'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/log-in', useFocusEffect: jest.fn() }))

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: number | null } }
let mockAnswer: Answer = { data: { sent: true } }
const mockCalls: unknown[] = []
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useAuthOtpRequestMutation: () => [async (arg: unknown) => { mockCalls.push(arg); return mockAnswer }],
  },
}))
jest.mock('@gs/core/crypto/deviceUid', () => ({ deviceUid: async () => 'test-install' }))
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn() }))
jest.mock('../session', () => ({ lastEstate: { get: () => null, set: jest.fn() }, openSession: jest.fn() }))

beforeEach(() => {
  mockAnswer = { data: { sent: true } }
  mockCalls.length = 0
})

describe('the Pending screen, for an invited member', () => {
  it('says who invited them, and when, at the site', () => {
    expect(invitedByLine({ invited_by: 'Andrea Fletcher', invited_at: '2026-09-24T15:00:00Z' })).toBe('Andrea Fletcher · 24 Sep 2026')
    expect(invitedByLine({ invited_by: 'the estate office' })).toBe('the estate office')
  })

  it('draws no "Invited by" row for an ordinary claim', () => {
    expect(invitedByLine({})).toBeNull()
    expect(invitedByLine(null)).toBeNull()
    expect(invitedByLine({ invited_by: '   ' })).toBeNull()
  })

  it('says the office confirms invited members with one tap, and the app opens by itself', () => {
    expect(invitedPendingWords('Phoenix Park Village 1')).toEqual({
      title: 'Your invite is with the estate office',
      sub: 'Phoenix Park Village 1 confirms invited members with one tap. The app opens by itself when they do.',
    })
  })
})

describe('asking for a sign-in code', () => {
  it('says the server\'s own sentence when the code could not be emailed (503 mail_unavailable)', async () => {
    const said = 'The code could not be emailed just now: nothing was sent. Try again in a minute.'
    mockAnswer = { error: { status: 503, code: 'mail_unavailable', message: said, fieldErrors: {}, retryAfter: null } }

    const { result } = await renderHook(() => useRequestCode())
    let outcome: Awaited<ReturnType<typeof result.current.request>> | null = null
    await act(async () => {
      outcome = await result.current.request({ estate: 'phoenixpark', email: 'soft@example.com' })
    })

    expect(outcome).toEqual({ ok: false, field: null, message: said })
    expect(mockCalls).toEqual([{ estate: 'phoenixpark', channel: 'email', destination: 'soft@example.com' }])
  })
})

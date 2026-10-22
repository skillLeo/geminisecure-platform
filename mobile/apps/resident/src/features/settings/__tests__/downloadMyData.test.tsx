/**
 * "DOWNLOAD MY DATA" WORKS (board 33, M50 §4.3). The row's tap said the export was not available; now it fetches the
 * server's PDF of everything held about the person (`me.data_export`) — fresh, never a copy from before — and opens the
 * share sheet with it. Whatever stops it is said in the screen's own sheet, in the server's words where it gave some, and
 * never in the phone's grey dialog. Two fast taps fetch once.
 */

import { act, renderHook } from '@testing-library/react-native'
import { Alert } from 'react-native'
import { SUPPORT_CONTACT } from '@gs/core'
import { DOWNLOAD_NOT_SAVED, myDataFileName, usePrivacy } from '../useSettings'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn(), back: jest.fn() }, usePathname: () => '/settings/privacy', useFocusEffect: jest.fn() }))
jest.mock('expo-image', () => ({ Image: { clearMemoryCache: jest.fn(async () => true), clearDiskCache: jest.fn(async () => true) } }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true })), requestForegroundPermissionsAsync: jest.fn() }))
jest.mock('@gs/core/notify', () => ({ notificationPermission: jest.fn(async () => 'granted') }))
jest.mock('@/features/auth/session', () => ({ closeSession: jest.fn(async () => undefined) }))
jest.mock('@/offline/runtime', () => ({ drain: jest.fn(async () => undefined) }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

type Got = { ok: true; uri: string; fromPhone: boolean } | { ok: false; why: 'unsupported' } | { ok: false; why: 'failed'; error: { message: string } }
let mockGot: Got = { ok: true, uri: 'file:///cache/api-downloads/my-data/GeminiSecure-my-data.pdf', fromPhone: false }
const mockDownload = jest.fn(async (_input: unknown): Promise<Got> => mockGot)
jest.mock('@gs/core/api', () => ({ ...jest.requireActual('@gs/core/api'), downloadFile: (input: unknown) => mockDownload(input) }))
const mockShare = jest.fn(async (_uri: string, _options?: unknown) => undefined)
let mockCanShare = true
jest.mock('expo-sharing', () => ({ isAvailableAsync: async () => mockCanShare, shareAsync: (uri: string, options?: unknown) => mockShare(uri, options) }))

const settle = async () => {
  await act(async () => {
    for (let i = 0; i < 8; i++) await Promise.resolve()
    await new Promise((resolve) => setTimeout(resolve, 5))
  })
}

let alert: jest.SpyInstance
beforeEach(() => {
  alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)
  mockGot = { ok: true, uri: 'file:///cache/api-downloads/my-data/GeminiSecure-my-data.pdf', fromPhone: false }
  mockCanShare = true
  mockDownload.mockClear()
  mockShare.mockClear()
  mockState.network.online = true
})
afterEach(() => {
  expect(alert).not.toHaveBeenCalled()
  alert.mockRestore()
})

it("fetches the server's file fresh, never a copy from before, and opens the share sheet with it", async () => {
  const { result } = await renderHook(() => usePrivacy())
  await act(async () => result.current.download.run())
  await settle()

  expect(mockDownload).toHaveBeenCalledWith({ path: '/me/data-export', folder: 'my-data', name: expect.stringMatching(/^GeminiSecure-my-data-\d{4}-\d{2}-\d{2}\.pdf$/), online: true, fresh: true })
  expect(mockShare).toHaveBeenCalledWith('file:///cache/api-downloads/my-data/GeminiSecure-my-data.pdf', expect.objectContaining({ mimeType: 'application/pdf' }))
  expect(result.current.download.busy).toBe(false)
  expect(result.current.download.problem).toBeNull()
})

it("says the server's own sentence when it refused, in the screen's sheet", async () => {
  mockGot = { ok: false, why: 'failed', error: { message: 'Too many requests. Try again in a minute.' } }
  const { result } = await renderHook(() => usePrivacy())
  await act(async () => result.current.download.run())
  await settle()

  expect(result.current.download.problem).toBe('Too many requests. Try again in a minute.')
  expect(mockShare).not.toHaveBeenCalled()
  await act(async () => result.current.download.clearProblem())
  expect(result.current.download.problem).toBeNull()
})

it('a phone that cannot keep or share a file says so: with the way to ask for a copy', async () => {
  for (const setUp of [() => (mockGot = { ok: false, why: 'unsupported' }), () => (mockCanShare = false)]) {
    setUp()
    const { result } = await renderHook(() => usePrivacy())
    await act(async () => result.current.download.run())
    await settle()
    expect(result.current.download.problem).toBe(DOWNLOAD_NOT_SAVED)
  }
  expect(DOWNLOAD_NOT_SAVED).toContain(SUPPORT_CONTACT.email)
  expect(DOWNLOAD_NOT_SAVED).not.toMatch(/not available/i)
})

it('two fast taps fetch once', async () => {
  const { result } = await renderHook(() => usePrivacy())
  await act(async () => {
    result.current.download.run()
    result.current.download.run()
  })
  await settle()
  expect(mockDownload).toHaveBeenCalledTimes(1)
})

it("names the file by the estate's calendar, as the server does", () => {
  // 10 PM on 8 October in Kingston is 03:00 UTC on the 9th.
  expect(myDataFileName(new Date('2026-10-09T03:00:00Z'))).toBe('GeminiSecure-my-data-2026-10-08.pdf')
  expect(myDataFileName(new Date('2026-01-05T15:00:00Z'))).toBe('GeminiSecure-my-data-2026-01-05.pdf')
})

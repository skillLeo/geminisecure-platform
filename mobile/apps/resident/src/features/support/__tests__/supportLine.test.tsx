/**
 * "CALL GEMINI SECURITY" ONLY WITH A NUMBER BEHIND IT (the user, 8 Oct 2026): while the console has none, Help & support
 * has no call row at all — no placeholder number, no button that rings nothing; once it has one, a tap calls exactly it.
 */

import { act, renderHook } from '@testing-library/react-native'
import { Linking } from 'react-native'
import { useHelp } from '@/features/settings/useSettings'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn(), back: jest.fn() }, usePathname: () => '/settings/help', useFocusEffect: jest.fn() }))
jest.mock('expo-image', () => ({ Image: { clearMemoryCache: jest.fn(), clearDiskCache: jest.fn() } }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true })), requestForegroundPermissionsAsync: jest.fn() }))
jest.mock('@gs/core/notify', () => ({ notificationPermission: jest.fn(async () => 'granted') }))
jest.mock('@/features/auth/session', () => ({ closeSession: jest.fn(async () => undefined) }))
jest.mock('@/offline/runtime', () => ({ drain: jest.fn(async () => undefined) }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

let mockContact: unknown = undefined
jest.mock('@gs/core/api/resident', () => {
  const actual = jest.requireActual('@gs/core/api/resident')
  return { residentApi: { ...actual.residentApi, useSupportContactQuery: () => ({ data: mockContact }) } }
})

beforeEach(() => {
  mockContact = undefined
  jest.spyOn(Linking, 'openURL').mockResolvedValue(true)
})

it('has no call row while the console has no number, not a placeholder, not a button that rings nothing', async () => {
  mockContact = { company: 'Gemini Security', phone: null, phone_display: null, email: 'gemisecja@gmail.com' }
  const { result } = await renderHook(() => useHelp())
  expect(result.current.contacts.call).toBeNull()
  expect(result.current.contacts.email).toBe('gemisecja@gmail.com')
})

it('once the console saves one, offers "Call Gemini Security" and calls exactly that number', async () => {
  mockContact = { company: 'Gemini Security', phone: '+18765550123', phone_display: '+1 876 555 0123', email: 'gemisecja@gmail.com' }
  const { result } = await renderHook(() => useHelp())
  expect(result.current.contacts.call).toBe('Call Gemini Security')
  let said: string | null = 'not called'
  await act(async () => {
    said = await result.current.callSupport()
  })
  expect(Linking.openURL).toHaveBeenCalledWith('tel:+18765550123')
  expect(said).toBeNull()
})

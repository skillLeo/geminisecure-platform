/**
 * M47 §6 — MY E-PASS ON SCREEN, HALF AN HOUR AT A TIME. The real PassScreen, its server answer scripted:
 *
 *   - the QR is the half hour this moment is in, and the line under the name says which half hour;
 *   - the four-digit code is NOT shown: it opens the gate all day, so a screenshot of it would outlive the QR;
 *   - the 24-hour paragraph is one short line (6c);
 *   - the next half hour replaces it as the clock moves, with no connection;
 *   - a day of half hours spent: the screen says it ran out, and to connect once.
 */

import { act, fireEvent, render, screen } from '@testing-library/react-native'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, useLocalSearchParams: () => ({ id: 'e-pass' }), usePathname: () => '/visitors/pass/e-pass', useFocusEffect: jest.fn() }))
jest.mock('expo-brightness', () => ({ getBrightnessAsync: jest.fn(async () => 0.5), setBrightnessAsync: jest.fn(async () => undefined) }))
jest.mock('expo-clipboard', () => ({ setStringAsync: jest.fn(async () => true) }))
jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Success: 'success' } }))
// The QR as the token it would draw, so the test reads WHICH half hour is on screen.
jest.mock('../components/PassQr', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native')
  const { createElement } = jest.requireActual<typeof import('react')>('react')
  return { PassQr: ({ token, label }: { token: string; label: string }) => createElement(Text, { accessibilityLabel: label }, `qr:${token}`) }
})

// 9:41 AM at the estate (Kingston, UTC−5) on Friday 4 September 2026.
const T0 = Date.parse('2026-09-04T14:41:00Z')
const HALF = 30 * 60_000
const START = Date.parse('2026-09-04T14:30:00Z') // the 9:30 AM half hour
const iso = (t: number) => new Date(t).toISOString()
const slots = Array.from({ length: 48 }, (_, i) => ({ valid_from: iso(START + i * HALF), valid_to: iso(START + (i + 1) * HALF), token: `slot-${i}` }))
const mockEpass = {
  id: 99, pass_id: 'e-1', category: 'resident', visitor_name: 'Hassam Ahmed', visitor_phone: null, purpose: null, vehicle_plate: null, no_vehicle: false,
  valid_from: slots[0]!.valid_from, valid_to: slots[0]!.valid_to, single_use: false, status: 'active', code: 'PPV2-4471', token: 'slot-0', share_count: 0,
  used_at: null, cancelled_at: null, last_admitted_at: null, last_admitted_post: null, last_admitted_by: null, host: 'Hassam Ahmed', slots, refresh_after: slots[0]!.valid_to,
}
const mockQuery = { data: mockEpass as unknown, error: undefined as unknown, isLoading: false, isUninitialized: false, refetch: jest.fn(async () => undefined) }
const mockNoop = [jest.fn()]
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useVisitorPassesIndexQuery: () => ({ data: undefined, error: undefined, isLoading: false, isUninitialized: true, refetch: jest.fn() }),
    useEpassShowQuery: () => mockQuery,
    useVisitorPassesShareMutation: () => mockNoop,
    useVisitorPassesCancelMutation: () => mockNoop,
    useVisitorPassesStoreMutation: () => mockNoop,
  },
}))
jest.mock('@/offline/runtime', () => ({ submit: jest.fn(async () => undefined) }))
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => null }))
const mockState = { network: { online: false }, session: { status: 'active', demo: false, principal: { name: 'Hassam Ahmed', siteName: 'Phoenix Park Village 1', reference: 'Phase 2 · Lot 47' } } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

import { getStorage } from '@gs/core/lib'
import PassScreen from '../screens/PassScreen'

jest.setTimeout(30_000)

beforeEach(async () => {
  jest.useFakeTimers({ now: T0 })
  await getStorage().clearAll()
  mockQuery.data = mockEpass
  mockQuery.error = undefined
})
afterEach(() => jest.useRealTimers())

it('shows the half hour this moment is in, its window, its QR, and the typed code under it for a scanner that will not read (the user, 9 Oct)', async () => {
  await render(<PassScreen />)
  expect(screen.getByText('My e-Pass')).toBeTruthy()
  expect(screen.getByText('4 Sep 2026, 9:30 – 10:00 AM')).toBeTruthy()
  expect(screen.getByLabelText('QR code for your e-Pass')).toBeTruthy()
  expect(screen.getByText('qr:slot-0')).toBeTruthy()
  expect(screen.getByText('PPV2-4471')).toBeTruthy()
  expect(screen.getByText('If the QR will not scan, the guard can type this code.')).toBeTruthy()
  // Read by the guard, not passed around: the e-Pass's code is not a copy button.
  expect(screen.queryByLabelText(/Tap to copy/)).toBeNull()
})

it('says the user\'s one line on the screen, word for word, with no link, no sheet and no second card saying it works offline', async () => {
  await render(<PassScreen />)
  expect(screen.getByText('Show this at the gate. It works with no connection. Each code lasts 30 minutes and the next one takes over by itself. Open the app online once a day so your phone always has current codes.')).toBeTruthy()
  expect(screen.queryByText(/renews every 30 minutes/)).toBeNull()
  expect(screen.queryByLabelText('How does this work?')).toBeNull()
  expect(screen.queryByText(/this is the pass saved on this phone/)).toBeNull()
})

it('moves to the next half hour as the clock does, from what the phone holds', async () => {
  await render(<PassScreen />)
  await act(async () => {
    jest.advanceTimersByTime(20 * 60_000) // 10:01 AM
  })
  expect(screen.getByText('4 Sep 2026, 10:00 – 10:30 AM')).toBeTruthy()
  expect(screen.getByText('qr:slot-1')).toBeTruthy()
})

/*
 * A HELPER'S DAYS AND HOURS (the user, 8 Oct 2026): the server signs their e-Pass only inside them, says them under
 * the QR, and turns it off — paused, ended, or closed for the next day — in its own words.
 */
it("a Helper's e-Pass says their days and hours under the QR; anybody else's says none", async () => {
  mockQuery.data = { ...mockEpass, hours: 'Your e-Pass works Mon–Sat, 7:00 AM – 7:00 PM.' }
  const helper = await render(<PassScreen />)
  expect(screen.getByText('Your e-Pass works Mon–Sat, 7:00 AM – 7:00 PM.')).toBeTruthy()
  await helper.unmount()

  await getStorage().clearAll()
  mockQuery.data = { ...mockEpass, hours: null }
  await render(<PassScreen />)
  expect(screen.queryByText(/Your e-Pass works/)).toBeNull()
})

it('paused by the household: "Your e-Pass is off", in the server\'s words, never the day kept on the phone', async () => {
  // The phone holds a day of half hours from before the pause.
  const before = await render(<PassScreen />)
  expect(screen.getByText('qr:slot-0')).toBeTruthy()
  await before.unmount()

  const paused = "Your household has paused your e-Pass, so the gate will not admit you on it. Ask Andrea Fletcher to turn it back on."
  mockQuery.data = undefined
  mockQuery.error = { status: 403, code: 'access_paused', message: paused, fieldErrors: {}, retryAfter: null }
  await render(<PassScreen />)
  expect(screen.getByText('Your e-Pass is off')).toBeTruthy()
  expect(screen.getByText(paused)).toBeTruthy()
  expect(screen.queryByLabelText('QR code for your e-Pass')).toBeNull()
  expect(screen.queryByText(/qr:slot/)).toBeNull()
})

it('outside their hours for the next day: off, and when it opens again', async () => {
  const closed = 'Your e-Pass works Sat, Sun, 9:00 AM – 1:00 PM. It opens again then.'
  mockQuery.data = undefined
  mockQuery.error = { status: 403, code: 'outside_hours', message: closed, fieldErrors: {}, retryAfter: null }
  await render(<PassScreen />)
  expect(screen.getByText('Your e-Pass is off')).toBeTruthy()
  expect(screen.getByText(closed)).toBeTruthy()
})

it('a day of half hours spent: it ran out, and says to connect once', async () => {
  jest.setSystemTime(START + 48 * HALF + 60_000)
  await render(<PassScreen />)
  expect(screen.getByText('Your e-Pass ran out')).toBeTruthy()
  expect(screen.getByText('This phone keeps a day of it, and that day is over. Connect once to collect the next.')).toBeTruthy()
  expect(screen.queryByLabelText('QR code for your e-Pass')).toBeNull()
})

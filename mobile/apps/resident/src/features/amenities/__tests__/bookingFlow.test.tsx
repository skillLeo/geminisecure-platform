/**
 * M47 §8–§10 — BOOK AN AMENITY AS THE DESIGN DOES, on the real screens, their server answers scripted:
 *
 *   board 21   each place as the board writes it: "Up to 30 guests · garden setting", "$2,000/hr · $5,000 deposit";
 *   board 22   the hero's place, two-hour blocks, the house rules ticked with their gold link opening the rules, no
 *              guests box and no note, every figure the server's quote — "Gazebo rate — 2 hrs × $2,000 · $4,000",
 *              "Total due today · $9,000" — and "Confirm & pay $9,000", which opens the card checkout with them;
 *              what stops it is said under the button, never in a system dialog;
 *   board 31   confirmed: the server's code, the guests' QR the gate accepts and its caption, the deposit note with the
 *              card, and "Add to calendar", which adds it.
 */

import { Alert } from 'react-native'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react-native'
import { router } from 'expo-router'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
let mockParams: Record<string, string> = {}
jest.mock('expo-router', () => ({ router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: () => false }, useLocalSearchParams: () => mockParams, usePathname: () => '/amenities', useFocusEffect: jest.fn() }))
jest.mock('expo-status-bar', () => ({ StatusBar: () => null }))
// The access gate is the feature flag's; these screens are under test whatever the flag.
jest.mock('../components/AmenityParts', () => ({ ...jest.requireActual('../components/AmenityParts'), gated: (Screen: unknown) => Screen }))
const mockAddToCalendar = jest.fn(async () => 'opened')
jest.mock('../calendar', () => ({ addToCalendar: (...args: unknown[]) => mockAddToCalendar(...(args as [])) }))

// 9:41 AM on Friday 4 September 2026 at the estate.
const NOW = Date.parse('2026-09-04T14:41:00Z')

const gazebo = {
  id: 3, name: 'Gazebo', icon_key: 'gazebo', capacity: 30, opens_at: '09:00:00', closes_at: '21:00:00', booking_fee: '3000.00', deposit: '5000.00', currency: 'JMD',
  cancellation_hours: 48, booking_window_days: 30, taken: [], description: 'garden setting', place: 'Phase 2', hourly_rate: '2000.00', slot_minutes: 120,
  rules_summary: 'noise cut-off at 9:00 PM and no glassware on the lawn', rules: 'Noise cut-off at 9:00 PM.\nNo glassware on the lawn.',
}
const mockAmenities = { data: { may_book: true, card_payments: true, items: [gazebo] } as Record<string, unknown>, error: undefined, isLoading: false, refetch: jest.fn(async () => undefined) }
const mockBookings = { data: { card_payments: true, items: [] as unknown[] }, error: undefined, isLoading: false, refetch: jest.fn(async () => undefined) }
const mockQuoteArgs: unknown[] = []
const QUOTE = { minutes: 120, hourly_rate: '2000.00', fee: '4000.00', owed_before: '0.00', credit: '0.00', deposit: '5000.00', total: '9000.00', currency: 'JMD' }
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useAmenitiesIndexQuery: () => mockAmenities,
    useBookingsIndexQuery: () => mockBookings,
    useBookingsStoreMutation: () => [jest.fn()],
    useBookingsCancelMutation: () => [jest.fn()],
    useBookingsQuoteQuery: (arg: unknown, opts: { skip?: boolean }) => {
      if (!opts.skip) mockQuoteArgs.push(arg)
      return opts.skip ? { data: undefined, isFetching: false, isError: false } : { data: QUOTE, isFetching: false, isError: false }
    },
  },
}))
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => null }))
const mockState = { network: { online: true }, session: { status: 'active', principal: { id: 1, name: 'Hassam Ahmed', siteName: 'Phoenix Park Village 1' } } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))
jest.mock('@gs/core/lib', () => ({ ...jest.requireActual('@gs/core/lib'), flags: { ...jest.requireActual('@gs/core/lib').flags, testCardCheckout: true, amenityBookings: true } }))
jest.mock('@gs/core', () => ({ ...jest.requireActual('@gs/core'), flags: { ...jest.requireActual('@gs/core').flags, testCardCheckout: true, amenityBookings: true } }))

import { useAmenityList } from '../useAmenities'
import BookingRequestScreen from '../screens/BookingRequestScreen'
import BookingAnswerScreen from '../screens/BookingAnswerScreen'

jest.setTimeout(30_000)

beforeEach(() => {
  jest.useFakeTimers({ now: NOW })
  jest.mocked(router.push).mockClear()
  mockQuoteArgs.length = 0
  mockAddToCalendar.mockClear()
  mockAmenities.data = { may_book: true, card_payments: true, items: [gazebo] }
})
afterEach(() => jest.useRealTimers())

describe('board 21: each place as the board writes it', () => {
  it('"Up to 30 guests · garden setting" and "$2,000/hr · $5,000 deposit"', async () => {
    const { result } = await renderHook(() => useAmenityList())
    expect(result.current.cards[0]).toMatchObject({ name: 'Gazebo', detail: 'Up to 30 guests · garden setting', price: '$2,000/hr · $5,000 deposit', available: true })
  })
})

describe('board 22: the Gazebo, to book and pay for', () => {
  beforeEach(() => {
    mockParams = { id: '3' }
  })

  it('draws the six blocks, the hero with its place, the rules ticked, and no guests box, no note, no system dialog', async () => {
    const alert = jest.spyOn(Alert, 'alert')
    await render(<BookingRequestScreen />)
    expect(screen.getByText('Up to 30 guests · garden setting · Phase 2')).toBeTruthy()
    for (const label of ['9–11 AM', '11–1 PM', '1–3 PM', '3–5 PM', '5–7 PM', '7–9 PM']) expect(screen.getByText(label)).toBeTruthy()
    expect(screen.getByRole('checkbox', { name: "I've read and accept the Gazebo house rules", checked: true })).toBeTruthy()
    expect(screen.getByText(', including noise cut-off at 9:00 PM and no glassware on the lawn.', { exact: false })).toBeTruthy()
    expect(screen.queryByText('Number of guests')).toBeNull()
    expect(screen.queryByText(/office should know/i)).toBeNull()
    // Before any hours: the rate and the deposit, and a total that waits for the hours.
    for (const text of ['Gazebo rate', '$2,000/hr', 'Refundable damage deposit', '$5,000', 'Total due today', '—', 'Deposit is refunded after a condition check within 3 business days of your event.']) {
      expect(screen.getByText(text)).toBeTruthy()
    }
    // Pressed with no time chosen: said under the button.
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Confirm & pay' }))
    })
    expect(screen.getByText('Choose a time slot: tap the first one you need, then the last.')).toBeTruthy()
    expect(alert).not.toHaveBeenCalled()
    expect(router.push).not.toHaveBeenCalled()
  })

  it('the gold link opens the house rules as the estate wrote them', async () => {
    await render(<BookingRequestScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('link', { name: 'Gazebo house rules' }))
    })
    expect(screen.getByText('Noise cut-off at 9:00 PM.')).toBeTruthy()
    expect(screen.getByText('No glassware on the lawn.')).toBeTruthy()
  })

  it('two hours chosen: the server’s quote, word for word, and "Confirm & pay $9,000" opens the card checkout with it', async () => {
    await render(<BookingRequestScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('checkbox', { name: '11:00 AM to 1:00 PM' }))
    })
    expect(mockQuoteArgs.at(-1)).toMatchObject({ amenity: 3, starts_at: '2026-09-05T11:00:00-05:00', ends_at: '2026-09-05T13:00:00-05:00' })
    for (const text of ['Gazebo rate: 2 hrs × $2,000', '$4,000', '$5,000', '$9,000']) expect(screen.getByText(text)).toBeTruthy()
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Confirm & pay $9,000' }))
    })
    expect(router.push).toHaveBeenCalledWith({
      pathname: '/dues/test-pay',
      params: { kind: 'book', amenity: '3', starts: '2026-09-05T11:00:00-05:00', ends: '2026-09-05T13:00:00-05:00', amount: '9000.00', charge: 'Gazebo · 5 Sep 2026 · 11:00 AM – 1:00 PM', rules: '1' },
    })
  })

  it('the rules unticked: the booking waits for them, and says so under the button', async () => {
    await render(<BookingRequestScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('checkbox', { name: '11:00 AM to 1:00 PM' }))
    })
    await act(async () => {
      fireEvent.press(screen.getByRole('checkbox', { name: "I've read and accept the Gazebo house rules" }))
    })
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Confirm & pay $9,000' }))
    })
    expect(screen.getByText('Tick the box to accept the Gazebo house rules.')).toBeTruthy()
    expect(router.push).not.toHaveBeenCalled()
  })

  it('with the demonstration card off, it is the request it was: "Send booking request", $0 due today', async () => {
    mockAmenities.data = { may_book: true, card_payments: false, items: [gazebo] }
    await render(<BookingRequestScreen />)
    expect(screen.getByRole('button', { name: 'Send booking request' })).toBeTruthy()
    expect(screen.getByText('$0')).toBeTruthy()
  })

  it('an amenity whose bookings wait for the office is a request even with the card on (M53 §2): no card, $0 due today', async () => {
    mockAmenities.data = { may_book: true, card_payments: true, items: [{ ...gazebo, requires_approval: true }] }
    await render(<BookingRequestScreen />)
    expect(screen.getByRole('button', { name: 'Send booking request' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Confirm & pay/ })).toBeNull()
    expect(screen.getByText('$0')).toBeTruthy()
  })
})

describe('board 31: booked, paid and confirmed', () => {
  const confirmed = {
    id: 41, reference: 'BKG-2026-09-0041', amenity: { id: 3, name: 'Gazebo' }, starts_at: '2026-09-05T16:00:00+00:00', ends_at: '2026-09-05T18:00:00+00:00', guests: null,
    status: 'confirmed', booking_fee: '4000.00', deposit: '5000.00', deposit_state: 'held', deposit_kept: null, deposit_demonstration: true, currency: 'JMD',
    cancellable_until: '2026-09-03T16:00:00+00:00', declined_reason: null, cancelled_at: null, fee_charged: true, fee_outstanding: '0.00', fee_to_clear: '0.00', fee_paid: true,
    confirmation_code: 'GZ-2609-A4', gate_token: 'eyJwYXNzIjoiZ2F6ZWJvIn0.c2ln', paid_with: 'Visa •••• 4242',
  }

  beforeEach(() => {
    mockParams = { booking: '41' }
    mockBookings.data = { card_payments: true, items: [confirmed] }
  })

  it('the tick and the board’s words, the server’s code, the guests’ QR and its caption, the deposit going back to the card', async () => {
    await render(<BookingAnswerScreen />)
    for (const text of ['Booking confirmed', "You're all set for the Gazebo", 'CONFIRMATION CODE', 'GZ-2609-A4', 'Show this at the gate on the day of your event', '5 Sep 2026', '11:00 AM – 1:00 PM']) {
      expect(screen.getByText(text)).toBeTruthy()
    }
    expect(screen.getByLabelText('QR code of booking GZ-2609-A4')).toBeTruthy()
    expect(screen.getByText('Your $5,000 deposit is refunded within 3 business days after a condition check, straight back to Visa •••• 4242.')).toBeTruthy()
  })

  it('"Add to calendar" adds it: the booking as an event, on the estate’s own times', async () => {
    await render(<BookingAnswerScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Add to calendar' }))
    })
    expect(mockAddToCalendar).toHaveBeenCalledWith(expect.objectContaining({ title: 'Gazebo booking', startsAt: confirmed.starts_at, endsAt: confirmed.ends_at, location: 'Gazebo, Phase 2, Phoenix Park Village 1', uid: 'GZ-2609-A4@geminisecure' }), expect.any(Date))
  })

  it('a booking with no guests’ pass draws no QR, never a code the gate would refuse', async () => {
    mockBookings.data = { card_payments: true, items: [{ ...confirmed, gate_token: null, confirmation_code: null }] }
    await render(<BookingAnswerScreen />)
    expect(screen.queryByText('Show this at the gate on the day of your event')).toBeNull()
    expect(screen.getByText('BKG-2026-09-0041')).toBeTruthy()
  })
})

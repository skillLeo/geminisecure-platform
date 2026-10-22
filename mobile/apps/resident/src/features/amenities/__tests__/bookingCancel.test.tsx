/**
 * CANCELLING A BOOKING IS ASKED IN A SHEET (DESIGN §12.2 row 6), not with "Keep it" / "Yes, cancel it" swapped into
 * the card — which never named the booking on its buttons, and closed on a refusal so the estate's reason landed
 * under the row after the question had gone. Now: "Cancel your booking of the Gazebo on …?", "Cancel the booking" /
 * "Keep the booking", sent once and online only, and the estate's refusal stays in the sheet. If the sheet is closed
 * without the booking being cancelled, what the estate said stays on the row.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, renderHook } from '@testing-library/react-native'
import { CANCEL_BOOKING_LINE, cancelBookingSheet, type Booking } from '../amenities'
import { useMyBookings } from '../useAmenities'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn() }, usePathname: () => '/amenities/bookings', useFocusEffect: jest.fn() }))

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const mockCalls: unknown[] = []
let mockAnswer: () => Answer | Promise<Answer> = () => ({ data: undefined })
async function mockCancel(arg: unknown): Promise<Answer> {
  mockCalls.push(arg)
  return mockAnswer()
}
const DAY = 86_400_000
const at = (ms: number) => new Date(Date.now() + ms).toISOString()
const mockBooking: Booking = {
  id: 7, reference: 'BKG-2026-09-0004', amenity: { id: 3, name: 'Gazebo' }, starts_at: at(3 * DAY), ends_at: at(3 * DAY + 2 * 3_600_000), guests: 12, status: 'confirmed',
  booking_fee: '3000.00', deposit: '5000.00', deposit_state: 'awaiting', deposit_kept: null, currency: 'JMD', cancellable_until: at(DAY), declined_reason: null, cancelled_at: null,
} as Booking
// The same objects on every render, as RTK Query hands back: a new `data` each time would be a new answer each time.
const mockAmenitiesQuery = { data: { may_book: true, items: [] }, error: undefined, refetch: async () => undefined }
const mockBookingsQuery = { data: { items: [mockBooking] }, error: undefined, refetch: async () => undefined }
const mockCancelMutation = [mockCancel]
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useAmenitiesIndexQuery: () => mockAmenitiesQuery,
    useBookingsIndexQuery: () => mockBookingsQuery,
    useBookingsCancelMutation: () => mockCancelMutation,
  },
}))
let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
const mockState = { network: { online: true } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)
const settle = async () => { await act(async () => { for (let i = 0; i < 8; i++) await Promise.resolve() }) }
const refused = (status: number, message: string): Answer => ({ error: { status, code: 'cancellation_refused', message, fieldErrors: {}, retryAfter: null } })

beforeEach(() => {
  mockCalls.length = 0
  mockAnswer = () => ({ data: { ...mockBooking, status: 'cancelled', cancelled_at: new Date().toISOString() } })
  mockRefusal = null
  mockState.network.online = true
})

describe('what the sheet says', () => {
  it('names WHICH booking in full, and both buttons say what they do to it', () => {
    const words = cancelBookingSheet('the Gazebo on 4 Oct 2026, 11:00 AM – 1:00 PM')
    expect(words.title).toBe('Cancel your booking of the Gazebo on 4 Oct 2026, 11:00 AM – 1:00 PM?')
    expect(words.lines).toEqual([CANCEL_BOOKING_LINE])
    expect(words.confirmLabel).toBe('Cancel the booking')
    expect(words.cancelLabel).toBe('Keep the booking')
    expect(CANCEL_BOOKING_LINE).toMatch(/cannot be undone in the app/)
  })
})

describe('cancelling a booking', () => {
  it('asks about that booking; Keep the booking changes nothing', async () => {
    const { result } = await renderHook(() => useMyBookings())
    await act(async () => result.current.askCancel('7'))
    expect(result.current.asking?.id).toBe('7')
    expect(result.current.asking?.which).toMatch(/^the Gazebo on /)
    await act(async () => result.current.keep())
    expect(result.current.asking).toBeNull()
    expect(mockCalls).toEqual([])
  })

  it('confirm cancels ONCE and the sheet closes on the estate’s yes', async () => {
    let give: (a: Answer) => void = () => undefined
    mockAnswer = () => new Promise<Answer>((resolve) => { give = resolve })
    const { result } = await renderHook(() => useMyBookings())
    await act(async () => result.current.askCancel('7'))
    await act(async () => {
      result.current.confirmCancel()
      result.current.confirmCancel()
    })
    expect(result.current.cancelWorking).toBe(true)
    await act(async () => give({ data: { ...mockBooking, status: 'cancelled', cancelled_at: new Date().toISOString() } }))
    await settle()
    expect(mockCalls).toEqual([{ booking: 7 }])
    expect(result.current.asking).toBeNull()
    // The estate's answer is drawn at once: the booking now reads as cancelled.
    expect([...result.current.upcoming, ...result.current.past].find((row) => row.id === '7')?.pill.word).toBe('Cancelled')
  })

  it('the estate’s refusal stays IN the sheet, and on the row once the sheet is closed', async () => {
    mockAnswer = () => refused(409, 'The estate has already assigned the Gazebo for that evening. Speak to the office.')
    const { result } = await renderHook(() => useMyBookings())
    await act(async () => result.current.askCancel('7'))
    await act(async () => result.current.confirmCancel())
    await settle()
    expect(result.current.asking?.id).toBe('7')
    expect(result.current.cancelProblem).toBe('The estate has already assigned the Gazebo for that evening. Speak to the office.')
    await act(async () => result.current.keep())
    expect(result.current.asking).toBeNull()
    expect(result.current.cancelNote).toEqual({ id: '7', text: 'The estate has already assigned the Gazebo for that evening. Speak to the office.' })
    // Asking again starts clean.
    await act(async () => result.current.askCancel('7'))
    expect(result.current.cancelNote).toBeNull()
    expect(result.current.cancelProblem).toBeNull()
  })

  it('no answer is said as not knowing, never as cancelled', async () => {
    mockAnswer = () => ({ error: { status: 'timeout', code: 'timeout', message: 'slow', fieldErrors: {}, retryAfter: null } })
    const { result } = await renderHook(() => useMyBookings())
    await act(async () => result.current.askCancel('7'))
    await act(async () => result.current.confirmCancel())
    await settle()
    expect(result.current.cancelProblem).toMatch(/cannot tell whether the booking was cancelled/)
  })

  it('offline: nothing is sent, and it says why', async () => {
    const { result, rerender } = await renderHook(() => useMyBookings())
    await act(async () => result.current.askCancel('7'))
    mockState.network.online = false // the connection went while the question was up
    await rerender(undefined)
    await act(async () => result.current.confirmCancel())
    await settle()
    expect(mockCalls).toEqual([])
    expect(result.current.cancelProblem).toBe(result.current.offlineCancelLine)
  })

  it('a demonstration session is refused before anything is sent', async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => useMyBookings())
    await act(async () => result.current.askCancel('7'))
    await act(async () => result.current.confirmCancel())
    await settle()
    expect(mockCalls).toEqual([])
    expect(result.current.cancelProblem).toBe(mockRefusal)
  })
})

describe('the screen', () => {
  const screen = readFileSync(path.join(__dirname, '..', 'screens', 'MyBookingsScreen.tsx'), 'utf8')
  it('asks in a ConfirmSheet, and the card no longer swaps in "Keep it" / "Yes, cancel it"', () => {
    expect(screen).toContain('<ConfirmSheet')
    expect(screen).toContain('cancelBookingSheet(')
    expect(screen).not.toContain("'Keep it'")
    expect(screen).not.toContain("'Yes, cancel it'")
    expect(screen).not.toContain('"Keep it"')
    expect(screen).not.toContain('"Yes, cancel it"')
  })
})

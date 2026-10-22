/**
 * A HELPER'S DAYS AND HOURS (the user, 8 Oct 2026) — the primary resident sets when a Helper may come.
 *
 * Driven as a resident drives it: the Profile tab's real Household card, the member's sheet, "Days and hours", and the
 * real flow behind it (useHoursChange) — only the API is scripted. The words are the server's (AccessRules::windowLine);
 * online only; sent once; the server's refusal stays in the sheet; pausing is the red one.
 */

import { act, fireEvent, render, renderHook, screen } from '@testing-library/react-native'
import { now, siteDayIso } from '@gs/core'
import {
  DEFAULT_WINDOW, dateWords, daysWords, overnightLine, sameWindow, stepDate, stepMonths, stepTime, timeWords, toggleDay, windowLine, windowOf, windowProblem, type HelperWindow,
} from '../helperHours'
import { memberRows, type Member } from '../household'
import { HOURS_OFFLINE_SHEET, hoursSheet } from '../sheets'
import { useHoursChange, type ProfileModel } from '../useProfile'
import { Household } from '../screens/ProfileScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/profile', useFocusEffect: jest.fn() }))

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const mockCalls: { endpoint: string; arg: unknown }[] = []
let mockAnswer: (endpoint: string) => Answer | Promise<Answer> = () => ({ data: { id: 9 } })
async function mockSend(endpoint: string, arg: unknown): Promise<Answer> {
  mockCalls.push({ endpoint, arg })
  return mockAnswer(endpoint)
}
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useMembersAccessMutation: () => [(arg: unknown) => mockSend('members.access', arg), { isLoading: false }],
    useMembersInviteMutation: () => [(arg: unknown) => mockSend('members.invite', arg), { isLoading: false }],
    useMembersInviteCancelMutation: () => [(arg: unknown) => mockSend('members.invite_cancel', arg), { isLoading: false }],
  },
}))
let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
jest.mock('@/features/auth/session', () => ({ closeSession: jest.fn(async () => undefined), refreshFromMe: jest.fn(async () => undefined) }))
jest.mock('@/offline/runtime', () => ({ drainNow: jest.fn(async () => undefined), outbox: { all: () => [], dismiss: jest.fn(async () => undefined), reset: jest.fn() } }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] as unknown[] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)

const MON_SAT = { days: [1, 2, 3, 4, 5, 6], from: '07:00', to: '19:00', until: null, paused: false, own: false, line: 'Mon–Sat, 7:00 AM – 7:00 PM' }
const PRIMARY = { id: 1, full_name: 'Andrea Fletcher', relationship: 'owner', is_primary: true, status: 'verified', phone: null, email: 'andrea@example.com', access_level: 'member', access_window: null, invite: null } as unknown as Member
const HELPER = { id: 9, full_name: 'Marcia Brown', relationship: 'other', is_primary: false, status: 'verified', phone: null, email: null, access_level: 'helper', access_window: MON_SAT, invite: null } as unknown as Member
const CHILD = { id: 7, full_name: 'Soft Dev', relationship: 'child', is_primary: false, status: 'verified', phone: null, email: null, access_level: 'dependent', access_window: null, invite: null } as unknown as Member
const settle = async () => { await act(async () => { for (let i = 0; i < 6; i++) await Promise.resolve() }) }
const idle = { target: null, open: false, working: false, problem: null, ask: jest.fn(), cancel: jest.fn(), confirm: jest.fn() }

const after = jest.fn()
function ProfileHousehold({ primary = true, members = [PRIMARY, HELPER, CHILD] }: { primary?: boolean; members?: Member[] }) {
  const hoursChange = useHoursChange(after)
  const m = {
    fixture: null,
    members: { status: 'ready', rows: memberRows(members, []), message: null },
    membersFetchedAt: 0,
    manage: { allowed: primary, selfId: PRIMARY.id, online: mockState.network.online, removal: idle, levelChange: idle, levelsOpen: false, hoursChange },
    refresh: jest.fn(),
    dismiss: jest.fn(),
  } as unknown as ProfileModel
  return <Household m={m} />
}

beforeEach(() => {
  mockCalls.length = 0
  mockAnswer = () => ({ data: { id: 9 } })
  mockRefusal = null
  mockState.network.online = true
  after.mockClear()
})

describe('the words, as the server says them', () => {
  it('days', () => {
    expect(daysWords([1, 2, 3, 4, 5, 6])).toBe('Mon–Sat')
    expect(daysWords([5, 1, 3])).toBe('Mon, Wed, Fri')
    expect(daysWords([6, 7])).toBe('Sat, Sun')
    expect(daysWords([1, 2, 3, 4, 5, 6, 7])).toBe('Every day')
  })

  it('times, on the half hour and around the clock', () => {
    expect(timeWords('00:00')).toBe('12:00 AM')
    expect(timeWords('12:30')).toBe('12:30 PM')
    expect(timeWords('19:00')).toBe('7:00 PM')
    expect(stepTime('23:30', 1)).toBe('00:00')
    expect(stepTime('00:00', -1)).toBe('23:30')
    expect(stepTime('07:00', 3)).toBe('08:30')
  })

  it('the end date: a day at a time, never before today; a month is a calendar month', () => {
    expect(stepDate('2026-10-31', 1)).toBe('2026-11-01')
    expect(stepDate('2026-10-09', -5, '2026-10-08')).toBe('2026-10-08')
    expect(stepMonths('2027-01-31', 1)).toBe('2027-02-28')
    expect(stepMonths('2026-10-08', 3)).toBe('2027-01-08')
    expect(dateWords('2030-12-31')).toBe('31 Dec 2030')
  })

  it('the whole line, the contract walk\'s own, and a paused helper', () => {
    expect(windowLine({ days: [1, 2, 3, 4, 5], from: '08:00', to: '17:00', until: '2030-12-31', paused: false })).toBe('Mon–Fri, 8:00 AM – 5:00 PM, until 31 Dec 2030')
    expect(windowLine({ ...DEFAULT_WINDOW, paused: true })).toBe('Paused by the household')
  })

  it('an overnight window says so; a day window does not', () => {
    expect(overnightLine({ from: '22:00', to: '06:00' })).toBe('Overnight: they may come from 10:00 PM until 6:00 AM the next morning.')
    expect(overnightLine({ from: '07:00', to: '19:00' })).toBeNull()
  })

  it('what the server would refuse is said before it is sent', () => {
    expect(windowProblem({ ...DEFAULT_WINDOW, days: [] })).toBe('Choose at least one day they may come.')
    expect(windowProblem({ ...DEFAULT_WINDOW, to: '07:00' })).toMatch(/not the same time/)
    expect(windowProblem(DEFAULT_WINDOW)).toBeNull()
  })

  it('a member\'s window is the server\'s; a Member or a Dependent has none', () => {
    expect(windowOf(HELPER)).toEqual({ days: [1, 2, 3, 4, 5, 6], from: '07:00', to: '19:00', until: null, paused: false })
    expect(windowOf(CHILD)).toBeNull()
    expect(toggleDay([1, 2], 7)).toEqual([1, 2, 7])
    expect(toggleDay([1, 2, 7], 2)).toEqual([1, 7])
    expect(sameWindow(DEFAULT_WINDOW, { ...DEFAULT_WINDOW, days: [6, 5, 4, 3, 2, 1] })).toBe(true)
  })
})

describe('what the sheet says', () => {
  const saved = windowOf(HELPER)!
  it('nothing changed is nothing to save', () => {
    const words = hoursSheet(HELPER, saved, saved, true)
    expect(words.title).toBe("Marcia Brown's days and hours")
    expect(words.blocked).toBe('Nothing has changed yet.')
    expect(words.cancelLabel).toBe('Close')
    expect(words.destructive).toBe(false)
  })

  it('pausing is the red one, and says what it does at once', () => {
    const words = hoursSheet(HELPER, { ...saved, paused: true }, saved, true)
    expect(words.confirmLabel).toBe('Pause Marcia Brown')
    expect(words.destructive).toBe(true)
    expect(words.lines[0]).toBe("Paused: Marcia Brown's e-Pass is cancelled at once and every gate refuses it, until you resume them.")
    expect(hoursSheet(HELPER, saved, { ...saved, paused: true }, true).confirmLabel).toBe('Resume Marcia Brown')
  })

  it('a change, offline, cannot be confirmed, and says why', () => {
    expect(hoursSheet(HELPER, { ...saved, days: [1, 2, 3, 4, 5, 6, 7] }, saved, false).blocked).toBe(HOURS_OFFLINE_SHEET)
    expect(hoursSheet(HELPER, { ...saved, days: [] }, saved, true).blocked).toBe('Choose at least one day they may come.')
  })
})

const openMember = async (name: string) => {
  await act(async () => fireEvent.press(screen.getByRole('button', { name: new RegExp(`^${name}, .*\\. Open$`) })))
}
const openHours = async () => {
  await openMember('Marcia Brown')
  await act(async () => fireEvent.press(screen.getByLabelText("Set Marcia Brown's days and hours")))
}

describe('from the Household card', () => {
  it('a Helper\'s sheet says when they come, and the primary may change it', async () => {
    await render(<ProfileHousehold />)
    await openMember('Marcia Brown')
    expect(screen.getByText('Comes: Mon–Sat, 7:00 AM – 7:00 PM')).toBeTruthy()
    expect(screen.getByLabelText("Set Marcia Brown's days and hours")).toBeTruthy()
  })

  it('never for a Dependent, and never for a resident who is not the primary', async () => {
    await render(<ProfileHousehold />)
    await openMember('Soft Dev')
    expect(screen.queryByLabelText("Set Soft Dev's days and hours")).toBeNull()

    await render(<ProfileHousehold primary={false} />)
    await openMember('Marcia Brown')
    expect(screen.getByText('Comes: Mon–Sat, 7:00 AM – 7:00 PM')).toBeTruthy()
    expect(screen.queryByLabelText("Set Marcia Brown's days and hours")).toBeNull()
  })

  it('opens on what the server holds: nothing changed, nothing to save', async () => {
    await render(<ProfileHousehold />)
    await openHours()
    expect(screen.getByText("Marcia Brown's days and hours")).toBeTruthy()
    expect(screen.getByText('Mon–Sat, 7:00 AM – 7:00 PM')).toBeTruthy()
    expect(screen.getByTestId('confirm-sheet-blocked')).toHaveTextContent('Nothing has changed yet.')
  })

  it('adding Sunday asks about the new days; Save sends members.access ONCE and the sheet closes on the answer', async () => {
    await render(<ProfileHousehold />)
    await openHours()
    await act(async () => fireEvent.press(screen.getByRole('checkbox', { name: 'Sunday' })))
    expect(screen.getByText('Every day, 7:00 AM – 7:00 PM')).toBeTruthy()
    const save = screen.getByText('Save days and hours')
    await act(async () => {
      fireEvent.press(save)
      fireEvent.press(save)
    })
    await settle()
    expect(mockCalls).toEqual([{ endpoint: 'members.access', arg: { member: '9', days: [1, 2, 3, 4, 5, 6, 7], from: '07:00', to: '19:00', paused: false } }])
    expect(after).toHaveBeenCalledTimes(1)
    expect(screen.queryByText("Marcia Brown's days and hours")).toBeNull()
  })

  it('the times move half an hour a time; an end date goes with them', async () => {
    await render(<ProfileHousehold />)
    await openHours()
    await act(async () => fireEvent.press(screen.getByLabelText('From 7:00 AM: half an hour later')))
    await act(async () => fireEvent.press(screen.getByLabelText('Until 7:00 PM: half an hour earlier')))
    expect(screen.getByText('Mon–Sat, 7:30 AM – 6:30 PM')).toBeTruthy()

    const today = siteDayIso(now())
    await act(async () => fireEvent.press(screen.getByRole('switch', { name: 'Ends on a date' })))
    expect(screen.getByText(`Their e-Pass stops working after ${dateWords(stepDate(today, 7))}.`)).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: 'Ends in a month' })))
    const until = stepMonths(today, 1)
    expect(screen.getByText(`Mon–Sat, 7:30 AM – 6:30 PM, until ${dateWords(until)}`)).toBeTruthy()

    await act(async () => fireEvent.press(screen.getByText('Save days and hours')))
    await settle()
    expect(mockCalls).toEqual([{ endpoint: 'members.access', arg: { member: '9', days: [1, 2, 3, 4, 5, 6], from: '07:30', to: '18:30', paused: false, until } }])
  })

  it('Pause now is the red one, and is sent as a pause', async () => {
    await render(<ProfileHousehold />)
    await openHours()
    await act(async () => fireEvent.press(screen.getByRole('switch', { name: 'Pause now' })))
    expect(screen.getByText("Paused: Marcia Brown's e-Pass is cancelled at once and every gate refuses it, until you resume them.")).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByText('Pause Marcia Brown')))
    await settle()
    expect(mockCalls).toEqual([{ endpoint: 'members.access', arg: { member: '9', days: [1, 2, 3, 4, 5, 6], from: '07:00', to: '19:00', paused: true } }])
  })

  it('a refusal stays IN the sheet, in the server\'s words', async () => {
    mockAnswer = () => ({ error: { status: 409, code: 'not_a_helper', message: 'Days and hours are for a Helper. Change their access to Helper first.', fieldErrors: {}, retryAfter: null } })
    await render(<ProfileHousehold />)
    await openHours()
    await act(async () => fireEvent.press(screen.getByRole('checkbox', { name: 'Sunday' })))
    await act(async () => fireEvent.press(screen.getByText('Save days and hours')))
    await settle()
    expect(screen.getByText("Marcia Brown's days and hours")).toBeTruthy()
    expect(screen.getByText('Days and hours are for a Helper. Change their access to Helper first.')).toBeTruthy()
    expect(after).not.toHaveBeenCalled()
  })

  it('offline, a change cannot be confirmed, and nothing is sent', async () => {
    mockState.network.online = false
    await render(<ProfileHousehold />)
    await openHours()
    await act(async () => fireEvent.press(screen.getByRole('checkbox', { name: 'Sunday' })))
    expect(screen.getByTestId('confirm-sheet-blocked')).toHaveTextContent(HOURS_OFFLINE_SHEET)
    await act(async () => fireEvent.press(screen.getByText('Save days and hours')))
    await settle()
    expect(mockCalls).toEqual([])
  })
})

describe('the hours flow itself', () => {
  const window: HelperWindow = { ...DEFAULT_WINDOW, days: [1, 2, 3, 4, 5, 6, 7] }
  it('refuses offline, and sends nothing', async () => {
    const { result } = await renderHook(() => useHoursChange(after))
    mockState.network.online = false
    await act(async () => result.current.ask({ member: HELPER, window }))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(HOURS_OFFLINE_SHEET)
    expect(mockCalls).toEqual([])
  })

  it('a demonstration session is refused before anything is sent', async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => useHoursChange(after))
    await act(async () => result.current.ask({ member: HELPER, window }))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(mockRefusal)
    expect(mockCalls).toEqual([])
  })

  it('no answer is not "no connection": it says it cannot tell', async () => {
    mockAnswer = () => ({ error: { status: 'timeout', code: 'timeout', message: 'timed out', fieldErrors: {}, retryAfter: null } })
    const { result } = await renderHook(() => useHoursChange(after))
    await act(async () => result.current.ask({ member: HELPER, window }))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toMatch(/cannot tell whether their days and hours changed/)
  })
})

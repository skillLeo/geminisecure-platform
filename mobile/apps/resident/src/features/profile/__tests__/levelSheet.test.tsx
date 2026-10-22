/**
 * J-LVL-1 — the primary resident changes a member's access level (household invites, stage 2).
 *
 * Driven as a resident drives it: the Profile tab's real Household card and LevelSheet, the real flow behind them
 * (useLevelChange), the row's own "Access" pressed and a level chosen in the sheet — only the API is scripted.
 * Narrowing is a destructive act and says what is lost at once; widening is not. Online only, sent once, and the
 * server's refusal stays in the sheet. Until the server opens levels, no "Access" control is drawn at all.
 */

import { act, fireEvent, render, renderHook, screen } from '@testing-library/react-native'
import { lostWith, memberRows, type Member } from '../household'
import { LEVEL_OFFLINE_SHEET, levelSheet } from '../sheets'
import { useLevelChange, type ProfileModel } from '../useProfile'
import { Household } from '../screens/ProfileScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/profile', useFocusEffect: jest.fn() }))

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const mockCalls: { endpoint: string; arg: unknown }[] = []
let mockAnswer: (endpoint: string) => Answer | Promise<Answer> = () => ({ data: { id: 7 } })
async function mockSend(endpoint: string, arg: unknown): Promise<Answer> {
  mockCalls.push({ endpoint, arg })
  return mockAnswer(endpoint)
}
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useMembersLevelMutation: () => [(arg: unknown) => mockSend('members.level', arg), { isLoading: false }],
    useMembersDestroyMutation: () => [(arg: unknown) => mockSend('members.destroy', arg), { isLoading: false }],
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

const PRIMARY = { id: 1, full_name: 'Andrea Fletcher', relationship: 'owner', is_primary: true, status: 'verified', phone: null, email: 'andrea@example.com', access_level: 'member', invite: null } as unknown as Member
const SOFT = { id: 7, full_name: 'Soft Dev', relationship: 'child', is_primary: false, status: 'verified', phone: null, email: null, access_level: 'member', invite: null } as unknown as Member
const settle = async () => { await act(async () => { for (let i = 0; i < 6; i++) await Promise.resolve() }) }

const after = jest.fn()
function ProfileHousehold({ levelsOpen = true }: { levelsOpen?: boolean }) {
  const levelChange = useLevelChange(after)
  const m = {
    fixture: null,
    members: { status: 'ready', rows: memberRows([PRIMARY, SOFT], []), message: null },
    membersFetchedAt: 0,
    manage: {
      allowed: true,
      selfId: PRIMARY.id,
      online: mockState.network.online,
      removal: { target: null, open: false, working: false, problem: null, ask: jest.fn(), cancel: jest.fn(), confirm: jest.fn() },
      levelChange,
      levelsOpen,
      hoursChange: { target: null, open: false, working: false, problem: null, ask: jest.fn(), cancel: jest.fn(), confirm: jest.fn() },
    },
    refresh: jest.fn(),
    dismiss: jest.fn(),
  } as unknown as ProfileModel
  return <Household m={m} />
}

beforeEach(() => {
  mockCalls.length = 0
  mockAnswer = () => ({ data: { id: 7 } })
  mockRefusal = null
  mockState.network.online = true
  after.mockClear()
})

describe('what the access sheet says', () => {
  it('narrowing is destructive and says what they lose at once; widening is not', () => {
    const narrow = levelSheet(SOFT, 'dependent', true)
    expect(narrow.title).toBe('Change Soft Dev to Dependent?')
    expect(narrow.destructive).toBe(true)
    expect(narrow.lines[0]).toBe(lostWith('dependent'))
    expect(narrow.lines[0]).toContain('Panic and their own e-pass stay.')
    expect(narrow.confirmLabel).toBe('Change to Dependent')
    expect(narrow.cancelLabel).toBe('Keep Member')

    const widen = levelSheet({ ...SOFT, access_level: 'helper' }, 'member', true)
    expect(widen.destructive).toBe(false)
    expect(widen.confirmLabel).toBe('Change to Member')
    expect(widen.lines[0]).toBe('They can do everything a resident can except manage members.')
  })

  it('their current level is no change: nothing to confirm', () => {
    const same = levelSheet(SOFT, 'member', true)
    expect(same.blocked).toBe('Soft Dev already has Member access.')
    expect(same.destructive).toBe(false)
  })

  it('offline, the button is disabled with the reason', () => {
    expect(levelSheet(SOFT, 'helper', false).blocked).toBe(LEVEL_OFFLINE_SHEET)
  })
})

const openMember = async (name: string) => {
  const row = screen.queryByRole('button', { name: new RegExp(`^${name}, .*\. Open$`) })
  if (row) await fireEvent.press(row)
}
const access = async () => {
  if (!screen.queryByLabelText("Change Soft Dev's access")) await openMember('Soft Dev')
  return screen.getByLabelText("Change Soft Dev's access")
}

describe('changing a member\'s access from the Household card', () => {
  it('draws no "Access" control until the server has opened levels', async () => {
    await render(<ProfileHousehold levelsOpen={false} />)
    expect(screen.queryByLabelText("Change Soft Dev's access")).toBeNull()
  })

  it('never beside the primary resident', async () => {
    await render(<ProfileHousehold />)
    expect(screen.queryByLabelText("Change Andrea Fletcher's access")).toBeNull()
    expect(await access()).toBeTruthy()
  })

  it('opens on their current level; choosing a narrower one asks about THAT, destructively', async () => {
    await render(<ProfileHousehold />)
    { const button = await access(); await act(async () => fireEvent.press(button)) }
    expect(screen.getByText("Soft Dev's access")).toBeTruthy()

    await act(async () => fireEvent.press(screen.getByRole('radio', { name: /^Dependent\./ })))
    expect(screen.getByText('Change Soft Dev to Dependent?')).toBeTruthy()
    expect(screen.getByText(lostWith('dependent'))).toBeTruthy()
    expect(mockCalls).toEqual([])
  })

  it('Keep changes nothing: nothing is sent', async () => {
    await render(<ProfileHousehold />)
    { const button = await access(); await act(async () => fireEvent.press(button)) }
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: /^Helper\./ })))
    await act(async () => fireEvent.press(screen.getByText('Keep Member')))
    await settle()
    expect(screen.queryByText('Change Soft Dev to Helper?')).toBeNull()
    expect(mockCalls).toEqual([])
    expect(after).not.toHaveBeenCalled()
  })

  it('confirm calls members.level ONCE, for that member and that level, and the sheet closes on the answer', async () => {
    await render(<ProfileHousehold />)
    { const button = await access(); await act(async () => fireEvent.press(button)) }
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: /^Dependent\./ })))
    const confirm = screen.getByText('Change to Dependent')
    await act(async () => {
      fireEvent.press(confirm)
      fireEvent.press(confirm)
    })
    await settle()
    expect(mockCalls).toEqual([{ endpoint: 'members.level', arg: { member: '7', access_level: 'dependent' } }])
    expect(after).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Change Soft Dev to Dependent?')).toBeNull()
  })

  it('a 409 stays IN the sheet, in the server\'s words', async () => {
    mockAnswer = () => ({ error: { status: 409, code: 'access_levels_not_open', message: 'Dependent and Helper access can be given once the Resident App update that supports them is out.', fieldErrors: {}, retryAfter: null } })
    await render(<ProfileHousehold />)
    { const button = await access(); await act(async () => fireEvent.press(button)) }
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: /^Dependent\./ })))
    await act(async () => fireEvent.press(screen.getByText('Change to Dependent')))
    await settle()
    expect(screen.getByText('Change Soft Dev to Dependent?')).toBeTruthy()
    expect(screen.getByText(/can be given once the Resident App update/)).toBeTruthy()
    expect(after).not.toHaveBeenCalled()
  })
})

describe('the level flow itself', () => {
  it('refuses offline, and sends nothing', async () => {
    const { result } = await renderHook(() => useLevelChange(after))
    mockState.network.online = false
    await act(async () => result.current.ask({ member: SOFT, level: 'helper' }))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(LEVEL_OFFLINE_SHEET)
    expect(mockCalls).toEqual([])
  })

  it('a demonstration session is refused before anything is sent', async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => useLevelChange(after))
    await act(async () => result.current.ask({ member: SOFT, level: 'helper' }))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(mockRefusal)
    expect(mockCalls).toEqual([])
  })
})

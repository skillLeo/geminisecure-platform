/**
 * J-CS-3 — THE REPORTED BUG: "Remove" asked below the fold, with buttons that named half a person.
 *
 * The user, 24 September: tapping Remove beside "Soft Dev" drew "Remove Soft Dev?" UNDER the household list, often
 * off the screen, so nothing seemed to happen; and its buttons read "Remove Soft" / "Keep Soft". Now the question is
 * a ConfirmSheet over the screen, named in full, sent once, and a refusal stays in it. The same for cancelling an
 * invite.
 *
 * Driven the way a resident drives it: the Profile tab's real Household card (ProfileScreen's own RemoveMemberSheet,
 * InviteParts' own CancelInviteSheet), the real hooks behind them (useMemberRemoval, useInvites), and the row's own
 * buttons pressed — only the API is scripted. A wiring mistake between the row, the sheet and the flow fails here.
 */

import { AccessibilityInfo, View } from 'react-native'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react-native'
import { INVITE_OFFLINE, REMOVE_OFFLINE, removeSentence, cancelInviteSentence, memberRows, type Member } from '../household'
import { CANCEL_INVITE_NO_ANSWER, CANCEL_INVITE_OFFLINE, REMOVE_NO_ANSWER, cancelInviteSheet, removeSheet } from '../sheets'
import { useMemberRemoval, type ProfileModel } from '../useProfile'
import { Household } from '../screens/ProfileScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/profile', useFocusEffect: jest.fn() }))
/** The REAL sheet — only watched, so a test can see where the screen told it focus goes back to. */
const mockSheetProps = new Map<string, Record<string, unknown>>()
jest.mock('@gs/core', () => {
  const actual = jest.requireActual<typeof import('@gs/core')>('@gs/core')
  const { createElement } = jest.requireActual<typeof import('react')>('react')
  return {
    ...actual,
    ConfirmSheet: (props: import('@gs/core').ConfirmSheetProps) => {
      if (props.title) mockSheetProps.set(props.title, props as unknown as Record<string, unknown>)
      return createElement(actual.ConfirmSheet, props)
    },
  }
})

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const mockCalls: { endpoint: string; arg: unknown }[] = []
let mockAnswer: (endpoint: string) => Answer | Promise<Answer> = () => ({ data: { removed: true } })
/** An answer the test gives when it chooses: the write is on its way until then. */
function mockLater(): { answer: Promise<Answer>; give: (a: Answer) => void } {
  let give: (a: Answer) => void = () => undefined
  const answer = new Promise<Answer>((resolve) => { give = resolve })
  return { answer, give: (a) => give(a) }
}
async function mockSend(endpoint: string, arg: unknown): Promise<Answer> {
  mockCalls.push({ endpoint, arg })
  return mockAnswer(endpoint)
}
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
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

const PRIMARY = { id: 1, full_name: 'Andrea Fletcher', relationship: 'owner', is_primary: true, status: 'verified', phone: null, email: 'andrea@example.com', invite: null } as unknown as Member
const SOFT = { id: 7, full_name: 'Soft Dev', relationship: 'child', is_primary: false, status: 'pending', phone: null, email: 'softdev.571@gmail.com', invite: null } as unknown as Member
const CONFLICT = (message: string): Answer => ({ error: { status: 409, code: 'conflict', message, fieldErrors: {}, retryAfter: null } })
const NO_ANSWER = (status: 'timeout' | 'network'): Answer => ({ error: { status, code: status, message: 'slow', fieldErrors: {}, retryAfter: null } })
const settle = async () => { await act(async () => { for (let i = 0; i < 6; i++) await Promise.resolve() }) }
const frame = async () => { await act(async () => { await new Promise((resolve) => setTimeout(resolve, 30)) }) }

beforeEach(() => {
  mockCalls.length = 0
  mockAnswer = () => ({ data: { removed: true } })
  mockRefusal = null
  mockState.network.online = true
  ;(AccessibilityInfo.sendAccessibilityEvent as jest.Mock).mockClear()
})

/**
 * The Profile tab's Household card as the primary resident sees it, with the REAL removal flow behind it. The rest
 * of the tab's model is what Household reads and nothing more.
 */
const after = jest.fn()
function ProfileHousehold({ members = [PRIMARY, SOFT] }: { members?: Member[] }) {
  const removal = useMemberRemoval(after)
  const m = {
    fixture: null,
    members: { status: 'ready', rows: memberRows(members, []), message: null },
    membersFetchedAt: 0,
    // Levels are not open on this server: the level flow stands idle, and no "Access" control is drawn. Nobody is a
    // Helper, so the days-and-hours flow stands idle too.
    manage: {
      allowed: true, selfId: PRIMARY.id, online: mockState.network.online, removal, levelsOpen: false,
      levelChange: { target: null, open: false, working: false, problem: null, ask: jest.fn(), cancel: jest.fn(), confirm: jest.fn() },
      hoursChange: { target: null, open: false, working: false, problem: null, ask: jest.fn(), cancel: jest.fn(), confirm: jest.fn() },
    },
    refresh: jest.fn(),
    dismiss: jest.fn(),
  } as unknown as ProfileModel
  return <Household m={m} />
}

const openMember = async (name: string) => {
  const row = screen.queryByRole('button', { name: new RegExp(`^${name}, .*\. Open$`) })
  if (row) await fireEvent.press(row)
}
// The member's own actions are in the sheet their row opens (M42 §5 #28): open it, then the action.
const removeRow = async () => {
  if (!screen.queryByRole('button', { name: 'Remove Soft Dev from your household' })) await openMember('Soft Dev')
  return screen.getByRole('button', { name: 'Remove Soft Dev from your household' })
}
const removeTitle = 'Remove Soft Dev from your household?'
type Node = View & { isConnected?: boolean; props?: Record<string, unknown> }
/** What the screen handed its sheet: the control that opened it, and where focus goes if that control is gone. */
/**
 * A native node's label. React Native's View turns the `aria-label` a screen passes into `accessibilityLabel` on the
 * host node, so that is where the label a screen reader reads is found.
 */
const labelOf = (node: Node | null | undefined): unknown => node?.props?.accessibilityLabel ?? node?.props?.['aria-label']
/**
 * The label of the last node the screen reader was sent to. Compared by label, never by node: a failing
 * node-to-node match makes Jest print the whole native tree, which does not fit in memory.
 */
const lastFocused = (): unknown => {
  const calls = (AccessibilityInfo.sendAccessibilityEvent as jest.Mock).mock.calls
  const last = calls[calls.length - 1] as [Node, string] | undefined
  return last ? `${String(labelOf(last[0]))} · ${last[1]}` : 'nothing focused'
}
const focusPlan = (title: string): { opener: Node | null; fallbacks: (Node | null)[] } => {
  const props = mockSheetProps.get(title) as { returnFocusTo?: { current: Node | null }; fallbackFocusTo?: { current: Node | null }[] } | undefined
  return { opener: props?.returnFocusTo?.current ?? null, fallbacks: (props?.fallbackFocusTo ?? []).map((ref) => ref.current) }
}

describe('what the removal sheet says', () => {
  it('names the person IN FULL, the title and the red button, and says what removing does', () => {
    const words = removeSheet({ full_name: '  Soft   Dev ' }, true)
    expect(words.title).toBe('Remove Soft Dev from your household?')
    expect(words.confirmLabel).toBe('Remove Soft Dev')
    expect(words.cancelLabel).toBe('Cancel')
    expect(words.lines).toEqual([removeSentence('Soft Dev')])
    expect(words.blocked).toBeNull()
  })

  it('offline, the button is disabled with the reason: removing is never saved to do later', () => {
    expect(removeSheet({ full_name: 'Soft Dev' }, false).blocked).toBe(REMOVE_OFFLINE)
  })

  it('a member with no name on the register is still named as a person, never as "them"', () => {
    expect(removeSheet({ full_name: '' }, true)).toMatchObject({ title: 'Remove this person from your household?', confirmLabel: 'Remove this person' })
  })
})

describe('the Household row and its sheet (M47 §14.3, M48 §2.1)', () => {
  it('the row is the initials, the name and the ACCESS LEVEL; the sheet adds the relationship and "Awaiting verification"', async () => {
    await render(<ProfileHousehold />)
    // Soft Dev is a child of the household with Member access: the chip is the access, never "Child".
    expect(screen.getByRole('button', { name: 'Soft Dev, Member. Open' })).toBeOnTheScreen()
    expect(screen.queryByText('Child')).not.toBeOnTheScreen()
    await openMember('Soft Dev')
    expect(mockSheetProps.get('Soft Dev')?.lines).toEqual(['Member · Child', 'Awaiting verification by the estate office'])
  })
})

describe('removing a member from the Household card (the reported bug)', () => {
  beforeEach(() => after.mockClear())

  it('Remove on the row opens the sheet, over the screen, named in full', async () => {
    await render(<ProfileHousehold />)
    expect(screen.queryByText(removeTitle)).not.toBeOnTheScreen()
    await fireEvent.press(await removeRow())
    expect(screen.getByText(removeTitle)).toBeOnTheScreen()
    expect(screen.getByLabelText(removeTitle)).toHaveProp('role', 'alertdialog')
    expect(screen.getByRole('button', { name: 'Remove Soft Dev' })).toBeEnabled()
    expect(screen.queryByText(/Remove Soft\b(?! Dev)/)).not.toBeOnTheScreen()
    expect(screen.queryByText(/Keep Soft/)).not.toBeOnTheScreen()
  })

  it('Cancel changes nothing: nothing is sent and nobody is removed', async () => {
    await render(<ProfileHousehold />)
    await fireEvent.press(await removeRow())
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel' }))
    await settle()
    expect(screen.queryByText(removeTitle)).not.toBeOnTheScreen()
    expect(mockCalls).toEqual([])
    expect(after).not.toHaveBeenCalled()
  })

  it('confirm calls members.destroy ONCE, for that member, and the sheet closes on the answer', async () => {
    const later = mockLater()
    mockAnswer = () => later.answer
    await render(<ProfileHousehold />)
    await fireEvent.press(await removeRow())
    await fireEvent.press(screen.getByRole('button', { name: 'Remove Soft Dev' }))
    // On its way: the button is busy, and a second press sends nothing more.
    expect(screen.getByRole('button', { name: 'Remove Soft Dev' })).toBeBusy()
    await fireEvent.press(screen.getByRole('button', { name: 'Remove Soft Dev' }))
    await act(async () => later.give({ data: { removed: true } }))
    await settle()
    expect(mockCalls).toEqual([{ endpoint: 'members.destroy', arg: { member: '7' } }])
    expect(after).toHaveBeenCalledWith(SOFT)
    expect(screen.queryByText(removeTitle)).not.toBeOnTheScreen()
  })

  it('a 409 stays IN the sheet, in the server’s words, and nobody is taken off the list', async () => {
    mockAnswer = () => CONFLICT('This person is not on your household’s register.')
    await render(<ProfileHousehold />)
    await fireEvent.press(await removeRow())
    await fireEvent.press(screen.getByRole('button', { name: 'Remove Soft Dev' }))
    await settle()
    expect(screen.getByText(removeTitle)).toBeOnTheScreen()
    expect(screen.getByRole('alert')).toHaveTextContent('This person is not on your household’s register.')
    expect(after).not.toHaveBeenCalled()
  })

  it('offline: confirm is disabled with REMOVE_OFFLINE, and nothing is sent', async () => {
    mockState.network.online = false
    await render(<ProfileHousehold />)
    await fireEvent.press(await removeRow())
    const button = screen.getByRole('button', { name: 'Remove Soft Dev' })
    expect(button).toBeDisabled()
    expect(button).toHaveProp('accessibilityHint', REMOVE_OFFLINE)
    await fireEvent.press(button)
    await settle()
    expect(mockCalls).toEqual([])
  })

  it('never traps the resident: Close while it is being removed takes the sheet away, and a refusal brings it back', async () => {
    const later = mockLater()
    mockAnswer = () => later.answer
    await render(<ProfileHousehold />)
    await fireEvent.press(await removeRow())
    await fireEvent.press(screen.getByRole('button', { name: 'Remove Soft Dev' }))
    await fireEvent.press(screen.getByRole('button', { name: 'Close' }))
    expect(screen.queryByText(removeTitle)).not.toBeOnTheScreen()
    // Still that one removal, on its way: Remove again shows it — it never sends a second.
    await fireEvent.press(await removeRow())
    expect(screen.getByText(removeTitle)).toBeOnTheScreen()
    await fireEvent.press(screen.getByRole('button', { name: 'Close' }))
    await act(async () => later.give(CONFLICT('The estate office has locked this household. Nobody was removed.')))
    await settle()
    expect(screen.getByText(removeTitle)).toBeOnTheScreen()
    expect(screen.getByRole('alert')).toHaveTextContent('The estate office has locked this household. Nobody was removed.')
    expect(mockCalls).toHaveLength(1)
  })

  it('once Soft Dev is removed, the screen reader goes to "Add household member": their row, and its Remove, are gone', async () => {
    await render(<ProfileHousehold />)
    await fireEvent.press(await removeRow())
    await frame()
    const { opener, fallbacks } = focusPlan(removeTitle)
    // The member's row opened the sheet their Remove is in (M42 §5 #28): it is what focus would go back to.
    expect(String(labelOf(opener))).toMatch(/^Soft Dev, .*\. Open$/)
    const addRow = fallbacks.find((node) => labelOf(node) === 'Add household member')
    expect(addRow).toBeTruthy()
    await fireEvent.press(screen.getByRole('button', { name: 'Remove Soft Dev' }))
    await settle()
    // What a phone's node says once its row has left the screen (React Native's element, like a browser's).
    if (opener) opener.isConnected = false
    await frame()
    expect(lastFocused()).toBe('Add household member · focus')
  })
})

describe('the removal flow itself', () => {
  beforeEach(() => after.mockClear())

  it('refuses offline too, in case the connection went between drawing and pressing', async () => {
    const { result } = await renderHook(() => useMemberRemoval(after))
    mockState.network.online = false
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(REMOVE_OFFLINE)
    expect(mockCalls).toEqual([])
  })

  it('a demonstration session is refused before anything is sent', async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => useMemberRemoval(after))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(mockRefusal)
    expect(mockCalls).toEqual([])
  })

  it.each(['timeout', 'network'] as const)('no answer (%s) is said as not knowing: a delete that timed out may have gone through', async (status) => {
    mockAnswer = () => NO_ANSWER(status)
    const { result } = await renderHook(() => useMemberRemoval(after))
    await act(async () => result.current.ask(SOFT))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.open).toBe(true)
    expect(result.current.problem).toBe(REMOVE_NO_ANSWER)
    expect(result.current.problem).not.toBe(REMOVE_OFFLINE)
    expect(after).not.toHaveBeenCalled()
  })
})

describe('what the cancel-invite sheet says', () => {
  it('names the person in full, and both buttons say what they do to the invite', () => {
    const words = cancelInviteSheet({ full_name: 'Soft Dev' }, true)
    expect(words.title).toBe("Cancel Soft Dev's invite?")
    expect(words.lines).toEqual([cancelInviteSentence('Soft Dev')])
    expect(words.confirmLabel).toBe('Cancel the invite')
    expect(words.cancelLabel).toBe('Keep the invite')
    expect(words.blocked).toBeNull()
  })

  it('offline, the reason is about CANCELLING, not the sentence about sending an invite', () => {
    expect(cancelInviteSheet({ full_name: 'Soft Dev' }, false).blocked).toBe(CANCEL_INVITE_OFFLINE)
    expect(CANCEL_INVITE_OFFLINE).not.toBe(INVITE_OFFLINE)
    expect(CANCEL_INVITE_OFFLINE).toMatch(/^Cancelling an invite needs a connection/)
  })
})

describe('cancelling an invite from the Household card', () => {
  const SENT = { status: 'sent', email: 'softdev.571@gmail.com', sent_at: '2026-09-25T15:00:00Z', last_attempt_at: '2026-09-25T15:00:00Z', reason: null, expires_at: '2026-10-09T15:00:00Z', resend_after: null, attempts: 1 }
  const CANCELLED = { ...SENT, status: 'cancelled' }
  const invited = { ...SOFT, invite: SENT } as unknown as Member
  const cancelLink = async () => {
  if (!screen.queryByRole('button', { name: 'Cancel the invite to Soft Dev' })) await openMember('Soft Dev')
  return screen.getByRole('button', { name: 'Cancel the invite to Soft Dev' })
}
  const title = "Cancel Soft Dev's invite?"

  beforeEach(() => { mockAnswer = (endpoint) => (endpoint === 'members.invite_cancel' ? { data: { ...invited, invite: CANCELLED } } : { data: {} }) })

  it('Cancel invite under the row opens the sheet, named in full', async () => {
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    expect(screen.getByText(title)).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Keep the invite' })).toBeEnabled()
    expect(screen.queryByText(/Keep Soft/)).not.toBeOnTheScreen()
  })

  it('Keep the invite changes nothing', async () => {
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    await fireEvent.press(screen.getByRole('button', { name: 'Keep the invite' }))
    await settle()
    expect(screen.queryByText(title)).not.toBeOnTheScreen()
    expect(mockCalls).toEqual([])
  })

  it('confirm calls members.invite_cancel ONCE, the sheet closes, and the row says it is cancelled', async () => {
    const later = mockLater()
    mockAnswer = () => later.answer
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel the invite' }))
    expect(screen.getByRole('button', { name: 'Cancel the invite' })).toBeBusy()
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel the invite' }))
    await act(async () => later.give({ data: { ...invited, invite: CANCELLED } }))
    await settle()
    expect(mockCalls).toEqual([{ endpoint: 'members.invite_cancel', arg: { member: '7' } }])
    expect(screen.queryByText(title)).not.toBeOnTheScreen()
    // The row is only initials, name and role (M42 §5 #28): what became of the invite is in the member's sheet.
    expect(screen.queryByText('Invite cancelled.')).not.toBeOnTheScreen()
    await openMember('Soft Dev')
    expect(screen.getByText('Invite cancelled.')).toBeOnTheScreen()
  })

  it('a 409 stays IN the sheet, in the server’s words', async () => {
    mockAnswer = () => CONFLICT('There is no invite to cancel: they have already signed in.')
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel the invite' }))
    await settle()
    expect(screen.getByText(title)).toBeOnTheScreen()
    expect(screen.getByRole('alert')).toHaveTextContent('There is no invite to cancel: they have already signed in.')
  })

  it('offline: confirm is disabled with the CANCELLING sentence, and nothing is sent', async () => {
    mockState.network.online = false
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    const button = screen.getByRole('button', { name: 'Cancel the invite' })
    expect(button).toBeDisabled()
    expect(button).toHaveProp('accessibilityHint', CANCEL_INVITE_OFFLINE)
    await fireEvent.press(button)
    await settle()
    expect(mockCalls).toEqual([])
  })

  it.each(['timeout', 'network'] as const)('no answer (%s) is said as not knowing whether it was cancelled', async (status) => {
    mockAnswer = () => NO_ANSWER(status)
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel the invite' }))
    await settle()
    expect(screen.getByRole('alert')).toHaveTextContent(CANCEL_INVITE_NO_ANSWER)
  })

  it('closed while the cancel is on its way, the invite’s controls stay held until the answer', async () => {
    const later = mockLater()
    mockAnswer = () => later.answer
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel the invite' }))
    await fireEvent.press(screen.getByRole('button', { name: 'Close' }))
    expect(screen.queryByText(title)).not.toBeOnTheScreen()
    // Nothing else about the invite is pressed while it is being cancelled — and the link says why.
    expect(await cancelLink()).toBeDisabled()
    expect(await cancelLink()).toHaveProp('accessibilityHint', 'Wait: this invite is being changed now.')
    expect(screen.getByRole('button', { name: 'Resend invite to Soft Dev' })).toBeDisabled()
    await act(async () => later.give({ data: { ...invited, invite: CANCELLED } }))
    await settle()
    expect(screen.getByText('Invite cancelled.')).toBeOnTheScreen()
    expect(mockCalls).toHaveLength(1)
  })

  it('once cancelled, the screen reader goes to "Add household member": the invite lives in the member’s sheet', async () => {
    await render(<ProfileHousehold members={[PRIMARY, invited]} />)
    await fireEvent.press(await cancelLink())
    await frame()
    const { opener, fallbacks } = focusPlan(title)
    // The invite's controls are in the member's sheet now (M42 §5 #28): focus goes back to the member's own row.
    expect(String(labelOf(opener))).toMatch(/^Soft Dev, .*\. Open$/)
    expect(fallbacks.map((node) => labelOf(node))).toContain('Add household member')
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel the invite' }))
    await settle()
    await frame()
    // The card's own "Add household member" — the one control on it that never moves.
    expect(lastFocused()).toBe('Add household member · focus')
  })
})

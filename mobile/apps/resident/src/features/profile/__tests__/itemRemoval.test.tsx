/**
 * Removing an emergency contact, a vehicle or a pet (`contacts.destroy`, `vehicles.destroy`, `pets.destroy`).
 *
 * The user's screenshot listed "Marcia Ahmed · Spouse" four times and said "To remove a vehicle or a contact, contact
 * the estate office" — nothing on the phone could fix it. Now each one's own screen has Remove (M50 §5.3c, §5.4c),
 * asked in a ConfirmSheet naming it, sent once and only while connected, with the server's refusal kept in the sheet.
 */

import { act, renderHook } from '@testing-library/react-native'
import { ITEM_OFFLINE, itemNoAnswer, removeContactSheet, removePetSheet, removeVehicleSheet } from '../sheets'
import { addRecordsOf, type HouseholdOp } from '../household'
import { useItemRemoval } from '../useProfile'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/profile', useFocusEffect: jest.fn() }))

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const mockCalls: { endpoint: string; arg: unknown }[] = []
let mockAnswer: () => Answer = () => ({ data: { removed: true } })
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useMembersDestroyMutation: () => [async () => ({ data: { removed: true } }), { isLoading: false }],
    useContactsDestroyMutation: () => [async (arg: unknown) => { mockCalls.push({ endpoint: 'contacts.destroy', arg }); return mockAnswer() }, { isLoading: false }],
    useVehiclesDestroyMutation: () => [async (arg: unknown) => { mockCalls.push({ endpoint: 'vehicles.destroy', arg }); return mockAnswer() }, { isLoading: false }],
    usePetsDestroyMutation: () => [async (arg: unknown) => { mockCalls.push({ endpoint: 'pets.destroy', arg }); return mockAnswer() }, { isLoading: false }],
  },
}))
let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
jest.mock('@/features/auth/session', () => ({ closeSession: jest.fn(async () => undefined), refreshFromMe: jest.fn(async () => undefined) }))
jest.mock('@/offline/runtime', () => ({ drainNow: jest.fn(async () => undefined), outbox: { all: () => [], dismiss: jest.fn(async () => undefined), reset: jest.fn() } }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] as unknown[] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

const MARCIA = { id: 41, name: 'Marcia Ahmed', relationship: 'Spouse', phone: '876-555-0148' }
const COROLLA = { id: 9, plate: 'PPV 4471', make: 'Toyota', model: 'Corolla', colour: 'Silver' }
const BELLA = { id: 5, name: 'Bella', kind: 'dog', kind_label: 'Dog' }
const settle = async () => { await act(async () => { for (let i = 0; i < 6; i++) await Promise.resolve() }) }
const after = jest.fn()

beforeEach(() => {
  mockCalls.length = 0
  mockAnswer = () => ({ data: { removed: true } })
  mockRefusal = null
  mockState.network.online = true
  after.mockClear()
})

describe('what the sheets say', () => {
  it('names the contact in full, says what removing does to a panic, and is held offline with the reason', () => {
    const words = removeContactSheet({ name: '  Marcia   Ahmed ' }, true)
    expect(words.title).toBe('Remove Marcia Ahmed from your emergency contacts?')
    expect(words.confirmLabel).toBe('Remove Marcia Ahmed')
    expect(words.lines[0]).toMatch(/panic screen stops offering to text or call them/)
    expect(words.blocked).toBeNull()
    expect(removeContactSheet(MARCIA, false).blocked).toBe(ITEM_OFFLINE)
  })

  it('names the vehicle, and says the gate decides nothing from this list', () => {
    const words = removeVehicleSheet({ line: 'Silver Toyota Corolla · PPV 4471' }, true)
    expect(words.title).toBe('Remove Silver Toyota Corolla · PPV 4471 from your vehicles?')
    expect(words.lines[0]).toMatch(/nobody's entry changes/)
    expect(removeVehicleSheet({ line: 'PPV 4471' }, false).blocked).toBe(ITEM_OFFLINE)
  })

  it('names the pet (M50 §5.3c)', () => {
    const words = removePetSheet({ line: 'Bella · Dog' }, true)
    expect(words.title).toBe('Remove Bella · Dog from your pets?')
    expect(words.confirmLabel).toBe('Remove the pet')
    expect(removePetSheet({ line: 'Bella · Dog' }, false).blocked).toBe(ITEM_OFFLINE)
  })
})

describe.each([
  ['contact' as const, MARCIA, 'contacts.destroy', { contact: '41' }],
  ['vehicle' as const, COROLLA, 'vehicles.destroy', { vehicle: '9' }],
  ['pet' as const, BELLA, 'pets.destroy', { pet: '5' }],
])('removing a %s', (what, item, endpoint, arg) => {
  it(`sends ${endpoint} ONCE, for that row, and runs "after" only on the server's yes`, async () => {
    const { result } = await renderHook(() => useItemRemoval<{ id: number }>(what, after))
    await act(async () => result.current.ask(item))
    await act(async () => result.current.confirm())
    await act(async () => result.current.confirm())
    await settle()
    expect(mockCalls).toEqual([{ endpoint, arg }])
    expect(after).toHaveBeenCalledWith(item)
    expect(result.current.open).toBe(false)
  })

  it('offline, nothing is sent and the sheet keeps the reason', async () => {
    mockState.network.online = false
    const { result } = await renderHook(() => useItemRemoval<{ id: number }>(what, after))
    await act(async () => result.current.ask(item))
    await act(async () => result.current.confirm())
    await settle()
    expect(mockCalls).toEqual([])
    expect(result.current.problem).toBe(ITEM_OFFLINE)
    expect(after).not.toHaveBeenCalled()
  })

  it("a refusal stays in the sheet in the server's words; no answer says the phone cannot tell", async () => {
    mockAnswer = () => ({ error: { status: 404, code: 'not_found', message: 'That is not on your household’s list.', fieldErrors: {}, retryAfter: null } })
    const { result } = await renderHook(() => useItemRemoval<{ id: number }>(what, after))
    await act(async () => result.current.ask(item))
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.open).toBe(true)
    expect(result.current.problem).toBe('That is not on your household’s list.')

    mockAnswer = () => ({ error: { status: 'timeout', code: 'timeout', message: 'slow', fieldErrors: {}, retryAfter: null } })
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.problem).toBe(itemNoAnswer(what))
    expect(after).not.toHaveBeenCalled()
  })

  it('a demonstration session is refused before anything is sent', async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => useItemRemoval<{ id: number }>(what, after))
    await act(async () => result.current.ask(item))
    await act(async () => result.current.confirm())
    await settle()
    expect(mockCalls).toEqual([])
    expect(result.current.problem).toBe(mockRefusal)
  })
})

it("the queue's own record of ADDING a contact or vehicle goes with it, so it is never drawn back", () => {
  const op = (id: string, endpoint: string, result: unknown) => ({ id, endpoint, state: 'confirmed', result, body: {}, error: null }) as unknown as HouseholdOp
  const ops = [op('a', 'contacts.store', { id: 41, name: 'Marcia' }), op('b', 'vehicles.store', { id: 41, plate: 'X' }), op('c', 'contacts.store', { id: 42, name: 'Other' })]
  expect(addRecordsOf(ops, 41, 'contacts.store')).toEqual(['a'])
  expect(addRecordsOf(ops, 41, 'vehicles.store')).toEqual(['b'])
  expect(addRecordsOf(ops, 41)).toEqual([]) // members.store by default
})

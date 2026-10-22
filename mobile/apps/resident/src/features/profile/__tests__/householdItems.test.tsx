/**
 * M50 §5.2–§5.4 — A HOUSEHOLD ADDS, CHANGES AND REMOVES ITS VEHICLES, PETS AND EMERGENCY CONTACTS FROM PROFILE.
 *
 * The card "Vehicles & pets" listed two cars and offered no way to add, change or remove one; pets did not exist; the
 * emergency contact "form" was a read-only card with one Email box under the title "Add their email". Driven as a
 * resident drives it — the real screens and their real hooks, only the API and the phone's queue scripted:
 *
 *   - a pill or a contact opens its own screen, filled in; Save sends the CHANGE (`…update`, queued) for that row;
 *   - Remove lives inside that screen, asked in a sheet, and sent once (`…destroy`, online only);
 *   - "+ Add pet" adds a pet — a name and a kind from a chooser;
 *   - the contact form is Add household member's four boxes, its relationship a dropdown with its OWN list;
 *   - the Profile cards end in "+ Add vehicle", "+ Add pet", "+ Add emergency contact" — for whoever may change them.
 */

import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { router } from 'expo-router'
import {
  CONTACT_RELATIONSHIPS, contactRelationshipLabel, contactRows, petAlreadyListed, petBody, petLine, petRows, vehicleRows, type HouseholdOp,
} from '../household'
import AddContactScreen from '../screens/AddContactScreen'
import AddVehicleScreen from '../screens/AddVehicleScreen'
import PetScreen from '../screens/PetScreen'
import ProfileScreen from '../screens/ProfileScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
let mockParams: { id?: string } = {}
jest.mock('expo-router', () => ({
  router: { replace: jest.fn(), push: jest.fn(), back: jest.fn(), canGoBack: () => true, navigate: jest.fn(), dismissTo: jest.fn() },
  useLocalSearchParams: () => mockParams,
  usePathname: () => '/household',
  useFocusEffect: jest.fn(),
}))

const COROLLA = { id: 9, plate: 'PPV 154154', make: 'Toyota', model: 'Corolla', colour: 'Silver' }
const BELLA = { id: 5, name: 'Bella', kind: 'dog' as const, kind_label: 'Dog' }
const MARCIA = { id: 41, name: 'Marcia Ahmed', relationship: 'cousin', phone: '+1 876 555 0148', email: 'marcia@example.com' }

type Answer = { data?: unknown; error?: unknown }
const mockCalls: { endpoint: string; arg: unknown }[] = []
const mockSubmitted: { endpoint: string; params?: unknown; body: unknown }[] = []
const destroy = (endpoint: string) => () => [async (arg: unknown): Promise<Answer> => { mockCalls.push({ endpoint, arg }); return { data: { removed: true } } }, { isLoading: false }]
// One answer object per list, as RTK Query keeps one: a new object every render would be saved again every render.
const read = (items: unknown[]) => {
  const answer = { data: { items }, error: undefined, isLoading: false, isUninitialized: false, refetch: jest.fn() }
  return () => answer
}
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useVehiclesIndexQuery: read([COROLLA]),
    usePetsIndexQuery: read([BELLA]),
    useContactsIndexQuery: read([MARCIA]),
    useVehiclesDestroyMutation: destroy('vehicles.destroy'),
    usePetsDestroyMutation: destroy('pets.destroy'),
    useContactsDestroyMutation: destroy('contacts.destroy'),
    useMembersDestroyMutation: destroy('members.destroy'),
    useMembersLevelMutation: destroy('members.level'),
    useMembersAccessMutation: destroy('members.access'),
    useMembersInviteMutation: destroy('members.invite'),
    useMembersInviteCancelMutation: destroy('members.invite_cancel'),
  },
}))
jest.mock('@/offline/runtime', () => ({
  submit: jest.fn(async (input: { endpoint: string; params?: unknown; body: unknown }) => { mockSubmitted.push({ endpoint: input.endpoint, params: input.params, body: input.body }); return { id: `op-${mockSubmitted.length}` } }),
  drain: jest.fn(async () => undefined),
  drainNow: jest.fn(async () => undefined),
  outbox: { all: () => [], dismiss: jest.fn(async () => undefined), reset: jest.fn() },
}))
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => null }))
jest.mock('@/features/auth/session', () => ({ closeSession: jest.fn(async () => undefined), refreshFromMe: jest.fn(async () => undefined) }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] as unknown[] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))
let mockModel: Record<string, unknown>
jest.mock('../useProfile', () => ({ ...jest.requireActual('../useProfile'), useProfile: () => mockModel }))

import { getStorage } from '@gs/core/lib'

jest.setTimeout(30_000)

/** A form's Save runs its checks, then the queue, each a turn of the loop: a few whole turns, each inside act. */
const settle = async () => {
  for (let i = 0; i < 6; i++) await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
}
/** A tap, as a resident's finger makes it, and whatever it set going let finish. (fireEvent is itself an act: awaited.) */
const press = async (element: Parameters<typeof fireEvent.press>[0]) => {
  await fireEvent.press(element)
  await settle()
}

beforeEach(async () => {
  await getStorage().clearAll()
  mockCalls.length = 0
  mockSubmitted.length = 0
  mockParams = {}
  mockState.network.online = true
  jest.mocked(router.push).mockClear()
})

describe('the words and the rows', () => {
  it('a pet is a name and a kind, said as board 17 says "Milo · dog"; the same pet twice is one pet', () => {
    expect(petBody({ name: '  Bella  ', kind: 'dog' })).toEqual({ name: 'Bella', kind: 'dog' })
    expect(petLine({ name: 'Bella', kind: 'dog' })).toBe('Bella · dog')
    expect(petAlreadyListed({ name: 'BELLA', kind: 'dog' }, [BELLA])).toBe(true)
    expect(petAlreadyListed({ name: 'Bella', kind: 'cat' }, [BELLA])).toBe(false)
  })

  it('the contact list is its own, a doctor or a neighbour, never a tenant, and an old free-text one shows as written', () => {
    expect(CONTACT_RELATIONSHIPS.map((r) => r.label)).toEqual(expect.arrayContaining(['Doctor', 'Neighbour', 'Friend']))
    expect(CONTACT_RELATIONSHIPS.map((r) => r.label).join(' ')).not.toMatch(/Tenant|helper/i)
    expect(contactRelationshipLabel('Sibling')).toBe('Brother or sister')
    expect(contactRelationshipLabel('cousin')).toBe('Cousin')
  })

  it('a change still on this phone is drawn as typed, marked; a refused change leaves the row as the server holds it', () => {
    const op = (over: Partial<HouseholdOp>): HouseholdOp => ({ id: 'q1', endpoint: 'vehicles.update', params: { vehicle: 9 }, body: { plate: 'PPV 1541', make: 'Toyota', model: 'Corolla', colour: 'Grey' }, state: 'pending', error: null, result: null, ...over })
    expect(vehicleRows([COROLLA], [op({})])[0]).toMatchObject({ item: { plate: 'PPV 1541', colour: 'Grey' }, queue: 'pending' })
    expect(vehicleRows([COROLLA], [op({ state: 'refused', error: { message: 'No.' } })])[0]).toMatchObject({ item: { plate: 'PPV 154154' }, queue: null })
    expect(contactRows([MARCIA], [op({ endpoint: 'contacts.update', params: { contact: 41 }, body: { name: 'Marcia Brown', relationship: 'Sibling', phone: MARCIA.phone, email: MARCIA.email } })])[0]?.item.name).toBe('Marcia Brown')
    expect(petRows([], [op({ endpoint: 'pets.store', params: {}, body: { name: 'Rex', kind: 'cat' } })]).map((r) => petLine(r.item))).toEqual(['Rex · cat'])
  })
})

describe('a vehicle (M50 §5.3a–c, e–h)', () => {
  it('its pill opens it filled in; Save sends the CHANGE for that vehicle', async () => {
    mockParams = { id: '9' }
    await render(<AddVehicleScreen />)
    expect(screen.getByText('Edit vehicle')).toBeTruthy()
    expect(screen.getByDisplayValue('PPV 154154')).toBeTruthy()
    await fireEvent.changeText(screen.getByDisplayValue('Silver'), 'Grey')
    await press(screen.getByText('Save vehicle'))
    expect(screen.getByText('Vehicle changed')).toBeTruthy()
    expect(mockSubmitted).toEqual([{ endpoint: 'vehicles.update', params: { vehicle: '9' }, body: { plate: 'PPV 154154', make: 'Toyota', model: 'Corolla', colour: 'Grey' } }])
  })

  it('Remove is inside it, asked in a sheet, and sent once', async () => {
    mockParams = { id: '9' }
    await render(<AddVehicleScreen />)
    await press(screen.getByLabelText('Remove Silver Toyota Corolla · PPV 154154 from your vehicles'))
    expect(screen.getByText('Remove Silver Toyota Corolla · PPV 154154 from your vehicles?')).toBeTruthy()
    await press(screen.getByText('Remove the vehicle'))
    expect(mockCalls).toEqual([{ endpoint: 'vehicles.destroy', arg: { vehicle: '9' } }])
  })

  it('adding: no edit line, no Remove, and the footer that said it could not be changed is gone', async () => {
    await render(<AddVehicleScreen />)
    expect(screen.getByText('Add vehicle')).toBeTruthy()
    expect(screen.queryByText('Remove vehicle')).toBeNull()
    expect(screen.queryByText(/cannot be removed or changed/)).toBeNull()
  })
})

describe('a pet (M50 §5.3d)', () => {
  it('"+ Add pet": a name, a kind from the chooser, Save sends pets.store', async () => {
    await render(<PetScreen />)
    await fireEvent.changeText(screen.getByLabelText('Name'), 'Rex')
    await press(screen.getByRole('button', { name: /^Kind:/ }))
    await press(screen.getByRole('radio', { name: 'Cat' }))
    await press(screen.getByText('Save pet'))
    expect(screen.getByText('Pet added')).toBeTruthy()
    expect(mockSubmitted).toEqual([{ endpoint: 'pets.store', params: undefined, body: { name: 'Rex', kind: 'cat' } }])
  })

  it('the same pet again is said under the box, and nothing is sent', async () => {
    await render(<PetScreen />)
    await fireEvent.changeText(screen.getByLabelText('Name'), 'bella')
    await press(screen.getByRole('button', { name: /^Kind:/ }))
    await press(screen.getByRole('radio', { name: 'Dog' }))
    await press(screen.getByText('Save pet'))
    expect(screen.getAllByText("bella is already on your household's list.").length).toBeGreaterThan(0)
    expect(mockSubmitted).toEqual([])
  })

  it('its pill opens it; Remove is inside, sent once', async () => {
    mockParams = { id: '5' }
    await render(<PetScreen />)
    expect(screen.getByDisplayValue('Bella')).toBeTruthy()
    await press(screen.getByLabelText('Remove Bella · dog from your pets'))
    await press(screen.getByText('Remove the pet'))
    expect(mockCalls).toEqual([{ endpoint: 'pets.destroy', arg: { pet: '5' } }])
  })
})

describe('an emergency contact (M50 §5.2, §5.4)', () => {
  it('is Add household member with the access level and the invite taken out, and nothing it deleted', async () => {
    await render(<AddContactScreen />)
    for (const words of ['Add emergency contact', 'Full name', 'Relationship', 'Phone number', 'Email', 'Save emergency contact']) expect(screen.getAllByText(words).length).toBeGreaterThan(0)
    expect(screen.queryByText('Add their email')).toBeNull()
    expect(screen.queryByText(/Emailed at once if you raise an alert/)).toBeNull()
    expect(screen.queryByText('Access level')).toBeNull()
    expect(screen.queryByText('Send an app invite')).toBeNull()
  })

  it('opened from Profile it is filled in; a new relationship from its own list, and Save sends the change', async () => {
    mockParams = { id: '41' }
    await render(<AddContactScreen />)
    expect(screen.getByText('Edit emergency contact')).toBeTruthy()
    expect(screen.getByDisplayValue('Marcia Ahmed')).toBeTruthy()
    expect(screen.getByText('Cousin')).toBeTruthy()
    await press(screen.getByRole('button', { name: /^Relationship:/ }))
    await press(screen.getByRole('radio', { name: 'Doctor' }))
    await press(screen.getByText('Save emergency contact'))
    expect(screen.getByText('Emergency contact changed')).toBeTruthy()
    expect(mockSubmitted).toEqual([{ endpoint: 'contacts.update', params: { contact: '41' }, body: { name: 'Marcia Ahmed', relationship: 'Doctor', phone: '+1 876 555 0148', email: 'marcia@example.com' } }])
  })

  it('Remove is inside it, sent once', async () => {
    mockParams = { id: '41' }
    await render(<AddContactScreen />)
    await press(screen.getByLabelText('Remove Marcia Ahmed from your emergency contacts'))
    await press(screen.getByText('Remove Marcia Ahmed'))
    expect(mockCalls).toEqual([{ endpoint: 'contacts.destroy', arg: { contact: '41' } }])
  })
})

describe('the Profile cards (M50 §5.3a, §5.4a)', () => {
  const idle = { target: null, open: false, working: false, problem: null, ask: jest.fn(), cancel: jest.fn(), confirm: jest.fn() }
  const row = <T,>(item: T, id: number) => ({ key: `s:${id}`, item, queue: null, opId: null, refusal: null })
  const model = (mayEdit: boolean) => ({
    fixture: null,
    hero: { name: 'Hassam Ahmed', initials: 'HA', place: 'Phase 2 · Lot 47', role: 'Primary' },
    photo: { canChange: false, uri: null, hasPhoto: false, blocked: null, sheet: { open: false, ask: jest.fn(), take: jest.fn(), choose: jest.fn(), remove: jest.fn(), cancel: jest.fn() } },
    details: { status: 'loading' },
    members: { status: 'ready', rows: [], message: null },
    membersFetchedAt: 0,
    manage: { allowed: false, selfId: null, online: true, removal: idle, levelChange: idle, hoursChange: idle, levelsOpen: false },
    vehicles: { status: 'ready', rows: [row(COROLLA, 9)], message: null },
    pets: { status: 'ready', rows: [row(BELLA, 5)], message: null },
    contacts: { status: 'ready', rows: [row(MARCIA, 41)], message: null },
    mayEdit,
    denied: false,
    deniedMessage: '',
    keptBy: null,
    staleNote: null,
    waiting: 0,
    refreshing: false,
    refresh: jest.fn(),
    dismiss: jest.fn(),
  })

  it('end in "+ Add vehicle" and "+ Add emergency contact", and each row opens its own screen; no pet anywhere (M57 §6)', async () => {
    mockModel = model(true)
    await render(<ProfileScreen />)
    expect(screen.getByRole('link', { name: 'Add vehicle' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Add emergency contact' })).toBeTruthy()
    // Pets are out of the interface (flags.householdPets): the server's pet is neither shown nor addable.
    expect(screen.queryByRole('link', { name: 'Add pet' })).toBeNull()
    expect(screen.queryByText('Bella · dog')).toBeNull()
    expect(screen.getByText('Vehicles')).toBeTruthy()
    expect(screen.queryByText('Vehicles & pets')).toBeNull()

    await press(screen.getByRole('button', { name: 'Silver Toyota Corolla · PPV 154154. Edit' }))
    await press(screen.getByRole('link', { name: 'Marcia Ahmed · cousin · +1 876 555 0148. Edit' }))
    expect(jest.mocked(router.push).mock.calls.map((call) => call[0])).toEqual(['/household/vehicle?id=9', '/household/contact?id=41'])
  })

  it('for a person the server does not let change them: the lists, and no way in', async () => {
    mockModel = model(false)
    await render(<ProfileScreen />)
    expect(screen.queryByRole('link', { name: 'Add vehicle' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Add emergency contact' })).toBeNull()
    expect(screen.queryByRole('button', { name: /\. Edit$/ })).toBeNull()
    expect(screen.getByText('Silver Toyota Corolla · PPV 154154')).toBeTruthy()
    expect(screen.queryByText('Bella · dog')).toBeNull()
  })
})

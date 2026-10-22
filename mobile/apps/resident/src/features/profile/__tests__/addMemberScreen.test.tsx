/**
 * resident-app-32 · Add household member, held to M47 §15 / M48 §2.2: the board's elements and nothing else — Full name,
 * Relationship, Phone number (no "(optional)"), Email, Access level, "Send an app invite" with its switch ON and the
 * line "They'll get their own login by email", Save — no "no email" tick, no "by SMS", and no system dialog (U7): a
 * level the server does not give yet is answered under the control.
 *
 * The screen is drawn from a scripted model (useAddMember is the form's logic, tested in household.test.ts); the form
 * control is react-hook-form's own.
 */

import { Alert } from 'react-native'
import { fireEvent, render, screen } from '@testing-library/react-native'
import { LEVELS } from '../household'
import AddMemberScreen from '../screens/AddMemberScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn(), back: jest.fn(), canGoBack: () => true }, usePathname: () => '/household/add', useFocusEffect: jest.fn() }))
jest.mock('../useInvites', () => ({ useInvites: () => ({ withAnswer: (m: unknown) => m, now: 0, online: true, problem: null, working: null, send: jest.fn() }) }))

const mockModel = {
  allowed: true as boolean | null,
  levelRefused: false,
  saveError: null as string | null,
  invite: true,
  toggleInvite: jest.fn(),
  chooseLevel: jest.fn(),
}
jest.mock('../useHouseholdForms', () => {
  const { useForm } = jest.requireActual('react-hook-form')
  const { LEVELS: levels, RELATIONSHIPS } = jest.requireActual('../household')
  return {
    useAddMember: () => {
      const form = useForm({ defaultValues: { full_name: '', relationship: '', phone: '', email: '', invite: true } })
      return {
        online: true, busy: false, recorded: null, retryNow: jest.fn(), changeAndResend: jest.fn(), clear: jest.fn(), capture: jest.fn(),
        nameLit: false, control: form.control, errors: {}, firstError: null,
        relationships: RELATIONSHIPS, relationship: null, relationshipLabel: null, chooseRelationship: jest.fn(),
        hasEmail: false, emailSuggestion: null, acceptEmailSuggestion: jest.fn(),
        levelsOpen: false, levels, level: 'member', capturedOffline: false, added: null, send: jest.fn(), startAnother: jest.fn(),
        ...mockModel,
      }
    },
  }
})

jest.setTimeout(30_000)

beforeEach(() => {
  mockModel.levelRefused = false
  mockModel.saveError = null
  mockModel.invite = true
  jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)
})
afterEach(() => jest.restoreAllMocks())

describe('Add household member (M47 §15)', () => {
  it('draws the board top to bottom and nothing else', async () => {
    await render(<AddMemberScreen />)
    for (const words of ['Add household member', 'Full name', 'Relationship', 'Phone number', 'Email', 'Access level', 'Send an app invite', "They'll get their own login by email", 'Save household member']) {
      expect(screen.getAllByText(words).length).toBeGreaterThan(0)
    }
    for (const level of LEVELS) expect(screen.getByRole('radio', { name: level.label })).toBeTruthy()
    // Deleted (§15a–g, §15.4): none of these is on the screen.
    expect(screen.queryByText(/\(optional\)/)).toBeNull()
    expect(screen.queryByText(/by SMS/)).toBeNull()
    expect(screen.queryByText(/This person has no email/)).toBeNull()
    expect(screen.queryByText(/The control room calls it/)).toBeNull()
    expect(screen.queryByText(/Emailed at once/)).toBeNull()
    expect(screen.queryByText(/Type their email above/)).toBeNull()
    expect(screen.queryByText(/cannot be changed from the app/)).toBeNull()
    expect(screen.queryByText(/Estate time/)).toBeNull()
  })

  it('the invite switch is OFF by default (M52 §6), and turning it on is the resident’s choice: no dialog', async () => {
    mockModel.invite = false
    await render(<AddMemberScreen />)
    const toggle = screen.getByRole('switch', { name: 'Send an app invite' })
    expect(toggle.props['aria-checked'] ?? toggle.props.accessibilityState?.checked).toBe(false)
    await fireEvent.press(toggle)
    expect(mockModel.toggleInvite).toHaveBeenCalled()
    expect(Alert.alert).not.toHaveBeenCalled()
  })

  it('never says Dependent and Helper are "not switched on yet": the line is deleted (M51-A §1), and no dialog either', async () => {
    mockModel.levelRefused = true
    await render(<AddMemberScreen />)
    await fireEvent.press(screen.getByRole('radio', { name: 'Helper' }))
    expect(mockModel.chooseLevel).toHaveBeenCalledWith('helper')
    expect(screen.queryByText(/not switched on yet/)).toBeNull()
    expect(screen.queryByText(/everyone is a Member/)).toBeNull()
    expect(Alert.alert).not.toHaveBeenCalled()
  })

  it('a save that could not be kept is said above the button, never in a dialog', async () => {
    mockModel.saveError = 'This could not be saved on this phone.'
    await render(<AddMemberScreen />)
    expect(screen.getByText('This could not be saved on this phone.')).toBeTruthy()
    expect(Alert.alert).not.toHaveBeenCalled()
  })
})

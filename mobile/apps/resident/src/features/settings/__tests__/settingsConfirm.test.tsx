/**
 * THE SETTINGS SCREENS ASK IN A SHEET TOO (DESIGN §12.2 rows 3-5): Sign out on Settings and on Security, and Clear
 * saved data on Privacy. Each used to swap a card into the page — at the very bottom on Settings and Security, where
 * a hack scrolled the screen to find it. `SettingsParts.Confirm` is now a thin wrapper round the ConfirmSheet, the
 * row that opens it stays on the screen, and the models run on confirmFlow: done once, a failure stays in the sheet.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react-native'
import { getStorage } from '@gs/core'
import { Confirm } from '../components/SettingsParts'
import { usePrivacy, useSignOut } from '../useSettings'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
const mockReplace = jest.fn()
jest.mock('expo-router', () => ({ router: { replace: (...a: unknown[]) => mockReplace(...a), push: jest.fn(), back: jest.fn() }, usePathname: () => '/settings', useFocusEffect: jest.fn() }))
jest.mock('expo-image', () => ({ Image: { clearMemoryCache: jest.fn(async () => true), clearDiskCache: jest.fn(async () => true) } }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => ({ status: 'granted', granted: true, canAskAgain: true })), requestForegroundPermissionsAsync: jest.fn() }))
jest.mock('@gs/core/notify', () => ({ notificationPermission: jest.fn(async () => 'granted') }))
const mockClose = jest.fn(async () => undefined)
jest.mock('@/features/auth/session', () => ({ closeSession: () => mockClose() }))
const mockDrain = jest.fn(async () => undefined)
jest.mock('@/offline/runtime', () => ({ drain: () => mockDrain() }))
type Op = { id: string; endpoint: string; state: string; label: string }
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] as Op[] } }
const mockDispatch = jest.fn()
jest.mock('@/store', () => ({ useAppDispatch: () => mockDispatch, useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)
const settle = async () => { await act(async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); await new Promise((r) => setTimeout(r, 5)) }) }

beforeEach(() => {
  mockClose.mockReset()
  mockClose.mockImplementation(async () => undefined)
  mockDrain.mockClear()
  mockReplace.mockClear()
  mockDispatch.mockClear()
  mockState.outbox.ops = []
})

describe('SettingsParts.Confirm is the ConfirmSheet', () => {
  const props = { title: 'Sign out of this phone?', lines: ['Everything the app saved on this phone is removed. You will need a new sign-in code to come back.'], confirmLabel: 'Sign out', working: false }

  it('closed, nothing is drawn in the page any more', async () => {
    await render(<Confirm open={false} {...props} onConfirm={jest.fn()} onCancel={jest.fn()} />)
    expect(screen.toJSON()).toBeNull()
  })

  it('open, the question is over the screen with its sentences, the button in full, and Cancel', async () => {
    const onCancel = jest.fn()
    const onConfirm = jest.fn()
    await render(<Confirm open {...props} onConfirm={onConfirm} onCancel={onCancel} />)
    expect(screen.getByLabelText('Sign out of this phone?')).toHaveProp('role', 'alertdialog')
    expect(screen.getByText(props.lines[0]!)).toBeOnTheScreen()
    await fireEvent.press(screen.getByRole('button', { name: 'Cancel' }))
    expect(onCancel).toHaveBeenCalledTimes(1)
    await fireEvent.press(screen.getByRole('button', { name: 'Sign out' }))
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('blocked says why and cannot be pressed; a refusal is shown inside', async () => {
    const onConfirm = jest.fn()
    await render(<Confirm open {...props} blocked="An emergency alert from this phone has not reached the estate yet." problem="Signing out did not finish." onConfirm={onConfirm} onCancel={jest.fn()} />)
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeDisabled()
    expect(screen.getByRole('alert')).toHaveTextContent('Signing out did not finish.')
    await fireEvent.press(screen.getByRole('button', { name: 'Sign out' }))
    expect(onConfirm).not.toHaveBeenCalled()
  })
})

describe('signing out from Settings or Security', () => {
  it('asks first; Cancel keeps the session', async () => {
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    expect(result.current.asking).toBe(true)
    expect(mockDrain).toHaveBeenCalled()
    await act(async () => result.current.dismiss())
    expect(result.current.asking).toBe(false)
    expect(mockClose).not.toHaveBeenCalled()
  })

  it('confirm signs out ONCE and goes to Log In', async () => {
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    await act(async () => {
      result.current.confirm()
      result.current.confirm()
    })
    await settle()
    expect(mockClose).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })

  it('a sign-out that did not finish is SAID, in the sheet: it used to be swallowed without a word', async () => {
    mockClose.mockImplementation(async () => { throw new Error('storage') })
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.asking).toBe(true)
    expect(result.current.problem).toBe('Signing out did not finish, so you are still signed in on this phone. Try again.')
    expect(mockReplace).not.toHaveBeenCalled()
  })

  it('an emergency alert not yet sent blocks it', async () => {
    mockState.outbox.ops = [{ id: 'op-1', endpoint: 'duress.store', state: 'pending', label: 'Emergency alert' }]
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    expect(result.current.blocked).toMatch(/emergency alert/)
    await act(async () => result.current.confirm())
    await settle()
    expect(mockClose).not.toHaveBeenCalled()
  })
})

describe('clearing the data saved on this phone (Privacy)', () => {
  it('asks first, clears only the saved copies, then closes and says what it did', async () => {
    getStorage().set('v1:cache:notices.index', '{"items":[]}')
    const { result } = await renderHook(() => usePrivacy())
    await act(async () => result.current.clearing.ask())
    expect(result.current.clearing.asking).toBe(true)
    await act(async () => result.current.clearing.confirm())
    await settle()
    expect(result.current.clearing.asking).toBe(false)
    expect(result.current.clearing.problem).toBeNull()
    expect(result.current.clearing.done).toMatch(/^Cleared\./)
    expect(getStorage().getString('v1:cache:notices.index')).toBeNull()
  })

  it('Cancel clears nothing', async () => {
    getStorage().set('v1:cache:notices.index', '{"items":[]}')
    const { result } = await renderHook(() => usePrivacy())
    await act(async () => result.current.clearing.ask())
    await act(async () => result.current.clearing.dismiss())
    await settle()
    expect(result.current.clearing.asking).toBe(false)
    expect(getStorage().getString('v1:cache:notices.index')).toBe('{"items":[]}')
  })
})

describe('the three screens', () => {
  const read = (file: string) => readFileSync(path.join(__dirname, '..', 'screens', file), 'utf8')

  // Board 19 (Security) draws no sign-out of this phone: since the M42 rebuild only Settings (board 18) has it.
  it('Settings keeps its Sign out card, opens the sheet, and no longer scrolls to find a card', () => {
    for (const file of ['SettingsScreen.tsx']) {
      const source = read(file)
      expect(source).toMatch(/<Confirm\s+open=\{out\.asking\}/)
      expect(source).not.toContain('scrollToEnd')
      expect(source).not.toContain('onContentSizeChange')
      expect(source).not.toMatch(/\{out\.asking \?/)
    }
  })

  it('Privacy keeps its Clear row and opens the sheet over it', () => {
    const source = read('PrivacyScreen.tsx')
    expect(source).toMatch(/<Confirm\s+open=\{clearing\.asking\}/)
    expect(source).not.toMatch(/\{clearing\.asking \?/)
  })
})

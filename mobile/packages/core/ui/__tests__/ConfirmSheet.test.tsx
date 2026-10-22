/**
 * J-CS-1 — A CONFIRMATION THAT CANNOT BE MISSED, AND CANNOT BE MISREAD.
 *
 * The user (24 September): "When I click Remove, instead of showing a popup it shows at the bottom. Sometimes the
 * user doesn't feel there is any section below." The card was drawn under the list, off the screen, and its buttons
 * read "Remove Soft" / "Keep Soft". Rendered here the way a phone renders it: a Modal over the screen, the whole
 * name on the title and the button, the server's refusal INSIDE it, and nothing but the red button ever confirms.
 */

import { useRef, useState } from 'react'
import { AccessibilityInfo, AppState, Platform, View } from 'react-native'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { palette } from '../../theme/colors'
import { ConfirmSheet, type ConfirmSheetProps } from '../ConfirmSheet'
import { GuardButton } from '../guard/GuardButton'
import { ResidentButton } from '../resident/ResidentButton'
import { setCovered } from '../overlayCover'

jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))

/** The screen the sheet sits on: its route, and a way to blur it and focus it again as a navigator would. */
let mockPath = '/(tabs)/profile'
const mockScreens = new Set<{ blur: () => void; focus: () => void }>()
jest.mock('expo-router', () => {
  const { useEffect } = jest.requireActual<typeof import('react')>('react')
  return {
    usePathname: () => mockPath,
    useFocusEffect: (effect: () => void | (() => void)) => {
      useEffect(() => {
        let cleanup = effect()
        const screen = { blur: () => { cleanup?.(); cleanup = undefined }, focus: () => { cleanup = effect() } }
        mockScreens.add(screen)
        return () => {
          mockScreens.delete(screen)
          cleanup?.()
        }
      }, [effect])
    },
  }
})

// The first render in a worker loads the whole kit (fonts, icons, the Modal): give it room on a cold cache.
jest.setTimeout(30_000)

const TITLE = 'Remove Soft Dev from your household?'
const LINE = 'Soft Dev leaves your household’s list at once and can no longer use the Resident App for this home.'

function sheet(over: Partial<ConfirmSheetProps> = {}) {
  const onConfirm = jest.fn()
  const onCancel = jest.fn()
  const props: ConfirmSheetProps = { open: true, title: TITLE, lines: [LINE], confirmLabel: 'Remove Soft Dev', working: false, onConfirm, onCancel, ...over }
  return { props, onConfirm: (over.onConfirm as jest.Mock | undefined) ?? onConfirm, onCancel: (over.onCancel as jest.Mock | undefined) ?? onCancel }
}

const confirmButton = (name = 'Remove Soft Dev') => screen.getByRole('button', { name })
const cancelButton = (name = 'Cancel') => screen.getByRole('button', { name })
const backdrop = () => screen.getByTestId('confirm-sheet-backdrop', { includeHiddenElements: true })
/** A tap on the scrim: it is not a control (no role, not a tab stop), so it answers to the touch itself. */
const tapBackdrop = async () => fireEvent(backdrop(), 'responderRelease')
/** The Modal itself — the first thing on the screen — which is where Android's back button lands. */
const modal = () => {
  const root = screen.root
  if (!root) throw new Error('nothing is on the screen')
  return root
}
const appStateHandler = (): ((state: string) => void) => {
  const calls = (AppState.addEventListener as jest.Mock).mock.calls.filter(([event]) => event === 'change')
  const handler = calls[calls.length - 1]?.[1] as ((state: string) => void) | undefined
  if (!handler) throw new Error('the sheet is not listening to AppState')
  return handler
}

beforeEach(() => {
  mockPath = '/(tabs)/profile'
  setCovered('app-lock', false)
  ;(AccessibilityInfo.sendAccessibilityEvent as jest.Mock).mockClear()
  ;(AccessibilityInfo.announceForAccessibility as jest.Mock).mockClear()
  ;(AppState.addEventListener as jest.Mock).mockClear()
})

afterEach(() => {
  jest.restoreAllMocks()
})

describe('what it shows', () => {
  it('closed, it renders nothing at all, so a measured board keeps its numbers', async () => {
    const { props } = sheet({ open: false })
    await render(<ConfirmSheet {...props} />)
    expect(screen.toJSON()).toBeNull()
  })

  it('open, it names the person IN FULL, on the title and on the button, and says what happens', async () => {
    const { props } = sheet({ lines: [LINE, 'Their open invite is cancelled too.'] })
    await render(<ConfirmSheet {...props} />)
    expect(screen.getByText(TITLE)).toBeOnTheScreen()
    expect(screen.getByText(LINE)).toBeOnTheScreen()
    expect(screen.getByText('Their open invite is cancelled too.')).toBeOnTheScreen()
    expect(confirmButton()).toBeEnabled()
    expect(cancelButton()).toBeEnabled()
    // A dialog a screen reader announces as one, named by its question.
    const dialog = screen.getByLabelText(TITLE)
    expect(dialog).toHaveProp('role', 'alertdialog')
    expect(dialog).toHaveProp('aria-modal', true)
    expect(screen.getByRole('heading', { name: TITLE })).toBeOnTheScreen()
  })

  it('draws the confirm button ABOVE Cancel, and a red OUTLINE when the act cannot be undone (M98: never solid red)', async () => {
    const { props } = sheet()
    await render(<ConfirmSheet {...props} />)
    const [first, second, ...rest] = screen.getAllByRole('button')
    expect(first).toHaveAccessibleName('Remove Soft Dev')
    expect(second).toHaveAccessibleName('Cancel')
    expect(rest).toEqual([])
    // Solid red is an open panic's colour and nothing else: a destructive act is white with a red-700 edge.
    expect(confirmButton()).toHaveStyle({ backgroundColor: palette.white, borderColor: palette['red-700'] })
    expect(confirmButton()).not.toHaveStyle({ backgroundColor: palette['red-700'] })
  })

  it('a question that is not destructive gets the ordinary primary button', async () => {
    const { props } = sheet({ destructive: false, confirmLabel: 'Change to Member' })
    await render(<ConfirmSheet {...props} />)
    expect(confirmButton('Change to Member')).toHaveStyle({ backgroundColor: palette['navy-600'] })
  })

  it('Cancel can be named for the act ("Keep the pass") when the act itself is called "cancel"', async () => {
    const { props, onCancel } = sheet({ title: 'Cancel Jane Brown’s pass?', confirmLabel: 'Cancel Jane Brown’s pass', cancelLabel: 'Keep the pass' })
    await render(<ConfirmSheet {...props} />)
    await fireEvent.press(cancelButton('Keep the pass'))
    expect(onCancel).toHaveBeenCalledTimes(1)
  })

  it('what it is asked about can be added under the lines (a picker, a list)', async () => {
    const { props } = sheet({ children: <View testID="extra" /> })
    await render(<ConfirmSheet {...props} />)
    expect(screen.getByTestId('extra')).toBeOnTheScreen()
  })

  it('only MEASURED colour pairs are drawn as text (the contrast gate reads apps/*, not this kit)', async () => {
    const guard = sheet({ tone: 'guard', problem: 'Signing out did not finish.', blocked: 'Not while an alert is live.' })
    await render(<ConfirmSheet {...guard.props} />)
    // Guard: red-500 and amber-500 are measured on surface-800 — so their boxes are surface-800, marked by a border.
    expect(screen.getByTestId('confirm-sheet-problem')).toHaveStyle({ backgroundColor: palette['surface-800'] })
    expect(screen.getByTestId('confirm-sheet-blocked')).toHaveStyle({ backgroundColor: palette['surface-800'] })
    const resident = sheet({ problem: 'That member has already been removed.', blocked: 'No connection.' })
    await screen.rerender(<ConfirmSheet {...resident.props} />)
    // Resident: a refusal is an ordinary problem, amber-700 on amber-100 like the blocked reason (M98), never red.
    expect(screen.getByTestId('confirm-sheet-problem')).toHaveStyle({ backgroundColor: palette['amber-100'] })
    expect(screen.getByTestId('confirm-sheet-blocked')).toHaveStyle({ backgroundColor: palette['amber-100'] })
  })

  it('the Guard App gets its own dark card and its own buttons', async () => {
    const { props } = sheet({ tone: 'guard', title: 'Sign out of this phone?', confirmLabel: 'Sign out', cancelLabel: 'Stay signed in' })
    await render(<ConfirmSheet {...props} />)
    expect(confirmButton('Sign out')).toHaveStyle({ backgroundColor: palette['red-700'], height: 52 })
    expect(screen.getByLabelText('Sign out of this phone?')).toHaveStyle({ backgroundColor: palette['surface-800'] })
  })

  it('never says the same sentence twice: a refusal that IS the blocked reason is shown once', async () => {
    // The connection went during the attempt: the flow's refusal and the reason the button is now disabled are the
    // same words. The old inline cards avoided this (`!online && !problem`); the sheet does too.
    const offline = 'Removing someone needs a connection. It takes their access away at once, so it is never saved to do later.'
    const { props } = sheet({ problem: offline, blocked: offline })
    await render(<ConfirmSheet {...props} />)
    expect(screen.getAllByText(offline)).toHaveLength(1)
    expect(screen.getByRole('alert')).toHaveTextContent(offline)
    expect(confirmButton()).toBeDisabled()
    expect(confirmButton()).toHaveProp('accessibilityHint', offline)
  })

  it('the backdrop is not a control: no role, hidden from screen readers, so a browser never makes it a tab stop', async () => {
    // react-native-web gives EVERY Pressable tabIndex 0 (`accessible={false}` is not read there), and its Modal
    // focuses the first focusable thing inside — which was this unlabelled full-screen backdrop, so Enter cancelled.
    const { props } = sheet()
    await render(<ConfirmSheet {...props} />)
    const scrim = backdrop()
    expect(scrim).toHaveProp('aria-hidden', true)
    expect(scrim.props.role).toBeUndefined()
    expect(scrim.props.onPress).toBeUndefined()
    expect(scrim.props.tabIndex).toBeUndefined()
  })
})

describe('what it does', () => {
  it('Cancel calls onCancel and never onConfirm', async () => {
    const { props, onConfirm, onCancel } = sheet()
    await render(<ConfirmSheet {...props} />)
    await fireEvent.press(cancelButton())
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('two quick presses on confirm call onConfirm ONCE', async () => {
    const { props, onConfirm } = sheet()
    await render(<ConfirmSheet {...props} />)
    await fireEvent.press(confirmButton())
    await fireEvent.press(confirmButton())
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('after a refusal the same sheet can be pressed again: the guard lets go once the attempt is over', async () => {
    const { props, onConfirm } = sheet()
    await render(<ConfirmSheet {...props} />)
    await fireEvent.press(confirmButton())
    await screen.rerender(<ConfirmSheet {...props} working />)
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="Nobody was removed: the estate office has locked this household." />)
    await fireEvent.press(confirmButton())
    expect(onConfirm).toHaveBeenCalledTimes(2)
  })

  it('the backdrop CANCELS, and never confirms', async () => {
    const { props, onConfirm, onCancel } = sheet()
    await render(<ConfirmSheet {...props} />)
    await tapBackdrop()
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it("Android's back button cancels", async () => {
    const { props, onConfirm, onCancel } = sheet()
    await render(<ConfirmSheet {...props} />)
    await fireEvent(modal(), 'requestClose')
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('while the write runs: confirm is busy, and the way out says it CLOSES the sheet: it cannot call the write off', async () => {
    const { props, onConfirm } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    expect(confirmButton()).toBeBusy()
    await fireEvent.press(confirmButton())
    expect(onConfirm).not.toHaveBeenCalled()
    // Not "Cancel": pressing it does not cancel anything, and the sheet says so before it is pressed.
    expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeOnTheScreen()
    expect(cancelButton('Close')).toBeEnabled()
    expect(screen.getByText(/Closing this does not stop it/)).toBeOnTheScreen()
  })

  // THE SHEET NEVER TRAPS ANYBODY (DESIGN INV-12). It is a window over the whole app — the Home tab's panic button
  // too — and one slow write can take most of a minute. So while it runs, Close, the backdrop and Android's back
  // all take the sheet off the screen at once; the write carries on, and what became of it comes back.
  it.each([
    ['Close', async () => fireEvent.press(cancelButton('Close'))],
    ['the backdrop', tapBackdrop],
    ["Android's back button", async () => fireEvent(modal(), 'requestClose')],
  ])('while the write runs, %s takes the sheet off the screen at once, and never confirms', async (_how, leave) => {
    const { props, onConfirm, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await leave()
    expect(onCancel).toHaveBeenCalledTimes(1) // the caller's flow hides it (confirmFlow never calls a write off)
    expect(onConfirm).not.toHaveBeenCalled()
    // Gone even when the caller does not close it: nobody is held in front of it.
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
  })

  it('a write it was closed on comes back when it is refused: the refusal is never lost', async () => {
    const { props } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await fireEvent.press(cancelButton('Close'))
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="Nobody was removed: the estate office has locked this household." />)
    expect(screen.getByText(TITLE)).toBeOnTheScreen()
    expect(screen.getByRole('alert')).toHaveTextContent('Nobody was removed: the estate office has locked this household.')
  })

  it("the server's refusal is shown INSIDE the sheet, as an alert", async () => {
    const { props } = sheet({ problem: 'That member has already been removed.' })
    await render(<ConfirmSheet {...props} />)
    expect(screen.getByRole('alert')).toHaveTextContent('That member has already been removed.')
    expect(screen.getByText(TITLE)).toBeOnTheScreen() // still the same question, still open
  })

  it('a refusal that arrives while the sheet is up is SPOKEN on an iPhone, not left to appear in silence', async () => {
    // role="alert" on a Text announces nothing on a phone. VoiceOver is told in words; TalkBack reads a live region.
    const { props } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="Nobody was removed: the estate office has locked this household." />)
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledTimes(1)
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledWith('Nobody was removed: the estate office has locked this household.')
    // The same words again (another render) are not said twice.
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="Nobody was removed: the estate office has locked this household." lines={[LINE, 'x']} />)
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledTimes(1)
  })

  it('on Android the refusal is in an assertive live region that is there before the words arrive', async () => {
    jest.replaceProperty(Platform, 'OS', 'android')
    const { props } = sheet()
    await render(<ConfirmSheet {...props} />)
    // Present while there is nothing in it: TalkBack reads a live region when what is INSIDE it changes.
    expect(screen.getByTestId('confirm-sheet-live', { includeHiddenElements: true })).toHaveProp('aria-live', 'assertive')
    await screen.rerender(<ConfirmSheet {...props} problem="That member has already been removed." />)
    expect(screen.getByTestId('confirm-sheet-live')).toHaveTextContent('That member has already been removed.')
    expect(AccessibilityInfo.announceForAccessibility).not.toHaveBeenCalled()
  })

  it('blocked: the confirm button is disabled and says why: to the eye and to a screen reader', async () => {
    const offline = 'Removing someone needs a connection. It takes their access away at once, so it is never saved to do later.'
    const { props, onConfirm } = sheet({ blocked: offline })
    await render(<ConfirmSheet {...props} />)
    expect(screen.getByText(offline)).toBeOnTheScreen()
    expect(confirmButton()).toBeDisabled()
    expect(confirmButton()).toHaveProp('accessibilityHint', offline)
    await fireEvent.press(confirmButton())
    expect(onConfirm).not.toHaveBeenCalled()
    expect(cancelButton()).toBeEnabled()
  })
})

describe('it never stands over something more important (INV-12)', () => {
  it('hides while the app lock covers the app, and comes back when it lifts', async () => {
    const { props } = sheet()
    await render(<ConfirmSheet {...props} />)
    await act(async () => setCovered('app-lock', true))
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
    await act(async () => setCovered('app-lock', false))
    expect(screen.getByText(TITLE)).toBeOnTheScreen()
  })

  it('the app going to the background drops the question (nothing was done)…', async () => {
    const { props, onCancel, onConfirm } = sheet()
    await render(<ConfirmSheet {...props} />)
    await act(async () => appStateHandler()('background'))
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('…but not a write already on its way', async () => {
    const { props, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await act(async () => appStateHandler()('background'))
    expect(onCancel).not.toHaveBeenCalled()
  })

  it('a refusal that came back while the app was away survives the app lock’s own prompt, and is there when it lifts', async () => {
    // Remove → the phone goes in a pocket while the write runs → it is refused → back a minute later: the lock
    // covers and asks for Face ID, which takes the app out of 'active' again. That must not drop the refusal.
    const { props, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await act(async () => appStateHandler()('background'))
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="Nobody was removed: the estate office has locked this household." />)
    await act(async () => appStateHandler()('active'))
    await act(async () => setCovered('app-lock', true))
    await act(async () => appStateHandler()('inactive')) // the lock's prompt
    await act(async () => appStateHandler()('active')) // the owner confirmed
    await act(async () => setCovered('app-lock', false))
    expect(onCancel).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent('Nobody was removed: the estate office has locked this household.')
  })

  it('under the cover, the lock’s prompt never drops a question: only the owner can see it once it lifts', async () => {
    const { props, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await act(async () => setCovered('app-lock', true))
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="The estate did not accept that." />)
    await act(async () => appStateHandler()('inactive'))
    await act(async () => appStateHandler()('active'))
    await act(async () => setCovered('app-lock', false))
    expect(onCancel).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent('The estate did not accept that.')
  })

  it('a refusal that lands between an iPhone going inactive and going to the background is kept too', async () => {
    const { props, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await act(async () => appStateHandler()('inactive'))
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="The estate did not accept that." />)
    await act(async () => appStateHandler()('background'))
    await act(async () => appStateHandler()('active'))
    expect(onCancel).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent('The estate did not accept that.')
  })

  it('a refusal to a write it was closed on, landing while the app is away, waits for the app to come back', async () => {
    // Closed while working, the question is out of sight until its answer: when that is a refusal, the sheet opens
    // again — possibly while the phone is in a pocket. Nobody has seen it yet, so leaving the app cannot drop it.
    // (The jest mock's currentState is a function; the phone's is the state's name.)
    const state = AppState as unknown as { currentState: unknown }
    const was = state.currentState
    state.currentState = 'inactive'
    try {
      const { props, onCancel } = sheet({ problem: 'The estate did not accept that.' })
      await render(<ConfirmSheet {...props} />)
      await act(async () => appStateHandler()('background'))
      expect(onCancel).not.toHaveBeenCalled()
      await act(async () => appStateHandler()('active'))
      expect(screen.getByRole('alert')).toHaveTextContent('The estate did not accept that.')
      // Seen now: leaving drops it as it drops any other.
      await act(async () => appStateHandler()('background'))
      expect(onCancel).toHaveBeenCalledTimes(1)
    } finally {
      state.currentState = was
    }
  })

  it('a refusal already seen, and an unanswered question, still go when the app is left', async () => {
    const { props, onCancel } = sheet({ problem: 'The estate did not accept that.' })
    await render(<ConfirmSheet {...props} />)
    await act(async () => appStateHandler()('background'))
    expect(onCancel).toHaveBeenCalledTimes(1)
  })

  it('what is kept for one write is not kept for the next question', async () => {
    const { props, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await act(async () => appStateHandler()('background'))
    await screen.rerender(<ConfirmSheet {...props} open={false} working={false} />) // it went through
    await act(async () => appStateHandler()('active'))
    await screen.rerender(<ConfirmSheet {...props} open working={false} />) // a new question
    await act(async () => appStateHandler()('background'))
    expect(onCancel).toHaveBeenCalledTimes(1)
  })

  it('its screen losing focus (a push tapped, a tab changed) drops an unanswered question', async () => {
    const { props, onCancel } = sheet()
    await render(<ConfirmSheet {...props} />)
    await act(async () => mockScreens.forEach((s) => s.blur()))
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
  })

  it('a write on its way when the screen blurs is left to finish, hidden, and its outcome is there on return', async () => {
    const { props, onCancel } = sheet({ working: true })
    await render(<ConfirmSheet {...props} />)
    await act(async () => mockScreens.forEach((s) => s.blur()))
    expect(onCancel).not.toHaveBeenCalled()
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
    await screen.rerender(<ConfirmSheet {...props} working={false} problem="The estate did not accept that." />)
    await act(async () => mockScreens.forEach((s) => s.focus()))
    expect(screen.getByRole('alert')).toHaveTextContent('The estate did not accept that.')
  })

  it('never opens on the emergency screens: except the panic screen’s own question', async () => {
    mockPath = '/panic'
    const { props } = sheet()
    await render(<ConfirmSheet {...props} />)
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
    await screen.rerender(<ConfirmSheet {...props} emergencyScreen />)
    expect(screen.getByText(TITLE)).toBeOnTheScreen()
  })

  it('nor on the Guard App’s duress screen', async () => {
    mockPath = '/duress'
    const { props } = sheet({ tone: 'guard' })
    await render(<ConfirmSheet {...props} />)
    expect(screen.queryByText(TITLE)).not.toBeOnTheScreen()
  })
})

describe('focus', () => {
  let trigger: View | null = null
  function Screen({ open }: { open: boolean }) {
    const ref = useRef<View>(null)
    const { props } = sheet({ open, returnFocusTo: ref })
    return (
      <>
        <View ref={(node) => { ref.current = node; trigger = node }} testID="trigger" />
        <ConfirmSheet {...props} />
      </>
    )
  }
  const frame = async () => { await act(async () => { await new Promise((resolve) => setTimeout(resolve, 30)) }) }

  it('moves to the question when it opens, and back to what opened it when it closes', async () => {
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Screen open={false} />)
    await frame()
    expect(send).not.toHaveBeenCalled()

    await screen.rerender(<Screen open />)
    await frame()
    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0]?.[1]).toBe('focus')
    expect(send.mock.calls[0]?.[0]).not.toBe(trigger)

    await screen.rerender(<Screen open={false} />)
    await frame()
    expect(send).toHaveBeenCalledTimes(2)
    expect(send).toHaveBeenLastCalledWith(trigger, 'focus')
  })

  it('goes back to the row’s own control even when that row holds the ref only while its question is up', async () => {
    let asked: View | null = null
    function Row({ open }: { open: boolean }) {
      const ref = useRef<View>(null)
      const { props } = sheet({ open, returnFocusTo: ref })
      return (
        <>
          {/* As ProfileScreen gives the ref: only to the member whose removal is being asked. */}
          <View ref={open ? (node) => { ref.current = node; if (node) asked = node } : undefined} testID="row-remove" />
          <ConfirmSheet {...props} />
        </>
      )
    }
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Row open={false} />)
    await screen.rerender(<Row open />)
    await frame()
    await screen.rerender(<Row open={false} />)
    await frame()
    expect(asked).not.toBeNull()
    expect(send).toHaveBeenLastCalledWith(asked, 'focus')
  })

  it('HIDING is not closing: under the app lock the screen reader is never sent to the control beneath it', async () => {
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Screen open />)
    await frame()
    expect(send).toHaveBeenCalledTimes(1) // the question
    await act(async () => setCovered('app-lock', true))
    await frame()
    expect(send).toHaveBeenCalledTimes(1) // nothing: the opener is under the lock
    await act(async () => setCovered('app-lock', false))
    await frame()
    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]?.[0]).not.toBe(trigger) // back to the question, which is on screen again
    await screen.rerender(<Screen open={false} />)
    await frame()
    expect(send).toHaveBeenLastCalledWith(trigger, 'focus') // closed: now it goes back
  })

  it('a question closed while it was out of sight moves nobody’s focus', async () => {
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Screen open />)
    await frame()
    await act(async () => setCovered('app-lock', true))
    await screen.rerender(<Screen open={false} />)
    await frame()
    await act(async () => setCovered('app-lock', false))
    await frame()
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('when what opened it is gone, its row was removed, focus goes to the nearest neighbour still on the screen', async () => {
    // After "Remove Soft Dev" the row and its Remove are gone: focus would be sent to a node that is no longer there.
    const gone = { isConnected: false } as unknown as View
    let neighbour: View | null = null
    function Removed({ open }: { open: boolean }) {
      const opener = useRef<View>(gone)
      const alsoGone = useRef<View>(gone)
      const next = useRef<View>(null)
      const { props } = sheet({ open, returnFocusTo: opener, fallbackFocusTo: [alsoGone, next] })
      return (
        <>
          <View ref={(node) => { next.current = node; neighbour = node }} testID="add-member" />
          <ConfirmSheet {...props} />
        </>
      )
    }
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Removed open />)
    await frame()
    await screen.rerender(<Removed open={false} />)
    await frame()
    expect(neighbour).not.toBeNull()
    expect(send).toHaveBeenLastCalledWith(neighbour, 'focus')
    expect(send).not.toHaveBeenCalledWith(gone, 'focus')
  })

  it('an answer that closes it in the same render as the press is still an answer: the neighbour first, not the opener', async () => {
    // Removed at once — the answer lands before the sheet has ever drawn "working". The opener is on its way off the
    // screen with its row, even though this render still has it, so the neighbour is where the screen reader goes.
    let opened: View | null = null
    let neighbour: View | null = null
    function Instant() {
      const [open, setOpen] = useState(true)
      const opener = useRef<View>(null)
      const next = useRef<View>(null)
      const { props } = sheet({ open, returnFocusTo: opener, fallbackFocusTo: [next], onConfirm: jest.fn(() => setOpen(false)) })
      return (
        <>
          <View ref={(node) => { opener.current = node; opened = node }} testID="row-remove" />
          <View ref={(node) => { next.current = node; neighbour = node }} testID="add-member" />
          <ConfirmSheet {...props} />
        </>
      )
    }
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Instant />)
    await frame()
    await fireEvent.press(confirmButton())
    await frame()
    expect(neighbour).not.toBeNull()
    expect(send).toHaveBeenLastCalledWith(neighbour, 'focus')
    expect(send).not.toHaveBeenCalledWith(opened, 'focus')
  })

  it('refused, then closed with Cancel, it goes back to the opener: the press alone does not make it an answer', async () => {
    let opened: View | null = null
    function Refused({ open, problem }: { open: boolean; problem: string | null }) {
      const opener = useRef<View>(null)
      const next = useRef<View>(null)
      const { props } = sheet({ open, problem, returnFocusTo: opener, fallbackFocusTo: [next] })
      return (
        <>
          <View ref={(node) => { opener.current = node; opened = node }} testID="row-remove" />
          <View ref={next} testID="add-member" />
          <ConfirmSheet {...props} />
        </>
      )
    }
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    await render(<Refused open problem={null} />)
    await frame()
    await fireEvent.press(confirmButton())
    // The refusal comes back in the very next render, with no "working" render between.
    await screen.rerender(<Refused open problem="The estate did not accept that." />)
    await frame()
    await screen.rerender(<Refused open={false} problem="The estate did not accept that." />)
    await frame()
    expect(opened).not.toBeNull()
    expect(send).toHaveBeenLastCalledWith(opened, 'focus')
  })

  it('shown again with a refusal in it, the screen reader goes to the refusal, the news, not the title', async () => {
    const send = AccessibilityInfo.sendAccessibilityEvent as jest.Mock
    const { props } = sheet({ problem: 'The estate did not accept that.' })
    await render(<ConfirmSheet {...props} />)
    await frame()
    const node = send.mock.calls[0]?.[0] as { props?: { testID?: string } } | undefined
    expect(node?.props?.testID).toBe('confirm-sheet-problem')
    // Focused, so it is read: not announced a second time on top of it.
    expect(AccessibilityInfo.announceForAccessibility).not.toHaveBeenCalled()
  })
})


describe('the buttons it is built from', () => {
  it('GuardButton has a danger variant: red-700, a white label, 52 tall', async () => {
    await render(<GuardButton variant="danger" label="Remove it from this phone" onPress={jest.fn()} />)
    const button = screen.getByRole('button', { name: 'Remove it from this phone' })
    expect(button).toHaveStyle({ backgroundColor: palette['red-700'], height: 52 })
    expect(screen.getByText('Remove it from this phone')).toHaveStyle({ color: palette.white })
  })

  it('a disabled danger GuardButton greys out, as the primary does', async () => {
    await render(<GuardButton variant="danger" label="Sign out" onPress={jest.fn()} disabled disabledReason="Not now" />)
    expect(screen.getByRole('button', { name: 'Sign out' })).toHaveStyle({ backgroundColor: palette['surface-700'] })
  })

  it('ResidentButton has a destructive variant: a red OUTLINE, white with a red-700 edge and red-700 words (M98)', async () => {
    await render(<ResidentButton variant="destructive" label="Delete my account" onPress={jest.fn()} />)
    const button = screen.getByRole('button', { name: 'Delete my account' })
    expect(button).toHaveStyle({ backgroundColor: palette.white, borderColor: palette['red-700'] })
    expect(button).not.toHaveStyle({ backgroundColor: palette['red-700'] })
    expect(screen.getByText('Delete my account')).toHaveStyle({ color: palette['red-700'] })
  })

  it('ResidentButton keeps solid red for the one control that raises an emergency alert', async () => {
    await render(<ResidentButton variant="danger" label="Emergency alert" onPress={jest.fn()} />)
    expect(screen.getByRole('button', { name: 'Emergency alert' })).toHaveStyle({ backgroundColor: palette['red-700'] })
  })

  it('both kit buttons take a ref, so a trigger can be given focus back', async () => {
    const guard: { current: View | null } = { current: null }
    const resident: { current: View | null } = { current: null }
    await render(
      <>
        <GuardButton ref={guard} label="Sign out" onPress={jest.fn()} />
        <ResidentButton ref={resident} label="Sign out" onPress={jest.fn()} />
      </>,
    )
    expect(guard.current).not.toBeNull()
    expect(resident.current).not.toBeNull()
  })
})

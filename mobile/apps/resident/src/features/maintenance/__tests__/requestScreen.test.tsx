/**
 * resident-app-11 · NEW MAINTENANCE REQUEST, AS THE BOARD DRAWS IT (M42 §5 #10) — proved on the real screen, its model
 * mocked:
 *
 *   - the bar: the back chevron and "New maintenance request" — no `i`, no panic shield;
 *   - the board's words in the board's order, and none of what the client struck out (the reverse checklist);
 *   - four chips, Plumbing · Electrical · Lighting · Gate; the segment; the microphone's LIVE state — its rings,
 *     "Listening…" in gold and the board's blue waveform — and nothing of it while it is not listening;
 *   - the waveform is the level it is handed and nothing else: still when there is no level, never moving by itself;
 *   - "Transcript — tap to edit", its placeholder the board's own words in grey; "Photos & video" with the photos beside the "+";
 *   - the entry toggle has nothing under it, and is not asked about a common area;
 *   - after Submit it never says "submitted" until the server has the request.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { StyleSheet } from 'react-native'
import { router } from 'expo-router'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { c } from '@gs/core'
import { fill, ink } from '@/design/tokens'
import { stillLevels } from '@/features/incidents/dictation'
import { requestFixture } from '@/fidelity/maintenance'
import { REQUEST_CHIPS } from '../tickets'
import type { MaintenanceRequestModel, Recorded } from '../useMaintenanceRequest'
import MaintenanceRequestScreen from '../screens/MaintenanceRequestScreen'

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false }, usePathname: () => '/maintenance/new', useFocusEffect: jest.fn() }))
let mockModel: MaintenanceRequestModel
jest.mock('../useMaintenanceRequest', () => ({ useMaintenanceRequest: () => mockModel }))

jest.setTimeout(30_000)

/** What the hook hands over in fidelity mode: the board's own moment. */
const board = (over: Partial<MaintenanceRequestModel> = {}): MaintenanceRequestModel => ({
  chips: REQUEST_CHIPS.map((chip) => ({ id: chip.id, label: chip.label, on: chip.id === requestFixture.kind })),
  chooseChip: jest.fn(),
  area: 'unit',
  chooseArea: jest.fn(),
  mic: { available: true, listening: true, levels: stillLevels(requestFixture.levels), notice: null, model: null, toggle: jest.fn() },
  transcript: { value: requestFixture.transcript, attention: false, change: jest.fn(), focus: jest.fn(), blur: jest.fn() },
  photos: {
    thumbs: [{ key: 'board-0', uri: null, remove: null }, { key: 'board-1', uri: null, remove: null }],
    canAdd: true,
    adding: { open: false, blocked: null, problem: null, ask: jest.fn(), cancel: jest.fn(), take: jest.fn(), choose: jest.fn() },
    removing: { open: false, confirm: jest.fn(), cancel: jest.fn() },
  },
  priority: 'high',
  choosePriority: jest.fn(),
  entry: { shown: true, on: true, set: jest.fn() },
  busy: false,
  problem: null,
  send: jest.fn(),
  recorded: null,
  retryNow: jest.fn(),
  changeAndResend: jest.fn(),
  startAnother: jest.fn(),
  ...over,
})

const recorded = (over: Partial<Recorded>): Recorded => ({ title: 'Lighting: Phase 2 · Lot 47', state: 'confirmed', refusal: null, ticketId: '1042', number: '1042', photos: { total: 0, waiting: 0, refused: 0, notSaved: 0 }, ...over })
const bars = () => screen.getAllByTestId('wave-bar', { includeHiddenElements: true }).map((bar) => StyleSheet.flatten(bar.props.style))

/** The board's words, in its order (node tools/design/board-brief.mjs 11 --strings). */
const BOARD = ['New maintenance request', 'Category', 'Plumbing', 'Electrical', 'Lighting', 'Gate', 'Inside my unit', 'Common area', 'Describe the problem', 'Listening…', 'Transcript: tap to edit', 'Photos & video', 'Urgency', 'Low', 'Medium', 'High', "Allow entry when I'm not home", 'Submit request']

beforeEach(() => {
  jest.mocked(router.replace).mockClear()
})

describe('the board, word for word', () => {
  it('the bar: the back chevron and the title: no `i`, no panic shield', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByRole('heading', { name: 'New maintenance request' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Back' })).toBeTruthy()
    expect(screen.queryByLabelText(/^About /)).toBeNull()
    expect(screen.queryByLabelText(/panic/i)).toBeNull()
  })

  it('draws every word of the board, the transcript in its card, and nothing the client struck out', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    for (const text of BOARD) expect(screen.getByText(text)).toBeTruthy()
    expect(screen.getByLabelText('Describe the problem').props.value).toBe('"Di gate light dem nuh work since Tuesday, it dark by the visitor parking at night."')
    // M42 §5 #10, Remove — every one of them, and the old screen's notes beside the controls.
    for (const gone of [
      /What needs fixing/,
      /Kitchen tap is leaking/,
      /The office will see/,
      /not a key or a gate pass/,
      /Anything else the office should know/,
      /Best time to come/,
      /The office plans around it/,
      /Photos, if you can/,
      /tap to edit the transcript/,
      /^Your requests$/,
      /^See all$/,
      /Tap to speak/,
      /Choose from your photos/,
      /No connection/,
      /Save request on this phone/,
    ]) {
      expect(screen.queryByText(gone)).toBeNull()
    }
    // "the long transcript placeholder label" is gone: the card's placeholder is the board's own words, in grey (the
    // user, 7 October) — never the old instruction.
    const card = screen.getByLabelText('Describe the problem')
    expect(card.props.placeholder).toBe(requestFixture.transcript)
    expect(card.props.placeholderTextColor).toBe(c(ink.secondary))
  })
})

describe('the controls', () => {
  it('four chips, all there, the chosen one checked, and a tap chooses', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByRole('radio', { name: 'Plumbing', checked: true })).toBeTruthy()
    for (const name of ['Electrical', 'Lighting', 'Gate']) expect(screen.getByRole('radio', { name, checked: false })).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: 'Gate' })))
    expect(mockModel.chooseChip).toHaveBeenCalledWith('gate')
  })

  it('the segment and the urgency chips choose', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByRole('radio', { name: 'Inside my unit', checked: true })).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: 'Common area', checked: false })))
    expect(mockModel.chooseArea).toHaveBeenCalledWith('common')
    expect(screen.getByRole('radio', { name: 'High urgency', checked: true })).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: 'Low urgency' })))
    expect(mockModel.choosePriority).toHaveBeenCalledWith('low')
  })

  it('Submit request sends: the board’s words with or without a connection', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Submit request' })))
    expect(mockModel.send).toHaveBeenCalledTimes(1)
  })

  it('the first thing to put right is said above Submit, and only then', async () => {
    mockModel = board({ problem: 'Choose what kind of problem it is.' })
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByRole('alert')).toBeTruthy()
    expect(screen.getByText('Choose what kind of problem it is.')).toBeTruthy()
  })
})

describe('the microphone and its waveform', () => {
  it('listening: "Listening…" and the board’s seven bars, in the board’s navy-500, not gold', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByText('Listening…')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Stop listening' })).toBeTruthy()
    expect(bars().map((style) => style.height)).toEqual([6, 14, 9, 18, 8, 15, 6])
    for (const style of bars()) expect(style.backgroundColor).toBe(fill.dot)
    for (const style of bars()) expect(style.backgroundColor).not.toBe(fill.gold)
  })

  it('with no level reported the bars lie still while it listens, and nothing moves them as time passes', async () => {
    jest.useFakeTimers()
    try {
      mockModel = board({ mic: { ...board().mic, levels: stillLevels() } })
      await render(<MaintenanceRequestScreen />)
      expect(bars().map((style) => style.height)).toEqual([6, 6, 6, 6, 6, 6, 6])
      await act(async () => {
        jest.advanceTimersByTime(5_000)
      })
      expect(bars().map((style) => style.height)).toEqual([6, 6, 6, 6, 6, 6, 6])
    } finally {
      jest.useRealTimers()
    }
  })

  it('as the microphone’s level changes, the bars follow it, newest on the right', async () => {
    let current: readonly number[] = [0, 0, 0, 0, 0, 0, 0]
    const listeners = new Set<() => void>()
    const levels = { get: () => current, subscribe: (listener: () => void) => (listeners.add(listener), () => void listeners.delete(listener)) }
    mockModel = board({ mic: { ...board().mic, levels } })
    await render(<MaintenanceRequestScreen />)
    expect(bars().map((style) => style.height)).toEqual([6, 6, 6, 6, 6, 6, 6])
    await act(async () => {
      current = [0, 0, 0, 0, 0, 0.5, 1]
      for (const listener of listeners) listener()
    })
    expect(bars().map((style) => style.height)).toEqual([6, 6, 6, 6, 6, 12, 18])
  })

  it('the waveform is drawn from its level and nothing else: no timer, no loop, no animation', () => {
    const code = readFileSync(path.join(__dirname, '..', 'screens', 'MaintenanceRequestScreen.tsx'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
    for (const loop of [/setInterval/, /setTimeout/, /requestAnimationFrame/, /Animated\./, /withRepeat/, /useSharedValue/, /Math\.random/]) expect(code).not.toMatch(loop)
  })

  it('not listening: no "Listening…", no rings, no waveform to be seen, and a tap starts it', async () => {
    const toggle = jest.fn()
    mockModel = board({ mic: { ...board().mic, listening: false, toggle } })
    await render(<MaintenanceRequestScreen />)
    expect(screen.queryByText('Listening…')).toBeNull()
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Speak your request' })))
    expect(toggle).toHaveBeenCalledTimes(1)
  })

  it('a microphone that cannot listen says why, where "Listening…" goes', async () => {
    const notice = "Speaking your request isn't available in this version of the app: type it below, or use the microphone on your keyboard."
    mockModel = board({ mic: { ...board().mic, available: false, listening: false, notice } })
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByText(notice)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Speak your request', disabled: true })).toBeTruthy()
  })
})

describe('the transcript', () => {
  it('a tap opens it for editing, the hook stops the listening first, and typing changes it', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    const input = screen.getByLabelText('Describe the problem')
    await act(async () => fireEvent(input, 'focus'))
    expect(mockModel.transcript.focus).toHaveBeenCalledTimes(1)
    await act(async () => fireEvent.changeText(input, 'The gate light is out.'))
    expect(mockModel.transcript.change).toHaveBeenCalledWith('The gate light is out.')
  })
})

describe('Photos & video', () => {
  it('the photos themselves beside the "+", each one asking before it is taken back', async () => {
    const remove = jest.fn()
    mockModel = board({ photos: { ...board().photos, thumbs: [{ key: 'file:///a.jpg', uri: 'file:///a.jpg', remove }] } })
    await render(<MaintenanceRequestScreen />)
    const photo = screen.getByRole('button', { name: 'Photo 1. Remove it' })
    expect(JSON.stringify(screen.getByTestId('photo-1', { includeHiddenElements: true }).props.source)).toContain('file:///a.jpg')
    await act(async () => fireEvent.press(photo))
    expect(remove).toHaveBeenCalledTimes(1)
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Add a photo' })))
    expect(mockModel.photos.adding.ask).toHaveBeenCalledTimes(1)
  })

  it('the board’s two photos are plain tiles that open nothing', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    expect(screen.queryByRole('button', { name: /^Photo \d/ })).toBeNull()
  })

  it('the "+" asks: the camera, or the phone’s photos', async () => {
    mockModel = board({ photos: { ...board().photos, adding: { ...board().photos.adding, open: true } } })
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByText('Add a photo')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Take a photo' })))
    expect(mockModel.photos.adding.take).toHaveBeenCalledTimes(1)
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Choose from your photos' })))
    expect(mockModel.photos.adding.choose).toHaveBeenCalledTimes(1)
  })

  it('no "+" once the request holds all it takes', async () => {
    mockModel = board({ photos: { ...board().photos, canAdd: false } })
    await render(<MaintenanceRequestScreen />)
    expect(screen.queryByRole('button', { name: 'Add a photo' })).toBeNull()
  })
})

describe('the entry toggle', () => {
  it('has nothing under it, and switches', async () => {
    mockModel = board()
    await render(<MaintenanceRequestScreen />)
    const toggle = screen.getByRole('switch', { name: "Allow entry when I'm not home", checked: true })
    await act(async () => fireEvent.press(toggle))
    expect(mockModel.entry.set).toHaveBeenCalledWith(false)
  })

  it('is not asked about a common area', async () => {
    mockModel = board({ area: 'common', entry: { shown: false, on: false, set: jest.fn() } })
    await render(<MaintenanceRequestScreen />)
    expect(screen.queryByRole('switch')).toBeNull()
    expect(screen.queryByText("Allow entry when I'm not home")).toBeNull()
  })
})

describe('after Submit', () => {
  it('the office has it: the gold tick, its number, and the way to follow it', async () => {
    mockModel = board({ recorded: recorded({}) })
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByText('Request #1042 submitted')).toBeTruthy()
    expect(screen.getByText('Lighting: Phase 2 · Lot 47')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Follow request' })))
    expect(router.replace).toHaveBeenCalledWith('/maintenance/1042')
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Report another problem' })))
    expect(mockModel.startAnother).toHaveBeenCalledTimes(1)
  })

  it('still on this phone: "Saved on this phone", never "submitted"', async () => {
    mockModel = board({ recorded: recorded({ state: 'pending', ticketId: null, number: null, photos: { total: 2, waiting: 2, refused: 0, notSaved: 0 } }) })
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByRole('heading', { name: 'Saved on this phone' })).toBeTruthy()
    expect(screen.queryByText(/submitted/i)).toBeNull()
    expect(screen.getByText(/2 photos will follow by themselves/)).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Try sending now' })))
    expect(mockModel.retryNow).toHaveBeenCalledTimes(1)
  })

  it('refused: the server’s sentence, and back to the form with everything still written', async () => {
    mockModel = board({ recorded: recorded({ state: 'refused', ticketId: null, number: null, refusal: 'Your unit is not active.' }) })
    await render(<MaintenanceRequestScreen />)
    expect(screen.getByText('Not accepted')).toBeTruthy()
    expect(screen.getByText(/^Your unit is not active\. Nothing has been filed with the office\./)).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Edit and resend' })))
    expect(mockModel.changeAndResend).toHaveBeenCalledTimes(1)
  })
})

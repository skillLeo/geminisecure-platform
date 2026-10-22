/**
 * resident-app-14 · REPORT INCIDENT ON A REAL PHONE (M47 §13) — proved on the real screen, its model mocked:
 *
 *   - the four chips wrap onto a second row before one is ever cut — never clipped, never a sideways swipe (§13.1);
 *   - the page scrolls rather than cut anything off, and Submit has the board's 20 below it — or the home-indicator
 *     strip where that is deeper (§13.2);
 *   - the location row names a place in words — never degrees (§13.3);
 *   - no system dialog anywhere (U7): "Add a photo" and "Remove this photo?" are sheets, and why nothing was sent is
 *     a line above Submit.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { Alert, StyleSheet } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { STILL_DICTATION } from '@gs/core/speech'
import { stillLevels } from '../dictation'
import { INCIDENT_TYPES, LOCATION_MAX } from '../incidents'
import type { IncidentReportModel } from '../useIncidentReport'
import ReportIncidentScreen from '../screens/ReportIncidentScreen'

const metrics = (bottom: number) => ({ frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 0, left: 0, right: 0, bottom } })
const render = (ui: React.ReactElement, bottom = 0) => renderTree(<SafeAreaProvider initialMetrics={metrics(bottom)}>{ui}</SafeAreaProvider>)

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('react-native-keyboard-controller', () => require('react-native-keyboard-controller/jest'))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false }, usePathname: () => '/incident/new', useFocusEffect: jest.fn() }))
let mockModel: IncidentReportModel
jest.mock('../useIncidentReport', () => ({ useIncidentReport: () => mockModel }))

jest.setTimeout(30_000)

const model = (over: Partial<IncidentReportModel> = {}): IncidentReportModel => ({
  types: INCIDENT_TYPES,
  type: 'suspicious',
  chooseType: jest.fn(),
  dictation: { ...STILL_DICTATION, levels: stillLevels() },
  transcript: 'A grey pickup by the playground.',
  setTranscript: jest.fn(),
  transcriptError: null,
  media: {
    items: [{ key: 'file:///a.jpg', kind: 'photo', uri: 'file:///a.jpg' }],
    canAdd: true,
    adding: { open: false, blocked: null, problem: null, ask: jest.fn(), cancel: jest.fn(), take: jest.fn(), choose: jest.fn() },
    removing: { open: false, ask: jest.fn(), confirm: jest.fn(), cancel: jest.fn() },
  },
  where: { title: '12 Palm Drive, Portmore', line: 'Pinned from current location', place: '', setPlace: jest.fn(), fallback: '12 Palm Drive, Portmore', maxLength: LOCATION_MAX },
  keepNamePrivate: true,
  setKeepNamePrivate: jest.fn(),
  busy: false,
  send: jest.fn(),
  problem: null,
  recorded: null,
  retryNow: jest.fn(),
  changeAndResend: jest.fn(),
  ...over,
})

let alert: jest.SpyInstance
beforeEach(() => {
  alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)
})
afterEach(() => alert.mockRestore())

describe('§13.1: the chips wrap, never cut', () => {
  it('all four are there and tappable, in a row that wraps and clips nothing', async () => {
    mockModel = model()
    await render(<ReportIncidentScreen />)
    for (const t of INCIDENT_TYPES) expect(screen.getByRole('radio', { name: t.label })).toBeTruthy()
    const row = StyleSheet.flatten(screen.getByLabelText('Kind of incident').props.style)
    expect(row.flexWrap).toBe('wrap')
    expect(row.overflow).not.toBe('hidden')
    await act(async () => fireEvent.press(screen.getByRole('radio', { name: 'Trespass' })))
    expect(mockModel.chooseType).toHaveBeenCalledWith('trespass')
  })
})

describe('§13.2: Submit clears the bottom edge', () => {
  const padding = () => StyleSheet.flatten(screen.getByTestId('report-page').props.contentContainerStyle).paddingBottom

  it('the board’s 20 below it on a phone with no home indicator, the strip itself where that is deeper', async () => {
    mockModel = model()
    const first = await render(<ReportIncidentScreen />)
    expect(padding()).toBe(20)
    await act(async () => first.unmount())
    await render(<ReportIncidentScreen />, 48)
    // M57 §2: the gesture strip and one spacing step above it, never flush on it.
    expect(padding()).toBe(56)
  })
})

describe('§13.3: a place in words', () => {
  it('the row names the place and says how it was found: no degrees', async () => {
    mockModel = model()
    await render(<ReportIncidentScreen />)
    expect(screen.getByText('12 Palm Drive, Portmore')).toBeTruthy()
    expect(screen.getByText('Pinned from current location')).toBeTruthy()
    expect(screen.queryByText(/°/)).toBeNull()
  })
})

describe('U7: no system dialog', () => {
  it('"Add a photo" opens the sheet, and a photo asks in a sheet before it is taken off', async () => {
    mockModel = model()
    await render(<ReportIncidentScreen />)
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Add a photo' })))
    expect(mockModel.media.adding.ask).toHaveBeenCalledTimes(1)
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Photo 1. Take it off this report' })))
    expect(mockModel.media.removing.ask).toHaveBeenCalledWith('file:///a.jpg')
    expect(alert).not.toHaveBeenCalled()
  })

  it('the sheet offers the camera and the phone’s photos, and says what went wrong in itself', async () => {
    mockModel = model({ media: { ...model().media, adding: { ...model().media.adding, open: true, problem: 'The camera is not available.' } } })
    await render(<ReportIncidentScreen />)
    expect(screen.getByText('Take a photo')).toBeTruthy()
    expect(screen.getByText('Choose from your photos')).toBeTruthy()
    expect(screen.getByText('The camera is not available.')).toBeTruthy()
  })

  it('why nothing was sent is a line above Submit', async () => {
    mockModel = model({ problem: 'This is a demonstration account: nothing you do here is sent.' })
    await render(<ReportIncidentScreen />)
    expect(screen.getByText('This is a demonstration account: nothing you do here is sent.')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Submit report' })))
    expect(mockModel.send).toHaveBeenCalledTimes(1)
    expect(alert).not.toHaveBeenCalled()
  })

  it('neither the screen nor its model calls a system dialog', () => {
    for (const file of ['../screens/ReportIncidentScreen.tsx', '../useIncidentReport.ts']) {
      expect(readFileSync(path.join(__dirname, file), 'utf8')).not.toMatch(/Alert\.alert|\bAlert\b.*from 'react-native'/)
    }
  })
})

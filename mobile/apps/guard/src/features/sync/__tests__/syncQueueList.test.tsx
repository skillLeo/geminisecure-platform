/**
 * THE SYNC QUEUE DRAWS ONLY WHAT FITS (M11 A4).
 *
 * Everything waiting is listed, and every confirmed act stays for a day as proof — hundreds at a busy
 * gate — and all of them used to be mounted the moment the screen opened. The sections are the rows of a
 * FlatList now. Proved on the real screen with the queue model mocked:
 *   - 300 confirmed acts mount a first window of rows, not 300 — and the list still holds them all;
 *   - the sections come in the order they have always been drawn, each title with its count, and the
 *     title at the top is drawn as the first;
 *   - each section's card: the first row carries its top edge, the last its bottom, every row its sides;
 *   - only a refusal can be dismissed, and dismissing asks for exactly that act;
 *   - the warning, the empty state and the closing note are where they were.
 */

import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { StyleSheet, type ViewStyle } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import type { QueueRow, SyncQueueModel } from '../useSyncQueue'
import SyncQueueScreen, { queueItems } from '../screens/SyncQueueScreen'

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false } }))
let mockModel: SyncQueueModel
jest.mock('../useSyncQueue', () => ({ useSyncQueue: () => mockModel }))

// A phone the width of the client's (360) and the boards' height, with no insets.
const METRICS = { frame: { x: 0, y: 0, width: 360, height: 856 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

const row = (id: string, state: QueueRow['state'], title = `Gate entry ${id}`): QueueRow => ({ id, icon: 'privacy-note', title, detail: `Main gate · 9:${String(Number(id) % 60).padStart(2, '0')} AM`, state, trouble: state === 'refused' ? 'The pass had been cancelled.' : null })

const model = (over: Partial<SyncQueueModel>): SyncQueueModel => ({ waiting: [], refused: [], confirmed: [], online: true, refreshing: false, warning: null, refresh: jest.fn(), dismiss: jest.fn(), ...over })

/** The host view a row is drawn in: the nearest one above its title that carries the card's sides. */
function sliceOf(title: string): ViewStyle {
  let node = screen.getByText(title).parent
  while (node) {
    const style = StyleSheet.flatten(node.props.style) as ViewStyle | undefined
    if (style?.borderLeftWidth === 1 && style.borderRightWidth === 1) return style
    node = node.parent
  }
  throw new Error(`no card slice above ${title}`)
}

beforeEach(() => jest.useFakeTimers())
afterEach(async () => {
  await act(async () => {
    jest.runOnlyPendingTimers()
  })
  jest.useRealTimers()
})

describe('300 confirmed acts', () => {
  beforeEach(() => {
    mockModel = model({ confirmed: Array.from({ length: 300 }, (_, i) => row(String(i + 1), 'confirmed')) })
  })

  it('mount a first window of rows, not 300, and the list holds them all', async () => {
    await render(<SyncQueueScreen />)
    const rows = screen.queryAllByText(/^Gate entry \d+$/).length
    // eslint-disable-next-line no-console
    console.log(`sync queue: ${rows} of 300 rows mounted on first render`)
    expect(rows).toBeGreaterThan(0)
    expect(rows).toBeLessThan(40)
    const items = queueItems(mockModel)
    expect(items).toHaveLength(301)
    expect(items[0]).toMatchObject({ kind: 'title', text: 'Confirmed · 300', first: true })
    // The closing note is the list's footer, drawn whatever the window.
    expect(screen.getByText('Everything you recorded has reached the office. Confirmed items stay here for a day as your proof.')).toBeTruthy()
  })
})

describe('the sections, in the order they have always been drawn', () => {
  const full = () => model({ waiting: [row('1', 'pending'), row('2', 'syncing')], refused: [row('3', 'refused')], confirmed: [row('4', 'confirmed'), row('5', 'confirmed'), row('6', 'confirmed')] })

  it('waiting, not accepted, confirmed: each title with its count, only the top one drawn as the first', () => {
    const items = queueItems(full())
    expect(items.map((i) => (i.kind === 'title' ? i.text : i.row.id))).toEqual(['Waiting to upload · 2', '1', '2', 'Not accepted · 1', '3', 'Confirmed · 3', '4', '5', '6'])
    expect(items.filter((i) => i.kind === 'title').map((i) => i.kind === 'title' && i.first)).toEqual([true, false, false])
    // With a warning above them, no title is the first.
    expect(queueItems({ ...full(), warning: 'A lot is waiting to upload.' }).filter((i) => i.kind === 'title').every((i) => i.kind === 'title' && !i.first)).toBe(true)
    // An empty section draws nothing: with nothing waiting, "Not accepted" is the top one.
    const noWaiting = queueItems({ ...full(), waiting: [] })
    expect(noWaiting[0]).toMatchObject({ kind: 'title', text: 'Not accepted · 1', first: true })
  })

  it('each card: the first row carries its top edge, the last its bottom, every row its sides; one row is a whole card', async () => {
    mockModel = full()
    await render(<SyncQueueScreen />)
    const top = sliceOf('Gate entry 4')
    expect(top).toMatchObject({ borderTopWidth: 1, borderTopLeftRadius: 16, borderTopRightRadius: 16, paddingTop: 2, marginTop: 8, paddingHorizontal: 13 })
    expect(top.borderBottomWidth).toBeUndefined()
    const middle = sliceOf('Gate entry 5')
    expect(middle.borderTopWidth).toBeUndefined()
    expect(middle.borderBottomWidth).toBeUndefined()
    const bottom = sliceOf('Gate entry 6')
    expect(bottom).toMatchObject({ borderBottomWidth: 1, borderBottomLeftRadius: 16, borderBottomRightRadius: 16, paddingBottom: 2 })
    expect(bottom.marginTop).toBeUndefined()
    expect(sliceOf('Gate entry 3')).toMatchObject({ borderTopWidth: 1, borderBottomWidth: 1, paddingTop: 2, paddingBottom: 2, marginTop: 8 })
    // A section's title sits 20 below the card before it; the top one 2 below the list's top.
    expect(StyleSheet.flatten(screen.getByText('Waiting to upload · 2').props.style)).toMatchObject({ marginTop: 2 })
    expect(StyleSheet.flatten(screen.getByText('Confirmed · 3').props.style)).toMatchObject({ marginTop: 20 })
  })

  it('only a refusal can be dismissed, and dismissing asks for exactly that act', async () => {
    mockModel = full()
    await render(<SyncQueueScreen />)
    const buttons = screen.getAllByRole('button').filter((b) => /^I have read this/.test(String(b.props['aria-label'] ?? b.props.accessibilityLabel ?? '')))
    expect(buttons).toHaveLength(1)
    await act(async () => {
      fireEvent.press(screen.getByLabelText('I have read this: remove Gate entry 3 from the list'))
    })
    expect(mockModel.dismiss).toHaveBeenCalledWith('3')
    expect(screen.getByText('The pass had been cancelled.')).toBeTruthy()
  })
})

describe('the notes are where they were', () => {
  it('nothing at all: the empty state, and no closing note', async () => {
    mockModel = model({})
    await render(<SyncQueueScreen />)
    expect(queueItems(mockModel)).toHaveLength(0)
    expect(screen.getByText('Everything is sent')).toBeTruthy()
    expect(screen.queryByText(/already saved safely on this device/)).toBeNull()
  })

  it('a warning sits above the sections; with signal and something waiting, the pull-down line is added', async () => {
    mockModel = model({ waiting: [row('1', 'pending')], warning: 'A lot is waiting to upload. Move to where there is signal when you can.' })
    await render(<SyncQueueScreen />)
    // The warning is still announced as an alert: its note (a plain view, so not a query target) says so.
    let note = screen.getByText('A lot is waiting to upload. Move to where there is signal when you can.').parent
    while (note && note.props.role !== 'alert') note = note.parent
    expect(note?.props.role).toBe('alert')
    // …and it comes before the first section's title in the list.
    const order = screen.getAllByText(/A lot is waiting to upload\.|^Waiting to upload · 1$/).map((n) => String(n.props.children))
    expect(order[0]).toMatch(/^A lot is waiting/)
    expect(order[1]).toBe('Waiting to upload · 1')
    expect(screen.getByText(/already saved safely on this device/)).toBeTruthy()
    expect(screen.getByText('You have signal now: pull this list down to send straight away.')).toBeTruthy()
  })

  it('with no signal, the pull-down line is not offered', async () => {
    mockModel = model({ waiting: [row('1', 'pending')], online: false })
    await render(<SyncQueueScreen />)
    expect(screen.queryByText('You have signal now: pull this list down to send straight away.')).toBeNull()
  })
})

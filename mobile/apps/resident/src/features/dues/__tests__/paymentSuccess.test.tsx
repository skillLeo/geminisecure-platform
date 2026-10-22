/**
 * resident-app-30 · PAYMENT SUCCESSFUL, AS THE BOARD DRAWS IT (the user, 7 October: the eleventh screen) — proved on the
 * real screen, its model mocked:
 *   - with the design's own content it draws every string the board draws, in the board's order, and nothing else;
 *   - live, the demonstration Pay just recorded: the estate's receipt, "Visa •••• 4242 (demonstration)", "Your balance",
 *     the note — and the board's "Download PDF", which makes the receipt's PDF on the phone (receiptPdf.ts);
 *   - a reversed payment has no gold tick; Done and the outline button do what they say;
 *   - the page does not scroll, and a state with nothing to show says so with a way back.
 */

import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { successFixture } from '@/fidelity/dues'
import { DEMONSTRATION_NOTE, type SuccessView } from '../duesLogic'
import type { PaymentSuccessModel } from '../useDues'
import ReceiptScreen from '../screens/ReceiptScreen'

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => true }, useLocalSearchParams: () => ({ payment: '501' }), useFocusEffect: () => undefined }))
let mockModel: PaymentSuccessModel
const mockWanted: unknown[] = []
jest.mock('../useDues', () => ({
  usePaymentSuccess: (wanted: unknown) => {
    mockWanted.push(wanted)
    return mockModel
  },
}))

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

/** `node tools/design/board-brief.mjs resident-app-30 --strings`, in order. */
const BOARD_STRINGS = [
  'Payment successful',
  '$12,400.00 paid to Phoenix Park Village 1 · receipt #4472',
  'Maintenance fee — September',
  '$6,200.00',
  'Maintenance fee — August',
  '$6,200.00',
  'Paid with',
  'Visa •••• 4417',
  'Date & time',
  'Sep 4, 9:41 AM',
  'Your balance',
  '$0.00',
  'Your payment reminders for this balance have been turned off automatically — nothing more to do.',
  'Download PDF',
  'Done',
]

const board: SuccessView = { reversed: false, title: successFixture.title, line: successFixture.summary, details: [...successFixture.lines], total: successFixture.total, note: successFixture.note }

const model = (over: Partial<PaymentSuccessModel> = {}): PaymentSuccessModel => ({
  status: 'ready',
  errorMessage: null,
  view: board,
  secondary: { label: successFixture.secondary, onPress: jest.fn() },
  problem: null,
  done: jest.fn(),
  refresh: jest.fn(),
  ...over,
})

const demonstration: SuccessView = {
  reversed: false,
  title: 'Payment successful',
  line: '$12,400.00 paid to Phoenix Park Village 1 · receipt #PPV-R-04472',
  details: [
    { label: 'Paid with', value: 'Visa •••• 4242 (demonstration)' },
    // A moment: the PDF names its clock (receiptPdf.ts); the screen, read at the estate, draws the time alone (M98).
    { label: 'Date & time', value: '7 Oct 2026, 3:41 PM', moment: true },
  ],
  total: { label: 'Your balance', value: '$0.00' },
  note: DEMONSTRATION_NOTE,
}

type Element = { type: string; props: Record<string, unknown>; children: Array<Element | string> | null }
const drawn = (node: unknown): string[] => {
  if (node === null || node === undefined) return []
  if (typeof node === 'string') return [node]
  if (Array.isArray(node)) return node.flatMap(drawn)
  return ((node as Element).children ?? []).flatMap(drawn)
}
const elementsOf = (node: unknown): Element[] => {
  if (node === null || typeof node !== 'object') return []
  if (Array.isArray(node)) return node.flatMap(elementsOf)
  const element = node as Element
  return [element, ...(element.children ?? []).flatMap(elementsOf)]
}

beforeEach(() => {
  mockWanted.length = 0
})

describe("with the design's own content", () => {
  it('draws every string the board draws, in order, and nothing the board does not', async () => {
    mockModel = model()
    await render(<ReceiptScreen />)
    expect(drawn(screen.toJSON())).toEqual(BOARD_STRINGS)
  })

  it('asks for the receipt the route names', async () => {
    mockModel = model()
    await render(<ReceiptScreen />)
    expect(mockWanted[0]).toBe('501')
  })

  it('does not scroll', async () => {
    mockModel = model()
    await render(<ReceiptScreen />)
    expect(elementsOf(screen.toJSON()).filter((e) => e.type === 'RCTScrollView')).toHaveLength(0)
  })
})

describe('live: the demonstration Pay just recorded', () => {
  it('is the estate’s receipt — the test card named as a demonstration, the balance now, and the note that no money moved', async () => {
    mockModel = model({ view: demonstration, secondary: { label: 'Download PDF', onPress: jest.fn() } })
    await render(<ReceiptScreen />)
    expect(drawn(screen.toJSON())).toEqual([
      'Payment successful',
      '$12,400.00 paid to Phoenix Park Village 1 · receipt #PPV-R-04472',
      'Paid with',
      'Visa •••• 4242 (demonstration)',
      'Date & time',
      '7 Oct 2026, 3:41 PM',
      'Your balance',
      '$0.00',
      DEMONSTRATION_NOTE,
      'Download PDF',
      'Done',
    ])
  })

  it('the outline button and Done do what they say', async () => {
    mockModel = model({ view: demonstration, secondary: { label: 'Download PDF', onPress: jest.fn() } })
    await render(<ReceiptScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Download PDF' }))
    })
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Done' }))
    })
    expect(mockModel.secondary.onPress).toHaveBeenCalledTimes(1)
    expect(mockModel.done).toHaveBeenCalledTimes(1)
  })

  it('a share sheet that would not open is said, under the buttons', async () => {
    mockModel = model({ view: demonstration, problem: 'The share sheet could not be opened.' })
    await render(<ReceiptScreen />)
    expect(screen.getByText('The share sheet could not be opened.')).toBeTruthy()
  })
})

describe('a reversed payment', () => {
  it('says so, with no gold tick', async () => {
    mockModel = model({ view: { ...demonstration, reversed: true, title: 'Payment reversed', line: '$12,400.00 was recorded and then reversed by Phoenix Park Village 1 · receipt #PPV-R-04472' } })
    await render(<ReceiptScreen />)
    expect(screen.getByText('Payment reversed')).toBeTruthy()
    expect(screen.queryByText('Payment successful')).toBeNull()
  })
})

describe('nothing to show', () => {
  it.each([
    ['error', 'This receipt could not be loaded.'],
    ['missing', 'No such receipt'],
    ['denied', "Receipts aren't open to your account"],
    ['empty', 'No payments recorded yet'],
  ] as const)('%s says so, with a way back', async (status, words) => {
    mockModel = model({ status, view: null, errorMessage: status === 'error' ? 'This receipt could not be loaded.' : null })
    await render(<ReceiptScreen />)
    expect(screen.getByText(words)).toBeTruthy()
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Back' }))
    })
    expect(mockModel.done).toHaveBeenCalledTimes(1)
  })

  it('loading draws no receipt', async () => {
    mockModel = model({ status: 'loading', view: null })
    await render(<ReceiptScreen />)
    expect(screen.queryByText('Payment successful')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Back' })).toBeNull()
  })
})

/**
 * THE PAY SCREEN IS THE BOARD (resident-app-10, M42 §5 #9) — proved on the real screen with the pay model mocked:
 *   - with the design's own content it draws every string the board draws, in the board's words and order, and
 *     NOTHING ELSE: no card form, no DEMONSTRATION chip, no test-card paragraph, no reference to copy, no instructions;
 *   - the one element the M42 order adds — the amount field — is drawn only while "Pay another amount" is chosen, and
 *     directly under it;
 *   - every control does what it says: the cards choose, "Add new card" opens Add a card, the switch asks for AutoPay
 *     and Pay asks the hook (whose one dialog is proved in usePay.test.tsx);
 *   - live, the saved Visa and AutoPay's terms are not drawn: no card is stored, and no AutoPay terms exist;
 *   - live with the server's demonstration checkout on, its card row comes first, says it is a demonstration, and the
 *     button waits while the payment is sent;
 *   - the page fits the board's phone and is never cut off on a smaller one: it may scroll there, and it keeps the board's
 *     20 below its last line — or the home-indicator strip where that is deeper (M47 §13.2); a state that is not ready
 *     draws no checkout.
 */

import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { StyleSheet, type StyleProp, type ViewStyle } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { fill } from '@/design/tokens'
import { paymentFixture } from '@/fidelity/dues'
import { BANK_TRANSFER, CASH_AGENT, DEMO_CARD, fullBalanceTitle, payLabel } from '../pay'
import type { PayModel } from '../useDues'
import PayScreen from '../screens/PayScreen'

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false }, useFocusEffect: () => undefined }))
let mockModel: PayModel
jest.mock('../useDues', () => ({ usePay: () => mockModel }))

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

/** `node tools/design/board-brief.mjs 10 --strings`, in order. */
const BOARD_STRINGS = [
  'Pay maintenance fee',
  'Amount to pay',
  'Pay full balance: $12,400',
  'Clears both overdue months',
  'Pay another amount',
  'Enter a part-payment',
  'Payment method',
  'Visa •••• 4417',
  'Saved card · expires 08/28',
  'Bank transfer',
  'NCB, Scotiabank, JMMB & more',
  'Cash agent',
  'Pay in person, reference at checkout',
  'Add new card',
  'Save a card for next time',
  'Turn on AutoPay',
  'Automatically pay up to $15,000 on the 28th each month.',
  'Pay $12,400.00',
  'Payments are processed securely by our payment gateway. GeminiSecure never stores your full card number.',
]

/** The design's own content, as the hook hands it over in fidelity mode. */
const design = (over: Partial<PayModel> = {}): PayModel => ({
  status: 'ready',
  errorMessage: null,
  full: { title: fullBalanceTitle(paymentFixture.balance), sub: paymentFixture.clears },
  choice: 'full',
  choose: jest.fn(),
  part: '',
  setPart: jest.fn(),
  methods: [{ key: 'card', ...paymentFixture.card }, BANK_TRANSFER, CASH_AGENT],
  method: 'card',
  chooseMethod: jest.fn(),
  addCard: jest.fn(),
  autopay: { on: true, line: paymentFixture.autopay.line, toggle: jest.fn(), busy: false },
  payLabel: payLabel(paymentFixture.balance),
  pay: jest.fn(),
  paying: false,
  note: null,
  clearNote: jest.fn(),
  refresh: jest.fn(),
  ...over,
})

/** A live household's with card payments off: Bank transfer first; no AutoPay terms; AutoPay off. */
const live = (over: Partial<PayModel> = {}): PayModel =>
  design({ full: { title: 'Pay full balance: $6,200', sub: 'Clears 1 overdue month' }, methods: [BANK_TRANSFER, CASH_AGENT], method: 'bank', autopay: { on: false, line: null, toggle: jest.fn(), busy: false }, payLabel: 'Pay $6,200.00', ...over })

type Node = ReturnType<typeof screen.toJSON>
type Element = { type: string; props: Record<string, unknown>; children: Array<Element | string> | null }

/** Every piece of text on the screen, in the order it is drawn — and `[field]` where a text box is. */
const drawn = (node: Node | Element | string | null): string[] => {
  if (node === null) return []
  if (typeof node === 'string') return [node]
  if (Array.isArray(node)) return node.flatMap(drawn)
  const element = node as Element
  return [...(element.type === 'TextInput' ? ['[field]'] : []), ...(element.children ?? []).flatMap(drawn)]
}
const elementsOf = (node: unknown): Element[] => {
  if (node === null || typeof node !== 'object') return []
  if (Array.isArray(node)) return node.flatMap(elementsOf)
  const element = node as Element
  return [element, ...(element.children ?? []).flatMap(elementsOf)]
}
const styleOf = (e: { props: Record<string, unknown> }) => StyleSheet.flatten(e.props.style as StyleProp<ViewStyle>) ?? {}
const fields = () => elementsOf(screen.toJSON()).filter((e) => e.type === 'TextInput')

beforeEach(() => {
  jest.useFakeTimers()
})
afterEach(async () => {
  await act(async () => {
    jest.runOnlyPendingTimers()
  })
  jest.useRealTimers()
})

describe("with the design's own content", () => {
  beforeEach(() => {
    mockModel = design()
  })

  it('draws every string the board draws, in order, and nothing the board does not', async () => {
    await render(<PayScreen />)
    expect(drawn(screen.toJSON())).toEqual(BOARD_STRINGS)
  })

  it('none of what the client deleted is drawn', async () => {
    await render(<PayScreen />)
    for (const gone of [/DEMONSTRATION/i, /4242/, /test card/i, /no money/i, /Or pay by bank transfer, cash or cheque/, /Still owed on this charge/, /^Copy$/, /Copied/, /payment instructions/i, /Copy the reference/, /reference to quote/i, /How to pay/, /Pay your dues/, /Card number|Security code|Expiry/]) {
      expect(screen.queryAllByText(gone)).toHaveLength(0)
    }
  })

  it('there is nothing to type into, no card number, no amount, until "Pay another amount" is chosen', async () => {
    await render(<PayScreen />)
    expect(fields()).toHaveLength(0)
  })

  it('the cards and rows say which is chosen; the switch is drawn on, as the board draws it', async () => {
    await render(<PayScreen />)
    expect(screen.getByRole('radio', { name: /^Pay full balance: \$12,400\./, checked: true })).toBeTruthy()
    expect(screen.getByRole('radio', { name: /^Pay another amount\./, checked: false })).toBeTruthy()
    expect(screen.getByRole('radio', { name: /^Visa •••• 4417\./, checked: true })).toBeTruthy()
    for (const name of [/^Bank transfer\./, /^Cash agent\./]) expect(screen.getByRole('radio', { name, checked: false })).toBeTruthy()
    expect(screen.getByRole('switch', { name: 'Turn on AutoPay', checked: true })).toBeTruthy()
  })

  it('the chosen amount card is tinted; the card brand sits on the dark tile; the AutoPay card is cream', async () => {
    await render(<PayScreen />)
    expect(styleOf(screen.getByRole('radio', { name: /^Pay full balance/ })).backgroundColor).toBe(fill.tint)
    expect(styleOf(screen.getByRole('radio', { name: /^Pay another amount/ })).backgroundColor).toBeUndefined()
    const tiles = elementsOf(screen.toJSON()).filter((e) => styleOf(e).width === 38 && styleOf(e).height === 26).map((e) => styleOf(e).backgroundColor)
    expect(tiles).toEqual([fill.deep, fill.tint, fill.tint, fill.tint])
    expect(elementsOf(screen.toJSON()).filter((e) => styleOf(e).backgroundColor === fill.goldTint)).toHaveLength(1)
    expect(styleOf(screen.getByRole('switch', { name: 'Turn on AutoPay' })).backgroundColor).toBe(fill.gold)
  })
})

describe('the one added element: the amount field, while "Pay another amount" is chosen', () => {
  it('is ONE field, directly under that card: before "Payment method"', async () => {
    mockModel = design({ choice: 'part', part: '5000', payLabel: 'Pay $5,000.00' })
    await render(<PayScreen />)
    expect(fields()).toHaveLength(1)
    const order = drawn(screen.toJSON())
    expect(order.slice(order.indexOf('Pay another amount'), order.indexOf('Payment method') + 1)).toEqual(['Pay another amount', 'Enter a part-payment', '[field]', 'Payment method'])
    expect(fields()[0]!.props.value).toBe('5000')
    expect(screen.getByRole('button', { name: 'Pay $5,000.00' })).toBeTruthy()
  })

  it('carries no example or instruction of its own: the card above it says what to enter', async () => {
    mockModel = design({ choice: 'part' })
    await render(<PayScreen />)
    expect(fields()[0]!.props.placeholder).toBeUndefined()
    expect(drawn(screen.toJSON())).toEqual([...BOARD_STRINGS.slice(0, 6), '[field]', ...BOARD_STRINGS.slice(6)])
  })

  it('what is typed goes to the hook', async () => {
    mockModel = design({ choice: 'part' })
    await render(<PayScreen />)
    await act(async () => {
      fireEvent.changeText(screen.getByLabelText('Part-payment amount'), '2,500')
    })
    expect(mockModel.setPart).toHaveBeenCalledWith('2,500')
  })
})

describe('every control does what it says', () => {
  beforeEach(() => {
    mockModel = design()
  })

  it('the amount cards and the method rows choose', async () => {
    await render(<PayScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('radio', { name: /^Pay another amount\./ }))
    })
    expect(mockModel.choose).toHaveBeenLastCalledWith('part')
    await act(async () => {
      fireEvent.press(screen.getByRole('radio', { name: /^Pay full balance/ }))
    })
    expect(mockModel.choose).toHaveBeenLastCalledWith('full')
    for (const [name, key] of [[/^Bank transfer\./, 'bank'], [/^Cash agent\./, 'cash'], [/^Visa •••• 4417\./, 'card']] as const) {
      await act(async () => {
        fireEvent.press(screen.getByRole('radio', { name }))
      })
      expect(mockModel.chooseMethod).toHaveBeenLastCalledWith(key)
    }
  })

  it('"Add new card" opens Add a card; the switch asks for AutoPay; Pay asks the hook: once each', async () => {
    await render(<PayScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Add new card. Save a card for next time' }))
    })
    await act(async () => {
      fireEvent.press(screen.getByRole('switch', { name: 'Turn on AutoPay' }))
    })
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Pay $12,400.00' }))
    })
    expect(mockModel.addCard).toHaveBeenCalledTimes(1)
    expect(mockModel.autopay.toggle).toHaveBeenCalledTimes(1)
    expect(mockModel.pay).toHaveBeenCalledTimes(1)
  })
})

describe('live: what the server does not have is not drawn', () => {
  it('no saved card, no AutoPay terms, and the switch off: the rest exactly as the board', async () => {
    mockModel = live()
    await render(<PayScreen />)
    const texts = drawn(screen.toJSON())
    for (const gone of ['Visa •••• 4417', 'Saved card · expires 08/28', 'Automatically pay up to $15,000 on the 28th each month.']) expect(texts).not.toContain(gone)
    expect(texts).toEqual(['Pay maintenance fee', 'Amount to pay', 'Pay full balance: $6,200', 'Clears 1 overdue month', 'Pay another amount', 'Enter a part-payment', 'Payment method', 'Bank transfer', 'NCB, Scotiabank, JMMB & more', 'Cash agent', 'Pay in person, reference at checkout', 'Add new card', 'Save a card for next time', 'Turn on AutoPay', 'Pay $6,200.00', 'Payments are processed securely by our payment gateway. GeminiSecure never stores your full card number.'])
    expect(screen.getByRole('radio', { name: /^Bank transfer\./, checked: true })).toBeTruthy()
    const off = screen.getByRole('switch', { name: 'Turn on AutoPay', checked: false })
    expect(styleOf(off).backgroundColor).toBe(fill.switchOff)
  })
})

describe('live, with the server’s demonstration checkout on (the user, 7 October)', () => {
  const demo = (over: Partial<PayModel> = {}) => live({ methods: [DEMO_CARD, BANK_TRANSFER, CASH_AGENT], method: 'card', ...over })

  it('the demonstration card is the first row, chosen, and its own line says what it is: no saved card that is not there', async () => {
    mockModel = demo()
    await render(<PayScreen />)
    const texts = drawn(screen.toJSON())
    expect(texts.slice(texts.indexOf('Payment method'), texts.indexOf('Bank transfer'))).toEqual(['Payment method', 'Visa •••• 4242', 'Demonstration card · no money moves'])
    expect(screen.getByRole('radio', { name: 'Visa •••• 4242. Demonstration card · no money moves', checked: true })).toBeTruthy()
    expect(texts).not.toContain('Saved card · expires 08/28')
  })

  it('while the payment is on its way, the button waits: busy and disabled, a spinner for its words (two taps are one payment: usePay.test)', async () => {
    mockModel = demo({ paying: true })
    await render(<PayScreen />)
    const button = screen.getByRole('button', { name: 'Pay $6,200.00' })
    expect(button.props.accessibilityState).toMatchObject({ busy: true, disabled: true })
    expect(screen.queryByText('Pay $6,200.00')).toBeNull()
  })
})

describe('the page', () => {
  // At 390 × 844 the board's content fits and nothing moves (the fidelity harness, resident-app-10); a smaller phone,
  // or the amount field with its keyboard, scrolls instead of cutting the padlock line off under the system bar.
  it('is never cut off: one page that scrolls only when it must, with the home-indicator strip (34) and one spacing step (8) below: with the design, and with the amount field open', async () => {
    for (const model of [design(), design({ choice: 'part' })]) {
      mockModel = model
      const shown = await render(<PayScreen />)
      const page = screen.getByTestId('pay-page')
      expect(page.props.alwaysBounceVertical).toBe(false)
      // M57 §2: never flush on the strip.
      expect(StyleSheet.flatten(page.props.contentContainerStyle).paddingBottom).toBe(42)
      await act(async () => shown.unmount())
    }
  })

  it.each(['loading', 'error', 'denied', 'settled'] as const)('%s draws no checkout', async (status) => {
    mockModel = design({ status, errorMessage: 'The server said no.' })
    await render(<PayScreen />)
    expect(screen.getByText('Pay maintenance fee')).toBeTruthy()
    expect(screen.queryByText('Amount to pay')).toBeNull()
    expect(screen.queryAllByRole('button', { name: /^Pay \$/ })).toHaveLength(0)
    if (status === 'error') expect(screen.getByText('The server said no.')).toBeTruthy()
    if (status === 'settled') expect(screen.getByText('Nothing to pay')).toBeTruthy()
  })
})

/**
 * THE DUES SCREEN IS THE BOARD (resident-app-09, M42 §5 #8) — proved on the real screen with the dues model mocked:
 *   - with the design's own content it draws every string the board draws, in the board's words, and NOTHING ELSE
 *     (the reverse checklist: no plan card, no pay-now card, no charges card, no notes, no "JMD");
 *   - the four ageing boxes and the gold "Pay now" sit inside the dark-blue card; "Pay now" opens the Pay screen and
 *     "AutoPay" opens AutoPay;
 *   - a payment row is the gold "+" tile and a gold amount, a charge row the calendar tile;
 *   - the page does not scroll: the statement's own rows are the only list, and a long ledger mounts a window of rows
 *     (M11 A4), not all of them;
 *   - a state that is not ready draws no card.
 */

import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { StyleSheet, type StyleProp, type ViewStyle } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { router } from 'expo-router'
import { c } from '@gs/core'
import { fill, ink } from '@/design/tokens'
import { statementFixture } from '@/fidelity/dues'
import type { StatementRow } from '../statement'
import type { DuesModel } from '../useDues'
import DuesScreen from '../screens/DuesScreen'

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false }, useFocusEffect: () => undefined }))
let mockModel: DuesModel
jest.mock('../useDues', () => ({ useDuesStatement: () => mockModel }))

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

/** `node tools/design/board-brief.mjs 09 --strings`, in order — less the tab bar's five, which the navigator draws. */
const BOARD_STRINGS = [
  'Dues & payments',
  'Total outstanding',
  '$12,400.00',
  '2 months overdue · next due Sep 28',
  'CURRENT', '$0', '30 DAYS', '$6,200', '60 DAYS', '$6,200', '90+ DAYS', '$0',
  'Pay now',
  'Statement',
  'AutoPay',
  'Maintenance fee — September', 'Charged Sep 1 · due Sep 28', '−$6,200', 'Bal. $12,400',
  'Maintenance fee — August', 'Charged Aug 1 · overdue', '−$6,200', 'Bal. $6,200',
  'Payment received — receipt #4471', 'Jul 30 · Visa •••• 4417', '+$6,200', 'Bal. $0',
  'Maintenance fee — July', 'Charged Jul 1', '−$6,200', 'Bal. $6,200',
]

const model = (over: Partial<DuesModel> = {}): DuesModel => ({
  status: 'ready',
  errorMessage: null,
  ...statementFixture,
  linesState: 'ready',
  refreshing: false,
  refresh: jest.fn(),
  reread: jest.fn(),
  ...over,
})

type Node = ReturnType<typeof screen.toJSON>
/** Every piece of text on the screen, in the order it is drawn. */
const textsOf = (node: Node | string): string[] => {
  if (node === null) return []
  if (typeof node === 'string') return [node]
  if (Array.isArray(node)) return node.flatMap(textsOf)
  return (node.children ?? []).flatMap((child) => textsOf(child as Node | string))
}

/** The drawn tree's elements, depth first. */
type Element = { type: string; props: Record<string, unknown>; children: Array<Element | string> | null }
const elementsOf = (node: unknown): Element[] => {
  if (node === null || typeof node !== 'object') return []
  if (Array.isArray(node)) return node.flatMap(elementsOf)
  const element = node as Element
  return [element, ...(element.children ?? []).flatMap(elementsOf)]
}
const labelOf = (e: Element) => (e.props['aria-label'] ?? e.props.accessibilityLabel) as string | undefined
/** A statement row, found by the start of its spoken label. */
const rowOf = (title: string): Element => {
  const row = elementsOf(screen.toJSON()).find((e) => labelOf(e)?.startsWith(`${title},`))
  if (!row) throw new Error(`no row "${title}"`)
  return row
}
/** The tile's fill, and the glyph's paths. */
const tileOf = (row: Element) => {
  const tile = row.children?.[0]
  if (!tile || typeof tile === 'string') throw new Error('no tile')
  return { fill: StyleSheet.flatten(tile.props.style as StyleProp<ViewStyle>)?.backgroundColor, paths: elementsOf(tile).map((e) => e.props.d).filter(Boolean) }
}
/** Every scrolling view on the screen. */
const scrollers = () => elementsOf(screen.toJSON()).filter((e) => e.type === 'RCTScrollView')

const PLUS = 'M12 5v14M5 12h14'
const CALENDAR = 'M3 10h18M8 3v4M16 3v4'

const line = (i: number): StatementRow => ({ key: `j${i}`, kind: i % 3 === 0 ? 'payment' : 'charge', title: `Line ${i}`, sub: 'Charged Sep 1', amount: '−$6,200', balance: 'Bal. $6,200' })

beforeEach(() => {
  jest.useFakeTimers()
  jest.mocked(router.push).mockClear()
})
afterEach(async () => {
  await act(async () => {
    jest.runOnlyPendingTimers()
  })
  jest.useRealTimers()
})

describe('with the design\'s own content', () => {
  beforeEach(() => {
    mockModel = model()
  })

  it('draws every string the board draws, in order, and nothing the board does not', async () => {
    await render(<DuesScreen />)
    expect(textsOf(screen.toJSON())).toEqual(BOARD_STRINGS)
  })

  it('none of what the client removed is drawn', async () => {
    await render(<DuesScreen />)
    for (const gone of [/payment plan/i, /Next payment/, /Pay a different amount/, /Next:|Final:/, /Agreed with the estate office/, /^Charges$/, /Show paid charges/, /DEMONSTRATION/i, /How to pay/, /JMD/, /These are the latest/, /Saved on this phone/]) {
      expect(screen.queryAllByText(gone)).toHaveLength(0)
    }
  })

  it('"Pay now" opens the Pay screen, as it always has; "AutoPay" opens AutoPay', async () => {
    await render(<DuesScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Pay now' }))
    })
    expect(router.push).toHaveBeenLastCalledWith('/dues/pay')
    await act(async () => {
      fireEvent.press(screen.getByRole('tab', { name: 'AutoPay' }))
    })
    expect(router.push).toHaveBeenLastCalledWith('/dues/autopay')
    expect(router.push).toHaveBeenCalledTimes(2)
  })

  it('a payment row is the gold "+" tile and a gold amount; a charge row is the calendar tile', async () => {
    await render(<DuesScreen />)
    expect(tileOf(rowOf('Payment received — receipt #4471'))).toEqual({ fill: fill.goldTint, paths: [PLUS] })
    for (const title of ['Maintenance fee — September', 'Maintenance fee — August', 'Maintenance fee — July']) {
      expect(tileOf(rowOf(title))).toEqual({ fill: fill.tint, paths: [CALENDAR] })
    }
    expect(StyleSheet.flatten(screen.getByText('+$6,200').props.style).color).toBe(c(ink.link))
    expect(StyleSheet.flatten(screen.getAllByText('−$6,200')[0]!.props.style).color).toBe(c(ink.heading))
  })

  it('the page itself does not scroll: the statement\'s rows are the only list on it', async () => {
    await render(<DuesScreen />)
    expect(scrollers()).toHaveLength(1)
    expect(screen.getAllByLabelText(/^(Maintenance fee|Payment received)/)).toHaveLength(4)
  })
})

describe('what is not on the board is not invented', () => {
  it('no ageing figures that would not add up, and nothing to pay when nothing is owed', async () => {
    mockModel = model({ balance: { amount: '$0.00', due: null, ageing: null, canPay: false } })
    await render(<DuesScreen />)
    expect(screen.getByText('Total outstanding')).toBeTruthy()
    expect(screen.getByText('$0.00')).toBeTruthy()
    for (const label of ['CURRENT', '30 DAYS', '60 DAYS', '90+ DAYS', 'Pay now']) expect(screen.queryByText(label)).toBeNull()
  })
})

describe('a long ledger', () => {
  it('scrolls inside the statement card, mounting a window of its 200 rows — not all of them', async () => {
    mockModel = model({ lines: Array.from({ length: 200 }, (_, i) => line(i)) })
    await render(<DuesScreen />)
    const mounted = screen.queryAllByLabelText(/^Line \d+,/).length
    // eslint-disable-next-line no-console
    console.log(`dues: ${mounted} of 200 statement rows mounted`)
    expect(mounted).toBeGreaterThan(0)
    expect(mounted).toBeLessThan(60)
    expect(screen.getByLabelText(/^Line 0,/)).toBeTruthy()
    expect(screen.queryByLabelText(/^Line 199,/)).toBeNull()
    // Still one scrolling view: the statement card's own.
    expect(scrollers()).toHaveLength(1)
  })
})

describe('a state that is not ready draws no card', () => {
  it.each(['loading', 'error', 'denied'] as const)('%s', async (status) => {
    mockModel = model({ status, errorMessage: 'The server said no.', balance: null })
    await render(<DuesScreen />)
    expect(screen.queryByText('Total outstanding')).toBeNull()
    expect(screen.queryAllByLabelText(/^Maintenance fee/)).toHaveLength(0)
    if (status === 'error') expect(screen.getByText('The server said no.')).toBeTruthy()
    // A refusal: what is closed, and the server's own sentence for why (M50 §4.1: nothing says "not available yet").
    if (status === 'denied') expect(screen.getByText("Dues aren't open to your account")).toBeTruthy()
    if (status === 'denied') expect(screen.getByText('The server said no.')).toBeTruthy()
  })

  it('a statement still loading, or that could not load, draws no rows', async () => {
    mockModel = model({ lines: [], linesState: 'error' })
    await render(<DuesScreen />)
    expect(screen.getByText('Total outstanding')).toBeTruthy()
    expect(screen.getByText('Your statement could not be loaded.')).toBeTruthy()
    expect(scrollers()).toHaveLength(0)
  })
})

/**
 * resident-app-08 · THE VISITORS TAB, AS THE BOARD DRAWS IT (M42 §5 #7) — proved on the real screen, its model mocked:
 *
 *   - the bar: the back chevron, "Visitors" and the "+" that opens Invite visitor — no panic shield (M42 §6.1);
 *   - two tabs, "On site now" first and open, then "History" — "Expected" is gone;
 *   - On site now: the cards, then "Recent activity" and its rows, every line whole (no "· Singl…"), the badge beside
 *     the name so the line under it has the card's whole width (M47 §5.6);
 *   - a card or row of a real pass opens it; the design's own sample is drawn and opens nothing;
 *   - My e-Pass is ONE ROW at the top of "On site now" (M50 §6.1, superseding M47 §5.3) — never a visitor card, never
 *     in History — and an empty tab says so in one line (M50 §6.2);
 *   - History is still ONE VIRTUALISED LIST (M11 A4): 250 past passes mount a first window of cards, not 250.
 */

import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { router } from 'expo-router'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { trackingFixture } from '@/fidelity/visitors'
import type { SiteCard } from '../tracking'
import type { TrackingModel } from '../useVisitorTracking'
import VisitorsScreen, { itemsOf } from '../screens/VisitorsScreen'

// A phone the width of the client's (360) and the boards' height, with no insets.
const METRICS = { frame: { x: 0, y: 0, width: 360, height: 856 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

// The kit's states animate; in Jest the animation library runs on its own published mocks.
jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false }, usePathname: () => '/visitors', useFocusEffect: jest.fn() }))
let mockModel: TrackingModel
jest.mock('../useVisitorTracking', () => ({ useVisitorTracking: () => mockModel }))

/** What the hook hands over in fidelity mode: the board's own sample. */
const board = (over: Partial<TrackingModel> = {}): TrackingModel => ({
  status: 'ready',
  errorMessage: null,
  refreshing: false,
  refresh: jest.fn(),
  onSite: trackingFixture.onSite.map((v) => ({ key: v.id, passId: null, initials: v.initials, name: v.name, line: v.detail, badge: v.chip })),
  activity: trackingFixture.activity.map((r) => ({ key: r.id, passId: null, entered: r.entered, name: r.name, line: r.detail, time: r.time })),
  history: [],
  // A phone's model: the e-Pass row on (useVisitorTracking leaves it off only for the board's own picture).
  epass: over.status !== 'denied',
  ...over,
})

const past = (i: number): SiteCard => ({ key: `h:${i}`, passId: String(i), initials: 'V', name: `Visitor ${i}`, line: 'Arrived 4:00 PM · Delivery pass', badge: '6 Oct 2026' })
const labelOf = (b: { props: Record<string, unknown> }) => String(b.props['aria-label'] ?? b.props.accessibilityLabel ?? '')
const cards = () => screen.queryAllByRole('button').filter((b) => /^Visitor \d+,/.test(labelOf(b)))

// The list renders its later batches on a timer; run them inside act so nothing updates after a test.
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

describe('the bar', () => {
  it('the back chevron, "Visitors", and the "+" that opens Invite visitor: no panic shield', async () => {
    mockModel = board()
    await render(<VisitorsScreen />)
    expect(screen.getByRole('heading', { name: 'Visitors' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Back' })).toBeTruthy()
    expect(screen.queryByLabelText(/panic/i)).toBeNull()
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Invite visitor' }))
    })
    expect(router.push).toHaveBeenCalledWith('/visitors/invite')
  })
})

describe('the tabs', () => {
  it('"On site now" first and open, then "History", and no "Expected"', async () => {
    mockModel = board()
    await render(<VisitorsScreen />)
    expect(screen.getAllByRole('tab').map(labelOf)).toEqual(['On site now', 'History'])
    expect(screen.getByRole('tab', { name: 'On site now', selected: true })).toBeTruthy()
    expect(screen.getByRole('tab', { name: 'History', selected: false })).toBeTruthy()
    expect(screen.queryByText('Expected')).toBeNull()
  })
})

describe('On site now', () => {
  it('draws the design’s cards, then "Recent activity" and its four rows: every line whole', async () => {
    mockModel = board()
    await render(<VisitorsScreen />)
    for (const text of ['MJ', 'Arrived 2:14 PM · Guest of Andrea Fletcher', '45 min', 'TC', 'Arrived 10:02 AM · Contractor pass', '3h 12m', 'Recent activity', 'Entered · Main Gate · by Guard Whyte', 'Exited · Main Gate · by Guard Palmer', 'Entered · Service Gate · by Guard Whyte', 'Entered · Main Gate · by Guard Palmer', '2:14 PM', '11:48 AM', '10:02 AM', '9:20 AM']) {
      expect(screen.getByText(text)).toBeTruthy()
    }
    // Each name is on its card and on its rows, as the board draws them.
    expect(screen.getAllByText('Marcia James')).toHaveLength(2)
    expect(screen.getAllByText('TropiClean Pest Control')).toHaveLength(2)
    expect(screen.getAllByText('Devon Grant')).toHaveLength(2)
    // No truncation (M42 §5 #7: no "· Singl…"): a line too long for one row wraps instead.
    for (const text of ['Arrived 2:14 PM · Guest of Andrea Fletcher', 'Entered · Service Gate · by Guard Whyte']) {
      expect(screen.getByText(text).props.numberOfLines).toBeUndefined()
    }
    // The design's sample is no real pass: drawn, and nothing on it pretends to open one.
    expect(screen.queryByLabelText(/^Marcia James,/)).toBeNull()
  })

  it('a real visitor’s card and row open the pass', async () => {
    mockModel = board({
      onSite: [{ key: 's:12', passId: '12', initials: 'MJ', name: 'Marcia James', line: 'Arrived 2:14 PM · Guest of Andrea Fletcher', badge: '45 min' }],
      activity: [{ key: 'a:12', passId: '12', entered: true, name: 'Marcia James', line: 'Entered', time: '2:14 PM' }],
    })
    await render(<VisitorsScreen />)
    await act(async () => {
      fireEvent.press(screen.getByLabelText('Marcia James, Arrived 2:14 PM · Guest of Andrea Fletcher, 45 min'))
    })
    expect(router.push).toHaveBeenLastCalledWith('/visitors/pass/12')
    await act(async () => {
      fireEvent.press(screen.getByLabelText('Marcia James, Entered, 2:14 PM'))
    })
    expect(router.push).toHaveBeenCalledTimes(2)
  })

  it('no rows, no "Recent activity" title over nothing', () => {
    const m = board({ activity: [] })
    expect(itemsOf(m, 'onSite').map((item) => item.kind)).toEqual(['card', 'card'])
    expect(itemsOf(board(), 'onSite').map((item) => item.kind)).toEqual(['card', 'card', 'title', 'row', 'row', 'row', 'row'])
  })
})

describe('a visitor still expected today (M47 §5.3)', () => {
  it('is a card like the rest: the name, what the visit is for, and the window in the badge, and it opens the pass', async () => {
    mockModel = board({ onSite: [{ key: 'e:31', passId: '31', initials: 'YK', name: 'Yahya Khan', line: 'Friend', badge: '9:39 AM – 12:00 PM' }], activity: [] })
    await render(<VisitorsScreen />)
    for (const text of ['YK', 'Yahya Khan', 'Friend', '9:39 AM – 12:00 PM']) expect(screen.getByText(text)).toBeTruthy()
    await act(async () => {
      fireEvent.press(screen.getByLabelText('Yahya Khan, Friend, 9:39 AM – 12:00 PM'))
    })
    expect(router.push).toHaveBeenLastCalledWith('/visitors/pass/31')
  })
})

describe('one line each (M47 §5.6)', () => {
  it('the badge sits beside the name, so the line under it is not squeezed beside the badge', async () => {
    mockModel = board()
    await render(<VisitorsScreen />)
    type Node = { parent: Node | null }
    const ancestors = (node: Node): Node[] => (node.parent ? [node.parent, ...ancestors(node.parent)] : [])
    const holds = (root: Node, node: Node) => ancestors(node).includes(root)
    // The smallest box holding the name and its badge is their row — and the line is not in it.
    const name = screen.getAllByText('Marcia James')[0] as unknown as Node
    const row = ancestors(name).find((box) => holds(box, screen.getByText('45 min') as unknown as Node))!
    expect(holds(row, screen.getByText('Arrived 2:14 PM · Guest of Andrea Fletcher') as unknown as Node)).toBe(false)
  })
})

describe('My e-Pass on this tab: its own row, never a visitor card (M50 §6.1, superseding M47 §5.3)', () => {
  it.each(['ready', 'loading', 'error'] as const)('is the first row of "On site now" while the list is %s, and opens the e-Pass', async (status) => {
    mockModel = board({ status, errorMessage: status === 'error' ? 'The server said no.' : null })
    await render(<VisitorsScreen />)
    expect(screen.getByText('My e-Pass')).toBeTruthy()
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'My e-Pass. Your own pass for the gate' }))
    })
    expect(router.push).toHaveBeenLastCalledWith('/visitors/pass/e-pass')
  })

  it('is never in History', async () => {
    mockModel = board()
    await render(<VisitorsScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('tab', { name: 'History' }))
    })
    expect(screen.queryByText('My e-Pass')).toBeNull()
  })
})

describe('an empty tab says so in one line (M50 §6.2)', () => {
  it('nobody on site or expected: one line and one way on, never a blank page', async () => {
    mockModel = board({ onSite: [], activity: [], history: [] })
    await render(<VisitorsScreen />)
    expect(screen.getByText('No visitors on site or expected today')).toBeTruthy()
    // The empty tab's own way on, beside the bar's "+": both invite somebody.
    expect(screen.getAllByRole('button', { name: 'Invite visitor' })).toHaveLength(2)
    await act(async () => {
      fireEvent.press(screen.getByRole('tab', { name: 'History' }))
    })
    expect(screen.getByText('No past visitors yet')).toBeTruthy()
  })
})

describe('an account the server refuses the visitors to (M50 §4.1: nothing says "not available yet")', () => {
  it('with no home on the register: says why, and offers no e-Pass, which it does not have', async () => {
    mockModel = board({ status: 'denied', errorMessage: 'This account has not been linked to a unit yet. Claim your unit, and the estate will approve it.' })
    await render(<VisitorsScreen />)
    expect(screen.getByText("Visitor passes aren't open to your account")).toBeTruthy()
    expect(screen.getByText('This account has not been linked to a unit yet. Claim your unit, and the estate will approve it.')).toBeTruthy()
    expect(screen.queryByText('My e-Pass')).toBeNull()
    expect(screen.queryByText(/not available yet/i)).toBeNull()
  })

  it('a Dependent or a Helper: the server\'s sentence, and their own e-Pass still at the top (M50 §6.1)', async () => {
    const said = 'Visitor passes are issued by the household\'s Members. Your access in the Fletcher household is Dependent: ask Andrea Fletcher, or the estate office, if that should change.'
    mockModel = board({ status: 'denied', errorMessage: said, epass: true })
    await render(<VisitorsScreen />)
    expect(screen.getByText(said)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'My e-Pass. Your own pass for the gate' })).toBeTruthy()
  })
})

describe('History with 250 passes', () => {
  beforeEach(() => {
    mockModel = board({ history: Array.from({ length: 250 }, (_, i) => past(i + 1)) })
  })

  it('mounts a first window of cards, not all 250: the list still holds every one', async () => {
    await render(<VisitorsScreen />)
    await act(async () => {
      fireEvent.press(screen.getByRole('tab', { name: 'History' }))
    })
    const mounted = cards().length
    // eslint-disable-next-line no-console
    console.log(`visitors history: ${mounted} of 250 cards mounted on first render`)
    expect(mounted).toBeGreaterThan(0)
    expect(mounted).toBeLessThan(60)
    expect(screen.getByLabelText('Visitor 1, Arrived 4:00 PM · Delivery pass, 6 Oct 2026')).toBeTruthy()
    expect(itemsOf(mockModel, 'history')).toHaveLength(250)
    // History draws only its cards: no "Recent activity" under them.
    expect(screen.queryByText('Recent activity')).toBeNull()
  })
})

describe('before the list has loaded', () => {
  it('a spinner on the first load, and no cards', async () => {
    mockModel = board({ status: 'loading' })
    await render(<VisitorsScreen />)
    expect(itemsOf(mockModel, 'onSite')).toEqual([])
    expect(screen.queryByText('Marcia James')).toBeNull()
  })

  it('a failed first load says so, with a way to try again', async () => {
    const refresh = jest.fn()
    mockModel = board({ status: 'error', errorMessage: 'The server said no.', refresh })
    await render(<VisitorsScreen />)
    expect(screen.getByText('The server said no.')).toBeTruthy()
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Try again' }))
    })
    expect(refresh).toHaveBeenCalledTimes(1)
  })
})

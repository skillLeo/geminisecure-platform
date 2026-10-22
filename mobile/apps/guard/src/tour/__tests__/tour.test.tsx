/**
 * THE FIRST-RUN TOUR (M32 F11, §14.3) — the Guard App's.
 *
 *   - it shows once and not twice: Done on the last card, and the next visit to Home has no tour;
 *   - Skip on card one never shows it again;
 *   - Next, Back and Skip are on every card, in the Guard App's tone, five cards at most;
 *   - it can be reopened from More, as "Show me around again", even after it has been seen — and that records nothing;
 *   - it does not appear during an emergency, a scan or a shift start: never over the duress screen, a ringing alert,
 *     the scanner, a walk-up or clocking in, and Home holds it back while a duress alert is live;
 *   - it is not shown before the account works: a handset that is not enrolled, or is enrolled but has not finished
 *     setup, has no tour.
 */

import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { Tour, TOUR_MAX, TOUR_NEVER_OVER } from '@gs/core'
import { getStorage, jsonStore } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { TOUR_CARDS, tourKey, tourStore, useFirstRunTour } from '../tour'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
let mockPath = '/(tabs)'
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, usePathname: () => mockPath, useFocusEffect: jest.fn() }))
type MockSession = { status: 'booting' | 'signedOut' | 'pending' | 'active'; principal: { id: number; name: string; siteName: string | null; reference: string | null } | null }
const mockState: { session: MockSession } = { session: { status: 'active', principal: null } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)

const SRC = path.join(__dirname, '..', '..')
const DESMOND = { id: 9001, name: 'Desmond Clarke', siteName: 'Greendale Estate', reference: 'GS-9001' }
const SETUP_DONE = { finishedAt: '2026-10-01T08:00:00Z', notificationsAskedAt: '2026-10-01T07:58:00Z', locationDisclosedAt: '2026-10-01T07:59:00Z' }

/** Home as far as the tour goes: the hook, and the tour when it says so. `duress` is a live duress alert. */
function Home({ duress = false }: { duress?: boolean }) {
  const tour = useFirstRunTour(duress)
  return tour.open ? <Tour tone="guard" cards={TOUR_CARDS} open onClose={tour.close} /> : null
}

const next = async () => act(async () => fireEvent.press(screen.getByText('Next')))

beforeEach(() => {
  jsonStore(getStorage(), 'tour', 'v1').remove()
  jsonStore(getStorage(), 'onboarding', 'v1').remove()
  onboarding.update(SETUP_DONE)
  mockPath = '/(tabs)'
  mockState.session = { status: 'active', principal: DESMOND }
})

describe('it shows once and not twice', () => {
  it('opens on the first visit to Home after setup, and not on the second once it is done', async () => {
    const first = await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
    expect(screen.getByText('Your shift')).toBeTruthy()

    for (let i = 1; i < TOUR_CARDS.length; i++) await next()
    await act(async () => fireEvent.press(screen.getByText('Done')))
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(tourStore.seen(tourKey(DESMOND)!)).toBe(true)
    await first.unmount()

    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })
})

describe('Skip on card one never shows it again', () => {
  it('closes from the very first card, and Home stays without it', async () => {
    const first = await render(<Home />)
    expect(screen.getByText('1 OF 5')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))
    expect(screen.queryByTestId('tour')).toBeNull()
    await first.unmount()

    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })
})

describe('Next, Back and Skip on every card', () => {
  it('walks forward and back with all three on each card, Done on the last', async () => {
    const closed = jest.fn()
    await render(<Tour tone="guard" cards={TOUR_CARDS} open onClose={closed} />)

    for (let i = 0; i < TOUR_CARDS.length; i++) {
      expect(screen.getByText(`${i + 1} OF ${TOUR_CARDS.length}`)).toBeTruthy()
      expect(screen.getByText(TOUR_CARDS[i]!.body)).toBeTruthy()
      expect(screen.getByTestId('tour-skip')).toBeTruthy()
      expect(screen.getByText('Back')).toBeTruthy()
      expect(screen.getByText(i === TOUR_CARDS.length - 1 ? 'Done' : 'Next')).toBeTruthy()
      if (i < TOUR_CARDS.length - 1) await next()
    }
    await act(async () => fireEvent.press(screen.getByLabelText('Back')))
    expect(screen.getByText(TOUR_CARDS[3]!.title)).toBeTruthy()

    // Android's back button: the Modal's request to close, as the sheets' tests send it.
    await act(async () => fireEvent(screen.root!, 'requestClose'))
    expect(closed).toHaveBeenCalledWith('skipped')
  })

  it('has Back on the first card too: off, and saying why', async () => {
    await render(<Tour tone="guard" cards={TOUR_CARDS} open onClose={jest.fn()} />)
    const back = screen.getByLabelText('Back')

    expect(back.props.accessibilityState?.disabled ?? back.props['aria-disabled']).toBe(true)
    expect(back.props.accessibilityHint).toBe('This is the first card.')
  })

  it('is the five cards §14.1 names, one short sentence or two each', () => {
    expect(TOUR_CARDS.length).toBeLessThanOrEqual(TOUR_MAX)
    expect(TOUR_CARDS.map((card) => card.title)).toEqual(['Your shift', 'Scan a visitor', 'Checkpoints', 'Report something', 'Alerts'])
    for (const card of TOUR_CARDS) {
      expect(card.body.split(/(?<=[.!?])\s+/).filter(Boolean).length).toBeLessThanOrEqual(2)
      expect(card.body.length).toBeLessThanOrEqual(140)
    }
  })
})

describe('it can be reopened from More', () => {
  it('opens again when asked, after it has been seen, and records nothing', async () => {
    tourStore.markSeen(tourKey(DESMOND)!, new Date('2026-10-01T09:00:00Z'))
    const before = jsonStore(getStorage(), 'tour', 'v1').get()

    await render(<Tour tone="guard" cards={TOUR_CARDS} open onClose={jest.fn()} />)
    expect(screen.getByTestId('tour')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))

    expect(jsonStore(getStorage(), 'tour', 'v1').get()).toEqual(before)
  })

  it('is a row on More called "Show me around again" that opens the same cards in place', () => {
    const more = readFileSync(path.join(SRC, 'features', 'more', 'screens', 'MoreScreen.tsx'), 'utf8')

    expect(more).toContain('title="Show me around again"')
    expect(more).toMatch(/<Tour tone="guard" cards=\{TOUR_CARDS\} open onClose=\{\(\) => setTouring\(false\)\} \/>/)
    expect(more).not.toMatch(/markSeen/)
  })
})

describe('it does not appear during an emergency, a scan or a shift start', () => {
  it.each(['/duress', '/alert/12', '/alertness', '/call-style', '/scan', '/walk-up', '/clock-in', '/clock-in-override', '/clock-out'])('not over %s', async (where) => {
    mockPath = where
    await render(<Tour tone="guard" cards={TOUR_CARDS} open onClose={jest.fn()} />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })

  it.each([
    ['/duress', 'duress.tsx'],
    ['/alert/7', 'alert/[id].tsx'],
    ['/alertness', 'alertness.tsx'],
    ['/call-style', 'call-style.tsx'],
    ['/scan', 'scan/index.tsx'],
    ['/scan/manual', 'scan/manual.tsx'],
    ['/walk-up', 'walk-up/index.tsx'],
    ['/walk-up/waiting', 'walk-up/waiting.tsx'],
    ['/clock-in', 'clock-in.tsx'],
    ['/clock-in-override', 'clock-in-override.tsx'],
    ['/clock-out', 'clock-out.tsx'],
  ])('%s is a screen the app really has (app/%s), and the tour never stands over it', (route, file) => {
    expect(existsSync(path.join(SRC, '..', 'app', file))).toBe(true)
    expect(TOUR_NEVER_OVER.test(route)).toBe(true)
  })

  it('Home holds it back while a duress alert is live, and raises it once the alert is over', async () => {
    const live = await render(<Home duress />)
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(tourStore.seen(tourKey(DESMOND)!)).toBe(false)
    await live.unmount()

    await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
  })

  it('Home mounts it with the live duress alert as its brake, and never in a fidelity build', () => {
    const home = readFileSync(path.join(SRC, 'features', 'dashboard', 'screens', 'DashboardScreen.tsx'), 'utf8')

    expect(home).toContain('const tour = useFirstRunTour(isLive(activeDuress))')
    expect(home).toContain('{!isFidelity && tour.open ? <Tour tone="guard" cards={TOUR_CARDS} open onClose={tour.close} /> : null}')
  })
})

describe('it is not shown before the account works', () => {
  it.each(['signedOut', 'booting'] as const)('%s: no tour', async (status) => {
    mockState.session = { status, principal: null }
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })

  it('enrolled but setup not finished: no tour, and none in the middle of setup', async () => {
    onboarding.update({ finishedAt: null })
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(tourStore.seen(tourKey(DESMOND)!)).toBe(false)
  })

  it('and the first visit to Home after setup finishes is the one that has it', async () => {
    onboarding.update({ locationDisclosedAt: null })
    const halfway = await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
    await halfway.unmount()

    onboarding.update(SETUP_DONE)
    await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
  })
})

/**
 * THE FIRST-RUN TOUR (M32 F11, §14.3) — the Resident App's.
 *
 *   - it shows once and not twice: Done on the last card, and a second visit to Home has no tour;
 *   - Skip on card one never shows it again;
 *   - Next and Skip are on every card, Back on every card after the first (M47 §3.4), five cards at most;
 *   - it is no longer reopened from Profile: the rebuilt Profile (board 17) draws no "Show me around again" row;
 *   - it never stands over the panic screen, and Home never raises it over the emergency it is showing;
 *   - it is not shown before the account works: a resident waiting for the estate's approval has no tour;
 *   - seen once is seen: the thirty-minute sign-out and a revocation keep the record, so the next sign-in has no tour.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { Tour, TOUR_MAX } from '@gs/core'
import { clearEverything } from '@gs/core/auth'
import { getStorage, jsonStore } from '@gs/core/lib'
import { TOUR_CARDS, tourKey, tourStore, useFirstRunTour } from '../tour'
import { TOUR_STORAGE_KEY } from '../tourKey'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
let mockPath = '/(tabs)'
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, usePathname: () => mockPath, useFocusEffect: jest.fn() }))
type MockSession = { status: 'booting' | 'signedOut' | 'pending' | 'active'; principal: { id: number; name: string; siteName: string | null; reference: string | null } | null; demo?: boolean }
const mockState: { session: MockSession } = { session: { status: 'active', principal: null } }
const mockDispatch = jest.fn()
jest.mock('@/store', () => ({ useAppDispatch: () => mockDispatch, useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

// The account's record, as `me.show` gives it (M47 §3). By default an OLDER server: it answered, and keeps no record.
type MockMe = { data?: { account: { shown?: { tour: string | null } } }; isSuccess: boolean; isError: boolean }
const OLD_SERVER: MockMe = { data: { account: {} }, isSuccess: true, isError: false }
let mockMe: MockMe = OLD_SERVER
jest.mock('@gs/core/api/resident', () => ({
  residentApi: { useMeShowQuery: () => mockMe, endpoints: { meShown: { initiate: (arg: unknown) => ({ mockShown: arg }) } } },
}))

jest.setTimeout(30_000)

const SRC = path.join(__dirname, '..', '..')
const ANDREA = { id: 41, name: 'Andrea Campbell', siteName: 'Greendale Estate', reference: 'Phase 2 · Lot 47' }

/** Home as far as the tour goes: the hook, and the tour when it says so. */
function Home() {
  const tour = useFirstRunTour()
  return tour.open ? <Tour cards={TOUR_CARDS} open onClose={tour.close} /> : null
}

const press = async (label: string) => act(async () => fireEvent.press(screen.getByLabelText(label)))
const next = async () => act(async () => fireEvent.press(screen.getByText('Next')))

beforeEach(() => {
  jsonStore(getStorage(), 'tour', 'v1').remove()
  mockPath = '/(tabs)'
  mockState.session = { status: 'active', principal: ANDREA }
  mockMe = OLD_SERVER
  mockDispatch.mockClear()
})

const toldTheAccount = () => mockDispatch.mock.calls.filter(([action]) => (action as { mockShown?: unknown }).mockShown !== undefined).map(([action]) => (action as { mockShown: unknown }).mockShown)

describe('once per ACCOUNT, on every phone (M47 §3)', () => {
  it('never shows for an account that has finished or skipped it: after a sign-out, a reinstall, on another phone', async () => {
    // Nothing on this phone at all, as after a chosen sign-out or a reinstall: the account's record decides.
    mockMe = { data: { account: { shown: { tour: '2026-10-07T14:02:11+00:00' } } }, isSuccess: true, isError: false }
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(toldTheAccount()).toEqual([])
  })

  it('shows for an account that has not, and finishing or skipping tells the account', async () => {
    mockMe = { data: { account: { shown: { tour: null } } }, isSuccess: true, isError: false }
    await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()

    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(toldTheAccount()).toEqual([{ key: 'tour' }])
    expect(tourStore.seen(tourKey(ANDREA)!)).toBe(true)
  })

  it('seen on this phone but not yet on the account (no signal when it closed): not shown, and the account is told', async () => {
    tourStore.markSeen(tourKey(ANDREA)!)
    mockMe = { data: { account: { shown: { tour: null } } }, isSuccess: true, isError: false }
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(toldTheAccount()).toEqual([{ key: 'tour' }])
  })

  it('waits for the account to answer, so it never flashes up and vanishes', async () => {
    mockMe = { data: undefined, isSuccess: false, isError: false }
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })

  it('on an older server that keeps no record, this phone decides, as before', async () => {
    const first = await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))
    await first.unmount()
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })

  it('a demonstration session asks the account for nothing', async () => {
    mockState.session = { status: 'active', principal: ANDREA, demo: true }
    mockMe = { data: { account: { shown: { tour: null } } }, isSuccess: true, isError: false }
    await render(<Home />)
    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))
    expect(toldTheAccount()).toEqual([])
  })
})

describe('seen once is seen, through a session that ends by itself', () => {
  it('is kept when the session ends by itself (the thirty-minute window, a revocation), and goes with a chosen sign-out', async () => {
    tourStore.markSeen(tourKey(ANDREA)!)
    expect(getStorage().keys()).toContain(TOUR_STORAGE_KEY)

    for (const reason of ['expired', 'revoked'] as const) {
      await clearEverything('resident', jest.fn(), reason, [TOUR_STORAGE_KEY])
      expect(tourStore.seen(tourKey(ANDREA)!)).toBe(true)
    }

    await clearEverything('resident', jest.fn(), 'signed_out', [TOUR_STORAGE_KEY])
    expect(tourStore.seen(tourKey(ANDREA)!)).toBe(false)
  })

  it("is on the store's list of what a session that ends by itself keeps", () => {
    expect(readFileSync(path.join(SRC, 'store', 'index.ts'), 'utf8')).toMatch(/clearEverything\('resident', store\.dispatch, reason, \[[^\]]*\bTOUR_STORAGE_KEY\b[^\]]*\]\)/)
  })

  it('so the same account, signed out by the thirty minutes and back in, comes to a Home with no tour', async () => {
    tourStore.markSeen(tourKey(ANDREA)!)
    await clearEverything('resident', jest.fn(), 'expired', [TOUR_STORAGE_KEY])
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })
})

describe('it shows once and not twice', () => {
  it('opens on the first visit to Home, and not on the second once it is done', async () => {
    const first = await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
    expect(screen.getByText(TOUR_CARDS[0]!.title)).toBeTruthy()

    for (let i = 1; i < TOUR_CARDS.length; i++) await next()
    expect(screen.getByText(TOUR_CARDS[TOUR_CARDS.length - 1]!.title)).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByText('Done')))
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(tourStore.seen(tourKey(ANDREA)!)).toBe(true)
    await first.unmount()

    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })

  it('is per account on this phone: someone else signing in here still gets theirs', async () => {
    tourStore.markSeen(tourKey(ANDREA)!)
    mockState.session = { status: 'active', principal: { ...ANDREA, id: 42, name: 'Marcus Reid' } }
    await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
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
  it('walks forward and back, with all three on each card and Done on the last', async () => {
    const closed = jest.fn()
    await render(<Tour cards={TOUR_CARDS} open onClose={closed} />)

    for (let i = 0; i < TOUR_CARDS.length; i++) {
      expect(screen.getByText(`${i + 1} OF ${TOUR_CARDS.length}`)).toBeTruthy()
      expect(screen.getByText(TOUR_CARDS[i]!.title)).toBeTruthy()
      expect(screen.getByText(TOUR_CARDS[i]!.body)).toBeTruthy()
      expect(screen.getByTestId('tour-skip')).toBeTruthy()
      // M47 §3.4: no Back on the first card — there is nowhere to go back to.
      if (i === 0) expect(screen.queryByText('Back')).toBeNull()
      else expect(screen.getByText('Back')).toBeTruthy()
      expect(screen.getByText(i === TOUR_CARDS.length - 1 ? 'Done' : 'Next')).toBeTruthy()
      if (i < TOUR_CARDS.length - 1) await next()
    }

    await press('Back')
    expect(screen.getByText(TOUR_CARDS[TOUR_CARDS.length - 2]!.title)).toBeTruthy()
    await next()
    await act(async () => fireEvent.press(screen.getByText('Done')))
    expect(closed).toHaveBeenCalledWith('done')
  })

  it('has no Back on the first card (M47 §3.4), and Back from the second goes to the first', async () => {
    await render(<Tour cards={TOUR_CARDS} open onClose={jest.fn()} />)
    expect(screen.queryByLabelText('Back')).toBeNull()

    await next()
    await press('Back')
    expect(screen.getByText('1 OF 5')).toBeTruthy()
    expect(screen.queryByLabelText('Back')).toBeNull()
  })

  it('skips from any card, Android back included', async () => {
    const closed = jest.fn()
    await render(<Tour cards={TOUR_CARDS} open onClose={closed} />)
    await next()
    await next()
    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))
    expect(closed).toHaveBeenLastCalledWith('skipped')

    // Android's back button: the Modal's request to close, as the sheets' tests send it.
    closed.mockClear()
    await next()
    await act(async () => fireEvent(screen.root!, 'requestClose'))
    expect(closed).toHaveBeenCalledWith('skipped')
  })

  it('is five cards, one short sentence or two each, drawn from the icon set', () => {
    expect(TOUR_CARDS).toHaveLength(5)
    expect(TOUR_CARDS.length).toBeLessThanOrEqual(TOUR_MAX)
    expect(TOUR_CARDS.map((card) => card.title)).toEqual(['Visitors', 'Panic', 'Dues', 'Maintenance', 'Community'])
    for (const card of TOUR_CARDS) {
      expect(card.body.split(/(?<=[.!?])\s+/).filter(Boolean).length).toBeLessThanOrEqual(2)
      expect(card.body.length).toBeLessThanOrEqual(140)
    }
  })

  it('says the panic hold the app really asks for, a second and a half, and never "pay it"', () => {
    const hold = readFileSync(path.join(SRC, 'features', 'panic', 'usePanic.ts'), 'utf8').match(/export const HOLD_MS = (\d+)/)?.[1]
    const panic = TOUR_CARDS.find((card) => card.key === 'panic')!
    const dues = TOUR_CARDS.find((card) => card.key === 'dues')!

    expect(hold).toBe('1500')
    expect(panic.body).toContain('a second and a half')
    expect(dues.body).not.toMatch(/\bpay it\b/i)
  })
})

describe('it can be reopened from Profile', () => {
  it('opens again when asked, after it has been seen, and records nothing', async () => {
    tourStore.markSeen(tourKey(ANDREA)!, new Date('2026-10-01T09:00:00Z'))
    const before = jsonStore(getStorage(), 'tour', 'v1').get()

    await render(<Tour cards={TOUR_CARDS} open onClose={jest.fn()} />)
    expect(screen.getByTestId('tour')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByTestId('tour-skip')))

    expect(jsonStore(getStorage(), 'tour', 'v1').get()).toEqual(before)
  })

  // Profile no longer offers "Show me around again": board 17 draws no such row (M42 §5 #28, the reverse checklist).
  it('is not reopened from Profile, the rebuilt board draws no such row, and Profile marks nothing seen', () => {
    const profile = readFileSync(path.join(SRC, 'features', 'profile', 'screens', 'ProfileScreen.tsx'), 'utf8')

    expect(profile).not.toContain('Show me around again')
    expect(profile).not.toMatch(/markSeen/)
  })
})

describe('it never stands over an emergency', () => {
  it('is not drawn over the panic screen', async () => {
    mockPath = '/panic'
    await render(<Tour cards={TOUR_CARDS} open onClose={jest.fn()} />)
    expect(screen.queryByTestId('tour')).toBeNull()
  })

  it('Home raises it only when it is the screen in front and no approval is waiting on the resident', () => {
    const home = readFileSync(path.join(SRC, 'features', 'home', 'screens', 'HomeScreen.tsx'), 'utf8')

    expect(home).toMatch(/\{!isFidelity && tour\.open && m\.approvals\.length === 0 \? <Tour cards=\{TOUR_CARDS\} open onClose=\{tour\.close\} \/> : null\}/)
    expect(readFileSync(path.join(SRC, 'tour', 'tour.ts'), 'utf8')).toMatch(/useFocusEffect/)
  })
})

describe('it is not shown before the account works', () => {
  it.each(['pending', 'signedOut', 'booting'] as const)('%s: no tour', async (status) => {
    mockState.session = { status, principal: status === 'pending' ? ANDREA : null }
    await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
    expect(tourStore.seen(tourKey(ANDREA)!)).toBe(false)
  })

  it('and the first visit after the estate approves is the one that has it', async () => {
    mockState.session = { status: 'pending', principal: ANDREA }
    const waiting = await render(<Home />)
    expect(screen.queryByTestId('tour')).toBeNull()
    await waiting.unmount()

    mockState.session = { status: 'active', principal: ANDREA }
    await render(<Home />)
    expect(screen.getByTestId('tour')).toBeTruthy()
  })
})

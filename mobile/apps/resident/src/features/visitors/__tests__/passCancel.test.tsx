/**
 * J-PASS-1 — CANCELLING A VISITOR PASS IS ASKED ON EVERY PLATFORM, IN A SHEET THAT NAMES THE VISITOR.
 *
 * It was a native Alert on a phone and, in a browser, NO QUESTION AT ALL: `if (Platform.OS === 'web') return
 * m.cancel()` cancelled on the first tap. Now "Cancel Jane Brown's pass?" is a ConfirmSheet everywhere, and `cancel()`
 * says what became of it — done (the server cancelled it), saved (no connection: on this phone, the gate learns
 * later) or refused (in the server's words, which stay in the sheet).
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, renderHook } from '@testing-library/react-native'
import { DRAFT_SENDING, draftSent, passCancelSheet, discardDraftSheet } from '../passes'
import { usePass } from '../useVisitors'

jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Success: 'success' } }))
jest.mock('@gs/core', () => {
  const lib = jest.requireActual('@gs/core/lib')
  return { getStorage: lib.getStorage, jsonStore: lib.jsonStore, newUuid: lib.newUuid, useOfflineCache: lib.useOfflineCache, isFidelity: false, now: () => new Date() }
})

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const DAY = 86_400_000
const mockPass = { id: 12, visitor_name: 'Jane Brown', status: 'active', category: 'single', valid_from: new Date(Date.now() - 3_600_000).toISOString(), valid_to: new Date(Date.now() + DAY).toISOString(), token: 't', code: 'ABC123', share_count: 0, purpose: null, vehicle_plate: null, visitor_phone: null, cancelled_at: null }
const mockCalls: unknown[] = []
let mockAnswer: () => Answer = () => ({ data: { ...mockPass, status: 'cancelled', cancelled_at: new Date().toISOString() } })
async function mockCancel(arg: unknown): Promise<Answer> {
  mockCalls.push(arg)
  return mockAnswer()
}
const mockList = { data: { items: [mockPass] }, error: undefined, isLoading: false, isUninitialized: false, isFetching: false, refetch: jest.fn(async () => undefined) }
const mockCancelMutation = [mockCancel]
const mockNoop = [jest.fn()]
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useVisitorPassesIndexQuery: () => mockList,
    useEpassShowQuery: () => mockList,
    useVisitorPassesStoreMutation: () => mockNoop,
    useVisitorPassesShareMutation: () => mockNoop,
    useVisitorPassesCancelMutation: () => mockCancelMutation,
  },
}))
const mockSubmit = jest.fn(async (_input: unknown) => undefined)
jest.mock('@/offline/runtime', () => ({ submit: (input: unknown) => mockSubmit(input) }))
let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: { siteName: 'Phoenix Park Village' } } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)

beforeEach(() => {
  mockList.isFetching = false
  mockCalls.length = 0
  mockSubmit.mockClear()
  mockAnswer = () => ({ data: { ...mockPass, status: 'cancelled', cancelled_at: new Date().toISOString() } })
  mockRefusal = null
  mockState.network.online = true
})

describe('M50 §6.4: loading is never "not found"', () => {
  it('a pass just issued, while the list is asked for again, is LOADING, and "not on this phone" only once it has answered without it', async () => {
    mockList.isFetching = true
    const { result, rerender } = await renderHook(({ id }: { id: string }) => usePass(id), { initialProps: { id: '99' } })
    expect(result.current.status).toBe('loading')

    mockList.isFetching = false
    await rerender({ id: '99' })
    expect(result.current.status).toBe('missing')
  })

  it('a pass the phone holds is shown at once, however long the list takes', async () => {
    mockList.isFetching = true
    const { result } = await renderHook(() => usePass('12'))
    expect(result.current.status).toBe('ready')
  })
})

describe('what the sheet says', () => {
  it('names the visitor in full; the way out keeps the pass', () => {
    const words = passCancelSheet('Jane Brown', true)
    expect(words.title).toBe("Cancel Jane Brown's pass?")
    expect(words.confirmLabel).toBe("Cancel Jane Brown's pass")
    expect(words.cancelLabel).toBe('Keep the pass')
    expect(words.lines).toEqual(['They will not be able to enter on it. This cannot be undone: you would send a new pass.'])
  })

  it('with no connection it says BEFORE the tap that the gate learns of it later: it is never refused for that', () => {
    const words = passCancelSheet('Jane Brown', false)
    // Nothing is said as if the gate already knew: offline, the server has not been told, so ANY guard may still admit.
    expect(words.lines[0]).toMatch(/^Once the gate is told/)
    expect(words.lines[1]).toMatch(/saved on this phone/)
    expect(words.lines[1]).toMatch(/until then any guard may still admit on it/)
  })

  it('a draft is discarded with its own question, which says the visitor could never have entered on it', () => {
    const words = discardDraftSheet('Jane Brown')
    expect(words.title).toBe('Discard the draft for Jane Brown?')
    expect(words.lines).toEqual(['It was never sent, so Jane Brown cannot enter on it. What you typed is deleted from this phone.'])
    expect(words.confirmLabel).toBe('Discard the draft')
    expect(words.cancelLabel).toBe('Keep the draft')
  })

  it('never says "it was never sent" about a draft that is being sent, or that has just become a real pass', () => {
    expect(DRAFT_SENDING).toMatch(/being sent right now/)
    expect(DRAFT_SENDING).not.toMatch(/never sent/)
    expect(draftSent('  Jane   Brown ')).toBe('This draft has just been sent, so it is a real pass now and there is no draft to discard. If Jane Brown should not come, open the pass from Home’s Expected today and cancel it.')
  })
})

describe('cancel() says what became of it', () => {
  it("'done' when the server cancelled it, and the pass shows as cancelled at once", async () => {
    const { result } = await renderHook(() => usePass('12'))
    let outcome: unknown
    await act(async () => { outcome = await result.current.cancel() })
    expect(outcome).toEqual({ outcome: 'done' })
    expect(mockCalls).toEqual([{ pass: 12 }])
    expect(result.current.pass?.status).toBe('cancelled')
  })

  it("'refused' with the server's own words when it says no", async () => {
    mockAnswer = () => ({ error: { status: 409, code: 'pass_not_active', message: 'This pass has already been used at the gate.', fieldErrors: {}, retryAfter: null } })
    const { result } = await renderHook(() => usePass('12'))
    let outcome: unknown
    await act(async () => { outcome = await result.current.cancel() })
    expect(outcome).toEqual({ outcome: 'refused', message: 'This pass has already been used at the gate.' })
    expect(result.current.pass?.status).toBe('active')
  })

  it("'saved' with no connection: kept on this phone, and the pass says the gate learns of it later", async () => {
    mockState.network.online = false
    const { result } = await renderHook(() => usePass('12'))
    let outcome: unknown
    await act(async () => { outcome = await result.current.cancel() })
    expect(outcome).toEqual({ outcome: 'saved' })
    expect(mockCalls).toEqual([])
    expect(mockSubmit).toHaveBeenCalledWith(expect.objectContaining({ endpoint: 'visitor_passes.cancel', params: { pass: 12 } }))
    expect(result.current.note).toMatch(/Saved on this phone/)
  })

  it("'refused' before anything is sent or saved in a demonstration session", async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => usePass('12'))
    let outcome: unknown
    await act(async () => { outcome = await result.current.cancel() })
    expect(outcome).toEqual({ outcome: 'refused', message: mockRefusal })
    expect(mockCalls).toEqual([])
    expect(mockSubmit).not.toHaveBeenCalled()
  })
})

/*
 * The Visitors tab no longer draws drafts (M42 §5 #7 deleted "Expected", where they were listed, and board 08 draws
 * none), so it no longer offers a Discard to ask about. The sheet's words stay, tested above.
 */
describe('the screen', () => {
  const screen = readFileSync(path.join(__dirname, '..', 'screens', 'PassScreen.tsx'), 'utf8')

  it('asks on EVERY platform, the web no longer cancels on the first tap, in a ConfirmSheet, not a native alert', () => {
    expect(screen).not.toContain("Platform.OS === 'web') return m.cancel()")
    expect(screen).not.toContain('Alert.alert')
    expect(screen).toContain('<ConfirmSheet')
    expect(screen).toContain('passCancelSheet(')
  })
})

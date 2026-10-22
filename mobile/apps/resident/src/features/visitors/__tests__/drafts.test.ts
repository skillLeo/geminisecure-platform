/**
 * B119 — AN INVITATION DRAFT BELONGS TO THE PERSON WHO WROTE IT, AND GOES WHEN THEY DO.
 *
 * A draft is a guest's name, phone and plate, written with no connection. The sign-out warning tells
 * the resident "Signing out deletes them too", and `clearEverything` promises that the next person on
 * this phone never sees the last person's data. Both were untrue while the drafts were ALSO held in a
 * module-level copy: storage was wiped, the copy was not, and the next account to sign in on the same
 * phone — without the app being restarted — was shown the last resident's guests, with a working
 * "Send now" that would issue a signed pass for them under the new household. Any draft the new
 * account wrote saved the old ones back to disk beside it.
 *
 * Proved by running what the app runs: the real invite hook writes the draft, the real
 * `clearEverything` ends the session for each of its three reasons, and the real list hook is asked
 * what it shows next.
 */

import { act, renderHook } from '@testing-library/react-native'
import { getStorage } from '@gs/core/lib'
import { clearEverything } from '@gs/core/auth'
import { DRAFTS_STORAGE_KEY, adoptDrafts, draftOwner } from '../draftStore'
import { useInvite, useVisitorList } from '../useVisitors'

jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Success: 'success' } }))

// 8:00 AM in Kingston: the invite form's default window (now, for two hours — M12 §3.1) has not passed.
const mockNow = new Date('2026-09-24T13:00:00.000Z')
jest.mock('@gs/core', () => {
  const lib = jest.requireActual('@gs/core/lib')
  return { getStorage: lib.getStorage, jsonStore: lib.jsonStore, newUuid: lib.newUuid, useOfflineCache: lib.useOfflineCache, isFidelity: false, now: () => new Date(mockNow) }
})

const NO_SIGNAL = { status: 'network', code: 'network_unreachable', message: 'offline', fieldErrors: {}, retryAfter: null }
const mockIssue = jest.fn(async (_body: unknown) => ({ error: NO_SIGNAL }) as Record<string, unknown>)
const mockList = { data: { items: [] }, error: undefined, isLoading: false, isUninitialized: false, refetch: jest.fn(async () => undefined) }
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useVisitorPassesIndexQuery: () => mockList,
    useVisitorPassesStoreMutation: () => [mockIssue],
    useVisitorPassesShareMutation: () => [jest.fn()],
    useVisitorPassesCancelMutation: () => [jest.fn()],
    useEpassShowQuery: () => mockList,
  },
}))
jest.mock('@/offline/runtime', () => ({ submit: jest.fn(async () => undefined) }))

const mockState = { network: { online: false }, session: { status: 'active', demo: false, principal: { id: 1, name: 'Resident A', siteName: 'Phoenix Park Village', reference: null } } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

const DRAFTS_ON_DISK = 'v1:passes.drafts'

/** Resident on this phone writes an invitation with no connection — the app keeps it as a draft. */
async function writeDraft(guest: { name: string; phone: string; plate: string }): Promise<void> {
  const done = jest.fn()
  const invite = await renderHook(() => useInvite(done))
  await act(async () => invite.result.current.set('name', guest.name))
  await act(async () => invite.result.current.set('phone', guest.phone))
  await act(async () => invite.result.current.set('plate', guest.plate))
  // Driving in with a plate, or walking in — chosen, never neither (M18C §3.1; M42 §5 #6).
  await act(async () => invite.result.current.set('arrival', guest.plate ? 'drive' : 'walk'))
  // Required since M12 §2.13.
  await act(async () => invite.result.current.set('purpose', 'Family visit'))
  await act(async () => invite.result.current.submit())
  await act(async () => {
    await Promise.resolve()
    await Promise.resolve()
  })
  expect(done).toHaveBeenCalledWith({ kind: 'draft' })
  await invite.unmount()
}

const onDisk = (): { visitor_name: string }[] => JSON.parse(getStorage().getString(DRAFTS_ON_DISK) ?? '[]')

describe.each(['signed_out', 'revoked', 'expired'] as const)('after the session ends (%s), the next account on this phone', (reason) => {
  beforeEach(async () => {
    await getStorage().clearAll()
    mockIssue.mockClear()
    mockState.session.principal.id = 1
  })

  it('is shown none of the last resident’s drafts, not on a new screen, not on one still mounted', async () => {
    await writeDraft({ name: 'Mark Brown', phone: '876-555-0101', plate: 'PC 1234' })

    // Resident A opens Visitors: the draft is there, as it should be.
    const listA = await renderHook(() => useVisitorList())
    expect(listA.result.current.drafts.map((d) => d.visitor_name)).toEqual(['Mark Brown'])
    const theirs = listA.result.current.drafts[0]!.id

    // A leaves — by choice, by the estate, or by the idle window. No app restart: module memory survives.
    await clearEverything('resident', jest.fn(), reason)
    mockState.session.principal.id = 2

    // A screen still mounted while the store was wiped draws nothing of A's once it next renders…
    await listA.rerender(undefined)
    expect(listA.result.current.drafts).toEqual([])
    await listA.unmount()

    // …and resident B, signing in on the same phone and opening Visitors, sees no draft and cannot send one.
    const listB = await renderHook(() => useVisitorList())
    expect(listB.result.current.drafts).toEqual([])
    mockState.network.online = true
    await act(async () => listB.result.current.sendDraft(theirs))
    mockState.network.online = false
    expect(mockIssue).toHaveBeenCalledTimes(1) // A's own offline attempt only: no pass was asked for A's guest under B
    await listB.unmount()
  })

  it('never writes the last resident’s drafts back to disk beside a new one', async () => {
    await writeDraft({ name: 'Mark Brown', phone: '876-555-0101', plate: 'PC 1234' })
    const listA = await renderHook(() => useVisitorList())
    await listA.unmount()

    await clearEverything('resident', jest.fn(), reason)
    mockState.session.principal.id = 2
    expect(getStorage().getString(DRAFTS_ON_DISK)).toBeNull()

    await writeDraft({ name: 'Keisha Grant', phone: '876-555-0199', plate: '' })
    expect(onDisk().map((d) => d.visitor_name)).toEqual(['Keisha Grant'])

    const listB = await renderHook(() => useVisitorList())
    expect(listB.result.current.drafts.map((d) => d.visitor_name)).toEqual(['Keisha Grant'])
    await listB.unmount()
  })
})

/*
 * WITH THE APP'S OWN KEEP LIST (store/index.ts): a sign-out nobody chose keeps the drafts like the queue (D-M160), for
 * the SAME person — and B119 still holds for anybody else.
 */
describe.each(['revoked', 'expired'] as const)('a session that ended with nobody choosing it (%s)', (reason) => {
  beforeEach(async () => {
    await getStorage().clearAll()
    mockIssue.mockClear()
    mockState.session.principal.id = 1
  })

  it('gives the SAME resident their drafts back when they sign in again', async () => {
    await writeDraft({ name: 'Mark Brown', phone: '876-555-0101', plate: 'PC 1234' })
    await clearEverything('resident', jest.fn(), reason, [DRAFTS_STORAGE_KEY])
    adoptDrafts(draftOwner(1))
    const list = await renderHook(() => useVisitorList())
    expect(list.result.current.drafts.map((d) => d.visitor_name)).toEqual(['Mark Brown'])
    await list.unmount()
  })

  it('shows another resident none of them, and their sign-in deletes them from the phone', async () => {
    await writeDraft({ name: 'Mark Brown', phone: '876-555-0101', plate: 'PC 1234' })
    await clearEverything('resident', jest.fn(), reason, [DRAFTS_STORAGE_KEY])
    mockState.session.principal.id = 2
    // Drawn for nobody but their owner, even before the new session has adopted anything.
    const list = await renderHook(() => useVisitorList())
    expect(list.result.current.drafts).toEqual([])
    adoptDrafts(draftOwner(2))
    expect(onDisk()).toEqual([])
    await list.unmount()
  })
})

it('a CHOSEN sign-out deletes the drafts whatever the keep list says, as its warning promises', async () => {
  await getStorage().clearAll()
  mockState.session.principal.id = 1
  await writeDraft({ name: 'Mark Brown', phone: '876-555-0101', plate: 'PC 1234' })
  await clearEverything('resident', jest.fn(), 'signed_out', [DRAFTS_STORAGE_KEY])
  expect(getStorage().getString(DRAFTS_ON_DISK)).toBeNull()
})

describe('within one session the drafts still behave as drafts', () => {
  beforeEach(async () => {
    await getStorage().clearAll()
    mockIssue.mockClear()
    mockState.session.principal.id = 1
  })

  it('a mounted list sees a draft written elsewhere, and a discarded one goes from the list and from disk', async () => {
    const list = await renderHook(() => useVisitorList())
    expect(list.result.current.drafts).toEqual([])

    await writeDraft({ name: 'Mark Brown', phone: '876-555-0101', plate: 'PC 1234' })
    expect(list.result.current.drafts.map((d) => d.visitor_name)).toEqual(['Mark Brown'])
    expect(onDisk().map((d) => d.visitor_name)).toEqual(['Mark Brown'])

    const id = list.result.current.drafts[0]!.id
    await act(async () => list.result.current.discardDraft(id))
    expect(list.result.current.drafts).toEqual([])
    expect(onDisk()).toEqual([])
    await list.unmount()
  })
})

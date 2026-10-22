/**
 * The new request's hook, LIVE (not the board's fixture):
 *
 *   - the waveform is the level the recogniser reports (Report incident's level-reading dictation), nothing else;
 *   - what is being heard shows in the card as it is heard, and a tap in to edit stops the listening;
 *   - what is sent is the chip under the server's category, the title written from the resident's words and the place
 *     (M47 §11.5), entry by name — and no "best time" (M42 §5 #10);
 *   - a form with something missing, or a demonstration session, sends nothing and says why;
 *   - a photo is asked for in a sheet that closes before the picker opens, and asks before it is taken back.
 */

import { act, renderHook, waitFor } from '@testing-library/react-native'
import { REQUEST_WORDS } from '../dictation'
import { useMaintenanceRequest } from '../useMaintenanceRequest'

jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Success: 'success' } }))
jest.mock('@gs/core', () => ({ isFidelity: false, fidelityScreenId: () => null }))

type Heard = { available: boolean; reason: string | null; listening: boolean; hearing: string; trouble: string | null; note: string | null; model: null; toggle: jest.Mock }
let mockDictation: Heard
// What the recogniser last reported, bar by bar: the waveform's only source.
let mockLevels: readonly number[]
const mockPhrasing = jest.fn()
jest.mock('@/features/incidents/dictation', () => {
  const actual = jest.requireActual<typeof import('@/features/incidents/dictation')>('@/features/incidents/dictation')
  return {
    ...actual,
    useReportDictation: (_onHeard: (words: string) => void, phrasing: unknown) => {
      mockPhrasing(phrasing)
      return { ...mockDictation, levels: actual.stillLevels(mockLevels) }
    },
  }
})

const mockPick = jest.fn(async (_source: string, _room: number) => ({ photos: [{ uri: 'file:///a.jpg', mime: 'image/jpeg', fileName: null }], trouble: null as string | null }))
jest.mock('../photoPicker', () => ({ PHOTOS_UNAVAILABLE: 'Photos cannot be added here.', photosAvailable: () => true, pickPhotos: (source: string, room: number) => mockPick(source, room) }))

type Captured = { endpoint: string; body: Record<string, unknown>; after?: string }
let mockMedia = 0
const mockSubmit = jest.fn(async (input: Captured): Promise<{ id: string }> => ({ id: input.endpoint === 'tickets.store' ? 'op-1' : `m-${(mockMedia += 1)}` }))
jest.mock('@/offline/runtime', () => ({ submit: (input: Captured) => mockSubmit(input), drain: jest.fn(), drainNow: jest.fn(), outbox: { all: () => [] } }))

let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
jest.mock('../useTickets', () => ({ clearRefusal: jest.fn(async () => undefined) }))
const mockState = { outbox: { ops: [] as unknown[] }, session: { principal: { reference: 'Phase 2 · Lot 47' } } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)

beforeEach(() => {
  mockDictation = { available: true, reason: null, listening: false, hearing: '', trouble: null, note: null, model: null, toggle: jest.fn() }
  mockLevels = [0, 0, 0, 0, 0, 0, 0]
  mockPhrasing.mockClear()
  mockRefusal = null
  mockSubmit.mockClear()
  mockPick.mockClear()
})

const fill = async (result: { current: ReturnType<typeof useMaintenanceRequest> }, over: { kind?: 'plumbing' | 'electrical' | 'lighting' | 'gate'; words?: string } = {}) => {
  await act(async () => result.current.chooseChip(over.kind ?? 'lighting'))
  await act(async () => result.current.transcript.change(over.words ?? 'The light by the gate is out.'))
}

describe('the microphone, live', () => {
  it('the bars are the level the recogniser reports, Report incident’s, in the request’s own words, and "Listening…" only while it listens', async () => {
    mockDictation.listening = true
    mockLevels = [0, 0.1, 0.4, 0.9, 0.3, 0.6, 0.2]
    const { result, rerender } = await renderHook(() => useMaintenanceRequest())
    expect(result.current.mic.listening).toBe(true)
    expect(result.current.mic.levels.get()).toEqual([0, 0.1, 0.4, 0.9, 0.3, 0.6, 0.2])
    expect(mockPhrasing).toHaveBeenCalledWith(REQUEST_WORDS)
    expect(result.current.mic.notice).toBeNull()
    mockDictation.listening = false
    await rerender({})
    expect(result.current.mic.listening).toBe(false)
  })

  it('what is heard shows in the card as it is heard; a tap in to edit stops the listening', async () => {
    mockDictation.listening = true
    mockDictation.hearing = 'di gate light'
    const { result } = await renderHook(() => useMaintenanceRequest())
    expect(result.current.transcript.value).toBe('di gate light')
    await act(async () => result.current.transcript.focus())
    expect(mockDictation.toggle).toHaveBeenCalledTimes(1)
  })

  it('a phone that cannot listen says why, in the panel', async () => {
    mockDictation = { ...mockDictation, available: false, reason: 'Speaking is not available here.' }
    const { result } = await renderHook(() => useMaintenanceRequest())
    expect(result.current.mic.available).toBe(false)
    expect(result.current.mic.notice).toBe('Speaking is not available here.')
  })
})

describe('what is sent', () => {
  it('the chip under the server’s category, the title written from what is wrong and the unit, entry by name, and no best time', async () => {
    const { result } = await renderHook(() => useMaintenanceRequest())
    await fill(result)
    await act(async () => result.current.entry.set(true))
    await act(async () => result.current.send())
    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1))
    expect(mockSubmit.mock.calls[0]![0]).toMatchObject({ endpoint: 'tickets.store' })
    expect(mockSubmit.mock.calls[0]![0].body).toEqual({ title: 'The light by the gate is out: Phase 2 · Lot 47', category: 'electrical', description: 'The light by the gate is out.', priority: 'medium', access_permitted: true })
    await waitFor(() => expect(result.current.recorded?.title).toBe('The light by the gate is out: Phase 2 · Lot 47'))
    // Nothing has come back from the server: it is on this phone, never "submitted".
    expect(result.current.recorded?.state).toBe('pending')
  })

  it('a common area is filed as one, and entry is not asked', async () => {
    const { result } = await renderHook(() => useMaintenanceRequest())
    await fill(result, { kind: 'gate', words: 'The gate arm sticks open.' })
    await act(async () => result.current.chooseArea('common'))
    expect(result.current.entry.shown).toBe(false)
    await act(async () => result.current.send())
    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1))
    expect(mockSubmit.mock.calls[0]![0].body).toEqual({ title: 'The gate arm sticks open: Common area', category: 'security', description: 'The gate arm sticks open.', location: 'Common area', priority: 'medium' })
  })

  it('with nothing chosen nothing is sent, and the first thing to put right is said', async () => {
    const { result } = await renderHook(() => useMaintenanceRequest())
    await act(async () => result.current.send())
    await waitFor(() => expect(result.current.problem).toBe('Choose what kind of problem it is.'))
    expect(result.current.transcript.attention).toBe(true)
    expect(mockSubmit).not.toHaveBeenCalled()
    // Put right, the line goes.
    await fill(result)
    await waitFor(() => expect(result.current.problem).toBeNull())
  })

  it('a demonstration session captures nothing, and is told why', async () => {
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const { result } = await renderHook(() => useMaintenanceRequest())
    await fill(result)
    await act(async () => result.current.send())
    await waitFor(() => expect(result.current.problem).toBe(mockRefusal))
    expect(mockSubmit).not.toHaveBeenCalled()
  })
})

describe('photos', () => {
  it('asked for in a sheet that closes BEFORE the picker opens; the photo is its thumbnail; taken back only once asked', async () => {
    const { result } = await renderHook(() => useMaintenanceRequest())
    expect(result.current.photos.thumbs).toEqual([])
    expect(result.current.photos.canAdd).toBe(true)
    await act(async () => result.current.photos.adding.ask())
    expect(result.current.photos.adding.open).toBe(true)
    await act(async () => result.current.photos.adding.take())
    expect(result.current.photos.adding.open).toBe(false)
    await waitFor(() => expect(mockPick).toHaveBeenCalledWith('camera', 4))
    await waitFor(() => expect(result.current.photos.thumbs.map((t) => t.uri)).toEqual(['file:///a.jpg']))

    await act(async () => result.current.photos.thumbs[0]!.remove!())
    expect(result.current.photos.removing.open).toBe(true)
    expect(result.current.photos.thumbs).toHaveLength(1)
    await act(async () => result.current.photos.removing.confirm())
    expect(result.current.photos.thumbs).toEqual([])
  })

  it('what went wrong with the picker brings the sheet back, saying so', async () => {
    mockPick.mockImplementationOnce(async () => ({ photos: [], trouble: 'The camera is switched off for this app.' }))
    const { result } = await renderHook(() => useMaintenanceRequest())
    await act(async () => result.current.photos.adding.ask())
    await act(async () => result.current.photos.adding.take())
    await waitFor(() => expect(result.current.photos.adding.problem).toBe('The camera is switched off for this app.'))
    expect(result.current.photos.adding.open).toBe(true)
  })

  it('a photo is queued BEHIND its request, addressed by the request’s answer', async () => {
    const { result } = await renderHook(() => useMaintenanceRequest())
    await act(async () => result.current.photos.adding.ask())
    await act(async () => result.current.photos.adding.choose())
    await waitFor(() => expect(result.current.photos.thumbs).toHaveLength(1))
    await fill(result)
    await act(async () => result.current.send())
    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(2))
    expect(mockSubmit.mock.calls[1]![0]).toMatchObject({ endpoint: 'tickets.media', after: 'op-1' })
  })
})

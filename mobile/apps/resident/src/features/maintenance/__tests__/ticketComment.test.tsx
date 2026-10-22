/**
 * resident-app-26's working half, LIVE (M47 §11a–b) — the hook, with its outbox and the server mocked:
 *
 *   - "Assigned vendor": the company and its initials, the technician's line, and the number its button rings;
 *   - the timeline names who in the office did each step, by role — "3 Sep 2026, 8:15 AM · Property Manager" (M98's form);
 *   - "Add a comment" queues `tickets.comment` on this request, in the resident's words, and the box empties;
 *   - an empty box sends nothing; a demonstration session sends nothing and says why, under the box;
 *   - what became of the message: on its way, waiting for signal, sent (at the server's own time), refused in the
 *     server's words — and another request's message is not this one's.
 */

import { act, renderHook } from '@testing-library/react-native'
import type { QueuedOp, Ticket } from '../tickets'
import { commentState, useTicket } from '../useTickets'

const mockNow = new Date('2026-09-04T09:41:00-05:00')
jest.mock('@gs/core', () => ({ isFidelity: false, fidelityScreenId: () => null, now: () => mockNow, useOfflineCache: (_key: string, data: unknown) => data ?? null }))
jest.mock('@gs/core/api', () => ({ isApiError: () => false }))

let mockTickets: Ticket[] = []
jest.mock('@gs/core/api/resident', () => ({
  residentApi: { useTicketsIndexQuery: () => ({ data: { items: mockTickets, next_before: null }, error: undefined, refetch: async () => undefined }) },
}))

const mockSubmit = jest.fn(async (input: Record<string, unknown>) => ({ id: 'op-9', ...input }))
let mockOps: unknown[] = []
jest.mock('@/offline/runtime', () => {
  class DemoWriteRefused extends Error {}
  return { submit: (input: Record<string, unknown>) => mockSubmit(input), drainNow: async () => undefined, outbox: { all: () => mockOps }, DemoWriteRefused }
})

let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
jest.mock('@/fidelity/maintenance', () => ({ statusFixture: null }))
const mockState = { network: { online: true }, outbox: { ops: [] as unknown[] } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)

const MANAGER = { kind: 'staff' as const, name: 'Renae Cross', role: 'Property Manager' }

const request: Ticket = {
  id: 1042, number: '1042', title: 'Gate lighting: Phase 2 visitor parking', category: 'electrical', location: 'Phase 2 visitor parking', description: 'The light by the gate is out.',
  access_permitted: null, preferred_time: null, priority: 'high', status: 'assigned', reported_at: '2026-09-02T18:40:00-05:00', technician_name: 'Owen Grant',
  eta_starts_at: '2026-09-04T14:00:00-05:00', eta_ends_at: '2026-09-04T16:00:00-05:00', resolution: null, closed_at: null, media_count: 0,
  vendor: { name: 'Island Electric Services', phone: '876-555-0191' },
  timeline: [
    { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T18:40:00-05:00', by: { kind: 'resident', name: 'Hassam Ahmed', role: null } },
    { event: 'acknowledged', status: 'acknowledged', note: null, occurred_at: '2026-09-03T08:15:00-05:00', by: MANAGER },
    { event: 'assigned', status: 'assigned', note: null, occurred_at: '2026-09-03T09:00:00-05:00', by: MANAGER },
  ],
}

const op = (over: Partial<QueuedOp>): QueuedOp => ({ id: 'c-1', endpoint: 'tickets.comment', state: 'pending', capturedAt: '2026-09-04T09:00:00-05:00', after: null, params: { ticket: 1042 }, body: { body: 'It is worse at night.' }, error: null, result: null, ...over })

beforeEach(() => {
  mockTickets = [request]
  mockOps = []
  mockRefusal = null
  mockSubmit.mockClear()
})

describe('"Assigned vendor" and who did each step', () => {
  it('the company and its initials, the technician’s line and the number to ring', async () => {
    const { result } = await renderHook(() => useTicket('1042'))
    expect(result.current.vendor).toEqual({ initials: 'IE', name: 'Island Electric Services', line: 'Tech: Owen Grant · today, 2:00–4:00 PM', phone: '876-555-0191' })
  })

  it('no number, no number to ring, and nobody on the job, no card', async () => {
    mockTickets = [{ ...request, vendor: { name: 'Island Electric Services', phone: null } }]
    const { result } = await renderHook(() => useTicket('1042'))
    expect(result.current.vendor?.phone).toBeNull()

    mockTickets = [{ ...request, status: 'submitted', vendor: null, technician_name: null, eta_starts_at: null, eta_ends_at: null, timeline: request.timeline.slice(0, 1) }]
    const fresh = await renderHook(() => useTicket('1042'))
    expect(fresh.result.current.vendor).toBeNull()
  })

  it('"Under review" (M98; the board\'s "Acknowledged") names who in the office took it up, by role, and on no other step', async () => {
    const { result } = await renderHook(() => useTicket('1042'))
    const line = (key: string) => result.current.steps.find((step) => step.key === key)?.line
    expect(result.current.steps.find((step) => step.key === 'acknowledged')?.title).toBe('Under review')
    expect(line('submitted')).toBe('2 Sep 2026, 6:40 PM')
    expect(line('acknowledged')).toBe('3 Sep 2026, 8:15 AM · Property Manager')
    expect(line('assigned')).toBe('3 Sep 2026, 9:00 AM')
  })

  it('no role on the record: the name; an older server that names nobody: the stamp alone', async () => {
    const named = { ...request, timeline: request.timeline.map((entry) => (entry.event === 'acknowledged' ? { ...entry, by: { kind: 'staff' as const, name: 'Renae Cross', role: null } } : entry)) }
    mockTickets = [named]
    const first = await renderHook(() => useTicket('1042'))
    expect(first.result.current.steps.find((step) => step.key === 'acknowledged')?.line).toBe('3 Sep 2026, 8:15 AM · Renae Cross')

    mockTickets = [{ ...request, timeline: request.timeline.map(({ by: _by, ...entry }) => entry) }]
    const older = await renderHook(() => useTicket('1042'))
    expect(older.result.current.steps.find((step) => step.key === 'acknowledged')?.line).toBe('3 Sep 2026, 8:15 AM')
  })
})

describe('"Add a comment" sends', () => {
  it('queued as tickets.comment on this request, in the resident’s words, and the box empties', async () => {
    const { result } = await renderHook(() => useTicket('1042'))
    await act(async () => result.current.comment.setText('  It is worse at night.  '))
    await act(async () => result.current.comment.send())
    expect(mockSubmit).toHaveBeenCalledTimes(1)
    expect(mockSubmit).toHaveBeenCalledWith({ endpoint: 'tickets.comment', params: { ticket: 1042 }, body: { body: 'It is worse at night.' }, label: 'Comment · Request #1042', kind: 'ticket' })
    expect(result.current.comment.text).toBe('')
  })

  it('an empty box sends nothing', async () => {
    const { result } = await renderHook(() => useTicket('1042'))
    await act(async () => result.current.comment.setText('   '))
    await act(async () => result.current.comment.send())
    expect(mockSubmit).not.toHaveBeenCalled()
  })

  it('a demonstration session sends nothing and says why, under the box, and typing again clears it', async () => {
    mockRefusal = 'This is a demonstration account: nothing you do here is sent.'
    const { result } = await renderHook(() => useTicket('1042'))
    await act(async () => result.current.comment.setText('Hello'))
    await act(async () => result.current.comment.send())
    expect(mockSubmit).not.toHaveBeenCalled()
    expect(result.current.comment.state).toEqual({ kind: 'refused', line: mockRefusal })
    expect(result.current.comment.text).toBe('Hello')
    await act(async () => result.current.comment.setText('Hello!'))
    expect(result.current.comment.state).toBeNull()
  })

  it('the box stops at the server’s 500 characters', async () => {
    const { result } = await renderHook(() => useTicket('1042'))
    await act(async () => result.current.comment.setText('x'.repeat(600)))
    expect(result.current.comment.text).toHaveLength(500)
  })
})

describe('"Messages": one thread, both ends (M54 §2.2)', () => {
  const RESIDENT = { kind: 'resident' as const, name: 'Hassam Ahmed', role: null }
  const said = (event: string, note: string | null, at: string, by?: typeof MANAGER | typeof RESIDENT | null) => ({ event, status: null, note, occurred_at: at, ...(by === undefined ? {} : { by }) })

  it('the household\'s comment and the office\'s reply, oldest first, each with who and when; a step that is not a note is not a message', async () => {
    mockTickets = [{ ...request, timeline: [...request.timeline, said('note', 'It is worse at night.', '2026-09-04T11:20:00-05:00', RESIDENT), said('note', 'The technician comes tomorrow at 9.', '2026-09-04T12:05:00-05:00', MANAGER)] }]
    const { result } = await renderHook(() => useTicket('1042'))
    expect(result.current.messages.map(({ from, office, text, when }) => ({ from, office, text, when }))).toEqual([
      { from: 'Hassam Ahmed', office: false, text: 'It is worse at night.', when: '4 Sep 2026, 11:20 AM' },
      { from: 'Property Manager', office: true, text: 'The technician comes tomorrow at 9.', when: '4 Sep 2026, 12:05 PM' },
    ])
  })

  it('nobody has written: no thread at all; a note the server names nobody on is the office\'s', async () => {
    const quiet = await renderHook(() => useTicket('1042'))
    expect(quiet.result.current.messages).toEqual([])

    mockTickets = [{ ...request, timeline: [...request.timeline, said('note', 'Visit time changed: Sep 5, 9:00 AM', '2026-09-04T13:00:00-05:00', null)] }]
    const { result } = await renderHook(() => useTicket('1042'))
    expect(result.current.messages.map((m) => [m.from, m.office])).toEqual([['Estate office', true]])
  })

  it('a message sent from this phone is in the thread from the server\'s answer, before the list is fetched again', async () => {
    const answer = { ...request, timeline: [...request.timeline, said('note', 'It is worse at night.', '2026-09-04T11:20:00-05:00', RESIDENT)] }
    // As the outbox holds it: a ticket act, sent and answered.
    mockOps = [{ ...op({ state: 'confirmed', result: answer }), kind: 'ticket' }]
    const { result } = await renderHook(() => useTicket('1042'))
    expect(result.current.messages.map((m) => m.text)).toEqual(['It is worse at night.'])
    // The rest of the request is the same request: its stages are still drawn from it.
    expect(result.current.steps.find((step) => step.key === 'acknowledged')?.line).toBe('3 Sep 2026, 8:15 AM · Property Manager')
  })
})

describe('what became of the message, said under the box', () => {
  it('on its way, or waiting for signal', () => {
    expect(commentState('1042', [], true)).toBeNull()
    expect(commentState('1042', [op({})], true)).toEqual({ kind: 'sending', line: 'Sending…' })
    expect(commentState('1042', [op({ state: 'syncing' })], false)).toEqual({ kind: 'waiting', line: 'Saved on this phone: it goes to the estate office when you are back online.' })
  })

  it('sent: at the time the server put on the note, else when it was written', () => {
    const answer = { ...request, timeline: [...request.timeline, { event: 'note', status: 'assigned', note: 'It is worse at night.', occurred_at: '2026-09-04T11:20:00-05:00', by: { kind: 'resident' as const, name: 'Hassam Ahmed', role: null } }] }
    expect(commentState('1042', [op({ state: 'confirmed', result: answer })], true)).toEqual({ kind: 'sent', line: 'Sent to the estate office · 11:20 AM' })
    expect(commentState('1042', [op({ state: 'confirmed' })], true)).toEqual({ kind: 'sent', line: 'Sent to the estate office · 9:00 AM' })
  })

  it('refused, in the server’s words', () => {
    expect(commentState('1042', [op({ state: 'refused', error: { message: 'Write your message first.' } })], true)).toEqual({ kind: 'refused', line: 'Write your message first.' })
  })

  it('the LAST message on THIS request, not another request’s, and not a request being filed', () => {
    expect(commentState('1042', [op({ params: { ticket: 7 } })], true)).toBeNull()
    expect(commentState('1042', [op({ endpoint: 'tickets.store' })], true)).toBeNull()
    expect(commentState('1042', [op({ state: 'refused', error: { message: 'No.' } }), op({ id: 'c-2', state: 'confirmed' })], true)?.kind).toBe('sent')
  })
})

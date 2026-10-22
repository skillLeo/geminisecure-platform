/**
 * Every other record on the resident's phone, from the socket (M11 §2, D-175): the Treasurer records a payment and
 * the open Dues screen and Home's balance ask again; a ticket, a notice, a meeting or an election that changes does
 * the same for its screens — and every type registered is one the server sends. Never a vote.
 */

const mockKind = jest.fn()
const mockRecord = jest.fn()
jest.mock('@gs/core/live', () => ({ invalidateKind: (kind: string) => mockKind(kind), invalidateRecord: (kind: string, id: string) => mockRecord(kind, id) }))

import { LIVE_TYPES } from '@gs/core/api'
import { RECORD_KINDS, recordHandlers } from '../handlers/records'

const env = (t: string) => ({ v: 1, s: 'e' as const, ep: '01J9ZC3K', q: 1, p: null, t, e: t.split('.')[0], i: '12', m: 'ref' as const, o: null, at: '2026-10-03T12:00:00Z', sim: false })
const ctx = { channel: 'household.pp.4', via: 'socket' as const, own: false }

beforeEach(() => mockKind.mockClear())

it('a payment recorded in the console moves the Dues screen and Home\'s balance (the client\'s named case)', async () => {
  for (const h of recordHandlers['dues.changed'] ?? []) await h(env('dues.changed') as never, ctx)
  expect(mockKind.mock.calls.map((c) => c[0])).toEqual(['dues', 'payments'])
})

it.each(Object.entries(RECORD_KINDS))('%s changed or removed asks again for %p', async (entity, kinds) => {
  for (const verb of ['changed', 'removed']) {
    mockKind.mockClear()
    for (const h of recordHandlers[`${entity}.${verb}`] ?? []) await h(env(`${entity}.${verb}`) as never, ctx)
    expect(mockKind.mock.calls.map((c) => c[0])).toEqual(kinds)
  }
})

it('an answer on Live chat reads the conversation again, and nothing else the household shows (M50 §4.3)', async () => {
  for (const verb of ['changed', 'removed']) {
    mockKind.mockClear()
    mockRecord.mockClear()
    for (const h of recordHandlers[`chat.${verb}`] ?? []) await h(env(`chat.${verb}`) as never, { channel: 'resident.pp.9', via: 'socket', own: false })
    expect(mockRecord.mock.calls).toEqual([['household', 'support.chat.show']])
    expect(mockKind).not.toHaveBeenCalled()
  }
})

it('registers only types the server sends (the handler-type contract), and nothing about a vote', () => {
  for (const type of Object.keys(recordHandlers)) expect(LIVE_TYPES).toContain(type)
  expect(Object.keys(recordHandlers).some((t) => /ballot|vote|turnout/.test(t))).toBe(false)
})

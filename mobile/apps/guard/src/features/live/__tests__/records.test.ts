/**
 * Every other record on the guard's phone, from the socket (M11 §2, D-175): a `{entity}.changed` or `.removed`
 * asks again for every read of that kind a screen shows — and every type registered is one the server sends.
 */

const mockKind = jest.fn()
jest.mock('@gs/core/live', () => ({ invalidateKind: (kind: string) => mockKind(kind) }))

import { LIVE_TYPES } from '@gs/core/api'
import { RECORD_KINDS, recordHandlers } from '../handlers/records'

const env = (t: string) => ({ v: 1, s: 'c' as const, ep: '01J9ZC3K', q: 1, p: null, t, e: t.split('.')[0], i: '7', m: 'ref' as const, o: null, at: '2026-10-03T12:00:00Z', sim: false })
const ctx = { channel: 'guard.pp.5', via: 'socket' as const, own: false }

beforeEach(() => mockKind.mockClear())

it.each(Object.entries(RECORD_KINDS))('%s changed or removed asks again for %p', async (entity, kinds) => {
  for (const verb of ['changed', 'removed']) {
    mockKind.mockClear()
    for (const h of recordHandlers[`${entity}.${verb}`] ?? []) await h(env(`${entity}.${verb}`) as never, ctx)
    expect(mockKind.mock.calls.map((c) => c[0])).toEqual(kinds)
  }
})

it('registers only types the server sends (the handler-type contract)', () => {
  for (const type of Object.keys(recordHandlers)) expect(LIVE_TYPES).toContain(type)
})

it('covers what the guard\'s screens show that changes elsewhere: reports, shifts, messages, orders, requests, payslips', () => {
  expect(Object.keys(RECORD_KINDS).sort()).toEqual(['incident', 'message', 'orders', 'payroll', 'request', 'shift', 'ticket'])
})

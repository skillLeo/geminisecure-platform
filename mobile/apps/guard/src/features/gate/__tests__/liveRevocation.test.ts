/**
 * A PASS REVOKED BY THE LIVE CHANNEL WHILE A PULL IS OUT IS NOT WRITTEN AWAY BY THE PULL (M11 Part C, DESIGN §5.5, C6).
 *
 * `gateKnowledge.refresh` read the record, awaited the server, and wrote the record it had read plus the pull's
 * answer. A cancellation the socket delivered in between — `learnRevoked` — was overwritten, and the next scan of
 * the cancelled pass would have admitted the visitor. The refresh now merges into the record as it is when the
 * answer lands.
 */
jest.mock('@gs/core', () => jest.requireActual('@gs/core/lib'))

let resolvePull: (value: unknown) => void = () => undefined
const mockDispatch = jest.fn((action: { kind: string }) => {
  if (action.kind === 'keys') return Promise.resolve({ data: { keys: [{ version: 1, public_key: 'k1' }] } })
  return new Promise((resolve) => { resolvePull = resolve })
})

jest.mock('@/store', () => ({ store: { getState: () => ({}), dispatch: (action: { kind: string }) => mockDispatch(action) } }))
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    endpoints: {
      passesKeys: { initiate: () => ({ kind: 'keys' }) },
      syncPull: { initiate: () => ({ kind: 'pull' }) },
    },
  },
}))

import { gateKnowledge } from '../knowledge'

beforeEach(() => {
  gateKnowledge.forget()
  mockDispatch.mockClear()
})

it('a cancellation learnt from the socket during a refresh is still refused after the refresh lands', async () => {
  const refreshing = gateKnowledge.refresh('7')

  // The socket: the household cancelled P-LIVE while the pull was on its way.
  gateKnowledge.learnRevoked('P-LIVE', 'cancelled')

  resolvePull({ data: { passes: { keys: [], revoked: [{ pass_id: 'P-PULLED', status: 'used' }] }, next_since: '2026-10-02T14:00:00Z' } })
  await refreshing

  const revoked = gateKnowledge.get().revoked.map((r) => r.pass_id).sort()
  expect(revoked).toEqual(['P-LIVE', 'P-PULLED'])
})

it('learnRevoked ignores an empty id, and a pass used elsewhere reads as used', () => {
  gateKnowledge.learnRevoked('', 'cancelled')
  gateKnowledge.learnRevoked('P-9', 'used')
  const revoked = gateKnowledge.get().revoked
  expect(revoked.find((r) => r.pass_id === 'P-9')).toEqual({ pass_id: 'P-9', status: 'used' })
  expect(revoked.some((r) => r.pass_id === '')).toBe(false)
})

/**
 * B056 — THE PULL REPEATS ITSELF ON PURPOSE, AND THE PHONE KEEPS ONE OF EACH.
 *
 * `sync.pull`'s cursor used to be the second the pull started, so a panic or a pass cancellation written later in
 * that same second was excluded by every pull after it: a guard with no push never saw the panic, and the offline
 * verifier went on admitting the cancelled pass. The server now sends the cursor ten seconds early, so the last ten
 * seconds come again with every pull. What the phone keeps from a pull must stay one row per record.
 */
// The package root loads the UI kit (and Reanimated with it); these merges need only the storage and the clock.
jest.mock('@gs/core', () => jest.requireActual('@gs/core/lib'))
jest.mock('@/store', () => ({ store: { getState: () => ({}), dispatch: jest.fn() } }))
jest.mock('@gs/core/api/guard', () => ({ guardApi: { endpoints: {} } }))

import { mergeRevoked } from '../knowledge'
import { mergeKept, type KeptAlert } from '@/features/sitealert/siteAlert'

const AT = new Date('2026-09-24T15:00:00.000Z')
const alert = (id: string, over: Partial<KeptAlert> = {}): KeptAlert => ({ id, kind: 'panic', where: 'Lot 47', raisedAt: '2026-09-24T14:59:55Z', drill: null, answer: null, answeredAt: null, ...over })

it('a cancelled pass arriving again in the overlap is one revoked pass, not two', () => {
  const first = mergeRevoked([], [{ pass_id: 'P-1', status: 'cancelled' }])
  const again = mergeRevoked(first, [{ pass_id: 'P-1', status: 'cancelled' }, { pass_id: 'P-2', status: 'used' }])
  expect(again).toEqual([{ pass_id: 'P-1', status: 'cancelled' }, { pass_id: 'P-2', status: 'used' }])
})

it('a pass this handset admitted stays refused when the overlap brings it back as cancelled', () => {
  const merged = mergeRevoked([{ pass_id: 'P-3', status: 'used' }], [{ pass_id: 'P-3', status: 'cancelled' }])
  expect(merged).toHaveLength(1)
  expect(merged[0]?.pass_id).toBe('P-3')
})

it('a site alert arriving again is one alert on the phone, and an answer already given is not lost to the repeat', () => {
  // What the guard's own "On my way" answered with — the alert as the server then stood.
  const view = { id: 51, status: 'responding' } as unknown as NonNullable<KeptAlert['answer']>
  const answered: KeptAlert[] = mergeKept([], [alert('51')], AT).map((r) => ({ ...r, answer: view, answeredAt: '2026-09-24T14:59:58Z' }))
  const again = mergeKept(answered, [alert('51'), alert('52')], AT)
  expect(again.map((r) => r.id).sort()).toEqual(['51', '52'])
  expect(again.find((r) => r.id === '51')?.answer).toBe(view)
})

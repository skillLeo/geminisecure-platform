/**
 * The resident's own panic, from the socket (M11 Part C, DESIGN §2.4 A, §8.4): every step refetches the one
 * alert, so the panic timeline moves while it is open — acknowledged, dispatched, resolved — without a touch.
 */

const mockInvalidate = jest.fn()
jest.mock('@gs/core/live', () => ({ invalidateRecord: (cap: string, id: string) => mockInvalidate(cap, id) }))

import { liveHandlers } from '../handlers'
import { RECORD_KINDS } from '../handlers/records'

const env = (t: string, i: string) => ({ v: 1, s: 'c' as const, ep: '01J9ZC3K', q: 1, p: null, t, e: 'alert', i, m: 'ref' as const, o: null, at: '2026-10-02T14:03:11Z', sim: false })

beforeEach(() => mockInvalidate.mockClear())

it.each(['alert.raised', 'alert.reach', 'alert.acknowledged', 'alert.dispatched', 'alert.en_route', 'alert.on_scene', 'alert.resolved', 'alert.false_alarm'])('%s refetches the panic screen\'s alert', async (type) => {
  for (const h of liveHandlers()['alert.*'] ?? []) await h(env(type, '55') as never, { channel: 'resident.pp.9', via: 'socket', own: false })
  expect(mockInvalidate).toHaveBeenCalledWith('alerts', '55')
})

it('every group is registered: alerts, the household\'s visitors, and every other record the phone shows', () => {
  // …the other records' `.changed` / `.removed` (M11 §2, D-175): handlers/records.ts.
  const records = Object.keys(RECORD_KINDS).flatMap((entity) => [`${entity}.changed`, `${entity}.removed`])
  // …and Live chat with Gemini Security (M50 §4.3), which reads only the conversation again.
  expect(Object.keys(liveHandlers()).sort()).toEqual(['alert.*', 'approval.*', 'pass.*', ...records, 'chat.changed', 'chat.removed'].sort())
})

it('a pass issued, changed, cancelled or admitted anywhere reads the Visitors list again; a walk-up reads the household again', async () => {
  const all = liveHandlers()
  for (const h of all['pass.*'] ?? []) await h({ ...env('pass.admitted', '12'), e: 'pass' } as never, { channel: 'household.pp.4', via: 'socket', own: false })
  expect(mockInvalidate).toHaveBeenCalledWith('passes', 'LIST')
  for (const h of all['approval.*'] ?? []) await h({ ...env('approval.requested', '3'), e: 'approval' } as never, { channel: 'household.pp.4', via: 'socket', own: false })
  expect(mockInvalidate).toHaveBeenCalledWith('household', 'me.household')
})

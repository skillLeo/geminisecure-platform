/**
 * "Message dispatch…" (board resident-app-06; M50 §4.2 #2): the live channel with the control room, as the panic
 * screen draws it — the server's thread and what is still on its way, one list, and under each of the resident's own
 * exactly what is true about it.
 */

import type { AlertMessage } from '@gs/core/safety'
import { MESSAGE_ENDPOINT, STATE_WORDS, belongsTo, replyFrom, threadOf } from '../dispatchChannel'

const mine = (id: number, key: string, seen: string | null = null): AlertMessage => ({ id, from: 'you', name: null, body: `mine ${id}`, at: '2026-10-08T03:00:00Z', seen_at: seen, client_key: key })
const theirs = (id: number, name: string | null = 'Kemar'): AlertMessage => ({ id, from: 'dispatch', name, body: `reply ${id}`, at: '2026-10-08T03:01:00Z', seen_at: null, client_key: null })

type TestOp = Parameters<typeof threadOf>[1][number]
const op = (id: string, over: Partial<TestOp> = {}): TestOp => ({
  id,
  endpoint: MESSAGE_ENDPOINT as TestOp['endpoint'],
  params: { alert: 7 },
  body: { body: `queued ${id}`, client_key: `key-${id}` },
  state: 'pending',
  error: null,
  ...over,
})

const ALERT = { alertId: '7', alertOp: 'op-alert' }

describe('the thread', () => {
  it('is the server\'s messages in order, the resident\'s own saying Sent until the control room is shown them', () => {
    const items = threadOf([mine(1, 'k1', '2026-10-08T03:00:05Z'), theirs(2), mine(3, 'k3')], [], ALERT, true)
    expect(items.map((i) => [i.from, i.body, i.state])).toEqual([
      ['you', 'mine 1', 'delivered'],
      ['dispatch', 'reply 2', null],
      ['you', 'mine 3', 'sent'],
    ])
  })

  it('adds what is still on its way, and never twice once the server has it', () => {
    const items = threadOf([mine(1, 'key-a')], [op('a'), op('b')], ALERT, true)
    expect(items.map((i) => [i.body, i.state])).toEqual([
      ['mine 1', 'sent'],
      ['queued b', 'sending'],
    ])
  })

  it('says a message with no signal is waiting, and goes by itself', () => {
    const [item] = threadOf([], [op('a')], ALERT, false)
    expect(item?.state).toBe('waiting')
    expect(STATE_WORDS.waiting).toBe('Waiting for signal: it sends by itself')
  })

  it('says a refusal in the server\'s words: offering another try, except on a closed alert', () => {
    const items = threadOf([], [
      op('a', { state: 'refused', error: { status: 422, code: 'message_empty', message: 'Write a message first.' } }),
      op('b', { state: 'refused', error: { status: 409, code: 'alert_closed', message: 'This alert is closed… call 119.' } }),
    ], ALERT, true)
    expect(items.map((i) => [i.state, i.note, i.opId])).toEqual([
      ['failed', 'Write a message first.', 'a'],
      ['failed', 'This alert is closed… call 119.', null],
    ])
  })
})

describe('which alert a queued message is for', () => {
  it('is its id: or, written while the alert was held on the phone, the alert\'s own act', () => {
    expect(belongsTo(op('a'), ALERT)).toBe(true)
    expect(belongsTo(op('a', { params: { alert: 8 } }), ALERT)).toBe(false)
    expect(belongsTo(op('a', { params: { alert: { $from: 'op-alert', key: 'id' } } }), { alertId: null, alertOp: 'op-alert' })).toBe(true)
    expect(belongsTo(op('a', { params: { alert: { $from: 'op-other', key: 'id' } } }), { alertId: null, alertOp: 'op-alert' })).toBe(false)
    expect(belongsTo(op('a', { endpoint: 'duress.store' as TestOp['endpoint'] }), ALERT)).toBe(false)
  })
})

it('names the control room, and the dispatcher when it gave a name', () => {
  expect(replyFrom('Kemar')).toBe('Kemar (dispatch):')
  expect(replyFrom(null)).toBe('Dispatch:')
})

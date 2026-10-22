import { bubbles, dayHeading, idsOfMark, isUnread, markedIds, settledMarks, summarise, threadOf, toMarkRead, validateBody, whenLabel, type Message, type QueuedMessage, type ReadMark } from '../messages'

// 2026-09-04 09:41 in Jamaica (UTC−5).
const AT = new Date('2026-09-04T14:41:00Z')

const msg = (id: number, direction: Message['direction'], body: string, sent_at: string, read_at: string | null = null): Message => ({ id, direction, from: direction === 'inbound' ? 'you' : 'dispatch', body, sent_at, read_at })
const held = (id: string, body: string, capturedAt: string, state: QueuedMessage['state'] = 'pending', error: string | null = null, serverId: number | null = null): QueuedMessage => ({ id, body, capturedAt, state, error, serverId })

describe('the two conversations the server has', () => {
  it('a broadcast is its own thread; everything else is the line to dispatch', () => {
    expect(threadOf(msg(1, 'broadcast', 'x', AT.toISOString()))).toBe('broadcast')
    expect(threadOf(msg(2, 'outbound', 'x', AT.toISOString()))).toBe('dispatch')
    expect(threadOf(msg(3, 'inbound', 'x', AT.toISOString()))).toBe('dispatch')
  })
})

describe('when a message was sent, in the site’s zone', () => {
  it('today is a clock time', () => expect(whenLabel('2026-09-04T13:15:00Z', AT)).toBe('8:15 AM'))
  it('yesterday is a word', () => expect(whenLabel('2026-09-03T22:00:00Z', AT)).toBe('Yesterday'))
  it('this week is a weekday', () => expect(whenLabel('2026-08-31T15:00:00Z', AT)).toBe('Mon'))
  it('older is a date', () => expect(whenLabel('2026-08-20T15:00:00Z', AT)).toBe('20 Aug 2026'))
  it('03:00 UTC is still the evening before in Kingston', () => {
    expect(whenLabel('2026-09-04T03:00:00Z', AT)).toBe('Yesterday')
    expect(dayHeading('2026-09-04T03:00:00Z', AT)).toBe('Yesterday')
    expect(dayHeading('2026-09-04T06:00:00Z', AT)).toBe('Today')
  })
})

describe('unread: the server decides, per guard and per phone', () => {
  const b = msg(1, 'broadcast', 'New orders', '2026-09-04T13:00:00Z')
  const d = msg(2, 'outbound', 'Call in', '2026-09-04T13:30:00Z')
  const mark = (id: string, ids: number[], state: ReadMark['state'] = 'pending'): ReadMark => ({ id, ids: ids.map(String), state })

  it("the guard's own messages are never unread", () => expect(isUnread(msg(3, 'inbound', 'ok', '2026-09-04T13:31:00Z'))).toBe(false))
  it('read_at is THIS guard\'s read on THIS phone: set is read, null is unread: nothing on the phone overrides it', () => {
    expect(isUnread(b)).toBe(true)
    expect(isUnread({ ...b, read_at: '2026-09-04T13:05:00Z' })).toBe(false)
  })
  it('there is no local read marker any more', () => {
    const exported = Object.keys(jest.requireActual('../messages') as Record<string, unknown>)
    expect(exported).not.toContain('markSeen')
  })
  it('a mark-read waiting in the sync queue clears the badge: a REFUSED one does not: the server said no', () => {
    expect(isUnread(b, markedIds([mark('k1', [1])]))).toBe(false)
    expect(isUnread(b, markedIds([mark('k1', [1], 'syncing')]))).toBe(false)
    expect(isUnread(b, markedIds([mark('k1', [1], 'confirmed')]))).toBe(false)
    expect(isUnread(b, markedIds([mark('k1', [1], 'refused')]))).toBe(true)
    expect(isUnread(d, markedIds([mark('k1', [1])]))).toBe(true) // the other conversation is untouched
  })
  it('showing a conversation asks about ITS unread messages, once each, never the guard\'s own, never one already asked about', () => {
    const items = [b, d, msg(3, 'inbound', 'ok', '2026-09-04T13:31:00Z'), msg(4, 'broadcast', 'Old', '2026-09-03T13:00:00Z', '2026-09-03T14:00:00Z'), msg(5, 'broadcast', 'Later', '2026-09-04T14:00:00Z')]
    expect(toMarkRead(items, 'broadcast', [])).toEqual([1, 5])
    expect(toMarkRead(items, 'dispatch', [])).toEqual([2])
    expect(toMarkRead(items, 'broadcast', [mark('k1', [1])])).toEqual([5])
    // A refusal is an answer: it is not asked again every thirty seconds.
    expect(toMarkRead(items, 'broadcast', [mark('k1', [1, 5], 'refused')])).toEqual([])
  })
  it('never asks about more than the endpoint takes at once', () => {
    const many = Array.from({ length: 130 }, (_, i) => msg(i + 1, 'broadcast', 'x', '2026-09-04T13:00:00Z'))
    expect(toMarkRead(many, 'broadcast', [])).toHaveLength(100)
  })
  it('an answered mark-read leaves the sync queue once the list shows its messages read, and not before', () => {
    const confirmed = mark('k1', [1], 'confirmed')
    expect(settledMarks([b], [confirmed])).toEqual([])
    expect(settledMarks([{ ...b, read_at: '2026-09-04T13:05:00Z' }], [confirmed])).toEqual(['k1'])
    expect(settledMarks([], [confirmed])).toEqual(['k1']) // the message is no longer listed at all
    expect(settledMarks([{ ...b, read_at: '2026-09-04T13:05:00Z' }], [mark('k2', [1], 'pending'), mark('k3', [1], 'refused')])).toEqual([])
  })
  it('reads the ids out of a queued body, and nothing else', () => {
    expect(idsOfMark({ ids: [4, '5', null, { x: 1 }] })).toEqual(['4', '5'])
    expect(idsOfMark(null)).toEqual([])
    expect(idsOfMark({ ids: 'nope' })).toEqual([])
  })
})

describe('the inbox', () => {
  const items = [
    msg(1, 'broadcast', 'New standing orders posted for Main Gate', '2026-09-04T13:15:00Z'),
    msg(2, 'outbound', 'Contractor at 10', '2026-09-04T14:00:00Z'),
    msg(3, 'inbound', 'Copy that', '2026-09-04T14:15:00Z'),
  ]

  it('previews the newest of each conversation and counts what is unread', () => {
    const t = summarise(items, [], AT)
    expect(t.broadcast).toEqual({ kind: 'broadcast', preview: 'Dispatch: New standing orders posted for Main Gate', when: '8:15 AM', unread: 1 })
    expect(t.dispatch).toEqual({ kind: 'dispatch', preview: 'You: Copy that', when: '9:15 AM', unread: 1 })
  })
  it('the count follows the server, less what this phone is waiting to tell it', () => {
    expect(summarise(items, [], AT, [{ id: 'k1', ids: ['1'], state: 'pending' }]).broadcast.unread).toBe(0)
    expect(summarise(items, [], AT, [{ id: 'k1', ids: ['1'], state: 'refused' }]).broadcast.unread).toBe(1)
    expect(summarise(items.map((m) => ({ ...m, read_at: '2026-09-04T14:20:00Z' })), [], AT).dispatch.unread).toBe(0)
  })
  it('a message still on the phone is previewed as WAITING, never as sent', () => {
    const t = summarise(items, [held('q1', 'Grey pickup is gone', '2026-09-04T14:30:00Z')], AT)
    expect(t.dispatch.preview).toBe('You: Grey pickup is gone')
    expect(t.dispatch.when).toBe('Waiting to send')
  })
  it('a confirmed or refused op is not "waiting"', () => {
    const t = summarise(items, [held('q1', 'x', '2026-09-04T14:30:00Z', 'confirmed'), held('q2', 'y', '2026-09-04T14:31:00Z', 'refused', 'Too long')], AT)
    expect(t.dispatch.when).toBe('9:15 AM')
  })
  it('nothing at all is said plainly, not invented', () => {
    const t = summarise([], [], AT)
    expect(t.broadcast).toEqual({ kind: 'broadcast', preview: null, when: null, unread: 0 })
    expect(t.dispatch.preview).toBeNull()
  })
})

describe('a thread', () => {
  const items = [
    msg(3, 'inbound', 'Copy that', '2026-09-04T14:15:00Z'),
    msg(2, 'outbound', 'Contractor at 10', '2026-09-04T14:00:00Z'),
    msg(1, 'broadcast', 'New orders', '2026-09-04T13:15:00Z'),
    msg(0, 'outbound', 'Night note', '2026-09-03T23:00:00Z'),
  ]

  it('is oldest first, grouped by Jamaica day, with only its own messages', () => {
    const g = bubbles(items, [], 'dispatch')
    expect(g.map((x) => x.rows.map((r) => r.body))).toEqual([['Night note'], ['Contractor at 10', 'Copy that']])
    expect(bubbles(items, [], 'broadcast')[0]!.rows.map((r) => r.body)).toEqual(['New orders'])
  })
  it('what is still on the phone is last and says "Waiting to send"', () => {
    const rows = bubbles(items, [held('q1', 'Pickup gone', '2026-09-04T14:30:00Z', 'syncing')], 'dispatch').at(-1)!.rows
    expect(rows.at(-1)).toMatchObject({ mine: true, body: 'Pickup gone', state: 'waiting', note: 'Waiting to send' })
  })
  it('a refusal carries the server\'s sentence; a confirmed op is dropped (the server\'s copy is in the list)', () => {
    const rows = bubbles([], [held('q1', 'a', '2026-09-04T14:30:00Z', 'refused', 'The message is too long.'), held('q2', 'b', '2026-09-04T14:31:00Z', 'confirmed')], 'dispatch')[0]!.rows
    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({ state: 'refused', note: 'The message is too long.' })
  })
  it('a confirmed message is shown ONCE: from the phone until the list carries it, then from the list', () => {
    const op = held('q1', 'Copy that', '2026-09-04T14:15:00Z', 'confirmed', null, 3)
    const before = bubbles([], [op], 'dispatch')[0]!.rows
    expect(before).toHaveLength(1)
    expect(before[0]).toMatchObject({ state: 'sent', note: null })
    const after = bubbles(items, [op], 'dispatch').flatMap((g) => g.rows).filter((r) => r.body === 'Copy that')
    expect(after).toHaveLength(1)
    expect(after[0]!.id).toBe('m3')
  })
  it('queued messages never appear in the broadcast thread', () => {
    expect(bubbles([], [held('q1', 'a', '2026-09-04T14:30:00Z')], 'broadcast')).toEqual([])
  })
})

describe('what may be sent', () => {
  it('not nothing, not more than the server takes', () => {
    expect(validateBody('   ')).toBe('Write a message first.')
    expect(validateBody('x'.repeat(501))).toMatch(/under 500/)
    expect(validateBody(' ok ')).toBeNull()
  })
})

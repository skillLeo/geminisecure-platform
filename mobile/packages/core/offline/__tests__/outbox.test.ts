/**
 * The outbox under chaos (M2 P4 gate, invariants 7, 9, 12): kill connectivity mid-action,
 * mid-sync and mid-drain — nothing is lost, nothing is sent twice, every state is honest.
 */
import type { ApiError } from '../../api/errors'
import { createAsyncStorageEngine } from '../../lib/storage'
import { backoffMs, canQueue, createOutbox, NOT_SENDABLE, NOT_SENDABLE_SENTENCE, OfflineNotAllowedError, OUTBOX_LIMITS, type OutboxOp, type Sender } from '../outbox'

function memoryBackend() {
  const disk = new Map<string, string>()
  return {
    disk,
    getAllKeys: async () => [...disk.keys()],
    multiGet: async (keys: readonly string[]) => keys.map((k) => [k, disk.get(k) ?? null] as [string, string | null]),
    setItem: async (k: string, v: string) => void disk.set(k, v),
    removeItem: async (k: string) => void disk.delete(k),
    multiRemove: async (keys: readonly string[]) => keys.forEach((k) => disk.delete(k)),
  }
}

const err = (status: ApiError['status'], code: string): { error: ApiError } => ({ error: { status, code, message: `${code}!`, fieldErrors: {}, retryAfter: null } })
const NETWORK = err('network', 'network_unreachable')

/** A fake server that is idempotent the way the real one is: one effect per key, the first answer replayed. */
function fakeServer() {
  const effects: string[] = []
  const answers = new Map<string, unknown>()
  let online = true
  let dropResponseOnce = false
  const send: Sender = async (op, params) => {
    if (!online) return NETWORK
    const key = op.stamp.idempotencyKey
    if (!answers.has(key)) {
      effects.push(`${op.endpoint}:${JSON.stringify(params)}`)
      answers.set(key, { id: effects.length, endpoint: op.endpoint })
    }
    if (dropResponseOnce) { dropResponseOnce = false; return NETWORK } // the act happened; the answer was lost on the way back
    return { data: answers.get(key) }
  }
  return { send, effects, goOffline: () => (online = false), goOnline: () => (online = true), loseNextResponse: () => (dropResponseOnce = true) }
}

const clockIn = { endpoint: 'shifts.clock_in' as const, params: { shift: 812 }, body: { method: 'app' }, label: 'Clock-in · Main Gate', kind: 'clock', owner: 'GS-1041' }

async function freshOutbox(backend = memoryBackend()) {
  const storage = createAsyncStorageEngine(backend)
  await storage.hydrate()
  return { outbox: createOutbox({ storage }), backend, storage }
}

describe('capture', () => {
  it('an act is on disk before enqueue() resolves: with its key and the time it happened', async () => {
    const { outbox, backend } = await freshOutbox()
    const op = await outbox.enqueue(clockIn)
    const onDisk = JSON.parse(backend.disk.get('gs:outbox:v1')!) as OutboxOp[]
    expect(onDisk).toHaveLength(1)
    expect(onDisk[0]).toMatchObject({ id: op.id, state: 'pending', endpoint: 'shifts.clock_in', owner: 'GS-1041' })
    expect(onDisk[0]!.stamp.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/)
    expect(onDisk[0]!.stamp.deviceTime).toMatch(/^\d{4}-\d{2}-\d{2}T/)
  })

  it('invariant 9: a ballot or a payment can never be queued, and says so plainly', async () => {
    const { outbox } = await freshOutbox()
    expect(canQueue('elections.ballot')).toBe(false)
    expect(canQueue('autopay.store')).toBe(false)
    expect(canQueue('invoices.pay_intent')).toBe(false)
    expect(canQueue('gate.search')).toBe(false) // a read is not an act
    expect(canQueue('shifts.clock_in')).toBe(true)
    await expect(outbox.enqueue({ ...clockIn, endpoint: 'elections.ballot' })).rejects.toBeInstanceOf(OfflineNotAllowedError)
    await expect(outbox.enqueue({ ...clockIn, endpoint: 'elections.ballot' })).rejects.toThrow(/only be cast while you are connected/)
    expect(outbox.all()).toHaveLength(0)
  })
})

describe('chaos', () => {
  // THE BUG THAT REACHED A REAL PHONE (2026-09-19). Every session starts by draining an EMPTY queue. That drain
  // had nothing to await, so its "finished" reset ran before its "running" marker was set — the marker stayed
  // set for good, and no act queued afterwards was ever sent: a clock-in sat as "1 queued" with signal, forever.
  it('an empty drain does not wedge the outbox: what is queued AFTER it is still sent', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()

    await outbox.drain(server.send, { force: true }) // session start: nothing to send
    await outbox.drain(server.send, { force: true }) // a reconnect: still nothing
    expect(server.effects).toHaveLength(0)

    const op = await outbox.enqueue(clockIn)
    await outbox.drain(server.send, { force: true })

    expect(server.effects).toEqual(['shifts.clock_in:{"shift":812}'])
    expect(outbox.all().find((o) => o.id === op.id)).toMatchObject({ state: 'confirmed', attempts: 1 })
    expect(outbox.pendingCount()).toBe(0)
  })

  it('a drain that finishes, with work or without, always lets the next one run', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    for (let round = 0; round < 3; round++) {
      await outbox.drain(server.send, { force: true }) // empty
      await outbox.enqueue({ ...clockIn, endpoint: 'patrol.scan', params: { checkpoint: round }, label: `Checkpoint ${round}` })
      await outbox.drain(server.send, { force: true }) // with work
    }
    expect(server.effects).toHaveLength(3)
    expect(outbox.pendingCount()).toBe(0)
  })

  it('connectivity killed mid-action: 20 acts queue offline, then ALL drain, in order, exactly once each', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    server.goOffline()
    for (let i = 0; i < 20; i++) await outbox.enqueue({ ...clockIn, endpoint: 'patrol.scan', params: { checkpoint: i }, label: `Checkpoint ${i}` })
    await outbox.drain(server.send, { force: true })
    expect(outbox.pendingCount()).toBe(20)
    expect(server.effects).toHaveLength(0)

    server.goOnline()
    await outbox.drain(server.send, { force: true })
    expect(outbox.pendingCount()).toBe(0)
    expect(server.effects).toEqual(Array.from({ length: 20 }, (_, i) => `patrol.scan:{"checkpoint":${i}}`))
  })

  it('connectivity killed mid-sync: the drain stops, keeps order, and resumes with the SAME keys: no duplicates', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    for (let i = 0; i < 6; i++) await outbox.enqueue({ ...clockIn, endpoint: 'patrol.scan', params: { checkpoint: i }, label: `Checkpoint ${i}` })
    let sent = 0
    const flaky: Sender = async (op, params) => { if (sent === 3) server.goOffline(); sent++; return server.send(op, params) }
    await outbox.drain(flaky, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['confirmed', 'confirmed', 'confirmed', 'pending', 'pending', 'pending'])

    server.goOnline()
    await outbox.drain(server.send, { force: true })
    expect(outbox.all().every((o) => o.state === 'confirmed')).toBe(true)
    expect(server.effects).toHaveLength(6)
    expect(new Set(server.effects).size).toBe(6)
  })

  it('the response is lost on the way back: the act is resent with the same key and happens ONCE', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    await outbox.enqueue(clockIn)
    server.loseNextResponse()
    await outbox.drain(server.send, { force: true })
    expect(outbox.all()[0]!.state).toBe('pending') // honest: we do not know it landed
    await outbox.drain(server.send, { force: true })
    expect(outbox.all()[0]!.state).toBe('confirmed')
    expect(server.effects).toEqual(['shifts.clock_in:{"shift":812}'])
  })

  it('the app is killed mid-send: on restart the act is pending again, not lost and not "syncing" forever', async () => {
    const backend = memoryBackend()
    const first = await freshOutbox(backend)
    await first.outbox.enqueue(clockIn)
    const never: Sender = () => new Promise(() => undefined) // the process dies while this is in flight
    void first.outbox.drain(never, { force: true })
    await new Promise((r) => setTimeout(r, 20))
    expect((JSON.parse(backend.disk.get('gs:outbox:v1')!) as OutboxOp[])[0]!.state).toBe('syncing')

    const restarted = await freshOutbox(backend)
    expect(restarted.outbox.all()[0]!.state).toBe('pending')
    const server = fakeServer()
    await restarted.outbox.drain(server.send, { force: true })
    expect(restarted.outbox.all()[0]!.state).toBe('confirmed')
    expect(server.effects).toHaveLength(1)
  })

  it('two drains at once are one drain', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    for (let i = 0; i < 5; i++) await outbox.enqueue({ ...clockIn, endpoint: 'patrol.scan', params: { checkpoint: i }, label: `c${i}` })
    await Promise.all([outbox.drain(server.send, { force: true }), outbox.drain(server.send, { force: true }), outbox.drain(server.send, { force: true })])
    expect(server.effects).toHaveLength(5)
  })
})

describe('a refusal is an answer', () => {
  it('a 4xx is recorded with the server\'s sentence, never resent, and does not block the acts behind it', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    let calls = 0
    const send: Sender = async (op, params) => { calls++; return op.label === 'bad' ? err(409, 'orders_changed') : server.send(op, params) }
    await outbox.enqueue({ ...clockIn, label: 'bad', endpoint: 'orders.acknowledge', params: { version: 3 } })
    await outbox.enqueue(clockIn)
    await outbox.drain(send, { force: true })
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['refused', 'confirmed'])
    expect(outbox.all()[0]!.error).toMatchObject({ status: 409, code: 'orders_changed' })
    expect(calls).toBe(2)
  })

  it('"still being handled" and "slow down" are NOT refusals: they are retried with the same key', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    let first = true
    const send: Sender = async (op, params) => { if (first) { first = false; return err(409, 'request_in_progress') } return server.send(op, params) }
    await outbox.enqueue(clockIn)
    await outbox.drain(send, { force: true })
    expect(outbox.all()[0]!.state).toBe('pending')
    await outbox.drain(send, { force: true })
    expect(outbox.all()[0]!.state).toBe('confirmed')
  })
})

describe('order and dependency', () => {
  it('a clock-out waits for its clock-in; media waits for its report and takes the report\'s id', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    const report = await outbox.enqueue({ ...clockIn, endpoint: 'incidents.store', params: {}, body: { detail: 'Grey pickup, no plates, parked by the playground' }, label: 'Incident', kind: 'incident' })
    await outbox.enqueue({ ...clockIn, endpoint: 'requests.store', params: { incident: { $from: report.id, key: 'id' } }, body: null, label: 'Follow-up', kind: 'incident', after: report.id })
    server.goOffline()
    await outbox.drain(server.send, { force: true })
    server.goOnline()
    await outbox.drain(server.send, { force: true })
    expect(server.effects).toEqual(['incidents.store:{}', 'requests.store:{"incident":1}'])
  })

  it('an act whose parent was refused is not sent, and says why', async () => {
    const { outbox } = await freshOutbox()
    const parent = await outbox.enqueue({ ...clockIn, label: 'Clock-in' })
    await outbox.enqueue({ ...clockIn, endpoint: 'shifts.clock_out', label: 'Clock-out', after: parent.id })
    const send: Sender = async () => err(403, 'not_your_shift')
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['refused', 'refused'])
    expect(outbox.all()[1]!.error?.code).toBe('parent_refused')
  })
})

describe('whose queue it is', () => {
  it('a different guard signing in discards the last guard\'s unsent acts; the same guard keeps them', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(clockIn)
    expect(await outbox.adopt('GS-1041')).toBe(0)
    expect(outbox.all()).toHaveLength(1)
    expect(await outbox.adopt('GS-1052')).toBe(1)
    expect(outbox.all()).toHaveLength(0)
  })

  it('a pending act can never be dismissed: only one that has its answer', async () => {
    const { outbox } = await freshOutbox()
    const op = await outbox.enqueue(clockIn)
    await outbox.dismiss(op.id)
    expect(outbox.all()).toHaveLength(1)
  })
})

describe('backoff: a phone in a dead spot does not ask every few seconds (M11 §1.3)', () => {
  const T0 = new Date('2026-09-04T14:41:00Z').getTime()
  const REAL = ['setTimeout', 'setInterval', 'setImmediate', 'clearTimeout', 'clearInterval', 'clearImmediate', 'nextTick', 'queueMicrotask'] as const
  beforeEach(() => jest.useFakeTimers({ now: T0, doNotFake: [...REAL] }))
  afterEach(() => jest.useRealTimers())

  it('grows 4 s, 8 s, 16 s… to five minutes, and starts over after a success', () => {
    expect([0, 1, 2, 3, 4].map(backoffMs)).toEqual([0, 4000, 8000, 16000, 32000])
    expect(backoffMs(30)).toBe(OUTBOX_LIMITS.maxBackoffMs)
  })

  it('an automatic drain inside the backoff asks nothing; a forced one (reconnect, "Try now") goes at once', async () => {
    const { outbox } = await freshOutbox()
    const server = fakeServer()
    let tries = 0
    const counting: Sender = async (op, params) => { tries++; return server.send(op, params) }
    server.goOffline()
    await outbox.enqueue(clockIn)

    await outbox.drain(counting) // 1st failure: backoff 4 s
    await outbox.drain(counting) // inside it: nothing asked
    expect(tries).toBe(1)

    jest.setSystemTime(T0 + 4001)
    await outbox.drain(counting) // 2nd failure: backoff 8 s
    jest.setSystemTime(T0 + 4001 + 7000)
    await outbox.drain(counting) // still inside 8 s
    expect(tries).toBe(2)

    server.goOnline()
    await outbox.drain(counting, { force: true }) // a reconnect: at once
    expect(tries).toBe(3)
    expect(outbox.all()[0]!.state).toBe('confirmed')
  })
})

describe('retention: the queue does not grow for ever', () => {
  const T0 = new Date('2026-09-04T14:41:00Z').getTime()
  const REAL = ['setTimeout', 'setInterval', 'setImmediate', 'clearTimeout', 'clearInterval', 'clearImmediate', 'nextTick', 'queueMicrotask'] as const
  beforeEach(() => jest.useFakeTimers({ now: T0, doNotFake: [...REAL] }))
  afterEach(() => jest.useRealTimers())

  const report = { endpoint: 'incidents.store' as const, params: {}, body: { kind: 'fault' }, label: 'Report', kind: 'incident', owner: 'GS-1041' }
  const photo = (after: string) => ({ endpoint: 'incidents.media' as const, params: { incident: { $from: after, key: 'id' } }, body: { file: { uri: 'file:///x.jpg', name: 'x.jpg', type: 'image/jpeg' } }, label: 'Photo', kind: 'photo', owner: 'GS-1041', after })

  // Found by the maintenance review (2026-09-20): a confirmed report whose photo the server REFUSED was kept for ever,
  // because "a child that is not confirmed" held its parent — and a refused child is never sent again.
  it('a confirmed report whose photo was REFUSED leaves after its day, and takes the refused photo with it', async () => {
    const { outbox } = await freshOutbox()
    const parent = await outbox.enqueue(report)
    await outbox.enqueue(photo(parent.id))
    const send: Sender = async (op) => (op.endpoint === 'incidents.media' ? err(422, 'file_too_large') : { data: { id: 77 } })
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['confirmed', 'refused'])

    jest.setSystemTime(T0 + 23 * 3_600_000)
    await outbox.drain(send, { force: true })
    expect(outbox.all()).toHaveLength(2) // still shown: the refusal can be read for the same day the report is

    jest.setSystemTime(T0 + 25 * 3_600_000)
    await outbox.drain(send, { force: true })
    expect(outbox.all()).toEqual([])
  })

  it('a confirmed report whose photo is still WAITING is kept, however old: the photo needs its id', async () => {
    const { outbox } = await freshOutbox()
    const parent = await outbox.enqueue(report)
    await outbox.enqueue(photo(parent.id))
    const send: Sender = async (op) => (op.endpoint === 'incidents.media' ? NETWORK : { data: { id: 77 } })
    await outbox.drain(send, { force: true })
    jest.setSystemTime(T0 + 72 * 3_600_000)
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['confirmed', 'pending'])
  })

  it('a REFUSED report (no confirmed parent) stays until it is read: it is what the guard wrote', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(report)
    await outbox.drain(async () => err(422, 'validation'))
    jest.setSystemTime(T0 + 30 * 24 * 3_600_000)
    await outbox.drain(async () => err(422, 'validation'))
    expect(outbox.all().map((o) => o.state)).toEqual(['refused'])
  })
})
describe('pacing: a phone with no signal does not hammer', () => {
  const T0 = new Date('2026-09-04T14:41:00Z').getTime()
  const REAL = ['setTimeout', 'setInterval', 'setImmediate', 'clearTimeout', 'clearInterval', 'clearImmediate', 'nextTick', 'queueMicrotask'] as const
  beforeEach(() => jest.useFakeTimers({ now: T0, doNotFake: [...REAL] }))
  afterEach(() => jest.useRealTimers())

  // Seen live by the notices review (2026-09-20): the same queued act was re-sent 4 times in 3 seconds while offline.
  it('after a NETWORK failure the next automatic drain waits; four triggers in a row are ONE attempt', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(clockIn)
    const send = jest.fn<ReturnType<Sender>, Parameters<Sender>>(async () => NETWORK)
    await outbox.drain(send)
    await outbox.drain(send)
    await outbox.drain(send)
    await outbox.drain(send)
    expect(send).toHaveBeenCalledTimes(1)

    jest.setSystemTime(T0 + 5000)
    await outbox.drain(send)
    expect(send).toHaveBeenCalledTimes(2)
  })

  it('a reconnect, or a person pressing "Try now", goes AT ONCE', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(clockIn)
    const server = fakeServer()
    server.goOffline()
    await outbox.drain(server.send)
    server.goOnline()
    await outbox.drain(server.send, { force: true })
    expect(outbox.all()[0]!.state).toBe('confirmed')
  })

  it('a REFUSAL or a success never delays what comes next', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(clockIn)
    await outbox.drain(async () => err(422, 'validation'))
    await outbox.enqueue({ ...clockIn, params: { shift: 813 } })
    const send = jest.fn<ReturnType<Sender>, Parameters<Sender>>(async () => ({ data: { id: 1 } }))
    await outbox.drain(send)
    expect(send).toHaveBeenCalledTimes(1)
  })
})
/*
 * B125. A duress raised with no signal was captured LAST in a strictly first-in-first-out queue: once signal
 * came back it went only after every older act had gone one by one, and never at all while one of them kept
 * failing — a photo timing out on weak 3G, a steady 5xx. The alert is the one act that may not wait.
 */
describe('an alert never waits its turn', () => {
  const scan = (i: number) => ({ ...clockIn, endpoint: 'patrol.scan' as const, params: { checkpoint: i }, body: null, label: `Checkpoint ${i}`, kind: 'patrol' })
  const photo = { endpoint: 'incidents.media' as const, params: { incident: 5 }, body: { file: { uri: 'file:///documents/outbox-media/big.jpg', name: 'big.jpg', type: 'image/jpeg' } }, label: 'Photo', kind: 'photo', owner: 'GS-1041' }
  const duress = { endpoint: 'duress.store' as const, params: {}, body: { mode: 'silent' }, label: 'Duress alert', kind: 'silent', owner: 'GS-1041' }

  it('a duress held behind an act that keeps failing is still sent, and sent first', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(scan(1))
    await outbox.enqueue(photo)
    await outbox.enqueue(scan(2))
    const alert = await outbox.enqueue(duress)
    const calls: string[] = []
    const send: Sender = async (op) => { calls.push(op.endpoint); return op.endpoint === 'incidents.media' ? err('timeout', 'timeout') : { data: { id: calls.length } } }

    for (let i = 0; i < 3; i++) await outbox.drain(send, { force: true })

    expect(outbox.all().find((o) => o.id === alert.id)?.state).toBe('confirmed')
    expect(calls[0]).toBe('duress.store')
    expect(calls.filter((c) => c === 'duress.store')).toHaveLength(1) // once answered it is never sent again
    // …and the photo that keeps failing went to the back of the line after its first failure (26 Sep): the scan
    // after it no longer waits on it. Only the photo is still pending.
    expect(outbox.all().map((o) => [o.label, o.state])).toEqual([['Checkpoint 1', 'confirmed'], ['Photo', 'pending'], ['Checkpoint 2', 'confirmed'], ['Duress alert', 'confirmed']])
  })

  it('a duress raised while a drain is half-way through a long queue goes NEXT, not last', async () => {
    const { outbox } = await freshOutbox()
    for (let i = 0; i < 5; i++) await outbox.enqueue(scan(i))
    const order: string[] = []
    let pressed = false
    const send: Sender = async (op) => {
      order.push(op.label)
      if (!pressed) { pressed = true; await outbox.enqueue(duress) } // the guard is coerced while the first scan is on the wire
      return { data: { id: order.length } }
    }
    await outbox.drain(send, { force: true })
    expect(order).toEqual(['Checkpoint 0', 'Duress alert', 'Checkpoint 1', 'Checkpoint 2', 'Checkpoint 3', 'Checkpoint 4'])
  })

  it('one alert failing does not hold back another alert, nor the queue', async () => {
    const { outbox } = await freshOutbox()
    const first = await outbox.enqueue(duress)
    const second = await outbox.enqueue({ ...duress, body: { mode: 'audible' } })
    await outbox.enqueue(scan(1))
    const send: Sender = async (op) => (op.id === first.id ? err(503, 'server_error') : { data: { id: 1 } })
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['pending', 'confirmed', 'confirmed'])
    expect(outbox.all().find((o) => o.id === second.id)?.state).toBe('confirmed')
  })

  it('offer(): a held alert is sent ALONE, now, even while a drain is stuck on a slow upload', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(photo)
    const sent: string[] = []
    const send: Sender = async (op) => {
      sent.push(op.endpoint)
      return op.endpoint === 'incidents.media' ? new Promise(() => undefined) : { data: { id: 9 } } // the upload never finishes
    }
    void outbox.drain(send, { force: true }) // on the wire with the photo, and it will be for a long time
    await new Promise((r) => setTimeout(r, 10))
    const alert = await outbox.enqueue(duress)
    await outbox.offer(alert.id, send)
    expect(outbox.all().find((o) => o.id === alert.id)).toMatchObject({ state: 'confirmed', result: { id: 9 } })
    expect(sent).toEqual(['incidents.media', 'duress.store'])
  })

  it('offer() never sends an act twice at once, and leaves acts that wait on another to the drain', async () => {
    const { outbox } = await freshOutbox()
    const alert = await outbox.enqueue(duress)
    let calls = 0
    let answer: (v: { data: unknown }) => void = () => undefined
    const slow: Sender = () => { calls++; return new Promise((resolve) => { answer = resolve }) }
    const one = outbox.offer(alert.id, slow)
    await new Promise((r) => setTimeout(r, 10))
    await outbox.offer(alert.id, slow) // already on the wire: nothing
    expect(calls).toBe(1)
    answer({ data: { id: 1 } })
    await one
    expect(outbox.all()[0]!.state).toBe('confirmed')

    const parent = await outbox.enqueue(clockIn)
    const child = await outbox.enqueue({ ...clockIn, endpoint: 'shifts.clock_out', label: 'Clock-out', after: parent.id })
    await outbox.offer(child.id, async () => { calls++; return { data: {} } })
    expect(calls).toBe(1)
  })

  it('an answer settled from elsewhere while the queue was sending the same act is not undone by a late failure', async () => {
    const { outbox } = await freshOutbox()
    const alert = await outbox.enqueue(duress)
    let fail: (v: { error: ApiError }) => void = () => undefined
    const run = outbox.drain(() => new Promise((resolve) => { fail = resolve }), { force: true })
    await new Promise((r) => setTimeout(r, 10))
    await outbox.settle(alert.id, { id: 44 }) // the direct send was answered
    fail(NETWORK)
    await run
    expect(outbox.all()[0]).toMatchObject({ state: 'confirmed', result: { id: 44 } })
  })
})

/*
 * B062 / B135. A write whose first attempt died ON THE SERVER — a PHP fatal, a killed worker, a reload mid-request
 * — leaves its idempotency row "in progress" for good, and every retry with the same key is answered 409
 * request_in_progress. The queue read that as "try again later", so every drain stopped at that act, and every
 * clock-out, scan and report behind it sat at "Pending sync" for the thirty days the server keeps the row.
 */
describe('a request the server never finishes does not wedge the queue', () => {
  const T0 = new Date('2026-09-04T14:41:00Z').getTime()
  const REAL = ['setTimeout', 'setInterval', 'setImmediate', 'clearTimeout', 'clearInterval', 'clearImmediate', 'nextTick', 'queueMicrotask'] as const
  beforeEach(() => jest.useFakeTimers({ now: T0, doNotFake: [...REAL] }))
  afterEach(() => jest.useRealTimers())
  const scan = { ...clockIn, endpoint: 'patrol.scan' as const, params: { checkpoint: 3 }, label: 'Checkpoint 3', kind: 'patrol' }
  const IN_PROGRESS = err(409, 'request_in_progress')

  it('past the bound it stops blocking: it is refused with a plain sentence, never sent again, and the acts behind it go', async () => {
    const { outbox } = await freshOutbox()
    const stuck = await outbox.enqueue(clockIn)
    const behind = await outbox.enqueue(scan)
    const send = jest.fn<ReturnType<Sender>, Parameters<Sender>>(async (op) => (op.id === stuck.id ? IN_PROGRESS : { data: { id: 1 } }))

    await outbox.drain(send, { force: true })
    jest.setSystemTime(T0 + 5 * 60_000)
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['pending', 'pending']) // inside the bound: a genuine "still being handled"

    jest.setSystemTime(T0 + OUTBOX_LIMITS.stuckInProgressMs + 1000)
    await outbox.drain(send, { force: true })
    const after = outbox.all()
    expect(after.find((o) => o.id === stuck.id)).toMatchObject({ state: 'refused', error: { status: 409, code: 'request_stuck' } })
    expect(after.find((o) => o.id === stuck.id)?.error?.message).toMatch(/cannot tell whether it was saved/)
    expect(after.find((o) => o.id === behind.id)?.state).toBe('confirmed')

    const calls = send.mock.calls.filter(([op]) => op.id === stuck.id).length
    await outbox.drain(send, { force: true })
    expect(send.mock.calls.filter(([op]) => op.id === stuck.id)).toHaveLength(calls) // a refusal is never resent
  })

  it('the clock is the server saying "in progress": no signal in between does not reset it, any other answer does', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(clockIn)
    let answer: Awaited<ReturnType<Sender>> = IN_PROGRESS
    const send: Sender = async () => answer

    await outbox.drain(send, { force: true }) // in progress since T0
    jest.setSystemTime(T0 + 6 * 60_000)
    answer = NETWORK
    await outbox.drain(send, { force: true }) // nothing heard: the server's row is as it was
    jest.setSystemTime(T0 + OUTBOX_LIMITS.stuckInProgressMs + 1000)
    answer = IN_PROGRESS
    await outbox.drain(send, { force: true })
    expect(outbox.all()[0]!.state).toBe('refused')

    const second = await freshOutbox()
    await second.outbox.enqueue(clockIn)
    answer = IN_PROGRESS
    await second.outbox.drain(send, { force: true })
    jest.setSystemTime(T0 + OUTBOX_LIMITS.stuckInProgressMs + 2000)
    answer = err(503, 'server_error') // the request got past the key check this time: the key is not stuck
    await second.outbox.drain(send, { force: true })
    jest.setSystemTime(T0 + OUTBOX_LIMITS.stuckInProgressMs + 4000)
    answer = IN_PROGRESS // a fresh "in progress" — a concurrent retry, most likely — starts a fresh clock
    await second.outbox.drain(send, { force: true })
    expect(second.outbox.all()[0]!.state).toBe('pending')
  })

  it('a stuck photo is let go with the rest of what was kept for it', async () => {
    const storage = createAsyncStorageEngine(memoryBackend())
    await storage.hydrate()
    const gone: string[] = []
    const outbox = createOutbox({ storage, onGone: (op) => gone.push(op.label) })
    await outbox.enqueue({ ...clockIn, endpoint: 'incidents.media', params: { incident: 5 }, body: { file: { uri: 'file:///documents/outbox-media/a.jpg', name: 'a.jpg', type: 'image/jpeg' } }, label: 'Photo', kind: 'photo' })
    await outbox.drain(async () => IN_PROGRESS, { force: true })
    jest.setSystemTime(T0 + OUTBOX_LIMITS.stuckInProgressMs + 1000)
    await outbox.drain(async () => IN_PROGRESS, { force: true })
    expect(gone).toEqual(['Photo'])
  })

  it('an ALERT stuck the same way is surfaced, not dropped: refused with the sentence, for its screen to say so', async () => {
    const { outbox } = await freshOutbox()
    const alert = await outbox.enqueue({ endpoint: 'duress.store', params: {}, body: { mode: 'silent' }, label: 'Duress alert', kind: 'silent', owner: 'GS-1041' })
    await outbox.offer(alert.id, async () => IN_PROGRESS)
    jest.setSystemTime(T0 + OUTBOX_LIMITS.stuckInProgressMs + 1000)
    await outbox.offer(alert.id, async () => IN_PROGRESS)
    expect(outbox.all()[0]).toMatchObject({ id: alert.id, state: 'refused', error: { code: 'request_stuck' } })
  })
})

/*
 * THE CLIENT'S PHONE, 26 SEP. A security report reached the server; its photo never did, and a maintenance request
 * saved after it sat at "Pending sync" behind it — while a booking made from the same phone went straight through.
 * Three ways one act could hold up every act behind it for ever, and what each does now.
 */
describe('one act cannot hold up the rest for ever', () => {
  const report = { endpoint: 'incidents.store' as const, params: {}, body: { kind: 'fault' }, label: 'Report', kind: 'incident', owner: 'GS-1041' }
  const photo = (after: string) => ({ endpoint: 'incidents.media' as const, params: { incident: { $from: after, key: 'id' } }, body: { file: { uri: 'file:///documents/outbox-media/p.jpg', name: 'p.jpg', type: 'image/jpeg' } }, label: 'Photo', kind: 'photo', owner: 'GS-1041', after })
  const request = { ...clockIn, endpoint: 'patrol.scan' as const, params: { checkpoint: 9 }, body: null, label: 'Request', kind: 'patrol' }

  it('a photo that failed goes to the BACK of the line: what was saved after it is sent first', async () => {
    const { outbox } = await freshOutbox()
    const parent = await outbox.enqueue(report)
    await outbox.enqueue(photo(parent.id))
    await outbox.enqueue(request)
    const calls: string[] = []
    const send: Sender = async (op) => { calls.push(op.label); return op.label === 'Photo' ? NETWORK : { data: { id: 77 } } }

    await outbox.drain(send, { force: true })
    // The first time, the photo is in its turn and stops the drain as any failure does: it may be the network.
    expect(outbox.all().map((o) => [o.label, o.state])).toEqual([['Report', 'confirmed'], ['Photo', 'pending'], ['Request', 'pending']])

    calls.length = 0
    await outbox.drain(send, { force: true })
    expect(calls).toEqual(['Request', 'Photo']) // the request first; the photo after everything else
    expect(outbox.all().map((o) => [o.label, o.state])).toEqual([['Report', 'confirmed'], ['Photo', 'pending'], ['Request', 'confirmed']])
  })

  it('a photo that failed is still sent, with its report\'s id, once the reason is gone', async () => {
    const { outbox } = await freshOutbox()
    const parent = await outbox.enqueue(report)
    await outbox.enqueue(photo(parent.id))
    let photoWorks = false
    const seen: unknown[] = []
    const send: Sender = async (op, params) => {
      if (op.label !== 'Photo') return { data: { id: 77 } }
      seen.push(params)
      return photoWorks ? { data: { media_id: 1 } } : NETWORK
    }
    await outbox.drain(send, { force: true })
    await outbox.drain(send, { force: true })
    photoWorks = true
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['confirmed', 'confirmed'])
    expect(seen.at(-1)).toEqual({ incident: 77 })
  })

  it('a send that never answers is a TIMEOUT after three minutes: the drain ends, and the same key is sent again', async () => {
    jest.useFakeTimers()
    try {
      const { outbox } = await freshOutbox()
      const hung = await outbox.enqueue(clockIn)
      await outbox.enqueue(request)
      const keys: string[] = []
      let answering = false
      const send: Sender = (op) => {
        keys.push(op.stamp.idempotencyKey)
        return answering ? Promise.resolve({ data: { id: 1 } }) : new Promise(() => undefined) // on the wire, for ever
      }
      const run = outbox.drain(send, { force: true })
      await jest.advanceTimersByTimeAsync(OUTBOX_LIMITS.stalledSendMs + 10)
      await run // it ends — it used to hang here, and every drain after it with it
      expect(outbox.all().find((o) => o.id === hung.id)).toMatchObject({ state: 'pending', error: { status: 'timeout', code: 'send_stalled' } })

      answering = true
      await outbox.drain(send, { force: true })
      expect(outbox.all().map((o) => o.state)).toEqual(['confirmed', 'confirmed'])
      expect(keys[1]).toBe(keys[0]) // the same act, the same key: if the first did land, the server replays its answer
    } finally {
      jest.useRealTimers()
    }
  })

  it('an act the APP cannot send (no status, no network error) is said after three tries, and the line moves on', async () => {
    const { outbox } = await freshOutbox()
    const broken = await outbox.enqueue(clockIn)
    const behind = await outbox.enqueue(request)
    // What a mutation answers when the app itself throws before a request is made: no HTTP status at all.
    const local = { error: { name: 'TypeError', message: 'Cannot read property uri of undefined' } as unknown as ApiError }
    const send: Sender = async (op) => (op.id === broken.id ? local : { data: { id: 5 } })

    await outbox.drain(send, { force: true })
    await outbox.drain(send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['pending', 'pending']) // tried twice: it may still be passing

    await outbox.drain(send, { force: true })
    const after = outbox.all()
    expect(after.find((o) => o.id === broken.id)).toMatchObject({ state: 'refused', error: { code: NOT_SENDABLE } })
    expect(after.find((o) => o.id === broken.id)?.error?.message).toBe(NOT_SENDABLE_SENTENCE) // never the raw TypeError
    expect(after.find((o) => o.id === behind.id)?.state).toBe('confirmed')
  })

  it('a NETWORK failure is never counted as the app\'s own: it waits for signal however many tries it takes', async () => {
    const { outbox } = await freshOutbox()
    await outbox.enqueue(clockIn)
    for (let i = 0; i < OUTBOX_LIMITS.localFailuresBeforeRefusal + 3; i++) await outbox.drain(async () => NETWORK, { force: true })
    expect(outbox.all()[0]).toMatchObject({ state: 'pending' })
  })

  /*
   * THE M11 §1.7 AIRPLANE TEST (26 Sep): a notice read waited through several tries with no signal, and at its FIRST
   * local failure after the reconnect it was written off — the bound compared the act's whole history (`attempts`),
   * not failures on the phone. It is three IN A ROW now, and any answer from the server or the network starts again.
   */
  it('an act that waited through many tries with no signal is NOT written off at its first local failure', async () => {
    const { outbox } = await freshOutbox()
    const read = await outbox.enqueue(request)
    for (let i = 0; i < 6; i++) await outbox.drain(async () => NETWORK, { force: true })
    const local = { error: { name: 'TypeError', message: 'boom' } as unknown as ApiError }
    await outbox.drain(async () => local, { force: true })
    expect(outbox.all().find((o) => o.id === read.id)).toMatchObject({ state: 'pending', localFailures: 1 })
    await outbox.drain(async () => ({ data: { id: 3 } }), { force: true })
    expect(outbox.all().find((o) => o.id === read.id)).toMatchObject({ state: 'confirmed', localFailures: 0 })
  })

  it('an answer from the server or the network between local failures starts the count again', async () => {
    const { outbox } = await freshOutbox()
    const op = await outbox.enqueue(request)
    const local = { error: { name: 'TypeError', message: 'boom' } as unknown as ApiError }
    const signedOut = { error: { status: 401, code: 'unauthenticated', message: 'Signed out.', fieldErrors: {}, retryAfter: null } as ApiError }
    for (const answer of [local, local, NETWORK, local, local, signedOut, local, local]) await outbox.drain(async () => answer, { force: true })
    // Never three in a row: still waiting, and a 401 (signed out) is only ever a wait.
    expect(outbox.all().find((o) => o.id === op.id)).toMatchObject({ state: 'pending', localFailures: 2 })
    await outbox.drain(async () => local, { force: true })
    expect(outbox.all().find((o) => o.id === op.id)).toMatchObject({ state: 'refused', error: { code: NOT_SENDABLE } })
  })
})

describe('what was kept for an act goes when the act does', () => {
  const report = { endpoint: 'incidents.store' as const, params: {}, body: { kind: 'fault' }, label: 'Report', kind: 'incident', owner: 'GS-1041' }
  const photo = (after: string, owner = 'GS-1041') => ({ endpoint: 'incidents.media' as const, params: { incident: { $from: after, key: 'id' } }, body: { file: { uri: 'file:///documents/outbox-media/x.jpg', name: 'x.jpg', type: 'image/jpeg' } }, label: 'Photo', kind: 'photo', owner, after })

  async function withHook() {
    const storage = createAsyncStorageEngine(memoryBackend())
    await storage.hydrate()
    const gone: string[] = []
    return { outbox: createOutbox({ storage, onGone: (op) => gone.push(op.label) }), gone }
  }

  // Found by the incidents review (2026-09-20): a photo refused BECAUSE ITS REPORT WAS never reaches the sender,
  // so nothing removed the copy kept for it.
  it('a photo refused with its report is handed to onGone: it never reaches the sender', async () => {
    const { outbox, gone } = await withHook()
    const parent = await outbox.enqueue(report)
    await outbox.enqueue(photo(parent.id))
    const send = jest.fn<ReturnType<Sender>, Parameters<Sender>>(async () => err(422, 'validation'))
    await outbox.drain(send, { force: true })
    expect(send).toHaveBeenCalledTimes(1) // the report only
    expect(outbox.all().map((o) => o.state)).toEqual(['refused', 'refused'])
    expect(gone).toEqual(['Photo'])
  })

  it('a dismissed act, and another person\'s acts on adopt, are handed over too; a hook that throws breaks nothing', async () => {
    const { outbox, gone } = await withHook()
    const mine = await outbox.enqueue(report)
    await outbox.drain(async () => err(422, 'validation'), { force: true })
    await outbox.dismiss(mine.id)
    await outbox.enqueue({ ...report, label: 'Theirs', owner: 'GS-2000' })
    await outbox.adopt('GS-1041')
    expect(gone).toEqual(['Report', 'Theirs'])

    const storage = createAsyncStorageEngine(memoryBackend())
    await storage.hydrate()
    const fragile = createOutbox({ storage, onGone: () => { throw new Error('disk full') } })
    const op = await fragile.enqueue(report)
    await fragile.drain(async () => err(422, 'validation'), { force: true })
    await expect(fragile.dismiss(op.id)).resolves.toBeUndefined()
    expect(fragile.all()).toEqual([])
  })
})
/**
 * P2 GATE — an offline shift start.
 *
 * A guard with no signal clocks in, works, and clocks out. The phone is even restarted in
 * between. When signal returns the server receives: the clock-in FIRST, then the clock-out,
 * each exactly once, each with the Idempotency-Key and the device time of the moment the
 * guard actually pressed the button — not the moment the network came back.
 */
import type { ApiError } from '../../api/errors'
import { createAsyncStorageEngine } from '../../lib/storage'
import { createOutbox, type OutboxOp, type Sender } from '../outbox'

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

const NETWORK: { error: ApiError } = { error: { status: 'network', code: 'network_unreachable', message: 'offline', fieldErrors: {}, retryAfter: null } }

type Received = { endpoint: string; key: string; deviceTime: string; shift: unknown }

function server() {
  const received: Received[] = []
  const seen = new Set<string>()
  let online = false
  const send: Sender = async (op, params) => {
    if (!online) return NETWORK
    if (!seen.has(op.stamp.idempotencyKey)) {
      seen.add(op.stamp.idempotencyKey)
      received.push({ endpoint: op.endpoint, key: op.stamp.idempotencyKey, deviceTime: op.stamp.deviceTime, shift: params.shift })
    }
    return { data: { status: op.endpoint === 'shifts.clock_in' ? 'on_duty' : 'completed' } }
  }
  return { send, received, signalReturns: () => (online = true) }
}

async function boot(backend: ReturnType<typeof memoryBackend>) {
  const storage = createAsyncStorageEngine(backend)
  await storage.hydrate()
  return createOutbox({ storage })
}

describe('P2 gate: offline shift start', () => {
  it('clock-in and clock-out made with no signal survive a restart and reach the server in order, once, with their original key and time', async () => {
    const backend = memoryBackend()
    const api = server()

    // 07:00 — no signal at the gatehouse. The guard clocks in.
    let outbox = await boot(backend)
    const clockIn = await outbox.enqueue({ endpoint: 'shifts.clock_in', params: { shift: 812 }, body: { method: 'app', mock_location: false }, label: 'Clock-in · Main Gate', kind: 'clock', owner: 'GS-1041' })
    await outbox.drain(api.send, { force: true })
    expect(outbox.all().map((o) => o.state)).toEqual(['pending']) // honest: not "done", not "failed"
    expect(api.received).toHaveLength(0)

    // The phone dies and is restarted. The act is still there, unchanged.
    outbox = await boot(backend)
    expect(outbox.all()).toHaveLength(1)
    expect(outbox.all()[0]!.stamp).toEqual(clockIn.stamp)

    // 19:00 — still no signal. The guard clocks out; it is ordered after the clock-in.
    const clockOut = await outbox.enqueue({ endpoint: 'shifts.clock_out', params: { shift: 812 }, body: { handover_note: 'Gate arm sticking' }, label: 'Clock-out · Main Gate', kind: 'clock', owner: 'GS-1041', after: clockIn.id })
    await outbox.drain(api.send, { force: true })
    expect(api.received).toHaveLength(0)

    // Signal returns. Two drains race (a reconnect event and a screen opening) — still once each.
    api.signalReturns()
    await Promise.all([outbox.drain(api.send, { force: true }), outbox.drain(api.send, { force: true })])
    await outbox.drain(api.send, { force: true })

    expect(api.received.map((r) => r.endpoint)).toEqual(['shifts.clock_in', 'shifts.clock_out'])
    expect(api.received[0]).toMatchObject({ key: clockIn.stamp.idempotencyKey, deviceTime: clockIn.stamp.deviceTime, shift: 812 })
    expect(api.received[1]).toMatchObject({ key: clockOut.stamp.idempotencyKey, deviceTime: clockOut.stamp.deviceTime, shift: 812 })
    expect(outbox.all().map((o: OutboxOp) => o.state)).toEqual(['confirmed', 'confirmed'])
    expect(outbox.pendingCount()).toBe(0)
  })

  it('a clock-out never overtakes a clock-in the server has not yet accepted', async () => {
    const backend = memoryBackend()
    const outbox = await boot(backend)
    const order: string[] = []
    let failFirst = true
    const flaky: Sender = async (op) => {
      if (op.endpoint === 'shifts.clock_in' && failFirst) {
        failFirst = false
        return NETWORK
      }
      order.push(op.endpoint)
      return { data: {} }
    }
    const clockIn = await outbox.enqueue({ endpoint: 'shifts.clock_in', params: { shift: 9 }, label: 'in', kind: 'clock', owner: 'GS-1041' })
    await outbox.enqueue({ endpoint: 'shifts.clock_out', params: { shift: 9 }, label: 'out', kind: 'clock', owner: 'GS-1041', after: clockIn.id })

    await outbox.drain(flaky, { force: true }) // the clock-in fails: the clock-out must NOT go
    expect(order).toEqual([])
    await outbox.drain(flaky, { force: true })
    expect(order).toEqual(['shifts.clock_in', 'shifts.clock_out'])
  })
})

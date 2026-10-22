/**
 * M3 GATE A+B — A WHOLE SHIFT IN AIRPLANE MODE.
 *
 * "With the phone in airplane mode, a guard can work an entire shift — verify a pass, admit a
 *  walk-up, scan checkpoints, file an incident — and everything reconciles correctly and exactly
 *  once when connectivity returns. Kill the connection mid-action, mid-sync and mid-upload.
 *  Nothing lost, nothing duplicated."
 *
 * Every piece is the real one: the pass is a token signed by the REAL Laravel server and is
 * verified by the app's own verifier with `fetch` rigged to explode; the queue is the real
 * outbox on a disk that survives "killing the app"; the server is a fake that is idempotent
 * exactly the way the real one is (one effect per Idempotency-Key, the first answer replayed).
 */
import vector from '../../crypto/__tests__/server-vector.json'
import { verifyPassOffline } from '../../crypto/passVerifier'
import type { ApiError } from '../../api/errors'
import { createAsyncStorageEngine } from '../../lib/storage'
import { canQueue, createOutbox, OfflineNotAllowedError, type OutboxOp, type Sender } from '../outbox'

function disk() {
  const files = new Map<string, string>()
  return {
    files,
    getAllKeys: async () => [...files.keys()],
    multiGet: async (keys: readonly string[]) => keys.map((k) => [k, files.get(k) ?? null] as [string, string | null]),
    setItem: async (k: string, v: string) => void files.set(k, v),
    removeItem: async (k: string) => void files.delete(k),
    multiRemove: async (keys: readonly string[]) => keys.forEach((k) => files.delete(k)),
  }
}

const NETWORK: { error: ApiError } = { error: { status: 'network', code: 'network_unreachable', message: 'offline', fieldErrors: {}, retryAfter: null } }

type Effect = { endpoint: string; key: string; deviceTime: string; params: Record<string, string | number> }

function server() {
  const effects: Effect[] = []
  const answers = new Map<string, unknown>()
  let online = false
  let dieAfter: number | null = null // the connection drops after this many more requests ARRIVE
  let loseNextAnswer = false
  let nextId = 900
  const send: Sender = async (op, params) => {
    if (!online) return NETWORK
    if (dieAfter !== null && dieAfter-- <= 0) { online = false; dieAfter = null; return NETWORK }
    const key = op.stamp.idempotencyKey
    if (!answers.has(key)) {
      effects.push({ endpoint: op.endpoint, key, deviceTime: op.stamp.deviceTime, params })
      answers.set(key, { id: nextId++, reconciliation: op.endpoint === 'gate.entry' && (op.body as { pass_id?: string } | null)?.pass_id ? 'consumed' : null })
    }
    if (loseNextAnswer) { loseNextAnswer = false; online = false; return NETWORK } // the act HAPPENED; the answer never got back
    return { data: answers.get(key) }
  }
  return { send, effects, signalReturns: () => (online = true), dropAfter: (n: number) => (dieAfter = n), loseTheNextAnswer: () => (loseNextAnswer = true) }
}

async function openApp(files: ReturnType<typeof disk>) {
  const storage = createAsyncStorageEngine(files)
  await storage.hydrate()
  return createOutbox({ storage })
}

const OWNER = 'GS-9001'
const at = new Date('2026-09-04T15:00:00-05:00')

beforeAll(() => {
  globalThis.fetch = jest.fn(() => { throw new Error('NETWORK USED: airplane mode means airplane mode') }) as unknown as typeof fetch
})
afterAll(() => expect(globalThis.fetch).not.toHaveBeenCalled())

describe('M3 gate A+B: a whole shift in airplane mode', () => {
  it('nothing lost, nothing duplicated, everything in order: through a killed app, a drop mid-sync and a drop mid-upload', async () => {
    const files = disk()
    const api = server()
    let outbox = await openApp(files)
    const captured: OutboxOp[] = []
    const act = async (input: Parameters<typeof outbox.enqueue>[0]) => { const op = await outbox.enqueue(input); captured.push(op); return op }

    /* ---- 07:00 · no signal · the guard starts the shift ------------------------------------------ */
    const clockIn = await act({ endpoint: 'shifts.clock_in', params: { shift: 36 }, body: { method: 'app', mock_location: false }, label: 'Clock-in · Main Gate', kind: 'clock', owner: OWNER })

    /* ---- a visitor with a QR pass: verified ON THE HANDSET, with the real server's signature ------- */
    const verdict = verifyPassOffline({ token: vector.token, siteId: vector.site_id, keys: vector.keys, revoked: [], now: at })
    expect(verdict.verdict).toBe('valid_offline') // never "valid": a cancellation a minute ago is unknowable here
    const passId = 'pass' in verdict ? verdict.pass.pass_id : ''
    await act({ endpoint: 'gate.entry', body: { category: 'Visitor', pass_id: passId, verified_offline: true }, label: 'Entry · Pass holder', kind: 'gate', owner: OWNER })

    // A forged pass is refused offline too — and nothing is recorded for it.
    const [segment, signature] = vector.token.split('.') as [string, string]
    const forged = `${segment.slice(0, 20)}${segment[20] === 'A' ? 'B' : 'A'}${segment.slice(21)}.${signature}`
    expect(['bad_signature', 'malformed']).toContain(verifyPassOffline({ token: forged, siteId: vector.site_id, keys: vector.keys, now: at }).verdict)

    /* ---- a walk-up: the household cannot be asked with no signal, and that request is NEVER queued -- */
    expect(canQueue('gate.approvals.store')).toBe(false)
    await expect(outbox.enqueue({ endpoint: 'gate.approvals.store', body: { unit: 'Lot 47', visitor_name: 'Kevin Chambers' }, label: 'Walk-up', kind: 'gate', owner: OWNER })).rejects.toBeInstanceOf(OfflineNotAllowedError)
    // …so the estate's policy applies: the guard admits on their own decision, and THAT is a record.
    await act({ endpoint: 'gate.entry', body: { category: 'Visitor', subject: 'Kevin Chambers · Lot 47' }, label: 'Entry · Kevin Chambers', kind: 'gate', owner: OWNER })

    /* ---- THE APP IS KILLED MID-SHIFT (battery, a crash). Everything captured is still there. -------- */
    outbox = await openApp(files)
    expect(outbox.all().map((o) => o.id)).toEqual(captured.map((o) => o.id))

    /* ---- patrol: four checkpoints ------------------------------------------------------------------ */
    for (const checkpoint of [11, 12, 13, 14]) {
      await act({ endpoint: 'patrol.scan', params: { checkpoint }, body: { code: `PH-CP-2-${checkpoint}`, captured_offline: true }, label: `Checkpoint scan: ${checkpoint}`, kind: 'patrol', owner: OWNER })
    }

    /* ---- an incident with two photos: the photos wait for the report's id -------------------------- */
    const report = await act({ endpoint: 'incidents.store', body: { category: 'suspicious_activity', severity: 'medium', detail: 'Two men testing car door handles on Hibiscus Drive.' }, label: 'Incident report · Suspicious activity', kind: 'incident', owner: OWNER })
    for (const n of [1, 2]) {
      await act({ endpoint: 'incidents.media', params: { incident: { $from: report.id, key: 'id' } }, body: { uri: `file:///photo-${n}.jpg`, name: `photo-${n}.jpg`, type: 'image/jpeg' }, label: 'Incident photo', kind: 'incident', owner: OWNER, after: report.id })
    }

    /* ---- 19:00 · still no signal · the guard clocks out, ordered after the clock-in ---------------- */
    await act({ endpoint: 'shifts.clock_out', params: { shift: 36 }, body: { handover_note: 'Gate arm sticking.' }, label: 'Clock-out · Main Gate', kind: 'clock', owner: OWNER, after: clockIn.id })

    await outbox.drain(api.send, { force: true }) // a drain with no signal changes nothing and loses nothing
    expect(api.effects).toHaveLength(0)
    expect(outbox.pendingCount()).toBe(captured.length)
    expect(captured).toHaveLength(11)

    /* ---- SIGNAL RETURNS… and drops MID-SYNC after four requests ------------------------------------ */
    api.signalReturns()
    api.dropAfter(4)
    await outbox.drain(api.send, { force: true })
    expect(api.effects).toHaveLength(4)
    expect(outbox.pendingCount()).toBe(7)

    /* ---- back again… and the connection dies MID-UPLOAD: the server did the act, the answer was lost -- */
    api.signalReturns()
    api.loseTheNextAnswer()
    await outbox.drain(api.send, { force: true })
    expect(api.effects).toHaveLength(5) // it happened once on the server…
    expect(outbox.pendingCount()).toBe(7) // …and the phone, never having heard, still holds it as pending

    /* ---- the app is killed AGAIN, mid-sync, then signal for good. Two drains race (reconnect + a screen opening). */
    outbox = await openApp(files)
    api.signalReturns()
    await Promise.all([outbox.drain(api.send, { force: true }), outbox.drain(api.send, { force: true })])
    await outbox.drain(api.send, { force: true })

    /* ---- THE VERDICT -------------------------------------------------------------------------------- */
    // Exactly once each: eleven acts, eleven effects, no key seen twice.
    expect(api.effects).toHaveLength(11)
    expect(new Set(api.effects.map((e) => e.key)).size).toBe(11)
    // Each with the key AND the moment it was captured with — not the moment the network came back.
    for (const op of captured) expect(api.effects.find((e) => e.key === op.stamp.idempotencyKey)?.deviceTime).toBe(op.stamp.deviceTime)
    // In the order the guard did them.
    expect(api.effects.map((e) => e.endpoint)).toEqual([
      'shifts.clock_in', 'gate.entry', 'gate.entry', 'patrol.scan', 'patrol.scan', 'patrol.scan', 'patrol.scan', 'incidents.store', 'incidents.media', 'incidents.media', 'shifts.clock_out',
    ])
    // The photos went to the id the SERVER gave the report — never to a guess.
    const reportEffectIndex = api.effects.findIndex((e) => e.endpoint === 'incidents.store')
    const media = api.effects.filter((e) => e.endpoint === 'incidents.media')
    expect(media.map((e) => e.params.incident)).toEqual([900 + reportEffectIndex, 900 + reportEffectIndex])
    // Nothing is left waiting, nothing was refused, and every record says so.
    expect(outbox.pendingCount()).toBe(0)
    expect(outbox.all().map((o) => o.state)).toEqual(Array.from({ length: 11 }, () => 'confirmed'))
    // The offline admission is reconciled by the server, and the phone keeps its answer.
    expect(outbox.all().find((o) => o.endpoint === 'gate.entry' && (o.body as { pass_id?: string } | null)?.pass_id)?.result).toMatchObject({ reconciliation: 'consumed' })
  })

  it('what must never wait for signal is never allowed into the queue', () => {
    for (const endpoint of ['gate.approvals.store', 'gate.search', 'shifts.claim', 'devices.enrol', 'elections.ballot', 'invoices.pay_intent', 'autopay.store'] as const) {
      expect(canQueue(endpoint)).toBe(false)
    }
    for (const endpoint of ['shifts.clock_in', 'shifts.clock_out', 'gate.entry', 'gate.exit', 'gate.override', 'gate_events.store', 'patrol.scan', 'incidents.store', 'orders.acknowledge'] as const) {
      expect(canQueue(endpoint)).toBe(true)
    }
  })
})

/**
 * INVARIANT 8 — a panic or duress alert is on its way in under three seconds, BEFORE anything
 * else, and can never be lost or doubled. INVARIANT 13 rides along: nothing here can ever block.
 */
import type { ApiError } from '../../api/errors'
import { ALERT_DEADLINE_MS, ALERT_TYPES, POSITION_BUDGET_MS, RETRY_HELD_ALERT_MS, SEND_BUDGET_MS, alertSteps, isAlertType, keepOffering, raiseAlert, type AlertAnswer, type AlertBody, type AlertType, type RaiseDeps } from '../raiseAlert'

jest.useFakeTimers()

const stamp = { idempotencyKey: '6f1d2c9e-0000-4000-8000-000000000001', deviceTime: '2026-09-04T09:41:02-05:00' }
const answer: AlertAnswer = { id: 77, status: 'open', cancellable_until: '2026-09-04T14:41:12Z', acknowledged_at: null, cancelled_at: null, server_time: '2026-09-04T14:41:02Z' }
const net = (status: ApiError['status'], code: string, message = code): { error: ApiError } => ({ error: { status, code, message, fieldErrors: {}, retryAfter: null } })
const sleep = <T>(ms: number, value: T) => new Promise<T>((resolve) => setTimeout(() => resolve(value), ms))

function world(over: Partial<RaiseDeps> = {}) {
  const log: string[] = []
  const sent: { body: AlertBody; key: string }[] = []
  const captured: { body: AlertBody; key: string }[] = []
  let t = 0
  const deps: RaiseDeps = {
    stamp,
    online: true,
    clock: () => t,
    position: async () => ({ latitude: 18.0179, longitude: -76.8099 }),
    capture: async (body, s) => { log.push('capture'); captured.push({ body, key: s.idempotencyKey }); return { id: 'op-1' } },
    send: async (body, s) => { log.push('send'); sent.push({ body, key: s.idempotencyKey }); return { data: answer } },
    settle: async () => void log.push('settle'),
    ...over,
  }
  const run = async (mode: 'silent' | 'audible' = 'audible', type?: AlertType | null) => {
    const pending = raiseAlert(deps, type === undefined ? { mode } : { mode, type })
    // Let every timer the alert could possibly wait on elapse, and count the time it took.
    for (let i = 0; i < 400; i++) { await Promise.resolve(); jest.advanceTimersByTime(25); t += 25; await Promise.resolve() }
    return pending
  }
  return { deps, run, log, sent, captured }
}

describe('invariant 8: under three seconds, before anything else', () => {
  it('the budgets themselves fit inside the deadline', () => {
    expect(POSITION_BUDGET_MS + SEND_BUDGET_MS).toBeLessThan(ALERT_DEADLINE_MS)
  })

  it('on disk FIRST, then sent directly, then the queue is told, and the same key and body both times', async () => {
    const w = world()
    const out = await w.run()
    expect(w.log).toEqual(['capture', 'send', 'settle'])
    expect(out).toMatchObject({ state: 'sent', alert: { id: 77 }, withPosition: true })
    expect(w.sent[0]).toEqual(w.captured[0]) // identical request: the server replays, never doubles, never 422s
    expect(w.sent[0]?.key).toBe(stamp.idempotencyKey)
  })

  it('a GPS that takes ten seconds does not delay the alert: it goes without a position, inside the deadline', async () => {
    const w = world({ position: () => sleep(10_000, { latitude: 1, longitude: 1 }) })
    const out = await w.run()
    expect(out.state).toBe('sent')
    expect(out.withPosition).toBe(false)
    expect(w.sent[0]?.body).toEqual({ mode: 'audible' })
    expect(out.elapsedMs).toBeLessThan(ALERT_DEADLINE_MS)
  })

  it('location refused, or throwing, never stops an alert (safety never depends on consent)', async () => {
    const refused = await world({ position: async () => null }).run()
    const broken = await world({ position: async () => { throw new Error('permission denied') } }).run()
    expect([refused.state, broken.state]).toEqual(['sent', 'sent'])
  })

  it('a server that does not answer in time: the person is told within the deadline, and the queue holds the alert', async () => {
    const w = world({ send: () => sleep(60_000, { data: answer }) })
    const out = await w.run()
    expect(out).toMatchObject({ state: 'queued', why: 'slow', opId: 'op-1' })
    expect(out.elapsedMs).toBeLessThanOrEqual(ALERT_DEADLINE_MS)
    expect(w.log).not.toContain('settle') // still pending in the queue — it WILL be sent, with the same key
  })

  /*
   * FOUND ON A TEST PHONE, 2026-09-22. The server took 2.6 s over a tunnel, recorded the alert, and the
   * screen said "not received yet" for good: the answer landed after the budget and was thrown away, and
   * with the phone online the whole time no reconnect ever came to make the queue send it again.
   */
  it('an answer that lands AFTER the budget still tells the queue, so the screen can say it was received', async () => {
    const settled: unknown[] = []
    const w = world({ send: () => sleep(2_600, { data: answer }), settle: async (_id, a) => void settled.push(a) })
    const out = await w.run()
    expect(out).toMatchObject({ state: 'queued', why: 'slow', opId: 'op-1' })
    expect(out.elapsedMs).toBeLessThanOrEqual(ALERT_DEADLINE_MS)
    expect(settled).toEqual([answer])
  })

  it('a late FAILURE settles nothing: the alert stays held for the queue to send', async () => {
    const w = world({ send: () => sleep(2_600, net(500, 'server_error')) })
    await w.run()
    expect(w.log).not.toContain('settle')
  })

  it('no signal: captured, reported honestly as queued, marked as captured offline', async () => {
    const w = world({ online: false, send: async () => net('network', 'network_unreachable') })
    const out = await w.run('silent')
    expect(out).toMatchObject({ state: 'queued', why: 'no_signal', opId: 'op-1' })
    expect(w.captured[0]?.body).toEqual({ mode: 'silent', latitude: 18.0179, longitude: -76.8099, captured_offline: true })
  })

  it('a full disk does not stop an alert either: it is still sent', async () => {
    const w = world({ capture: async () => { throw new Error('ENOSPC') } })
    const out = await w.run()
    expect(out).toMatchObject({ state: 'sent', opId: null })
  })

  it('a refusal is shown in the server’s words; a server fault is not blamed on the person', async () => {
    expect(await world({ send: async () => net(403, 'account_suspended', 'The estate withdrew access.') }).run()).toMatchObject({ state: 'queued', why: 'refused', message: 'The estate withdrew access.' })
    expect(await world({ send: async () => net(500, 'server_error') }).run()).toMatchObject({ state: 'queued', why: 'server', message: null })
  })

  it('raising an alert touches nothing but the alert: no media, no upload, no other call', async () => {
    const w = world()
    await w.run()
    expect(w.log.filter((step) => step !== 'capture' && step !== 'send' && step !== 'settle')).toEqual([])
    expect(Object.keys(w.sent[0]!.body).sort()).toEqual(['latitude', 'longitude', 'mode'])
  })
})

/*
 * M10 §1: "Panic type: let the resident pick if they can — medical, fire, security, other — but never
 * require it. An unlabelled panic goes to everyone exactly the same."
 */
describe('what kind of emergency: carried if chosen, never required, never a reason to refuse', () => {
  it('the four values are the server\'s own, and nothing else passes', () => {
    expect([...ALERT_TYPES]).toEqual(['medical', 'fire', 'security', 'other'])
    for (const t of ALERT_TYPES) expect(isAlertType(t)).toBe(true)
    // The alert KINDS the server maps to are not types a phone may send: the server would refuse them.
    for (const bad of ['panic', 'duress', 'MEDICAL', '', null, undefined, 3]) expect(isAlertType(bad)).toBe(false)
  })

  it('with nothing chosen the body is exactly the body it always was: the key is absent, not null', async () => {
    for (const nothing of [undefined, null]) {
      const w = world()
      await w.run('audible', nothing)
      expect(w.sent[0]?.body).toEqual({ mode: 'audible', latitude: 18.0179, longitude: -76.8099 })
      expect('type' in w.sent[0]!.body).toBe(false)
    }
  })

  it('a chosen type is simply carried: the same in what is captured and what is sent', async () => {
    for (const type of ALERT_TYPES) {
      const w = world()
      const out = await w.run('audible', type)
      expect(out.state).toBe('sent')
      expect(w.sent[0]?.body.type).toBe(type)
      expect(w.sent[0]).toEqual(w.captured[0])
      expect(w.log).toEqual(['capture', 'send', 'settle'])
    }
  })

  it('it goes with a queued alert too, so the label survives no signal', async () => {
    const w = world({ online: false, send: async () => net('network', 'network_unreachable') })
    await w.run('audible', 'medical')
    expect(w.captured[0]?.body).toMatchObject({ type: 'medical', captured_offline: true })
  })

  it('a value the server does not know is dropped, and the alert goes as an unlabelled one', async () => {
    const w = world()
    const out = await w.run('audible', 'panic' as AlertType)
    expect(out.state).toBe('sent')
    expect('type' in w.sent[0]!.body).toBe(false)
  })

  it('a type costs no time: with a GPS that never answers, a labelled alert is inside the deadline like any other', async () => {
    const w = world({ position: () => sleep(10_000, { latitude: 1, longitude: 1 }) })
    const out = await w.run('audible', 'fire')
    expect(out.state).toBe('sent')
    expect(w.sent[0]?.body).toEqual({ mode: 'audible', type: 'fire' })
    expect(out.elapsedMs).toBeLessThan(ALERT_DEADLINE_MS)
  })
})

describe('a held alert is offered again until it is answered', () => {
  const tick = async (ms: number) => { jest.advanceTimersByTime(ms); await Promise.resolve(); await Promise.resolve() }

  // B125: the held act is sent again ON ITS OWN (`outbox.offer`), not by asking the queue to drain — a drain puts
  // it back in line behind whatever the queue is busy with.
  it('sends the held alert again, on its own, every few seconds while held, and stops once confirmed', async () => {
    let state: string | undefined = 'pending'
    let resends = 0
    keepOffering('held-1', { state: () => state, resend: async () => void resends++ })
    await tick(RETRY_HELD_ALERT_MS * 3)
    expect(resends).toBe(3)
    state = 'confirmed'
    await tick(RETRY_HELD_ALERT_MS * 3)
    expect(resends).toBe(3)
  })

  it('one timer per alert, however often it is asked, and a refusal or a vanished act stops it too', async () => {
    let resends = 0
    const deps = { state: (): string | undefined => 'pending', resend: async () => void resends++ }
    keepOffering('held-2', deps)
    keepOffering('held-2', deps)
    keepOffering('held-2', deps)
    await tick(RETRY_HELD_ALERT_MS)
    expect(resends).toBe(1)

    let refused = 0
    keepOffering('held-3', { state: () => 'refused', resend: async () => void refused++ })
    let vanished = 0
    keepOffering('held-4', { state: () => undefined, resend: async () => void vanished++ })
    await tick(RETRY_HELD_ALERT_MS * 2)
    expect([refused, vanished]).toEqual([0, 0])
    keepOffering('held-2', deps)() // the returned function stops it
  })

  it('a resend that throws does not stop the offering', async () => {
    let tries = 0
    const stop = keepOffering('held-5', { state: () => 'pending', resend: async () => { tries++; throw new Error('boom') } })
    await tick(RETRY_HELD_ALERT_MS * 2)
    expect(tries).toBe(2)
    stop()
  })
})

/*
 * What the person is told afterwards moved to alertProgress.ts with the server's six steps, and is tested
 * in alertProgress.test.ts. It is still importable from here, so nothing that imported it broke.
 */
describe('what the person is told afterwards', () => {
  it('is still exported from the engine, and is the six-step timeline', () => {
    const steps = alertSteps({ status: 'open', acknowledged_at: null, cancelled_at: null }, '9:41:02 AM', null)
    expect(steps.map((s) => s.key)).toEqual(['sent', 'acknowledged', 'dispatched', 'en_route', 'on_scene', 'resolved'])
  })
})

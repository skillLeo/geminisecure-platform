/**
 * CATCH-UP (DESIGN §5, C6): the log is the truth and the socket is a hint.
 *
 * Driven through the real client — configureLive, startLive, the real ChannelSync and scheduler, the real cursors
 * on the real (in-memory) storage — with only the socket, the three HTTP calls and AppState replaced (fakes.ts).
 */

import { clearEverything } from '../../auth/clearEverything'
import { getStorage } from '../../lib/storage'
import { liveChannels, nudgeLive } from '../client'
import { BATCH_MS, CatchUpScheduler, GAP_DELAY_MS, MAX_CHANNELS_PER_REQUEST, type CatchUpRequest } from '../catchUp'
import { onLiveEvent } from '../configure'
import { CURSORS_STORAGE_KEY, markConnected, recordLiveHead, savedCursors } from '../cursors'
import { registerDurableStore } from '../durable'
import { envelopeOnly, type LiveEnvelope } from '../envelope'
import { newOrigin } from '../origin'
import * as rtk from '../rtk'
import { EP, PASSES, advance, deliver, env, liveConfig, open, resetLive, settle, startHarness } from './fakes'

jest.mock('expo-image', () => ({ Image: { clearMemoryCache: jest.fn(async () => true), clearDiskCache: jest.fn(async () => true) } }))

const ALERTS = 'estate.pp.alerts'

/** What the handlers were handed, as `via:q`. */
function record(type = 'pass.*'): string[] {
  const seen: string[] = []
  onLiveEvent(type, (e, ctx) => {
    seen.push(`${ctx.via}:${e.q}`)
  })
  return seen
}

/** A catch-up answer for one channel. */
const ok = (name: string, events: unknown[], head: number, more = false) => ({ name, status: 'ok', events, head: { e: head }, ep: { e: EP }, more })

const cursorOf = (name: string) => liveChannels().find((c) => c.name === name)?.cursors.e
const stateOf = (name: string) => liveChannels().find((c) => c.name === name)?.state

beforeEach(() => {
  jest.useFakeTimers()
})

afterEach(() => {
  resetLive()
  jest.restoreAllMocks()
  jest.useRealTimers()
})

describe('apply before live (§5.3)', () => {
  it('what the socket delivers while a channel catches up is held, and applied after the answer: in order, once', async () => {
    savedCursors.save('g-5', { [PASSES]: { e: { q: 2, ep: EP } } })
    const h = await startHarness()
    const seen = record()

    h.sockets.last.events.opened()
    h.sockets.last.events.subscribed(PASSES)
    expect(stateOf(PASSES)).toBe('catching')

    // Published while the catch-up is on its way: held, not handed to anybody yet.
    deliver(h, PASSES, env(5, 4))
    deliver(h, PASSES, env(4, 3)) // also in the answer: a duplicate once the answer is applied
    await settle()
    expect(seen).toEqual([])

    h.http.answerWith(() => ({ channels: [ok(PASSES, [env(3, 2), env(4, 3)], 4)], relay: { ok: true, lag_ms: 5 } }))
    await advance(BATCH_MS)

    expect(h.http.catchUps).toEqual([{ channels: [{ name: PASSES, e: 2, ep: { e: EP } }] }])
    expect(seen).toEqual(['catch-up:3', 'catch-up:4', 'socket:5'])
    expect(stateOf(PASSES)).toBe('live')
    expect(cursorOf(PASSES)).toBe(5)
  })

  it('the cursor is saved on the phone for this principal, and a cold start catches up from it', async () => {
    let h = await startHarness()
    record()
    h.http.answerWith(() => ({ channels: [ok(PASSES, [env(8, 0)], 8)] }))
    await open(h)
    expect(savedCursors.load('g-5')).toEqual({ [PASSES]: { e: { q: 8, ep: EP } } })

    resetLiveKeepingCursors()
    h = await startHarness()
    await open(h)
    expect(h.http.catchUps[0]).toEqual({ channels: [{ name: PASSES, e: 8, ep: { e: EP } }] })
  })

  it('one event carried by two of this principal’s channels reaches the handlers once', async () => {
    const h = await startHarness({ config: liveConfig([{ name: PASSES }, { name: ALERTS }]) })
    const seen = record()
    h.http.answerWith((body) => ({ channels: body.channels.map((c) => ok(c.name, [], 10)) }))
    await open(h)

    deliver(h, PASSES, env(11, 10))
    deliver(h, ALERTS, env(11, 10))
    await settle()
    expect(seen).toEqual(['socket:11'])
  })
})

describe('the rule for each message on a live channel (§5.3)', () => {
  async function liveAt(q: number) {
    const h = await startHarness()
    const seen = record()
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], q)] }))
    await open(h)
    expect(stateOf(PASSES)).toBe('live')
    return { h, seen }
  }

  it('a duplicate is dropped', async () => {
    const { h, seen } = await liveAt(5)
    deliver(h, PASSES, env(5, 4))
    deliver(h, PASSES, env(3, 1))
    await settle()
    expect(seen).toEqual([])
    expect(cursorOf(PASSES)).toBe(5)
  })

  it('p > cursor is a gap: the channel catches up 300 ms later, from its cursor, and nothing skips ahead', async () => {
    const { h, seen } = await liveAt(5)
    deliver(h, PASSES, env(9, 8))
    await settle()
    expect(seen).toEqual([])
    expect(stateOf(PASSES)).toBe('catching')
    expect(h.http.catchUps).toHaveLength(1)

    h.http.answerWith(() => ({ channels: [ok(PASSES, [env(7, 5), env(9, 7)], 9)] }))
    await advance(GAP_DELAY_MS - 1)
    expect(h.http.catchUps).toHaveLength(1)
    await advance(1)
    expect(h.http.catchUps[1]).toEqual({ channels: [{ name: PASSES, e: 5, ep: { e: EP } }] })
    expect(seen).toEqual(['catch-up:7', 'catch-up:9'])
    expect(stateOf(PASSES)).toBe('live')
    expect(cursorOf(PASSES)).toBe(9)
  })

  it('p ≠ cursor is NOT a gap when p is behind it (a cursor seeded from a head)', async () => {
    const { h, seen } = await liveAt(5)
    deliver(h, PASSES, env(6, 2))
    await settle()
    expect(seen).toEqual(['socket:6'])
  })

  it('a message from another epoch resyncs the screens once, then applies', async () => {
    const { h, seen } = await liveAt(5)
    const resync = jest.spyOn(rtk, 'resyncMounted')
    deliver(h, PASSES, env(1, 0, { ep: '01JB0000' }))
    await settle()
    expect(resync).toHaveBeenCalledTimes(1)
    expect(seen).toEqual(['socket:1'])
    expect(liveChannels()[0]?.cursors.e).toBe(1)
    resync.mockRestore()
  })

  it('a live.nudge asks for one catch-up of that channel, 300 ms later, however many arrive', async () => {
    const { h } = await liveAt(5)
    deliver(h, PASSES, { t: 'live.nudge', q: null })
    deliver(h, PASSES, { t: 'live.nudge', q: null })
    await advance(GAP_DELAY_MS)
    expect(h.http.catchUps).toHaveLength(2)
    expect(h.http.catchUps[1]?.channels.map((c) => c.name)).toEqual([PASSES])
  })
})

describe('reset (§5.5)', () => {
  it('a reset takes the head and the server’s epoch, refetches the mounted screens ONCE for every channel it hits, and revalidates the stores those channels feed', async () => {
    savedCursors.save('g-5', { [PASSES]: { e: { q: 2, ep: EP } }, [ALERTS]: { e: { q: 3, ep: EP } } })
    const h = await startHarness({ config: liveConfig([{ name: PASSES }, { name: ALERTS }]) })
    const resync = jest.spyOn(rtk, 'resyncMounted')
    const revalidated: string[] = []
    registerDurableStore({ name: 'gate.revoked', feeds: (c) => c === PASSES, revalidate: (ctx) => void revalidated.push(`${ctx.reason}:${ctx.channel}:${ctx.head?.e}`) })
    await settle()
    revalidated.length = 0 // the start's own run, which durable.test covers

    h.http.answerWith((body) => ({ channels: body.channels.map((c) => ({ name: c.name, status: 'reset', head: { e: 900 }, ep: { e: '01JB0000' } })) }))
    await open(h)

    expect(resync).toHaveBeenCalledTimes(1)
    expect(revalidated).toEqual(['reset:estate.pp.passes:900'])
    expect(savedCursors.load('g-5')).toEqual({ [PASSES]: { e: { q: 900, ep: '01JB0000' } }, [ALERTS]: { e: { q: 900, ep: '01JB0000' } } })
    resync.mockRestore()
  })

  it('a channel with no cursor starts at the head and refetches once, but the durable stores are not revalidated for that', async () => {
    const h = await startHarness()
    const resync = jest.spyOn(rtk, 'resyncMounted')
    const revalidate = jest.fn()
    registerDurableStore({ name: 'kept', revalidate })
    await settle()
    revalidate.mockClear()

    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 70)] }))
    await open(h)
    expect(h.http.catchUps[0]).toEqual({ channels: [{ name: PASSES, e: null }] })
    expect(resync).toHaveBeenCalledTimes(1)
    expect(revalidate).not.toHaveBeenCalled()
    expect(cursorOf(PASSES)).toBe(70)
    resync.mockRestore()
  })

  it('the epoch an answer names goes with the cursor, so the next request lets the server check it', async () => {
    const h = await startHarness()
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 70)] }))
    await open(h)
    nudgeLive()
    await advance(0)
    expect(h.http.catchUps[1]).toEqual({ channels: [{ name: PASSES, e: 70, ep: { e: EP } }] })
  })
})

describe('the first cursor is the lowest X-Live-Head (§5.2)', () => {
  it('across every answer received before the socket connected', async () => {
    recordLiveHead('c=10;e=50')
    recordLiveHead('e=40')
    recordLiveHead('e=45;c=12')
    recordLiveHead('garbage')
    const h = await startHarness()
    await open(h)
    expect(h.http.catchUps[0]).toEqual({ channels: [{ name: PASSES, e: 40 }] })
  })

  it('and after a connect, what was fetched before it stays the floor until the next one', () => {
    recordLiveHead('e=40')
    markConnected()
    recordLiveHead('e=60')
    expect(require('../cursors').firstHeads()).toEqual({ e: 40 })
    markConnected()
    expect(require('../cursors').firstHeads()).toEqual({ e: 60 })
  })
})

describe('what a handler is given', () => {
  it('a socket message passes the app’s allowlist before any handler sees it: a key it does not list never arrives', async () => {
    const got: LiveEnvelope[] = []
    const h = await startHarness({ options: { allowlist: envelopeOnly } })
    onLiveEvent('pass.cancelled', (e) => void got.push(e))
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 1)] }))
    await open(h)

    deliver(h, PASSES, env(2, 1, { d: { guest: 'Marcia Brown', plate: '5544 KT' }, secret: 'x' }))
    await settle()
    expect(got).toHaveLength(1)
    expect(got[0]).not.toHaveProperty('d')
    expect(got[0]).not.toHaveProperty('secret')
  })

  it('the same allowlist is applied to a catch-up answer', async () => {
    const seen: unknown[] = []
    const h = await startHarness({ options: { allowlist: (a) => (seen.push(a), envelopeOnly(a)) } })
    const got: LiveEnvelope[] = []
    onLiveEvent('pass.cancelled', (e) => void got.push(e))
    h.http.answerWith(() => ({ channels: [ok(PASSES, [env(1, 0, { d: { guest: 'Marcia Brown' } })], 1)] }))
    await open(h)
    expect(seen).toHaveLength(1)
    expect(got[0]).not.toHaveProperty('d')
  })

  it('this install’s own write is not handed to a handler unless it asks: the outbox’s confirmation is the only path for it', async () => {
    const h = await startHarness()
    const plain: number[] = []
    const own: number[] = []
    onLiveEvent('pass.cancelled', (e) => void plain.push(e.q!))
    onLiveEvent('pass.cancelled', (e, ctx) => void (ctx.own && own.push(e.q!)), { includeOwn: true })
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 1)] }))
    await open(h)

    deliver(h, PASSES, env(2, 1, { o: newOrigin() }))
    deliver(h, PASSES, env(3, 2, { o: 'aaaaaaaaaaaaaaaa' }))
    await settle()
    expect(plain).toEqual([3])
    expect(own).toEqual([2])
  })

  it('a handler that throws does not stop the others, nor the cursor', async () => {
    const h = await startHarness()
    const after: number[] = []
    onLiveEvent('pass.cancelled', () => {
      throw new Error('boom')
    })
    onLiveEvent('pass.cancelled', (e) => void after.push(e.q!))
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 1)] }))
    await open(h)
    const quiet = jest.spyOn(console, 'error').mockImplementation(() => undefined)
    deliver(h, PASSES, env(2, 1))
    await settle()
    quiet.mockRestore()
    expect(after).toEqual([2])
    expect(cursorOf(PASSES)).toBe(2)
  })

  it('a slow handler holds up its own channel’s next event, and the cursor moves only after it finished', async () => {
    const h = await startHarness()
    let release: () => void = () => undefined
    const order: string[] = []
    onLiveEvent('pass.cancelled', async (e) => {
      order.push(`start ${e.q}`)
      if (e.q === 2) await new Promise<void>((r) => (release = r))
      order.push(`end ${e.q}`)
    })
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 1)] }))
    await open(h)

    deliver(h, PASSES, env(2, 1))
    deliver(h, PASSES, env(3, 2))
    await settle()
    expect(order).toEqual(['start 2'])
    expect(cursorOf(PASSES)).toBe(1)
    release()
    await settle()
    expect(order).toEqual(['start 2', 'end 2', 'start 3', 'end 3'])
    expect(cursorOf(PASSES)).toBe(3)
  })
})

describe('refused, and a permission change', () => {
  it('a channel refused in the answer stops: no handler, not asked for again', async () => {
    const h = await startHarness({ config: liveConfig([{ name: PASSES }, { name: ALERTS }]) })
    h.http.answerWith((body) => ({ channels: body.channels.map((c) => (c.name === ALERTS ? { name: ALERTS, status: 'refused' } : ok(c.name, [], 1))) }))
    await open(h)
    expect(stateOf(ALERTS)).toBe('refused')
    nudgeLive()
    await advance(0)
    expect(h.http.catchUps[1]?.channels.map((c) => c.name)).toEqual([PASSES])
  })

  it('access.changed reaches onAccessChanged, own or not, and a reduced grant fetches live.config again', async () => {
    const changes: string[] = []
    const h = await startHarness({ options: { onAccessChanged: (c) => void changes.push(`${c.effect}@${c.channel}`) } })
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 1)] }))
    await open(h)
    const before = h.http.configs

    deliver(h, PASSES, env(2, 1, { t: 'access.changed', e: 'principal', i: '5', sum: { effect: 'reduced' }, o: newOrigin() }))
    await settle()
    expect(changes).toEqual([`reduced@${PASSES}`])
    expect(h.http.configs).toBe(before + 1)
  })

  it('an ended grant does not fetch live.config: the next request ends the session', async () => {
    const changes: string[] = []
    const h = await startHarness({ options: { onAccessChanged: (c) => void changes.push(c.effect) } })
    h.http.answerWith(() => ({ channels: [ok(PASSES, [], 1)] }))
    await open(h)
    const before = h.http.configs
    deliver(h, PASSES, env(2, 1, { t: 'access.changed', e: 'principal', i: '5', sum: { effect: 'ended' } }))
    await settle()
    expect(changes).toEqual(['ended'])
    expect(h.http.configs).toBe(before)
  })
})

describe('the cursors on the phone', () => {
  it('writing one principal’s drops any other’s: a phone that changed hands keeps nobody’s place', () => {
    savedCursors.save('g-5', { [PASSES]: { e: { q: 3, ep: EP } } })
    savedCursors.save('g-9', { [PASSES]: { e: { q: 7, ep: EP } } })
    expect(savedCursors.load('g-5')).toEqual({})
    expect(savedCursors.load('g-9')).toEqual({ [PASSES]: { e: { q: 7, ep: EP } } })
  })

  it('a malformed cursor on disk is dropped, never trusted', () => {
    getStorage().set(CURSORS_STORAGE_KEY, JSON.stringify({ 'g-5': { [PASSES]: { e: { q: -1, ep: EP }, c: { q: 4, ep: 7 } }, other: { e: { q: 2, ep: null } } } }))
    expect(savedCursors.load('g-5')).toEqual({ other: { e: { q: 2, ep: null } } })
  })

  it.each(['signed_out', 'revoked', 'expired'] as const)('clearEverything (%s) removes them, and stops live so nothing writes them back', async (reason) => {
    const h = await startHarness()
    let answer: (v: unknown) => void = () => undefined
    h.http.answerWith(() => new Promise((r) => (answer = r)))
    h.sockets.last.events.opened()
    h.sockets.last.events.subscribed(PASSES)
    await advance(BATCH_MS)
    savedCursors.save('g-5', { [PASSES]: { e: { q: 3, ep: EP } } })
    expect(getStorage().keys()).toContain(CURSORS_STORAGE_KEY)

    await clearEverything('guard', h.store.dispatch, reason)
    expect(getStorage().keys()).not.toContain(CURSORS_STORAGE_KEY)

    // The answer that was on its way lands after the sign-out: it must not put the last person's place back.
    answer({ channels: [ok(PASSES, [env(4, 3)], 4)] })
    await settle()
    expect(getStorage().keys()).not.toContain(CURSORS_STORAGE_KEY)
  })
})

describe('the scheduler: one request for many channels, never two for one', () => {
  function scheduler(post: (body: CatchUpRequest) => Promise<unknown>, names: string[]) {
    const syncs = new Map(
      names.map((name) => [
        name,
        { name, state: 'catching', generation: 1, request: () => ({ name, e: 1 }), caughtUp: jest.fn(async () => false) },
      ]),
    )
    const outcomes: Array<{ ok: boolean; names: string[] }> = []
    const s = new CatchUpScheduler({
      post,
      allowlist: () => (a) => a,
      channel: (n) => syncs.get(n) as never,
      outcome: ({ ok: fine, names: which }) => void outcomes.push({ ok: fine, names: which }),
      retryIn: () => 1000,
    })
    return { s, syncs, outcomes }
  }
  const answerAll = async (body: CatchUpRequest) => ({ channels: body.channels.map((c) => ok(c.name, [], 1)) })

  it('everything asked for within 50 ms goes in one POST', async () => {
    const bodies: CatchUpRequest[] = []
    const { s } = scheduler(async (b) => (bodies.push(b), answerAll(b)), ['a.x.one', 'a.x.two'])
    s.request(['a.x.one'])
    await advance(20)
    s.request(['a.x.two'])
    await advance(BATCH_MS)
    expect(bodies.map((b) => b.channels.map((c) => c.name))).toEqual([['a.x.one', 'a.x.two']])
    s.stop()
  })

  it('a channel asked for again while its answer is on the way is asked again once it lands, not twice at once', async () => {
    const bodies: string[][] = []
    let land: () => void = () => undefined
    const { s } = scheduler(async (b) => {
      bodies.push(b.channels.map((c) => c.name))
      if (bodies.length === 1) await new Promise<void>((r) => (land = r))
      return answerAll(b)
    }, ['a.x.one'])
    s.request(['a.x.one'], 0)
    await advance(0)
    s.request(['a.x.one'], 0)
    await advance(10)
    expect(bodies).toHaveLength(1)
    land()
    await settle()
    await advance(0)
    expect(bodies).toEqual([['a.x.one'], ['a.x.one']])
    s.stop()
  })

  it('at most 200 channels a request, and when two requests fail, BOTH are retried', async () => {
    const names = Array.from({ length: MAX_CHANNELS_PER_REQUEST + 5 }, (_, i) => `a.x.c${i}`)
    const bodies: string[][] = []
    let failing = true
    const { s } = scheduler(async (b) => {
      bodies.push(b.channels.map((c) => c.name))
      if (failing) throw new Error('no signal')
      return answerAll(b)
    }, names)
    s.request(names, 0)
    await advance(0)
    expect(bodies.map((b) => b.length)).toEqual([MAX_CHANNELS_PER_REQUEST, 5])

    failing = false
    bodies.length = 0
    await advance(1000)
    expect(new Set(bodies.flat())).toEqual(new Set(names))
    s.stop()
  })

  it('a channel the answer leaves out is asked for again, and the round counts as failed', async () => {
    const bodies: string[][] = []
    const { s, outcomes } = scheduler(async (b) => {
      bodies.push(b.channels.map((c) => c.name))
      return { channels: b.channels.filter((c) => c.name !== 'a.x.two').map((c) => ok(c.name, [], 1)) }
    }, ['a.x.one', 'a.x.two'])
    s.request(['a.x.one', 'a.x.two'], 0)
    await advance(0)
    expect(outcomes[0]?.ok).toBe(false)
    await advance(1000)
    expect(bodies[1]).toEqual(['a.x.two'])
    s.stop()
  })

  it('a channel dropped before its turn is forgotten: the scheduler does not stay busy for ever', async () => {
    const { s, syncs } = scheduler(answerAll, ['a.x.one'])
    s.request(['a.x.one'])
    syncs.delete('a.x.one')
    await advance(BATCH_MS)
    expect(s.busy).toBe(false)
    s.stop()
  })
})

/** Ends live and forgets what it held in memory, but leaves what is on disk — a cold start. */
function resetLiveKeepingCursors(): void {
  const kept = savedCursors.load('g-5')
  resetLive()
  savedCursors.save('g-5', kept)
}

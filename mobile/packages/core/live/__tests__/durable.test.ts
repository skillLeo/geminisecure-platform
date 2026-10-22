/**
 * THE STORES THAT OUTLIVE THE APP (DESIGN §5.2, §5.5, C6).
 *
 * A handler that writes to disk and the cursor that says how far it got are two writes; a crash between them can
 * leave the cursor ahead of the store for ever. So each such store registers a revalidator, and it runs on boot,
 * after every sign-in and after a reset of a channel that feeds it — and NEVER on an ordinary event.
 */

import { startLive, stopLive } from '../client'
import { onLiveEvent } from '../configure'
import { savedCursors } from '../cursors'
import { durableStoreNames, registerDurableStore, revalidateDurableStores, type RevalidateContext } from '../durable'
import { EP, PASSES, advance, deliver, env, liveConfig, open, resetLive, settle, setOnline, startHarness } from './fakes'

const ALERTS = 'estate.pp.alerts'

function recorder(name: string, over: Partial<Parameters<typeof registerDurableStore>[0]> = {}) {
  const runs: string[] = []
  registerDurableStore({ name, revalidate: (ctx: RevalidateContext) => void runs.push(ctx.reason === 'start' ? 'start' : `reset:${ctx.channel}:${ctx.head?.e}`), ...over })
  return runs
}

beforeEach(() => {
  jest.useFakeTimers()
})

afterEach(() => {
  resetLive()
  jest.useRealTimers()
})

describe('when a revalidator runs', () => {
  it('on boot, once the phone is in the foreground and online', async () => {
    const runs = recorder('alerts.kept')
    await startHarness()
    expect(runs).toEqual(['start'])
  })

  it('not while the phone is offline: it waits for the signal, then runs once', async () => {
    const runs = recorder('alerts.kept')
    const h = await startHarness({ online: false })
    expect(runs).toEqual([])
    h.store.dispatch(setOnline(true))
    await settle()
    h.store.dispatch(setOnline(false))
    h.store.dispatch(setOnline(true))
    await settle()
    expect(runs).toEqual(['start'])
  })

  it('after every sign-in: a new session is a new start', async () => {
    const runs = recorder('alerts.kept')
    await startHarness()
    stopLive() // signed out
    startLive() // signed in again, the app still running
    await settle()
    expect(runs).toEqual(['start', 'start'])
  })

  it('after a reset of a channel that feeds it, with the head to save, and not after a reset of one that does not', async () => {
    savedCursors.save('g-5', { [PASSES]: { e: { q: 2, ep: EP } }, [ALERTS]: { e: { q: 2, ep: EP } } })
    const passes = recorder('gate.revoked', { feeds: (c) => c === PASSES })
    const every = recorder('everything')
    const h = await startHarness({ config: liveConfig([{ name: PASSES }, { name: ALERTS }]) })
    h.http.answerWith((body) => ({ channels: body.channels.map((c) => (c.name === ALERTS ? { name: ALERTS, status: 'reset', head: { e: 400 }, ep: { e: EP } } : { name: c.name, status: 'ok', events: [], head: { e: 2 }, ep: { e: EP } })) }))
    await open(h)
    expect(passes).toEqual(['start'])
    expect(every).toEqual(['start', `reset:${ALERTS}:400`])
  })

  it('after an epoch change seen on the socket: the database behind the stream was rebuilt', async () => {
    const runs = recorder('alerts.kept')
    const h = await startHarness()
    h.http.answerWith(() => ({ channels: [{ name: PASSES, status: 'ok', events: [], head: { e: 5 }, ep: { e: EP } }] }))
    await open(h)
    deliver(h, PASSES, env(1, 0, { ep: '01JB0000' }))
    await settle()
    expect(runs).toEqual(['start', `reset:${PASSES}:1`])
  })

  it('NEVER on an ordinary event: that is what the handlers are for', async () => {
    const runs = recorder('alerts.kept')
    const handled: number[] = []
    onLiveEvent('pass.*', (e) => void handled.push(e.q!))
    const h = await startHarness()
    h.http.answerWith(() => ({ channels: [{ name: PASSES, status: 'ok', events: [env(1, 0)], head: { e: 1 }, ep: { e: EP } }] }))
    await open(h)
    deliver(h, PASSES, env(2, 1))
    deliver(h, PASSES, env(3, 2))
    await advance(10 * 60_000) // a safety catch-up in there too
    expect(handled).toEqual([1, 2, 3])
    expect(runs).toEqual(['start'])
  })
})

describe('what a revalidator can rely on', () => {
  it('one that fails does not stop the rest, and nothing about it is logged', async () => {
    const log = jest.spyOn(console, 'error').mockImplementation(() => undefined)
    registerDurableStore({ name: 'a.broken', revalidate: () => Promise.reject(new Error('disk full: Marcia Brown')) })
    const runs = recorder('b.fine')
    const ran = await revalidateDurableStores({ reason: 'start' })
    expect(ran).toEqual(['a.broken', 'b.fine'])
    expect(runs).toEqual(['start'])
    expect(log).not.toHaveBeenCalled()
    log.mockRestore()
  })

  it('registering a name again replaces it; the old unregister does not remove the new one', () => {
    const offOld = registerDurableStore({ name: 'kept', revalidate: () => undefined })
    registerDurableStore({ name: 'kept', revalidate: () => undefined })
    offOld()
    expect(durableStoreNames()).toEqual(['kept'])
  })

  it('a store that keeps its own cursor (the gate’s revoked list) is where its channel starts, so live can never be ahead of what was saved', async () => {
    savedCursors.save('g-5', { [PASSES]: { e: { q: 90, ep: EP } } }) // live's own place: AHEAD of the store
    registerDurableStore({ name: 'gate.revoked', feeds: (c) => c === PASSES, cursor: () => ({ e: { q: 40, ep: EP } }), revalidate: () => undefined })
    const h = await startHarness()
    await open(h)
    expect(h.http.catchUps[0]).toEqual({ channels: [{ name: PASSES, e: 40, ep: { e: EP } }] })
  })

  it('a store whose cursor cannot be read is treated as keeping none', async () => {
    registerDurableStore({ name: 'gate.revoked', cursor: () => { throw new Error('corrupt') }, revalidate: () => undefined })
    savedCursors.save('g-5', { [PASSES]: { e: { q: 12, ep: EP } } })
    const h = await startHarness()
    await open(h)
    expect(h.http.catchUps[0]).toEqual({ channels: [{ name: PASSES, e: 12, ep: { e: EP } }] })
  })
})

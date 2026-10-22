/**
 * LIVE â†’ THE RTK QUERY CACHE (DESIGN Â§8.4, C3): change what is cached in place, or refetch one record â€” never a
 * whole list, never a reload. Against a real store and real cache entries.
 */

import { baseApi } from '../../api/baseApi'
import { residentApi } from '../../api/generated/residentApi'
import { bindLiveStore, invalidateRecord, patchEverywhere, perRecord, resyncMounted } from '../rtk'
import { makeStore, settle } from './fakes'

type Pass = { id: number | string; status: string; guest: string; note?: string }

let fetches: Record<string, number> = {}
const probe = baseApi.injectEndpoints({
  endpoints: (build) => ({
    liveProbeRecord: build.query<Pass, number>({
      queryFn: (id) => {
        fetches[`record:${id}`] = (fetches[`record:${id}`] ?? 0) + 1
        return { data: { id, status: `fetch ${fetches[`record:${id}`]}`, guest: 'Marcia Brown' } }
      },
      providesTags: (_r, _e, id) => [{ type: 'passes', id: String(id) }],
    }),
    liveProbeTickets: build.query<{ items: Array<{ id: number }> }, void>({
      queryFn: () => {
        fetches.tickets = (fetches.tickets ?? 0) + 1
        return { data: { items: [{ id: 1 }] } }
      },
      providesTags: [{ type: 'tickets', id: 'LIST' }],
    }),
  }),
  overrideExisting: true,
})

/*
 * The notifier and the screen ask for visitor passes with different arguments. `undefined` and `{}` say nothing and
 * share ONE entry since the cache-key fix (api/baseApi.ts `saysNothing`); `{status: 'active'}` is its own. Two entries,
 * three ways to ask â€” and every one of them must read the patch.
 */
const ARGS = [undefined, {}, { status: 'active' }] as const
const ENTRIES = 2
const rows = (): Pass[] => [
  { id: 7, status: 'active', guest: 'Marcia Brown' },
  { id: 8, status: 'active', guest: 'Dwayne Reid' },
]

// Real stores leave RTK's timers behind (keepUnusedDataFor, auto-batching): fake, and dropped after each test.
const stores: Array<ReturnType<typeof makeStore>> = []
function storeFor() {
  const made = makeStore()
  stores.push(made)
  bindLiveStore(made)
  return made
}

function seededStore() {
  const made = storeFor()
  for (const arg of ARGS) made.dispatch(residentApi.util.upsertQueryData('visitorPassesIndex', arg as never, { items: rows() } as never) as never)
  return made
}

const cached = (s: ReturnType<typeof makeStore>, arg: unknown) => residentApi.endpoints.visitorPassesIndex.select(arg as never)(s.getState() as never).data as unknown as { items: Pass[] } | undefined

beforeEach(() => {
  jest.useFakeTimers({ doNotFake: ['nextTick', 'queueMicrotask', 'setImmediate'] })
  fetches = {}
})

afterEach(() => {
  for (const s of stores.splice(0)) s.dispatch(baseApi.util.resetApiState())
  jest.clearAllTimers()
  jest.useRealTimers()
})

describe('patchEverywhere', () => {
  it('patches every cached argument of visitorPassesIndex â€” undefined, {} and {status: "active"} â€” not only the first', async () => {
    const store = seededStore()
    await settle()
    const changed = patchEverywhere('visitorPassesIndex', 7, { status: 'cancelled' })
    expect(changed).toBe(ENTRIES)
    for (const arg of ARGS) {
      expect(cached(store, arg)?.items.find((p) => p.id === 7)).toEqual({ id: 7, status: 'cancelled', guest: 'Marcia Brown' })
      expect(cached(store, arg)?.items.find((p) => p.id === 8)?.status).toBe('active')
    }
  })

  it('merges into the row: the fields the event does not carry are kept', async () => {
    const store = seededStore()
    await settle()
    patchEverywhere('visitorPassesIndex', '7', { note: 'at the gate' })
    expect(cached(store, undefined)?.items[0]).toEqual({ id: 7, status: 'active', guest: 'Marcia Brown', note: 'at the gate' })
  })

  it('null removes the row from every list', async () => {
    const store = seededStore()
    await settle()
    expect(patchEverywhere('visitorPassesIndex', 8, null)).toBe(ENTRIES)
    for (const arg of ARGS) expect(cached(store, arg)?.items.map((p) => p.id)).toEqual([7])
  })

  it('never ADDS a row â€” whether a new one belongs under a listâ€™s filter is the serverâ€™s call', async () => {
    const store = seededStore()
    await settle()
    expect(patchEverywhere('visitorPassesIndex', 99, { status: 'active', guest: 'New Guest' })).toBe(0)
    for (const arg of ARGS) expect(cached(store, arg)?.items.map((p) => p.id)).toEqual([7, 8])
  })

  it('a single record is merged when it is that one, and left alone when it is not', async () => {
    const store = storeFor()
    const sub = store.dispatch(probe.endpoints.liveProbeRecord.initiate(7))
    await sub
    expect(patchEverywhere('liveProbeRecord' as never, 7, { status: 'expired' })).toBe(1)
    expect(patchEverywhere('liveProbeRecord' as never, 8, { status: 'expired' })).toBe(0)
    expect(probe.endpoints.liveProbeRecord.select(7)(store.getState() as never).data?.status).toBe('expired')
    sub.unsubscribe()
  })
})

describe('invalidateRecord and resyncMounted', () => {
  it('invalidateRecord refetches the one record, and nothing else', async () => {
    const store = storeFor()
    const a = store.dispatch(probe.endpoints.liveProbeRecord.initiate(7))
    const b = store.dispatch(probe.endpoints.liveProbeRecord.initiate(8))
    await Promise.all([a, b])
    invalidateRecord('passes', 7)
    await settle()
    expect(fetches).toEqual({ 'record:7': 2, 'record:8': 1 })
    a.unsubscribe()
    b.unsubscribe()
  })

  it('a reset invalidates every tag a cached read provides in ONE dispatch: what a screen shows is refetched once', async () => {
    const store = storeFor()
    const a = store.dispatch(probe.endpoints.liveProbeRecord.initiate(7))
    const t = store.dispatch(probe.endpoints.liveProbeTickets.initiate())
    await Promise.all([a, t])
    const dispatch = jest.spyOn(store, 'dispatch')
    const tags = resyncMounted()
    await settle()
    expect(tags).toBe(2)
    expect(dispatch.mock.calls.filter(([action]) => (action as { type?: string }).type?.endsWith('invalidateTags'))).toHaveLength(1)
    expect(fetches).toEqual({ 'record:7': 2, tickets: 2 })
    dispatch.mockRestore()
    a.unsubscribe()
    t.unsubscribe()
  })

  it('what no screen shows is dropped, so nothing stale is shown when it next mounts', async () => {
    const store = storeFor()
    const a = store.dispatch(probe.endpoints.liveProbeRecord.initiate(7))
    await a
    a.unsubscribe()
    resyncMounted()
    await settle()
    expect(fetches).toEqual({ 'record:7': 1 })
    expect(probe.endpoints.liveProbeRecord.select(7)(store.getState() as never).data).toBeUndefined()
  })

  it('nothing cached, nothing dispatched', () => {
    const store = storeFor()
    const dispatch = jest.spyOn(store, 'dispatch')
    expect(resyncMounted()).toBe(0)
    expect(dispatch).not.toHaveBeenCalled()
  })
})

describe('perRecord: one refetch per record at a time (Â§2.2)', () => {
  it('asked again while it runs, it runs ONCE more afterwards â€” however many times it was asked', async () => {
    let running = 0
    let most = 0
    let runs = 0
    const release: Array<() => void> = []
    const run = () =>
      new Promise<void>((r) => {
        runs += 1
        running += 1
        most = Math.max(most, running)
        release.push(() => {
          running -= 1
          r()
        })
      })
    const first = perRecord('pass:7', run)
    void perRecord('pass:7', run)
    void perRecord('pass:7', run)
    await settle()
    expect(runs).toBe(1)
    release.shift()!()
    await settle()
    expect(runs).toBe(2)
    release.shift()!()
    await first
    expect(runs).toBe(2)
    expect(most).toBe(1)
  })

  it('different records do not wait for each other; a run that fails does not stop the next', async () => {
    const order: string[] = []
    await Promise.all([
      perRecord('pass:1', async () => {
        order.push('1')
        throw new Error('network')
      }),
      perRecord('pass:2', async () => void order.push('2')),
    ])
    await perRecord('pass:1', async () => void order.push('1 again'))
    expect(order).toEqual(['1', '2', '1 again'])
  })
})

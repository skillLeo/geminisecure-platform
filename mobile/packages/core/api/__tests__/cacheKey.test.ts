/**
 * ONE QUESTION, ONE CACHE ENTRY, ONE REQUEST (M11 A4).
 *
 * Every generated query reads its argument as `arg ?? {}`, so `undefined`, `null` and `{}` ask the server
 * exactly the same thing. RTK Query's default key told `undefined` from `{}`, so the Resident notifier's
 * `visitor_passes.index(undefined)` and the Visitors screen's `visitor_passes.index({})` were two cache
 * entries — and the same GET went out twice, and was polled twice. Proved here on a real store:
 *   - the four ways of saying nothing share ONE entry and send ONE request;
 *   - an argument that does say something is still its own entry, and still its own request;
 *   - every generated query really does read `arg ?? {}` — the premise the key relies on.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { tokenStorage } from '../../auth/tokenStorage'
import { baseApi, saysNothing, serializeQueryArgs } from '../baseApi'
import { configureApi } from '../baseQuery'
import { createCoreStore } from '../store'

process.env.EXPO_PUBLIC_API_URL = 'http://192.168.18.119:8000/api/v1/'

type Arg = { status?: 'active' | 'used' } | void | null
const api = baseApi.injectEndpoints({
  endpoints: (build) => ({
    probePasses: build.query<{ items: unknown[] }, Arg>({
      query: (arg) => {
        const a = (arg ?? {}) as Record<string, unknown>
        return { url: '/visitor-passes', params: a.status ? { status: a.status } : {} }
      },
    }),
  }),
  overrideExisting: true,
})

let urls: string[] = []
// Real requests through the real store leave timers behind: a released entry is kept for
// `keepUnusedDataFor` (300 s), every request carries fetchBaseQuery's abort timeout (never cleared), and the
// store tells its subscribers on the next animation frame (RTK's auto-batching, a timer in React Native's
// Jest setup). They run on fake timers here and are dropped when each test ends, instead of firing after the
// test environment is gone. Promises, nextTick and setImmediate stay real, so every request still answers.
const stores: ReturnType<typeof createCoreStore>[] = []
const storeFor = () => {
  const store = createCoreStore()
  stores.push(store)
  return store
}
afterEach(() => {
  for (const store of stores.splice(0)) store.dispatch(api.util.resetApiState())
  jest.clearAllTimers()
  jest.useRealTimers()
})
beforeEach(async () => {
  jest.useFakeTimers({ doNotFake: ['nextTick', 'queueMicrotask', 'setImmediate'] })
  urls = []
  configureApi({ onSessionRevoked: jest.fn(), backoffMs: () => 0 })
  await tokenStorage.set('resident', 'tok_live')
  globalThis.fetch = jest.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const req = input instanceof Request ? input : new Request(input as string, init)
    // fetchBaseQuery writes an empty params object as a bare "?"; the request is the same one.
    urls.push(req.url.replace(/\?$/, ''))
    return new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  }) as unknown as typeof fetch
})

describe('an argument that says nothing is keyed as no argument', () => {
  it('undefined, null, {} and { status: undefined } say nothing; anything with a value says something', () => {
    expect(saysNothing(undefined)).toBe(true)
    expect(saysNothing(null)).toBe(true)
    expect(saysNothing({})).toBe(true)
    expect(saysNothing({ status: undefined })).toBe(true)
    expect(saysNothing({ status: 'active' })).toBe(false)
    expect(saysNothing({ household: '' })).toBe(false)
    expect(saysNothing({ page: 0 })).toBe(false)
    expect(saysNothing([])).toBe(false)
  })

  it('the key for all four is the key for no argument; a real argument keeps its own', () => {
    const endpointDefinition = api.endpoints.probePasses as never
    const key = (queryArgs: unknown) => serializeQueryArgs({ queryArgs, endpointName: 'probePasses', endpointDefinition })
    const none = key(undefined)
    for (const same of [null, {}, { status: undefined }]) expect(key(same)).toBe(none)
    expect(key({ status: 'active' })).not.toBe(none)
    expect(key({ status: 'active' })).toBe(key({ status: 'active' }))
  })

  it('on a real store: the notifier’s undefined and the screen’s {} are ONE request', async () => {
    const store = storeFor()
    const a = store.dispatch(api.endpoints.probePasses.initiate(undefined))
    const b = store.dispatch(api.endpoints.probePasses.initiate({}))
    const c = store.dispatch(api.endpoints.probePasses.initiate(null))
    await Promise.all([a, b, c])
    expect(urls).toEqual(['http://192.168.18.119:8000/api/v1/visitor-passes'])
    expect(Object.keys(store.getState().api.queries)).toHaveLength(1)
    a.unsubscribe()
    b.unsubscribe()
    c.unsubscribe()
  })

  it('a filtered list is still its own entry and its own request', async () => {
    const store = storeFor()
    const all = store.dispatch(api.endpoints.probePasses.initiate({}))
    const active = store.dispatch(api.endpoints.probePasses.initiate({ status: 'active' }))
    await Promise.all([all, active])
    expect(urls.sort()).toEqual(['http://192.168.18.119:8000/api/v1/visitor-passes', 'http://192.168.18.119:8000/api/v1/visitor-passes?status=active'])
    expect(Object.keys(store.getState().api.queries)).toHaveLength(2)
    all.unsubscribe()
    active.unsubscribe()
  })
})

describe('the premise: every generated query reads its argument as `arg ?? {}`', () => {
  it('holds for every query in both generated clients', () => {
    for (const file of ['guardApi.ts', 'residentApi.ts']) {
      const source = readFileSync(path.resolve(__dirname, '..', 'generated', file), 'utf8')
      const queries = source.split('build.query<').slice(1)
      expect(queries.length).toBeGreaterThan(10)
      for (const q of queries) expect(q.slice(0, q.indexOf('}),'))).toMatch(/query: \((_?arg)\) => \{ const a = \(\1 \?\? \{\}\)/)
    }
  })
})

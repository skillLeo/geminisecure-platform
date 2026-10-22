import { tokenStorage } from '../../auth/tokenStorage'
import { DEFAULT_SITE_ZONE, forgetSiteZone, siteZone } from '../../lib/siteTime'
import { firstHeads, forgetHeads } from '../../live/cursors'
import { forgetOrigins, isOwnOrigin } from '../../live/origin'
import { baseApi } from '../baseApi'
import { configureApi } from '../baseQuery'
import { createCoreStore } from '../store'
import { write } from '../write'

process.env.EXPO_PUBLIC_API_URL = 'http://192.168.18.119:8000/api/v1/'

const api = baseApi.injectEndpoints({
  endpoints: (build) => ({
    probeRead: build.query<unknown, void>({ query: () => '/shifts/me/current' }),
    probeWrite: build.mutation<unknown, { detail: string }>({ query: (body) => write('/incidents', body) }),
    probeNoRetry: build.mutation<unknown, void>({ query: () => write('/elections/1/ballot', { marks: [3] }), extraOptions: { maxRetries: 0 } }),
  }),
  overrideExisting: true,
})

type Call = { url: string; headers: Record<string, string>; body: string | null }
let calls: Call[] = []

function respondWith(sequence: Array<{ status: number; body: unknown } | 'network'>) {
  let i = 0
  globalThis.fetch = jest.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const req = input instanceof Request ? input : new Request(input as string, init)
    calls.push({ url: req.url, headers: Object.fromEntries(req.headers.entries()), body: req.body ? await req.clone().text() : null })
    const next = sequence[Math.min(i, sequence.length - 1)]
    i += 1
    if (next === 'network' || next === undefined) throw new TypeError('Network request failed')
    return new Response(JSON.stringify(next.body), { status: next.status, headers: { 'Content-Type': 'application/json' } })
  }) as unknown as typeof fetch
}

const onSessionRevoked = jest.fn()

beforeEach(async () => {
  calls = []
  onSessionRevoked.mockClear()
  configureApi({ onSessionRevoked, backoffMs: () => 0 })
  await tokenStorage.set('guard', 'tok_live')
})

describe('baseQuery', () => {
  it('prefixes the API root, asks for JSON and carries the Bearer token', async () => {
    respondWith([{ status: 200, body: { shift: null } }])
    const store = createCoreStore()
    const result = await store.dispatch(api.endpoints.probeRead.initiate())
    expect(result.data).toEqual({ shift: null })
    expect(calls[0]?.url).toBe('http://192.168.18.119:8000/api/v1/shifts/me/current')
    expect(calls[0]?.headers.authorization).toBe('Bearer tok_live')
    expect(calls[0]?.headers.accept).toBe('application/json')
  })

  /**
   * THE ONE CLOCK — the ONE place the site's zone reaches the phone. Every payload that carries it comes
   * through here, so a screen never has to remember to read it, and a change at the office lands on
   * the next read. A refusal carries no zone and must not move it.
   */
  it('takes the site’s zone off the answer, and only off an answer that has one', async () => {
    forgetSiteZone()
    expect(siteZone()).toBe(DEFAULT_SITE_ZONE)

    respondWith([{ status: 200, body: { shift: { id: 1, site: { timezone: 'America/New_York' } } } }])
    await createCoreStore().dispatch(api.endpoints.probeRead.initiate())
    expect(siteZone()).toBe('America/New_York')

    respondWith([{ status: 200, body: { shift: null } }])
    await createCoreStore().dispatch(api.endpoints.probeRead.initiate())
    expect(siteZone()).toBe('America/New_York')

    respondWith([{ status: 403, body: { error: { code: 'missing_ability', message: 'No.' } } }])
    await createCoreStore().dispatch(api.endpoints.probeRead.initiate())
    expect(siteZone()).toBe('America/New_York')
    forgetSiteZone()
  })

  it('sends no Authorization header when signed out', async () => {
    await tokenStorage.clear('guard')
    respondWith([{ status: 200, body: {} }])
    await createCoreStore().dispatch(api.endpoints.probeRead.initiate())
    expect(calls[0]?.headers.authorization).toBeUndefined()
  })

  it('a 401 ends the session, clears the caches, and is not retried', async () => {
    respondWith([{ status: 401, body: { error: { code: 'unauthenticated', message: 'This request needs a handset token.' } } }])
    const store = createCoreStore()
    const result = await store.dispatch(api.endpoints.probeRead.initiate())
    expect(result.error).toMatchObject({ status: 401, code: 'unauthenticated' })
    expect(calls).toHaveLength(1)
    expect(onSessionRevoked).toHaveBeenCalledTimes(1)
    expect(store.getState().session).toMatchObject({ status: 'signedOut', endedReason: 'revoked' })
  })

  // Found live by the profile review (2026-09-20): a deliberate sign-out empties the API cache, every screen still
  // mounted re-asks at once with NO token, and the 401s that come back were read as "the office revoked this phone".
  it('a 401 to a request sent with NO token revokes nothing: there is no session to revoke', async () => {
    await tokenStorage.clear('guard')
    respondWith([{ status: 401, body: { error: { code: 'unauthenticated', message: 'This request needs a handset token.' } } }])
    const store = createCoreStore()
    const result = await store.dispatch(api.endpoints.probeRead.initiate())
    expect(result.error).toMatchObject({ status: 401 })
    expect(onSessionRevoked).not.toHaveBeenCalled()
    expect(store.getState().session.endedReason).not.toBe('revoked')
  })

  it('a 401 that answers an OLD token never throws away the session that replaced it', async () => {
    // The request leaves with tok_live; before its answer lands, somebody signs in again on this phone.
    globalThis.fetch = jest.fn(async () => {
      await tokenStorage.set('guard', 'tok_new_session')
      return new Response(JSON.stringify({ error: { code: 'unauthenticated', message: 'Revoked.' } }), { status: 401, headers: { 'Content-Type': 'application/json' } })
    }) as unknown as typeof fetch
    const store = createCoreStore()
    await store.dispatch(api.endpoints.probeRead.initiate())
    expect(onSessionRevoked).not.toHaveBeenCalled()
    expect(tokenStorage.peek()).toBe('tok_new_session')
    expect(store.getState().session.endedReason).not.toBe('revoked')
  })

  /*
   * Q-M44 — the estate office ending a Resident App account. Just as final as a 401 and
   * no screen can recover from it: leaving the app "signed in" would be a home screen
   * that refuses every panel on it for ever, with no way out but reinstalling.
   */
  it('a 403 account_revoked ends the session too: the estate office cut this account off', async () => {
    respondWith([{ status: 403, body: { error: { code: 'account_revoked', message: 'The estate office has ended this account’s access. Use the Visitor Entrance.' } } }])
    const store = createCoreStore()
    const result = await store.dispatch(api.endpoints.probeRead.initiate())
    expect(result.error).toMatchObject({ status: 403, code: 'account_revoked' })
    expect(calls).toHaveLength(1)
    expect(onSessionRevoked).toHaveBeenCalledTimes(1)
    expect(store.getState().session).toMatchObject({ status: 'signedOut', endedReason: 'revoked' })
  })

  it('any OTHER 403 leaves the session alone: a missing ability is about one endpoint, not the account', async () => {
    respondWith([{ status: 403, body: { error: { code: 'missing_ability', message: 'Not on this handset.' } } }])
    const store = createCoreStore()
    await store.dispatch(api.endpoints.probeRead.initiate())
    expect(onSessionRevoked).not.toHaveBeenCalled()
    expect(store.getState().session.endedReason).not.toBe('revoked')
  })

  it('retries a 503, and every attempt carries the SAME Idempotency-Key and device time', async () => {
    respondWith([{ status: 503, body: {} }, { status: 503, body: {} }, { status: 201, body: { id: 9 } }])
    const result = await createCoreStore().dispatch(api.endpoints.probeWrite.initiate({ detail: 'Grey pickup, no plates' }))
    expect(result.data).toEqual({ id: 9 })
    expect(calls).toHaveLength(3)
    const keys = new Set(calls.map((c) => c.headers['idempotency-key']))
    const times = new Set(calls.map((c) => c.headers['x-device-time']))
    expect(keys.size).toBe(1)
    expect(times.size).toBe(1)
    expect([...keys][0]).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('retries a dropped connection, then gives up with a plain sentence', async () => {
    respondWith(['network'])
    const result = await createCoreStore().dispatch(api.endpoints.probeWrite.initiate({ detail: 'x' }))
    expect(calls).toHaveLength(3)
    expect(result.error).toMatchObject({ status: 'network', code: 'network_unreachable' })
  })

  it('never retries a refusal: a 422 is an answer', async () => {
    respondWith([{ status: 422, body: { error: { code: 'validation_failed', message: 'The detail field is required.' }, errors: { detail: ['The detail field is required.'] } } }])
    const result = await createCoreStore().dispatch(api.endpoints.probeWrite.initiate({ detail: '' }))
    expect(calls).toHaveLength(1)
    expect(result.error).toMatchObject({ code: 'validation_failed', fieldErrors: { detail: ['The detail field is required.'] } })
  })

  it('never retries the ballot, whatever happens (invariant 3)', async () => {
    respondWith([{ status: 503, body: {} }])
    await createCoreStore().dispatch(api.endpoints.probeNoRetry.initiate())
    expect(calls).toHaveLength(1)
  })
})

/*
 * LIVE (M11 Part C, DESIGN §5.2, §6.1): two headers, neither about a body. X-Live-Origin marks this install's own
 * writes so their live echo is recognised; X-Live-Head, read off every answer, is where a channel with no cursor
 * starts, so nothing fetched before the socket existed is skipped.
 */
describe('the live headers', () => {
  const liveApi = baseApi.injectEndpoints({
    endpoints: (build) => ({
      probeLiveAuth: build.mutation<unknown, void>({ query: () => ({ url: '/live/auth', method: 'POST', body: { socket_id: '1.2', channel_names: [] } }) }),
    }),
    overrideExisting: true,
  })

  beforeEach(() => {
    forgetOrigins()
    forgetHeads()
  })

  it('a write carries ONE X-Live-Origin nonce, the same on every retry of it, and this install knows it as its own', async () => {
    respondWith([{ status: 503, body: {} }, { status: 503, body: {} }, { status: 201, body: { id: 9 } }])
    await createCoreStore().dispatch(api.endpoints.probeWrite.initiate({ detail: 'Grey pickup, no plates' }))
    const nonces = new Set(calls.map((c) => c.headers['x-live-origin']))
    expect(calls).toHaveLength(3)
    expect(nonces.size).toBe(1)
    const [nonce] = [...nonces]
    expect(nonce).toMatch(/^[a-f0-9]{16}$/)
    expect(isOwnOrigin(nonce)).toBe(true)
  })

  it('two writes, two nonces: it is never an id for this handset', async () => {
    respondWith([{ status: 201, body: {} }])
    await createCoreStore().dispatch(api.endpoints.probeWrite.initiate({ detail: 'a' }))
    await createCoreStore().dispatch(api.endpoints.probeWrite.initiate({ detail: 'b' }))
    expect(calls[0]?.headers['x-live-origin']).not.toBe(calls[1]?.headers['x-live-origin'])
  })

  it('a read carries none, and neither do live’s own calls, which write no record', async () => {
    respondWith([{ status: 200, body: {} }])
    await createCoreStore().dispatch(api.endpoints.probeRead.initiate())
    await createCoreStore().dispatch(liveApi.endpoints.probeLiveAuth.initiate())
    expect(calls.map((c) => c.headers['x-live-origin'])).toEqual([undefined, undefined])
  })

  it('every answer’s X-Live-Head is kept, the lowest per stream: the first cursor of a channel that has none', async () => {
    const heads = ['c=10;e=50', 'e=40', 'c=12;e=45', null]
    let i = 0
    globalThis.fetch = jest.fn(async () => {
      const head = heads[i++]
      return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json', ...(head ? { 'X-Live-Head': head } : {}) } })
    }) as unknown as typeof fetch
    for (let n = 0; n < heads.length; n++) await createCoreStore().dispatch(api.endpoints.probeRead.initiate(undefined, { forceRefetch: true }))
    expect(firstHeads()).toEqual({ c: 10, e: 40 })
  })
})

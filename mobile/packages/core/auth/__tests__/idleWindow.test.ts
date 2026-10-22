/**
 * THE THIRTY-MINUTE IDLE WINDOW, ON THE PHONE'S SIDE — M6, Q-M42.
 *
 * The client's ruling, verbatim: "Automatically logs him out within 30 minutes."
 *
 * The server closes the window (`App\Support\SessionWindow`, and its own tests). What these
 * tests hold the apps to is the three things that make the ruling safe rather than merely
 * enforced:
 *
 *   1. a session that TIMED OUT is not reported as one somebody REVOKED — they are different
 *      facts and the screens say different things;
 *   2. an expired session never destroys what was captured offline. A guard's outbox can be
 *      holding a duress alert, and a phone sitting in a pocket is not a reason to lose it;
 *   3. "Sign out" tells the server, and never claims to have done so when it could not.
 */

import type { ApiError } from '../../api/errors'
import { baseApi } from '../../api/baseApi'
import { configureApi, SESSION_EXPIRED } from '../../api/baseQuery'
import { createCoreStore } from '../../api/store'
import { createAsyncStorageEngine } from '../../lib/storage'
import { createOutbox, type Sender } from '../../offline/outbox'
import { endServerSession } from '../endServerSession'
import { sessionReducer, sessionExpired, sessionRestored, sessionRevoked, signedOut, type SessionState } from '../sessionSlice'
import { tokenStorage } from '../tokenStorage'

process.env.EXPO_PUBLIC_API_URL = 'http://192.168.18.119:8000/api/v1'

const api = baseApi.injectEndpoints({
  endpoints: (build) => ({ idleProbe: build.query<unknown, void>({ query: () => '/shifts/me/current' }) }),
  overrideExisting: true,
})

const booting: SessionState = { status: 'booting', principal: null, endedReason: null, demo: false, reviewer: false }
const marcus = { id: 7, name: 'Marcus Whyte', siteName: 'Phoenix Park Village 1', reference: 'GS-9001' }

function respondOnce(status: number, body: unknown) {
  globalThis.fetch = jest.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })) as unknown as typeof fetch
}

const onSessionRevoked = jest.fn()

beforeEach(async () => {
  onSessionRevoked.mockClear()
  configureApi({ onSessionRevoked, backoffMs: () => 0 })
  await tokenStorage.set('guard', 'tok_live')
})

/* ── 1. Timed out is not revoked ─────────────────────────────────────────── */

describe('a session that timed out is not a session anybody revoked', () => {
  it('ends the session with its OWN reason, and tells the app which it was', async () => {
    respondOnce(401, { error: { code: SESSION_EXPIRED, message: 'Your session timed out after 30 minutes with nothing sent from this phone.' } })

    const store = createCoreStore()
    const result = await store.dispatch(api.endpoints.idleProbe.initiate())

    expect(result.error).toMatchObject({ status: 401, code: 'session_expired' })
    expect(store.getState().session).toMatchObject({ status: 'signedOut', endedReason: 'expired' })

    /*
     * THE HOOK IS TOLD WHICH, and that is not decoration. Each app's store passes the reason
     * straight to `clearEverything`, which keeps the offline outbox on anything but a chosen
     * sign-out. An expired session reported as "revoked" would still keep the queue today —
     * but it would ALSO put "the office signed this phone out" in front of a guard at 2 a.m.,
     * which would send them to a supervisor to ask a question nobody can answer.
     */
    expect(onSessionRevoked).toHaveBeenCalledTimes(1)
    expect(onSessionRevoked).toHaveBeenCalledWith('expired')
  })

  it('still calls an ordinary 401 a revocation', async () => {
    respondOnce(401, { error: { code: 'unauthenticated', message: 'No token, or a token that has been revoked.' } })

    const store = createCoreStore()
    await store.dispatch(api.endpoints.idleProbe.initiate())

    expect(store.getState().session).toMatchObject({ status: 'signedOut', endedReason: 'revoked' })
    expect(onSessionRevoked).toHaveBeenCalledWith('revoked')
  })

  it('keeps the three endings apart in the slice, and carries no demonstration banner out of any of them', () => {
    const active = sessionReducer(booting, sessionRestored({ principal: marcus, demo: true, reviewer: true }))

    expect(sessionReducer(active, sessionExpired())).toEqual({ status: 'signedOut', principal: null, endedReason: 'expired', demo: false, reviewer: false })
    expect(sessionReducer(active, sessionRevoked()).endedReason).toBe('revoked')
    expect(sessionReducer(active, signedOut()).endedReason).toBe('signed_out')
  })
})

/* ── 2. An expired session loses nothing ─────────────────────────────────── */

describe('an expired session does not destroy queued offline work', () => {
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

  const expired: ApiError = { status: 401, code: SESSION_EXPIRED, message: 'Your session timed out.', fieldErrors: {}, retryAfter: null }

  it('leaves a duress alert PENDING when the window closes mid-drain: it is never refused, and never dropped', async () => {
    const storage = createAsyncStorageEngine(memoryBackend())
    await storage.hydrate()
    const outbox = createOutbox({ storage })

    const alert = await outbox.enqueue({ endpoint: 'duress.store', body: { mode: 'audible' }, label: 'Duress alert', kind: 'alert', owner: 'GS-9001' })
    const report = await outbox.enqueue({ endpoint: 'incidents.store', body: { detail: 'Two men at the north fence.' }, label: 'Incident report', kind: 'incident', owner: 'GS-9001' })

    const refusedBy401: Sender = async () => ({ error: expired })
    await outbox.drain(refusedBy401, { force: true })

    const after = outbox.all()

    /*
     * A 4xx is normally an ANSWER and is recorded as `refused` — the server said no, and the
     * act is shown to the guard and never sent again. A 401 is the one 4xx that is not an
     * answer about the ACT at all: it is about the session. Recorded as a refusal, a duress
     * alert raised at a gate would be quietly marked "not accepted" and never sent, which is
     * the worst bug this queue could have.
     */
    expect(after.map((o) => o.state)).toEqual(['pending', 'pending'])
    expect(after.find((o) => o.id === alert.id)?.state).toBe('pending')
    expect(after.find((o) => o.id === report.id)?.state).toBe('pending')
    expect(outbox.pendingCount()).toBe(2)

    // And it is still on DISK, for the same guard to send after they are back in.
    const onDisk = JSON.parse(storage.getString('outbox:v1') ?? '[]') as { state: string }[]
    expect(onDisk).toHaveLength(2)
    expect(onDisk.every((o) => o.state === 'pending')).toBe(true)
  })

  it('sends the very same act, with the same key, once the guard is back in', async () => {
    const storage = createAsyncStorageEngine(memoryBackend())
    await storage.hydrate()
    const outbox = createOutbox({ storage })

    const alert = await outbox.enqueue({ endpoint: 'duress.store', body: { mode: 'audible' }, label: 'Duress alert', kind: 'alert', owner: 'GS-9001' })
    await outbox.drain(async () => ({ error: expired }), { force: true })

    const keysSeen: string[] = []
    await outbox.drain(async (op) => { keysSeen.push(op.stamp.idempotencyKey); return { data: { id: 1 } } }, { force: true })

    expect(keysSeen).toEqual([alert.stamp.idempotencyKey])
    expect(outbox.all()[0]?.state).toBe('confirmed')
  })

  it('keeps everything but a chosen sign-out: the outbox survives revoked AND expired', () => {
    /*
     * Read from the source rather than run, because `clearEverything` reaches the image cache
     * and the file system and this assertion is about ONE branch. The branch is written as
     * "anything but a chosen sign-out" on purpose: a reason added later keeps the queue by
     * default rather than silently destroying it.
     */
    const source = require('node:fs').readFileSync(require('node:path').join(__dirname, '..', 'clearEverything.ts'), 'utf8') as string

    expect(source).toContain("if (reason !== 'signed_out')")
    expect(source).toContain('OUTBOX_PREFIX')
    expect(source).not.toContain("if (reason === 'revoked')")
  })

  it('keeps what the app says goes with its queue, a held panic\'s record, exactly when it keeps the queue (B118)', () => {
    const source = require('node:fs').readFileSync(require('node:path').join(__dirname, '..', 'clearEverything.ts'), 'utf8') as string
    // Kept beside the queue on a sign-out nobody chose…
    expect(source).toMatch(/if \(reason !== 'signed_out'\) \{\s*for \(const key of storage\.keys\(\)\) if \(!key\.startsWith\(OUTBOX_PREFIX\) && !keep\.includes\(key\)\) storage\.remove\(key\)/)
    // …and gone with it on a chosen one: the chosen branch clears everything, with no exception.
    expect(source).toMatch(/\} else \{\s*await storage\.clearAll\(\)/)
    expect(source).toContain('keep: readonly string[] = []')
  })
})

/* ── 3. Sign out tells the server, and never pretends ────────────────────── */

describe('endServerSession', () => {
  it('counts a 200 as told', async () => {
    await expect(endServerSession(async () => ({ data: { signed_out: true } }))).resolves.toBe(true)
  })

  it('counts a 401 as told: the token was already dead on the server, which is the thing we wanted', async () => {
    await expect(endServerSession(async () => ({ error: { status: 401, code: 'unauthenticated' } }))).resolves.toBe(true)
    await expect(endServerSession(async () => ({ error: { status: 401, code: SESSION_EXPIRED } }))).resolves.toBe(true)
  })

  it('never claims to have told the server when it could not', async () => {
    await expect(endServerSession(async () => ({ error: { status: 'network', code: 'network_unreachable' } }))).resolves.toBe(false)
    await expect(endServerSession(async () => ({ error: { status: 'timeout', code: 'timeout' } }))).resolves.toBe(false)
    await expect(endServerSession(async () => ({ error: { status: 500, code: 'server_error' } }))).resolves.toBe(false)
    // A thrown JavaScript error has no status at all, and is not an excuse to say it worked.
    await expect(endServerSession(async () => ({ error: { name: 'TypeError', message: 'boom' } }))).resolves.toBe(false)
    await expect(endServerSession(async () => { throw new Error('boom') })).resolves.toBe(false)
  })

  it('is bounded, so a dead connection never holds a guard on a spinner', async () => {
    const started = Date.now()
    const forever = endServerSession(() => new Promise<never>(() => undefined), 40)

    await expect(forever).resolves.toBe(false)
    expect(Date.now() - started).toBeLessThan(2000)
  })
})

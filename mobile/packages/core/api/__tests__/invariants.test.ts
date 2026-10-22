/**
 * The invariants that live in the API layer, each with a test that fails loudly.
 *   1 tenant isolation · 3 a guard never sees money (ALLOWLIST) · 4 ballots are unlinkable
 *   7 idempotency (see also baseQuery.test.ts) · 9 never fake an offline success
 */
import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { tokenStorage } from '../../auth/tokenStorage'
import { configureApi } from '../baseQuery'
import { ENDPOINTS, REQUEST_FIELDS, RESPONSE_KEYS, type EndpointName } from '../generated/endpoints'
import { guardApi } from '../generated/guardApi'
import { residentApi } from '../generated/residentApi'
import { enforceAllowlist, onAllowlistLeak } from '../runtime'
import { createCoreStore } from '../store'

process.env.EXPO_PUBLIC_API_URL = 'http://192.168.18.119:8000/api/v1'

const names = Object.keys(ENDPOINTS) as EndpointName[]
const guardNames = names.filter((n) => (ENDPOINTS[n].apps as readonly string[]).includes('guard'))
const API_DIR = path.join(__dirname, '..')

function respondOnce(body: unknown, status = 200) {
  const calls: Request[] = []
  globalThis.fetch = jest.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const req = input instanceof Request ? input : new Request(input as string, init)
    calls.push(req.clone())
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
  }) as unknown as typeof fetch
  return calls
}

beforeEach(async () => {
  configureApi({ onSessionRevoked: () => undefined, backoffMs: () => 0 })
  await tokenStorage.set('guard', 'tok')
})

describe('invariant 3: a guard never sees money: an ALLOWLIST, enforced on every guard response', () => {
  it('drops every key the catalogue does not list: including ones no denylist would have guessed', () => {
    const leaks: string[] = []
    onAllowlistLeak((_e, paths) => leaks.push(...paths))
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined)
    const clean = enforceAllowlist('gate.search', {
      items: [{
        unit: 'Lot 47', household: 'Fletcher household', primary_resident: 'Andrea Fletcher', access_restricted: true,
        balance: '12400.00', ageing: { d30: '6200.00' }, reason: 'arrears over 60 days', category_exempt: false,
        expected_visitors: [{ visitor_name: 'Marcia James', category: 'guest', valid_to: '2026-09-04T16:00:00-05:00', owed: '1.00' }],
      }],
      debug: { sql: 'select * from ledgers' },
    })
    expect(clean).toEqual({
      items: [{
        unit: 'Lot 47', household: 'Fletcher household', primary_resident: 'Andrea Fletcher', access_restricted: true,
        expected_visitors: [{ visitor_name: 'Marcia James', category: 'guest', valid_to: '2026-09-04T16:00:00-05:00' }],
      }],
    })
    expect(leaks.sort()).toEqual(['debug', 'items.*.ageing', 'items.*.balance', 'items.*.category_exempt', 'items.*.expected_visitors.*.owed', 'items.*.reason'])
    spy.mockRestore()
  })

  it('is wired into EVERY guard endpoint: none can be added without it', () => {
    const src = readFileSync(path.join(API_DIR, 'generated', 'guardApi.ts'), 'utf8')
    const blocks = src.split(/\n {4}\/\*\*/).slice(1)
    expect(blocks.length).toBe(guardNames.length)
    for (const block of blocks) expect(block).toMatch(/transformResponse: \(data: unknown\) => enforceAllowlist\('/)
  })

  it('holds end to end: a leaked balance on a real guard call never reaches the cache a screen reads', async () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined)
    respondOnce({ items: [{ unit: 'Lot 47', household: 'Fletcher household', primary_resident: null, access_restricted: true, balance: '12400.00' }] })
    const result = await createCoreStore().dispatch(guardApi.endpoints.gateSearch.initiate({ unit: '47' }))
    expect(JSON.stringify(result.data)).not.toMatch(/12400|balance/)
    expect(result.data?.items[0]?.access_restricted).toBe(true)
    spy.mockRestore()
  })

  it('the catalogue itself lists no household money on any guard endpoint: the one money a guard reads is their own payslip', () => {
    const money = /(^|[._])(balance|arrears|owed|owing|outstanding|amount|ageing|aging|invoice|payment|paid|dues|ledger|debt|bucket|fee)([._]|$)/i
    const offenders = guardNames.filter((n) => n !== 'payslips.me').flatMap((n) => RESPONSE_KEYS[n].filter((k) => money.test(k)).map((k) => `${n}: ${k}`))
    expect(offenders).toEqual([])
  })

  it('a restricted household reaches a guard as a boolean and nothing else', () => {
    const standing = RESPONSE_KEYS['gate.search'].filter((k) => /restrict|standing|status/.test(k))
    expect(standing).toEqual(['items.*.access_restricted'])
  })
})

describe('invariant 1: tenant isolation: the estate and the principal come from the token, never from the app', () => {
  it('no authenticated endpoint accepts a tenant, estate, guard or account identifier', () => {
    const forbidden = /^(tenant|tenant_id|estate|estate_id|guard_id|account_id|user_id|resident_id)$/
    const offenders = names.filter((n) => ENDPOINTS[n].ability !== null).flatMap((n) => REQUEST_FIELDS[n].filter((f) => forbidden.test(f)).map((f) => `${n}: ${f}`))
    expect(offenders).toEqual([])
  })

  it('the base query sends the token and nothing that names a tenant', async () => {
    const calls = respondOnce({ shift: null })
    await createCoreStore().dispatch(guardApi.endpoints.shiftsMeCurrent.initiate())
    const headers = Object.fromEntries(calls[0]!.headers.entries())
    // The token, and three facts about the PHONE'S CLOCK (M12 §1.4: lib/time.ts) — none of them names anybody.
    expect(Object.keys(headers).sort()).toEqual(['accept', 'authorization', 'x-device-mono', 'x-device-run', 'x-device-sent-at'])
    expect(Object.values(headers).join(' ')).not.toMatch(/tenant|estate/i)
    expect(headers['x-device-sent-at']).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/)
    expect(calls[0]!.url).not.toMatch(/tenant|estate/)
  })
})

describe('invariant 4: ballots are unlinkable', () => {
  it('the ballot is never retried, is marked opaque, and is a write', () => {
    expect(ENDPOINTS['elections.ballot']).toMatchObject({ write: true, opaque: true })
    const src = readFileSync(path.join(API_DIR, 'generated', 'residentApi.ts'), 'utf8')
    const block = src.slice(src.indexOf('electionsBallot: build.mutation'))
    expect(block.slice(0, block.indexOf('}),'))).toMatch(/extraOptions: \{ maxRetries: 0 \}/)
  })

  it('a failing ballot is sent exactly once', async () => {
    const calls = respondOnce({}, 503)
    await createCoreStore().dispatch(residentApi.endpoints.electionsBallot.initiate({ election: 1 } as never))
    expect(calls).toHaveLength(1)
  })

  it('nothing in the API layer logs, hashes or stores a request body', () => {
    const files = readdirSync(API_DIR).filter((f) => /\.ts$/.test(f)).map((f) => path.join(API_DIR, f))
    for (const f of files) {
      const code = readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')
      expect({ f: path.basename(f), logsBody: /console\.(log|info|debug|warn)\(/.test(code) }).toEqual({ f: path.basename(f), logsBody: false })
      expect({ f: path.basename(f), hashes: /createHash|digest\(|sha256|md5/i.test(code) }).toEqual({ f: path.basename(f), hashes: false })
    }
  })
})

describe('invariant 7: every write is stamped; sign-in and enrolment are never retried', () => {
  it('every write endpoint goes through write()', () => {
    for (const app of ['guardApi', 'residentApi']) {
      const src = readFileSync(path.join(API_DIR, 'generated', `${app}.ts`), 'utf8')
      for (const n of names.filter((x) => ENDPOINTS[x].write)) {
        const fn = n.replace(/[._-]+([a-z0-9])/gi, (_, c: string) => c.toUpperCase())
        const at = src.indexOf(`    ${fn}: build.mutation`)
        if (at === -1) continue
        expect({ n, stamped: /return write\(/.test(src.slice(at, src.indexOf('}),', at))) }).toEqual({ n, stamped: true })
      }
    }
  })

  it('a write carries both headers on the wire', async () => {
    const calls = respondOnce({ id: 1 })
    await createCoreStore().dispatch(guardApi.endpoints.shiftsBreakStart.initiate({ shift: 5 } as never))
    const h = Object.fromEntries(calls[0]!.headers.entries())
    expect(h['idempotency-key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(h['x-device-time']).toMatch(/^\d{4}-\d{2}-\d{2}T/)
  })
})

describe('invariant 9, never fake an offline success', () => {
  it('the ballot is online-only in the server\'s own words (the outbox test in offline/ proves it is refused)', () => {
    expect(ENDPOINTS['elections.ballot'].offline).toMatch(/online only|never queue|not queue|cannot/i)
  })
})

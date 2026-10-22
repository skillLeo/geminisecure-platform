/**
 * INVARIANT 2 — the pass verifier, with the world switched off.
 * `fetch` throws for the whole file: any network use at all fails the test.
 */
import nacl from 'tweetnacl'
import { base64UrlDecode, verifyPassOffline, type PassPayload } from '../passVerifier'

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'
function b64u(bytes: Uint8Array): string {
  let out = ''
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i]!, b = bytes[i + 1], c = bytes[i + 2]
    out += B64[a >> 2]! + B64[((a & 3) << 4) | ((b ?? 0) >> 4)]!
    if (b !== undefined) out += B64[((b & 15) << 2) | ((c ?? 0) >> 6)]!
    if (c !== undefined) out += B64[c & 63]!
  }
  return out
}
const ascii = (s: string) => Uint8Array.from(s, (ch) => ch.charCodeAt(0))

const seed = Uint8Array.from({ length: 32 }, (_, i) => i + 1)
const site = nacl.sign.keyPair.fromSeed(seed)
const stranger = nacl.sign.keyPair.fromSeed(Uint8Array.from({ length: 32 }, (_, i) => 200 - i))
const KEYS = [{ version: 3, public_key: b64u(site.publicKey) }]

const base: PassPayload = {
  pass_id: '0d6b8a1e-5f0c-4b7e-9a36-2c1f4e8d7a90', tenant_id: 'phoenixpark', site_id: 'phoenixpark',
  valid_from: '2026-09-04T14:00:00-05:00', valid_to: '2026-09-04T16:00:00-05:00', single_use: true, nonce: 'n-81f2', key_version: 3,
}
function sign(payload: object, secret = site.secretKey): string {
  const segment = b64u(new TextEncoder().encode(JSON.stringify(payload)))
  return `${segment}.${b64u(nacl.sign.detached(ascii(segment), secret))}`
}
const at = (iso: string) => new Date(iso)
const inWindow = at('2026-09-04T15:00:00-05:00')
const verify = (token: string, now = inWindow, extra: Partial<Parameters<typeof verifyPassOffline>[0]> = {}) =>
  verifyPassOffline({ token, siteId: 'phoenixpark', keys: KEYS, now, ...extra })

beforeAll(() => {
  globalThis.fetch = jest.fn(() => { throw new Error('NETWORK USED: the verifier must never touch it') }) as unknown as typeof fetch
})
afterAll(() => expect(globalThis.fetch).not.toHaveBeenCalled())

describe('a valid pass', () => {
  it('verifies with no network, and the verdict is valid_OFFLINE, never "valid"', () => {
    const v = verify(sign(base))
    expect(v).toEqual({ verdict: 'valid_offline', pass: { pass_id: base.pass_id, valid_from: base.valid_from, valid_to: base.valid_to, single_use: true } })
  })

  it('tolerates a handset clock two minutes out, either side, and no more', () => {
    const token = sign(base)
    expect(verify(token, at('2026-09-04T13:58:01-05:00')).verdict).toBe('valid_offline')
    expect(verify(token, at('2026-09-04T13:57:59-05:00')).verdict).toBe('not_yet_valid')
    expect(verify(token, at('2026-09-04T16:01:59-05:00')).verdict).toBe('valid_offline')
    expect(verify(token, at('2026-09-04T16:02:01-05:00')).verdict).toBe('expired')
  })
})

describe('every invalid branch', () => {
  it('tampered: one changed field breaks the signature', () => {
    const [segment, sig] = sign(base).split('.') as [string, string]
    const forged = b64u(new TextEncoder().encode(JSON.stringify({ ...base, valid_to: '2026-12-31T23:59:00-05:00' })))
    expect(verify(`${forged}.${sig}`).verdict).toBe('bad_signature')
    expect(verify(`${segment}.${sig}`).verdict).toBe('valid_offline')
  })

  it('forged: signed by a key that is not the site\'s', () => {
    expect(verify(sign(base, stranger.secretKey)).verdict).toBe('bad_signature')
  })

  it('unknown key version: a key this handset has never been given', () => {
    expect(verify(sign({ ...base, key_version: 9 })).verdict).toBe('unknown_key')
  })

  it('a retired key still verifies while it is published (rotation never strands a pass)', () => {
    const old = nacl.sign.keyPair.fromSeed(Uint8Array.from({ length: 32 }, () => 7))
    const keys = [...KEYS, { version: 2, public_key: b64u(old.publicKey) }]
    expect(verify(sign({ ...base, key_version: 2 }, old.secretKey), inWindow, { keys }).verdict).toBe('valid_offline')
  })

  it('wrong site: refused BEFORE any key is touched, and the verdict names no site and no tenant', () => {
    const elsewhere = sign({ ...base, site_id: 'oceanview', tenant_id: 'oceanview' }, stranger.secretKey)
    const v = verify(elsewhere)
    expect(v).toEqual({ verdict: 'wrong_site' })
    expect(JSON.stringify(v)).not.toMatch(/oceanview/)
  })

  it('malformed: not two halves, a short signature, bad base64, not JSON, keys missing, extra or out of order', () => {
    const good = sign(base)
    const [segment] = good.split('.') as [string]
    const reordered = { tenant_id: base.tenant_id, pass_id: base.pass_id, site_id: base.site_id, valid_from: base.valid_from, valid_to: base.valid_to, single_use: true, nonce: 'x', key_version: 3 }
    for (const bad of ['', 'nodot', 'a.b.c', `${segment}.`, `${segment}.AAAA`, `!!!.${'A'.repeat(86)}`, sign({ ...base, visitor_name: 'Marcia James' }), sign({ pass_id: 'x' }), sign(reordered), `${b64u(ascii('not json'))}.${'A'.repeat(86)}`]) {
      expect({ bad: bad.slice(0, 12), verdict: verify(bad).verdict }).toEqual({ bad: bad.slice(0, 12), verdict: 'malformed' })
    }
  })

  it('revoked: a pass cancelled or already used, learned at the last sync, is refused locally from then on', () => {
    const token = sign(base)
    expect(verify(token, inWindow, { revoked: [{ pass_id: base.pass_id, status: 'cancelled' }] })).toMatchObject({ verdict: 'revoked', reason: 'cancelled' })
    expect(verify(token, inWindow, { revoked: [{ pass_id: base.pass_id, status: 'used' }] })).toMatchObject({ verdict: 'revoked', reason: 'used' })
    expect(verify(token, inWindow, { revoked: [{ pass_id: 'someone-else', status: 'used' }] }).verdict).toBe('valid_offline')
  })
})

describe('what a pass may carry', () => {
  it('names no visitor, unit or household: a QR code is shown to whoever stands near it', () => {
    const decoded = new TextDecoder().decode(base64UrlDecode(sign(base).split('.')[0]!)!)
    expect(Object.keys(JSON.parse(decoded))).toEqual(['pass_id', 'tenant_id', 'site_id', 'valid_from', 'valid_to', 'single_use', 'nonce', 'key_version'])
  })

  it('base64url decoding is exact, padded or not', () => {
    expect(Array.from(base64UrlDecode('AQID')!)).toEqual([1, 2, 3])
    expect(Array.from(base64UrlDecode('AQI=')!)).toEqual([1, 2])
    expect(Array.from(base64UrlDecode('-_8')!)).toEqual([251, 255])
    expect(base64UrlDecode('A')).toBeNull()
    expect(base64UrlDecode('AQ+D')).toBeNull()
  })
})

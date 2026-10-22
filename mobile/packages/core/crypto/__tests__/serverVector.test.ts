/**
 * INVARIANT 2, cross-implementation: a token signed by the REAL Laravel server (PHP sodium,
 * the phoenixpark site key) verifies in the app's verifier (tweetnacl) — with the network
 * switched off and no database anywhere in this process. The vector is produced by
 * `npm run api:vector`; it contains public keys only.
 */
import vector from './server-vector.json'
import { verifyPassOffline } from '../passVerifier'

beforeAll(() => {
  globalThis.fetch = jest.fn(() => { throw new Error('NETWORK USED') }) as unknown as typeof fetch
})
afterAll(() => expect(globalThis.fetch).not.toHaveBeenCalled())

const inWindow = new Date('2026-09-04T15:00:00-05:00')

describe('a pass signed by the real server', () => {
  it('verifies offline on the handset', () => {
    const v = verifyPassOffline({ token: vector.token, siteId: vector.site_id, keys: vector.keys, now: inWindow })
    expect(v).toMatchObject({ verdict: 'valid_offline', pass: { pass_id: vector.payload.pass_id, single_use: true } })
  })

  it('fails when one character of the payload is changed', () => {
    const [segment, sig] = vector.token.split('.') as [string, string]
    const flipped = segment.slice(0, 20) + (segment[20] === 'A' ? 'B' : 'A') + segment.slice(21)
    const v = verifyPassOffline({ token: `${flipped}.${sig}`, siteId: vector.site_id, keys: vector.keys, now: inWindow })
    expect(['bad_signature', 'malformed']).toContain(v.verdict)
  })

  it('is refused at another site without saying which site it is for', () => {
    const v = verifyPassOffline({ token: vector.token, siteId: 'oceanview', keys: vector.keys, now: inWindow })
    expect(v).toEqual({ verdict: 'wrong_site' })
  })

  it('is expired after its window and not yet valid before it', () => {
    expect(verifyPassOffline({ token: vector.token, siteId: vector.site_id, keys: vector.keys, now: new Date('2026-09-04T18:00:00-05:00') }).verdict).toBe('expired')
    expect(verifyPassOffline({ token: vector.token, siteId: vector.site_id, keys: vector.keys, now: new Date('2026-09-04T09:41:00-05:00') }).verdict).toBe('not_yet_valid')
  })
})

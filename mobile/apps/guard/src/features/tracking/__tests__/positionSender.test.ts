/**
 * Sending on-duty positions: what each answer from the server means for the queue, and what is sent.
 */
import { sendPositions } from '../positionSender'
import type { Fix } from '../trackingPolicy'

const fix: Fix = { id: 'a1', latitude: 18.0181, longitude: -76.8102, accuracy_m: 8, source: 'interval', battery_pct: 70, captured_at: '2026-10-03T12:00:00-05:00' }

const answer = (status: number, body: unknown) => jest.fn(async () => ({ ok: status >= 200 && status < 300, status, json: async () => body }) as unknown as Response)
const deps = (fetch: jest.Mock) => ({ fetch: fetch as unknown as typeof globalThis.fetch, token: async () => 'tok', baseUrl: () => 'https://api.test/api/v1' })

describe('sending positions', () => {
  it('posts the batch with the token, a fresh key and the phone clock NOW, and reads what was kept', async () => {
    const fetch = answer(200, { shift_id: 7, accepted: ['a1'], refused: [{ id: 'b2', code: 'outside_shift', message: 'Taken after clock-out.' }] })
    const result = await sendPositions(7, [fix], deps(fetch))
    expect(result).toEqual({ kind: 'answered', accepted: ['a1'], refused: [{ id: 'b2', code: 'outside_shift', message: 'Taken after clock-out.' }] })
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit & { headers: Record<string, string> }]
    expect(url).toBe('https://api.test/api/v1/shifts/7/positions')
    expect(init.method).toBe('POST')
    expect(init.headers.Authorization).toBe('Bearer tok')
    expect(init.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(init.headers['X-Device-Time']).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/)
    expect(JSON.parse(String(init.body))).toEqual({ positions: [fix] })
  })

  it('stops sharing when the server will keep nothing', async () => {
    expect(await sendPositions(7, [fix], deps(answer(403, { error: { code: 'consent_required' } })))).toEqual({ kind: 'stop', code: 'consent_required' })
    expect(await sendPositions(7, [fix], deps(answer(403, { error: { code: 'tracking_off' } })))).toEqual({ kind: 'stop', code: 'tracking_off' })
  })

  it('drops a shift that is not this officer\'s, and keeps everything on a failure or with no session', async () => {
    expect(await sendPositions(7, [fix], deps(answer(404, { error: { code: 'not_found' } })))).toEqual({ kind: 'not_this_shift' })
    expect(await sendPositions(7, [fix], deps(answer(403, { error: { code: 'not_your_shift' } })))).toEqual({ kind: 'not_this_shift' })
    expect(await sendPositions(7, [fix], deps(answer(503, null)))).toEqual({ kind: 'failed' })
    expect(await sendPositions(7, [fix], deps(answer(401, { error: { code: 'unauthenticated' } })))).toEqual({ kind: 'signed_out' })
    const offline = jest.fn(async () => { throw new TypeError('Network request failed') })
    expect(await sendPositions(7, [fix], deps(offline as unknown as jest.Mock))).toEqual({ kind: 'failed' })
  })

  it('sends nothing without a token', async () => {
    const fetch = answer(200, {})
    expect(await sendPositions(7, [fix], { ...deps(fetch), token: async () => null })).toEqual({ kind: 'signed_out' })
    expect(fetch).not.toHaveBeenCalled()
  })
})

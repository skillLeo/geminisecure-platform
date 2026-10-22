import { newStamp, write } from '../write'

const headersOf = (args: ReturnType<typeof write>) => args.headers as Record<string, string>

describe('write: invariant 4: offline writes carry an idempotency key', () => {
  it('stamps every write with an Idempotency-Key and X-Device-Time', () => {
    const args = write('/gate/entry', { pass_id: 'abc' })
    expect(args).toMatchObject({ url: '/gate/entry', method: 'POST', body: { pass_id: 'abc' } })
    expect(headersOf(args)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(headersOf(args)['X-Device-Time']).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/)
  })

  it('gives two acts two keys', () => {
    expect(headersOf(write('/a'))['Idempotency-Key']).not.toBe(headersOf(write('/a'))['Idempotency-Key'])
  })

  it('replays an act captured offline with the key and time it was captured with, never fresh ones', () => {
    const stamp = { idempotencyKey: '11111111-1111-4111-8111-111111111111', deviceTime: '2026-09-04T02:10:00-05:00' }
    const first = write('/incidents', { detail: 'x' }, { stamp })
    const retry = write('/incidents', { detail: 'x' }, { stamp })
    expect(headersOf(first)).toEqual({ 'Idempotency-Key': stamp.idempotencyKey, 'X-Device-Time': stamp.deviceTime })
    expect(headersOf(retry)).toEqual(headersOf(first))
  })

  it('newStamp fixes key and time together, once', () => {
    const s = newStamp()
    expect(Object.keys(s).sort()).toEqual(['deviceTime', 'idempotencyKey'])
  })

  it('takes other write methods', () => {
    expect(write('/me', { name: 'A' }, { method: 'PATCH' }).method).toBe('PATCH')
  })
})

import { isRetryable, normalizeError } from '../errors'

describe('normalizeError', () => {
  it('reads the contract shape: error.code and error.message', () => {
    const e = normalizeError({ status: 401, data: { error: { code: 'unauthenticated', message: 'This request needs a handset token.' } } })
    expect(e).toMatchObject({ status: 401, code: 'unauthenticated', message: 'This request needs a handset token.', fieldErrors: {} })
  })

  it('reads a 422 with its TOP-LEVEL errors map: the live server puts it beside `error`, not inside it', () => {
    const e = normalizeError({
      status: 422,
      data: {
        error: { code: 'validation_failed', message: 'The estate field is required. (and 2 more errors)' },
        message: 'The estate field is required. (and 2 more errors)',
        errors: { estate: ['The estate field is required.'], channel: ['The channel field is required.'] },
      },
    })
    expect(e.code).toBe('validation_failed')
    expect(e.fieldErrors).toEqual({ estate: ['The estate field is required.'], channel: ['The channel field is required.'] })
  })

  it('never shows a raw server error: a debug 500 carries an exception class, SQL and a stack trace', () => {
    const e = normalizeError({
      status: 500,
      data: {
        message: 'SQLSTATE[HY000] [2002] No connection could be made (Connection: mysql, Host: 127.0.0.1, Port: 3307, Database: gs_platform, SQL: select * from `cache`)',
        exception: 'Illuminate\\Database\\QueryException',
        file: 'D:\\srv\\app\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php',
        trace: [{ file: 'Connection.php', line: 857 }],
      },
    })
    expect(e.code).toBe('server_error')
    expect(e.message).not.toMatch(/SQL|select|Illuminate|\.php|3307|gs_platform/i)
    expect(e.message).toBe('Something went wrong on our side. Please try again in a moment.')
  })

  it('never lets the server\'s catch-all 404 sentence reach a person (M57 §5.3), and keeps a 404 a controller worded', () => {
    const generic = normalizeError({ status: 404, data: { error: { code: 'not_found', message: 'Nothing at this address, or nothing this handset may see.' } } })
    expect(generic.code).toBe('not_found')
    expect(generic.message).not.toMatch(/Nothing at this address/)
    expect(generic.message).toBe("This is not on the estate's server, or not yet. Go back and try again; if it keeps happening, the estate office can help.")

    const worded = normalizeError({ status: 404, data: { error: { code: 'not_found', message: 'No alert of yours with that id.' } } })
    expect(worded.message).toBe('No alert of yours with that id.')
  })

  it('handles an HTML or empty body on a 4xx', () => {
    expect(normalizeError({ status: 404, data: '<html>Not Found</html>' }).code).toBe('unexpected_response')
    expect(normalizeError({ status: 403, data: undefined }).code).toBe('unexpected_response')
  })

  it('names being offline plainly, and promises nothing is lost', () => {
    const e = normalizeError({ status: 'FETCH_ERROR', error: 'TypeError: Network request failed' })
    expect(e).toMatchObject({ status: 'network', code: 'network_unreachable' })
    expect(e.message).toMatch(/kept on this phone/)
    expect(e.message).not.toMatch(/TypeError/)
  })

  it('names a timeout', () => {
    expect(normalizeError({ status: 'TIMEOUT_ERROR', error: 'AbortError' })).toMatchObject({ status: 'timeout', code: 'timeout' })
  })

  it('carries a refusal\'s details when it sends some (M18H account_deleted), and adds none when it does not', () => {
    const details = { deleted_on: '2026-09-27', restore_until: '2026-10-04', can_restore: true, ticket: 'eyJ…' }
    expect(normalizeError({ status: 409, data: { error: { code: 'account_deleted', message: 'This account was deleted on 27 September.', details } } }).details).toEqual(details)
    expect('details' in normalizeError({ status: 409, data: { error: { code: 'pass_not_active', message: 'x' } } })).toBe(false)
    expect('details' in normalizeError({ status: 409, data: { error: { code: 'x', message: 'y', details: ['not', 'a', 'map'] } } })).toBe(false)
  })

  it('carries Retry-After on a 429 and ignores it elsewhere', () => {
    const body = { error: { code: 'rate_limited', message: 'Too many requests.' } }
    expect(normalizeError({ status: 429, data: body }, '17').retryAfter).toBe(17)
    expect(normalizeError({ status: 429, data: body }, 'soon').retryAfter).toBeNull()
    expect(normalizeError({ status: 422, data: body }, '17').retryAfter).toBeNull()
  })
})

describe('isRetryable: a refusal is an answer, a failure is not', () => {
  const of = (status: number, code: string) => normalizeError({ status, data: { error: { code, message: 'x' } } })

  it('retries what the same Idempotency-Key can survive', () => {
    expect(isRetryable(normalizeError({ status: 'FETCH_ERROR', error: '' }))).toBe(true)
    expect(isRetryable(normalizeError({ status: 'TIMEOUT_ERROR', error: '' }))).toBe(true)
    expect(isRetryable(normalizeError({ status: 503, data: {} }))).toBe(true)
    expect(isRetryable(of(409, 'request_in_progress'))).toBe(true)
    expect(isRetryable(of(429, 'rate_limited'))).toBe(true)
  })

  it('never retries a refusal', () => {
    expect(isRetryable(of(401, 'unauthenticated'))).toBe(false)
    expect(isRetryable(of(403, 'missing_ability'))).toBe(false)
    expect(isRetryable(of(404, 'not_found'))).toBe(false)
    expect(isRetryable(of(422, 'validation_failed'))).toBe(false)
    expect(isRetryable(of(422, 'idempotency_key_reused'))).toBe(false)
    expect(isRetryable(of(409, 'pass_not_active'))).toBe(false)
  })
})

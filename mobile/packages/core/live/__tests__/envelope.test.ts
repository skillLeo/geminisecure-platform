import { allowSocketMessage, envelopeOnly, fromWire, isChannelName, isSequenced, parseEnvelope, toWire, type LiveAllowlist } from '../envelope'
import { ORIGIN_MEMORY_MS, forgetOrigins, isOwnOrigin, newOrigin } from '../origin'

/** The example in DESIGN §2.2, exactly as the relay publishes it. */
const EXAMPLE = {
  v: 1, s: 'c', ep: '01J9ZC3K', q: 1042, p: 1038, t: 'alert.resolved', e: 'alert', i: '55', m: 'inline',
  d: { status: 'resolved' }, sum: { status: 'resolved' }, o: '3fa1c09e5b2d7a10', at: '2026-10-02T14:03:11.221Z', sim: false,
}

describe('the wire envelope (§2.2)', () => {
  it('reads the design’s own example, field for field', () => {
    const env = parseEnvelope(EXAMPLE)
    expect(env).toEqual({ ...EXAMPLE })
    expect(env && isSequenced(env)).toBe(true)
  })

  it('reads it as the socket delivers it: a JSON string', () => {
    expect(parseEnvelope(JSON.stringify(EXAMPLE))).toEqual(EXAMPLE)
    expect(parseEnvelope('{not json')).toBeNull()
  })

  it.each([
    ['a sequence number that is not a number', { q: '1042' }],
    ['a sequence number of zero', { q: 0 }],
    ['a stream it does not know', { s: 'x' }],
    ['a logged event with no epoch', { ep: undefined }],
    ['a logged event with no stream', { s: undefined }],
    ['a logged event with no record id', { i: undefined }],
    ['a logged event with no entity', { e: '' }],
    ['a type that is not {entity}.{verb}', { t: 'ALERT' }],
    ['no type at all', { t: undefined }],
    ['a version this build cannot read', { v: 2 }],
    ['a negative previous seq', { p: -1 }],
    ['a mode that is neither ref nor inline', { m: 'full' }],
  ])('drops a message with %s: it is never guessed at', (_, change) => {
    expect(parseEnvelope({ ...EXAMPLE, ...change })).toBeNull()
  })

  it('drops what is not an object at all', () => {
    for (const raw of [null, undefined, 42, 'text', ['a']]) expect(parseEnvelope(raw)).toBeNull()
  })

  it('reads the relay’s nudge, no stream, no seq, as an ephemeral message that moves no cursor', () => {
    const env = parseEnvelope({ t: 'live.nudge', q: null })
    expect(env).toMatchObject({ t: 'live.nudge', q: null, s: null, ep: null, p: null })
    expect(env && isSequenced(env)).toBe(false)
  })

  it('a record id sent as a number is read as the string the server stores', () => {
    expect(parseEnvelope({ ...EXAMPLE, i: 55 })?.i).toBe('55')
  })

  it('an origin that is not a 16-hex nonce is never taken for one', () => {
    expect(parseEnvelope({ ...EXAMPLE, o: 'u-12' })?.o).toBeNull()
    expect(parseEnvelope({ ...EXAMPLE, o: '3FA1C09E5B2D7A10' })?.o).toBeNull()
  })

  it('a message with no inline data has no `d` at all', () => {
    const { d: _d, ...ref } = EXAMPLE
    expect(parseEnvelope({ ...ref, m: 'ref' })).not.toHaveProperty('d')
  })
})

describe('channel names are the server’s: the phone adds or removes only the transport prefix', () => {
  it('private-live.<name> both ways', () => {
    expect(toWire('estate.pp.passes')).toBe('private-live.estate.pp.passes')
    expect(fromWire('private-live.estate.pp.passes')).toBe('estate.pp.passes')
  })

  it('refuses everything that is not a live channel', () => {
    for (const wire of ['presence-live.user.7', 'private-encrypted-live.user.7', 'private-live.', 'private-live.User.7', 'estate.pp.alerts', 'private-live.user 7']) {
      expect(fromWire(wire)).toBeNull()
    }
  })

  it('a name from live.config is lower case and dotted, and at most 160 characters', () => {
    expect(isChannelName('household.pp.12')).toBe(true)
    expect(isChannelName('guard.pp.5')).toBe(true)
    expect(isChannelName('Household.pp.12')).toBe(false)
    expect(isChannelName('household..12')).toBe(false)
    expect(isChannelName('a'.repeat(161))).toBe(false)
    expect(isChannelName(12)).toBe(false)
  })
})

describe('the allowlist', () => {
  it('until an app configures one, the envelope’s own keys survive and inline data does not', () => {
    const answer = envelopeOnly({
      channels: [{ name: 'guard.pp.5', status: 'ok', head: { c: 9 }, more: false, events: [{ ...EXAMPLE, who: 'Marcia', amount_minor: 5000 }], extra: 1 }],
      relay: { ok: true, lag_ms: 40, secret: 'x' },
      build: 'abc',
      debug: true,
    }) as { channels: Array<{ events: Array<Record<string, unknown>> } & Record<string, unknown>>; relay: Record<string, unknown> } & Record<string, unknown>
    const env = answer.channels[0]!.events[0]!
    expect(env).not.toHaveProperty('d')
    expect(env).not.toHaveProperty('who')
    expect(env).not.toHaveProperty('amount_minor')
    expect(env.sum).toEqual({ status: 'resolved' })
    expect(answer.channels[0]).not.toHaveProperty('extra')
    expect(answer.relay).toEqual({ ok: true, lag_ms: 40 })
    expect(answer).not.toHaveProperty('debug')
  })

  it('a socket message goes through the app’s allowlist as a catch-up answer of one', () => {
    const seen: unknown[] = []
    // Stands in for enforceAllowlist('live.catch_up', …): it allows d.status and nothing else under d.
    const allowlist: LiveAllowlist = (answer) => {
      seen.push(answer)
      const a = answer as { channels: Array<{ events: Array<Record<string, unknown>> }> }
      return { channels: a.channels.map((c) => ({ ...c, events: c.events.map((e) => ({ ...e, d: { status: (e.d as { status?: string }).status } })) })) }
    }
    const out = allowSocketMessage(allowlist, 'guard.pp.5', JSON.stringify({ ...EXAMPLE, d: { status: 'resolved', lot: '47' } }))
    expect(seen).toEqual([{ channels: [{ name: 'guard.pp.5', status: 'ok', events: [{ ...EXAMPLE, d: { status: 'resolved', lot: '47' } }] }] }])
    expect((out as { d: unknown }).d).toEqual({ status: 'resolved' })
  })

  it('an allowlist that answers nothing usable lets nothing through', () => {
    expect(allowSocketMessage(() => ({}), 'guard.pp.5', EXAMPLE)).toBeNull()
    expect(allowSocketMessage(() => ({ channels: [{ events: [] }] }), 'guard.pp.5', EXAMPLE)).toBeNull()
    expect(allowSocketMessage(envelopeOnly, 'guard.pp.5', 'not json')).toBeNull()
  })
})

describe('the origin nonce (§6.1, §8.7)', () => {
  beforeEach(() => forgetOrigins())

  it('is 16 lower-case hex digits, fresh for every write', () => {
    const a = newOrigin()
    const b = newOrigin()
    expect(a).toMatch(/^[a-f0-9]{16}$/)
    expect(b).toMatch(/^[a-f0-9]{16}$/)
    expect(a).not.toBe(b)
  })

  it('this install recognises its own for two minutes, and then forgets it: it is never a stable id', () => {
    const sentAt = 1_000_000
    const nonce = newOrigin(sentAt)
    expect(isOwnOrigin(nonce, sentAt + 1)).toBe(true)
    expect(isOwnOrigin(nonce, sentAt + ORIGIN_MEMORY_MS)).toBe(true)
    expect(isOwnOrigin(nonce, sentAt + ORIGIN_MEMORY_MS + 1)).toBe(false)
    expect(isOwnOrigin(nonce, sentAt + 2)).toBe(false) // forgotten, not merely expired
  })

  it('a nonce somebody else sent is not this install’s', () => {
    expect(isOwnOrigin('3fa1c09e5b2d7a10')).toBe(false)
    expect(isOwnOrigin(null)).toBe(false)
  })
})

/**
 * THE CURSOR RULES (M11 Part C, design §2.2 and §5.3) — `resources/js/live/cursor.js`.
 *
 *   a duplicate is dropped;
 *   `p > cursor` is a gap, and nothing else is;
 *   a cursor seeded from a page's head does not cause a spurious catch-up;
 *   an epoch change resets, and is checked before the duplicate rule a restarted counter
 *   would otherwise trip.
 *
 * The pure rules first; the same rules seen through the store, on a channel a page holds,
 * are in live-store.test.mjs.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'

const { advance, classify, eventKey, normalizeEnvelope, normalizeHeads, toSeq } = await import(
    '../../resources/js/live/cursor.js'
)

const env = (overrides = {}) =>
    normalizeEnvelope({ v: 1, s: 'e', ep: '01J9ZC3K', q: 1042, p: 1038, t: 'ticket.updated', e: 'ticket', i: '55', m: 'ref', ...overrides })

describe('the cursor rules', () => {
    it('drops a duplicate: an event at or below the cursor has been applied already', () => {
        assert.equal(classify({ q: 1042, ep: '01J9ZC3K' }, env({ q: 1042 })), 'duplicate')
        assert.equal(classify({ q: 1050, ep: '01J9ZC3K' }, env({ q: 1042 })), 'duplicate')
    })

    it('applies the next event on the channel', () => {
        assert.equal(classify({ q: 1038, ep: '01J9ZC3K' }, env({ q: 1042, p: 1038 })), 'apply')
    })

    it('calls p > cursor a gap: the previous event on this channel was never applied', () => {
        assert.equal(classify({ q: 1030, ep: '01J9ZC3K' }, env({ q: 1042, p: 1038 })), 'gap')
    })

    it('does not call a cursor seeded from the stream head a gap when the channel was quiet', () => {
        // The page loaded at head 1040. This channel's previous event was 950, long before:
        // p !== cursor would have sent a catch-up here; p > cursor does not.
        assert.equal(classify({ q: 1040, ep: null }, env({ q: 1042, p: 950 })), 'apply')
    })

    it('applies the first event a channel ever carries (p null)', () => {
        assert.equal(classify({ q: 1040, ep: null }, env({ q: 1042, p: null })), 'apply')
    })

    it('resets on an epoch change, before the duplicate rule: a re-provisioned stream restarts at 1', () => {
        assert.equal(classify({ q: 1042, ep: '01J9ZC3K' }, env({ q: 3, p: null, ep: '01JB00AA' })), 'epoch')
    })

    it('adopts an epoch it did not know yet instead of resetting', () => {
        const cursor = { q: 1038, ep: null }
        const event = env({ q: 1042, p: 1038, ep: '01J9ZC3K' })

        assert.equal(classify(cursor, event), 'apply')
        assert.deepEqual(advance(cursor, event), { q: 1042, ep: '01J9ZC3K' })
    })

    it('asks for a catch-up when it has no number at all, rather than guessing', () => {
        assert.equal(classify({ q: null, ep: null }, env()), 'gap')
        assert.equal(classify(undefined, env()), 'gap')
    })

    it('leaves a control message (q null) to the caller: it never moves a cursor', () => {
        const nudge = normalizeEnvelope({ t: 'live.nudge', q: null })

        assert.equal(classify({ q: 5, ep: null }, nudge), 'control')
        assert.equal(nudge.s, null)
    })

    it('remembers one event by (stream, epoch, q), whatever channel it came on', () => {
        assert.equal(eventKey(env()), eventKey(env({ i: 'other channel, same event' })))
        assert.notEqual(eventKey(env()), eventKey(env({ s: 'c' })))
    })
})

describe('what counts as an envelope, and as a head', () => {
    it('refuses a sequenced envelope with no stream, a non-numeric q, or no type', () => {
        assert.equal(normalizeEnvelope({ t: 'x', q: 5 }), null)
        assert.equal(normalizeEnvelope({ t: 'x', s: 'c', q: 'five' }), null)
        assert.equal(normalizeEnvelope({ s: 'c', q: 5 }), null)
        assert.equal(normalizeEnvelope('not json'), null)
    })

    it('parses a frame handed on as a string', () => {
        assert.equal(normalizeEnvelope(JSON.stringify({ t: 'x', s: 'c', q: 5, p: 4 })).q, 5)
    })

    it('reads the heads CaptureLiveHeads shares, and leaves out anything that is not a number', () => {
        assert.deepEqual(normalizeHeads({ c: 1042, e: '88' }), { c: { q: 1042, ep: null }, e: { q: 88, ep: null } })
        assert.deepEqual(normalizeHeads({ c: { q: 7, ep: '01J9ZC3K' } }), { c: { q: 7, ep: '01J9ZC3K' } })
        assert.deepEqual(normalizeHeads({ c: -1, e: 'x' }), {})
        assert.deepEqual(normalizeHeads(null), {})
    })

    it('takes no unsafe or negative sequence number', () => {
        assert.equal(toSeq(2 ** 53), null)
        assert.equal(toSeq(-3), null)
        assert.equal(toSeq('007'), null)
        assert.equal(toSeq('12'), 12)
    })
})

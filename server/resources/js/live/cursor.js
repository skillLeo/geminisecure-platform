/**
 * THE CURSOR RULES, AND NOTHING ELSE (M11 Part C, design §2.2 and §5.3).
 *
 * Pure: no socket, no timers, no Vue. The store keeps one cursor per (channel, stream),
 * `{q, ep}`: the highest sequence number applied on that channel in that stream, and the
 * first eight characters of the stream's epoch. Everything here answers one question
 * about an envelope arriving on a channel: what should be done with it?
 *
 *   control     `q` is null: an ephemeral message (a `live.nudge`, a turnout signal).
 *               It carries no sequence number, so it never moves a cursor.
 *   epoch       the stream's epoch differs from the one this cursor was taken in: the
 *               database was re-provisioned, sequence numbers started again, and every
 *               number held is meaningless. Checked FIRST, because a restarted counter
 *               would otherwise look like a run of duplicates.
 *   duplicate   `q <= cursor`: already applied (a republish, or the same event on a
 *               second channel). Dropped.
 *   gap         `p > cursor`: the previous event on this channel in this stream is one
 *               this client never applied. Catch up before applying anything more.
 *   apply       everything else, including `p` null (the first event on the channel) and
 *               `p` below the cursor.
 *
 * WHY `p > cursor` AND NOT `p !== cursor`. A cursor is often seeded from a stream HEAD —
 * the page's `live.heads`, read when its request started — and the head is stream-wide.
 * The last event on one channel is usually well below the stream head, so the next
 * event's `p` sits below the cursor. That is not a gap: nothing on this channel happened
 * between them. `p !== cursor` would send a catch-up for every first event after a page
 * load; `p > cursor` sends one only when something on THIS channel really was missed.
 *
 * A cursor with no number (`q` null: no head, no catch-up yet) cannot tell a gap from a
 * first event, so it asks for a catch-up, which answers with the head.
 */

/** The two streams: the central database (`c`) and the estate's own (`e`). */
export const STREAMS = ['c', 'e']

/** A sequence number, or null. Accepts a non-negative safe integer or its decimal string. */
export function toSeq(value) {
    if (typeof value === 'number') {
        return Number.isSafeInteger(value) && value >= 0 ? value : null
    }

    if (typeof value === 'string' && /^(0|[1-9]\d{0,15})$/.test(value)) {
        const parsed = Number(value)

        return Number.isSafeInteger(parsed) ? parsed : null
    }

    return null
}

const toEpoch = (value) => (typeof value === 'string' && value !== '' ? value : null)

/**
 * A page's heads as the server shares them, as cursors.
 *
 * `{c: 1042, e: 88}` is the shape `CaptureLiveHeads` shares; `{c: {q: 1042, ep: '01J9ZC3K'}}`
 * and a separate `ep: {c: …}` map are read too, so an epoch sent with the heads is kept.
 * Anything that is not a sequence number is left out rather than guessed at.
 *
 * @returns {{c?: {q: number, ep: string|null}, e?: {q: number, ep: string|null}}}
 */
export function normalizeHeads(heads) {
    const out = {}

    if (!heads || typeof heads !== 'object') {
        return out
    }

    const epochs = heads.ep && typeof heads.ep === 'object' ? heads.ep : {}

    for (const stream of STREAMS) {
        const raw = heads[stream]
        const q = raw && typeof raw === 'object' ? toSeq(raw.q ?? raw.seq ?? raw.head) : toSeq(raw)

        if (q !== null) {
            const ep = raw && typeof raw === 'object' ? toEpoch(raw.ep) : null
            out[stream] = { q, ep: ep ?? toEpoch(epochs[stream]) }
        }
    }

    return out
}

/**
 * An envelope as it arrives (§2.2), checked. Null when it is not one.
 *
 * `data` from the socket is already parsed by pusher-js; a string is parsed here in case a
 * transport hands the raw frame on. A sequenced envelope must name its stream; a control
 * message (`q` null) need not.
 */
export function normalizeEnvelope(raw) {
    let env = raw

    if (typeof env === 'string') {
        try {
            env = JSON.parse(env)
        } catch {
            return null
        }
    }

    if (!env || typeof env !== 'object' || typeof env.t !== 'string' || env.t === '') {
        return null
    }

    const q = env.q === null || env.q === undefined ? null : toSeq(env.q)

    // A q that is present but not a number is a malformed envelope, not a control message.
    if (q === null && env.q !== null && env.q !== undefined) {
        return null
    }

    const s = env.s === 'c' || env.s === 'e' ? env.s : null

    if (q !== null && s === null) {
        return null
    }

    return {
        ...env,
        s,
        q,
        p: env.p === null || env.p === undefined ? null : toSeq(env.p),
        ep: toEpoch(env.ep),
        e: typeof env.e === 'string' ? env.e : '',
        i: env.i === null || env.i === undefined ? '' : String(env.i),
        m: env.m === 'inline' ? 'inline' : 'ref',
        o: typeof env.o === 'string' ? env.o : null,
    }
}

/**
 * What to do with `env` on a channel whose cursor for `env.s` is `cursor`.
 *
 * @param {{q: number|null, ep: string|null}|undefined} cursor
 * @returns {'control'|'epoch'|'duplicate'|'gap'|'apply'}
 */
export function classify(cursor, env) {
    if (env.q === null) {
        return 'control'
    }

    const at = cursor ?? { q: null, ep: null }

    if (at.ep && env.ep && at.ep !== env.ep) {
        return 'epoch'
    }

    if (at.q === null) {
        return 'gap'
    }

    if (env.q <= at.q) {
        return 'duplicate'
    }

    if (env.p !== null && env.p > at.q) {
        return 'gap'
    }

    return 'apply'
}

/** The cursor after `env` has been applied: its number, and the epoch it names. */
export function advance(cursor, env) {
    return { q: env.q, ep: env.ep ?? cursor?.ep ?? null }
}

/** The key an applied event is remembered by: the same event on two channels is one event. */
export function eventKey(env) {
    return `${env.s}:${env.ep ?? ''}:${env.q}`
}

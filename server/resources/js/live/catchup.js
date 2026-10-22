/**
 * THE CATCH-UP REQUEST AND ITS ANSWER (M11 Part C, design §5.1).
 *
 * Request:
 *   POST /live/catch-up
 *   {"channels":[{"name":"estate.pp.facilities","c":null,"e":1042,"ep":{"e":"01J9ZC3K"}}, …]}
 *
 * Answer:
 *   {"channels":[{"name":"…","status":"ok","events":[envelopes],"head":{"e":1050},"more":false},
 *                {"name":"…","status":"refused"},
 *                {"name":"…","status":"reset","head":{"e":2201}}],
 *    "relay":{"ok":true,"lag_ms":40},"build":"a342c56"}
 *
 * The server re-authorises every channel through the same registry the socket uses; the
 * list sent here is never trusted. This file only speaks the shapes: it sends the cursors it
 * is given and reads the answer into something the store can apply without re-checking it.
 * WHEN to catch up, and what applying means, is the store's.
 */
import { normalizeEnvelope, STREAMS, toSeq } from './cursor.js'
import { postJson } from './rows.js'

export const CATCH_UP_URL = '/live/catch-up'

/** The most channels one request carries (§5.1). */
export const CATCH_UP_MAX_CHANNELS = 200

const STATUSES = new Set(['ok', 'refused', 'reset'])

function heads(raw) {
    const out = {}

    if (raw && typeof raw === 'object') {
        for (const stream of STREAMS) {
            const q = toSeq(raw[stream])

            if (q !== null) {
                out[stream] = q
            }
        }
    }

    return out
}

function epochs(raw) {
    const out = {}

    if (raw && typeof raw === 'object') {
        for (const stream of STREAMS) {
            if (typeof raw[stream] === 'string' && raw[stream] !== '') {
                out[stream] = raw[stream]
            }
        }
    }

    return out
}

/**
 * The answer, checked: every channel entry has a known status, and its events are
 * envelopes, sequenced, sorted by stream and then sequence number. An entry that is none of
 * these is left out, and the store treats a channel it asked about and got no entry for as
 * a failed catch-up for that channel.
 */
export function normalizeCatchUp(body) {
    const channels = []

    for (const raw of Array.isArray(body?.channels) ? body.channels : []) {
        if (!raw || typeof raw.name !== 'string' || !STATUSES.has(raw.status)) {
            continue
        }

        const events = (Array.isArray(raw.events) ? raw.events : [])
            .map(normalizeEnvelope)
            .filter((env) => env !== null && env.q !== null)
            .sort((a, b) => (a.s === b.s ? a.q - b.q : a.s < b.s ? -1 : 1))

        channels.push({
            name: raw.name,
            status: raw.status,
            events,
            head: heads(raw.head),
            ep: epochs(raw.ep),
            more: raw.more === true,
        })
    }

    const relay = body?.relay && typeof body.relay === 'object'
        ? { ok: body.relay.ok !== false, lagMs: toSeq(body.relay.lag_ms) ?? 0 }
        : null

    return { channels, relay, build: typeof body?.build === 'string' ? body.build : null }
}

/**
 * Ask for everything after the given cursors.
 *
 * @param {Array<{name: string, c?: number|null, e?: number|null, ep?: object}>} channels
 * @param {{timeoutMs?: number, signal?: AbortSignal|null}} [options]  when to stop waiting
 * @returns {Promise<{ok: boolean, status: number, timedOut?: boolean, aborted?: boolean, result?: ReturnType<typeof normalizeCatchUp>}>}
 */
export async function postCatchUp(channels, options = {}) {
    const answer = await postJson(CATCH_UP_URL, { channels }, options)

    if (!answer.ok) {
        return { ok: false, status: answer.status, timedOut: answer.timedOut === true, aborted: answer.aborted === true }
    }

    return { ok: true, status: answer.status, result: normalizeCatchUp(answer.body) }
}

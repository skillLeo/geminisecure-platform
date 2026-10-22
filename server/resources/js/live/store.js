/**
 * THE LIVE STORE: ONE PER TAB, OUTLIVING EVERY PAGE (M11 Part C, design §5.3–§5.6, §6.1).
 *
 * Module-level, so an Inertia visit that swaps the page component swaps nothing here. It
 * holds, for every channel something on screen is bound to:
 *
 *   a reference count and a 30 s grace. A page that unmounts lets go of its channels; the
 *     next page asking for the same channel within 30 s gets it as it was — subscribed,
 *     authorised, with its cursors — so navigating never tears a channel down and never
 *     authorises it twice;
 *   a cursor per (channel, stream), `{q, ep}`, seeded from the page's heads (`live.heads`,
 *     read when its request started, so the page's data includes everything at or below
 *     them) and advanced by every event applied (cursor.js holds the rules);
 *   a phase: `down` (not subscribed), `catching-up` (subscribed, catch-up not applied yet:
 *     socket messages are buffered), `live`, or `refused`;
 *   a buffer, applied after the catch-up, in order, with duplicates dropped.
 *
 * CATCH-UP (§5.3) runs after every `pusher:subscription_succeeded` — every subscribe and
 * every reconnect — and is applied before the channel goes live. The channels subscribed in
 * one moment are asked about in ONE request (batched 50 ms). A gap (`p > cursor`) waits
 * 300 ms, in case the missing message is only late, then asks; so does a `live.nudge`. A
 * `reset` answer resyncs the screens bound to that channel. While the socket is down, or
 * catch-up is failing, catch-up IS the fallback poll: every 10 s, or 3 s while a dispatch
 * screen is open. While live, a safety catch-up runs every 5 minutes regardless.
 *
 * NOTHING WAITS ON A DEAD REQUEST. A catch-up with no answer after 10 s is given up on (and
 * the next one is given 20 s, then 30 s, so a slow server is still heard). One still out
 * when its channel is subscribed again, when the network or the tab comes back, or when
 * the reader presses Refresh is not waited for at all: it may be hanging on the connection
 * that went away, and what it would have answered is asked for again at once.
 *
 * STATUS (§6.4). "live" only when the socket is connected, every channel held is subscribed
 * and caught up, the last catch-up did not fail, the relay says it is keeping up, and a
 * relay pulse has arrived on THIS connection within the last 25 s. Anything else is named:
 * `connecting` and `catching-up` for the first 3 s, then `degraded`; `delayed` when the
 * relay is behind or its pulse is stale (or has not come 25 s after connecting); `refused`
 * when a channel was refused and everything else is live; `signed-out` on a 401 or 419.
 *
 * WHAT THIS NEVER DOES. It never calls `router.reload` for data (index.js reloads once, on
 * `access.changed`, which is not data), never builds a channel name (every name comes from
 * the server), and never disconnects the socket because a request came back 401 — the
 * panic composables share this socket, and their channels keep delivering.
 */
import { reactive } from 'vue'
import { dropQueuedAuthorizations, LIVE_PREFIX, setAuthListener } from './authorize.js'
import { CATCH_UP_MAX_CHANNELS, postCatchUp } from './catchup.js'
import { advance, classify, eventKey, normalizeEnvelope, normalizeHeads, STREAMS } from './cursor.js'
import { onSignedOut } from './rows.js'
import { sharedPusher } from './socket.js'

export const PULSE_CHANNEL = 'live-pulse'

/** Every interval the design names, in one place. */
export const TIMING = {
    grace: 30_000,
    catchUpBatch: 50,
    gapWait: 300,
    nudgeWait: 300,
    downPoll: 10_000,
    dispatchPoll: 3_000,
    safety: 5 * 60_000,
    pulseFresh: 25_000,
    degradedAfter: 3_000,
    connectingAfter: 2_000,
    awayForReturn: 30_000,
    ownOrigin: 2 * 60_000,
    authRetry: [2_000, 4_000, 8_000, 15_000, 30_000],

    /** A catch-up with no answer after this is given up on; each one given up on doubles it, to 30 s. */
    requestTimeout: 10_000,
    requestTimeoutMax: 30_000,
}

const BUFFER_CAP = 1_000
const SEEN_CAP = 500

const moduleLoadedAt = Date.now()

/** What the status and the indicator read. Reactive; everything else here is not. */
export const state = reactive({
    started: false,
    socket: 'initialized',
    connectedAt: 0,
    socketSince: moduleLoadedAt,
    pulseAt: 0,
    relay: { ok: true, lagMs: 0 },
    relaySince: 0,
    catchUpFailing: false,
    failingSince: 0,
    lastSyncAt: 0,
    signedOut: false,
    build: null,
    newVersion: false,
    resetAt: 0,
    now: moduleLoadedAt,

    /** name → phase, for every channel something holds right now. */
    channels: {},

    /** name → since when that channel has not been live. */
    since: {},

    /** Mounted dispatch screens: while any is open, the fallback poll runs every 3 s. */
    dispatchPages: 0,

    /** Bindings the current PAGE registered (a shell's own bindings are not counted). */
    pageBindings: 0,

    /**
     * What the current page SAID it is, when it is not a live list (M18H §7.2): `form` (an edit page — it shows no
     * record to go stale, and must never be re-read under somebody's typing) or `snapshot` (a report, drawn "As of …").
     * Null for a page that said nothing, which the indicator then judges by its bindings.
     */
    pageKind: null,
})

const channels = new Map()
const handlers = new Set()

let pusher = null
let pulseChannel = null
let generation = 0
let tickTimer = null
let lastPollAt = 0
let pollSince = 0
let lastFullAt = 0
let hiddenAt = 0
let wentOffline = false
let principal = null
let removeWindowListeners = () => {}

/* ---- channels ------------------------------------------------------------------------ */

function newChannel(name) {
    return {
        name,
        refs: 0,
        graceTimer: null,
        sub: null,
        listeners: null,
        subscribed: false,
        subGen: 0,
        askedGen: -1,
        phase: 'down',
        badSince: Date.now(),
        streams: new Set(),
        cursors: {},
        buffer: [],
        rewind: {},
        authRefused: false,
        authRetry: 0,
        authTimer: null,
    }
}

function setPhase(ch, phase) {
    const was = ch.phase
    ch.phase = phase

    if (phase !== 'live' && phase !== 'refused' && (was === 'live' || was === 'refused')) {
        ch.badSince = Date.now()
    }

    if (ch.refs > 0) {
        state.channels[ch.name] = phase
        state.since[ch.name] = ch.badSince
    }
}

/**
 * Hold `name`. `heads` are the heads the holder's data was read at: a new channel starts
 * from them, and a channel already held from further on is wound BACK to them and caught
 * up, so events the newcomer's data may lack are applied to it too.
 */
export function acquire(name, heads = {}, streams = []) {
    if (typeof name !== 'string' || !/^[a-z0-9][a-z0-9._-]*$/.test(name)) {
        return false
    }

    const seeds = normalizeHeads(heads)
    let ch = channels.get(name)
    const known = ch !== undefined

    if (!known) {
        ch = newChannel(name)
        channels.set(name, ch)

        for (const [stream, seed] of Object.entries(seeds)) {
            ch.cursors[stream] = { q: seed.q, ep: seed.ep }
        }
    }

    for (const stream of streams ?? []) {
        if (STREAMS.includes(stream)) {
            ch.streams.add(stream)
        }
    }

    // Held first, so a channel coming back out of its grace can be caught up.
    const wasIdle = ch.refs === 0
    ch.refs++

    if (ch.graceTimer !== null) {
        clearTimeout(ch.graceTimer)
        ch.graceTimer = null
    }

    if (known && wasIdle && ch.phase !== 'live' && ch.phase !== 'refused') {
        // Nothing showed it while it was down: to the screen holding it now, it is as new as its data.
        ch.badSince = Date.now()
    }

    state.channels[name] = ch.phase
    state.since[name] = ch.badSince

    if (known) {
        rewindTo(ch, seeds)

        if (wasIdle) {
            revive(ch)
        }
    }

    if (state.started && ch.sub === null) {
        subscribe(ch)
    }

    touch()

    return true
}

function rewindTo(ch, seeds) {
    let lowered = false

    for (const [stream, seed] of Object.entries(seeds)) {
        const cursor = ch.cursors[stream]

        if (!cursor || cursor.q === null) {
            ch.cursors[stream] = { q: seed.q, ep: cursor?.ep ?? seed.ep }

            continue
        }

        if (seed.q < cursor.q) {
            if (inFlight.has(ch.name)) {
                ch.rewind[stream] = Math.min(ch.rewind[stream] ?? Infinity, seed.q)
            } else {
                ch.cursors[stream] = { q: seed.q, ep: cursor.ep }
            }

            lowered = true
        }
    }

    if (lowered && state.started) {
        if (ch.subscribed && ch.phase === 'live') {
            setPhase(ch, 'catching-up')
        }

        requestCatchUp([ch.name], TIMING.catchUpBatch, 'rewind')
    }
}

/**
 * A channel held again, out of its grace, that is not live. While nothing held it, what
 * would have brought it back was left undone: a catch-up asked for after a reconnect, a gap
 * or a nudge is not sent for a channel nothing shows, and a failed authorisation is not
 * retried for one. Now that a screen holds it, both happen at once, so navigating back to a
 * page within 30 s costs nothing even when the socket blinked in between.
 */
function revive(ch) {
    if (!state.started || state.signedOut || ch.phase === 'live' || ch.phase === 'refused') {
        return
    }

    if (ch.subscribed) {
        if (!inFlight.has(ch.name)) {
            requestCatchUp([ch.name], TIMING.catchUpBatch, 'subscribe')
        }

        return
    }

    // pusher-js ignores this while an authorisation is out; a retry already waiting keeps its turn.
    if (ch.sub !== null && ch.authTimer === null && pusher !== null && state.socket === 'connected') {
        pusher.subscribe(LIVE_PREFIX + ch.name)
    }
}

/** Let go of `name`. After 30 s with nothing holding it, it is unsubscribed and forgotten. */
export function release(name) {
    const ch = channels.get(name)

    if (!ch || ch.refs === 0) {
        return
    }

    ch.refs--

    if (ch.refs > 0) {
        return
    }

    delete state.channels[name]
    delete state.since[name]

    ch.graceTimer = setTimeout(() => {
        ch.graceTimer = null

        if (ch.refs === 0 && channels.get(name) === ch) {
            teardown(ch)
        }
    }, TIMING.grace)

    touch()
}

function subscribe(ch) {
    const sub = pusher.subscribe(LIVE_PREFIX + ch.name)

    ch.listeners = {
        succeeded: () => onSubscribed(ch),
        error: () => onSubscriptionError(ch),
        live: (data) => onMessage(ch, data),
    }

    sub.bind('pusher:subscription_succeeded', ch.listeners.succeeded)
    sub.bind('pusher:subscription_error', ch.listeners.error)
    sub.bind('live', ch.listeners.live)
    ch.sub = sub

    if (sub.subscribed === true) {
        onSubscribed(ch)
    }
}

function unsubscribe(ch) {
    if (ch.sub !== null) {
        ch.sub.unbind('pusher:subscription_succeeded', ch.listeners.succeeded)
        ch.sub.unbind('pusher:subscription_error', ch.listeners.error)
        ch.sub.unbind('live', ch.listeners.live)
        pusher?.unsubscribe(LIVE_PREFIX + ch.name)
    }

    ch.sub = null
    ch.listeners = null
    ch.subscribed = false
}

function teardown(ch) {
    unsubscribe(ch)
    clearTimeout(ch.authTimer)
    clearTimeout(ch.graceTimer)
    due.delete(ch.name)
    again.delete(ch.name)
    channels.delete(ch.name)
    delete state.channels[ch.name]
    delete state.since[ch.name]
}

function onSubscribed(ch) {
    ch.subscribed = true
    ch.subGen++
    ch.authRefused = false
    ch.authRetry = 0
    clearTimeout(ch.authTimer)
    ch.authTimer = null
    setPhase(ch, 'catching-up')

    /*
     * A catch-up still out for this channel was asked before this subscription, so its answer
     * could not make it live (finishSync would ask again) — and after a reconnect it may be
     * hanging on the connection that died. It is not waited for: its channels are asked
     * about again in this subscribe's batch.
     */
    const stale = inFlight.has(ch.name) ? supersede((request) => request.chunk.includes(ch)) : []

    requestCatchUp([...new Set([...stale, ch.name])], TIMING.catchUpBatch, 'subscribe')
    touch()
}

function onSubscriptionError(ch) {
    ch.subscribed = false

    if (ch.authRefused) {
        ch.buffer = []
        setPhase(ch, 'refused')
    } else {
        setPhase(ch, 'down')
        retryAuthLater(ch)
    }

    touch()
}

function retryAuthLater(ch) {
    clearTimeout(ch.authTimer)
    ch.authTimer = null

    // An ended session is not asked again: every attempt would be answered 401. resume() asks.
    if (state.signedOut) {
        return
    }

    const delays = TIMING.authRetry
    const delay = delays[Math.min(ch.authRetry, delays.length - 1)]
    ch.authRetry++

    ch.authTimer = setTimeout(() => {
        ch.authTimer = null

        if (channels.get(ch.name) === ch && ch.refs > 0 && !state.signedOut && pusher && state.socket === 'connected' && !ch.subscribed) {
            pusher.subscribe(LIVE_PREFIX + ch.name)
        }
    }, delay)
}

setAuthListener({
    granted(name, streams) {
        const ch = channels.get(name)

        if (ch) {
            ch.authRefused = false

            for (const stream of streams) {
                if (STREAMS.includes(stream)) {
                    ch.streams.add(stream)
                }
            }
        }
    },
    refused(name) {
        const ch = channels.get(name)

        if (ch) {
            ch.authRefused = true
        }
    },
    signedOut: () => signOut(),
})

onSignedOut(() => signOut())

/* ---- messages ------------------------------------------------------------------------ */

function remember(seen, key) {
    seen.add(key)

    if (seen.size > SEEN_CAP) {
        seen.delete(seen.values().next().value)
    }
}

function reportError(error) {
    if (typeof console !== 'undefined') {
        console.error(error)
    }
}

/*
 * WHAT THE C8 HARNESS READS (design §11.3), and only when tests/Live/proof asked for it by setting
 * `window.__liveProof` before the page loaded. Per logged event: its stream, seq and type, when the
 * socket delivered it and when every binding holding it had it — so a row over 1 s splits into
 * publish, receive, refetch and render. Without the flag nothing is kept. Never a payload.
 */
const PROOF_KEEP = 500

function proofNote(env, stage) {
    if (typeof window === 'undefined' || window.__liveProof !== true || env.q === null) {
        return
    }

    const book = (window.__live ??= { events: [] })
    let entry = book.events.find((e) => e.s === env.s && e.q === env.q)

    if (entry === undefined) {
        entry = { s: env.s, q: env.q, t: env.t, received_at: null, applied_at: null }
        book.events.push(entry)

        if (book.events.length > PROOF_KEEP) {
            book.events.shift()
        }
    }

    entry[`${stage}_at`] ??= performance.timeOrigin + performance.now()
}

/** Hand an applied event to every binding holding this channel, once per binding. */
function deliver(ch, env) {
    if (env.t === 'live.resync') {
        resyncChannel(ch, 'reset')

        return
    }

    const key = eventKey(env)

    for (const handler of [...handlers]) {
        if (!handler.channels.has(ch.name) || (handler.accepts && !handler.accepts(env))) {
            continue
        }

        if (handler.seen.has(key)) {
            continue
        }

        remember(handler.seen, key)

        try {
            handler.apply(env, ch.name)
        } catch (error) {
            reportError(error)
        }
    }

    proofNote(env, 'applied')
}

/** An unlogged signal (turnout): no cursor, no dedupe, straight to the bindings. */
function deliverSignal(ch, env) {
    for (const handler of [...handlers]) {
        if (handler.signals === false || !handler.channels.has(ch.name) || (handler.accepts && !handler.accepts(env))) {
            continue
        }

        try {
            handler.apply(env, ch.name)
        } catch (error) {
            reportError(error)
        }
    }
}

function buffer(ch, env) {
    if (ch.buffer.length >= BUFFER_CAP) {
        // Everything dropped is after the cursor, so a catch-up fetches it again.
        ch.buffer = []
        requestCatchUp([ch.name], 0, 'overflow')
    }

    ch.buffer.push(env)
}

function onMessage(ch, raw) {
    const env = normalizeEnvelope(raw)

    if (env === null || !state.started) {
        return
    }

    proofNote(env, 'received')

    if (env.q === null) {
        if (env.t === 'live.nudge') {
            requestCatchUp([ch.name], TIMING.nudgeWait, 'nudge')
        } else {
            deliverSignal(ch, env)
        }

        return
    }

    if (ch.phase !== 'live') {
        buffer(ch, env)

        return
    }

    const verdict = classify(ch.cursors[env.s], env)

    if (verdict === 'duplicate') {
        return
    }

    if (verdict === 'epoch') {
        epochReset(ch, env)

        return
    }

    if (verdict === 'gap') {
        setPhase(ch, 'catching-up')
        buffer(ch, env)
        requestCatchUp([ch.name], TIMING.gapWait, 'gap')
        touch()

        return
    }

    ch.cursors[env.s] = advance(ch.cursors[env.s], env)
    ch.streams.add(env.s)
    deliver(ch, env)
}

/** The stream was re-provisioned: every number held is meaningless, so the screen resyncs. */
function epochReset(ch, env) {
    ch.cursors[env.s] = { q: env.q, ep: env.ep }
    ch.streams.add(env.s)
    state.resetAt = Date.now()
    resyncChannel(ch, 'reset')
}

const byStreamThenSeq = (a, b) => (a.s === b.s ? a.q - b.q : a.s < b.s ? -1 : 1)

/** Apply what was buffered. False when a gap is left: the rest stays buffered. */
function drain(ch) {
    const buffered = ch.buffer.sort(byStreamThenSeq)
    ch.buffer = []

    for (let at = 0; at < buffered.length; at++) {
        const env = buffered[at]
        const verdict = classify(ch.cursors[env.s], env)

        if (verdict === 'duplicate' || verdict === 'control') {
            continue
        }

        if (verdict === 'epoch') {
            epochReset(ch, env)

            continue
        }

        if (verdict === 'gap') {
            ch.buffer = buffered.slice(at)

            return false
        }

        ch.cursors[env.s] = advance(ch.cursors[env.s], env)
        ch.streams.add(env.s)
        deliver(ch, env)
    }

    return true
}

/* ---- resync -------------------------------------------------------------------------- */

const pendingResync = new Map()
let resyncQueued = false

function queueResync(handler, reason) {
    if (pendingResync.get(handler) !== 'reset') {
        pendingResync.set(handler, reason)
    }

    if (!resyncQueued) {
        resyncQueued = true

        Promise.resolve().then(() => {
            resyncQueued = false
            const batch = [...pendingResync]
            pendingResync.clear()

            for (const [each, why] of batch) {
                if (handlers.has(each)) {
                    try {
                        each.resync(why)
                    } catch (error) {
                        reportError(error)
                    }
                }
            }
        })
    }
}

function resyncChannel(ch, reason) {
    for (const handler of handlers) {
        if (handler.channels.has(ch.name)) {
            queueResync(handler, reason)
        }
    }
}

/* ---- catch-up ------------------------------------------------------------------------ */

const due = new Map()
const inFlight = new Set()
const again = new Set()
let dueTimer = null

/** The catch-ups out now: `{chunk, controller, superseded}`. */
const requests = new Set()

/** How long the next catch-up is waited for (TIMING.requestTimeout, doubled after each one given up on). */
let catchUpDeadline = TIMING.requestTimeout

function armDue() {
    clearTimeout(dueTimer)
    dueTimer = null

    if (due.size === 0) {
        return
    }

    let next = Infinity

    for (const entry of due.values()) {
        next = Math.min(next, entry.at)
    }

    dueTimer = setTimeout(fireDue, Math.max(0, next - Date.now()))
}

/**
 * Ask about `names` after `delay` ms. A channel already being asked about is asked about
 * again as soon as that answer is in.
 */
export function requestCatchUp(names, delay = 0, reason = 'poll') {
    if (!state.started || state.signedOut) {
        return
    }

    const at = Date.now() + delay

    for (const name of names) {
        const ch = channels.get(name)

        if (!ch || ch.refs === 0 || ch.phase === 'refused') {
            continue
        }

        if (inFlight.has(name)) {
            again.add(name)

            continue
        }

        const entry = due.get(name)

        if (entry) {
            entry.at = Math.min(entry.at, at)
            entry.reasons.add(reason)
        } else {
            due.set(name, { at, reasons: new Set([reason]) })
        }
    }

    armDue()
}

function fireDue() {
    dueTimer = null
    const now = Date.now()
    const batch = []

    for (const [name, entry] of [...due]) {
        if (entry.at > now) {
            continue
        }

        due.delete(name)
        const ch = channels.get(name)

        if (!ch || ch.refs === 0 || ch.phase === 'refused') {
            continue
        }

        if (inFlight.has(name)) {
            again.add(name)

            continue
        }

        // A gap the late message has filled meanwhile needs no request.
        if (entry.reasons.size === 1 && entry.reasons.has('gap') && ch.subscribed && ch.askedGen === ch.subGen && drain(ch)) {
            setPhase(ch, 'live')

            continue
        }

        batch.push(ch)
    }

    armDue()

    for (let at = 0; at < batch.length; at += CATCH_UP_MAX_CHANNELS) {
        send(batch.slice(at, at + CATCH_UP_MAX_CHANNELS))
    }

    touch()
}

function requestEntry(ch) {
    const entry = { name: ch.name }
    const streams = ch.streams.size > 0 ? [...ch.streams] : Object.keys(ch.cursors)
    const epochs = {}

    for (const stream of streams) {
        const cursor = ch.cursors[stream]
        entry[stream] = cursor ? cursor.q : null

        if (cursor?.ep) {
            epochs[stream] = cursor.ep
        }
    }

    if (Object.keys(epochs).length > 0) {
        entry.ep = epochs
    }

    return entry
}

function setFailing(failing) {
    if (failing && !state.catchUpFailing) {
        state.failingSince = Date.now()
    }

    state.catchUpFailing = failing
}

/** Apply a rewind that waited for an answer in flight. True when a cursor went back. */
function takeRewind(ch) {
    let rewound = false

    for (const [stream, q] of Object.entries(ch.rewind)) {
        const cursor = ch.cursors[stream]

        if (cursor && cursor.q !== null && q < cursor.q) {
            ch.cursors[stream] = { q, ep: cursor.ep }
            rewound = true
        }
    }

    ch.rewind = {}

    return rewound
}

/**
 * Stop waiting for the catch-ups out now that `which` picks. Their answers, if they ever
 * come, are ignored, and the browser is told to drop them; their channels are free to be
 * asked about again at once, and a rewind that was waiting for one of those answers is
 * applied now. Returns the channels they were for: the caller asks about them again.
 */
function supersede(which = () => true) {
    const names = new Set()

    for (const request of [...requests]) {
        if (!which(request)) {
            continue
        }

        request.superseded = true
        requests.delete(request)
        request.controller?.abort()

        for (const ch of request.chunk) {
            inFlight.delete(ch.name)
            again.delete(ch.name)
            takeRewind(ch)
            names.add(ch.name)
        }
    }

    return [...names]
}

function send(chunk) {
    const mine = generation
    const entries = []
    const request = { chunk, controller: typeof AbortController === 'function' ? new AbortController() : null, superseded: false }
    requests.add(request)

    for (const ch of chunk) {
        inFlight.add(ch.name)
        ch.askedGen = ch.subscribed ? ch.subGen : -1
        entries.push(requestEntry(ch))
    }

    postCatchUp(entries, { timeoutMs: catchUpDeadline, signal: request.controller?.signal ?? null })
        .then((answer) => {
            requests.delete(request)

            if (mine !== generation || request.superseded) {
                return
            }

            for (const ch of chunk) {
                inFlight.delete(ch.name)
            }

            // A slow answer is still an answer: after one is given up on, the next is waited for longer.
            catchUpDeadline = answer.timedOut === true
                ? Math.min(catchUpDeadline * 2, TIMING.requestTimeoutMax)
                : TIMING.requestTimeout

            if (!answer.ok) {
                if (answer.status !== 401 && answer.status !== 419) {
                    setFailing(true)
                }

                for (const ch of chunk) {
                    again.delete(ch.name)
                }

                return
            }

            applyResult(answer.result, chunk)
        })
        .catch((error) => {
            requests.delete(request)

            if (mine === generation && !request.superseded) {
                for (const ch of chunk) {
                    inFlight.delete(ch.name)
                }

                setFailing(true)
                reportError(error)
            }
        })
        .finally(() => {
            if (mine === generation) {
                touch()
            }
        })
}

function noteBuild(build) {
    if (typeof build === 'string' && build !== '' && state.build && build !== state.build) {
        state.newVersion = true
    }
}

function applyResult(result, chunk) {
    setFailing(false)
    state.lastSyncAt = Date.now()

    if (result.relay) {
        if (!result.relay.ok && state.relay.ok) {
            state.relaySince = Date.now()
        }

        state.relay = { ok: result.relay.ok, lagMs: result.relay.lagMs }
    }

    noteBuild(result.build)

    const answered = new Set()

    for (const entry of result.channels) {
        const ch = channels.get(entry.name)

        if (!ch || !chunk.includes(ch)) {
            continue
        }

        answered.add(ch.name)
        applyEntry(ch, entry)
    }

    for (const ch of chunk) {
        // A channel asked about and not answered is a failed catch-up, not a quiet one.
        if (!answered.has(ch.name) && channels.get(ch.name) === ch) {
            setFailing(true)
        }

        if (again.delete(ch.name)) {
            requestCatchUp([ch.name], 0, 'again')
        }
    }

    for (const handler of handlers) {
        if (typeof handler.afterCatchUp === 'function' && [...answered].some((name) => handler.channels.has(name))) {
            try {
                handler.afterCatchUp()
            } catch (error) {
                reportError(error)
            }
        }
    }
}

function applyEntry(ch, entry) {
    if (entry.status === 'refused') {
        ch.authRefused = true
        ch.buffer = []
        setPhase(ch, 'refused')

        return
    }

    for (const stream of Object.keys(entry.head)) {
        ch.streams.add(stream)
    }

    if (entry.status === 'reset') {
        for (const [stream, q] of Object.entries(entry.head)) {
            ch.cursors[stream] = { q, ep: entry.ep[stream] ?? null }
        }

        ch.rewind = {}
        state.resetAt = Date.now()
        resyncChannel(ch, 'reset')
        finishSync(ch)

        return
    }

    // Streams asked about with no cursor at all: the head is taken, and the screen resyncs once.
    const blind = Object.keys(entry.head).filter((stream) => (ch.cursors[stream]?.q ?? null) === null)
    const newEpochs = {}

    for (const env of entry.events) {
        const cursor = ch.cursors[env.s]

        if (cursor?.ep && env.ep && cursor.ep !== env.ep) {
            newEpochs[env.s] = env.ep

            continue
        }

        if (cursor && cursor.q !== null && env.q <= cursor.q) {
            continue
        }

        ch.cursors[env.s] = advance(cursor, env)
        ch.streams.add(env.s)
        deliver(ch, env)
    }

    if (!entry.more) {
        for (const [stream, q] of Object.entries(entry.head)) {
            const cursor = ch.cursors[stream]

            if (newEpochs[stream]) {
                ch.cursors[stream] = { q, ep: newEpochs[stream] }
            } else if (!cursor || cursor.q === null || q > cursor.q) {
                ch.cursors[stream] = { q, ep: cursor?.ep ?? entry.ep[stream] ?? null }
            }
        }
    }

    if (Object.keys(newEpochs).length > 0) {
        state.resetAt = Date.now()
        resyncChannel(ch, 'reset')
    } else if (blind.length > 0) {
        resyncChannel(ch, 'blind')
    }

    const rewound = takeRewind(ch)

    if (entry.more || rewound) {
        requestCatchUp([ch.name], 0, rewound ? 'rewind' : 'more')

        return
    }

    finishSync(ch)
}

/** After a catch-up is applied: live, if this answer was asked for after the subscription. */
function finishSync(ch) {
    if (!ch.subscribed) {
        ch.buffer = []

        if (ch.phase !== 'refused') {
            setPhase(ch, 'down')
        }

        return
    }

    if (ch.askedGen !== ch.subGen) {
        // Asked before the latest subscribe: events published in between are not in it.
        requestCatchUp([ch.name], 0, 'subscribe')

        return
    }

    if (drain(ch)) {
        setPhase(ch, 'live')
    } else {
        setPhase(ch, 'catching-up')
        requestCatchUp([ch.name], TIMING.gapWait, 'gap')
    }
}

/* ---- the connection, the pulse and the clock ---------------------------------------- */

function onConnectionState({ previous, current }) {
    state.socket = current

    if (current === 'connected') {
        state.connectedAt = Date.now()
    } else {
        if (previous === 'connected') {
            state.socketSince = Date.now()
        }

        for (const ch of channels.values()) {
            ch.subscribed = false
            ch.buffer = []

            if (ch.phase !== 'refused') {
                setPhase(ch, 'down')
            }
        }
    }

    touch()
}

function onPulseEvent(event, data) {
    if (typeof event === 'string' && event.startsWith('pusher')) {
        return
    }

    state.pulseAt = Date.now()
    noteBuild(data?.build)
    touch()
}

/** The fallback poll's interval: 3 s while a dispatch screen is open, 10 s otherwise. */
export function fallbackCadence() {
    return state.dispatchPages > 0 ? TIMING.dispatchPoll : TIMING.downPoll
}

function activeNames() {
    return [...channels.values()].filter((ch) => ch.refs > 0 && ch.phase !== 'refused').map((ch) => ch.name)
}

function tick() {
    state.now = Date.now()

    if (!state.started || state.signedOut) {
        return
    }

    const now = state.now
    const pollable = []
    let needed = false

    for (const ch of channels.values()) {
        if (ch.refs === 0 || ch.phase === 'refused' || (ch.phase === 'live' && !state.catchUpFailing)) {
            continue
        }

        // Still in need of the poll while a catch-up for it is out: the cadence runs on.
        needed = true

        if (!inFlight.has(ch.name) && !due.has(ch.name)) {
            pollable.push(ch.name)
        }
    }

    if (!needed) {
        pollSince = 0
    } else {
        if (pollSince === 0) {
            pollSince = now
        }

        if (pollable.length > 0 && now - Math.max(pollSince, lastPollAt) >= fallbackCadence()) {
            lastPollAt = now
            requestCatchUp(pollable, 0, 'poll')
        }
    }

    if (state.socket === 'connected' && now - lastFullAt >= TIMING.safety) {
        lastFullAt = now
        requestCatchUp(activeNames(), 0, 'safety')
    }
}

function touch() {
    state.now = Date.now()
}

function signOut() {
    if (!state.signedOut) {
        state.signedOut = true
        due.clear()
        clearTimeout(dueTimer)
        dueTimer = null

        for (const ch of channels.values()) {
            clearTimeout(ch.authTimer)
            ch.authTimer = null
        }

        touch()
    }
}

/**
 * The server has just served this person a page, live props and all (startLive with them):
 * whatever 401 or 419 marked the session ended, it is not ended now. A session turned over
 * by a sign-in in another tab answers the request in flight so, and the reader carries on in
 * this tab. What the ended session left undone is done again: a channel it could not
 * authorise is subscribed, and every channel held is caught up.
 */
export function resume() {
    if (!state.started || !state.signedOut) {
        return
    }

    state.signedOut = false

    for (const ch of channels.values()) {
        if (ch.refs > 0 && ch.phase !== 'refused' && !ch.subscribed && ch.sub !== null && pusher !== null && state.socket === 'connected') {
            ch.authRetry = 0
            pusher.subscribe(LIVE_PREFIX + ch.name)
        }
    }

    requestCatchUp(activeNames(), 0, 'resume')
    touch()
}

/* ---- starting and stopping ----------------------------------------------------------- */

/**
 * Whether the socket may be dead without knowing it yet: not connected, the browser went
 * offline since, or the relay's pulse — which arrives every 10 s on a live connection — has
 * gone stale on THIS connection. With no pulse seen on it yet there is nothing to judge by,
 * and pusher-js's own ping decides.
 */
function socketSuspect() {
    if (state.socket !== 'connected' || wentOffline) {
        return true
    }

    return state.pulseAt > 0 && state.pulseAt >= state.connectedAt && Date.now() - state.pulseAt >= TIMING.pulseFresh
}

/**
 * Back online, or back on the tab after a while (§5.4): the HTTP catch-up starts at once,
 * and a suspect socket is reconnected in parallel instead of waiting out the 30 s activity
 * and 10 s pong timeouts. A socket that is plainly alive is left alone: the panic channel
 * the composables hold on it has no catch-up, so a needless reconnect would be a moment in
 * which a panic could reach this screen only by the poll.
 */
function returnToLive() {
    if (!state.started || state.signedOut || pusher === null) {
        return
    }

    // "At once" (§5.4): a catch-up still out may be hanging on the network that went away.
    supersede()
    requestCatchUp(activeNames(), 0, 'return')

    if (socketSuspect()) {
        wentOffline = false
        pusher.disconnect()
        pusher.connect()
    }
}

function listenToWindow() {
    const onOnline = () => returnToLive()

    const onOffline = () => {
        wentOffline = true
    }

    const onVisibility = () => {
        if (typeof document === 'undefined') {
            return
        }

        if (document.hidden) {
            hiddenAt = Date.now()

            return
        }

        const away = hiddenAt === 0 ? 0 : Date.now() - hiddenAt
        hiddenAt = 0

        if (away >= TIMING.awayForReturn || state.socket !== 'connected') {
            returnToLive()
        }
    }

    const target = typeof window !== 'undefined' && typeof window.addEventListener === 'function' ? window : null
    const doc = typeof document !== 'undefined' && typeof document.addEventListener === 'function' ? document : null

    target?.addEventListener('online', onOnline)
    target?.addEventListener('offline', onOffline)
    doc?.addEventListener('visibilitychange', onVisibility)

    return () => {
        target?.removeEventListener('online', onOnline)
        target?.removeEventListener('offline', onOffline)
        doc?.removeEventListener('visibilitychange', onVisibility)
    }
}

/** Start: make (or reconnect) the one socket and subscribe everything already held. */
export function boot({ principal: who = null, build = null } = {}) {
    if (state.started) {
        return
    }

    const now = Date.now()
    pusher = sharedPusher()

    Object.assign(state, {
        started: true,
        signedOut: false,
        newVersion: false,
        resetAt: 0,
        pulseAt: 0,
        connectedAt: 0,
        socketSince: now,
        relay: { ok: true, lagMs: 0 },
        catchUpFailing: false,
        lastSyncAt: 0,
        build: typeof build === 'string' && build !== '' ? build : null,
        now,
    })

    principal = who
    lastPollAt = 0
    pollSince = 0
    lastFullAt = now
    hiddenAt = 0
    wentOffline = false

    pusher.connection.bind('state_change', onConnectionState)
    state.socket = pusher.connection.state

    if (state.socket === 'connected') {
        state.connectedAt = now
    } else if (state.socket === 'disconnected' || state.socket === 'initialized') {
        pusher.connect()
    }

    pulseChannel = pusher.subscribe(PULSE_CHANNEL)
    pulseChannel.bind_global(onPulseEvent)

    for (const ch of channels.values()) {
        if (ch.refs > 0 && ch.sub === null) {
            subscribe(ch)
        }
    }

    tickTimer = setInterval(tick, 1_000)
    removeWindowListeners = listenToWindow()

    // The C8 harness waits for the page to be live before it acts (proofNote says when it asked).
    if (typeof window !== 'undefined' && window.__liveProof === true) {
        const book = (window.__live ??= { events: [] })
        book.status = (names = null) => statusDetail(names).status
    }
}

/**
 * Stop: every channel unsubscribed, every cursor forgotten, the socket disconnected but
 * kept (Echo holds it). A channel a mounted page still holds keeps its place, with no
 * cursor, and is subscribed again — and resynced — on the next start.
 */
export function stop() {
    if (!state.started) {
        return
    }

    generation++
    clearInterval(tickTimer)
    tickTimer = null
    clearTimeout(dueTimer)
    dueTimer = null
    due.clear()

    for (const request of requests) {
        request.superseded = true
        request.controller?.abort()
    }

    requests.clear()
    inFlight.clear()
    again.clear()
    catchUpDeadline = TIMING.requestTimeout
    dropQueuedAuthorizations()

    for (const ch of [...channels.values()]) {
        if (ch.refs === 0) {
            teardown(ch)

            continue
        }

        unsubscribe(ch)
        clearTimeout(ch.authTimer)
        ch.authTimer = null
        ch.cursors = {}
        ch.streams.clear()
        ch.buffer = []
        ch.rewind = {}
        ch.authRefused = false
        setPhase(ch, 'down')
    }

    if (pulseChannel !== null) {
        pulseChannel.unbind_global(onPulseEvent)
        pusher.unsubscribe(PULSE_CHANNEL)
        pulseChannel = null
    }

    pusher.connection.unbind('state_change', onConnectionState)
    pusher.disconnect()
    pusher = null
    removeWindowListeners()
    removeWindowListeners = () => {}
    principal = null
    flashes.clear()
    flashTimes.clear()

    Object.assign(state, { started: false, socket: 'initialized', signedOut: false, newVersion: false, now: Date.now() })
}

/** The principal key (`u-12`) the store was started for, or null. */
export function startedFor() {
    return principal
}

/** The page's build, to compare with the relay's. */
export function setBuild(build) {
    if (typeof build === 'string' && build !== '') {
        state.build = build
    }
}

/* ---- bindings ------------------------------------------------------------------------ */

/**
 * Register a binding: `{channels: {has(name)}, accepts(env), apply(env, channel),
 * resync(reason), afterCatchUp?(), signals?: false}`. Returns the unregister.
 */
export function register(handler) {
    // What a registration has applied is its own: registered again, it starts afresh.
    handler.seen = new Set()
    handlers.add(handler)

    return () => {
        handlers.delete(handler)
        pendingResync.delete(handler)
    }
}

/**
 * The holder's data was just replaced by data the server read at `heads` — a visit that kept
 * the page, as a write that redirects back does — so what this binding applied AFTER those
 * heads is forgotten, and applied again when the catch-up the rewind asks for brings it
 * (§5.3): the new data may lack it. What it applied at or below them, the new data holds.
 */
export function forgetAfter(handler, heads) {
    if (!handler?.seen) {
        return
    }

    const seeds = normalizeHeads(heads)

    for (const key of [...handler.seen]) {
        // `${s}:${ep}:${q}` (cursor.js eventKey): the stream first, the seq last.
        const stream = key.slice(0, key.indexOf(':'))
        const q = Number(key.slice(key.lastIndexOf(':') + 1))
        const seed = seeds[stream]

        if (seed && Number.isFinite(q) && q > seed.q) {
            handler.seen.delete(key)
        }
    }
}

/** Refresh, as the indicator's button does it: catch up now and resync the page's bindings. */
export function refresh() {
    if (!state.started) {
        return
    }

    lastFullAt = Date.now()

    if (state.socket !== 'connected' && state.socket !== 'connecting' && pusher !== null) {
        pusher.connect()
    }

    // The reader asked for now, not for after whatever is still out.
    supersede()
    requestCatchUp(activeNames(), 0, 'refresh')

    for (const handler of handlers) {
        if (handler.page !== false) {
            queueResync(handler, 'refresh')
        }
    }
}

/* ---- status -------------------------------------------------------------------------- */

/**
 * The status over `names` (every channel held when null), and since when it has been so.
 *
 * @returns {{status: 'connecting'|'catching-up'|'live'|'degraded'|'refused'|'delayed'|'signed-out', since: number}}
 */
export function statusDetail(names = null) {
    const now = state.now

    if (state.signedOut) {
        return { status: 'signed-out', since: 0 }
    }

    const list = (names ?? Object.keys(state.channels)).filter((name) => name in state.channels)
    const socketUp = state.started && state.socket === 'connected'
    let since = Infinity
    let refused = false

    if (!state.started) {
        since = moduleLoadedAt
    } else if (!socketUp) {
        since = state.socketSince
    }

    if (state.catchUpFailing) {
        since = Math.min(since, state.failingSince)
    }

    for (const name of list) {
        const phase = state.channels[name]

        if (phase === 'refused') {
            refused = true
        } else if (phase !== 'live') {
            since = Math.min(since, state.since[name] ?? now)
        }
    }

    if (since !== Infinity) {
        if (now - since >= TIMING.degradedAfter) {
            return { status: 'degraded', since }
        }

        return { status: socketUp ? 'catching-up' : 'connecting', since }
    }

    if (!state.relay.ok) {
        return { status: 'delayed', since: state.relaySince }
    }

    if (state.pulseAt === 0 || state.pulseAt < state.connectedAt) {
        return now - state.connectedAt >= TIMING.pulseFresh
            ? { status: 'delayed', since: state.connectedAt }
            : { status: 'connecting', since: state.connectedAt }
    }

    if (now - state.pulseAt >= TIMING.pulseFresh) {
        return { status: 'delayed', since: state.pulseAt }
    }

    return { status: refused ? 'refused' : 'live', since: 0 }
}

export function statusFor(names = null) {
    return statusDetail(names).status
}

/* ---- the quiet highlight's bookkeeping, and the origin nonces ------------------------ */

const flashes = reactive(new Map())
const flashTimes = new Map()
const flashElements = new Map()
let flashCounter = 0

/** Say that the record behind `key` changed now, so every element showing it flashes. */
export function markFlash(key) {
    const now = Date.now()

    for (const [each, at] of flashTimes) {
        if (now - at > 10_000) {
            flashTimes.delete(each)
            flashes.delete(each)
        }
    }

    flashes.set(key, ++flashCounter)
    flashTimes.set(key, now)
}

/** A number that moves each time `key` is flashed (0 if it never was). Reactive. */
export function flashSeq(key) {
    return flashes.get(key) ?? 0
}

/** How long ago `key` was last flashed (Infinity if never, or if the clock has since gone back). */
export function flashAge(key) {
    const at = flashTimes.get(key)
    const age = at === undefined ? Infinity : Date.now() - at

    return age >= 0 ? age : Infinity
}

export function registerFlashElement(key, el) {
    if (!flashElements.has(key)) {
        flashElements.set(key, new Set())
    }

    flashElements.get(key).add(el)
}

export function unregisterFlashElement(key, el) {
    flashElements.get(key)?.delete(el)

    if (flashElements.get(key)?.size === 0) {
        flashElements.delete(key)
    }
}

/** Whether the focus is inside an element showing `key`: that row is being worked on. */
export function hasFocusWithin(key) {
    const active = typeof document !== 'undefined' ? document.activeElement : null

    if (!active) {
        return false
    }

    for (const el of flashElements.get(key) ?? []) {
        if (el === active || (typeof el.contains === 'function' && el.contains(active))) {
            return true
        }
    }

    return false
}

const origins = new Map()

function randomHex(bytes) {
    const values = new Uint8Array(bytes)

    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(values)
    } else {
        for (let at = 0; at < bytes; at++) {
            values[at] = Math.floor(Math.random() * 256)
        }
    }

    return [...values].map((value) => value.toString(16).padStart(2, '0')).join('')
}

/**
 * A new origin nonce for one write: 16 random hex characters, never reused, never a stable
 * id (§6.1). Remembered for 2 minutes, so the change it causes is not flashed on this tab.
 */
export function mintOrigin() {
    const now = Date.now()

    for (const [nonce, at] of origins) {
        if (now - at >= TIMING.ownOrigin) {
            origins.delete(nonce)
        }
    }

    let nonce = randomHex(8)

    while (origins.has(nonce)) {
        nonce = randomHex(8)
    }

    origins.set(nonce, now)

    return nonce
}

/** Whether `origin` is a nonce this tab sent within the last 2 minutes. */
export function isOwnOrigin(origin) {
    if (typeof origin !== 'string') {
        return false
    }

    const at = origins.get(origin)

    return at !== undefined && Date.now() - at < TIMING.ownOrigin
}

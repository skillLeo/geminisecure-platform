/**
 * THE LIVE STORE AND ITS SOCKET (M11 Part C, design §5.3–§5.6, §6.1, §6.5, §7.2).
 *
 *   one Pusher, made only by startLive — the sign-in page makes none — with echo.js's
 *   values, and Echo handed that same instance;
 *   authorisation batched: five channels subscribed in one tick are ONE POST to /live/auth,
 *   and a legacy name still goes to /broadcasting/auth;
 *   a channel held by a page survives an unmount and a remount within 30 s with no second
 *   authorisation, and is let go after;
 *   catch-up after every subscribe, batched, applied before the channel goes live; what
 *   arrives meanwhile is buffered and applied after it, in order, with no duplicates;
 *   a cursor seeded from the page's heads causes no catch-up of its own; a gap does, after
 *   300 ms; an epoch change and a `reset` resync the screen's bindings;
 *   while down, catch-up is the fallback poll (10 s, 3 s on dispatch screens); a 5-minute
 *   safety catch-up runs while live;
 *   a live event never reloads the page; `access.changed` does, and diff-subscribes;
 *   a 401 or 419 means the session ended;
 *   every Inertia write carries a fresh X-Live-Origin nonce, and this tab does not flash
 *   its own change.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { defineComponent, h } from 'vue'
import { mount, nextTick } from './support/render.mjs'
import { fireRouterEvent, http, page, replaced, resetInertia, visits } from './support/inertia.mjs'
import { liveServer, liveSocket, pusherOptions } from './support/live-socket.mjs'

const live = await import('../../resources/js/live/index.js')
const store = await import('../../resources/js/live/store.js')
const { startLive, stopLive, useLive, liveList } = live

const T0 = Date.parse('2026-10-02T14:02:31Z')

const opened = new Set()

async function settle(turns = 30) {
    for (let i = 0; i < turns; i++) {
        await Promise.resolve()
    }

    await nextTick()
}

/*
 * The clock moves as a real one does, in small steps: Node's mock timers run every timer
 * due in one `tick(ms)` with Date.now() already at the END of it, so one big jump would
 * show the store a clock no browser ever shows it.
 */
async function tick(ms) {
    let left = ms

    do {
        const step = Math.min(left, 100)
        mock.timers.tick(step)
        left -= step
        await settle()
    } while (left > 0)
}

const shellProps = (overrides = {}) => ({ shell: ['user.7'], heads: { c: 100, e: 50 }, principal: 'u-7', build: 'build-1', ...overrides })

/** The socket connects; the batched auth goes, then the batched catch-up; a pulse arrives. */
async function connect() {
    liveSocket.open()
    await tick(20)
    await tick(50)
    liveSocket.pulse({ build: 'build-1' })
    await settle()
}

/** A page: one component holding `channels` through useLive, with the given bindings. */
async function mountPage(pageLive, specs = {}, options = {}) {
    const Page = defineComponent({
        setup() {
            const handle = useLive(pageLive, specs, options)

            return () => h('div', `status:${handle.status}`)
        },
    })

    const view = await mount(Page)
    opened.add(view)

    return view
}

const leave = (view) => {
    view.unmount()
    opened.delete(view)
}

const authPosts = () => liveServer.requests.filter((request) => request.url === '/live/auth')
const catchUps = () => liveServer.requests.filter((request) => request.url === '/live/catch-up')

beforeEach(() => {
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: T0 })
    liveServer.reset()
    // The server's heads when the page was drawn: the same heads the page carries.
    liveServer.heads = { c: 100, e: 50 }
    liveServer.install()
    liveSocket.reset()
    resetInertia({}, '/estate/pp/maintenance')
    globalThis.document.cookie = 'XSRF-TOKEN=token%3D1; other=x'
})

afterEach(async () => {
    for (const view of opened) {
        view.unmount()
    }

    opened.clear()
    stopLive()
    await settle()
    mock.timers.reset()
    delete globalThis.document.cookie
})

describe('one socket, and only for a signed-in shell', () => {
    it('makes no Pusher on the sign-in page, which has no live prop', async () => {
        resetInertia({ auth: { user: null } }, '/login')

        assert.equal(startLive(page.props.live), null)
        const view = await mountPage(page.props.live, {})

        assert.equal(liveSocket.instances.length, 0)
        assert.equal(view.text(), 'status:connecting')
    })

    it('makes exactly one, with the values echo.js carried, however often the shell starts it', () => {
        startLive(shellProps())
        startLive(shellProps())

        assert.equal(liveSocket.instances.length, 1)

        const options = liveSocket.current().options
        assert.equal(options.cluster, '')
        assert.deepEqual(options.enabledTransports, ['ws', 'wss'])
        assert.equal(options.activityTimeout, 30000)
        assert.equal(options.pongTimeout, 10000)
        assert.equal(typeof options.channelAuthorization.customHandler, 'function')
        assert.deepEqual(Object.keys(pusherOptions()).sort(), Object.keys(options).sort())
    })

    it('hands Echo that same instance rather than letting it make its own', async () => {
        startLive(shellProps())
        const { echo } = await import('../../resources/js/echo.js?real')

        assert.equal(echo.connector.pusher, liveSocket.current())
        assert.equal(liveSocket.instances.length, 1)
    })

    it('runs whenLiveStarts once the socket exists, and at once when it already does', () => {
        let ran = 0
        live.whenLiveStarts(() => ran++)

        assert.equal(ran, 0)
        startLive(shellProps())
        assert.equal(ran, 1)
    })
})

describe('authorisation', () => {
    it('sends five channels subscribed in one tick as ONE POST, with the cookie token', async () => {
        startLive(shellProps({ shell: ['user.7', 'estate.pp.security', 'estate.pp.console', 'estate.pp.facilities', 'estate.pp.residents'] }))
        liveSocket.open()
        await tick(20)

        assert.equal(authPosts().length, 1)
        const post = authPosts()[0]
        assert.equal(post.method, 'POST')
        assert.equal(post.headers['X-XSRF-TOKEN'], 'token=1')
        assert.equal(post.body.socket_id, '1234.5678')
        // `channel_names`: the key f-web-auth's LiveAuthController and the API door read.
        assert.deepEqual(Object.keys(post.body).sort(), ['channel_names', 'socket_id'])
        assert.deepEqual(post.body.channel_names.sort(), [
            'private-live.estate.pp.console',
            'private-live.estate.pp.facilities',
            'private-live.estate.pp.residents',
            'private-live.estate.pp.security',
            'private-live.user.7',
        ])

        // And the server was told of each subscription, signed.
        assert.equal(liveSocket.current().channel('private-live.user.7').auth.auth, 'test-key:user.7')
    })

    it('waits the 20 ms window before sending, so a later subscribe in the window joins the batch', async () => {
        startLive(shellProps())
        liveSocket.open()
        await tick(10)
        assert.equal(authPosts().length, 0)

        liveSocket.current().subscribe('private-live.estate.pp.console')
        await tick(10)

        assert.equal(authPosts().length, 1)
        assert.equal(authPosts()[0].body.channel_names.length, 2)
    })

    it('still sends a legacy channel (the panic composables\') to /broadcasting/auth, one by one', async () => {
        startLive(shellProps())
        liveSocket.open()
        liveSocket.current().subscribe('private-estate.pp.alerts')
        await settle()

        const legacy = liveServer.last('/broadcasting/auth')
        assert.ok(legacy)
        assert.equal(legacy.body, 'socket_id=1234.5678&channel_name=private-estate.pp.alerts')
        assert.equal(legacy.headers['X-XSRF-TOKEN'], 'token=1')
        assert.ok(liveSocket.isSubscribed('private-estate.pp.alerts'))
    })

    it('keeps a refused channel refused, and says so, without asking again', async () => {
        liveServer.refused.add('estate.pp.dues')
        startLive(shellProps({ shell: ['user.7', 'estate.pp.dues'] }))
        await connect()

        assert.equal(store.state.channels['estate.pp.dues'], 'refused')
        assert.equal(store.statusFor(), 'refused')

        await tick(60_000)
        assert.equal(authPosts().length, 1)
    })

    it('answers every channel of a batch even when one channel\'s subscriber throws', async () => {
        const reported = []
        const error = console.error
        console.error = (thrown) => reported.push(thrown)

        try {
            startLive(shellProps({ shell: ['user.7', 'estate.pp.console', 'estate.pp.security'] }))
            liveSocket.current().subscribe('private-live.estate.pp.console').bind('pusher:subscription_succeeded', () => {
                throw new Error('a subscriber broke')
            })
            liveSocket.open()
            await tick(20)
            await tick(50)

            assert.equal(authPosts().length, 1)
            assert.equal(store.state.channels['user.7'], 'live')
            assert.equal(store.state.channels['estate.pp.security'], 'live')
            assert.equal(reported.length, 1)
        } finally {
            console.error = error
        }
    })

    it('asks again later when authorisation failed rather than was refused', async () => {
        liveServer.authStatus = 500
        startLive(shellProps())
        liveSocket.open()
        await tick(20)
        assert.equal(store.state.channels['user.7'], 'down')

        liveServer.authStatus = null
        await tick(2_000)
        await tick(20)
        await tick(50)

        assert.equal(authPosts().length, 2)
        assert.equal(store.state.channels['user.7'], 'live')
    })

    it('gives up on an authorisation that never answers after 10 s, and asks again', async () => {
        liveServer.holdAuth = true
        startLive(shellProps())
        liveSocket.open()
        await tick(20)
        assert.equal(authPosts().length, 1)

        await tick(9_900)
        assert.equal(authPosts()[0].signal?.aborted, false, 'still waited for: it is only slow so far')

        await tick(200)
        assert.equal(authPosts()[0].signal?.aborted, true)
        assert.equal(authPosts().length, 1)
        assert.equal(store.state.channels['user.7'], 'down')

        liveServer.holdAuth = false
        await tick(2_000)
        await tick(20)
        await tick(50)

        assert.equal(authPosts().length, 2)
        assert.equal(store.state.channels['user.7'], 'live')
    })

    it('never cuts short the legacy panic channel\'s authorisation, which nothing would ask for again', async () => {
        liveServer.holdLegacy = true
        startLive(shellProps())
        liveSocket.open()
        const errors = []
        const legacy = liveSocket.current().subscribe('private-estate.pp.alerts')
        legacy.bind('pusher:subscription_error', (error) => errors.push(error))

        await tick(60_000)
        assert.deepEqual(errors, [], 'a slow /broadcasting/auth is waited for, as pusher-js itself waited')

        liveServer.releaseLegacy()
        await settle()
        assert.ok(liveSocket.isSubscribed('private-estate.pp.alerts'))
    })

    it('reports a batch the door could not read (422), rather than only retrying it in silence', async () => {
        const reported = []
        const error = console.error
        console.error = (thrown) => reported.push(thrown)

        try {
            liveServer.authStatus = 422
            startLive(shellProps())
            liveSocket.open()
            await tick(20)

            assert.equal(reported.length, 1)
            assert.match(String(reported[0]?.message ?? reported[0]), /422/)
            assert.equal(store.state.channels['user.7'], 'down', 'a failure, not a refusal')
        } finally {
            console.error = error
        }
    })
})

describe('a channel outlives the page that held it by 30 s', () => {
    it('survives an unmount and a remount within 30 s with no second authorisation', async () => {
        startLive(shellProps({ shell: [] }))
        const pageLive = { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: [], kind: 'data' }
        const first = await mountPage(pageLive)
        await connect()

        assert.equal(authPosts().length, 1)
        assert.ok(liveSocket.isSubscribed('private-live.estate.pp.facilities'))

        leave(first)
        await tick(29_000)
        await mountPage(pageLive)
        await tick(5_000)

        assert.equal(authPosts().length, 1)
        assert.deepEqual(liveSocket.unsubscribed, [])
        assert.equal(store.state.channels['estate.pp.facilities'], 'live')
    })

    it('is unsubscribed 30 s after the last page let go of it', async () => {
        startLive(shellProps({ shell: [] }))
        const view = await mountPage({ channels: ['estate.pp.facilities'], heads: {}, bindings: [], kind: 'data' })
        await connect()

        leave(view)
        await tick(29_999)
        assert.deepEqual(liveSocket.unsubscribed, [])

        await tick(1)
        assert.deepEqual(liveSocket.unsubscribed, ['private-live.estate.pp.facilities'])
        assert.equal('estate.pp.facilities' in store.state.channels, false)
    })

    /** A page that holds the channel and records what reaches it, and when. */
    const recordingPage = async (pageLive, applied) => {
        const unregister = store.register({
            channels: { has: (name) => name === 'estate.pp.facilities' },
            accepts: () => true,
            apply: (env) => applied.push({ q: env.q, at: Date.now() }),
            resync() {},
        })
        const view = await mountPage(pageLive)

        return { view, unregister }
    }

    it('catches a channel up at once when a page holds it again after it reconnected in its grace, and never shows amber for it', async () => {
        startLive(shellProps({ shell: [] }))
        const pageLive = { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: [], kind: 'data' }
        const first = await mountPage(pageLive)
        await connect()

        // The page leaves; while nothing holds the channel, the socket drops and comes back.
        leave(first)
        await tick(1_000)
        liveSocket.drop()
        await tick(5_000)
        liveSocket.open()
        await tick(20)
        await tick(50)
        liveSocket.pulse()
        await tick(1_000)
        assert.ok(liveSocket.isSubscribed('private-live.estate.pp.facilities'), 'pusher-js resubscribed it')

        // The page comes back within the grace, and an event arrives on the socket at once.
        const applied = []
        const again = await recordingPage(pageLive, applied)
        const heldAt = Date.now()
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities'))

        const statuses = []

        for (let waited = 0; waited < 350; waited += 50) {
            statuses.push(store.statusFor())
            await tick(50)
        }

        assert.deepEqual(applied.map((each) => each.q), [51])
        assert.ok(applied[0].at - heldAt <= 350, `applied ${applied[0].at - heldAt} ms after the page held it`)
        assert.equal(store.state.channels['estate.pp.facilities'], 'live')
        assert.ok(!statuses.includes('degraded'), `never amber: ${statuses.join(', ')}`)
        assert.equal(authPosts().length, 2, 'the reconnect\'s authorisation, and no other')
        again.unregister()
    })

    it('subscribes a channel again at once when a page holds it again after its authorisation failed in its grace', async () => {
        startLive(shellProps({ shell: [] }))
        const pageLive = { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: [], kind: 'data' }
        const first = await mountPage(pageLive)
        await connect()

        leave(first)
        await tick(1_000)

        // It reconnects in the grace, the authorisation fails, and the retry 2 s later finds nothing holding it.
        liveServer.authStatus = 500
        liveSocket.drop()
        await tick(100)
        liveSocket.open()
        await tick(20)
        assert.equal(liveSocket.isSubscribed('private-live.estate.pp.facilities'), false)
        await tick(3_000)
        liveServer.authStatus = null
        liveSocket.pulse()
        const posts = authPosts().length

        await mountPage(pageLive)
        await tick(20)
        await tick(50)

        assert.equal(authPosts().length, posts + 1, 'asked again as soon as it was held')
        assert.equal(store.state.channels['estate.pp.facilities'], 'live')
        assert.notEqual(store.statusFor(), 'degraded')
    })
})

describe('catch-up before live', () => {
    it('asks about every channel subscribed together in ONE catch-up, from the page heads', async () => {
        startLive(shellProps({ shell: ['user.7', 'estate.pp.security'] }))
        liveSocket.open()
        await tick(20)

        assert.equal(catchUps().length, 0, 'not before the 50 ms batch')
        await tick(50)

        assert.equal(catchUps().length, 1)
        const asked = Object.fromEntries(catchUps()[0].body.channels.map((entry) => [entry.name, entry]))
        assert.deepEqual(asked['user.7'], { name: 'user.7', c: 100 })
        assert.deepEqual(asked['estate.pp.security'], { name: 'estate.pp.security', c: 100, e: 50 })
        assert.equal(store.state.channels['user.7'], 'live')
    })

    it('applies what was missed before going live, and buffers what arrives meanwhile, in order, once each', async () => {
        const seen = []
        liveServer.heads = { c: 100, e: 50 }
        startLive(shellProps({ shell: [] }))
        await mountPage(
            { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: ['rows'], kind: 'data' },
            {},
        )

        // A binding that records what it is handed, registered as a page's would be.
        const unregister = store.register({
            channels: { has: (name) => name === 'estate.pp.facilities' },
            accepts: () => true,
            apply: (env) => seen.push(env.q),
            resync() {},
        })

        // Two events were published while this page was loading; the socket saw neither.
        liveServer.publish('estate.pp.facilities', { i: '1' })
        liveServer.publish('estate.pp.facilities', { i: '2' })

        liveServer.holdCatchUps = true
        liveSocket.open()
        await tick(20)
        await tick(50)
        assert.equal(store.state.channels['estate.pp.facilities'], 'catching-up')

        // While the catch-up is out: the next event arrives live, and so does a repeat of 52 —
        // and one from the central stream that is no gap at all, which only buffering holds back.
        const third = liveServer.publish('estate.pp.facilities', { i: '3' })
        liveSocket.deliver('estate.pp.facilities', third)
        liveSocket.deliver('estate.pp.facilities', liveServer.log[1].env)
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities', { s: 'c', i: 'gate' }))
        await settle()
        assert.deepEqual(seen, [], 'nothing is applied before the catch-up')

        liveServer.releaseCatchUps()
        await settle()

        assert.deepEqual(seen.filter((q) => q < 100), [51, 52, 53], 'the estate stream in order')
        assert.deepEqual(seen.filter((q) => q > 100), [101], 'the central one once')
        assert.equal(seen.length, 4, 'no duplicates')
        assert.equal(store.state.channels['estate.pp.facilities'], 'live')
        unregister()
    })

    it('neither waits for nor goes live on a catch-up asked for before the latest subscribe', async () => {
        startLive(shellProps({ shell: ['user.7'] }))
        await connect()

        // The socket drops; the fallback poll asks while it is down, and is slow to answer.
        liveSocket.drop()
        await settle()
        liveServer.holdCatchUps = true
        await tick(11_000)
        assert.equal(catchUps().length, 2)

        // It reconnects before that answer lands: the answer would predate the subscription.
        liveSocket.open()
        await tick(20)
        await tick(50)

        assert.equal(catchUps().length, 3, 'asked again after the subscribe, not after the older answer')
        assert.equal(catchUps()[1].signal?.aborted, true, 'and the browser told to drop the older request')
        assert.equal(store.state.channels['user.7'], 'catching-up')

        liveServer.holdCatchUps = false
        liveServer.releaseCatchUps()
        await settle()

        assert.equal(store.state.channels['user.7'], 'live')
        assert.equal(catchUps().length, 3)
    })

    it('sends the catch-up at once after a reconnect even though the one sent on the dead network never answers', async () => {
        const applied = []
        startLive(shellProps({ shell: ['user.7', 'estate.pp.security'] }))
        const unregister = store.register({
            channels: { has: (name) => name === 'estate.pp.security' },
            accepts: () => true,
            apply: (env) => applied.push(env.q),
            resync() {},
        })
        await connect()

        // The network goes: the socket drops, and the fallback poll it sends then hangs.
        liveServer.holdCatchUps = true
        liveSocket.drop()
        await tick(11_000)
        const hung = catchUps().length

        // The network is back: pusher-js reconnects and resubscribes; the server answers again.
        liveServer.holdCatchUps = false
        liveSocket.open()
        await tick(20)
        await tick(50)
        liveSocket.pulse()
        const env = liveServer.publish('estate.pp.security')
        liveSocket.deliver('estate.pp.security', env)
        await settle()

        assert.equal(catchUps().length, hung + 1, 'one catch-up, within 70 ms of the reconnect')
        assert.deepEqual(applied, [env.q])
        assert.equal(store.state.channels['estate.pp.security'], 'live')
        assert.equal(store.statusFor(), 'live')
        unregister()
    })
})

describe('the cursor rules, on a channel a page holds', () => {
    const recorder = (name) => {
        const seen = []
        const unregister = store.register({
            channels: { has: (each) => each === name },
            accepts: () => true,
            apply: (env) => seen.push(env.q),
            resync: (why) => seen.push(`resync:${why}`),
        })

        return { seen, unregister }
    }

    it('applies the first event after a page load at once: a head-seeded cursor is not a gap', async () => {
        liveServer.heads = { c: 100, e: 50 }
        // This channel's previous event was long before the page's head.
        liveServer.log.push({ channel: 'estate.pp.facilities', env: { s: 'e', q: 20 } })
        startLive(shellProps({ shell: ['estate.pp.facilities'] }))
        await connect()
        const { seen, unregister } = recorder('estate.pp.facilities')
        const before = catchUps().length

        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities', { p: 20 }))
        await tick(1_000)

        assert.deepEqual(seen, [51])
        assert.equal(catchUps().length, before, 'no catch-up for it')
        unregister()
    })

    it('drops a duplicate', async () => {
        startLive(shellProps({ shell: ['estate.pp.facilities'] }))
        await connect()
        const { seen, unregister } = recorder('estate.pp.facilities')
        const event = liveServer.publish('estate.pp.facilities')

        liveSocket.deliver('estate.pp.facilities', event)
        liveSocket.deliver('estate.pp.facilities', event)
        await settle()

        assert.deepEqual(seen, [51])
        unregister()
    })

    it('catches up on a gap after 300 ms, and applies the missed event before the one that showed it', async () => {
        startLive(shellProps({ shell: ['estate.pp.facilities'] }))
        await connect()
        const { seen, unregister } = recorder('estate.pp.facilities')
        const before = catchUps().length

        liveServer.publish('estate.pp.facilities', { i: 'missed' })
        const shown = liveServer.publish('estate.pp.facilities', { i: 'shown' })
        liveSocket.deliver('estate.pp.facilities', shown)
        await tick(299)
        assert.equal(catchUps().length, before)

        await tick(1)
        assert.equal(catchUps().length, before + 1)
        assert.deepEqual(seen, [51, 52])
        unregister()
    })

    it('needs no request when the late message fills the gap within the 300 ms', async () => {
        startLive(shellProps({ shell: ['estate.pp.facilities'] }))
        await connect()
        const { seen, unregister } = recorder('estate.pp.facilities')
        const before = catchUps().length

        const late = liveServer.publish('estate.pp.facilities')
        const early = liveServer.publish('estate.pp.facilities')
        liveSocket.deliver('estate.pp.facilities', early)
        await tick(100)
        liveSocket.deliver('estate.pp.facilities', late)
        await tick(300)

        assert.equal(catchUps().length, before)
        assert.deepEqual(seen, [51, 52])
        unregister()
    })

    it('resyncs the bindings on an epoch change, instead of dropping a restarted counter as duplicates', async () => {
        startLive(shellProps({ shell: ['estate.pp.facilities'] }))
        await connect()
        const { seen, unregister } = recorder('estate.pp.facilities')

        // The first event teaches the cursor its stream's epoch.
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities'))
        // The estate database is re-provisioned: a new epoch, and the counter starts again.
        liveSocket.deliver('estate.pp.facilities', { ...liveServer.publish('estate.pp.facilities'), ep: 'EPOCH999', q: 3, p: null })
        await settle()

        assert.deepEqual(seen, [51, 'resync:reset'])

        // From there the new epoch's numbers are the ones that count.
        liveSocket.deliver('estate.pp.facilities', { ...liveServer.publish('estate.pp.facilities'), ep: 'EPOCH999', q: 4, p: 3 })
        await settle()
        assert.deepEqual(seen, [51, 'resync:reset', 4])
        unregister()
    })

    it('knows the epoch from the page heads when the server sends it there, before any event', async () => {
        startLive(shellProps({ shell: ['estate.pp.facilities'], heads: { c: 100, e: { q: 50, ep: 'EPOCH002' } } }))
        await connect()
        const { seen, unregister } = recorder('estate.pp.facilities')

        liveSocket.deliver('estate.pp.facilities', { ...liveServer.publish('estate.pp.facilities'), ep: 'EPOCH999', q: 3, p: null })
        await settle()

        assert.deepEqual(seen, ['resync:reset'])
        assert.deepEqual(catchUps()[0].body.channels[0].ep, { e: 'EPOCH002' })
        unregister()
    })

    it('resyncs the bindings when the catch-up answers reset', async () => {
        liveServer.resets.add('estate.pp.facilities')
        startLive(shellProps({ shell: ['estate.pp.facilities'] }))
        const { seen, unregister } = recorder('estate.pp.facilities')
        await connect()

        assert.deepEqual(seen, ['resync:reset'])
        assert.equal(store.state.channels['estate.pp.facilities'], 'live')
        unregister()
    })

    it('winds a held channel back to a newly mounted page\'s heads, and catches that page up', async () => {
        startLive(shellProps({ shell: [] }))
        const first = await mountPage({ channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: [], kind: 'data' })
        await connect()

        // Two events land live, on the first page.
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities'))
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities'))
        await settle()

        // The next page's data was read at head 51: event 52 may not be in it.
        const { seen, unregister } = recorder('estate.pp.facilities')
        leave(first)
        await mountPage({ channels: ['estate.pp.facilities'], heads: { c: 100, e: 51 }, bindings: [], kind: 'data' })
        await tick(50)

        assert.equal(catchUps().pop().body.channels[0].e, 51)
        assert.deepEqual(seen, [52])
        unregister()
    })

    it('re-applies what a page\'s new props may lack when the page survives the visit, as after a write that redirects back', async () => {
        const pageLive = () => ({ channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: ['rows'], kind: 'data' })
        resetInertia({ rows: [{ id: 11, status: 'open' }], live: pageLive() }, '/estate/pp/maintenance')
        startLive(shellProps({ shell: [] }))
        await mountPage(page.props.live, { rows: liveList('rows', { entity: 'gate_event', rowsUrl: '/rows' }) })
        await connect()

        // A change lands live, and is applied to the list.
        const change = liveServer.publish('estate.pp.facilities', { e: 'gate_event', i: '11', m: 'inline', d: { id: 11, status: 'admitted' } })
        liveSocket.deliver('estate.pp.facilities', change)
        await settle()
        assert.equal(page.props.rows[0].status, 'admitted')

        // The reader's own write redirects back, preserving state: the component stays, and its
        // props are replaced by data the server read at head 50 — before that change committed.
        page.props.rows = [{ id: 11, status: 'open' }]
        page.props.live = pageLive()
        await tick(50)

        assert.equal(catchUps().pop().body.channels[0].e, 50, 'wound back to the new props\' heads')
        assert.equal(page.props.rows[0].status, 'admitted', 'and the change applied to the new props as well')
    })

    it('does not re-apply what the new props already hold: only what came after their heads', async () => {
        const applied = []
        resetInertia({ live: { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: ['rows'], kind: 'data' } }, '/x')
        startLive(shellProps({ shell: [] }))
        await mountPage(page.props.live, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: '/rows', idsFor: (env) => (applied.push(env.q), []) }),
        })
        await connect()

        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities'))
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities'))
        await settle()
        assert.deepEqual(applied, [51, 52])

        // New props read at 51: they hold 51, and may lack 52.
        page.props.live = { channels: ['estate.pp.facilities'], heads: { c: 100, e: 51 }, bindings: ['rows'], kind: 'data' }
        await tick(50)

        assert.deepEqual(applied, [51, 52, 52])
    })
})

describe('the fallback poll and the safety catch-up', () => {
    it('catches up every 10 s while the socket is down', async () => {
        startLive(shellProps())
        await connect()
        const before = catchUps().length

        liveSocket.drop()
        await tick(9_000)
        assert.equal(catchUps().length, before)

        await tick(2_000)
        assert.equal(catchUps().length, before + 1)

        await tick(10_000)
        assert.equal(catchUps().length, before + 2)
    })

    it('catches up every 3 s while a dispatch screen is open', async () => {
        startLive(shellProps({ shell: ['gemini.dispatch'] }))
        await mountPage({ channels: ['gemini.dispatch'], heads: { c: 100 }, bindings: ['alerts'], kind: 'data' }, {}, { dispatch: true })
        await connect()
        const before = catchUps().length

        liveSocket.drop()
        await tick(4_000)
        assert.equal(catchUps().length, before + 1)

        await tick(3_000)
        assert.equal(catchUps().length, before + 2)
    })

    it('gives up on a catch-up that never answers after 10 s, tells the browser to drop it, and the poll asks again', async () => {
        startLive(shellProps())
        await connect()

        liveServer.holdCatchUps = true
        liveSocket.drop()
        await tick(11_000)
        assert.equal(catchUps().length, 2, 'the poll went, and hangs')
        liveServer.holdCatchUps = false

        await tick(8_000)
        assert.equal(catchUps().length, 2, 'nothing else while it is out')

        await tick(2_000)
        assert.equal(catchUps()[1].signal?.aborted, true, 'given up on at 10 s')

        await tick(1_000)
        assert.equal(catchUps().length, 3, 'asked again on the next tick of the poll')
        assert.equal(store.state.catchUpFailing, false, 'and that one answered')
    })

    it('gives the next catch-up longer after one timed out, so a slow answer is still an answer', async () => {
        startLive(shellProps())
        await connect()

        // Every answer now takes 15 s: longer than the first deadline, shorter than the next.
        liveServer.catchUpDelay = 15_000
        liveSocket.drop()
        await tick(11_000)
        assert.equal(catchUps().length, 2)

        await tick(11_000)
        assert.equal(catchUps()[1].signal?.aborted, true, 'the first was given up on at 10 s')
        assert.equal(catchUps().length, 3)
        assert.equal(store.state.catchUpFailing, true)

        await tick(15_000)
        assert.equal(store.state.catchUpFailing, false, 'the second, given 20 s, was answered at 15 s')
        assert.equal(catchUps()[2].signal?.aborted, false)
        assert.equal(catchUps().length, 3)
    })

    it('starts the catch-up at once on coming back online, though one sent on the dead network is still out', async () => {
        const listeners = new Map()
        globalThis.addEventListener = (type, handler) => listeners.set(type, handler)
        globalThis.removeEventListener = (type) => listeners.delete(type)

        try {
            startLive(shellProps())
            await connect()

            liveServer.holdCatchUps = true
            liveSocket.drop()
            listeners.get('offline')()
            await tick(11_000)
            const hung = catchUps().length
            liveServer.holdCatchUps = false

            listeners.get('online')()
            await tick(0)

            assert.equal(catchUps().length, hung + 1)
            assert.equal(catchUps()[hung - 1].signal?.aborted, true)
        } finally {
            stopLive()
            delete globalThis.addEventListener
            delete globalThis.removeEventListener
        }
    })

    it('catches up at once when the reader presses Refresh, though an earlier catch-up is still out', async () => {
        startLive(shellProps())
        await connect()

        liveServer.holdCatchUps = true
        store.requestCatchUp(['user.7'], 0, 'test')
        await tick(0)
        const hung = catchUps().length
        liveServer.holdCatchUps = false

        store.refresh()
        await tick(0)

        assert.equal(catchUps().length, hung + 1)
        assert.equal(store.state.channels['user.7'], 'live')
    })

    it('runs a safety catch-up every 5 minutes while live', async () => {
        startLive(shellProps())
        await connect()
        const before = catchUps().length

        await tick(299_000)
        assert.equal(catchUps().length, before)

        await tick(1_000)
        assert.equal(catchUps().length, before + 1)
    })

    const away = async (ms, { pulses = false } = {}) => {
        globalThis.document.hidden = true
        globalThis.document.dispatchEvent('visibilitychange')

        for (let left = ms; left > 0; left -= 10_000) {
            await tick(Math.min(left, 10_000))

            if (pulses) {
                liveSocket.pulse()
            }
        }

        globalThis.document.hidden = false
        globalThis.document.dispatchEvent('visibilitychange')
        await tick(0)
    }

    it('changes nothing after a glance away', async () => {
        startLive(shellProps())
        await connect()
        const before = catchUps().length

        await away(5_000)

        assert.equal(catchUps().length, before)
        assert.equal(liveSocket.current().connection.state, 'connected')
    })

    it('catches up at once after 30 s away, and leaves a socket that kept its pulse alone', async () => {
        startLive(shellProps())
        await connect()
        const before = catchUps().length

        await away(30_000, { pulses: true })

        assert.equal(catchUps().length, before + 1)
        assert.equal(liveSocket.current().connection.state, 'connected', 'the panic channel on it is not dropped for nothing')
    })

    it('catches up and reconnects at once after 30 s away when the pulse stopped: a socket dead without knowing it', async () => {
        startLive(shellProps())
        await connect()
        const before = catchUps().length

        await away(30_000)

        assert.equal(catchUps().length, before + 1)
        assert.equal(liveSocket.current().connection.state, 'connecting', 'a forced reconnect, not a wait for the ping to time out')
    })

    it('catches up and reconnects at once when the browser comes back online after being offline', async () => {
        const listeners = new Map()
        globalThis.addEventListener = (type, handler) => listeners.set(type, handler)
        globalThis.removeEventListener = (type) => listeners.delete(type)

        try {
            startLive(shellProps())
            await connect()
            const before = catchUps().length

            listeners.get('online')()
            await tick(0)
            assert.equal(catchUps().length, before + 1, 'online always catches up')
            assert.equal(liveSocket.current().connection.state, 'connected', 'a socket with a fresh pulse is left alone')

            listeners.get('offline')()
            listeners.get('online')()
            await tick(0)

            assert.equal(catchUps().length, before + 2)
            assert.equal(liveSocket.current().connection.state, 'connecting')
        } finally {
            stopLive()
            delete globalThis.addEventListener
            delete globalThis.removeEventListener
        }
    })
})

describe('no full reloads', () => {
    it('never reloads the page for a live event', async () => {
        liveServer.routes.set('/estate/pp/maintenance/live', () => [200, { rows: { 51: { id: 51, status: 'open' } } }])
        resetInertia({ rows: [] }, '/estate/pp/maintenance')
        startLive(shellProps({ shell: ['user.7', 'estate.pp.facilities'] }))
        await mountPage(
            { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: ['rows'], kind: 'data' },
            { rows: liveList('rows', { entity: 'ticket', rowsUrl: '/estate/pp/maintenance/live' }) },
        )
        await connect()

        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities', { i: '51' }))
        liveSocket.deliver('user.7', liveServer.publish('user.7', { t: 'notification.read', e: 'notification', i: 'x' }))
        await tick(200)

        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 0)
        assert.deepEqual(page.props.rows, [{ id: 51, status: 'open' }])
        assert.ok(replaced.length > 0)
    })

    it('reloads once on access.changed, keeping the scroll, and diff-subscribes to the new shell', async () => {
        startLive(shellProps({ shell: ['user.7', 'estate.pp.dues'] }))
        await connect()

        liveSocket.deliver('user.7', liveServer.publish('user.7', { t: 'access.changed', e: 'access', i: 'u-7', sum: { effect: 'reduced' } }))
        await settle()

        const reloads = visits.filter((visit) => visit.method === 'reload')
        assert.equal(reloads.length, 1)
        assert.equal(reloads[0].options.preserveScroll, true)

        reloads[0].options.onSuccess({ props: { live: shellProps({ shell: ['user.7', 'estate.pp.console'] }) } })
        reloads[0].options.onFinish()
        await tick(30_000)

        assert.ok(liveSocket.unsubscribed.includes('private-live.estate.pp.dues'))
        assert.ok(liveSocket.current().channel('private-live.estate.pp.console'))
    })

    it('does not reload for access.changed on any channel but the viewer\'s own', async () => {
        startLive(shellProps({ shell: ['user.7', 'estate.pp.settings'] }))
        await connect()

        liveSocket.deliver('estate.pp.settings', liveServer.publish('estate.pp.settings', { t: 'access.changed', e: 'access' }))
        await settle()

        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 0)
    })

    it('reloads again when a second access change lands while the first reload is out', async () => {
        startLive(shellProps())
        await connect()

        liveSocket.deliver('user.7', liveServer.publish('user.7', { t: 'access.changed', e: 'access' }))
        liveSocket.deliver('user.7', liveServer.publish('user.7', { t: 'access.changed', e: 'access' }))
        await settle()
        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 1)

        visits[0].options.onFinish()
        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 2)
    })
})

describe('the session, the principal and the sign-out', () => {
    it('says the session ended on a 419 from the catch-up, and stops asking', async () => {
        liveServer.catchUpStatus = 419
        startLive(shellProps())
        liveSocket.open()
        await tick(20)
        await tick(50)

        assert.equal(store.statusFor(), 'signed-out')
        const asked = catchUps().length
        await tick(60_000)
        assert.equal(catchUps().length, asked)
    })

    it('says the session ended on a 401 from the channel authorisation', async () => {
        liveServer.authStatus = 401
        startLive(shellProps())
        liveSocket.open()
        await tick(20)

        assert.equal(store.statusFor(), 'signed-out')
    })

    it('stops asking for channel authorisation once the session has ended', async () => {
        liveServer.authStatus = 401
        startLive(shellProps({ shell: ['user.7', 'estate.pp.security'] }))
        liveSocket.open()
        await tick(20)
        assert.equal(store.statusFor(), 'signed-out')
        const posts = authPosts().length

        await tick(5 * 60_000)

        assert.equal(authPosts().length, posts, 'no retry answered 401 again and again')
    })

    it('is live again when the shell next starts it with the server\'s props: the session was renewed, not ended', async () => {
        // A sign-in in another tab turns the session over, and this tab's request in flight is answered 419.
        liveServer.authStatus = 419
        startLive(shellProps({ shell: ['user.7', 'estate.pp.security'] }))
        liveSocket.open()
        await tick(20)
        assert.equal(store.statusFor(), 'signed-out')

        // The reader keeps going; the next visit is served, signed in, with this person's live props.
        liveServer.authStatus = null
        startLive(shellProps({ shell: ['user.7', 'estate.pp.security'] }))
        await tick(20)
        await tick(50)
        liveSocket.pulse()
        await settle()

        assert.equal(store.state.channels['user.7'], 'live')
        assert.equal(store.state.channels['estate.pp.security'], 'live')
        assert.equal(store.statusFor(), 'live')
    })

    it('catches up again once the session is renewed, after a 419 from the catch-up', async () => {
        liveServer.catchUpStatus = 419
        startLive(shellProps())
        await connect()
        assert.equal(store.statusFor(), 'signed-out')
        const asked = catchUps().length

        liveServer.catchUpStatus = null
        startLive(shellProps())
        await tick(0)

        assert.equal(catchUps().length, asked + 1)
        assert.equal(store.statusFor(), 'live')
    })

    it('stops everything when the app moves to a page with no live prop', async () => {
        startLive(shellProps())
        await connect()

        fireRouterEvent('navigate', { page: { props: { auth: null } } })

        assert.equal(store.state.started, false)
        assert.equal(liveSocket.current().connection.state, 'disconnected')
        assert.ok(liveSocket.unsubscribed.includes('private-live.user.7'))
    })

    it('starts again from nothing when another person\'s props arrive', async () => {
        startLive(shellProps())
        await connect()

        startLive(shellProps({ principal: 'u-9', shell: ['user.9'] }))

        assert.ok(liveSocket.unsubscribed.includes('private-live.user.7'))
        assert.ok(liveSocket.current().channel('private-live.user.9'))
        assert.equal(liveSocket.instances.length, 1, 'the same socket, reconnected')
    })
})

describe('X-Live-Origin', () => {
    it('puts a fresh 16-hex nonce on every Inertia write, and none on a read', async () => {
        startLive(shellProps())

        const first = await http.processRequest({ method: 'post', url: '/x', headers: {} })
        const second = await http.processRequest({ method: 'patch', url: '/x', headers: { A: 'b' } })
        const read = await http.processRequest({ method: 'get', url: '/x', headers: {} })

        assert.match(first.headers['X-Live-Origin'], /^[a-f0-9]{16}$/)
        assert.match(second.headers['X-Live-Origin'], /^[a-f0-9]{16}$/)
        assert.notEqual(first.headers['X-Live-Origin'], second.headers['X-Live-Origin'])
        assert.equal(second.headers.A, 'b')
        assert.equal(read.headers['X-Live-Origin'], undefined)

        assert.equal(store.isOwnOrigin(first.headers['X-Live-Origin']), true)
        assert.equal(store.isOwnOrigin('0123456789abcdef'), false)
    })

    it('remembers its own nonces for 2 minutes, and not after', async () => {
        startLive(shellProps())
        const write = await http.processRequest({ method: 'post', url: '/x', headers: {} })
        const nonce = write.headers['X-Live-Origin']

        mock.timers.tick(119_999)
        assert.equal(store.isOwnOrigin(nonce), true)

        mock.timers.tick(1)
        assert.equal(store.isOwnOrigin(nonce), false)
    })

    it('puts one on liveFetch writes too', async () => {
        await live.liveFetch('/estate/pp/thing', { method: 'POST', headers: { Accept: 'application/json' } })

        assert.match(liveServer.last('/estate/pp/thing').headers['X-Live-Origin'], /^[a-f0-9]{16}$/)

        await live.liveFetch('/estate/pp/thing')
        assert.equal(liveServer.last('/estate/pp/thing').headers['X-Live-Origin'], undefined)
    })
})

describe('what the C8 harness reads (design §11.3)', () => {
    const page = { channels: ['estate.pp.facilities'], heads: { c: 100, e: 50 }, bindings: ['rows'], kind: 'data' }

    afterEach(() => {
        delete globalThis.window.__liveProof
        delete globalThis.window.__live
    })

    async function oneEventArrives() {
        startLive(shellProps({ shell: [] }))
        await mountPage(page, {})
        await connect()
        liveSocket.deliver('estate.pp.facilities', liveServer.publish('estate.pp.facilities', { i: '1', sum: { status: 'closed' } }))
        await settle()
    }

    it('keeps nothing unless the harness asked for it before the page loaded', async () => {
        await oneEventArrives()

        assert.equal(globalThis.window.__live, undefined)
    })

    it('asked, it keeps each event\'s stream, seq and type, when it arrived and when the bindings had it — and nothing of the event itself', async () => {
        globalThis.window.__liveProof = true
        await oneEventArrives()

        assert.equal(globalThis.window.__live.status(), 'live', 'and it can ask whether the page is live before it acts')

        const [entry, ...more] = globalThis.window.__live.events
        assert.equal(more.length, 0)
        assert.deepEqual(Object.keys(entry).sort(), ['applied_at', 'q', 'received_at', 's', 't'])
        assert.equal(entry.s, 'e')
        assert.equal(entry.q, 51)
        assert.ok(entry.received_at > 0 && entry.applied_at >= entry.received_at)
    })
})
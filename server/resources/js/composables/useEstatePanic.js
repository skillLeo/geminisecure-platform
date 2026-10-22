import { computed, onBeforeUnmount, readonly, ref } from 'vue'
import { register as registerLive, state as liveState } from '../live/store.js'
import { awaitingControlRoom } from './alertStanding.js'

/**
 * One `alert.raised` broadcast, read into what a panic pop-up shows.
 *
 * SHARED BY BOTH CONSOLES (M10 §1.2). The estate's pop-up and the Gemini console's
 * (`useConsolePanic`) read the same payload off the same channel, and two copies of
 * this reading would drift — one of them would start showing a guessed officer count
 * or a blank where the lot belongs. Exported from here, where it was written, so the
 * estate console's behaviour is exactly what it was.
 *
 * @param {object} payload  `AlertRaised::broadcastWith()`, or a `PanicWatch` row
 */
export function panicFromPayload(payload) {
    return {
        id: payload.id,
        kind: payload.kind,
        kindLabel: payload.kind_label ?? 'Alert',

        // "Lot not supplied" rather than a gap: a blank where an address
        // belongs reads as a broken screen rather than as a fact.
        unit: payload.unit || 'Lot not supplied',
        who: payload.who || 'Someone at this estate',
        at: payload.server_time ?? null,
        urgent: payload.is_urgent === true,
        simulated: payload.is_simulated === true,

        // A DRILL (M10 §1.3) — `panic:walk` testing who a panic reaches. The
        // pop-up and the bar say DRILL first, so nobody runs to the lot.
        drill: payload.is_drill === true,

        // Raised in drill mode (M53 §3A.1): nobody was told, so no count is coming and none is waited for.
        drillMode: payload.drill_mode === true,

        /*
         * WHAT THE FAN-OUT ACTUALLY REACHED, passed through — never invented.
         * `null` means the server did not know yet when it broadcast, which since
         * M10 §1.2 is every time: the pop-up says "Alerting officers…" rather than
         * guessing a 0 or a 1, until `alert.reach` brings the number.
         */
        handsets: typeof payload.alerted_handsets === 'number' ? payload.alerted_handsets : null,
        via: typeof payload.alerted_via === 'string' ? payload.alerted_via : null,

        // The count is overdue: no `alert.reach` came within REACH_WAIT_MS.
        reachLate: false,

        // Whether Gemini's control room receives this estate's alerts; null = unknown, no claim.
        controlRoom: typeof payload.control_room === 'boolean' ? payload.control_room : null,

        // The site's own dispatch line, for when nobody's phone was reached.
        call: payload.call && typeof payload.call.number === 'string' && payload.call.number !== ''
            ? { label: payload.call.label || 'Gemini Dispatch', number: payload.call.number }
            : null,
        href: `/dispatch/alerts/${payload.id}`,
    }
}

/** How often the estate asks the server, while there is a reason to — the Gemini console's rate. */
export const ESTATE_POLL_MS = 15000

/*
 * THE COUNT ARRIVES SECOND, OR NOT AT ALL (M10 §1.2). `alert.raised` is sent before the fan-out
 * has tried a phone, so it carries no count; `alert.reach` follows with one. If it has not come
 * within this long, the pop-up stops saying "Alerting officers…" and says the count has not come
 * through — with who to call — because "alerting" held on screen indefinitely would become a claim
 * nobody checked.
 */
const REACH_WAIT_MS = 10000

/*
 * How long a first connection may take before the shell says the channel is down. A socket that
 * answers "refused" or drops is down at once; this is only for one that never answers at all.
 */
const CONNECT_GRACE_MS = 5000

/*
 * How long the channel is kept once no estate page is on screen. An Inertia visit takes the old
 * page's shell down before the new one is built, and leaving the channel in that gap is how an
 * alert broadcast mid-navigation was lost for good (B103). Longer than any page swap, short
 * enough that a tab that has left the estate console does not go on holding its channel.
 */
const LEAVE_GRACE_MS = 10000

/** The most alerts one visit keeps in memory — the newest. */
const KEEP = 50

const newestFirst = (a, b) => (Date.parse(b.at ?? '') || 0) - (Date.parse(a.at ?? '') || 0)

/*
 * MODULE STATE: one visit to this estate's console, however many pages it spans. See "IT OUTLIVES
 * THE PAGE" below for why none of it can live in the component.
 */
const session = {
    /** `${user}|${estate}` — a sign-out and a sign-in, or another estate, starts from nothing. */
    owner: undefined,

    /** Every alert this visit knows of, newest first — outstanding or settled. */
    alerts: ref([]),

    /** The one the pop-up is showing, by id, or null. */
    showingId: ref(null),

    /** Alerts this reader cleared from the bar. A new alert lights it again. */
    cleared: ref([]),

    /** The socket is up and this estate's channel is subscribed. */
    connected: ref(false),

    /** The channel is known to be down — refused, dropped, or never answered within the grace. */
    down: ref(false),

    /** The last time this console asked the server, and whether the answer came. */
    lastCheck: ref(null),

    /** Where this estate's console is rooted, and where it asks — from the latest page. */
    root: '',
    poll: null,
}

/* The live side of the visit: one channel, one poll, one clock, however many pages. */
const live = {
    estate: null,
    echo: null,
    channel: null,
    unbindState: () => {},
    mounts: 0,
    leaveTimer: null,
    graceTimer: null,
    pollTimer: null,
    lateness: null,
    inFlight: false,
    // An event landed while a check was out: the answer on its way may predate it, so ask once more.
    askAgain: false,
    everConnected: false,
    earlyReach: new Map(),
    unregisterLive: () => {},
}

/*
 * EVERY STEP OF AN ALERT, FROM THE LIVE LOG (M11 Part C, DESIGN §2.4 A, §6.6). The control room's
 * acknowledgement, an officer sent, on the way, there, the alert resolved or closed as a false alarm:
 * each arrives on `estate.{t}.security`, which the shell holds, and the bar asks the server where its
 * alerts now stand — so it stops the moment the control room takes the alert, rather than at the next
 * fifteen-second check, and the Security screen re-reads with it. Caught up after every drop, so a step
 * made while the socket was down still lands. The legacy channel above still carries the raise's own
 * payload, which is what opens the pop-up.
 */
const liveAlerts = (estate) => ({
    page: false,
    signals: false,
    channels: { has: (name) => name === `estate.${estate}.security` },
    // Alerts, and the gate (M11 Part C, §2.9 I10–I13): an admission, exit or refusal re-reads the Security screen's visitor log.
    accepts: (env) => env.e === 'alert' || env.e === 'gate',
    apply(env) {
        if (env.e === 'gate') {
            tellActivity('gate')

            return
        }

        tellActivity(env.t === 'alert.raised' ? 'raised' : 'progressed')
        ask()
    },
    resync() {
        ask()
    },
})

/* Who else on the page wants to know that something at this estate moved (`onEstateAlertActivity`). */
const activity = new Set()

const tellActivity = (why) => {
    for (const callback of [...activity]) {
        try {
            callback(why)
        } catch {
            // One screen's refresh failing must not stop the pop-up or another screen's.
        }
    }
}

/**
 * Be told when something at this estate may have changed: an alert raised, counted, sent an
 * officer, acknowledged, closed, or a guard clocking on or off — from the one channel the shell
 * already holds, and from its poll. The estate's Security screen re-reads itself on it (B108)
 * rather than opening a second subscription to the same channel, which would be left under it
 * when either unmounted.
 *
 * @param {(why: string) => void} callback
 * @returns {() => void} stop being told
 */
export function onEstateAlertActivity(callback) {
    activity.add(callback)

    // A screen that re-reads on this moves by itself (M11 Part C): its honesty line says so.
    liveState.pageBindings++
    let released = false

    return () => {
        activity.delete(callback)

        if (!released) {
            released = true
            liveState.pageBindings--
        }
    }
}

/** Whether this estate's alert channel is live right now — for a screen deciding whether to poll. */
export const estateAlertChannel = { connected: readonly(session.connected), down: readonly(session.down) }

/* --- reading the two sources ------------------------------------------------ */

const fromBroadcast = (payload) => ({
    ...panicFromPayload(payload),

    /*
     * `open`, because `AlertRaised` is sent as the alert is created, and an alert is created
     * unacknowledged — a fact about that moment, which the poll then keeps current.
     */
    status: 'open',
    acknowledgedAt: null,
    countDueAt: Date.now() + REACH_WAIT_MS,
})

const fromServer = (row) => {
    const alert = {
        ...panicFromPayload(row),
        status: typeof row.status === 'string' ? row.status : null,
        acknowledgedAt: row.acknowledged_at ?? null,
        fromServer: true,
    }

    // Due its count from when it was RAISED: an alert never counted a minute ago is late now.
    alert.countDueAt = (Date.parse(alert.at ?? '') || Date.now()) + REACH_WAIT_MS
    alert.reachLate = alert.handsets === null && !alert.simulated && !alert.drillMode && Date.now() > alert.countDueAt

    return alert
}

const applyReach = (alert, reach) => {
    if (typeof reach.alerted_handsets === 'number') {
        alert.handsets = reach.alerted_handsets
        alert.via = typeof reach.alerted_via === 'string' ? reach.alerted_via : alert.via
        alert.reachLate = false
    }

    // Never the other way: a count cannot make a drill real.
    alert.drill = alert.drill || reach.is_drill === true
}

/**
 * Add an alert this visit has not seen, or bring a known one up to date.
 *
 * A server answer always wins on the STATUS — it is the only source that knows about an
 * acknowledgement. A count wins only when it is a number: a later null never wipes one.
 *
 * @returns {boolean} whether anything a reader can see changed
 */
const merge = (incoming, popUp) => {
    const known = session.alerts.value.find((alert) => alert.id === incoming.id)

    if (known) {
        let changed = false

        if (incoming.fromServer && (known.status !== incoming.status || known.acknowledgedAt !== incoming.acknowledgedAt)) {
            known.status = incoming.status
            known.acknowledgedAt = incoming.acknowledgedAt
            changed = true
        }

        if (typeof incoming.handsets === 'number' && known.handsets !== incoming.handsets) {
            known.handsets = incoming.handsets
            known.via = incoming.via
            known.reachLate = false
            changed = true
        }

        if (incoming.call && !known.call) {
            known.call = incoming.call
        }

        return changed
    }

    if (live.earlyReach.has(incoming.id)) {
        applyReach(incoming, live.earlyReach.get(incoming.id))
        live.earlyReach.delete(incoming.id)
    }

    session.alerts.value = [incoming, ...session.alerts.value].sort(newestFirst).slice(0, KEEP)

    // A second pop-up for one emergency teaches a reader to close them without looking, so
    // only an alert new to this visit opens one — and only one that still needs somebody.
    if (popUp && awaitingControlRoom(incoming)) {
        session.showingId.value = incoming.id
    }

    return true
}

/* --- the socket --------------------------------------------------------------- */

const onRaised = (payload) => {
    if (!payload || payload.id === undefined || payload.id === null) {
        return
    }

    // Already known — a reconnect can replay. `merge` leaves it where it stands.
    merge(fromBroadcast(payload), true)
    tellActivity('raised')
}

/*
 * The count, for an alert already on screen — or one not here yet. Updated in place, so the
 * open pop-up and the bar change where they stand rather than a second pop-up appearing.
 */
const onReach = (payload) => {
    if (!payload || payload.id === undefined || payload.id === null) {
        return
    }

    const known = session.alerts.value.find((alert) => alert.id === payload.id)

    if (known) {
        applyReach(known, payload)
    } else {
        live.earlyReach.set(payload.id, payload)
    }

    tellActivity('reach')
}

/*
 * An officer was sent, is on the way, or is there (`AlertProgressed`). Sending an officer
 * records the control room's acknowledgement too (`AlertProgress::dispatch`), so the bar may be
 * about to stop: the server is asked, rather than the step being guessed into a status here.
 */
const onProgressed = (payload) => {
    tellActivity('progressed')

    if (payload?.id != null && session.alerts.value.some((alert) => alert.id === payload.id)) {
        ask()
    }
}

const setConnected = (value) => {
    const wasConnected = session.connected.value
    session.connected.value = value

    if (value) {
        const reconnected = !wasConnected && live.everConnected

        live.everConnected = true
        session.down.value = false
        clearTimeout(live.graceTimer)
        live.graceTimer = null

        /*
         * Live from here — so ask once for whatever the channel could not have delivered: an
         * alert raised between this page being drawn and the channel subscribing, or during an
         * outage. One request per connection, and the socket carries the rest.
         */
        if (!wasConnected) {
            ask()

            if (reconnected) {
                tellActivity('reconnected')
            }
        }

        return
    }

    // Down: said at once once it has been up, or when refused; a first connection gets the grace.
    if (live.everConnected || live.graceTimer === null) {
        session.down.value = true
    }

    if (wasConnected) {
        ask()
    }
}

const joinChannel = (estate) => {
    if (live.estate === estate && live.channel !== null) {
        return
    }

    leaveChannel()
    live.estate = estate
    live.unregisterLive = registerLive(liveAlerts(estate))
    session.down.value = false

    live.graceTimer = setTimeout(() => {
        live.graceTimer = null

        if (!session.connected.value) {
            session.down.value = true
        }
    }, CONNECT_GRACE_MS)

    /*
     * Echo is imported lazily, exactly as the dispatch stream does it — but this is on every
     * estate page, so it happens once per visit rather than once per screen.
     */
    import('../echo')
        .then(({ echo, onConnectionState }) => {
            if (live.estate !== estate) {
                return
            }

            live.echo = echo
            let socketUp = false

            live.unbindState = onConnectionState((state) => {
                socketUp = state === 'connected'

                // Pusher resubscribes after a reconnect and says so again; until then, down.
                if (!socketUp) {
                    setConnected(false)
                }
            })

            live.channel = echo.private(`estate.${estate}.alerts`)

            // Live only once THIS channel is subscribed: a connected socket whose subscription is
            // pending, or was refused, delivers nothing.
            live.channel.subscribed(() => {
                setConnected(socketUp)
            })

            live.channel.listen('.alert.raised', onRaised)

            // The count of officers' phones reached, which follows the alert (M10 §1.2).
            live.channel.listen('.alert.reach', onReach)

            // A response step, and a guard clocking on or off: the Security screen re-reads.
            live.channel.listen('.alert.progressed', onProgressed)
            live.channel.listen('.shift.clocked', () => tellActivity('shift'))

            /*
             * A refused subscription is not a quiet night. If authorisation fails the socket
             * stays up and simply never delivers, which on this surface is indistinguishable
             * from no emergencies — so it is reported as down, the shell says so (B110), and
             * the poll takes over.
             */
            live.channel.error(() => {
                session.down.value = true
                setConnected(false)
            })
        })
        .catch(() => {
            session.down.value = true
            setConnected(false)
        })
}

const leaveChannel = () => {
    live.unbindState()
    live.unbindState = () => {}
    live.unregisterLive()
    live.unregisterLive = () => {}
    clearTimeout(live.graceTimer)
    live.graceTimer = null

    if (live.echo && live.estate) {
        live.echo.leave(`estate.${live.estate}.alerts`)
    }

    live.echo = null
    live.channel = null
    live.estate = null
    live.everConnected = false
    session.connected.value = false
}

/* --- the poll ------------------------------------------------------------------ */

/** What nobody in the control room has acknowledged, and this reader has not cleared. */
const lit = () => session.alerts.value.filter((alert) => awaitingControlRoom(alert) && !session.cleared.value.includes(alert.id))

/**
 * Ask the server what is outstanding here, and where the alerts on screen now stand.
 *
 * The socket says when an alert is raised; it says nothing when the control room acknowledges
 * one, so without this the bar could not stop when it should — and an alert raised while the
 * channel was down would reach this screen only on the next page.
 */
async function ask() {
    const stopped = live.mounts === 0 && live.leaveTimer === null

    if (live.inFlight) {
        live.askAgain = true

        return
    }

    if (!session.poll || stopped) {
        return
    }

    live.inFlight = true

    const asked = session.alerts.value.filter(awaitingControlRoom).map((alert) => alert.id).slice(0, KEEP)
    const query = asked.map((id) => `ids[]=${encodeURIComponent(id)}`).join('&')
    const url = `${session.root}${session.poll}`

    try {
        const response = await fetch(query ? `${url}?${query}` : url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
        })

        // A refused or failed answer changes nothing on screen: the bar keeps blinking, and the
        // next tick asks again. Silence from the server is never read as "handled".
        if (!response.ok) {
            session.lastCheck.value = { ok: false, at: new Date() }

            return
        }

        const body = await response.json()
        const rows = Array.isArray(body?.alerts) ? body.alerts : []
        const answered = new Set(rows.map((row) => row.id))

        // Asked about and not answered: this reader may no longer see it.
        const before = session.alerts.value.length
        session.alerts.value = session.alerts.value.filter((alert) => !asked.includes(alert.id) || answered.has(alert.id))
        let changed = session.alerts.value.length !== before

        if (session.showingId.value !== null && !session.alerts.value.some((alert) => alert.id === session.showingId.value)) {
            session.showingId.value = null
        }

        for (const row of rows) {
            changed = merge(fromServer(row), true) || changed
        }

        session.lastCheck.value = { ok: true, at: new Date() }

        if (changed) {
            tellActivity('checked')
        }
    } catch {
        // Offline, or the session ended. The next tick asks again.
        session.lastCheck.value = { ok: false, at: new Date() }
    } finally {
        live.inFlight = false

        if (live.askAgain) {
            live.askAgain = false
            ask()
        }
    }
}

const startClocks = () => {
    if (live.pollTimer === null) {
        live.pollTimer = setInterval(() => {
            if (lit().length > 0 || !session.connected.value) {
                ask()
            }
        }, ESTATE_POLL_MS)
    }

    /*
     * THE WAIT FOR A COUNT, checked each second while any alert has none. Once it has passed,
     * "Alerting officers…" gives way to "has not come through" with who to call.
     */
    if (live.lateness === null) {
        live.lateness = setInterval(() => {
            const now = Date.now()

            for (const alert of session.alerts.value) {
                if (alert.handsets === null && !alert.simulated && !alert.drillMode && !alert.reachLate && now > (alert.countDueAt ?? now)) {
                    alert.reachLate = true
                }
            }
        }, 1000)
    }
}

const stopEverything = () => {
    clearInterval(live.pollTimer)
    clearInterval(live.lateness)
    clearTimeout(live.leaveTimer)
    live.pollTimer = null
    live.lateness = null
    live.leaveTimer = null
    live.earlyReach.clear()
    leaveChannel()
}

/**
 * A panic, on the estate's own console, wherever the reader happens to be.
 *
 * WHY THIS EXISTS — M10 §1, in the client's words:
 *
 *   "It must go to that community's main dashboard where the president and all the people can
 *    see it: a pop-up, and something blinking on it… they must know there is an emergency at a
 *    specific lot."
 *
 *   "When people are in a panic they don't remember to do the things they're supposed to do."
 *
 * THE CHANNEL WAS ALREADY THERE AND NOBODY LISTENED. `AlertRaised` broadcasts on the private
 * channel `estate.{id}.alerts`, and routes/channels.php admits a committee member who may see
 * this estate's security. This is the estate side of that channel.
 *
 * IT IS MOUNTED ON THE SHELL, NOT ON A SCREEN. `EstateConsole.vue` wraps every estate page, so
 * subscribing there is what makes the pop-up reach a reader wherever they are.
 *
 * IT OUTLIVES THE PAGE (B039, B103). The shell is rendered by each page, so Inertia builds it
 * afresh on every visit — and this used to keep its alerts in the component and leave the
 * channel on unmount, so the blinking bar vanished the moment a President clicked "Residents",
 * or the pop-up's own "Open Security", with nobody having acknowledged anything; and an alert
 * broadcast while the pages swapped was never seen. M10 §1.2 item 6 asks for "a persistent
 * blinking indicator until the alert is acknowledged". So, as the Gemini console does
 * (`useConsolePanic`):
 *
 *   - the alerts live at module level, for the whole visit;
 *   - the SERVER seeds them on every page (`panicWatch.alerts`, `PanicWatch::forEstate`) — a
 *     Vice President who opens the console a minute after the panic sees the bar, naming the
 *     lot — and they light the bar without re-opening the pop-up, which is not news twice;
 *   - the channel is held across the swap between pages (`LEAVE_GRACE_MS`), so nothing
 *     broadcast in that gap is lost, and left once no estate page is on screen;
 *   - and while anything is outstanding, or the channel is down, the server is asked every
 *     fifteen seconds (`PanicWatch::pollEstate`) — which is what stops the bar when the control
 *     room acknowledges, and brings an alert raised during an outage to the screen.
 *
 * IT KEEPS BLINKING UNTIL SOMEBODY ACTS. Dismissing the pop-up leaves the indicator going — the
 * alert has not been dealt with just because a box was closed. It stops when the server reports
 * the control room's acknowledgement or the alert closed (`awaitingControlRoom`), or when this
 * reader clears it by hand — the estate console has no way to acknowledge an alert, so its bar
 * keeps the Clear button it always had; clearing lasts the visit, and a new alert lights it again.
 *
 * A DEAD CHANNEL SAYS SO (B110). `down` is what the shell reads to tell the reader that the pop-up
 * cannot arrive live, rather than showing a console indistinguishable from a quiet night.
 *
 * @param {object|null} watch  the `panicWatch` shared prop: `{ estate, alerts, poll, checked_at }`
 * @param {object} [options]
 * @param {number|null} [options.owner]  the signed-in user, so a second person in this tab starts clean
 * @param {string} [options.root]  where this estate's console is rooted (`/estate/{key}` locally)
 */
export function useEstatePanic(watch, options = {}) {
    const estate = watch?.estate ? String(watch.estate) : ''

    // No watch: this reader may not see the estate's security, and no socket is opened.
    if (estate === '') {
        return {
            alerts: session.alerts,
            showing: computed(() => null),
            current: computed(() => null),
            blinking: computed(() => false),
            outstanding: computed(() => 0),
            connected: readonly(session.connected),
            down: computed(() => false),
            lastCheckFailed: computed(() => false),
            dismiss: () => {},
            clear: () => {},
        }
    }

    const owner = `${options.owner ?? ''}|${estate}`

    if (session.owner !== owner) {
        session.owner = owner
        session.alerts.value = []
        session.showingId.value = null
        session.cleared.value = []
        session.lastCheck.value = null
        stopEverything()
        live.mounts = 0
    }

    session.root = typeof options.root === 'string' ? options.root : ''
    session.poll = typeof watch.poll === 'string' && watch.poll !== '' ? watch.poll : null

    /*
     * WHAT WAS OUTSTANDING WHEN THIS PAGE WAS DRAWN lights the bar and nothing more: it is not
     * news, and re-opening a pop-up on every page for an alert already read would teach a reader
     * to close it unread.
     */
    for (const row of watch.alerts ?? []) {
        merge(fromServer(row), false)
    }

    /*
     * An alert this visit still believes outstanding that the server's list no longer names was
     * acknowledged or closed since the last page — or is a simulated one, which the list never
     * carries. Asked about at once, rather than left blinking for fifteen seconds on a belief.
     */
    const listed = new Set((watch.alerts ?? []).map((row) => row.id))
    const stale = session.alerts.value.some((alert) => awaitingControlRoom(alert) && !listed.has(alert.id))

    live.mounts++
    clearTimeout(live.leaveTimer)
    live.leaveTimer = null

    joinChannel(estate)
    startClocks()

    if (stale) {
        ask()
    }

    onBeforeUnmount(() => {
        live.mounts = Math.max(0, live.mounts - 1)

        if (live.mounts === 0) {
            live.leaveTimer = setTimeout(stopEverything, LEAVE_GRACE_MS)
        }
    })

    /* --- what the template reads ------------------------------------------------ */

    const lighting = computed(lit)

    /*
     * The bar names a REAL emergency ahead of any drill, whichever arrived last: a real alert
     * must never be relabelled a drill because a drill happened to be newer.
     */
    const current = computed(() => lighting.value.find((alert) => !alert.drill) ?? lighting.value[0] ?? null)
    const blinking = computed(() => lighting.value.length > 0)
    const outstanding = computed(() => lighting.value.length)
    const showing = computed(() => session.alerts.value.find((alert) => alert.id === session.showingId.value) ?? null)

    /** Close the pop-up. The bar stays until the alert is acknowledged — see above. */
    const dismiss = () => {
        session.showingId.value = null
    }

    /**
     * Stop the indicator, for what is on it now.
     *
     * Deliberately separate from `dismiss`: closing the box is not dealing with the emergency,
     * and the two must not be one action. A new alert lights the bar again.
     */
    const clear = () => {
        session.cleared.value = [...new Set([...session.cleared.value, ...lighting.value.map((alert) => alert.id)])]
        session.showingId.value = null
    }

    return {
        alerts: session.alerts,
        showing,
        current,
        blinking,
        outstanding,
        connected: readonly(session.connected),
        down: readonly(session.down),
        lastCheckFailed: computed(() => session.lastCheck.value?.ok === false),
        dismiss,
        clear,
    }
}

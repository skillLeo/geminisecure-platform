import { computed, onBeforeUnmount, ref } from 'vue'
import { register as registerLive } from '../live/store.js'
import { awaitingControlRoom } from './alertStanding.js'
import { panicFromPayload } from './useEstatePanic.js'

/** How often to ask the server, while there is a reason to. */
const POLL_MS = 15000

/** The most alerts one visit keeps in memory — the newest. */
const KEEP = 50

/*
 * How long a count may take before the pop-up stops saying "Alerting officers…" and says
 * the count has not come through — the estate pop-up's own wait (`useEstatePanic`), so
 * the two consoles give up on the same second.
 */
const REACH_WAIT_MS = 10000

/*
 * MODULE STATE: one visit to the console, however many pages it spans. See
 * "IT OUTLIVES THE PAGE" below for why it cannot live in the component.
 */
const session = {
    owner: ref(undefined),

    /** Every alert this visit knows of, newest first — outstanding or settled. */
    alerts: ref([]),

    /** The one the pop-up is showing, by id, or null. */
    showingId: ref(null),
}

const newestFirst = (a, b) => (Date.parse(b.at ?? '') || 0) - (Date.parse(a.at ?? '') || 0)

/**
 * A panic, on the Gemini console, wherever the reader happens to be — M10 §1.2 items 3 and 4.
 *
 *   3. "The Gemini Director — dashboard alert."
 *   4. "The Gemini Head of Security for that site — dashboard alert plus email."
 *
 * NOTHING REACHED EITHER OF THEM LIVE. The Gemini console subscribed to alerts only on
 * the dispatch screens, so a Director reading a pay run while a resident held a panic
 * button at Lot 47 saw nothing until the notification bell at their next page load. This
 * is the estate console's pop-up (`useEstatePanic`, `PanicOverlay`) brought to the Gemini
 * console's shell, with the three things a console needs that an estate does not:
 *
 * MANY ESTATES, EACH ON ITS OWN CHANNEL. `AlertRaised` is broadcast on `estate.{id}.alerts`
 * and on no global channel, so this subscribes to every estate `PanicWatch` names — every
 * client for the Director, the assigned sites for a Head of Security — and the SERVER
 * refuses any other (`routes/channels.php`). The list is handed down by the server, never
 * assembled here: a browser that could name its own estates could name somebody else's.
 *
 * IT OUTLIVES THE PAGE. `GeminiConsole.vue` is rendered by each page, so it is mounted
 * afresh on every visit, and state held in the component would forget an outstanding
 * panic the moment the Director clicked a sidebar link — the one thing "a persistent
 * blinking indicator until the alert is acknowledged" must not do. So the alerts live at
 * module level, for the whole visit to the console, and a full reload gets them back from
 * the server's own list of what nobody has acknowledged. The SUBSCRIPTIONS do not outlive
 * the page: the dispatch screens leave these same channels when they unmount, and a
 * channel this held on to would be torn down under it — or, worse, already subscribed
 * when a dispatch screen asked, so that screen's own "subscribed" never fired and it sat
 * in fallback on a socket that was fine. Both follow the page, and neither trips the other.
 *
 * IT STOPS BLINKING WHEN THE ALERT IS DEALT WITH, AND NOT BEFORE — when the server reports the
 * control room's acknowledgement, or the alert closed; an officer on the way from the handset
 * is not an acknowledgement (B090, `awaitingControlRoom`). The socket says when an
 * alert is raised; it says nothing when somebody acknowledges one. So while anything is
 * outstanding, and while any channel is down, this asks `PanicWatch::poll()` every
 * fifteen seconds — which also brings an alert raised during an outage to the screen. No
 * clear-by-hand: a Director who could silence the bar without acknowledging the alert
 * would have a way to make an unattended emergency look handled.
 *
 * WHERE THE SCREEN ALREADY SHOWS IT, THE POP-UP STANDS DOWN. The dispatch screens — the
 * queue, the map, the coverage and alertness boards, an alert's own screen — stream their
 * estates themselves and sound the siren (`useLiveDispatch`). A pop-up there would cover
 * the queue row a dispatcher is reaching for with a copy of it. So on a screen whose own
 * `estateIds` include the alert's estate the card is not opened; the bar still appears,
 * because it is the indicator that has to follow the reader to every other page.
 *
 * THE OFFICER COUNT ARRIVES SECOND. `alert.raised` goes before the fan-out has tried a
 * phone, so its `alerted_handsets` is null ("Alerting officers…"); `alert.reach` follows on
 * the same channel with the count — so the Director's channel needs no broadcast of its
 * own, it is the estate's. A count that overtakes its alert on the socket is held until
 * the alert lands. If none comes within the estate pop-up's own wait, the card says the
 * count has not come through, and the poll brings the same column when it exists.
 * Nothing here guesses a number.
 *
 * @param {object|null} watch  the `geminiPanicWatch` shared prop, or null
 * @param {object} [options]
 * @param {number|null} [options.owner]  the signed-in user, so a second person in this tab starts clean
 * @param {(estate: string) => boolean} [options.streamsHere]  whether the current screen streams that estate itself
 */
export function useConsolePanic(watch, options = {}) {
    const streamsHere = typeof options.streamsHere === 'function' ? options.streamsHere : () => false

    /** The estates this reader may watch, as the server named them, with their names. */
    const estates = new Map((watch?.estates ?? []).map((estate) => [String(estate.id), estate.name]))

    /** Each estate's clock (M19A §7): the pop-up says when it happened AT THE SITE. The estate console has one. */
    const zones = new Map([
        ...(watch?.estates ?? []).map((estate) => [String(estate.id), estate.zone ?? null]),
        ...(watch?.estate ? [[String(watch.estate), watch.zone ?? null]] : []),
    ])

    /* A different person in this tab — a sign-out and a sign-in — starts from nothing. */
    const owner = options.owner ?? null

    if (session.owner.value !== owner) {
        session.owner.value = owner
        session.alerts.value = []
        session.showingId.value = null
    }

    /*
     * NOTHING OUTSIDE THIS READER'S ESTATES SURVIVES A PAGE. A Head of Security taken off a
     * site keeps no alert from it on screen, because the server's list is re-read here on
     * every page and anything it no longer names is dropped.
     */
    session.alerts.value = session.alerts.value.filter((alert) => estates.has(alert.estate))

    if (session.showingId.value !== null && !session.alerts.value.some((alert) => alert.id === session.showingId.value)) {
        session.showingId.value = null
    }

    /* --- reading the two sources ------------------------------------------ */

    const fromBroadcast = (payload) => ({
        ...panicFromPayload(payload),
        who: payload.who || 'Name not supplied',
        estate: String(payload.estate),
        estateName: estates.get(String(payload.estate)) ?? 'An estate',
        zone: zones.get(String(payload.estate)) ?? null,

        /*
         * `open`, because `AlertRaised` is sent as the alert is created and an alert is
         * created unacknowledged. Said as "nobody had acknowledged it when it reached this
         * screen" — a fact about a moment, not a claim about now.
         */
        status: 'open',
        statusFrom: 'broadcast',
        statusAt: new Date().toISOString(),
        acknowledgedAt: null,
        resolvedAt: null,

        // The count is due from `alert.reach` within the wait, counted from arrival here.
        countDueAt: Date.now() + REACH_WAIT_MS,
    })

    const fromServer = (row, checkedAt) => {
        const alert = {
            ...panicFromPayload(row),
            who: row.who || 'Name not supplied',
            estate: String(row.estate),
            estateName: row.estate_name || estates.get(String(row.estate)) || 'An estate',
            zone: zones.get(String(row.estate)) ?? null,
            status: typeof row.status === 'string' ? row.status : null,
            statusFrom: 'server',
            statusAt: checkedAt ?? null,
            acknowledgedAt: row.acknowledged_at ?? null,
            dispatchedAt: row.dispatched_at ?? null,
            enRouteAt: row.en_route_at ?? null,
            onSceneAt: row.on_scene_at ?? null,
            resolvedAt: row.resolved_at ?? null,
            cancelled: row.cancelled === true,
            href: row.href || `/dispatch/alerts/${row.id}`,
        }

        // A row read from the server is due its count from when it was RAISED: an alert the
        // fan-out never counted, a minute ago, is late now rather than ten seconds from now.
        alert.countDueAt = (Date.parse(alert.at ?? '') || Date.now()) + REACH_WAIT_MS
        alert.reachLate = alert.handsets === null && !alert.drillMode && Date.now() > alert.countDueAt

        return alert
    }

    /* A count that overtook its own alert on the socket, kept until the alert lands. */
    const earlyReach = new Map()

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
     * acknowledgement. A count wins only when it is a number: a later null must never wipe
     * a count already known.
     */
    const merge = (incoming, popUp) => {
        const known = session.alerts.value.find((alert) => alert.id === incoming.id)

        if (known) {
            if (incoming.statusFrom === 'server') {
                known.status = incoming.status
                known.statusFrom = 'server'
                known.statusAt = incoming.statusAt
                known.acknowledgedAt = incoming.acknowledgedAt
                known.dispatchedAt = incoming.dispatchedAt
                known.enRouteAt = incoming.enRouteAt
                known.onSceneAt = incoming.onSceneAt
                known.resolvedAt = incoming.resolvedAt
                known.cancelled = incoming.cancelled
            }

            if (typeof incoming.handsets === 'number') {
                known.handsets = incoming.handsets
                known.via = incoming.via
                known.reachLate = false
            }

            if (incoming.call && !known.call) {
                known.call = incoming.call
            }

            return
        }

        if (earlyReach.has(incoming.id)) {
            applyReach(incoming, earlyReach.get(incoming.id))
            earlyReach.delete(incoming.id)
        }

        session.alerts.value = [incoming, ...session.alerts.value].sort(newestFirst).slice(0, KEEP)

        // A second pop-up for one emergency teaches a reader to close them without
        // looking, so only an alert new to this visit opens one — and not on a screen
        // that already shows it.
        if (popUp && !streamsHere(incoming.estate)) {
            session.showingId.value = incoming.id
        }
    }

    /*
     * WHAT WAS OUTSTANDING WHEN THIS PAGE WAS DRAWN lights the bar and nothing more: it is
     * not news, and re-opening a pop-up on every page for an alert already read would
     * teach a Director to close it unread.
     */
    for (const row of watch?.alerts ?? []) {
        merge(fromServer(row, watch.checked_at), false)
    }

    /*
     * An alert this visit still believes outstanding that the server's list no longer
     * names was acknowledged or resolved since the last page — or moved out of reach. The
     * first poll asks which, at once, rather than leaving the bar blinking for fifteen
     * seconds on a stale belief.
     */
    const listed = new Set((watch?.alerts ?? []).map((row) => row.id))
    const stale = session.alerts.value.some((alert) => awaitingControlRoom(alert) && !listed.has(alert.id))

    /* --- what the template reads ------------------------------------------ */

    /** What nobody in the control room has acknowledged yet — the bar's whole subject. */
    const open = computed(() => session.alerts.value.filter(awaitingControlRoom))

    /*
     * The bar names a REAL emergency ahead of any drill, whichever arrived last: a real
     * alert must never be relabelled a drill because a drill happened to be newer.
     */
    const current = computed(() => open.value.find((alert) => !alert.drill) ?? open.value[0] ?? null)
    const blinking = computed(() => open.value.length > 0)
    const outstanding = computed(() => open.value.length)
    const showing = computed(() => session.alerts.value.find((alert) => alert.id === session.showingId.value) ?? null)

    /** Close the pop-up. The bar stays until the alert is acknowledged — see the header. */
    const dismiss = () => {
        session.showingId.value = null
    }

    /* --- the socket -------------------------------------------------------- */

    const ids = [...estates.keys()]
    const subscribed = new Set()
    let socketUp = false
    const connected = ref(false)

    let echoInstance = null
    let unbindState = () => {}
    let disposed = false

    const recompute = () => {
        connected.value = socketUp && ids.length > 0 && ids.every((id) => subscribed.has(id))
    }

    const onRaised = (payload) => {
        if (disposed || !payload || payload.id === undefined || payload.id === null) {
            return
        }

        // A simulated alert is sent to nobody — the platform's rule, and `PanicWatch`'s.
        if (payload.is_simulated === true || !estates.has(String(payload.estate))) {
            return
        }

        merge(fromBroadcast(payload), true)
    }

    /*
     * The count, once the fan-out has it — `AlertReachKnown`, on the same channel. Updated
     * in place, so the open card and the bar change where they stand rather than a second
     * pop-up appearing. Tolerates never arriving: the wait below says so, and the poll
     * carries the same column.
     */
    const onReach = (payload) => {
        if (disposed || !payload || payload.id === undefined || payload.id === null) {
            return
        }

        if (!estates.has(String(payload.estate))) {
            return
        }

        const known = session.alerts.value.find((alert) => alert.id === payload.id)

        if (known) {
            applyReach(known, payload)

            return
        }

        earlyReach.set(payload.id, payload)
    }

    /*
     * THE WAIT FOR A COUNT, checked each second while any alert has none. Once it has
     * passed, "Alerting officers…" gives way to "has not come through" with who to call —
     * "alerting" held on screen indefinitely would become a claim nobody checked.
     */
    const lateness = ids.length > 0
        ? setInterval(() => {
            const now = Date.now()

            for (const alert of session.alerts.value) {
                if (alert.handsets === null && !alert.drillMode && !alert.reachLate && now > (alert.countDueAt ?? now)) {
                    alert.reachLate = true
                }
            }
        }, 1000)
        : null

    if (ids.length > 0) {
        /*
         * Imported lazily, exactly as the dispatch stream and the estate pop-up do it:
         * a console user with nothing to watch never opens a socket.
         */
        import('../echo')
            .then(({ echo, onConnectionState }) => {
                if (disposed) {
                    return
                }

                echoInstance = echo

                unbindState = onConnectionState((state) => {
                    socketUp = state === 'connected'

                    // Pusher resubscribes after a reconnect and says so again.
                    if (!socketUp) {
                        subscribed.clear()
                    }

                    recompute()
                })

                for (const id of ids) {
                    const channel = echo.private(`estate.${id}.alerts`)

                    channel.subscribed(() => {
                        subscribed.add(id)
                        recompute()
                    })

                    channel.listen('.alert.raised', onRaised)
                    channel.listen('.alert.reach', onReach)

                    /*
                     * A refused subscription is not a quiet night: it counts as down, and
                     * the poll covers that estate until it is back.
                     */
                    channel.error(() => {
                        subscribed.delete(id)
                        recompute()
                    })
                }
            })
            .catch(() => {
                connected.value = false
            })
    }

    /* --- the poll ---------------------------------------------------------- */

    let inFlight = false
    let askAgain = false

    const ask = async () => {
        if (inFlight) {
            // An event landed while a check was out: the answer on its way may predate it.
            askAgain = true

            return
        }

        if (disposed || !watch?.poll || ids.length === 0) {
            return
        }

        inFlight = true

        const asked = open.value.map((alert) => alert.id).slice(0, KEEP)
        const query = asked.map((id) => `ids[]=${encodeURIComponent(id)}`).join('&')

        try {
            const response = await fetch(query ? `${watch.poll}?${query}` : watch.poll, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            })

            // A refused or failed answer changes nothing: the bar keeps blinking, and the
            // next tick asks again. Silence from the server is never read as "handled".
            if (!response.ok || disposed) {
                return
            }

            const body = await response.json()
            const rows = Array.isArray(body?.alerts) ? body.alerts : []
            const answered = new Set(rows.map((row) => row.id))

            // Asked about and not answered: this reader may no longer see it.
            session.alerts.value = session.alerts.value.filter((alert) => !asked.includes(alert.id) || answered.has(alert.id))

            if (session.showingId.value !== null && !session.alerts.value.some((alert) => alert.id === session.showingId.value)) {
                session.showingId.value = null
            }

            for (const row of rows) {
                merge(fromServer(row, body.checked_at), true)
            }
        } catch {
            // Offline, or the session ended. The next tick asks again.
        } finally {
            inFlight = false

            if (askAgain) {
                askAgain = false
                ask()
            }
        }
    }

    /*
     * EVERY STEP OF AN ALERT, FROM THE LIVE LOG (M11 Part C, DESIGN §2.4 A, §6.6). Another dispatcher's
     * acknowledgement, an officer sent, the alert resolved: each arrives on the dispatch feed the shell
     * holds (`gemini.dispatch`, or each watched estate's copy), and the bar asks the server where its alerts
     * now stand — so it stops when the alert is taken, not at the next fifteen-second check. Caught up after
     * every drop. The legacy channel above still carries the raise's payload, which opens the pop-up.
     */
    const unregisterLive = ids.length > 0
        ? registerLive({
            page: false,
            signals: false,
            channels: { has: (name) => name === 'gemini.dispatch' || ids.some((id) => name === `gemini.${id}.dispatch`) },
            accepts: (env) => env.e === 'alert',
            apply: () => ask(),
            resync: () => ask(),
        })
        : () => {}

    const timer = ids.length > 0
        ? setInterval(() => {
            if (blinking.value || !connected.value) {
                ask()
            }
        }, POLL_MS)
        : null

    if (stale) {
        ask()
    }

    onBeforeUnmount(() => {
        disposed = true
        unregisterLive()
        clearInterval(timer)
        clearInterval(lateness)
        earlyReach.clear()
        unbindState()

        if (echoInstance) {
            for (const id of ids) {
                echoInstance.leave(`estate.${id}.alerts`)
            }
        }
    })

    return { alerts: session.alerts, showing, current, blinking, outstanding, connected, dismiss }
}

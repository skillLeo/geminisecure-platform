import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { register as registerLive, state as liveState } from '../live/store.js'
import { useAlertStream } from './useAlertStream.js'
import { useLifeSafetyPoll } from './useLifeSafetyPoll.js'
import { usePanicAlarm } from './usePanicAlarm.js'

/**
 * The one way a dispatch screen stays current (13 C1, C2).
 *
 * THE SOCKET IS THE NORMAL MODE. POLLING IS THE FALLBACK, AND IT SAYS SO.
 *
 * Until work order 13 the poll ran all the time — at full rate with no socket,
 * at a thirty-second heartbeat behind one — because a socket that silently
 * stopped delivering looked exactly like a calm night. The client ruled that a
 * thirty-second refresh on a panic queue is a stated degradation, not a normal
 * mode. So the two mechanisms no longer run in parallel:
 *
 *   live      every estate's channel is connected AND subscribed → no poll;
 *             each push re-fetches the screen's props
 *   fallback  any channel is down → the poll runs at the screen's full rate,
 *             starting the moment the channel is lost
 *
 * WHAT NOW CATCHES A SILENT SOCKET is the socket's own heartbeat rather than a
 * second mechanism: pusher-js pings after 30s of quiet and declares the
 * connection lost if no pong arrives within 10s (`echo.js`), so a dead socket
 * flips the screen to fallback within forty seconds. A refused subscription
 * counts as down from the start (`useAlertStream`).
 *
 * WHAT IS MISSED DURING AN OUTAGE is fetched once, on the way back: the moment
 * every channel is live again the screen refreshes, then stops polling.
 *
 * AND THE DISPATCHER IS TOLD WHICH MODE THEY ARE IN — `DispatchConnectionBar`,
 * on every dispatch screen, reads `connection` from here.
 *
 * @param {object} options
 * A PANIC IS AUDIBLE ON EVERY SCREEN THAT USES THIS. The alarm lives here rather
 * than on the alerts queue alone, because a dispatcher working the coverage board
 * or the live map must hear a panic exactly as loudly as one staring at the queue
 * — and putting it on one screen is how the other three stay silent. It sounds on
 * the broadcast's own `is_urgent`, so only a panic makes noise: an officer's duress,
 * or a press of the panic button whatever the resident labelled it — "Medical" is as
 * loud as no label (`DuressAlert::isPanic()`). An incident report stays quiet.
 *
 * @param {string[]} options.only        the props to re-fetch
 * @param {number} options.intervalMs    the fallback rate
 * @param {string[]} [options.estateIds] estates to listen to; omit for none
 * @param {boolean} [options.alarm]      sound the siren on an urgent alert
 */
export function useLiveDispatch({ only, intervalMs, estateIds = [], alarm = true }) {
    const panic = usePanicAlarm()
    /*
     * One subscription per estate. A Director sees every client at once, so the
     * map and the alertness board listen to all of them; a single alert's screen
     * listens only to the estate it belongs to.
     */
    let poll = null

    /*
     * The siren first, the refresh second, and deliberately in that order: the
     * noise costs nothing to start and a dispatcher hearing it half a second
     * earlier is the entire point. A push with no payload (an older server, or
     * the clock-in event) leaves `is_urgent` undefined and stays silent.
     */
    /*
     * WHEN THE REACH COUNT NEVER ARRIVES (M10 §1.2). An alert is broadcast before the
     * fan-out, with no count yet, and `alert.reach` follows with one. If that second
     * push is lost — a dropped socket, a Reverb restart — the row would say
     * "Alerting officers…" until something else happened to re-read the server. So
     * each alert without a count gets ONE re-read of its own a few seconds later,
     * cancelled the moment its count arrives. The server says "not recorded" once a
     * count is a minute overdue, so this never has to guess.
     */
    const REACH_WAIT_MS = 8000
    const reachWaits = new Map()

    const stopWaiting = (id) => {
        clearTimeout(reachWaits.get(id))
        reachWaits.delete(id)
    }

    const streams = estateIds.map((id) =>
        useAlertStream(id, {
            onAlert: (payload) => {
                if (alarm && payload?.is_urgent) {
                    panic.raise(payload.id)
                }

                poll?.refresh()

                if (payload?.id != null && payload.alerted_handsets == null && payload.is_simulated !== true) {
                    stopWaiting(payload.id)
                    reachWaits.set(
                        payload.id,
                        setTimeout(() => {
                            reachWaits.delete(payload.id)
                            poll?.refresh()
                        }, REACH_WAIT_MS),
                    )
                }
            },

            // The count: re-read, and no siren — this is not a new alert.
            onReach: (payload) => {
                if (payload?.id != null) {
                    stopWaiting(payload.id)
                }

                poll?.refresh()
            },
        })
    )

    /*
     * EVERY stream, not any. A console watching four estates with three sockets
     * up is not covered — the fourth estate's panic would arrive only on the
     * poll — so the screen stays in fallback until all of them are live. `every`
     * on an empty list is true, which is why the guard on length comes first: a
     * screen with no subscriptions is not live, it has nothing to be live on.
     */
    const streaming = computed(
        () => streams.length > 0 && streams.every((stream) => stream.connected.value),
    )

    poll = useLifeSafetyPoll(only, intervalMs, { active: () => !streaming.value })

    /*
     * THE SIREN STOPS WHEN ITS ALERTS ARE ACKNOWLEDGED — IN ANY SESSION (B107).
     *
     * `usePanicAlarm` promised to repeat "until the dispatcher silences it or the alert leaves
     * the unacknowledged state", and only the first half was built: an acknowledgement from a
     * second dispatcher's console is never broadcast, so nothing on this screen learnt of it and
     * the klaxon went on every three seconds until somebody clicked. So while it sounds, this
     * asks the server — every few seconds, about the alerts it is sounding for and nothing else
     * — which of them the control room still owes an answer (`PanicWatch::awaitingControlRoom`),
     * and the siren stops once none does. A refused or failed answer changes nothing: the siren
     * keeps sounding, because silence from the server is not an acknowledgement.
     */
    const ALARM_CHECK_MS = 5000
    let alarmTimer = null
    let alarmAsking = false

    const checkAlarm = async () => {
        const ids = panic.ringingIds()

        if (alarmAsking || ids.length === 0) {
            return
        }

        alarmAsking = true

        try {
            const response = await fetch(`/dispatch/alarm?${ids.map((id) => `ids[]=${encodeURIComponent(id)}`).join('&')}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            })

            if (response.ok) {
                const body = await response.json()

                if (Array.isArray(body?.awaiting)) {
                    panic.settle(body.awaiting)
                }
            }
        } catch {
            // Offline, or the session ended: the siren keeps sounding, and the next tick asks.
        } finally {
            alarmAsking = false
        }
    }

    watch(panic.sounding, (sounding) => {
        clearInterval(alarmTimer)
        alarmTimer = sounding ? setInterval(checkAlarm, ALARM_CHECK_MS) : null
    })

    /*
     * EVERY STEP OF AN ALERT, FROM THE LIVE LOG (M11 Part C, DESIGN §2.4 A, §6.6) — THE CLIENT'S RESOLVE.
     * A second dispatcher's acknowledgement, an officer sent, on the way, there, the alert resolved or closed
     * as a false alarm, the raiser's cancel: each arrives on the dispatch feed the shell holds, and this screen
     * reads its data again at once (one read for a burst) — so a resolve in another console moves this queue,
     * this map and this alert's page without a touch. A step that closes or takes an alert asks at once
     * whether the siren may stop. Caught up after every drop, so a step made while the socket was down lands.
     */
    let liveTimer = null

    const reread = () => {
        clearTimeout(liveTimer)
        liveTimer = setTimeout(() => {
            liveTimer = null
            poll?.refresh()
        }, 150)
    }

    const STEPS_THAT_MAY_SILENCE = ['alert.acknowledged', 'alert.dispatched', 'alert.en_route', 'alert.on_scene', 'alert.resolved', 'alert.false_alarm', 'alert.cancelled']

    const unregisterLive = registerLive({
        page: true,
        signals: false,
        channels: { has: (name) => name === 'gemini.dispatch' || /^gemini\.[^.]+\.dispatch(\.alert\.\d+)?$/.test(name) },
        accepts: (env) => env.e === 'alert',
        apply(env) {
            reread()

            if (STEPS_THAT_MAY_SILENCE.includes(env.t)) {
                checkAlarm()
            }
        },
        resync: () => reread(),
    })

    // This screen's data moves by itself: the honesty line says so rather than "does not update by itself".
    liveState.pageBindings++

    onUnmounted(() => {
        clearInterval(alarmTimer)
        clearTimeout(liveTimer)
        unregisterLive()
        liveState.pageBindings--
    })

    /** When the screen last fell back to polling, for the bar to say how long. */
    const downSince = ref(streaming.value ? null : new Date())

    watch(streaming, (isStreaming, wasStreaming) => {
        if (isStreaming) {
            poll.stop()
            downSince.value = null

            // Back from an outage: fetch once what the pushes may have missed.
            if (wasStreaming === false) {
                poll.refresh()
            }

            return
        }

        downSince.value = new Date()
        poll.refresh()
        poll.start()
    })

    /*
     * A clock for the bar's "for 2 min" and the map's staleness figure, so both
     * are live figures rather than values frozen at the last render.
     */
    const now = ref(Date.now())
    let ticker = null

    onMounted(() => {
        ticker = setInterval(() => {
            now.value = Date.now()
        }, 1000)
    })

    onUnmounted(() => {
        clearInterval(ticker)
        reachWaits.forEach((timer) => clearTimeout(timer))
        reachWaits.clear()
    })

    const connection = computed(() => ({
        mode: streaming.value ? 'live' : 'fallback',
        estates: streams.length,
        intervalMs,
        downForSeconds: downSince.value === null ? 0 : Math.max(0, Math.round((now.value - downSince.value.getTime()) / 1000)),
        lastUpdated: poll.lastUpdated.value,
    }))

    /** What the screen tells the dispatcher, in the two states it can be in. */
    const liveLabel = computed(() => (streaming.value ? 'Live' : 'Fallback · polling'))

    const liveReason = computed(() =>
        streaming.value
            ? 'Live over the alert channel: alerts and clock-ins push the moment they happen. If the channel '
              + 'drops, this screen falls back to polling within forty seconds and says so. Click to refresh now.'
            : `The live alert channel is down, so this screen is refreshing every ${intervalMs / 1000} seconds `
              + 'until it returns. Alerts can be up to that late. Click to refresh now.',
    )

    return { poll, streaming, connection, now, liveLabel, liveReason, panic }
}
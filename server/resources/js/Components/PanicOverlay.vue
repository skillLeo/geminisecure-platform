<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { awaitingControlRoom } from '../composables/alertStanding.js'
import { agoFrom, useTickingNow } from '../composables/useClock.js'

/**
 * The pop-up a panic puts in front of the estate's committee — M10 §1.
 *
 * The client asked for this in these words: "It must go to that community's main
 * dashboard where the president and all the people can see it: a pop-up, and
 * something blinking on it… they must know there is an emergency at a specific
 * lot."
 *
 * THE LOT IS THE LARGEST THING ON IT, and everything else is arranged around
 * that. A responder's first question is where, not what — and the reason it is
 * drawn this way rather than as a tidy notification is the client's other
 * sentence: "When people are in a panic they don't remember to do the things
 * they're supposed to do."
 *
 * IT IS A PANEL, NOT A `confirm()`. A browser dialog blocks the page, cannot be
 * styled, and would stop a second alert arriving while the first sat unanswered.
 *
 * CLOSING IT DOES NOT CLEAR THE INDICATOR. Dismissing the box means "I have read
 * this", not "this is handled", and the blinking bar stays until somebody says
 * otherwise. An unattended emergency must not be able to look like a handled
 * one.
 *
 * WHAT IT NEVER SHOWS: medical detail, the household's composition, or anything
 * else behind the alert screen's own permission. A lot, a name, a kind and a
 * time are what somebody needs in order to act.
 *
 * TWO CONSOLES, ONE POP-UP (M10 §1.2 items 3 and 4). The Gemini console mounts
 * this same component for the Director and for the Head of Security of the site,
 * as `variant="console"`, rather than a second copy that would drift from this
 * one — the lot first and largest, DRILL said first, the officer count only as
 * the server stated it. What differs is only what differs between the readers:
 *
 *   - THE ESTATE IS NAMED. A committee member is inside one estate; a Director
 *     watches all of them, and "Lot 47" alone does not say which.
 *   - THE BAR CANNOT BE CLEARED BY HAND. The estate's console has no way to
 *     acknowledge an alert, so its bar has a Clear button. A Director can, on the
 *     alert's own screen, so the console's bar stops when the SERVER says the
 *     alert was acknowledged or resolved — and offers the alert instead.
 *   - IT SAYS WHERE THE ALERT STANDS, from the last thing the server said and
 *     never ahead of it: "not acknowledged when last checked, at 10:42:05 PM",
 *     "acknowledged at 10:43 PM". It never claims an acknowledgement nobody made.
 *
 * `variant` defaults to the estate, so the estate console renders exactly what it
 * always did.
 */
const props = defineProps({
    /** The alert being shown, or null when there is none. */
    alert: { type: Object, default: null },

    /** Whether any alert this session is still outstanding. */
    blinking: { type: Boolean, default: false },

    /** Where the estate console is rooted, so the link works on either host shape. */
    root: { type: String, default: '' },

    /** `estate` — the committee's pop-up, as it was — or `console`, the Gemini console's. */
    variant: { type: String, default: 'estate' },

    /**
     * The alert the bar names, which outlives the pop-up — the newest one nobody has
     * acknowledged, a real one ahead of any drill, so a real emergency is never
     * relabelled a drill (M10 §1.3). On both consoles since B039: the estate's bar
     * read the lot off the open pop-up, and lost it the moment the pop-up closed.
     */
    current: { type: Object, default: null },

    /** How many alerts nobody has acknowledged, so the bar can say there are others. */
    outstanding: { type: Number, default: 0 },
})

const emit = defineEmits(['dismiss', 'clear'])

const onConsole = computed(() => props.variant === 'console')

/** ESTATE: the alert the bar names — the one it stands for, or the open pop-up's. */
const barAlert = computed(() => props.current ?? props.alert)

/*
 * THE CARD'S OWN CLOCK (B112). `when` read `Date.now()`, which Vue cannot watch, and nothing else
 * it read ever changed — the pop-up's alert is updated in place — so a card left open said "just
 * now" for as long as it stood. It reads a clock that moves every fifteen seconds instead.
 */
const now = useTickingNow()

/** "2 minutes ago", from the instant the server stamped, as of now. */
const when = computed(() => agoFrom(props.alert?.at, now.value) ?? 'just now')

/**
 * A server instant ON THE SITE'S CLOCK, tagged with it — "9:38 AM JA" (M19A §7: when it happened at the site, beside
 * how long ago). The zone's tag is made as the server makes it (SiteTime::clockTag). With no zone known yet, the
 * reader's own clock, untagged, as before.
 */
const tagOf = (zone) => {
    const words = String(zone).split('/').pop().split(/[\s_-]+/).filter(Boolean)
    if (words.length === 0) return ''
    return words.length === 1 ? words[0].slice(0, 2).toUpperCase() : words.map((w) => w[0]).join('').slice(0, 3).toUpperCase()
}
const clock = (iso, withSeconds = false) => {
    if (!iso) {
        return null
    }

    const at = new Date(iso)

    if (Number.isNaN(at.getTime())) {
        return null
    }

    const zone = props.alert?.zone ?? props.current?.zone ?? null
    const style = withSeconds ? { hour: 'numeric', minute: '2-digit', second: '2-digit' } : { hour: 'numeric', minute: '2-digit' }

    // An alert that arrives without its site's zone is printed on this browser's clock, and says so (M19A §4.2).
    try {
        return zone ? `${at.toLocaleTimeString('en-US', { ...style, timeZone: zone })} ${tagOf(zone)}` : `${at.toLocaleTimeString([], style)} your time`
    } catch {
        return `${at.toLocaleTimeString([], style)} your time`
    }
}

/**
 * The time it was raised, beside "2 minutes ago" — the time, not only its age. On both cards
 * since B112: the estate's said only its age, so its one clue to how long the emergency had been
 * going on was the one that could go stale.
 */
const raisedAt = computed(() => clock(props.alert?.at))

/**
 * CONSOLE: where the alert stands — ONLY what the server has said, and when it said it.
 *
 * `status` is `open` from the broadcast itself (an alert is created unacknowledged and
 * announced as it is created) and from every poll after it; anything else comes from the
 * server's own row. No branch here infers an acknowledgement from silence, and an
 * unknown status says so rather than guessing.
 */
const standing = computed(() => {
    const alert = props.alert

    if (!alert) {
        return null
    }

    const at = (iso) => (clock(iso) ? ` at ${clock(iso)}` : '')

    switch (alert.status) {
        case 'open':
            return alert.statusFrom === 'server'
                ? `Not acknowledged when last checked${clock(alert.statusAt, true) ? `, at ${clock(alert.statusAt, true)}` : ''}.`
                : 'Nobody had acknowledged it when it reached this screen.'
        case 'acknowledged':
            return `Acknowledged${at(alert.acknowledgedAt)}. It stays open until dispatch resolves it.`
        case 'responding': {
            /*
             * The response's furthest RECORDED step (`AlertProgress`), and only that: a step
             * nobody recorded is not said, so "on the way" never stands in for "dispatched".
             *
             * "Acknowledged" ONLY WHEN THE SERVER SAYS SO (B090). An officer the panic reached
             * can take it from the handset before anybody in the control room has looked —
             * `responding`, with no acknowledgement — and this read "Acknowledged; an officer
             * is on the way" about an alert nobody in the control room had acknowledged.
             */
            const who = alert.acknowledgedAt
                ? `Acknowledged${at(alert.acknowledgedAt)}`
                : 'Not acknowledged by the control room'

            if (alert.onSceneAt) {
                return `${who}; an officer is on scene${clock(alert.onSceneAt) ? ` since ${clock(alert.onSceneAt)}` : ''}.`
            }

            if (alert.enRouteAt) {
                return `${who}; an officer is on the way${clock(alert.enRouteAt) ? ` since ${clock(alert.enRouteAt)}` : ''}.`
            }

            if (alert.dispatchedAt) {
                return `${who}; an officer was dispatched${at(alert.dispatchedAt)}.`
            }

            return `${who}; an officer is responding.`
        }
        case 'resolved':
            return `Resolved${at(alert.resolvedAt)}.`
        case 'false_alarm':
            return alert.cancelled
                ? `Cancelled from the phone that raised it${at(alert.resolvedAt)}.`
                : `Resolved as a false alarm${at(alert.resolvedAt)}.`
        default:
            return 'Whether anybody has acknowledged it shows on the alert itself.'
    }
})

/** CONSOLE: the control room still owes this alert an answer (`alertStanding`). */
const awaiting = computed(() => (props.alert ? awaitingControlRoom(props.alert) : false))

/*
 * THE BAR'S HEIGHT, SO THE SIDEBAR PINS BELOW IT. The bar sits in the page, pushing
 * it down (M60 §C4), and stays at the top of the window as the page scrolls; the
 * sidebar is pinned to the window too, so it is pinned below the bar. While the bar
 * shows, its height is published as `--panic-bar-h` on the root element, and app.css
 * pins the sidebar there and takes the bar out of the shell's minimum height.
 * Measured, not assumed: on a narrow window the text wraps and the bar is taller.
 * Removed the moment the bar goes. Guarded, because the component is also mounted
 * with no document at all (tests/js).
 */
const bar = ref(null)
let watching = null

const publish = (px) => {
    const root = typeof document === 'undefined' ? null : document.documentElement

    if (!root?.style) {
        return
    }

    if (px > 0) {
        root.style.setProperty('--panic-bar-h', `${px}px`)
    } else {
        root.style.removeProperty('--panic-bar-h')
    }
}

const heightOf = (el) => Number(el?.offsetHeight) || 0

watch(
    bar,
    (el) => {
        watching?.disconnect()
        watching = null
        publish(heightOf(el))

        if (el && typeof ResizeObserver !== 'undefined') {
            watching = new ResizeObserver(() => publish(heightOf(el)))
            watching.observe(el)
        }
    },
    { flush: 'post' },
)

onBeforeUnmount(() => {
    watching?.disconnect()
    publish(0)
})
</script>

<template>
    <!--
      The persistent indicator. It outlives the pop-up on purpose: the client
      asked for "something blinking on it" so that an emergency nobody has dealt
      with cannot be closed out of sight.
    -->
    <div v-if="blinking" ref="bar" class="panic-bar" role="status" aria-live="assertive">
        <span class="panic-bar-dot" />
        <span v-if="onConsole" class="panic-bar-text">
            <template v-if="current">
                <template v-if="current.drill">DRILL at </template><template v-else>Emergency at </template>{{ current.estateName }} · {{ current.unit }}<template v-if="current.drill"> · a test of who a panic reaches</template><template v-if="outstanding > 1"> · {{ outstanding - 1 }} more {{ outstanding - 1 === 1 ? 'needs' : 'need' }} attention</template>
            </template>
            <template v-else>An alert nobody has acknowledged yet</template>
        </span>
        <!--
          THE ESTATE'S BAR NAMES THE LOT ONCE THE POP-UP IS CLOSED (B039). It
          read the lot off the open pop-up and nothing else, so after Close — or
          on a page drawn after the panic — it said only "Emergency at this
          estate". It names the alert it stands for now, a real one ahead of any
          drill, and says when there are others.
        -->
        <!-- M53 §4.9: the count first, "need attention", one line. A drill says so, and only when every alert is one. -->
        <span v-else class="panic-bar-text">
            <template v-if="barAlert?.drill">DRILL at this estate: a test of who a panic reaches</template>
            <template v-else>{{ outstanding }} {{ outstanding === 1 ? 'emergency needs' : 'emergencies need' }} attention</template>
        </span>
        <!--
          The console's bar is not cleared by hand: it stops when the alert is
          acknowledged or resolved, and what it offers is the alert itself.
          The estate's offers the Security screen, where the alert's standing
          is, and keeps its Clear button: the estate console cannot acknowledge.
        -->
        <Link v-if="onConsole && current" :href="current.href" class="panic-bar-btn" @click="emit('dismiss')">Open alert</Link>
        <template v-else-if="!onConsole">
            <Link :href="`${root}/security`" class="panic-bar-btn" @click="emit('dismiss')">Open Security</Link>
            <!-- It hides the bar for this person until the next alert, and changes no alert's record (M53 §4.9): so it says Hide. -->
            <button type="button" class="panic-bar-btn" title="Hide this bar until the next alert." @click="emit('clear')">Hide</button>
        </template>
    </div>

    <div v-if="alert" class="panic-scrim" role="alertdialog" aria-modal="true" aria-labelledby="panic-lot">
        <div class="panic-card">
            <!-- A DRILL SAYS SO FIRST (M10 §1.3), before the lot, before the kind. -->
            <div v-if="alert.drill" class="panic-drill">DRILL: nobody pressed a panic button. This tests who a panic reaches.</div>
            <div class="panic-kind"><template v-if="alert.drill">DRILL · </template>{{ alert.kindLabel }}<template v-if="alert.simulated"> · SIMULATED</template></div>

            <!-- The lot, first and largest. -->
            <div id="panic-lot" class="panic-lot">{{ alert.unit }}</div>

            <!-- Which estate — the console watches all of them, and a lot alone does not say. -->
            <div v-if="onConsole" class="panic-estate">{{ alert.estateName }}</div>

            <div class="panic-who">{{ alert.who }} · {{ raisedAt ? `${raisedAt}, ${when}` : when }}</div>

            <!--
              WHAT IS TRUE, FROM THE SERVER'S OWN NUMBERS (M10 §1).

              This read "The control room has this alert and officers have been
              notified" whatever had happened — on an estate with nobody on shift,
              and on the demonstration estate, whose alerts Gemini never sees. A
              committee member who believed it would stand down on a sentence nobody
              had checked. Each line below is now a fact the broadcast carried, or
              it is not said:

                - the control room, only when the server says it receives this
                  estate's alerts;
                - the officers' phones, as the fan-out counted them and
                  `alert.reach` delivered them — "Alerting officers…" while that
                  count is on its way, never a guessed number;
                - acknowledgement, never: this pop-up is not told when an officer
                  answers, so it does not say either way.

              ON THE CONSOLE the first line is where the alert stands instead: the
              reader IS the control room, and the console does ask the server
              whether it has been acknowledged — so it says what it was told, and
              when.
            -->
            <!-- Red while the control room owes it an answer — an officer on the way included (B090). -->
            <p v-if="onConsole" class="panic-status" :class="{ open: awaiting }">{{ standing }}</p>
            <p v-else-if="alert.controlRoom === true" class="panic-note">This alert is in Gemini's control room queue.</p>
            <p v-else-if="alert.controlRoom === false" class="panic-note">
                This is the demonstration estate: Gemini's control room does not receive its alerts.
            </p>

            <!--
              The count arrives SECOND (M10 §1.2): this pop-up is sent before any
              officer's phone has been tried, and `alert.reach` brings the number a
              moment later. Until then, "Alerting officers…". If it never comes, the
              line says so and gives the number to call — it does not go on saying
              "alerting" as if that were still news.
            -->
            <p class="panic-reach" :class="{ none: alert.handsets === 0 || alert.reachLate }">
                <template v-if="alert.simulated && alert.handsets === null">
                    Simulated alert: no officer's phone is sent it.
                </template>
                <template v-else-if="alert.drillMode">
                    Drill mode: the resident's account was in drill mode, so no officer's phone rang and nobody was emailed or texted.
                </template>
                <template v-else-if="alert.handsets === null && alert.reachLate">
                    How many officers' phones were reached has not come through — check {{ onConsole ? 'the alert' : 'the Security screen' }}, or call
                    {{ alert.call ? `${alert.call.label} on ${alert.call.number}` : 'the emergency services on 119' }}.
                </template>
                <template v-else-if="alert.handsets === null">
                    Alerting officers…
                </template>
                <template v-else-if="alert.handsets > 0">
                    Officers' phones alerted: {{ alert.handsets }}<template v-if="alert.via === 'rostered'"> — nobody is
                    clocked on, so these are the officers rostered here today</template>.
                </template>
                <template v-else>
                    No officer's phone was reached — call
                    {{ alert.call ? `${alert.call.label} on ${alert.call.number}` : 'the emergency services on 119' }}.
                </template>
            </p>

            <p v-if="!onConsole" class="panic-note">
                Whether an officer has acknowledged it shows on the Security screen. If anyone is in immediate
                danger, call the emergency services as well.
            </p>

            <div class="panic-actions">
                <!-- The dispatch alert's own screen: who is on duty, the timeline, and the buttons that answer it. -->
                <Link v-if="onConsole" :href="alert.href" class="panic-open" @click="emit('dismiss')">
                    Open alert
                </Link>
                <Link v-else :href="`${root}/security`" class="panic-open" @click="emit('dismiss')">
                    Open Security
                </Link>
                <button type="button" class="panic-close" @click="emit('dismiss')">Close</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * Authored. No board draws this — the estate console received nothing at all
 * when a panic was raised, so there was no design to reproduce. It uses the
 * palette's own red and the console's own radii, and introduces no colour the
 * rest of the console does not already carry.
 *
 * EVERY COLOUR IS A TOKEN. These rules carried a hex fallback inside each
 * `var()`, and several of the fallbacks were not even the token's value — a red
 * and a slate from some other palette, which would have shown the day the token
 * failed to load and never otherwise. `tokens.css` is imported by `app.css` on
 * every page, so the fallback was dead code with the wrong colour in it.
 */
/*
 * IN THE PAGE, PUSHING IT DOWN (M60 §C4): sticky in the flow before the console's shell, so the page starts below the
 * bar and is never drawn under it, and the bar stays at the top of the window as the page scrolls. It was fixed over
 * the page, and the shell made room for it only on pages carrying a board's class, so on a page without one, or after a
 * scroll, the header's buttons sat under it.
 */
.panic-bar {
    position: sticky;
    top: 0;
    z-index: 900;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    background: var(--red-700);
    color: var(--white);
    font-size: 13px;
    font-weight: 700;
}

.panic-bar-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--white);
    animation: panic-blink 1s steps(1, end) infinite;
}

.panic-bar-text {
    flex: 1;
    min-width: 0;
}

.panic-bar-btn {
    -webkit-appearance: none;
    appearance: none;
    border: 1px solid rgba(255, 255, 255, 0.6);
    background: transparent;
    color: var(--white);
    font: inherit;
    font-size: 12px;
    border-radius: 7px;
    padding: 3px 10px;
    cursor: pointer;

    /* The console's is a link to the alert, and a link arrives underlined. */
    text-decoration: none;
    white-space: nowrap;
}

@keyframes panic-blink {
    50% {
        opacity: 0.15;
    }
}

/* A reader who has asked for no motion still gets the bar, in one colour. */
@media (prefers-reduced-motion: reduce) {
    .panic-bar-dot {
        animation: none;
    }
}

.panic-scrim {
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(11, 31, 51, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.panic-card {
    background: var(--white);
    border-radius: 18px;
    border-top: 6px solid var(--red-700);
    padding: 26px 28px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 24px 60px rgba(11, 31, 51, 0.35);
}

/* A drill, said in a sentence above everything else on the card. */
.panic-drill {
    margin-bottom: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1.5px dashed var(--red-700);
    font-size: 12.5px;
    font-weight: 700;
    color: var(--red-700);
}

.panic-kind {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--red-700);
}

/* The largest thing on the card, because where is the first question. */
.panic-lot {
    font-family: 'Poppins', sans-serif;
    font-size: 34px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.15;
    margin-top: 6px;
    overflow-wrap: anywhere;
}

/* Console only: which estate, directly under the lot it qualifies. */
.panic-estate {
    margin-top: 2px;
    font-size: 16px;
    font-weight: 700;
    color: var(--navy-900);
    overflow-wrap: anywhere;
}

.panic-who {
    margin-top: 6px;
    font-size: 13.5px;
    color: var(--navy-700);
}

.panic-note {
    margin: 16px 0 0;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--slate-500);
}

/* Console only: where the alert stands, in red while nobody has acknowledged it. */
.panic-status {
    margin: 16px 0 0;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.5;
    color: var(--navy-700);
}

.panic-status.open {
    color: var(--red-700);
}

/* The one line the reader acts on: how many officers' phones, or who to call instead. */
.panic-reach {
    margin: 12px 0 0;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.45;
    color: var(--navy-900);
}

.panic-reach.none {
    color: var(--red-700);
}

.panic-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.panic-open {
    flex: 1;
    text-align: center;
    text-decoration: none;
    background: var(--red-700);
    color: var(--white);
    font-size: 13px;
    font-weight: 700;
    border-radius: 10px;
    padding: 11px 14px;
}

.panic-close {
    -webkit-appearance: none;
    appearance: none;
    border: 1.5px solid var(--navy-200);
    background: var(--white);
    color: var(--navy-700);
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    border-radius: 10px;
    padding: 11px 16px;
    cursor: pointer;
}
</style>

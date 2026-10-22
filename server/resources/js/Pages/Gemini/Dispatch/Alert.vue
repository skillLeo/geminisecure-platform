<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import { toast } from '../../../lib/toasts.js'
import { nextStep as nextStepOf, stepClass as stepClassOf } from '../../../lib/alertSteps.js'
import SourceBadge from '../../../Components/SourceBadge.vue'
import DispatchConnectionBar from '../../../Components/DispatchConnectionBar.vue'
import PanicAlarmControls from '../../../Components/PanicAlarmControls.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import ConsoleDialog from '../../../Components/ConsoleDialog.vue'
import BackLink from '../../../Components/BackLink.vue'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'
import { agoFrom, useTickingNow } from '../../../composables/useClock.js'

/**
 * Panic alert response — board screen super-admin-17.
 *
 * DOM and class names are the board's. The two inline styles — the dimmed
 * pending timeline row and the message box — are the board's own, copied
 * rather than promoted to classes the design does not define.
 *
 * The board draws the four actions as <div>s. Here they are real buttons that
 * POST and change state — acknowledge, dispatch an officer, and resolve, plus
 * the two steps a radio call records on the officer's behalf, en route and on
 * scene (M10 §1.3). Escalation alone is rendered disabled and captioned,
 * because nothing in this system records an escalation to the JCF, and a
 * life-safety button that accepts a click and does nothing is the worst
 * control on the screen.
 */
const props = defineProps({
    alert: { type: Object, required: true },
    timeline: { type: Array, required: true },
    actions: { type: Object, required: true },
    /** This alert's own estate, and no other. */
    estateIds: { type: Array, default: () => [] },
    /**
     * Whether THIS alert came from a real handset or from the simulator — a
     * response screen is about one incident. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },

    /**
     * The officers clocked on at this estate, in name order.
     *
     * A LIST, NOT A RANKING (M10 §0). A distance ranking briefly lived here and
     * the client has ruled it out: a panic reaches everyone who can help, at
     * once, and no officer is singled out as nearest. Nothing in this array
     * carries a distance, nothing is presented as closer than anything else,
     * and the order is alphabetical — an order rather than a claim.
     *
     * It survives because the message box needs an addressee and a dispatcher
     * needs to see who is actually available to be told something.
     */
    onDuty: { type: Array, default: () => [] },

    /**
     * WHO WAS TOLD, AND HOW FAST (M10 §1.3) — `Services\Dispatch\DeliveryReport`.
     *
     * All seven of §1.2's destinations, each with what it was handed, by which
     * channel, and how many milliseconds after the fan-out began. HANDED OFF,
     * never delivered: the platform knows when it let go of a message, not when a
     * phone received it, and the caption under the list says so every time.
     */
    deliveries: { type: Object, default: null },

    /**
     * THE RING (the guard ring §9.3) — `Services\Ring\RingReport`.
     *
     * Round by round: how many phones were SENT the ring, how many of those were
     * call-style ready and how many only partly (never added together), how many the
     * phones themselves said they SHOWED, and how many with the alarm sound on. The
     * word "rang" is not in it, because nothing the platform receives can say so.
     */
    rings: { type: Object, default: null },

    /**
     * THE RESIDENT'S LIVE CHANNEL (M50 §4.2 #2) — `Services\Dispatch\PanicMessages`. Null on an officer's duress.
     * `{ messages: [{ id, from: 'resident'|'dispatch', name, body, at, seen_at }], canReply, closed, max }`.
     */
    residentThread: { type: Object, default: null },

    /**
     * THE RESIDENT'S LIVE AUDIO (M50 §4.2 #1) — `Services\Dispatch\PanicAudio`. Null on an officer's duress.
     * `{ pieces, seconds, last_at, live, list_url, live_seconds }`.
     */
    residentAudio: { type: Object, default: null },
})

/* --- listening to the resident's live audio (M50 §4.2 #1) -------------- */

/*
 * The phone sends a few seconds at a time. "Listen" asks the server every two seconds for the pieces after the last
 * one queued and plays them in order, one after another, on one <audio> element; "From the start" queues them all.
 * Nothing plays until a person presses a button — a browser will not start sound by itself, and a console that did
 * would be a surprise in a control room.
 */
const audioListening = ref(false)
const audioPlaying = ref(false)
const audioProblem = ref(null)
const audioSummary = ref(props.residentAudio)
watch(() => props.residentAudio, (next) => { audioSummary.value = next })

let audioQueue = []
let audioLastSeq = -1
let audioTimer = null
const audioPlayer = typeof Audio === 'undefined' ? null : new Audio()

const playNextPiece = () => {
    if (!audioPlayer || audioPlaying.value || audioQueue.length === 0) {
        return
    }

    audioPlaying.value = true
    audioPlayer.src = audioQueue.shift()
    audioPlayer.play().catch(() => {
        audioPlaying.value = false
        audioProblem.value = 'The browser would not play the audio. Press Listen again.'
    })
}

if (audioPlayer) {
    audioPlayer.onended = () => {
        audioPlaying.value = false
        playNextPiece()
    }
    audioPlayer.onerror = () => {
        audioPlaying.value = false
        playNextPiece()
    }
}

const fetchPieces = async () => {
    if (!props.residentAudio) {
        return
    }

    try {
        const response = await fetch(`${props.residentAudio.list_url}?after=${audioLastSeq}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })

        if (!response.ok) {
            throw new Error(String(response.status))
        }

        const body = await response.json()
        audioSummary.value = { ...audioSummary.value, ...(body.summary ?? {}) }
        audioProblem.value = null

        for (const piece of body.pieces ?? []) {
            audioQueue.push(piece.url)
            audioLastSeq = Math.max(audioLastSeq, piece.seq)
        }

        playNextPiece()
    } catch {
        audioProblem.value = 'The audio could not be fetched just now. Trying again every two seconds.'
    }
}

const listen = (fromStart) => {
    stopListening()
    audioListening.value = true
    // Live: the newest piece or two, then whatever arrives. From the start: every piece, in order.
    audioLastSeq = fromStart ? -1 : Math.max(-1, (audioSummary.value?.pieces ?? 0) - 2)
    fetchPieces()
    audioTimer = setInterval(fetchPieces, 2000)
}

function stopListening() {
    audioListening.value = false
    clearInterval(audioTimer)
    audioTimer = null
    audioQueue = []
    audioPlaying.value = false
    audioPlayer?.pause()
}

onUnmounted(stopListening)

const audioLine = computed(() => {
    const a = audioSummary.value

    if (!a || a.pieces === 0) {
        return 'The resident is not sharing audio. It starts when their "Share live audio" switch is on.'
    }

    const recorded = a.seconds >= 60 ? `${Math.floor(a.seconds / 60)} min ${a.seconds % 60} s` : `${a.seconds} s`

    return a.live
        ? `Live — their phone is sending audio now (${recorded} so far).`
        : `Not live — the last audio came ${agoFrom(a.last_at, now.value) ?? 'a while ago'} (${recorded} in all).`
})

/* --- replying to the resident (M50 §4.2 #2) ---------------------------- */

const reply = useForm({ body: '' })

const sendReply = () => {
    if (!props.residentThread || reply.processing || reply.body.trim() === '') {
        return
    }

    reply.post(props.actions.reply_url, {
        preserveScroll: true,
        onSuccess: () => {
            reply.reset('body')
        },
        onError: (errors) => {
            // A refusal of the reply as a whole is what just happened, pressed with no dialog open: a toast. A fault
            // in what was typed stays under the box.
            if (errors.reply) {
                toast(errors.reply, { tone: 'error' })
            }
        },
    })
}

/* --- telling an officer to go ---------------------------------------- */

/*
 * WHO THE BOX IS ADDRESSED TO, and it is a choice rather than a fixed name.
 *
 * It opens on the first officer on duty so a dispatcher need not pick before
 * they can type — first by NAME, which is not a recommendation. The "Message"
 * button beside each officer moves the address. (Sending an officer is no longer
 * done from here: "Dispatch" below records it — M10 §1.3.)
 */
const messageTo = ref(props.onDuty[0]?.guard_id ?? null)

/* The server decides who is on duty; if that changes under a live refresh,
 * follow it rather than keep addressing somebody who has clocked off. */
watch(
    () => props.onDuty,
    (list) => {
        if (!list.some((c) => c.guard_id === messageTo.value)) {
            messageTo.value = list[0]?.guard_id ?? null
        }
    }
)

const addressee = computed(() => props.onDuty.find((c) => c.guard_id === messageTo.value) ?? null)

const message = useForm({ guard_id: null, body: '' })

/*
 * WHAT THE SERVER SAID AFTER A STEP HERE (B115, B018). Every action here redirects back with a sentence in the flash,
 * "Marcus Reid is dispatched… confirm by radio that they have it", "Sent to Marcus Reid", and once nothing on this
 * screen read it, so the one instruction a dispatch carried was never seen. It is what just happened, so a toast top
 * right with a cross (Hassam, 12 October); the shell says it, and on this screen it then stays up until the dispatcher
 * closes it, because it can carry that instruction. The same words make one toast, never two.
 */
const page = usePage()

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            toast(flash.success, { tone: 'success', sticky: true })
        }
    },
    { flush: 'post' },
)

const sendMessage = () => {
    if (!messageTo.value || message.processing) {
        return
    }

    message.guard_id = messageTo.value
    message.post(props.actions.message_url, {
        preserveScroll: true,
        onSuccess: () => {
            message.reset('body')
        },
    })
}

/* --- sending an officer, and the two steps a radio call records (M10 §1.3) --- */

/*
 * WHO TO SEND, chosen from the officers this panic was actually pushed to — the
 * server's list, in name order. It opens on the first by NAME, which is an order
 * and not a recommendation (M10 §0); nothing is sent until the button is pressed.
 *
 * This replaces "Dispatch another guard", which only re-addressed the message box:
 * a button reading "Dispatch Marcus" that recorded nothing and told the resident
 * nothing was a claim the screen could not keep.
 */
const dispatchTo = ref(props.actions.dispatch_candidates?.[0]?.guard_id ?? null)

watch(
    () => props.actions.dispatch_candidates,
    (list) => {
        if (!(list ?? []).some((c) => c.guard_id === dispatchTo.value)) {
            dispatchTo.value = list?.[0]?.guard_id ?? null
        }
    }
)

const chosen = computed(() => (props.actions.dispatch_candidates ?? []).find((c) => c.guard_id === dispatchTo.value) ?? null)

/*
 * The server's own sentence when it refuses a step, never a generic failure (B115, B018: a refusal once came back and
 * nothing on the screen said anything). A step is pressed with no dialog open, so its refusal is what just happened: a
 * toast top right that stays until it is closed (Hassam, 12 October). The sentence a step was answered with is the
 * shell's toast, kept up above.
 */
const progress = (url, data = {}) => {
    router.post(url, data, {
        preserveScroll: true,
        onError: (errors) => {
            toast(errors.progress ?? errors.guard_id ?? errors.note ?? Object.values(errors)[0] ?? 'That could not be recorded.', { tone: 'error' })
        },
    })
}

const dispatchOfficer = () => {
    if (chosen.value) {
        progress(props.actions.dispatch_url, { guard_id: chosen.value.guard_id })
    }
}

const markEnRoute = () => progress(props.actions.en_route_url)

const markOnScene = () => progress(props.actions.on_scene_url)

/**
 * The second life-safety surface. A dispatcher holding this open must see the
 * status change under them — a guard acknowledging on the ground, the alert
 * resolving — without reloading.
 *
 * It subscribes to one estate: this alert's own. The queue and the map watch
 * every estate a dispatcher covers, but this screen is about one incident at one
 * community, and listening wider would refresh it every time anything happened
 * anywhere.
 *
 * Polling runs only while the channel is down (13 C2), every three seconds, and
 * the bar says so. On a screen a dispatcher is holding open DURING an incident,
 * a socket that quietly stopped delivering would look exactly like an alert
 * nobody was responding to — which is why a dead socket is detected by its own
 * ping within forty seconds and the screen drops to polling at once.
 */
const { poll, connection, panic } = useLiveDispatch({
    // The ranking too: a guard clocking on or off during an incident changes who
    // should go, and a list that refreshed only on a full reload would keep
    // recommending somebody who has gone off duty.
    // `onDuty` too: a guard clocking on or off during an incident changes who
    // can be told something, and a list that refreshed only on a full reload
    // would keep offering somebody who has gone home.
    // `deliveries` too: the hand-off rows are written the moment the last leg goes,
    // which can be a few milliseconds after this screen was opened from the queue.
    // `rings` too: a round, a phone's receipt and the stop all land while the screen is open.
    only: ['alert', 'timeline', 'actions', 'onDuty', 'deliveries', 'rings'],
    intervalMs: 3000,
    estateIds: props.estateIds,
})

/*
 * "FIRED", AS OF NOW (B112). The server's "2 minutes ago" is true at the moment the page was
 * drawn, and on a quiet channel this screen re-reads nothing — so a dispatcher twenty minutes
 * into an incident read that it had just begun. The age is counted here from the instant.
 */
const now = useTickingNow()
const fired = computed(() => agoFrom(props.alert.fired_at, now.value) ?? props.alert.fired)

const acknowledge = () => progress(props.actions.acknowledge_url)

/** People to call (M18C §4.2): who pressed, their household, their emergency contacts — each group said, even empty. */
const peopleGroups = computed(() => {
    const p = props.alert.people
    if (!p) return []
    return [
        { title: 'Who raised it', people: p.resident ? [p.resident] : [], none: 'Not on the register — the unit is above.' },
        { title: 'Household', people: p.members ?? [], none: 'Nobody else on the register.' },
        { title: 'Emergency contacts', people: p.contacts ?? [], none: 'None on file.' },
    ]
})

/**
 * "Resolve & classify" — a choice from the server's list, and a note for what
 * the list cannot say (24 September 2026).
 *
 * This was the browser's own window.prompt, and the free text it took WAS the
 * classification: "asdf" went on the record as readily as "medical", and a
 * refusal (a note over the limit, B115) made the prompt vanish with the alert
 * still open and no word why. It is the console's dialog now. The list and the
 * limit come from `actions`, which reads them from the same place the server
 * checks them (App\Support\AlertClassification), and every refusal is shown in
 * the dialog under the field it is about.
 *
 * Cancel posts nothing. Esc and the backdrop cancel too; nothing but the red
 * button resolves.
 *
 * B115 (a note over the limit refused with no word) is answered in the dialog:
 * the counter shows the limit, the button says why it cannot be pressed, and a
 * refusal from the server shows under the note.
 */
const resolveOpen = ref(false)
const resolveForm = useForm({ classification: '', note: '' })
const noteMax = computed(() => props.actions.resolve_note_max ?? 500)

const classificationGroups = computed(() => {
    const groups = []

    for (const option of props.actions.resolve_classifications ?? []) {
        let group = groups.find((g) => g.name === option.group)

        if (!group) {
            group = { name: option.group, options: [] }
            groups.push(group)
        }

        group.options.push(option)
    }

    return groups
})

const chosenClassification = computed(() => (props.actions.resolve_classifications ?? []).find((o) => o.value === resolveForm.classification) ?? null)

const resolveBlocked = computed(() => {
    if (!chosenClassification.value) {
        return 'Choose how this alert is classified'
    }

    if (chosenClassification.value.note_required && resolveForm.note.trim() === '') {
        return 'Add a few words on what happened — every closed alert keeps a note'
    }

    if (resolveForm.note.length > noteMax.value) {
        return `The note is over ${noteMax.value} characters`
    }

    return null
})

const resolve = () => {
    resolveForm.clearErrors()
    resolveOpen.value = true
}

const cancelResolve = () => {
    resolveOpen.value = false
    resolveForm.clearErrors()
}

const submitResolve = () => {
    if (resolveBlocked.value || resolveForm.processing) {
        return
    }

    resolveForm.post(props.actions.resolve_url, {
        preserveScroll: true,
        onSuccess: () => {
            resolveOpen.value = false
            resolveForm.reset()
        },
    })
}

/*
 * M96: WHEN EVERY ACTION IS DEAD, THE RESULT INSTEAD OF THE BUTTONS. A closed alert takes no step, and a role that may
 * only watch can never take one; either way the panel said so through six greyed buttons and their tooltips. Read from
 * the server's own flags, and a screen drawn by an older server (no flags) keeps its buttons.
 */
const actionsResult = computed(() => {
    if (props.actions.settled === true) {
        return 'This alert is closed. Every step, and who took it, is on the timeline.'
    }

    return props.actions.may_act === false ? 'Your role may watch dispatch but not act on an alert.' : null
})

/**
 * A recorded step's badge: its state in the Emergency list's word, then who and when (M96 §3; Hassam, 11 Oct: the badge
 * is the state, the button is the verb): "Acknowledged · 10:42 PM", "Dispatched · Marcus Reid · 10:44 PM".
 */
const doneBadge = (state, ...details) => [state, ...details.filter((detail) => detail)].join(' · ')

// Which button is supposed to be clicked first (Hassam, Batch 1, 12 Oct), the pure logic lib/alertSteps.js, so it can
// be proved on its own: the workflow's order, read from the steps already recorded, never a new restriction.
const nextStep = computed(() => nextStepOf(props.actions))
const stepClass = (key) => stepClassOf(props.actions, key)

/* Why Escalate cannot be pressed, said on the screen and not only on hover: nothing in this system records it yet. */
const ESCALATION_WHY = 'Not recorded here yet: call the police directly, then say so when you resolve the alert.'
</script>

<template>
    <Head :title="`${alert.headline}: ${alert.who}`" />

    <GeminiConsole :title="`${alert.headline}: ${alert.who}`">
        <!-- The way back, in words, to the queue this alert is opened from (M95 §3): the shared back control. -->
        <template #lead>
            <BackLink href="/dispatch/alerts" label="Back to alerts" />
        </template>

        <template #byline>
            <SourceBadge v-bind="sourceBadge" />
            <DispatchConnectionBar :connection="connection" @refresh="poll.refresh()" />
            <!--
              The siren sounds here too — for another panic at this estate while this
              one is being worked — so it can be quietened here (B107).
            -->
            <PanicAlarmControls :alarm="panic" />
        </template>

        <div class="response-layout">
            <div>
                <div class="alert-hero">
                    <div class="ah-top">
                        <div>
                            <div class="ah1">{{ alert.headline }}</div>
                            <div class="ah2">{{ alert.subtitle }}</div>
                        </div>
                        <div class="ah-badge">{{ alert.status_label }}</div>
                    </div>
                    <div class="ah-stats">
                        <div>
                            <div class="ahs-v">{{ fired }}</div>
                            <div class="ahs-l">Fired</div>
                        </div>
                        <div>
                            <div class="ahs-v">{{ alert.responder }}</div>
                            <!--
                              "Guard on post", and never "Nearest guard".

                              This label read "Nearest guard" over a name that
                              `responder()` chose alphabetically — no distance was
                              computed and no position consulted, so a dispatcher
                              reading it believed a calculation had happened. A
                              distance ranking was then built to make it true, and
                              the client has since ruled the whole idea out: a
                              panic goes to everyone who can help, at once (M10
                              §0). So the honest label is back, and it is the only
                              claim this line makes.
                            -->
                            <div class="ahs-l">Guard on post</div>
                        </div>
                        <div>
                            <div class="ahs-v">{{ alert.location_source }}</div>
                            <div class="ahs-l">Location source</div>
                        </div>
                    </div>
                </div>

                <!--
                  A DRILL SAYS SO BEFORE ANYTHING ELSE (M10 §1.3). `panic:walk`
                  raises a real alert on the resident's path to prove who a panic
                  reaches; the headline already starts with DRILL, and this says in
                  a sentence what that means so nobody dispatches to it.
                -->
                <div v-if="alert.is_drill" class="drill-note" role="note">
                    Drill: a practice alert, raised to test who a panic reaches. Nobody pressed a panic button.
                </div>

                <!--
                  WHO IS ON DUTY — A LIST, NOT A RANKING.

                  This panel briefly ordered officers by distance from the
                  incident. The client has ruled that out: a panic reaches
                  everyone who can help, at once, and nobody is nominated as
                  nearest (M10 §0). What is left is the useful half — who is
                  actually clocked on right now, so a dispatcher can see the
                  cover they have and address a message to one of them. The
                  order is alphabetical and the caption says so, because an
                  unexplained order on a life-safety screen gets read as a
                  recommendation.
                -->
                <div v-if="onDuty.length" class="panel">
                    <div class="panel-head">On duty now</div>

                    <div
                        v-for="officer in onDuty"
                        :key="officer.shift_id ?? officer.guard_id"
                        class="cand-row"
                        :class="{ top: officer.guard_id === messageTo }"
                    >
                        <div class="cand-who">
                            <div class="cand-name">{{ officer.name }}</div>
                            <div class="cand-post">{{ officer.post }}</div>
                        </div>
                        <button
                            type="button"
                            class="cand-pick"
                            :disabled="!actions.can_message"
                            :title="actions.message_title ?? `Address the message box to ${officer.name}`"
                            @click="messageTo = officer.guard_id"
                        >
                            {{ officer.guard_id === messageTo ? 'Messaging' : 'Message' }}
                        </button>
                    </div>

                    <p class="cand-basis">
                        Every officer clocked on at this estate, in name order. A panic reaches all of them at
                        once — none of them is nearer to the incident than another as far as this platform knows.
                    </p>
                </div>

                <div class="panel">
                    <div class="panel-head">Response timeline</div>
                    <!--
                      Every state this alert has actually reached, and the ones
                      it has not, drawn as pending. Nothing is narrated that the
                      row cannot prove.
                    -->
                    <div v-for="(step, i) in timeline" :key="i" class="timeline-row">
                        <div class="tl-dot-col">
                            <div class="tl-dot" :class="{ pending: step.pending }"></div>
                            <div v-if="i < timeline.length - 1" class="tl-line"></div>
                        </div>
                        <div class="tl-txt" :class="{ 'tl-pending': step.pending }">
                            <div class="tl1">{{ step.title }}</div>
                            <div class="tl2">{{ step.meta }}</div>
                        </div>
                    </div>
                </div>

                <!--
                  RINGING (the guard ring §9.3).

                  Every round of the call-style ring: the raise, the escalation to every
                  officer of the estate, the re-rings. SENT is the push service taking the
                  message; SHOWN ON THE PHONE is the phone's own receipt; WITH THE ALARM
                  SOUND ON is that receipt's own sound check. The caption says so under the
                  list every time, and nothing here says a phone "rang".
                -->
                <div v-if="rings && rings.active" class="panel">
                    <div class="panel-head">Ringing</div>

                    <div v-for="round in rings.rounds" :key="round.round" class="told-row" :class="{ unreached: round.state === 'none' }">
                        <div class="told-who">
                            {{ round.label }}
                            <span v-if="round.state === 'pending'" class="told-flag">Due</span>
                            <span v-else-if="round.state === 'skipped'" class="told-flag">Not sent</span>
                            <span v-else-if="round.safety_net" class="told-flag">Safety net</span>
                        </div>
                        <div class="told-reason">{{ round.summary }}</div>
                    </div>

                    <div v-if="rings.answers.length" class="told-row">
                        <div class="told-who">Answers</div>
                        <div v-for="(answer, i) in rings.answers" :key="i" class="told-leg">
                            <span class="told-channel">{{ answer.who }}</span>
                            <span>{{ answer.what }}</span>
                            <span class="told-ms">{{ answer.at }}</span>
                        </div>
                    </div>

                    <div v-if="rings.stopped" class="told-row">
                        <div class="told-who">Ringing stopped · {{ rings.stopped.at }}</div>
                        <div class="told-reason">
                            {{ rings.stopped.why }}. Stop sent to {{ rings.stopped.phones }} phone{{ rings.stopped.phones === 1 ? '' : 's' }}.
                        </div>
                    </div>
                    <div v-else-if="rings.next" class="told-row">
                        <div class="told-who">Next</div>
                        <div class="told-reason">{{ rings.next }}</div>
                    </div>

                    <p class="told-basis">{{ rings.caption }}</p>
                </div>

                <!--
                  WHO WAS TOLD, AND HOW FAST (M10 §1.3).

                  Every one of §1.2's seven destinations, in the work order's own
                  order, with each channel it was handed by. A destination nothing
                  addresses is listed as NOT reached with the reason, because a list
                  that simply left the Director out would be read as "the Director
                  was told".

                  Every figure is a HAND-OFF — the platform letting go of the
                  message to the push queue, the mail queue, the live channel, the
                  text queue — and never arrival on a phone. The labels say "handed
                  off in", and the caption under the list says why, every time.
                -->
                <div v-if="deliveries" class="panel">
                    <div class="panel-head">Who was told, and how fast</div>

                    <p v-if="!deliveries.recorded" class="told-basis">{{ deliveries.empty_reason }}</p>

                    <template v-else>
                        <div
                            v-for="destination in deliveries.destinations"
                            :key="destination.key"
                            class="told-row"
                            :class="{ unreached: !destination.reached }"
                        >
                            <div class="told-who">
                                {{ destination.label }}
                                <span v-if="!destination.reached" class="told-flag failed">Not reached</span>
                            </div>

                            <div v-for="(leg, i) in destination.legs" :key="i" class="told-leg" :title="leg.note ?? undefined">
                                <span class="told-channel">{{ leg.channel_label }}</span>
                                <span>{{ leg.recipients_label }}</span>
                                <span class="told-outcome" :class="{ off: !leg.reached, failed: leg.outcome === 'failed' }">{{ leg.outcome_label }}</span>
                                <span class="told-ms">{{ leg.elapsed_label }}</span>
                            </div>

                            <div v-if="destination.reason" class="told-reason">{{ destination.reason }}</div>

                            <!-- The ring's rounds to this destination, under it: never counted as its reach (G13). -->
                            <div v-for="(leg, i) in destination.rings ?? []" :key="'ring-' + i" class="told-leg" :title="leg.note ?? undefined">
                                <span class="told-channel">{{ leg.channel_label }}</span>
                                <span>{{ leg.recipients_label }}</span>
                                <span class="told-outcome" :class="{ off: !leg.reached, failed: leg.outcome === 'failed' }">{{ leg.outcome_label }}</span>
                                <span class="told-ms">{{ leg.round_label }}</span>
                            </div>
                        </div>

                        <p class="told-basis">
                            {{ deliveries.reached }} of {{ deliveries.of }} destinations reached.
                            <template v-if="deliveries.fanout">{{ deliveries.fanout }}</template>
                            {{ deliveries.caption }}
                        </p>
                    </template>
                </div>

                <div class="panel">
                    <div class="panel-head">Live channel</div>
                    <div class="chat-thread">
                        <div>
                            <!--
                              A system notice, not a conversation. There is no
                              message model anywhere in this system, so drawing
                              a thread of plausible-looking traffic here would
                              be inventing a record of a response.
                            -->
                            <!--
                              This read "Messaging opens when the Guard App
                              ships", which had stopped being true twice over:
                              the app shipped, and the message model it said did
                              not exist was already in the schema. The handset
                              has served `direction = broadcast OR guard_id = me`
                              since it was built; only the write was missing.
                            -->
                            <div class="chat-bubble dispatcher">
                                <template v-if="addressee">
                                    This reaches {{ addressee.name }} on their handset and is kept on the
                                    record. They are at {{ addressee.post }}.
                                </template>
                                <template v-else>
                                    No officer is on duty at this estate, so there is no handset to send to.
                                    Reach whoever is posted here by radio.
                                </template>
                            </div>
                            <div class="chat-meta">Dispatch console · goes to one officer, never a broadcast</div>
                        </div>
                    </div>
                    <!-- The board's own inline style, verbatim: its stylesheet
                         defines no class for this box. -->
                    <!-- The board's own inline style, verbatim, now wrapping a
                         real form rather than a disabled placeholder. -->
                    <form
                        class="field-box"
                        style="margin-top:12px;height:38px;border-radius:10px;background:var(--navy-100);display:flex;align-items:center;padding:0 13px;gap:8px;"
                        @submit.prevent="sendMessage"
                    >
                        <input
                            v-model="message.body"
                            type="text"
                            :disabled="!actions.can_message || !addressee || message.processing"
                            :title="actions.message_title ?? undefined"
                            :maxlength="500"
                            :placeholder="addressee ? `Message ${addressee.name}…` : 'Nobody on duty to message'"
                            style="font-size:12px;color:var(--navy-900);"
                        />
                        <button
                            type="submit"
                            class="chat-send"
                            :disabled="!actions.can_message || !addressee || message.processing || message.body.trim() === ''"
                        >
                            {{ message.processing ? 'Sending…' : 'Send' }}
                        </button>
                    </form>

                    <div v-if="message.errors.body" class="chat-error">{{ message.errors.body }}</div>
                </div>

                <!--
                  THE RESIDENT'S OWN CHANNEL (M50 §4.2 #2): what they wrote from their panic screen, and the control
                  room's replies — a reply is on their screen at once and pushed to their phone. Only on a resident's
                  panic; the page refreshes itself on every message (the live step `alert.message`).
                -->
                <div v-if="residentThread" class="panel">
                    <div class="panel-head">Messages with the resident</div>
                    <div class="chat-thread" aria-live="polite">
                        <div v-if="residentThread.messages.length === 0">
                            <div class="chat-bubble dispatcher">
                                Nothing written yet. The resident can write from their panic screen, and what you send
                                here appears there at once.
                            </div>
                        </div>
                        <div v-for="m in residentThread.messages" :key="m.id">
                            <div class="chat-bubble" :class="m.from === 'resident' ? 'dispatcher' : 'guard'">{{ m.body }}</div>
                            <div class="chat-meta" :style="m.from === 'resident' ? null : 'text-align:right;'">
                                {{ m.from === 'resident' ? (m.name ?? 'Resident') : `Dispatch${m.name ? ' · ' + m.name : ''}` }}
                                · {{ agoFrom(m.at, now) }}
                                <template v-if="m.from !== 'resident'"> · {{ m.seen_at ? 'Delivered' : 'Sent' }}</template>
                            </div>
                        </div>
                    </div>
                    <form
                        class="field-box"
                        style="margin-top:12px;height:38px;border-radius:10px;background:var(--navy-100);display:flex;align-items:center;padding:0 13px;gap:8px;"
                        @submit.prevent="sendReply"
                    >
                        <input
                            v-model="reply.body"
                            type="text"
                            :disabled="!residentThread.canReply || residentThread.closed || reply.processing"
                            :maxlength="residentThread.max"
                            :placeholder="residentThread.closed ? 'This alert is closed' : 'Reply to the resident…'"
                            aria-label="Reply to the resident"
                            style="font-size:12px;color:var(--navy-900);"
                        />
                        <button
                            type="submit"
                            class="chat-send"
                            :disabled="!residentThread.canReply || residentThread.closed || reply.processing || reply.body.trim() === ''"
                        >
                            {{ reply.processing ? 'Sending…' : 'Send' }}
                        </button>
                    </form>
                    <div v-if="reply.errors.body" class="chat-error">{{ reply.errors.body }}</div>
                </div>

                <!--
                  THE RESIDENT'S LIVE AUDIO (M50 §4.2 #1): a few seconds at a time from their phone while their switch
                  is on. Plays only when a person presses Listen.
                -->
                <div v-if="residentAudio" class="panel">
                    <div class="panel-head">Live audio from the resident</div>
                    <div class="chat-thread">
                        <div>
                            <div class="chat-bubble dispatcher" role="status">{{ audioLine }}</div>
                            <div v-if="audioListening" class="chat-meta">{{ audioPlaying ? 'Playing…' : 'Listening — waiting for the next piece' }}</div>
                        </div>
                    </div>
                    <div style="margin-top:12px;display:flex;gap:8px;">
                        <button
                            v-if="!audioListening"
                            type="button"
                            class="chat-send"
                            :disabled="!audioSummary || audioSummary.pieces === 0"
                            @click="listen(false)"
                        >
                            Listen
                        </button>
                        <button
                            v-if="!audioListening"
                            type="button"
                            class="chat-send"
                            :disabled="!audioSummary || audioSummary.pieces === 0"
                            @click="listen(true)"
                        >
                            From the start
                        </button>
                        <button v-else type="button" class="chat-send" @click="stopListening">Stop listening</button>
                    </div>
                    <div v-if="audioProblem" class="chat-error">{{ audioProblem }}</div>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-head">Resident</div>
                    <div class="info-row">
                        <div class="ir-l">Name</div>
                        <div class="ir-r">{{ alert.who }}</div>
                    </div>
                    <div class="info-row">
                        <div class="ir-l">Unit</div>
                        <div class="ir-r">{{ alert.unit }}</div>
                    </div>
                    <!-- Medical notes are not held centrally at all: said, never filled in. -->
                    <div class="info-row">
                        <div class="ir-l">Medical notes</div>
                        <div class="ir-r">{{ alert.medical_notes }}</div>
                    </div>
                    <!-- The household and its contacts are in "People to call" below (M18C §4.2). -->
                    <div v-if="!alert.people" class="info-row">
                        <div class="ir-l">Household</div>
                        <div class="ir-r">{{ alert.household }}</div>
                    </div>
                    <!--
                      What the household's own nominated contacts were sent (M10
                      §1.2 item 7): a count from the central ledger, never who they
                      are. Absent on an officer's duress, which texts no household.
                    -->
                    <div v-if="alert.household_contacts" class="info-row">
                        <div class="ir-l">Household contacts</div>
                        <div class="ir-r">{{ alert.household_contacts }}</div>
                    </div>
                    <!-- And what they were emailed (M12 §2.5), from the central ledger: a count, never an address. -->
                    <div v-if="alert.household_emails" class="info-row">
                        <div class="ir-l">Household contacts by email</div>
                        <div class="ir-r">{{ alert.household_emails }}</div>
                    </div>
                    <!--
                      What the site's two Heads of Security were texted (M10 §1.2
                      items 4 and 5): a count from the same ledger, never a phone
                      number. On every alert, an officer's duress included.
                    -->
                    <div v-if="alert.heads_of_security_texts" class="info-row">
                        <div class="ir-l">Heads of Security by text</div>
                        <div class="ir-r">{{ alert.heads_of_security_texts }}</div>
                    </div>
                </div>

                <!--
                  EVERY NUMBER, READY TO DIAL (M18C §4.2): the resident, every household member and every emergency
                  contact, with name, relationship and number. A dispatcher never goes looking for a number mid-emergency.
                -->
                <div v-if="alert.people" class="panel" aria-labelledby="people-head">
                    <div id="people-head" class="panel-head">People to call</div>
                    <template v-for="group in peopleGroups" :key="group.title">
                        <div class="pc-group">{{ group.title }}</div>
                        <div v-if="group.people.length === 0" class="pc-none">{{ group.none }}</div>
                        <div v-for="(p, i) in group.people" :key="group.title + i" class="pc-row">
                            <div class="pc-who">
                                <div class="pc-name">{{ p.name }}</div>
                                <div class="pc-rel">
                                    {{ p.relationship }}{{ p.alerts_to_primary ? ' · no email — alerts go to the primary resident' : '' }}
                                </div>
                            </div>
                            <a v-if="p.tel" class="pc-call" :href="`tel:${p.tel}`" :aria-label="`Call ${p.name} on ${p.phone}`">
                                {{ p.phone }}
                            </a>
                            <span v-else class="pc-nocall">{{ p.phone }}</span>
                        </div>
                    </template>
                </div>

                <div class="panel">
                    <div class="panel-head">Actions</div>
                    <!--
                      M96: WHEN EVERY ACTION IS DEAD, THE RESULT INSTEAD OF THE BUTTONS. A closed alert takes no step, and a
                      role that may only watch can never take one: each says so in words, and the timeline beside this
                      panel holds every step and who took it.
                    -->
                    <p v-if="actionsResult" class="action-result">{{ actionsResult }}</p>
                    <div v-else class="action-btns">
                        <!-- A step already recorded is a badge with its time, never a dead button (M96 level 3). -->
                        <span v-if="actions.acknowledged" class="action-done">{{ doneBadge('Acknowledged', actions.acknowledged_time) }}</span>
                        <!-- The workflow's own order, which button is next (Hassam, Batch 1, 12 Oct), never a new restriction. -->
                        <span v-else class="action-step">
                            <span v-if="nextStep === 'acknowledge'" class="action-next">Next step</span>
                            <button
                                type="button"
                                class="action-btn"
                                :class="stepClass('acknowledge')"
                                :disabled="!actions.can_acknowledge"
                                :title="actions.acknowledge_title"
                                @click="acknowledge"
                            >
                                <BoardIcon name="check" :stroke="3" />
                                <span>{{ actions.acknowledge_label }}</span>
                            </button>
                        </span>

                        <!--
                          A REAL DISPATCH NOW (M10 §1.3). This button re-addressed
                          the message box to the next officer and recorded nothing,
                          under a label that said "Dispatch". It now sends one
                          officer: the row records who and when, the officer's
                          handset is pushed "You are dispatched", and the resident
                          is told "An officer has been dispatched" — the first
                          moment "help is on the way" is true.

                          The pick list is the officers this panic reached, in name
                          order — none of them nearer than another as far as this
                          platform knows (M10 §0). Once somebody is sent the button
                          says who and stops: one alert, one dispatched officer.
                        -->
                        <!-- Somebody sent, or taking it from their own handset: a badge saying who and when (level 3). -->
                        <span v-if="actions.dispatched || actions.responding" class="action-done">
                            {{ actions.dispatched ? doneBadge('Dispatched', actions.officer, actions.dispatched_time) : doneBadge('Responding', actions.officer) }}
                        </span>
                        <template v-else>
                            <select
                                v-if="actions.can_dispatch"
                                v-model="dispatchTo"
                                class="dispatch-pick"
                                aria-label="Officer to dispatch"
                            >
                                <option v-for="officer in actions.dispatch_candidates" :key="officer.guard_id" :value="officer.guard_id">
                                    {{ officer.name }} · {{ officer.post }}
                                </option>
                            </select>
                            <span class="action-step">
                                <span v-if="nextStep === 'dispatch'" class="action-next">Next step</span>
                                <button
                                    type="button"
                                    class="action-btn"
                                    :class="stepClass('dispatch')"
                                    :disabled="!actions.can_dispatch || !chosen"
                                    :title="actions.dispatch_title ?? (chosen ? `Send ${chosen.name} to this alert. Their handset is pushed it and the resident is told an officer has been dispatched.` : undefined)"
                                    @click="dispatchOfficer"
                                >
                                    <BoardIcon name="guards" :stroke="1.7" />
                                    <span>{{ actions.can_dispatch && chosen ? `Dispatch ${chosen.name}` : actions.dispatch_label }}</span>
                                </button>
                                <!-- Nobody to send: greyed, and why, on the screen (M96 level 2). -->
                                <span v-if="!actions.can_dispatch && actions.dispatch_title" class="action-why">{{ actions.dispatch_title }}</span>
                            </span>
                        </template>

                        <!--
                          A RADIO CALL, RECORDED ON THE OFFICER'S BEHALF. Kept as
                          recorded by this dispatcher — never as though the
                          officer's handset had said it — and forward only: each
                          button says what is already recorded and stops.
                        -->
                        <!-- On the way: a badge once recorded; nothing once the officer is already there (level 3). -->
                        <span v-if="actions.en_route" class="action-done">{{ doneBadge('On the way', actions.en_route_time) }}</span>
                        <span v-else-if="!actions.on_scene" class="action-step">
                            <span v-if="nextStep === 'en_route'" class="action-next">Next step</span>
                            <button
                                type="button"
                                class="action-btn"
                                :class="stepClass('en_route')"
                                :disabled="!actions.can_en_route"
                                :title="actions.en_route_title"
                                @click="markEnRoute"
                            >
                                <BoardIcon name="clock" :stroke="1.7" />
                                <span>{{ actions.en_route_label }}</span>
                            </button>
                            <span v-if="!actions.can_en_route && actions.en_route_title" class="action-why">{{ actions.en_route_title }}</span>
                        </span>

                        <span v-if="actions.on_scene" class="action-done">{{ doneBadge('On scene', actions.on_scene_time) }}</span>
                        <span v-else class="action-step">
                            <span v-if="nextStep === 'on_scene'" class="action-next">Next step</span>
                            <button
                                type="button"
                                class="action-btn"
                                :class="stepClass('on_scene')"
                                :disabled="!actions.can_on_scene"
                                :title="actions.on_scene_title"
                                @click="markOnScene"
                            >
                                <BoardIcon name="map" :stroke="1.7" />
                                <span>{{ actions.on_scene_label }}</span>
                            </button>
                            <span v-if="!actions.can_on_scene && actions.on_scene_title" class="action-why">{{ actions.on_scene_title }}</span>
                        </span>

                        <!-- Nothing in this system records an escalation yet: greyed, and why, on the screen (M96 level 2). -->
                        <span class="action-step">
                            <button
                                type="button"
                                class="action-btn danger"
                                disabled
                                :title="ESCALATION_WHY"
                            >
                                <BoardIcon name="alert-strong" />
                                <span>Escalate to police / JCF</span>
                            </button>
                            <span class="action-why">{{ ESCALATION_WHY }}</span>
                        </span>

                        <span class="action-step">
                            <span v-if="nextStep === 'resolve'" class="action-next">Next step</span>
                            <button
                                type="button"
                                class="action-btn"
                                :class="stepClass('resolve')"
                                :disabled="!actions.can_resolve"
                                :title="actions.resolve_title"
                                @click="resolve"
                            >
                                <BoardIcon name="check" :stroke="2" />
                                <span>Resolve &amp; classify</span>
                            </button>
                        </span>
                    </div>

                </div>
            </div>
        </div>

        <ConsoleDialog
            :open="resolveOpen"
            title="Resolve and classify this alert"
            tone="danger"
            confirm-label="Resolve alert"
            busy-label="Resolving…"
            :busy="resolveForm.processing"
            :confirm-blocked-reason="resolveBlocked"
            @confirm="submitResolve"
            @cancel="cancelResolve"
        >
            <p class="rs-lead">
                {{ alert.headline }} · {{ alert.who }} · {{ alert.unit }}. This closes the alert for everyone,
                and it cannot be reopened from this console.
            </p>

            <fieldset class="rs-set">
                <legend class="rs-legend">How was it classified?</legend>

                <div v-for="group in classificationGroups" :key="group.name" class="rs-group">
                    <div class="rs-group-name">{{ group.name }}</div>
                    <label
                        v-for="option in group.options"
                        :key="option.value"
                        class="rs-option"
                        :class="{ on: resolveForm.classification === option.value }"
                    >
                        <input v-model="resolveForm.classification" type="radio" name="resolve-classification" :value="option.value" />
                        <span>{{ option.label }}</span>
                    </label>
                </div>

                <div v-if="resolveForm.errors.classification" class="rs-field-error" role="alert">{{ resolveForm.errors.classification }}</div>
            </fieldset>

            <label class="rs-note-label" for="resolve-note">
                What happened
                <span class="rs-note-hint">— a few words for the record</span>
            </label>
            <textarea
                id="resolve-note"
                v-model="resolveForm.note"
                class="rs-note"
                rows="3"
                :maxlength="noteMax"
                placeholder="What happened, in a line or two. It goes on the alert's timeline and in the audit log."
            ></textarea>
            <div class="rs-note-foot">
                <span v-if="resolveForm.errors.note" class="rs-field-error" role="alert">{{ resolveForm.errors.note }}</span>
                <span class="rs-count" :class="{ over: resolveForm.note.length >= noteMax }">{{ resolveForm.note.length }} / {{ noteMax }}</span>
            </div>
        </ConsoleDialog>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule below REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the actions as <div>s and the message box as a <span> of
 * placeholder text. They are a <button> and an <input> here so they can be
 * operated — or visibly refuse to be — and the browser's own chrome for those
 * elements would otherwise show through and change the pixels the board
 * specifies. Each variant's background, border and colour still come from the
 * board's own rules, which are more specific than these and win.
 *
 * The one exception is the "On duty now" panel below, which is authored: the
 * board draws no such list. It borrows the panel and row spacing the boards
 * already use either side of it.
 */
.cand-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--navy-100);
}

.cand-row:last-of-type {
    border-bottom: 0;
}

/* The officer the message box is currently addressed to — a statement about
 * this screen's state, not about who is nearest. */
.cand-row.top .cand-name {
    font-weight: 700;
}

.cand-name {
    font-size: 14px;
    color: var(--navy-900);
}

.cand-post {
    font-size: 12px;
    color: var(--slate-500);
}

/* Addressing the message box to this officer. */
.cand-pick {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    /* --navy-600 is the board's own action blue. `--blue-600` was never a token, so
     * its hex fallback was what rendered — a literal the gate rightly refuses. */
    color: var(--navy-600);
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
}

.cand-pick:disabled {
    color: var(--slate-500);
    cursor: not-allowed;
}

.cand-basis {
    margin: 12px 0 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

/*
 * "Who was told, and how fast" (M10 §1.3). Authored: no board draws it. It borrows
 * the row spacing and the muted caption the "On duty now" panel above already uses,
 * and the only colour it adds is the red the console already means "not done" by.
 */
.told-row {
    padding: 10px 0;
    border-bottom: 1px solid var(--navy-100);
}

.told-row:last-of-type {
    border-bottom: 0;
}

.told-who {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/*
 * RED IN THREE WEIGHTS (Hassam, 12 Oct): solid red is the open panic's alone; a red outline or muted red is Failed,
 * Rejected and destructive; amber is everything else. "Due", "Not sent" and "Safety net" are warnings, in amber; a
 * destination nobody reached, and a leg that failed, are Failed, in muted red text.
 */
.told-flag {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--amber-700);
}

.told-flag.failed {
    color: var(--red-700);
}

.told-leg {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    margin-top: 4px;
    font-size: 12.5px;
    color: var(--navy-700);
}

.told-channel {
    min-width: 92px;
    font-weight: 700;
}

/* Nobody to tell, or not sent: a warning. Failed: muted red. */
.told-outcome.off {
    color: var(--amber-700);
}

.told-outcome.off.failed {
    color: var(--red-700);
}

.told-ms {
    color: var(--slate-500);
    font-variant-numeric: tabular-nums;
}

.told-reason {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

.told-basis {
    margin: 12px 0 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--slate-500);
}

/* A drill, said in a sentence under the hero: an urgent notice, so amber (red is an open panic's alone). */
.drill-note {
    margin: 12px 0 0;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px dashed var(--amber-700);
    font-size: 13px;
    font-weight: 700;
    color: var(--amber-700);
}

/*
 * The send button and its error line. Authored, because the board drew the
 * message box as a disabled placeholder and therefore drew no control to
 * operate it.
 */
.chat-send {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-600);
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
}

.chat-send:disabled {
    color: var(--slate-500);
    cursor: not-allowed;
}

.chat-error {
    margin-top: 6px;
    font-size: 12px;
    /*
     * Amber, not red (Hassam, 11 Oct): red is reserved for panic and life safety, and on this screen the panic is the
     * red. A refused message or step is an ordinary error, so it reads in the warning colour.
     */
    color: var(--amber-700);
}

/* What the server answered when it accepted an action (B115) — the error line's calm twin. */
.chat-note {
    margin-top: 6px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--navy-700);
}

/*
 * The officer to dispatch. Authored: the board's "Dispatch another guard" was a
 * <div> naming nobody, and a real dispatch needs a real choice. Sized and
 * bordered like the outlined action button beneath it.
 */
.dispatch-pick {
    height: 42px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-200);
    background: var(--white);
    color: var(--navy-900);
    font-family: inherit;
    font-size: 12.5px;
    padding: 0 10px;
}

.action-btn {
    -webkit-appearance: none;
    appearance: none;
    font-family: inherit;
    cursor: pointer;
}

/*
 * Only the filled variants, on purpose.
 *
 * `.action-btn.outline` carries a 1.5px border the board draws, and a blanket
 * `.action-btn { border: 0 }` here would match its specificity exactly — which
 * of the two won would then depend on the order the bundler emitted them in,
 * and the outlined buttons would lose their border on some builds. Naming the
 * two variants that need the default border removed leaves the third alone.
 */
.action-btn.primary,
.action-btn.danger {
    border: 0;
}

/* The message box: an input dressed back down to the span it replaces.
 * `flex: 1` gives it the row's width so the placeholder cannot clip, where a
 * default input would size itself to about twenty characters. */
.panel input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font-family: inherit;
    padding: 0;
}

.panel input::placeholder {
    color: inherit;
    opacity: 1;
}

/* Deliberately inert, and visibly so: no silent click. Changes the cursor and
 * nothing that occupies space. */
.action-btn[disabled],
.panel input[disabled] {
    cursor: not-allowed;
}

/*
 * M96 level 2 looks it (seen on screen, 11 Oct): a greyed action was drawn exactly like a live one, its reason under it
 * the only sign. And Escalate, which nothing here records yet, was solid saturated red, which is the open panic's
 * alone: it reads as a red outline, greyed, its reason beneath it.
 */
.action-btn[disabled] {
    opacity: 0.55;
}

.action-btn.danger[disabled] {
    background: var(--white);
    color: var(--red-700);
    box-shadow: inset 0 0 0 1.5px var(--red-700);
}

/* M96 level 3: a step already recorded, as a badge with its time, where a dead button stood. */
.action-done {
    display: inline-flex;
    align-items: center;
    min-height: 42px;
    padding: 0 12px;
    border-radius: 10px;
    background: var(--navy-100);
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
}

/* M96 level 2: a greyed action with its reason under it, on the screen, not only on hover. */
.action-step {
    display: inline-flex;
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
}

.action-why {
    font-size: 11px;
    line-height: 1.45;
    color: var(--slate-600);
}

/*
 * WHICH BUTTON IS SUPPOSED TO BE CLICKED FIRST (Hassam, Batch 1, 12 Oct), said in words, not only by which one is
 * solid: a cue above the next step in the workflow's own order, never a restriction on any other.
 */
.action-next {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--navy-600);
}

/* M96: the result instead of the buttons, when no action is left to take. */
.action-result {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: var(--navy-700);
}

/*
 * The resolve dialog's form (ConsoleDialog draws the frame and the buttons).
 * Authored: no board draws it. Tokens only.
 */
.rs-lead {
    margin: 0;
}

.rs-set {
    margin: 16px 0 0;
    padding: 0;
    border: 0;
}

.rs-legend {
    padding: 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.rs-group {
    margin-top: 10px;
}

.rs-group-name {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--slate-500);
    margin-bottom: 4px;
}

.rs-option {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 38px;
    padding: 0 12px;
    margin-top: 4px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-100);
    font-size: 13.5px;
    color: var(--navy-900);
    cursor: pointer;
}

.rs-option.on {
    border-color: var(--navy-600);
    background: var(--navy-100);
    font-weight: 700;
}

.rs-option input {
    flex: 0 0 auto;
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--navy-600);
}

.rs-note-label {
    display: block;
    margin-top: 16px;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy-900);
}

.rs-note-hint {
    font-weight: 400;
    color: var(--text-body);
}

/* A step not reached yet (M18B): told by the placeholder colour, never by fading the words — 7.27 : 1 on white. */
.tl-pending .tl1,
.tl-pending .tl2 {
    color: var(--text-placeholder);
}

/* People to call (M18C §4.2): a name and relationship, and the number as the thing to press. */
.pc-group {
    padding: 10px 16px 4px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-primary);
}
.pc-none {
    padding: 2px 16px 8px;
    font-size: 13px;
    color: var(--text-body);
}
.pc-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 16px;
    border-top: 1px solid var(--navy-100);
}
.pc-who {
    min-width: 0;
}
.pc-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}
.pc-rel {
    font-size: 12.5px;
    color: var(--text-body);
}
.pc-call {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    min-height: 36px;
    padding: 0 12px;
    border-radius: 10px;
    background: var(--navy-600);
    color: var(--text-on-dark);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}
.pc-call:hover,
.pc-call:focus-visible {
    background: var(--navy-700);
}
.pc-nocall {
    flex-shrink: 0;
    font-size: 13px;
    color: var(--text-body);
}

.rs-note {
    display: block;
    width: 100%;
    box-sizing: border-box;
    margin-top: 6px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1.5px solid var(--navy-200);
    background: var(--white);
    font-family: inherit;
    font-size: 13.5px;
    line-height: 1.45;
    color: var(--navy-900);
    resize: vertical;
}

.rs-note:focus {
    outline: 2px solid var(--amber-500);
    outline-offset: 1px;
}

.rs-note-foot {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 4px;
    font-size: 12px;
}

.rs-count {
    margin-left: auto;
    color: var(--slate-500);
    font-variant-numeric: tabular-nums;
}

/* A note at its limit and a field's error are ordinary errors: amber (Hassam, 12 Oct). */
.rs-count.over {
    color: var(--amber-700);
    font-weight: 700;
}

.rs-field-error {
    display: block;
    margin-top: 6px;
    font-size: 12.5px;
    color: var(--amber-700);
}
</style>

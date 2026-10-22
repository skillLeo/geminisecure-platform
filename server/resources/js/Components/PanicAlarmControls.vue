<script setup>
import { computed } from 'vue'

/**
 * THE SIREN'S CONTROLS, ON EVERY DISPATCH SCREEN THAT SOUNDS IT (B107).
 *
 * `useLiveDispatch` sounds the panic siren on the queue, the live map, the coverage and
 * alertness boards and an alert's own screen — on purpose, so a dispatcher on any of them hears
 * a panic as loudly as one staring at the queue. The controls lived on the queue alone. On the
 * other four a square-wave klaxon repeated every three seconds with nothing on the page to
 * quieten it, and a fresh tab whose browser refused sound looked armed. These are the queue's
 * own chips, lifted out so that every screen with the siren draws the same ones:
 *
 *   - one chip says the siren's state and acts on it: "Silence alarm" while it sounds (quiet for
 *     the alerts sounding now; a new one still sounds), "Enable alarm sound" while the browser
 *     is refusing audio (the click is the gesture it wants), "Alarm off" once muted (turn it
 *     back on), "Alarm on" otherwise;
 *   - "Mute" turns it off for good on this browser, and says so rather than looking like the
 *     same button.
 *
 * WHETHER THE SIREN CAN BE HEARD IS ON SCREEN, which is the other half of the point: a browser
 * will not play audio until the page has been interacted with, and a silent tab that looks armed
 * is worse than no alarm at all — the dispatcher believes a panic will announce itself.
 */
const props = defineProps({
    /** `usePanicAlarm()`, as `useLiveDispatch` hands it out. */
    alarm: { type: Object, required: true },
})

const label = computed(() => {
    if (props.alarm.muted.value) {
        return 'Alarm off'
    }

    if (props.alarm.blocked.value) {
        return 'Enable alarm sound'
    }

    return props.alarm.sounding.value ? 'Silence alarm' : 'Alarm on'
})

const title = computed(() => {
    if (props.alarm.muted.value) {
        return 'The panic siren is off on this browser. Click to turn it back on.'
    }

    if (props.alarm.blocked.value) {
        return 'This browser will not play sound until you interact with the page. Click once to enable the panic siren.'
    }

    return 'A panic or duress alert sounds a siren on this screen until it is silenced or acknowledged. Click to silence the one sounding now.'
})

const act = () => {
    if (props.alarm.muted.value) {
        props.alarm.setMuted(false)
    } else if (props.alarm.blocked.value) {
        props.alarm.enable()
    } else {
        props.alarm.silence()
    }
}
</script>

<template>
    <!--
      The siren's own state, beside the channel's, because they answer the same
      question: will this screen tell me when something happens? One click
      silences what is sounding; the small toggle beside it turns the siren off
      for good on this browser.
    -->
    <button
        type="button"
        class="alarm-chip"
        :class="{ sounding: alarm.sounding.value, blocked: alarm.blocked.value, off: alarm.muted.value }"
        :title="title"
        @click="act"
    >
        {{ label }}
    </button>

    <button
        v-if="!alarm.muted.value"
        type="button"
        class="alarm-chip off"
        title="Turn the panic siren off on this browser. It stays off until you turn it back on."
        @click="alarm.setMuted(true)"
    >
        Mute
    </button>
</template>

<style scoped>
/*
 * The alarm chips. The board draws no control here, so these are authored — the
 * siren did not exist when it was drawn. They borrow the badge sizing already
 * beside them so the byline row stays one line of chrome rather than becoming a
 * toolbar.
 */
.alarm-chip {
    -webkit-appearance: none;
    appearance: none;
    border: 1px solid var(--navy-100);
    background: var(--white);
    color: var(--navy-900);
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
}

/* Sounding right now — the one chip on the screen that should pull the eye. */
.alarm-chip.sounding {
    /* --red-700, the board's danger red: `--red-600` was never a token, so its hex
     * fallback was what rendered. White on it is 6.5:1. */
    background: var(--red-700);
    border-color: var(--red-700);
    color: var(--white);
    animation: alarm-pulse 0.7s steps(1, end) infinite;
}

/* The browser is refusing to play it, which is a warning and not a state. */
.alarm-chip.blocked {
    /* Near-black text on the amber: `--amber-900` was never a token either. */
    background: var(--amber-100);
    border-color: var(--amber-500);
    color: var(--navy-900);
}

.alarm-chip.off {
    color: var(--slate-500);
}

@keyframes alarm-pulse {
    50% {
        opacity: 0.45;
    }
}

/* A dispatcher who has asked for no motion gets the colour and not the flash. */
@media (prefers-reduced-motion: reduce) {
    .alarm-chip.sounding {
        animation: none;
    }
}
</style>

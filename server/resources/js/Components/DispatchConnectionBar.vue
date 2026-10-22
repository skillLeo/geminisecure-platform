<script setup>
import { computed } from 'vue'

/**
 * The dispatch shift bar's connection state (13 C2).
 *
 * "Show the connection state in the shift bar so a dispatcher knows which they
 * are on." Every dispatch screen carries it beside the source badge, and it
 * says exactly one of two things:
 *
 *   Live channel           pushes arrive the moment they happen; nothing polls
 *   Fallback · every 3s    the channel is down, this screen is polling, and for
 *                          how long — amber, because it is a degradation
 *
 * Pressing it refreshes now. It is a real button because it is the one control
 * that answers "is this still working", and that answer has to be actionable.
 */
const props = defineProps({
    /** `useLiveDispatch().connection` */
    connection: { type: Object, required: true },
})

const emit = defineEmits(['refresh'])

const live = computed(() => props.connection.mode === 'live')

const duration = (seconds) => (seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)} min`)

const label = computed(() =>
    live.value
        ? 'Live channel'
        : `Fallback · polling every ${props.connection.intervalMs / 1000}s`,
)

const title = computed(() => {
    if (props.connection.estates === 0) {
        return `No estate channel to listen on for this view, so it refreshes every ${props.connection.intervalMs / 1000} seconds. Press to refresh now.`
    }

    return live.value
        ? 'Connected to the live alert channel. Alerts and clock-ins arrive the moment they happen, and nothing is polling. If the channel drops, this switches to polling within forty seconds. Press to refresh now.'
        : `The live alert channel has been down for ${duration(props.connection.downForSeconds)}. This screen is refreshing every ${props.connection.intervalMs / 1000} seconds instead, so an alert can reach you that much later. Press to refresh now.`
})
</script>

<template>
    <button
        type="button"
        class="dispatch-connection"
        :class="{ 'dispatch-connection--fallback': !live }"
        :title="title"
        :aria-label="title"
        @click="emit('refresh')"
    >
        <i aria-hidden="true"></i>
        <span>{{ label }}</span>
        <span v-if="!live && connection.estates > 0 && connection.downForSeconds >= 5" class="dispatch-connection__for">
            · {{ duration(connection.downForSeconds) }}
        </span>
    </button>
</template>

<style scoped>
.dispatch-connection {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: 6px;
    font: inherit;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-600);
    background: var(--navy-100);
    border: 0;
    border-radius: 100px;
    padding: 4px 10px 4px 8px;
    white-space: nowrap;
    cursor: pointer;
}

.dispatch-connection i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--success-600);
    flex: 0 0 auto;
}

/* Amber: a stated degradation, not a failure and not the normal mode. */
.dispatch-connection--fallback {
    color: var(--amber-700);
    background: var(--amber-100);
}

.dispatch-connection--fallback i {
    background: var(--amber-500);
}
</style>

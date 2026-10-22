<script setup>
/**
 * Marks a screen whose data ORIGINATES ON A MOBILE DEVICE.
 *
 * Roughly 40 web screens display data the Guard App and Resident App generate:
 * the live map, the alert queue, the gate feed, patrol monitoring. Those apps
 * are a later phase, so until they exist the simulator drives the same
 * endpoints the real apps will call.
 *
 * The badge exists so the client can tell, at a glance and without asking,
 * which figures are real and which are simulated. Quiet by design: it sits
 * beside the heading in the same slate as body copy, and never competes with
 * the data it annotates.
 *
 * It is NOT a status pill. Semantic colours are reserved for verified, denied
 * and warning states; borrowing one here would dilute the vocabulary that
 * keeps "denied" from ever looking like "verified".
 */
defineProps({
    // 'guard' | 'resident' — which app produces this data
    source: { type: String, required: true },
    // true while the simulator is the producer rather than a real device
    simulated: { type: Boolean, default: false },
})

const label = {
    guard: 'Guard App',
    resident: 'Resident App',
}
</script>

<template>
    <span class="source-badge" :class="{ 'source-badge--simulated': simulated }">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <rect x="7" y="2" width="10" height="20" rx="2.5" stroke="currentColor" stroke-width="1.6" />
            <path d="M11 18.5h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        </svg>
        <span>{{ simulated ? `Simulated ${label[source]} data` : `Live from ${label[source]}` }}</span>
    </span>
</template>

<style scoped>
.source-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--slate-500);
    background: var(--navy-100);
    border-radius: 100px;
    padding: 4px 10px 4px 8px;
    white-space: nowrap;
}

.source-badge svg {
    width: 12px;
    height: 12px;
    display: block;
    flex: 0 0 auto;
}

/*
 * Simulated data reads amber, not green or red. It is neither a success nor a
 * fault - it is a caveat, and amber is the system's warning family.
 */
.source-badge--simulated {
    color: var(--amber-700);
    background: var(--amber-100);
}
</style>

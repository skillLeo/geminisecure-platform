<script setup>
/**
 * Cross-cutting rule 13: every empty state names what happened and offers the
 * next action. A blank panel reading "no data" is a defect, not a state.
 *
 * Two distinct cases, deliberately separated, because they need different
 * copy and a different next action:
 *
 *   first-use  nothing exists yet   -> offer to create the first one
 *   filtered   things exist, none match -> offer to clear the filter
 *
 * Collapsing them produces "No estates found" on a platform with 40 estates
 * and an active search, which tells the reader nothing about why.
 */
defineProps({
    variant: {
        type: String,
        default: 'first-use',
        validator: (v) => ['first-use', 'filtered', 'error', 'denied'].includes(v),
    },
    title: { type: String, required: true },
    body: { type: String, required: true },
    actionLabel: { type: String, default: null },
})

defineEmits(['action'])
</script>

<template>
    <div class="empty-state" :class="`empty-state--${variant}`">
        <div class="empty-icon">
            <svg v-if="variant === 'error'" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                <path d="M12 8v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                <circle cx="12" cy="16" r="1" fill="currentColor" />
            </svg>
            <svg v-else-if="variant === 'denied'" viewBox="0 0 24 24" fill="none">
                <rect x="4" y="10" width="16" height="10" rx="2" stroke="currentColor" stroke-width="1.7" />
                <path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            </svg>
            <svg v-else-if="variant === 'filtered'" viewBox="0 0 24 24" fill="none">
                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.7" />
                <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="none">
                <path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
            </svg>
        </div>

        <div class="empty-title">{{ title }}</div>
        <p class="empty-body">{{ body }}</p>

        <button v-if="actionLabel" type="button" class="btn-primary-sm" @click="$emit('action')">
            <span>{{ actionLabel }}</span>
        </button>
    </div>
</template>

<style scoped>
.empty-state {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 44px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.empty-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
}

.empty-icon svg {
    width: 22px;
    height: 22px;
    color: var(--navy-600);
    display: block;
}

/*
 * A read that failed and a page this role may not open are amber (Hassam's final red rule, 11 Oct 2026: errors and
 * denials are amber; solid red is an open panic's or duress alert's alone). The icon and the words still say which.
 */
.empty-state--error .empty-icon,
.empty-state--denied .empty-icon {
    background: var(--amber-100);
}

.empty-state--error .empty-icon svg,
.empty-state--denied .empty-icon svg {
    color: var(--amber-700);
}

.empty-title {
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--navy-900);
    margin-bottom: 6px;
}

.empty-body {
    font-size: 12.5px;
    color: var(--slate-500);
    line-height: 1.6;
    max-width: 420px;
    margin-bottom: 18px;
}
</style>

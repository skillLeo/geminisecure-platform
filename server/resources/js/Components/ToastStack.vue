<script setup>
import { dismissToast, toasts } from '../lib/toasts.js'

/**
 * The console's one place for messages (M95 §2): top right, above the content, stacked newest first, each with an icon,
 * its words and a close control. What goes in it, and when each one closes, is `lib/toasts.js`'s; this only draws it.
 *
 * DRAWN AS A CARD (Hassam, 12 Oct: "the popup must be amazing and professional everywhere"; on live a success toast had
 * no background at all, its words over the page's buttons, on every page whose board lacks the greens). White, with
 * the kind in three places at once: a coloured edge, an icon in a tinted ring, and a one-word title above the sentence.
 *
 * Colour is never the only signal (M96 §3): each tone has its own icon and title, and the words say what happened. A
 * failure is amber with dark words (Hassam's final red rule, 11 Oct 2026: solid red is an open panic's or duress
 * alert's alone; an error, a warning and a refusal are amber), its edge a shade deeper than a warning's.
 */
const label = {
    success: 'Done',
    error: 'Not done',
    warning: 'Warning',
    info: 'Information',
}
</script>

<template>
    <TransitionGroup tag="div" name="toast" class="toast-stack">
        <div
            v-for="item in toasts"
            :key="item.id"
            class="toast"
            :class="item.tone"
            :role="item.tone === 'error' || item.tone === 'warning' ? 'alert' : 'status'"
            :aria-live="item.tone === 'error' || item.tone === 'warning' ? 'assertive' : 'polite'"
        >
            <span class="toast-icon" aria-hidden="true">
                <svg v-if="item.tone === 'success'" viewBox="0 0 24 24" fill="none">
                    <path d="M7 12.5l3.2 3.2L17 9" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <svg v-else-if="item.tone === 'error'" viewBox="0 0 24 24" fill="none">
                    <path d="M8.5 8.5l7 7M15.5 8.5l-7 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
                </svg>
                <svg v-else-if="item.tone === 'warning'" viewBox="0 0 24 24" fill="none">
                    <path d="M12 7.5v6M12 16.8h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" />
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="none">
                    <path d="M12 11v5.5M12 7.6h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" />
                </svg>
            </span>
            <div class="toast-body">
                <div class="toast-title">{{ label[item.tone] ?? label.info }}</div>
                <div class="toast-text">{{ item.text }}</div>
            </div>
            <button type="button" class="toast-close" aria-label="Close this message" title="Close this message" @click="dismissToast(item.id)">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
    </TransitionGroup>
</template>

<style scoped>
/*
 * Above the page and its dialogs, never in the page's flow: it pushes nothing down. Below the red emergency bar while it
 * shows (`--panic-bar-h`, published by PanicOverlay) AND below the console's 64 px top bar, so it never sits over Open
 * Security and Hide, nor over the page's own buttons top right (Export, New charge, New schedule; Hassam, 12 Oct).
 */
.toast-stack {
    position: fixed;
    top: calc(72px + var(--panic-bar-h, 0px));
    right: 20px;
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: min(400px, calc(100vw - 40px));
    pointer-events: none;
}

/*
 * The greens are defined by some board sheets only, not by tokens.css, so every tone names its colours with the
 * board's own values standing in where a page lacks them: a toast looks the same on every page.
 */
.toast {
    --toast-edge: var(--navy-600, #1b5fa8);
    --toast-ring: var(--navy-100, #e7f0fb);
    --toast-ink: var(--navy-600, #1b5fa8);

    pointer-events: auto;
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 12px 14px 16px;
    overflow: hidden;
    border: 1px solid var(--navy-100, #e7f0fb);
    border-radius: 14px;
    background: var(--white, #fff);
    box-shadow:
        0 18px 40px rgba(11, 31, 51, 0.16),
        0 2px 6px rgba(11, 31, 51, 0.06);
    color: var(--navy-900, #0b1f33);
}

/* The coloured edge: the kind at a glance, before a word is read. */
.toast::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 4px;
    background: var(--toast-edge);
}

.toast.success {
    --toast-edge: var(--green-700, #15803d);
    --toast-ring: var(--green-100, #dcfce7);
    --toast-ink: var(--green-700, #15803d);
}

.toast.error {
    --toast-edge: var(--amber-700, #b87908);
    --toast-ring: var(--amber-100, #fff3dc);
    --toast-ink: var(--amber-700, #b87908);
}

.toast.warning {
    --toast-edge: var(--amber-500, #ffb627);
    --toast-ring: var(--amber-100, #fff3dc);
    --toast-ink: var(--amber-700, #b87908);
}

.toast-icon {
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--toast-ring);
    color: var(--toast-ink);
    display: flex;
    align-items: center;
    justify-content: center;
}

.toast-icon svg {
    width: 18px;
    height: 18px;
    display: block;
}

.toast-body {
    flex: 1 1 auto;
    min-width: 0;
    padding-top: 1px;
}

.toast-title {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.3;
    color: var(--navy-900, #0b1f33);
}

.toast-text {
    margin-top: 2px;
    overflow-wrap: anywhere;
    font-size: 12.5px;
    font-weight: 500;
    line-height: 1.5;
    color: var(--slate-600, #475569);
}

.toast-close {
    -webkit-appearance: none;
    appearance: none;
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    margin: -2px -2px 0 0;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--slate-500, #64748b);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.toast-close svg {
    width: 15px;
    height: 15px;
    display: block;
}

.toast-close:hover {
    background: var(--navy-100, #e7f0fb);
    color: var(--navy-900, #0b1f33);
}

.toast-close:focus-visible {
    outline: 2px solid var(--amber-500, #ffb627);
    outline-offset: 1px;
}

/* In from the right, out to the right; still for a reader who asks for less motion. */
.toast-enter-active,
.toast-leave-active {
    transition:
        transform 0.22s ease,
        opacity 0.22s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateX(24px);
}

.toast-move {
    transition: transform 0.22s ease;
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active,
    .toast-move {
        transition: none;
    }
}

@media (max-width: 640px) {
    .toast-stack {
        top: calc(64px + var(--panic-bar-h, 0px));
        right: 10px;
        left: 10px;
        width: auto;
    }
}
</style>

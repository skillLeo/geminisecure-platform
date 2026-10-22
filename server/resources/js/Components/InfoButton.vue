<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { INFO } from '../lib/infoTopics'

/**
 * THE `i` BESIDE A RULE (M32 F12) — both consoles. A small button beside a heading that opens a short note on what the
 * rule is and what it is for: two or three sentences, and "What happens next" where that helps. The words live in one
 * file, one key per topic (`lib/infoTopics.js`), so a sentence is corrected there and nowhere else.
 *
 * SMALL AND QUIET: a 16 px glyph in the page's secondary colour, its hit area widened by padding to 28 px so it is easy
 * to press and pushes nothing aside. It sits beside a heading, never on a control.
 *
 * THE NOTE OPENS OVER THE PAGE, as ConsoleDialog does: moved to <body> where there is one, focus inside it, Escape or a
 * click outside it closes it, and focus goes back to the `i`. It has one button, Close, because it asks nothing.
 */
const props = defineProps({
    /** A key of `INFO`. An unknown key draws nothing rather than an empty note. */
    topic: { type: String, required: true },
})

const info = computed(() => INFO[props.topic] ?? null)
const open = ref(false)
const button = ref(null)
const closer = ref(null)
const titleId = `info-${Math.random().toString(36).slice(2, 10)}`

// Where there is no real document (the consoles' script tests), the note renders in place: the same markup.
const canTeleport = typeof document !== 'undefined' && typeof document.querySelector === 'function'

const close = () => {
    open.value = false
}

const onKeydown = (event) => {
    if (open.value && event.key === 'Escape') {
        event.preventDefault?.()
        close()
    }
}

watch(open, async (isOpen) => {
    if (typeof document === 'undefined') {
        return
    }

    if (isOpen) {
        document.addEventListener('keydown', onKeydown)
        await nextTick()
        closer.value?.focus?.()
    } else {
        document.removeEventListener('keydown', onKeydown)
        button.value?.focus?.()
    }
})

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') {
        document.removeEventListener('keydown', onKeydown)
    }
})
</script>

<template>
    <span v-if="info" class="ib-wrap">
        <button
            ref="button"
            type="button"
            class="ib-btn"
            :aria-label="`About ${info.title}`"
            aria-haspopup="dialog"
            :aria-expanded="open ? 'true' : 'false'"
            data-info-button
            @click="open = true"
        >
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                <path d="M12 11v5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <circle cx="12" cy="7.7" r="1.1" fill="currentColor" />
            </svg>
        </button>

        <Teleport to="body" :disabled="!canTeleport">
            <div v-if="open" class="ib-backdrop" data-info-backdrop @mousedown.self="close">
                <div class="ib-panel" role="dialog" aria-modal="true" :aria-labelledby="titleId" data-info-sheet>
                    <div :id="titleId" class="ib-title">{{ info.title }}</div>
                    <p v-for="line in info.lines" :key="line" class="ib-line">{{ line }}</p>
                    <template v-if="info.next">
                        <div class="ib-next">What happens next</div>
                        <p class="ib-line">{{ info.next }}</p>
                    </template>
                    <div class="ib-actions">
                        <button ref="closer" type="button" class="ib-close" @click="close">Close</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </span>
</template>

<style scoped>
/*
 * Authored: no board draws it. Every value is a design token, and the backdrop is the navy ConsoleDialog and the panic
 * overlay already dim the page with, so the three read as one family.
 */
.ib-wrap {
    display: inline-flex;
    vertical-align: middle;
}

.ib-btn {
    -webkit-appearance: none;
    appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    margin: -6px 0 -6px 2px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--slate-500);
    cursor: pointer;
}

.ib-btn:hover {
    color: var(--navy-600);
}

.ib-btn:focus-visible,
.ib-close:focus-visible {
    outline: 2px solid var(--amber-500);
    outline-offset: 2px;
}

.ib-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(11, 31, 51, 0.55);
}

.ib-panel {
    width: 100%;
    max-width: 440px;
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    background: var(--white);
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(11, 31, 51, 0.35);
    padding: 22px 24px;
    text-align: left;
}

.ib-title {
    font-size: 17px;
    font-weight: 700;
    line-height: 1.3;
    color: var(--navy-900);
}

.ib-line {
    margin: 10px 0 0;
    font-size: 13.5px;
    font-weight: 400;
    line-height: 1.55;
    color: var(--slate-600);
}

.ib-next {
    margin-top: 14px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--navy-600);
}

.ib-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
}

.ib-close {
    -webkit-appearance: none;
    appearance: none;
    height: 40px;
    padding: 0 18px;
    border-radius: 10px;
    background: var(--white);
    color: var(--navy-900);
    border: 1.5px solid var(--navy-200);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
}
</style>

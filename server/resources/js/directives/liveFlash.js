import { watch } from 'vue'
import { flashAge, flashSeq, registerFlashElement, unregisterFlashElement } from '../live/store.js'

/**
 * THE QUIET HIGHLIGHT (M11 Part C, design §6.3).
 *
 *     <tr v-for="ticket in rows" :key="ticket.number" v-live-flash:ticket="ticket.number">
 *
 * When a live update changes the record behind an element — changed somewhere else, not by
 * this tab's own write — the element's background fades from `var(--navy-100)` to nothing
 * over 1.6 s. So a reader notices that it moved, without being alarmed by it.
 *
 *   KEYED BY RECORD, NOT BY NODE. The directive's value (with its argument as the
 *   namespace: `ticket:55`) names the record; the bindings mark the record as changed, and
 *   every element showing it flashes. A row created a moment after the change — a new row
 *   arriving, or a virtualised table drawing it as it scrolls in — still flashes.
 *   REDUCED MOTION. Under `prefers-reduced-motion: reduce` nothing animates: the element
 *   takes the `.live-changed` class (app.css: a static `var(--navy-100)` background) for
 *   2 s instead.
 *   NO NEW COLOUR. The existing token only; never amber, which means "degraded" here, and
 *   no literal of any kind.
 *
 * It also registers the element under its key, so a list does not move or remove a row the
 * reader has the focus inside (bindings.js, `hold`).
 */

const FLASH_MS = 1600
const TINT_MS = 2000

const records = new WeakMap()

const keyOf = (binding) => {
    const value = binding.value

    if (value === undefined || value === null || value === '') {
        return null
    }

    return binding.arg ? `${binding.arg}:${value}` : String(value)
}

function reducedMotion() {
    try {
        return typeof window !== 'undefined'
            && typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches === true
    } catch {
        return false
    }
}

/** The token as it resolves on this element, so the animation does not depend on var() support. */
function tint(el) {
    try {
        const value = typeof getComputedStyle === 'function' ? getComputedStyle(el).getPropertyValue('--navy-100').trim() : ''

        return value !== '' ? value : 'var(--navy-100)'
    } catch {
        return 'var(--navy-100)'
    }
}

function play(el, record) {
    if (reducedMotion()) {
        el.classList?.add('live-changed')
        clearTimeout(record.timer)
        record.timer = setTimeout(() => el.classList?.remove('live-changed'), TINT_MS)

        return
    }

    if (typeof el.animate === 'function') {
        el.animate([{ backgroundColor: tint(el) }, { backgroundColor: 'transparent' }], { duration: FLASH_MS, easing: 'ease-out' })
    }
}

function bind(el, key) {
    const record = { key, seen: key === null ? 0 : flashSeq(key), timer: null, stop: null }
    records.set(el, record)

    if (key === null) {
        return
    }

    registerFlashElement(key, el)

    if (record.seen !== 0 && flashAge(key) < FLASH_MS) {
        play(el, record)
    }

    record.stop = watch(
        () => flashSeq(key),
        (seq) => {
            if (seq !== 0 && seq !== record.seen) {
                record.seen = seq
                play(el, record)
            }
        },
    )
}

function unbind(el) {
    const record = records.get(el)

    if (!record) {
        return
    }

    record.stop?.()
    clearTimeout(record.timer)
    el.classList?.remove('live-changed')

    if (record.key !== null) {
        unregisterFlashElement(record.key, el)
    }

    records.delete(el)
}

export const vLiveFlash = {
    mounted(el, binding) {
        bind(el, keyOf(binding))
    },

    updated(el, binding) {
        const key = keyOf(binding)

        if (records.get(el)?.key !== key) {
            unbind(el)
            bind(el, key)
        }
    },

    beforeUnmount(el) {
        unbind(el)
    },
}

export default vLiveFlash

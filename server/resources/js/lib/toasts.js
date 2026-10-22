import { reactive } from 'vue'

/**
 * EVERY MESSAGE, TOP RIGHT, WITH A CLOSE CONTROL (M95 §2; Hassam, 11 Oct).
 *
 * Messages were banners pushed into the page: a page grew by a line nobody saw on a tall screen, and the remittance
 * advice said "Preparing" twice, as a banner and as a box of its own. Now every message is one of these, in one stack
 * drawn by `ToastStack` in the shell, above the content and never pushing it down:
 *
 *   - four tones: success, error, warning, info, each with its own icon and words, never colour alone;
 *   - newest at the top;
 *   - a success or a piece of information closes itself after a few seconds; a failure or a warning stays until the
 *     person closes it, because a failure they did not see is one they will repeat;
 *   - the same words are never shown twice: the one already showing comes to the top and starts its clock again;
 *   - a message with a `key` changes in place (a document being prepared, then ready, or failed).
 *
 * The red emergency bar is not one of these and never will be (M95 §2.3).
 */

const CLOSES_AFTER_MS = { success: 5000, info: 7000 }

/** More than this and the oldest message that closes by itself goes first. */
const MOST = 6

/** The stack, newest first. */
export const toasts = reactive([])

let next = 1
const timers = new Map()

const stopClock = (id) => {
    if (timers.has(id)) {
        clearTimeout(timers.get(id))
        timers.delete(id)
    }
}

/** Close one message. */
export function dismissToast(id) {
    stopClock(id)

    const at = toasts.findIndex((each) => each.id === id)

    if (at >= 0) {
        toasts.splice(at, 1)
    }
}

/** Close the message a `key` names, if one is showing. */
export function dismissToastKeyed(key) {
    const found = toasts.find((each) => each.key === key)

    if (found) {
        dismissToast(found.id)
    }
}

/**
 * Say something, and return its id.
 *
 * @param {string} text  the words, exactly as the person reads them
 * @param {{ tone?: 'success'|'error'|'warning'|'info', key?: string|null, sticky?: boolean }} options
 *        `sticky` keeps a success or a piece of information up until it is closed or replaced
 */
export function toast(text, { tone = 'success', key = null, sticky = false } = {}) {
    const words = String(text ?? '').trim()

    if (words === '') {
        return null
    }

    const showing = toasts.find((each) => (key !== null && each.key === key) || each.text === words)
    let id

    if (showing) {
        id = showing.id
        Object.assign(showing, { text: words, tone, key: key ?? showing.key })
        toasts.splice(toasts.indexOf(showing), 1)
        toasts.unshift(showing)
    } else {
        id = next++
        toasts.unshift({ id, text: words, tone, key })
    }

    stopClock(id)

    const after = sticky ? null : CLOSES_AFTER_MS[tone] ?? null

    if (after !== null && typeof setTimeout === 'function') {
        timers.set(id, setTimeout(() => dismissToast(id), after))
    }

    // Too many: the oldest that would have closed by itself goes. A failure is never dropped unseen.
    while (toasts.length > MOST) {
        const oldest = [...toasts].reverse().find((each) => each.tone === 'success' || each.tone === 'info')

        if (!oldest) {
            break
        }

        dismissToast(oldest.id)
    }

    return id
}

/** Empty the stack: for the console's script tests, which share one module between cases. */
export function resetToasts() {
    for (const each of [...toasts]) {
        dismissToast(each.id)
    }
}

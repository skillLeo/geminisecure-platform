import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * A CLOCK A TEMPLATE CAN READ (B112).
 *
 * `Date.now()` is not reactive: a computed that reads it is worked out once and cached, so an
 * age printed from it — "just now" on a panic card — stays exactly what it was when the card
 * opened, for as long as the card stands. A President back at the desk twenty-five minutes
 * later read the emergency as brand new. This ref moves every `ms`, so anything computed from
 * it moves too, and it stops when the component is taken down.
 *
 * @param {number} [ms]  how often it moves; fifteen seconds is finer than any age it feeds
 */
export function useTickingNow(ms = 15000) {
    const now = ref(Date.now())
    let timer = null

    onMounted(() => {
        now.value = Date.now()
        timer = setInterval(() => {
            now.value = Date.now()
        }, ms)
    })

    onBeforeUnmount(() => {
        clearInterval(timer)
    })

    return now
}

/**
 * "just now", "3 minutes ago", "2 hours ago", "4 days ago" — the age of a server instant, as of
 * `now`. Null for an instant that cannot be read, so the screen says nothing rather than a guess.
 *
 * @param {string|null} iso
 * @param {number} now  milliseconds, from `useTickingNow`
 */
export function agoFrom(iso, now) {
    const at = iso ? Date.parse(iso) : Number.NaN

    if (Number.isNaN(at)) {
        return null
    }

    const seconds = Math.max(0, Math.round((now - at) / 1000))

    if (seconds < 60) {
        return 'just now'
    }

    const minutes = Math.round(seconds / 60)

    if (minutes < 60) {
        return minutes === 1 ? '1 minute ago' : `${minutes} minutes ago`
    }

    const hours = Math.floor(minutes / 60)

    if (hours < 24) {
        return hours === 1 ? '1 hour ago' : `${hours} hours ago`
    }

    const days = Math.floor(hours / 24)

    return days === 1 ? '1 day ago' : `${days} days ago`
}

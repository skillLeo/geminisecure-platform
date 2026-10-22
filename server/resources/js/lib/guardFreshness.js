/**
 * HOW FRESH A GUARD'S PIN IS, AND IT NEVER PRETENDS (Hassam, 12 Oct 2026; docs/handoff/GUARD_TRACKING.md 5.5).
 *
 * `App\Services\Tracking\GuardFreshness` in JavaScript, for both consoles' maps: the server sends each officer's
 * `reading_at` (when the newest reading was taken, never later than when it arrived), `arrived_at`, `tolerance_s`,
 * `clocked_in_at` and its own `freshness` as of the read; a page calls this on its ticking clock, so a pin goes from
 * Live to Last seen to No signal without a reload. Only `not_shared` is taken from the server as given.
 *
 *   live        the newest reading is under LIVE_SECONDS old
 *   last_seen   older than that, while something has arrived within the tolerance
 *   no_signal   nothing has arrived for longer than the tolerance (or nothing since a clock-in that long ago; on the
 *               estate's screen, which has no clock-in time, the server's call: "No signal since the shift began")
 *   waiting     clocked in, nothing yet, still within the tolerance
 *   not_shared  no consent to the current wording, or tracking switched off
 *
 * `timeWords(iso)` writes a moment the way the console writes one (the estate's clock and its tag, "9:38 PM JA").
 */
export const LIVE_SECONDS = 60

export const DEFAULT_TOLERANCE_SECONDS = 300

/** "1 min ago", "4 min ago", "1 h 5 min ago". Never under a minute: that is Live. */
export function minutesAgo(seconds) {
    const minutes = Math.max(1, Math.floor(Math.max(0, seconds) / 60))

    return minutes < 60 ? `${minutes} min ago` : `${Math.floor(minutes / 60)} h ${minutes % 60} min ago`
}

const at = (iso) => (iso ? Date.parse(iso) : Number.NaN)

/**
 * @param {{ reading_at?: string|null, arrived_at?: string|null, clocked_in_at?: string|null, tolerance_s?: number, freshness?: string }} officer
 * @param {number} nowMs
 * @param {(iso: string) => string} timeWords
 * @returns {{ freshness: 'live'|'last_seen'|'no_signal'|'waiting'|'not_shared', label: string, ageSeconds: number|null }}
 */
export function guardFreshness(officer, nowMs, timeWords) {
    if (officer?.freshness === 'not_shared') {
        return { freshness: 'not_shared', label: 'Position not shared', ageSeconds: null }
    }

    const tolerance = Math.max(60, Number(officer?.tolerance_s) || DEFAULT_TOLERANCE_SECONDS) * 1000
    const reading = at(officer?.reading_at)
    const arrived = at(officer?.arrived_at)
    const clockedIn = at(officer?.clocked_in_at)

    if (!Number.isFinite(reading) || !Number.isFinite(arrived)) {
        if (Number.isFinite(clockedIn) && nowMs - clockedIn > tolerance) {
            return { freshness: 'no_signal', label: `No signal since clock-in at ${timeWords(officer.clocked_in_at)}`, ageSeconds: null }
        }

        // The estate never sees a clock-in time (Hassam, 12 Oct): the server's call as of the read stands, and the page
        // reads the feed again while anyone is still waiting.
        if (!Number.isFinite(clockedIn) && officer?.freshness === 'no_signal') {
            return { freshness: 'no_signal', label: 'No signal since the shift began', ageSeconds: null }
        }

        return { freshness: 'waiting', label: 'Waiting for the first position', ageSeconds: null }
    }

    const ageSeconds = Math.max(0, Math.floor((nowMs - reading) / 1000))

    if (nowMs - arrived > tolerance) {
        return { freshness: 'no_signal', label: `No signal · last seen ${timeWords(officer.reading_at)}`, ageSeconds }
    }

    if (ageSeconds < LIVE_SECONDS) {
        return { freshness: 'live', label: 'Live', ageSeconds }
    }

    return { freshness: 'last_seen', label: `Last seen ${minutesAgo(ageSeconds)} · ${timeWords(officer.reading_at)}`, ageSeconds }
}

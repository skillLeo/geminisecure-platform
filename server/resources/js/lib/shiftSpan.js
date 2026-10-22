import { offsetMinutes, span } from './scheduleTime.js'

/**
 * WHAT A SHIFT'S THREE FIELDS SAY, WORKED OUT AS THEY ARE TYPED (M35 §3).
 *
 * A date, a start and an end, in the estate's time. An end that is not after the start is the next morning — a night
 * shift, the most common shift there is — never a mistake: the server reads it the same way (`ShiftPlanner`), so the
 * form says it in one quiet line instead of refusing. A shift is never longer than 24 hours, because an end is a time of
 * day: the longest one possible ends at the time it started, the next day.
 *
 *   date 2026-10-20, 18:00 to 07:00, in Jamaica, seen from Karachi:
 *     minutes       780
 *     nextDay       true
 *     endsLine      "Ends 21 Oct, 7:00 AM JA — 13 hours"
 *     durationLine  "13 hours, 6:00 PM to 7:00 AM JA"
 *     viewerLine    "4:00 AM to 5:00 PM your time"   (null when the viewer is on the estate's clock)
 *
 * The server's ticks below the form stay the word that counts; this is so nobody waits for them to know what they typed.
 *
 * @param {{date: string, starts: string, ends: string, estateZone: string, estateTag: string, viewerZone?: string|null}} f
 * @returns {null | {minutes: number, nextDay: boolean, endDate: string, endsLine: string|null, durationLine: string, viewerLine: string|null}}
 */
export function shiftSpan({ date, starts, ends, estateZone, estateTag, viewerZone = null }) {
    const time = /^(\d{2}):(\d{2})$/
    const day = /^(\d{4})-(\d{2})-(\d{2})$/

    if (!day.test(date ?? '') || !time.test(starts ?? '') || !time.test(ends ?? '')) {
        return null
    }

    const [sh, sm] = starts.split(':').map(Number)
    const [eh, em] = ends.split(':').map(Number)
    const from = sh * 60 + sm
    const nextDay = eh * 60 + em <= from
    const to = eh * 60 + em + (nextDay ? 24 * 60 : 0)
    const minutes = to - from

    const [y, m, d] = date.split('-').map(Number)
    const endDate = new Date(Date.UTC(y, m - 1, d + (nextDay ? 1 : 0))).toISOString().slice(0, 10)

    const length = span(minutes)

    const endsLine = nextDay
        ? `Ends ${new Date(`${endDate}T00:00:00Z`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })}, ${clock(eh, em)} ${estateTag} — ${length}`
        : null

    const durationLine = `${length}, ${clock(sh, sm)} to ${clock(eh, em)} ${estateTag}`

    return { minutes, nextDay, endDate, endsLine, durationLine, viewerLine: viewerLine({ date, endDate, sh, sm, eh, em, estateZone, viewerZone }) }
}

/** "6:00 PM" — written out here, so every line reads alike whatever the browser's own spacing. */
function clock(h, min) {
    return `${h % 12 === 0 ? 12 : h % 12}:${String(min).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}

/** The same start and end on the viewer's clock, or null when the viewer keeps the estate's time (or will not say). */
function viewerLine({ date, endDate, sh, sm, eh, em, estateZone, viewerZone }) {
    if (!viewerZone || !estateZone || viewerZone === estateZone) {
        return null
    }

    // A wall-clock time at the estate as the instant it is: the estate's offset at that moment, taken from its zone.
    const instant = (ymd, h, min) => {
        const [y, m, d] = ymd.split('-').map(Number)
        const asUtc = Date.UTC(y, m - 1, d, h, min)

        return new Date(asUtc - offsetMinutes(estateZone, new Date(asUtc)) * 60000)
    }

    const at = (when) => {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: viewerZone, hourCycle: 'h23', hour: '2-digit', minute: '2-digit' }).formatToParts(when)
        const get = (type) => Number(parts.find((p) => p.type === type)?.value ?? 0)

        return clock(get('hour') % 24, get('minute'))
    }
    const start = instant(date, sh, sm)
    const end = instant(endDate, eh, em)

    if (offsetMinutes(viewerZone, start) === offsetMinutes(estateZone, start)) {
        return null
    }

    return `${at(start)} to ${at(end)} your time`
}

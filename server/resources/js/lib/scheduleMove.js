/**
 * MOVING A SHIFT BY DRAGGING IT (M30 §4.3): the same times, on the client's clock, at another gate or on another day.
 *
 * A block dropped on another cell keeps its start and end as the client's clock reads them; it moves by as many days
 * as the cells are apart, and to the cell's gate. The sentence that asks before anything is saved is made here, in the
 * milestone's words: "Move Andre Campbell's 6:00 AM – 6:00 PM JA shift from Main Gate, Mon 5 Oct, to Back Gate,
 * Tue 6 Oct?" Whether the move is allowed is the server's to say (`/guards/schedule/check`), never this file's.
 */

const pad = (n) => String(n).padStart(2, '0')

/** "06:00": an instant's wall-clock time at the client. */
export function clientClock(iso, zone) {
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone: zone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(iso))
    const part = (type) => parts.find((p) => p.type === type)?.value ?? '00'

    return `${part('hour')}:${part('minute')}`
}

/** "2026-10-05": an instant's date at the client. */
export function clientDate(iso, zone) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso))
}

/** A calendar date moved by whole days ("2026-10-05", 1 → "2026-10-06"). */
export function addDays(date, days) {
    const [y, m, d] = date.split('-').map(Number)
    const at = new Date(Date.UTC(y, m - 1, d + days))

    return `${at.getUTCFullYear()}-${pad(at.getUTCMonth() + 1)}-${pad(at.getUTCDate())}`
}

/** How many days apart two calendar dates are. */
export function daysBetween(from, to) {
    return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000)
}

/** "Mon 5 Oct" for a calendar date. */
export function dayLabel(date) {
    const [y, m, d] = date.split('-').map(Number)

    return new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(Date.UTC(y, m - 1, d)))
}

/** "6:00 AM" for "06:00". */
export function twelveHour(clock) {
    const [h, m] = clock.split(':').map(Number)

    return `${((h + 11) % 12) + 1}:${pad(m)} ${h < 12 ? 'AM' : 'PM'}`
}

/**
 * Where a dragged block lands, and the sentence that asks; null when it is dropped where it already is.
 *
 * `fromDay` and `toDay` are the cells' dates as the grid draws them, the client's days on either clock (§4.5): only
 * how far apart they are is used, so the shift keeps its own clock either way.
 */
export function plannedMove({ block, fromPost, fromDay, toPost, toDay, zone, tag }) {
    if (fromPost.id === toPost.id && fromDay === toDay) {
        return null
    }

    const startDate = clientDate(block.starts_at, zone)
    const date = addDays(startDate, daysBetween(fromDay, toDay))
    const starts = clientClock(block.starts_at, zone)
    const ends = clientClock(block.ends_at, zone)
    const whose = block.guard ? `${block.guard.name}'s` : 'this'

    return {
        post_id: toPost.id,
        date,
        starts,
        ends,
        duty: block.duty,
        guard_id: block.guard?.id ?? null,
        sentence: `Move ${whose} ${twelveHour(starts)} – ${twelveHour(ends)} ${tag} shift from ${fromPost.name}, ${dayLabel(startDate)}, to ${toPost.name}, ${dayLabel(date)}?`,
    }
}

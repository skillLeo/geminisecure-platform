/**
 * The Schedule's one line about clocks (M30 §4.5): whose time the grid is in, and how far the viewer's clock is from
 * it — computed from the zones themselves, never written in, so it is right on both sides of a clock change.
 */

/** Minutes a zone is ahead of UTC at an instant, from the browser's own zone database. */
export function offsetMinutes(zone, at = new Date()) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: zone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(at)

    const get = (type) => Number(parts.find((p) => p.type === type)?.value ?? 0)
    const wall = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour') % 24, get('minute'), get('second'))

    return Math.round((wall - Math.floor(at.getTime() / 1000) * 1000) / 60000)
}

/** "10 hours", "1 hour", "5 hours 30 minutes". */
export function span(minutes) {
    const m = Math.abs(minutes)
    const h = Math.floor(m / 60)
    const rest = m % 60
    const hours = h === 0 ? '' : `${h} hour${h === 1 ? '' : 's'}`
    const mins = rest === 0 ? '' : `${rest} minute${rest === 1 ? '' : 's'}`

    return [hours, mins].filter(Boolean).join(' ')
}

/**
 * The sentence under the week navigator.
 *
 * @param {{client: string, estateZone: string, estateTag: string, shownTag: string, mine: boolean, viewerZone: string|null}} c
 *   `mine`: the console is on the viewer's own clock ("My time"); `viewerZone`: the viewer's own zone, or null if unknown
 */
export function zoneSentence(c, at = new Date()) {
    const viewer = c.viewerZone
    const apart = viewer ? offsetMinutes(viewer, at) - offsetMinutes(c.estateZone, at) : 0

    if (c.mine) {
        const first = `All times are your time (${c.shownTag}).`
        if (!viewer || apart === 0) return first

        return `${first} ${c.client}'s time is ${span(apart)} ${apart > 0 ? 'behind' : 'ahead'}.`
    }

    const first = `All times are ${c.client}'s time (${c.estateTag}).`
    if (!viewer || apart === 0) return first

    return `${first} Your time is ${span(apart)} ${apart > 0 ? 'ahead' : 'behind'}.`
}

/** The browser's own zone, or null where it will not say. */
export function browserZone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || null
    } catch {
        return null
    }
}

/**
 * "Upcoming shifts" on the guard's own profile (M12 §3.3) — the pure half.
 *
 * The next fourteen days AT THE SITE, from shifts.me. The server's range is in UTC, so a day either side is asked
 * for and the window is cut here, on the site's calendar. A shift already over is not upcoming; the one the guard
 * is on now is, and says so. An open shift is one nobody holds, so it is not the guard's and is not listed.
 */

import { dateWeekday, shiftName, siteDayIso, time12 } from '@gs/core/lib'
import type { ShiftRow } from '../payslips/payslips'

/** How far ahead the profile looks. The server serves up to 31 days in one ask. */
export const UPCOMING_DAYS = 14

/** What to ask the server for: today to fourteen days on, and a day either side for the UTC edge. */
export function upcomingRange(at: Date): { from: string; to: string } {
  return { from: siteDayIso(at, -1), to: siteDayIso(at, UPCOMING_DAYS + 1) }
}

export type UpcomingRow = { key: string; day: string; title: string; detail: string; now: boolean }

export function upcomingShifts(items: readonly ShiftRow[], at: Date): UpcomingRow[] {
  const today = siteDayIso(at)
  const last = siteDayIso(at, UPCOMING_DAYS)
  const nowMs = at.getTime()

  return items
    .filter((s) => s.status === 'rostered' || s.status === 'on_duty')
    .filter((s) => Date.parse(s.rostered_end) > nowMs || s.status === 'on_duty')
    .filter((s) => { const d = siteDayIso(s.rostered_start); return d >= today && d <= last || s.status === 'on_duty' })
    .sort((p, q) => (p.rostered_start < q.rostered_start ? -1 : p.rostered_start > q.rostered_start ? 1 : 0))
    .map((s) => {
      const onDuty = s.status === 'on_duty'
      const d = siteDayIso(s.rostered_start)
      // The weekday rides with the date (M19A §4.4): "Wed, Sep 30".
      const day = d === today ? 'Today' : d === siteDayIso(at, 1) ? 'Tomorrow' : dateWeekday(s.rostered_start)
      return {
        key: String(s.id),
        day,
        title: s.post.name,
        detail: `${shiftName(s.rostered_start)} · ${time12(s.rostered_start)} – ${time12(s.rostered_end)}${onDuty ? ' · on duty now' : ''}`,
        now: onDuty,
      }
    })
}

/** Said when there is nothing in the window — plainly, and never as a fault. */
export const NO_UPCOMING = `Nothing is rostered for you in the next ${UPCOMING_DAYS} days. Open shifts you can claim are under Leave & duty.`

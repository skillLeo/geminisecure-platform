/**
 * "Add 'Upcoming shifts' to the guard's profile" (M12 §3.3). The next fourteen days on the SITE's calendar: the
 * shift the guard is on now, then what is rostered; never an open shift, one already over, or one past the window.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { NO_UPCOMING, upcomingRange, upcomingShifts } from '../upcoming'
import type { ShiftRow } from '../../payslips/payslips'

// 10:00 on Saturday 26 September at the site (Jamaica, UTC−5).
const at = new Date('2026-09-26T15:00:00Z')

const shift = (id: number, status: ShiftRow['status'], start: string, end: string, post = 'Main Gate'): ShiftRow => ({
  id, status, post: { id: 1, name: post }, site: { id: 1, name: 'Seaview', timezone: 'America/Jamaica' },
  rostered_start: start, rostered_end: end, actual_start: status === 'on_duty' ? start : null, actual_end: null, worked_hours: 0, night_hours: 0,
})

it('asks for today to fourteen days on, with a day either side for the UTC edge', () => {
  expect(upcomingRange(at)).toEqual({ from: '2026-09-25', to: '2026-10-11' })
})

it('lists the shift on now first, then what is rostered, each on the site clock', () => {
  const rows = upcomingShifts([
    shift(7, 'rostered', '2026-09-30T12:00:00Z', '2026-10-01T00:00:00Z', 'North Gate'),
    shift(2, 'on_duty', '2026-09-26T12:00:00Z', '2026-09-27T00:00:00Z'),
    shift(1, 'completed', '2026-09-26T11:00:00Z', '2026-09-26T14:00:00Z'),
    shift(3, 'rostered', '2026-09-28T00:00:00Z', '2026-09-28T12:00:00Z'),
    shift(4, 'open', '2026-09-29T12:00:00Z', '2026-09-30T00:00:00Z'),
    shift(5, 'rostered', '2026-10-11T12:00:00Z', '2026-10-12T00:00:00Z'),
    shift(6, 'rostered', '2026-09-26T00:00:00Z', '2026-09-26T12:00:00Z'),
    shift(8, 'missed', '2026-09-26T13:00:00Z', '2026-09-26T23:00:00Z'),
  ], at)

  expect(rows).toEqual([
    { key: '2', day: 'Today', title: 'Main Gate', detail: 'Day Shift · 7:00 AM – 7:00 PM · on duty now', now: true },
    { key: '3', day: 'Tomorrow', title: 'Main Gate', detail: 'Night Shift · 7:00 PM – 7:00 AM', now: false },
    { key: '7', day: '30 Sep 2026', title: 'North Gate', detail: 'Day Shift · 7:00 AM – 7:00 PM', now: false },
  ])
})

it('says so plainly when nothing is rostered, and where open shifts are', () => {
  expect(upcomingShifts([], at)).toEqual([])
  expect(NO_UPCOMING).toBe('Nothing is rostered for you in the next 14 days. Open shifts you can claim are under Leave & duty.')
})

it('is drawn on the profile, outside the board fixture, with its loading, refusal and error states', () => {
  const screen = readFileSync(path.join(__dirname, '..', 'screens', 'ProfileScreen.tsx'), 'utf8')
  expect(screen).toContain("<Heading>{'Upcoming shifts'}</Heading>")
  expect(screen).toMatch(/useUpcomingShifts\(fixture\)/)
  expect(screen).toMatch(/upcoming\.status === 'forbidden'/)
  expect(screen).toMatch(/upcoming\.status === 'error'/)
})

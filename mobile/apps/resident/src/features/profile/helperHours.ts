/**
 * A HELPER'S DAYS AND HOURS (the user, 8 Oct 2026) — what the primary resident sets for one Helper in their household's
 * sheet: the days, the hours (on the half hour; an overnight window is allowed), an end date or none, and "pause now".
 * The server keeps them (`members.access`) and is what enforces them — the e-Pass is only signed inside them, and the
 * gate refuses it outside — so this file only shapes what the sheet shows, in the server's own words. Pure.
 */

import type { Member } from './household'

export type HelperWindow = { days: number[]; from: string; to: string; until: string | null; paused: boolean }

/** The estate's default when nothing has been read: Monday to Saturday, 7 AM to 7 PM (AccessRules on the server). */
export const DEFAULT_WINDOW: HelperWindow = { days: [1, 2, 3, 4, 5, 6], from: '07:00', to: '19:00', until: null, paused: false }

export const DAY_NAMES: readonly (readonly [number, string, string])[] = [
  [1, 'Mon', 'Monday'], [2, 'Tue', 'Tuesday'], [3, 'Wed', 'Wednesday'], [4, 'Thu', 'Thursday'], [5, 'Fri', 'Friday'], [6, 'Sat', 'Saturday'], [7, 'Sun', 'Sunday'],
]

/** Their days and hours as the server holds them — null for a Member or a Dependent, and from a server older than this. */
export function windowOf(member: Pick<Member, 'access_window'> | null | undefined): HelperWindow | null {
  const w = member?.access_window
  if (!w) return null
  return { days: [...w.days], from: w.from, to: w.to, until: w.until ?? null, paused: !!w.paused }
}

/** "07:00" moved by half hours, around the clock. */
export function stepTime(hhmm: string, halfHours: number): string {
  const [h = 0, m = 0] = hhmm.split(':').map(Number)
  const minutes = (((h * 60 + m + halfHours * 30) % 1440) + 1440) % 1440
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

/** "07:00" → "7:00 AM". */
export function timeWords(hhmm: string): string {
  const [h = 0, m = 0] = hhmm.split(':').map(Number)
  return `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}

/** [1,2,3,4,5,6] → "Mon–Sat"; [1,3,5] → "Mon, Wed, Fri"; all seven → "Every day" — as the server says them. */
export function daysWords(days: readonly number[]): string {
  const sorted = [...new Set(days)].sort((a, b) => a - b)
  if (sorted.length === 7) return 'Every day'
  const name = (d: number) => DAY_NAMES.find(([n]) => n === d)?.[1] ?? '?'
  const run = sorted.length >= 3 && sorted.every((d, i) => i === 0 || d === sorted[i - 1]! + 1)
  return run ? `${name(sorted[0]!)}–${name(sorted[sorted.length - 1]!)}` : sorted.map(name).join(', ')
}

/** "2026-10-31" → "31 Oct 2026": the one way a date is written (M98, 12 Oct 2026; it read "Oct 31, 2026"). */
export function dateWords(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number)
  const month = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][(m ?? 1) - 1] ?? '?'
  return `${d} ${month} ${y}`
}

/** "2026-10-31" moved by whole days — never before `floor` (today, at the estate). */
export function stepDate(ymd: string, days: number, floor?: string): string {
  const [y = 1970, m = 1, d = 1] = ymd.split('-').map(Number)
  const next = new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10)
  return floor && next < floor ? floor : next
}

/** "2026-10-31" a calendar month on — the last day of a shorter month when it has no 31st. */
export function stepMonths(ymd: string, months: number): string {
  const [y = 1970, m = 1, d = 1] = ymd.split('-').map(Number)
  const last = new Date(Date.UTC(y, m - 1 + months + 1, 0)).getUTCDate()
  return new Date(Date.UTC(y, m - 1 + months, Math.min(d, last))).toISOString().slice(0, 10)
}

/** The quick end dates offered beside the day-by-day one: from today, at the estate. */
export const END_PRESETS: readonly { label: string; name: string; from: (today: string) => string }[] = [
  { label: '1 week', name: 'Ends in a week', from: (today) => stepDate(today, 7) },
  { label: '2 weeks', name: 'Ends in two weeks', from: (today) => stepDate(today, 14) },
  { label: '1 month', name: 'Ends in a month', from: (today) => stepMonths(today, 1) },
  { label: '3 months', name: 'Ends in three months', from: (today) => stepMonths(today, 3) },
]

/** "Overnight: they may come from 10:00 PM until 6:00 AM the next morning." — null for a window inside one day. */
export const overnightLine = (w: Pick<HelperWindow, 'from' | 'to'>): string | null =>
  w.to < w.from ? `Overnight: they may come from ${timeWords(w.from)} until ${timeWords(w.to)} the next morning.` : null

/** "Mon–Sat, 7:00 AM – 7:00 PM, until 31 Oct 2026" — or "Paused by the household", as the server says it. */
export function windowLine(w: HelperWindow): string {
  if (w.paused) return 'Paused by the household'
  const line = `${daysWords(w.days)}, ${timeWords(w.from)} – ${timeWords(w.to)}`
  return w.until ? `${line}, until ${dateWords(w.until)}` : line
}

/** A day in or out of the window. */
export const toggleDay = (days: readonly number[], day: number): number[] => (days.includes(day) ? days.filter((d) => d !== day) : [...days, day].sort((a, b) => a - b))

/** The same days, hours, end and pause — nothing to save. */
export const sameWindow = (a: HelperWindow, b: HelperWindow): boolean =>
  a.from === b.from && a.to === b.to && a.until === b.until && a.paused === b.paused && daysWords(a.days) === daysWords(b.days)

/** What stops the sheet from saving, in the server's words — or null. */
export function windowProblem(w: HelperWindow): string | null {
  if (w.days.length === 0) return 'Choose at least one day they may come.'
  if (w.from === w.to) return 'Choose the time they may come from and until, on the half hour, not the same time.'
  return null
}

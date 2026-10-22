/**
 * The guard's own licence, as My Profile says it (board guard-app-26).
 *
 * "A guard must be able to see their own licence expiry — an expiry surprise costs a shift."
 *
 * The server sends the number, the expiry date and what the licence is called. The WARNING is worked out
 * here, from the date and today's date at the site — never from the `state` the server sent with it: a
 * profile opened with no signal three weeks after it was cached must still say "expires in 4 days".
 *
 * `expires_on` is a CALENDAR DATE (YYYY-MM-DD), not a moment. It is never put through a timezone: a
 * licence that runs out on Nov 30 runs out on Nov 30 wherever the phone thinks it is.
 */

import { siteDayIso, siteZone } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

export type Licence = Api.GuardsMeResponse['licence']

/** What a row says when the office has not recorded the value. Never an invented number or date. */
export const HELD = 'Held by the office'

export const DEFAULT_WARNING_DAYS = 30

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const YMD = /^(\d{4})-(\d{2})-(\d{2})/

const parts = (ymd: string): [number, number, number] | null => {
  const m = YMD.exec(ymd)
  if (!m) return null
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  return mo >= 1 && mo <= 12 && d >= 1 && d <= 31 ? [y, mo, d] : null
}

/** "Nov 30, 2026" — the board's own format — from a calendar date. `null` for anything that is not one. */
export function calendarDate(ymd: string | null | undefined): string | null {
  const p = ymd ? parts(ymd) : null
  return p ? `${MONTHS[p[1] - 1]} ${p[2]}, ${p[0]}` : null
}

/** Whole calendar days from `today` to `expiresOn` — 0 on the day, negative once it has passed. */
export function daysLeft(expiresOn: string, today: string): number | null {
  const e = parts(expiresOn)
  const t = parts(today)
  if (!e || !t) return null
  return Math.round((Date.UTC(e[0], e[1] - 1, e[2]) - Date.UTC(t[0], t[1] - 1, t[2])) / 86_400_000)
}

/**
 * Today's calendar date AT THE SITE, by the site's IANA zone name — the name, never an offset.
 * The arithmetic is the app's one clock (`@gs/core/lib`); a zone this phone cannot render, or none yet,
 * falls back to the zone the session already holds.
 */
export function todayAt(zone: string | null | undefined, at: Date): string {
  return siteDayIso(at, 0, zone || siteZone())
}

export type LicenceWarning = { level: 'expiring' | 'expired'; title: string; body: string }

export type LicenceView = {
  /** Row labels and values, in order. */
  rows: (readonly [string, string])[]
  /** The pill under the guard's name — the licence number when the office has one. */
  number: string | null
  warning: LicenceWarning | null
}

export function licenceWarning(licence: Pick<Licence, 'name' | 'expires_on' | 'warning_days'>, today: string): LicenceWarning | null {
  if (!licence.expires_on) return null
  const left = daysLeft(licence.expires_on, today)
  const date = calendarDate(licence.expires_on)
  if (left === null || date === null) return null
  const name = licence.name || 'licence'
  if (left < 0) {
    return { level: 'expired', title: `Licence expired: ${date}`, body: `Your ${name} ran out ${left === -1 ? 'yesterday' : `${-left} days ago`}. You cannot stand a post until the office has recorded your renewal. Call your supervisor now.` }
  }
  const within = typeof licence.warning_days === 'number' && licence.warning_days > 0 ? licence.warning_days : DEFAULT_WARNING_DAYS
  if (left > within) return null
  const when = left === 0 ? 'today' : left === 1 ? 'tomorrow' : `in ${left} days`
  return { level: 'expiring', title: `Licence expires ${when}: ${date}`, body: `Renew your ${name} before then and give the office the new certificate. Without a valid licence you cannot be given shifts.` }
}

/** `null` while nothing has reached the phone — the screen then shows its loading or error state, not invented rows. */
export function licenceView(licence: Licence | null | undefined, today: string): LicenceView | null {
  if (!licence) return null
  const name = licence.name || 'Licence'
  const number = licence.number && licence.number.trim() !== '' ? licence.number.trim() : null
  return {
    rows: [[name, number ?? HELD], ['Licence expires', calendarDate(licence.expires_on) ?? HELD]],
    number,
    warning: licenceWarning(licence, today),
  }
}

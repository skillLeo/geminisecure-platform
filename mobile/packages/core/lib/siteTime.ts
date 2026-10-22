/**
 * ONE CLOCK. Every instant either app turns into words or digits passes through this file, and it is
 * rendered in the SITE's time zone — never the handset's, never a fixed offset.
 *
 * WHY. Storage on the server is UTC and every `/api/v1` time is a UTC instant. The zone a time is READ
 * in belongs to the estate, not to the phone: a guard whose handset is set to Miami time must still read
 * Kingston shift times, and the next territory GeminiSecure opens in may observe daylight saving, so the
 * zone is always the IANA NAME the server sent (`timezone` on the site or estate) and never "−5".
 *
 * WHERE THE ZONE COMES FROM. The server, and nowhere else:
 *
 *   1  `DEFAULT_SITE_ZONE` until the first answer arrives — what a first-run phone draws behind the splash
 *   2  `hydrateSiteZone()` behind the splash: what the server last told THIS phone, off the key-value store
 *   3  `adoptSiteZone(body)` on every API answer (packages/core/api/baseQuery.ts): the enrolment, the guard's
 *      shift and profile reads, `sync.pull`, and the resident's estate all carry `timezone`, so whichever
 *      answers first sets it and a change at the office reaches the phone on the next read
 *   4  `forgetSiteZone()` at sign-out: the next principal's site decides, not the last one's
 *
 * It is a DISPLAY FACT, kept beside the principal in the key-value store — never a credential, never in
 * SecureStore, and it opens nothing.
 *
 * THE PHONE'S OWN CLOCK is used in exactly two places in the product, both of which say so: `X-Device-Time`
 * (lib/time.ts — the moment an act happened, which is a fact about the handset) and a countdown or an "x
 * seconds ago", which measures elapsed time and has no zone at all.
 */

import { useSyncExternalStore } from 'react'
import { isFidelity } from '../fidelity'
import { getStorage } from './storage'

/**
 * What a phone reads before the server has told it the site's zone — and NO zone written into the code (M19A §9).
 * It is the build's setting (`EXPO_PUBLIC_PLATFORM_TIMEZONE`, as the server's is `PLATFORM_TIMEZONE`), and unset or
 * unreadable it is UTC: the instant exactly as the server sent it, never a guess at a country. Every estate's own zone
 * arrives from the server as an IANA name, on the first answer, and replaces it.
 */
export const DEFAULT_SITE_ZONE: string = (() => {
  // Read the way flags.ts reads its switches: babel-preset-expo writes the build's value in at bundle time.
  const configured = process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE
  if (typeof configured !== 'string' || configured === '') return 'UTC'
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: configured }).format(new Date(0))
    return configured
  } catch {
    return 'UTC'
  }
})()

/** Beside the principal, in the same key-value store. Not a secret. */
export const SITE_ZONE_KEY = 'site_zone'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const DAY_MINUTES = 24 * 60

const two = (n: number): string => String(n).padStart(2, '0')

export type SiteParts = {
  year: number
  /** Zero-based, as `Date`'s is. */
  month: number
  day: number
  /** 0 = Sunday. */
  weekday: number
  hour: number
  minute: number
  second: number
  /** Minutes east of UTC at this instant in this zone — Kingston is −300 all year, New York −240 or −300. */
  offsetMinutes: number
  zone: string
}

/* ---- the zone database, asked once per zone ------------------------------------------------------- */

const formatters = new Map<string, Intl.DateTimeFormat | null>()

const hasIntl = (): boolean => typeof Intl !== 'undefined' && typeof Intl.DateTimeFormat === 'function'

function formatterFor(name: string): Intl.DateTimeFormat | null {
  const known = formatters.get(name)
  if (known !== undefined) return known
  let made: Intl.DateTimeFormat | null = null
  if (hasIntl()) {
    try {
      const f = new Intl.DateTimeFormat('en-US', { timeZone: name, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
      f.formatToParts(new Date(0))
      made = f
    } catch {
      // An unknown zone name, or a runtime with no zone database: fall through.
      made = null
    }
  }
  formatters.set(name, made)
  return made
}

/**
 * The net for a runtime with NO zone database at all (no `Intl`): every zone falls back to UTC, which is at least the
 * instant the server actually sent. No offset is written down for any country (M19A §8.4, §9) — the one that used to
 * be, Jamaica's −300, is gone. Hermes on both platforms carries `Intl`, so this is a safety net and not a code path
 * the product runs on.
 */
const NO_ZONE_DATABASE_OFFSET: Record<string, number> = { UTC: 0 }

/** True for an IANA zone name this runtime can actually render. */
export function isZoneName(value: unknown): value is string {
  if (typeof value !== 'string' || !/^[A-Za-z][A-Za-z0-9_+-]*(?:\/[A-Za-z0-9_+-]+){0,2}$/.test(value)) return false
  return hasIntl() ? formatterFor(value) !== null : true
}

/* ---- the one store -------------------------------------------------------------------------------- */

let zone: string = DEFAULT_SITE_ZONE
const watchers = new Set<(zone: string) => void>()

/** The zone every formatter below uses when it is not given one. */
export function siteZone(): string {
  return zone
}

/** The server's word. Returns true when it CHANGED the zone — anything unreadable is ignored, not adopted. */
export function setSiteZone(next: unknown): boolean {
  if (!isZoneName(next) || next === zone) return false
  zone = next
  try {
    getStorage().set(SITE_ZONE_KEY, next)
  } catch {
    // A phone whose store cannot be written still shows the right times this session.
  }
  for (const watch of watchers) watch(next)
  return true
}

/**
 * Take the site's zone off an API answer that carries it. The field sits in one of four places across the
 * catalogue (`timezone`, `site.timezone`, `estate.timezone`, `shift.site.timezone`), so one reader covers
 * every payload that has it today and any that gains it later.
 *
 * A zone on a LIST ROW (`items.*.site.timezone`, on `shifts.open`) is deliberately NOT read here: those
 * rows may be shifts at other sites, and the session's zone is the guard's OWN site. A screen that shows
 * such a row passes that row's zone to the formatter itself.
 *
 * The first READABLE name wins, not merely the first field present: an empty string is not nullish, so
 * `timezone: ''` beside a good `site.timezone` would otherwise leave the phone on its default.
 */
export function adoptSiteZone(body: unknown): boolean {
  if (body === null || typeof body !== 'object') return false
  const at = body as { timezone?: unknown; site?: { timezone?: unknown }; estate?: { timezone?: unknown }; shift?: { site?: { timezone?: unknown } } }
  const found = [at.timezone, at.site?.timezone, at.estate?.timezone, at.shift?.site?.timezone].find((candidate) => isZoneName(candidate))
  return found === undefined ? false : setSiteZone(found)
}

/** Behind the splash, after the key-value store is in memory: what the server last told this phone. */
export function hydrateSiteZone(): string {
  try {
    const saved = getStorage().getString(SITE_ZONE_KEY)
    if (isZoneName(saved)) zone = saved
    // A phone that chose "times on this phone's clock" before M19A keeps nothing of the choice: see LEGACY_TIME_VIEW_KEY.
    getStorage().remove(LEGACY_TIME_VIEW_KEY)
  } catch {
    // Nothing saved, or no store yet: the default stands until the first answer.
  }
  return zone
}

/** Sign-out, or a revoked token: the next principal's site decides the zone, not the last one's. */
export function forgetSiteZone(): void {
  zone = DEFAULT_SITE_ZONE
  try {
    getStorage().remove(SITE_ZONE_KEY)
    getStorage().remove(LEGACY_TIME_VIEW_KEY)
  } catch {
    // Nothing to forget.
  }
  for (const watch of watchers) watch(zone)
}

/** For a screen that must redraw when the server's answer moves the zone. Returns its own unsubscribe. */
export function watchSiteZone(listener: (zone: string) => void): () => void {
  watchers.add(listener)
  return () => {
    watchers.delete(listener)
  }
}

/* ---- one clock, the estate's (M19A §3) ---------------------------------------------------------------------- */

/**
 * EVERYTHING TO DO WITH THE ESTATE IS SHOWN IN THE ESTATE'S TIME, ALWAYS, WITH A SHORT LABEL ON THE NUMBER (M19A §3).
 *
 * There used to be a choice here — "read times on this phone's clock" (M11 §4.1) — and it is gone. The gate is at the
 * estate and so is the guard: a resident in New York who read a plumber's window on their own clock would set a window
 * that is wrong when the van arrives. So nothing is converted to the viewer's clock. Their own clock is only ever shown
 * BESIDE the estate's, secondary, where they must act on a time (`yourTimeLine`), and only when the two differ.
 *
 * The key the choice was kept under is removed at start-up and sign-out, so a phone that chose it keeps nothing of it.
 */
export const LEGACY_TIME_VIEW_KEY = 'prefs.time_view'

/** The zone this handset is set to, when the runtime can say and render it. */
export function phoneZone(): string | null {
  if (!hasIntl()) return null
  try {
    const found = Intl.DateTimeFormat().resolvedOptions().timeZone // phone-clock: shown only BESIDE the estate's, secondary (M19A §4.3), and to decide whether the header clock is drawn (§5.1)
    return isZoneName(found) ? found : null
  } catch {
    return null
  }
}

/**
 * Whether the person holding this phone reads another clock than the estate's: the phone is set to another zone. Then
 * the header carries the estate's clock (M19A §5.1) and a time they must act on shows theirs underneath (§4.3). False at
 * the estate — which is every resident who lives there, so most people never see either.
 */
export function readingAnotherClock(): boolean {
  const phone = phoneZone()
  return phone !== null && phone !== zone
}

/** "America/Jamaica" → "Jamaica time"; "America/New_York" → "New York time". */
export function clockNameOf(zoneName: string = zone): string {
  const place = zoneName.split('/').pop()?.replace(/_/g, ' ').trim()
  return place ? `${place} time` : 'the estate’s time'
}

/**
 * "America/Jamaica" → "JA"; "America/New_York" → "NY"; "America/Port_of_Spain" → "POS"; "Asia/Karachi" → "KA". The tag an
 * estate time carries in a dense place — a row, a list, a table: "9:38 AM JA" (M19A §4.2). Made from the zone's own
 * place name, as `clockNameOf` is, so no estate's zone is written into the product.
 */
export function clockTagOf(zoneName: string = zone): string {
  const words = (zoneName.split('/').pop() ?? '').split(/[\s_-]+/).filter(Boolean)
  if (words.length === 0) return 'ET'
  if (words.length === 1) return words[0]!.slice(0, 2).toUpperCase()
  return words.map((w) => w[0]!).join('').toUpperCase().slice(0, 3)
}

/** The zone times are shown in: always the estate's (M19A §3). Kept as a name for the callers that ask. */
export function displayZone(): string {
  return zone
}

/** Which zone a formatter shows `input` in: the one a caller names (a row's own site, on the guard's open shifts), else the estate's. */
function shownIn(_input: Date | string | number, zoneName: string | undefined): { zone: string; label: string } {
  return { zone: zoneName ?? zone, label: '' }
}

/* ---- an instant, broken into the site's wall clock -------------------------------------------------- */

const NOT_A_TIME: SiteParts = { year: NaN, month: NaN, day: NaN, weekday: NaN, hour: NaN, minute: NaN, second: NaN, offsetMinutes: NaN, zone: '' }

/**
 * NOTHING, written four ways. The API sends `null` for a time that has not happened yet (`actual_end`,
 * `used_at`, `cancelled_at`), and `atSiteZone` answers '' for a day it cannot read. `new Date(null)` is
 * NOT invalid — it is the epoch — so a field that is simply absent would otherwise be drawn as a real
 * time, "Dec 31, 7:00 PM", 1969 at the site. An absent time is absent, and is rendered as nothing.
 */
const isNothing = (input: unknown): boolean => input === null || input === undefined || (typeof input === 'string' && input.trim() === '')

/** An instant as it reads AT THE SITE. Everything else in this file is built from it. */
export function siteParts(input: Date | string | number, zoneName: string = zone): SiteParts {
  if (isNothing(input)) return { ...NOT_A_TIME, zone: zoneName }
  const at = input instanceof Date ? input : new Date(input)
  const ms = at.getTime()
  if (!Number.isFinite(ms)) return { ...NOT_A_TIME, zone: zoneName }

  // A zone name this runtime cannot render (a typo, a name newer than its database) is not silently read as
  // UTC — which would draw a Kingston evening as the next morning. The session's own zone answers instead.
  const used = formatterFor(zoneName) !== null || !hasIntl() ? zoneName : zone
  const formatter = formatterFor(used)
  if (formatter) {
    const found = formatter.formatToParts(at)
    const pick = (type: string): number => Number(found.find((p) => p.type === type)?.value)
    const [year, month, day, minute, second] = [pick('year'), pick('month'), pick('day'), pick('minute'), pick('second')]
    const hour = pick('hour') % 24
    if ([year, month, day, hour, minute, second].every((n) => Number.isFinite(n))) {
      const wall = Date.UTC(year, month - 1, day, hour, minute, second)
      return { year, month: month - 1, day, weekday: new Date(wall).getUTCDay(), hour, minute, second, offsetMinutes: Math.round((wall - ms) / 60_000), zone: used }
    }
  }

  const offsetMinutes = NO_ZONE_DATABASE_OFFSET[used] ?? NO_ZONE_DATABASE_OFFSET[zone] ?? 0
  const shifted = new Date(ms + offsetMinutes * 60_000)
  return {
    year: shifted.getUTCFullYear(),
    month: shifted.getUTCMonth(),
    day: shifted.getUTCDate(),
    weekday: shifted.getUTCDay(),
    hour: shifted.getUTCHours(),
    minute: shifted.getUTCMinutes(),
    second: shifted.getUTCSeconds(),
    offsetMinutes,
    zone: used,
  }
}

/** Minutes east of UTC at this instant in this zone. */
export const siteOffsetMinutes = (input: Date | string | number, zoneName: string = zone): number => siteParts(input, zoneName).offsetMinutes

/** True when both instants fall on the same calendar day AT THE SITE. */
export function isSameSiteDay(a: Date | string | number, b: Date | string | number, zoneName: string = zone): boolean {
  const p = siteParts(a, zoneName)
  const q = siteParts(b, zoneName)
  return p.year === q.year && p.month === q.month && p.day === q.day
}

/* ---- words and digits ------------------------------------------------------------------------------- */

const hour12 = (hour: number): number => (hour % 12 === 0 ? 12 : hour % 12)
const meridiem = (hour: number): string => (hour < 12 ? 'AM' : 'PM')

/** A reading that is not a time at all. Every label below answers '' for one rather than digits nobody meant. */
const unreadable = (p: SiteParts): boolean => !Number.isFinite(p.year)

/**
 * "7:00 AM" — the digits, and since M98 (12 Oct 2026) all a time ever says: "No JA after a time, ever." The estate's clock
 * is the only clock shown; a phone on another zone gets its own beside it (`yourTimeLine`), never a tag on the number.
 */
export function time12(input: Date | string | number, zoneName?: string): string {
  const where = shownIn(input, zoneName)
  const p = siteParts(input, where.zone)
  return unreadable(p) ? '' : `${hour12(p.hour)}:${two(p.minute)} ${meridiem(p.hour)}${where.label}`
}

/** "7:24" — the boards' compact clock, no meridiem. */
export function timeCompact(input: Date | string | number, zoneName?: string): string {
  const where = shownIn(input, zoneName)
  const p = siteParts(input, where.zone)
  return unreadable(p) ? '' : `${hour12(p.hour)}:${two(p.minute)}${where.label}`
}

/** "9:41:02 AM" */
export function time12s(input: Date | string | number, zoneName?: string): string {
  const where = shownIn(input, zoneName)
  const p = siteParts(input, where.zone)
  return unreadable(p) ? '' : `${hour12(p.hour)}:${two(p.minute)}:${two(p.second)} ${meridiem(p.hour)}${where.label}`
}

/**
 * "9 Oct 2026": THE ONE WAY A DATE IS WRITTEN (M98, 12 Oct 2026), the consoles' Law 5 form, so a resident reads the
 * day the way the estate office writes it. The day, the month's first three letters, the year, every time.
 */
const oneDate = (p: SiteParts): string => `${p.day} ${MONTHS[p.month]!.slice(0, 3)} ${p.year}`

/**
 * "9 Oct 2026" (M98's one form). Under the fidelity harness the board's own "Friday, September 4" is drawn, so the
 * harness still measures the board.
 */
export function dateLong(input: Date | string | number, zoneName?: string): string {
  const p = siteParts(input, shownIn(input, zoneName).zone)
  if (unreadable(p)) return ''
  return isFidelity ? `${DAYS[p.weekday]}, ${MONTHS[p.month]} ${p.day}` : oneDate(p)
}

/**
 * "9 Oct 2026" (M98's one form; it read "Fri, Sep 4"). The name is kept for its callers. Under the fidelity harness
 * the boards' "Fri, Sep 4" is drawn.
 */
export function dateWeekday(input: Date | string | number, zoneName?: string): string {
  const p = siteParts(input, shownIn(input, zoneName).zone)
  // `MONTHS[NaN]` is undefined, and `.slice()` on it THREW: a missing timestamp took a screen down.
  if (unreadable(p)) return ''
  return isFidelity ? `${DAYS[p.weekday]!.slice(0, 3)}, ${MONTHS[p.month]!.slice(0, 3)} ${p.day}` : oneDate(p)
}

/**
 * "9 Oct 2026": the date a person reads (M98's one form, always with its year; it read "Fri, Sep 4" and left the year
 * to the caller). Under the fidelity harness the boards' own "Sep 4" is drawn, so the harness still measures the board.
 */
export function dateShort(input: Date | string | number, zoneName?: string): string {
  const p = siteParts(input, shownIn(input, zoneName).zone)
  if (unreadable(p)) return ''
  return isFidelity ? `${MONTHS[p.month]!.slice(0, 3)} ${p.day}` : oneDate(p)
}

/* ---- a time a person reads: the digits, no tag (M98) ------------------------------------------------------- */

/**
 * NO TAG, EVER (M98, 12 Oct 2026: "No JA after a time, ever"). Every time a person reads used to end in the estate's
 * tag ("4:00 PM JA", M19A §4.2); the consoles never wrote one and now neither does a phone. The text comes back as it
 * went in, so every caller that wrapped a time in it says the time alone.
 */
export function withClockTag(text: string, _zoneName?: string): string {
  return text
}

/** "9:38 AM": in dense places, rows, lists, tables. '' for a time that is not one. */
export function timeTag(input: Date | string | number, zoneName?: string): string {
  return withClockTag(time12(input, zoneName), zoneName)
}

/** "9:38 AM": where there is room too (it read "9:38 AM Jamaica time"; M98, one way to write a time). */
export function timeWords(input: Date | string | number, zoneName?: string): string {
  return time12(input, zoneName)
}

/** "9 Oct 2026, 9:38 AM": a day and a time, the consoles' form (M98). Under the fidelity harness "Sep 4, 9:38 AM". */
export function dayTimeTag(input: Date | string | number, zoneName?: string): string {
  const d = dateShort(input, zoneName)
  return d ? `${d}, ${timeTag(input, zoneName)}` : ''
}

/** "9:00 – 11:00 AM", or "11:00 PM – 1:00 AM": one meridiem when both ends share it. */
function rangeBare(from: Date | string | number, to: Date | string | number, zoneName?: string): string {
  const a = siteParts(from, zoneName ?? zone)
  const b = siteParts(to, zoneName ?? zone)
  if (unreadable(a) || unreadable(b)) return ''
  const start = meridiem(a.hour) === meridiem(b.hour) ? timeCompact(from, zoneName) : time12(from, zoneName)
  return `${start} – ${time12(to, zoneName)}`
}

/** "9:00 – 11:00 AM", or "11:00 PM – 1:00 AM": a range, no tag (M98). */
export function rangeTag(from: Date | string | number, to: Date | string | number, zoneName?: string): string {
  return withClockTag(rangeBare(from, to, zoneName), zoneName)
}

/** "8:58 – 11:00 AM": a range where there is room too (it read "… Jamaica time"; M98, one way to write a time). */
export function rangeWords(from: Date | string | number, to: Date | string | number, zoneName?: string): string {
  return rangeBare(from, to, zoneName)
}

const HOUR_MS = 60 * 60_000

/**
 * A RECENT EVENT IN WORDS THAT BELONG TO NO CLOCK (M19A §4.1) — "just now", "a minute ago", "2 minutes ago", "an hour
 * ago", "5 hours ago" — and, once the estate's midnight has passed, "yesterday at 4:15 PM". Anything older, or still to
 * come, is the day and the time in the one form: "4 Sep 2026, 9:38 AM". `at` is now: the caller's clock, so a test can
 * name it. The consoles say "just now" and "2 minutes ago" the same way.
 */
export function sinceWords(input: Date | string | number, at: Date | string | number, zoneName: string = zone): string {
  if (isNothing(input)) return ''
  const then = input instanceof Date ? input.getTime() : new Date(input).getTime()
  const current = at instanceof Date ? at.getTime() : new Date(at).getTime()
  if (!Number.isFinite(then) || !Number.isFinite(current)) return ''
  const ago = current - then
  // A clock a minute or two apart from the server's must not print "in 1 minute" for something that has just happened.
  if (ago < -2 * 60_000 || ago >= 48 * HOUR_MS) return dayTimeTag(input, zoneName)
  if (ago < 60_000) return 'just now'
  const minutes = Math.floor(ago / 60_000)
  if (minutes < 60) return minutes === 1 ? 'a minute ago' : `${minutes} minutes ago`
  if (isSameSiteDay(then, current, zoneName)) {
    const hours = Math.floor(ago / HOUR_MS)
    return hours === 1 ? 'an hour ago' : `${hours} hours ago`
  }
  return siteDayIso(then, 1, zoneName) === siteDayIso(current, 0, zoneName) ? `yesterday at ${timeTag(input, zoneName)}` : dayTimeTag(input, zoneName)
}

/**
 * THE VIEWER'S OWN CLOCK, SECONDARY (M19A §4.3), under a time they must act on — a pass, a meeting, an election closing:
 * "11:58 PM – 2:00 AM your time (27 Sep 2026 – 28 Sep 2026)", its days in the one form (M98). Null when the phone keeps
 * the estate's clock, which is every resident who lives there.
 */
export function yourTimeLine(from: Date | string | number, to?: Date | string | number | null): string | null {
  const phone = phoneZone()
  if (phone === null || phone === zone) return null
  const a = siteParts(from, phone)
  if (unreadable(a)) return null
  const dayOf = (p: SiteParts) => oneDate(p)
  if (to === undefined || to === null || isNothing(to)) return `${time12(from, phone)} your time (${dayOf(a)})`
  const b = siteParts(to, phone)
  if (unreadable(b)) return `${time12(from, phone)} your time (${dayOf(a)})`
  const days = a.year === b.year && a.month === b.month && a.day === b.day ? dayOf(a) : `${dayOf(a)} – ${dayOf(b)}`
  return `${time12(from, phone)} – ${time12(to, phone)} your time (${days})`
}

/** "Estate time · 9:38 AM" — the header's quiet clock (M19A §5.1), for a phone on another zone; null at the estate. */
export function estateClockLine(at: Date | string | number): string | null {
  return readingAnotherClock() ? `Estate time · ${time12(at)}` : null
}

/**
 * The one explanation (M19A §5.2), word for word — on the card Home shows once to a phone on another zone, and again in
 * Profile → Language & accessibility for anyone who wants it.
 *
 * UNUSED (M98, 12 Oct 2026): no screen draws it. Its Home card left in M46, and Settings imports it without drawing
 * it. Kept, and listed for the hub to decide (Hassam: "Someone wrote them for a reason"). If it is ever drawn, Hassam's
 * rule applies: a clock's name only where the reader might not be at the estate.
 */
export function estateClockExplained(zoneName: string = zone): string {
  return `This estate is on ${clockNameOf(zoneName)}. Times you see and times you enter are the estate’s clock, so your visitors and the guards all agree.`
}

/**
 * "Jamaica time: it is 9:38 AM there now." — for beside a time field, and ONLY on a phone set to another zone (M19A
 * §5.3): where the reader might not be at the estate, so the clock is named (Hassam's rule, M98). UNUSED: no screen
 * draws it today; listed for the hub.
 */
export function estateNowLine(at: Date | string | number): string | null {
  return readingAnotherClock() ? `${clockNameOf()}: it is ${time12(at)} there now.` : null
}

/* ---- the minute, for a clock drawn on screen ------------------------------------------------------------------ */

const minuteWatchers = new Set<() => void>()
let minuteTimer: ReturnType<typeof setTimeout> | null = null
const minuteNow = (): number => Math.floor(Date.now() / 60_000)

function armMinute(): void {
  minuteTimer = setTimeout(() => {
    for (const watch of minuteWatchers) watch()
    armMinute()
  }, 60_000 - (Date.now() % 60_000) + 50)
}

function watchMinute(listener: () => void): () => void {
  minuteWatchers.add(listener)
  if (minuteTimer === null) armMinute()
  return () => {
    minuteWatchers.delete(listener)
    if (minuteWatchers.size === 0 && minuteTimer !== null) {
      clearTimeout(minuteTimer)
      minuteTimer = null
    }
  }
}

/** The minute it is now, redrawn as each one turns — one timer for every clock on screen. */
export const useMinute = (): number => useSyncExternalStore(watchMinute, minuteNow, minuteNow)

/** The site's zone, redrawn when the server's answer moves it. */
export const useSiteZone = (): string => useSyncExternalStore((l) => watchSiteZone(() => l()), siteZone, siteZone)

/** "4h 12m" — a LENGTH of time. It has no zone. */
export function duration(ms: number): string {
  const total = Math.max(0, Math.round(ms / 60_000))
  const h = Math.floor(total / 60)
  return h ? `${h}h ${two(total % 60)}m`.replace(/ 0(\d)m$/, ' $1m') : `${total}m`
}

/** A roster's name for a shift, from when it starts at the site: the API gives times, the boards say "Day Shift". */
export function shiftName(rosteredStart: Date | string | number, zoneName: string = zone): 'Day Shift' | 'Night Shift' {
  const h = siteParts(rosteredStart, zoneName).hour
  return h >= 5 && h < 17 ? 'Day Shift' : 'Night Shift'
}

/* ---- calendar days, and what the phone SENDS -------------------------------------------------------- */

/** The calendar day at the site that `at` falls on, `offsetDays` days on. "YYYY-MM-DD". */
export function siteDayIso(at: Date | string | number, offsetDays = 0, zoneName: string = zone): string {
  const p = siteParts(at, zoneName)
  if (!Number.isFinite(p.year)) return ''
  const day = new Date(Date.UTC(p.year, p.month, p.day + offsetDays))
  return `${day.getUTCFullYear()}-${two(day.getUTCMonth() + 1)}-${two(day.getUTCDate())}`
}

const DAY_ISO = /^(\d{4})-(\d{2})-(\d{2})/

/**
 * OUTBOUND. A human picked a WALL-CLOCK time — "Saturday, 2 PM" — meaning the clock on the wall AT THE
 * SITE. This turns it into the instant that is, written as ISO-8601 with the site's own offset for that
 * day, so the server stores the moment the resident meant whatever zone the phone is set to.
 *
 * `minutes` is minutes past midnight; 1440 is midnight at the END of the day and rolls into the next one.
 *
 * ON A DAYLIGHT-SAVING CHANGE, THE SERVER'S RULE (M11 §4.4). The zone's offset a day before and a day after are
 * tried, and the one that reads back as the time asked for wins:
 *   an ordinary day      both agree
 *   a time that happens twice (01:30 as the clocks go back)   the FIRST, as PHP takes it
 *   a time that never happens (02:30 as the clocks go forward) the offset from before the change, which lands
 *                        just after the gap (03:30) — PHP's answer too. This used to land an hour BEFORE it (01:30),
 *                        so a pass asked for from 02:30 would have opened at 01:30.
 */
export function atSiteZone(dayIso: string, minutes: number, zoneName: string = zone): string {
  const m = DAY_ISO.exec(dayIso)
  if (!m) return ''
  const roll = Math.floor(minutes / DAY_MINUTES)
  const rest = minutes - roll * DAY_MINUTES
  const wall = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + roll, Math.floor(rest / 60), rest % 60, 0)
  const DAY_MS = DAY_MINUTES * 60_000
  const before = siteOffsetMinutes(wall - DAY_MS, zoneName)
  const after = siteOffsetMinutes(wall + DAY_MS, zoneName)
  const readsAsAsked = (offsetMinutes: number): boolean => {
    const p = siteParts(wall - offsetMinutes * 60_000, zoneName)
    return p.hour === Math.floor(rest / 60) && p.minute === rest % 60
  }
  let offset = readsAsAsked(before) || !readsAsAsked(after) ? before : after
  if (!Number.isFinite(offset)) offset = 0
  const shown = new Date(wall)
  const sign = offset >= 0 ? '+' : '-'
  const abs = Math.abs(offset)
  return (
    `${shown.getUTCFullYear()}-${two(shown.getUTCMonth() + 1)}-${two(shown.getUTCDate())}` +
    `T${two(shown.getUTCHours())}:${two(shown.getUTCMinutes())}:00${sign}${two(Math.floor(abs / 60))}:${two(abs % 60)}`
  )
}

/**
 * Noon at the site on a CALENDAR DAY — the instant to hand a formatter when all there is is a date
 * ("2026-09-20"), so no zone can ever draw it as the day before or the day after.
 */
export const siteNoon = (dayIso: string, zoneName: string = zone): string => atSiteZone(dayIso, 12 * 60, zoneName)

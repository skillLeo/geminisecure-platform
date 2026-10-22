/**
 * Visitor passes — the pure logic, tested without a phone.
 *
 * A PASS IS SIGNED BY THE SERVER. One made on the phone admits nobody, so an invitation written
 * with no connection is a DRAFT — kept on the phone, labelled as a draft everywhere it appears,
 * and never shown with a QR code — until the server has signed it.
 *
 * TIMES ARE THE ESTATE'S. The resident picks "today, 2:00–4:00 PM" meaning the clock on the wall
 * where they live; the window is built in the SITE's zone (`atSiteZone`) and sent as the
 * instant that is — never in the phone's own zone, so a pass written from abroad still opens the
 * gate at 2 PM at home.
 */

import { atSiteZone, dateShort, isSameSiteDay, normalisePhone, phoneProblem, phoneWords, rangeTag, sinceWords, siteDayIso, siteNoon, siteParts, siteZone, time12, timeWords, type SiteParts } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

export type Pass = Api.VisitorPassesIndexResponse['items'][number]
export type Category = 'single' | 'recurring' | 'contractor' | 'delivery'

/** The four kinds of pass, as the switch at the top of Invite visitor names them (board resident-app-07). */
export const CATEGORIES: { value: Category; label: string }[] = [
  { value: 'single', label: 'Single visit' },
  { value: 'recurring', label: 'Recurring' },
  { value: 'contractor', label: 'Contractor' },
  { value: 'delivery', label: 'Delivery' },
]

/** A recurring or contractor pass runs from a first day to a last; a single visit or a delivery is one day. */
export const spansDays = (category: Category): boolean => category === 'recurring' || category === 'contractor'

/**
 * HOW THE VISITOR ARRIVES (M42 §5 #6; M47 §7.1, M48 §2.3): Walk in · Drive in · Ride in, in that order. Only Drive in
 * asks for a plate — a passenger in somebody else's car (Ride in) does not know it, and nobody walking has one. Walk in
 * and Ride in are both sent as the server's `no_vehicle`, and the pass keeps which it was (`arrival`, M50 §6.3), so the
 * pass screen says "Walk in" or "Ride in" — never "On foot or by taxi".
 */
export type Arrival = 'walk' | 'drive' | 'ride'
export const ARRIVALS: { value: Arrival; label: string }[] = [
  { value: 'walk', label: 'Walk in' },
  { value: 'drive', label: 'Drive in' },
  { value: 'ride', label: 'Ride in' },
]
export const isArrival = (value: unknown): value is Arrival => ARRIVALS.some((a) => a.value === value)

/**
 * How a pass says its visitor comes (M50 §6.10): "Drive in · PC 4471", "Walk in", "Ride in" — and, for a pass issued
 * before the pass kept which, "Walk in or ride in". Null when it says neither a plate nor "no vehicle".
 */
export function arrivalWords(pass: { vehicle_plate?: string | null; no_vehicle?: boolean | null; arrival?: string | null }): string | null {
  if (pass.vehicle_plate) return `Drive in · ${pass.vehicle_plate}`
  if (!pass.no_vehicle) return null
  return pass.arrival === 'walk' ? 'Walk in' : pass.arrival === 'ride' ? 'Ride in' : 'Walk in or ride in'
}

export const CATEGORY_LABEL: Record<string, string> = { single: 'Single visit', recurring: 'Recurring pass', contractor: 'Contractor pass', delivery: 'Delivery pass', resident: 'Your e-Pass' }

export const MAX_PASS_DAYS = 31

/**
 * The calendar day at the site, `offset` days from the day `at` falls on there — named by its DATE, always ("Wed, Oct 7"),
 * never "Today" or "Tomorrow" (M47 §7.2, M48 §2.3).
 */
export function siteDay(at: Date, offset = 0): { iso: string; label: string } {
  const iso = siteDayIso(at, offset)
  return { iso, label: dateShort(siteNoon(iso)) }
}

/** "2026-09-04" + 14:00 → the instant 2 PM is at the site, with the site’s own offset. */
export const atSite = (dayIso: string, minutes: number): string => atSiteZone(dayIso, minutes)

/** A label for a time of day the resident picked — a clock face, not a moment, so any day does. */
export const clockLabel = (minutes: number): string => time12(atSite('2026-01-01', minutes))

/* ── the window's chips (M12 §3.1) ─────────────────────────────────────────
 *
 * "PASS NOT YET VALID", AND WHY. The zone was never wrong: a pass for the next half hour, sent the way the phone sends
 * it, is admitted at every hour of the estate's day (PassForTheNextHalfHourTest on the server). The FORM was: it opened
 * on 2:00 – 4:00 PM whatever the time, and offered only half-hour slots, so a resident at 10:12 who wanted their guest
 * in now could not say "now" — they chose 10:30, the pass read "Today · 10:30 – 11:00 AM", and the gate rightly
 * refused it as not yet valid for eighteen minutes. So on today the first chip is NOW, the slots already gone are not
 * offered, and the form opens on now, for two hours.
 */

/** 6:00 AM … 11:30 PM — the slots offered for a day that is not today. */
export const SLOTS = Array.from({ length: 36 }, (_, i) => 6 * 60 + i * 30)
/** Every half hour of the day — today's slots still to come, at 2 AM too. */
const DAY_SLOTS = Array.from({ length: 48 }, (_, i) => i * 30)
/** The last minute of the day: a short pass ends on the day it starts. */
export const LAST_MINUTE = 23 * 60 + 59

/** The minute of the day it is AT THE SITE. */
export function siteMinute(at: Date): number {
  const p = siteParts(at)
  return p.hour * 60 + p.minute
}

export type TimeChip = { value: number; label: string }

/**
 * "From": on today, the minute it is now — named by its time ("1:28 AM"), never "Now" (M47 §7.2) — then the slots still
 * to come; on any other day, the day's slots.
 */
export function fromChips(dayIso: string, at: Date): TimeChip[] {
  if (dayIso !== siteDayIso(at)) return SLOTS.map((t) => ({ value: t, label: clockLabel(t) }))
  const nowMinute = siteMinute(at)
  return [{ value: nowMinute, label: clockLabel(nowMinute) }, ...DAY_SLOTS.filter((t) => t > nowMinute).map((t) => ({ value: t, label: clockLabel(t) }))]
}

/** "Until": after the start for a pass that ends the same day (and 11:59 PM, so a late start still has an end); any slot for a long one. */
export function untilChips(fromMinutes: number, long: boolean): TimeChip[] {
  const slots = [...DAY_SLOTS, LAST_MINUTE]
  return (long ? slots.filter((t) => t >= 6 * 60) : slots.filter((t) => t > fromMinutes)).map((t) => ({ value: t, label: clockLabel(t) }))
}

/** The form opens on now, for two hours — to the half hour, never past the end of the day. */
export function defaultWindow(at: Date): { fromMinutes: number; toMinutes: number } {
  const nowMinute = siteMinute(at)
  return { fromMinutes: nowMinute, toMinutes: Math.min(Math.ceil((nowMinute + 120) / 30) * 30, LAST_MINUTE) }
}

/**
 * The Arrival window's time box: the real times, both ends with their meridiem, an en dash with a space either side —
 * "1:28 AM – 5:30 AM" (M47 §7.2, M48 §2.3), as Home writes a window. Never "Now": a pass that opens as soon as it is
 * signed (M12 §3.1) shows the minute it is now.
 */
export function windowLabel(input: Pick<InviteInput, 'fromDay' | 'fromMinutes' | 'toMinutes'>, at: Date): string {
  const opensNow = input.fromDay === siteDayIso(at) && input.fromMinutes <= siteMinute(at)
  return `${clockLabel(opensNow ? siteMinute(at) : input.fromMinutes)} – ${clockLabel(input.toMinutes)}`
}

/**
 * The Arrival window's day box: the real date, "7 Oct 2026" — never "Today" or "Tomorrow" (M47 §7.2) — and a pass that
 * spans days by its two dates, as compactly as the box allows, in the one order (M98, day month year): "4–11 Sep 2026",
 * "28 Sep – 5 Oct 2026", "28 Dec 2026 – 3 Jan 2027".
 */
export function dayLabel(input: Pick<InviteInput, 'fromDay' | 'toDay'>, at: Date): string {
  void at
  if (input.toDay === input.fromDay) return dateShort(siteNoon(input.fromDay))
  const a = siteParts(siteNoon(input.fromDay))
  const b = siteParts(siteNoon(input.toDay))
  const month = (p: SiteParts) => LONG_MONTHS[p.month]!.slice(0, 3)
  if (a.year !== b.year) return `${a.day} ${month(a)} ${a.year} – ${b.day} ${month(b)} ${b.year}`
  return a.month === b.month ? `${a.day}–${b.day} ${month(b)} ${b.year}` : `${a.day} ${month(a)} – ${b.day} ${month(b)} ${b.year}`
}

/**
 * THE FOOTER — the design's two lines, with the visitor's own first name where the board has "Marcia" (M42 §5 #6): "they",
 * never a guessed "she"; "Your visitor" until a name is typed.
 *
 * MADE TRUE (M47 §7.5, M48 §2.3b). The design's "will get this pass by SMS and WhatsApp automatically" promised what
 * nothing does: `visitor_passes.store` signs and stores the pass and tells the household's own phones, and that is all —
 * no SMS provider is connected and the server has no WhatsApp channel. The resident sends it themselves, from the pass
 * screen that opens next (Send to my visitor). So the line says exactly that, and keeps the design's second half.
 */
export function footerLine(name: string): string {
  const first = name.trim().split(/\s+/)[0] ?? ''
  return first
    ? `${first} gets this pass when you send it on the next screen: they just show it at the gate, no phone call needed.`
    : 'Your visitor gets this pass when you send it on the next screen: they just show it at the gate, no phone call needed.'
}

/**
 * The start, kept sensible when the day changes: a start that was "now" becomes the next slot on another day, and a
 * start already gone on today becomes now. The end follows when it would no longer be after the start.
 */
export function onDay(input: InviteInput, at: Date): InviteInput {
  const today = input.fromDay === siteDayIso(at)
  const nowMinute = siteMinute(at)
  let from = input.fromMinutes
  if (!today && !SLOTS.includes(from)) from = Math.max(6 * 60, Math.min(Math.ceil(from / 30) * 30, SLOTS[SLOTS.length - 1]!))
  if (today && from < nowMinute) from = nowMinute
  const short = input.category === 'single' || input.category === 'delivery'
  const to = short && input.toMinutes <= from ? Math.min(Math.ceil((from + 120) / 30) * 30, LAST_MINUTE) : input.toMinutes
  return { ...input, fromMinutes: from, toMinutes: to }
}

export type Draft = {
  id: string
  category: Category
  visitor_name: string
  visitor_phone: string
  purpose: string
  vehicle_plate: string
  /** Walk in or Ride in — the server's "no vehicle" (M18C §3.1). Absent on a draft written before the choice existed. */
  no_vehicle?: boolean
  /** Which it was — walk, drive or ride (M50 §6.3). Absent on a draft written before the pass kept it. */
  arrival?: Arrival
  valid_from: string
  valid_to: string
  createdAt: string
  /** The phone's own clock when it was written, with its offset — sent with it, as every write's X-Device-Time. */
  deviceTime?: string
  /** The server's refusal, in its words, when it would not sign this draft: it is then not sent again by itself. */
  problem?: string | null
  /** Whose it is (`account:<id>`), so a sign-out nobody chose can keep it for the same person (draftStore.ts). */
  owner?: string
}

/**
 * `arrival` is chosen, never assumed — null until it is (M18C §3.1: a plate, or the explicit "no vehicle"; never neither).
 * `plate` is sent for Drive in only.
 */
export type InviteInput = { category: Category; name: string; phone: string; purpose: string; arrival: Arrival | null; plate: string; fromDay: string; toDay: string; fromMinutes: number; toMinutes: number }
export type InviteErrors = Partial<Record<'name' | 'phone' | 'purpose' | 'arrival' | 'plate' | 'window', string>>

export function validateInvite(input: InviteInput, at: Date): InviteErrors {
  const errors: InviteErrors = {}
  if (input.name.trim().length < 2) errors.name = "Enter the visitor's name: the guard reads it at the gate."
  // Nothing is optional (M18C): the visitor's number, read the server's way.
  // The example is the estate's own country's (M32 F7).
  // "The guard can call them at the gate" was struck from this screen (M42 §5 #6), here as under the field.
  if (input.phone.trim() === '') errors.phone = `Enter the visitor's phone number. ${phoneWords().example}`
  else {
    const problem = phoneProblem(input.phone)
    if (problem) errors.phone = problem
  }
  // M12 §2.13: required — the guard asks what a visitor is there for, and the pass should already say. Chosen from the
  // list since M42 (visitPurposes.ts).
  if (input.purpose.trim().length < 2) errors.purpose = 'Choose what the visit is for.'
  if (input.arrival === null) errors.arrival = 'Choose how they arrive.'
  else if (input.arrival === 'drive' && input.plate.trim().length < 2) errors.plate = 'Enter the plate, or choose Walk in or Ride in.'
  const from = new Date(atSite(input.fromDay, input.fromMinutes)).getTime()
  const to = new Date(atSite(input.toDay, input.toMinutes)).getTime()
  if (to <= from) errors.window = 'The pass must end after it starts.'
  // M19A §6: refused against the ESTATE's clock, and the estate's time now is said, so a resident abroad sees why.
  else if (to <= at.getTime()) errors.window = `That window has already passed at the estate: it is ${timeWords(at)} there now. Choose a later time.`
  else if (to - from > MAX_PASS_DAYS * 86_400_000) errors.window = `A pass can last ${MAX_PASS_DAYS} days at most. Choose an earlier end date.`
  return errors
}

export const toRequest = (input: InviteInput): Api.VisitorPassesStoreRequest => ({
  category: input.category,
  visitor_name: input.name.trim(),
  visitor_phone: normalisePhone(input.phone) ?? input.phone.trim(),
  // Required (M12 §2.13): `validateInvite` refuses a pass without one, and the server does too.
  purpose: input.purpose.trim(),
  // Drive in sends its plate; Walk in and Ride in send the server's "no vehicle" — one or the other, never both (M18C §3.1).
  ...(input.arrival === 'drive' ? { vehicle_plate: input.plate.trim().toUpperCase() } : { no_vehicle: true }),
  // …and which it was, so the pass can say so (M50 §6.3).
  ...(input.arrival ? { arrival: input.arrival } : null),
  valid_from: atSite(input.fromDay, input.fromMinutes),
  valid_to: atSite(input.toDay, input.toMinutes),
})

/**
 * "7 Oct 2026, 2:00 – 4:00 PM" · "4 Sep 2026 – 30 Sep 2026" — the estate's clock in the one form, no tag (M98); the day
 * by its date, never "Today" (M47 §7.2).
 */
export function windowText(from: string, to: string, at: Date): string {
  void at
  if (!isSameSiteDay(from, to)) return `${dateShort(from)} – ${dateShort(to)}`
  return `${dateShort(from)}, ${rangeTag(from, to)}`
}

/*
 * THE E-PASS, HALF AN HOUR AT A TIME (M47 §6; the user's answer, 7 October: "48 slots, a day"). What the screen shows
 * opens the gate for thirty minutes, so a screenshot of it is dead within the half hour. The server hands the phone a
 * day of them (`slots`, oldest first, each its own signed token), so a gate with no signal still gets the right one;
 * the phone shows the half hour this moment is in. When the day of them is spent — the app not opened with a
 * connection for a day — there is nothing to show, and the screen says to connect once.
 */
export type Epass = Api.EpassShowResponse
type Slot = Api.EpassShowResponse['slots'][number]

/**
 * A HELPER'S E-PASS THAT IS OFF (8 Oct 2026): the household paused them, their end date passed, or no half hour of their
 * days and hours falls in the next day. The server's sentence says which and who can change it — and the copy kept on
 * the phone for a gate with no signal must not be shown instead (EpassKeeper forgets it).
 */
export const EPASS_OFF = ['access_paused', 'access_ended', 'outside_hours'] as const

export function epassOff(error: unknown): string | null {
  const e = error as { code?: unknown; message?: unknown } | null | undefined
  return e && typeof e.code === 'string' && (EPASS_OFF as readonly string[]).includes(e.code) && typeof e.message === 'string' ? e.message : null
}

/** The e-pass as the screen shows it now — the current half hour's window and token — or why there is none. */
export function epassNow(epass: (Omit<Epass, 'slots'> & { slots?: Slot[] }) | null | undefined, at: Date): { pass: Pass | null; ranOut: boolean } {
  if (!epass) return { pass: null, ranOut: false }
  const t = at.getTime()
  const ends = (iso: string) => new Date(iso).getTime()
  const slots = Array.isArray(epass.slots) ? epass.slots : []
  // An older server sends one window and no slots: that window, while it lasts.
  if (slots.length === 0) return ends(epass.valid_to) <= t ? { pass: null, ranOut: true } : { pass: epass, ranOut: false }
  if (ends(slots[slots.length - 1]!.valid_to) <= t) return { pass: null, ranOut: true }
  // The half hour that holds now — or, on a phone whose clock is behind the server's, the first: the gate's clock decides.
  const slot = [...slots].reverse().find((s) => ends(s.valid_from) <= t) ?? slots[0]!
  return { pass: { ...epass, valid_from: slot.valid_from, valid_to: slot.valid_to, token: slot.token }, ranOut: false }
}

export type Groups = { now: Pass[]; upcoming: Pass[]; history: Pass[] }

/** Active = its window holds this moment · Upcoming = it has not started · History = used, cancelled, expired. The e-pass is not a visitor. */
export function groupPasses(items: Pass[], at: Date): Groups {
  const t = at.getTime()
  const visitors = items.filter((p) => p.category !== 'resident')
  const live = visitors.filter((p) => p.status === 'active' && new Date(p.valid_to).getTime() >= t)
  const byStart = (p: Pass, q: Pass) => new Date(p.valid_from).getTime() - new Date(q.valid_from).getTime()
  return {
    now: live.filter((p) => new Date(p.valid_from).getTime() <= t).sort(byStart),
    upcoming: live.filter((p) => new Date(p.valid_from).getTime() > t).sort(byStart),
    history: visitors.filter((p) => !live.includes(p)).sort((p, q) => new Date(q.used_at ?? q.cancelled_at ?? q.valid_to).getTime() - new Date(p.used_at ?? p.cancelled_at ?? p.valid_to).getTime()),
  }
}

/** What happened to a pass, in the resident's words — from what the server tells a resident, which is not when a visitor LEFT. */
// No red: a cancelled pass is history, like an expired one — red is urgent and life-safety only (M12 §4.4, D-185).
export function passOutcome(p: Pass, at: Date): { word: string; tone: 'green' | 'amber' | 'slate'; detail: string } {
  if (p.status === 'used') return { word: 'Arrived', tone: 'green', detail: p.used_at ? `Admitted at the gate · ${sinceWords(p.used_at, at)}` : 'Admitted at the gate' }
  if (p.status === 'cancelled') return { word: 'Cancelled', tone: 'slate', detail: p.cancelled_at ? `You cancelled it · ${dateShort(p.cancelled_at)}` : 'You cancelled it' }
  if (p.status === 'expired' || new Date(p.valid_to).getTime() < at.getTime()) return { word: 'Expired', tone: 'slate', detail: `Not used · ended ${dateShort(p.valid_to)}` }
  if (new Date(p.valid_from).getTime() > at.getTime()) return { word: 'Upcoming', tone: 'amber', detail: windowText(p.valid_from, p.valid_to, at) }
  return { word: 'Active', tone: 'green', detail: windowText(p.valid_from, p.valid_to, at) }
}

export const initialsOf = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('')

/** The message sent with a pass when the server could not be asked for its own wording (no connection). */
/*
 * THE WORDS THAT GO WITH A PASS (M18F §4) — the caption under the picture, and the words-only version for a text
 * message. The server writes them when there is a connection (PassInvitation.php); these are its words, for when there
 * is not. The client's rules: the date once; the host's name; the code standing out (*CODE* is bold in WhatsApp); no
 * app jargon. The text message keeps to the plain GSM alphabet so it stays inside two segments.
 *
 * NO CLOCK NAMED, AND THE ONE DATE FORM (Hassam, M98, 12 Oct 2026): "Everything on a pass is estate time by
 * definition: the estate is in Jamaica, the gate is in Jamaica, the visitor arrives in Jamaica." So no "Jamaica time",
 * and the date is "27 Sep 2026" as everywhere else, where it said "Sunday 27 September". The server's words are to
 * follow (docs/handoff/RESIDENT_APP.md, "For the hub"); until they do, the two differ in just these two ways.
 */

export type InvitationPlace = { estate: string; unit: string | null; phase: string | null; host: string; zone?: string }

const LONG_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const tidyWords = (text: string | null | undefined): string => (text ?? '').trim().replace(/\s+/g, ' ')

/** "Phase 2 · Lot 47" (the session's reference) → the lot and its phase. */
export function unitAndPhase(reference: string | null | undefined): { unit: string | null; phase: string | null } {
  const parts = tidyWords(reference).split(' · ').map((part) => part.trim()).filter(Boolean)
  if (parts.length === 0) return { unit: null, phase: null }
  return { unit: parts[parts.length - 1]!, phase: parts.length > 1 ? parts[0]! : null }
}

export function passInvitation(p: Pick<Pass, 'category' | 'visitor_name' | 'code' | 'valid_from' | 'valid_to'>, at: InvitationPlace): { message: string; sms: string } {
  const zone = at.zone ?? siteZone()
  const a = siteParts(p.valid_from, zone)
  const b = siteParts(p.valid_to, zone)
  const name = tidyWords(p.visitor_name) || 'there'
  const host = tidyWords(at.host)
  const estate = tidyWords(at.estate)
  const unit = tidyWords(at.unit)
  const phase = tidyWords(at.phase)
  const place = `${estate}${unit ? `, ${unit}` : ''}${phase && unit ? ` (${phase})` : ''}`
  const placeShort = `${estate}${unit ? `, ${unit}` : ''}`

  const hm = (q: SiteParts) => `${q.hour % 12 === 0 ? 12 : q.hour % 12}:${String(q.minute).padStart(2, '0')}`
  const mer = (q: SiteParts) => (q.hour < 12 ? 'AM' : 'PM')
  const range = (dash: string) => (mer(a) === mer(b) ? `${hm(a)}${dash}${hm(b)} ${mer(b)}` : `${hm(a)} ${mer(a)}${dash}${hm(b)} ${mer(b)}`)
  // "27 Sep 2026": the one way a date is written (M98), the same in the caption and the text message.
  const dated = (q: SiteParts) => `${q.day} ${LONG_MONTHS[q.month]!.slice(0, 3)} ${q.year}`
  const sameDay = a.year === b.year && a.month === b.month && a.day === b.day

  const when = sameDay ? `${dated(a)}, ${range(' – ')}` : `${dated(a)}, ${hm(a)} ${mer(a)} to ${dated(b)}, ${hm(b)} ${mer(b)}`
  const whenSms = sameDay ? `${dated(a)}, ${range('-')}` : `${dated(a)}, ${hm(a)} ${mer(a)} to ${dated(b)}, ${hm(b)} ${mer(b)}`
  const sent = host ? `\n\nSent by ${host}` : ''
  const sentSms = host ? ` Sent by ${host}.` : ''
  const code = p.code

  const [hello, helloSms, gate] =
    p.category === 'recurring'
      ? [`Hi ${name}, here is your pass for ${place}.`, `Hi ${name} - your pass for ${placeShort}, valid`, `Show this pass at the gate each time you come, or give the guard the code *${code}*.`]
      : p.category === 'contractor'
        ? [`Hello ${name}, you're booked to work at ${place}.`, `Hello ${name} - you're booked to work at ${placeShort} on`, `Show this pass at the gate, or give the guard the code *${code}*. Please have your ID ready.`]
        : p.category === 'delivery'
          ? [`Hello ${name}, a delivery to ${place} is expected.`, `Hello ${name} - a delivery to ${placeShort} is expected`, `Show this pass at the gate, or give the guard the code *${code}*.`]
          : [`Hi ${name}, you're expected at ${place}.`, `Hi ${name} - you're expected at ${placeShort} on`, `Show this pass at the gate, or give the guard the code *${code}*.`]

  const message = `${hello}\n\n${p.category === 'recurring' && !sameDay ? 'Valid from ' : ''}${when}.\n\n${gate}${sent}`
  const smsGate = p.category === 'contractor' ? ` Give the guard the code ${code} at the gate, with your ID.` : ` Give the guard the code ${code} at the gate.`
  const sms = `${helloSms} ${p.category === 'recurring' && !sameDay ? 'from ' : ''}${whenSms}.${smsGate}${sentSms}`
  return { message, sms }
}

/** The caption when the server could not be asked for its own (no connection): the same words it would write. */
export const shareText = (p: Pick<Pass, 'category' | 'visitor_name' | 'code' | 'valid_from' | 'valid_to'>, at: InvitationPlace): string => passInvitation(p, at).message

/*
 * WHY A SAVED COPY IS ON SCREEN (B123, D-M074). A saved list is shown both with no signal and when the phone is
 * online and the estate's server failed or was slow. The lead said "No connection" for both — untrue on the second,
 * and it sent a resident hunting for a signal they already had. The same words the amenities screens use.
 */
export const staleLeadOf = (online: boolean): string => (online ? 'This could not be refreshed just now' : 'No connection')

/*
 * THE TWO QUESTIONS ON THESE SCREENS, asked in a ConfirmSheet (@gs/core) and naming the visitor in full. Cancelling
 * a pass works with no connection (the contract queues it), so that is never a reason to refuse it — but the sheet
 * says BEFORE the tap that the gate only learns of it later.
 */
type SheetWords = { title: string; lines: string[]; confirmLabel: string; cancelLabel: string }

const nameOf = (name: string): string => name.trim().replace(/\s+/g, ' ') || 'this visitor'

export function passCancelSheet(visitor: string, online: boolean): SheetWords {
  const name = nameOf(visitor)
  return {
    title: `Cancel ${name}'s pass?`,
    // Offline, the gate knows nothing until this phone is back online, so "they will not be able to enter" is not
    // said as if it were already true.
    lines: online
      ? ['They will not be able to enter on it. This cannot be undone: you would send a new pass.']
      : [
          'Once the gate is told, they will not be able to enter on it. This cannot be undone: you would send a new pass.',
          'There is no connection, so the cancellation is saved on this phone and the gate is told only when this phone is back online, until then any guard may still admit on it.',
        ],
    confirmLabel: `Cancel ${name}'s pass`,
    cancelLabel: 'Keep the pass',
  }
}

/**
 * Why a draft cannot be discarded right now — said in its sheet instead of the "it was never sent" line, which would
 * then be false: the draft's send is on its way, or it has just gone and the server made it a real pass.
 */
export const DRAFT_SENDING = 'This draft is being sent right now. Wait for the answer: if it goes, it becomes a pass you can cancel; if it does not, you can discard it.'
export const draftSent = (visitor: string): string =>
  `This draft has just been sent, so it is a real pass now and there is no draft to discard. If ${nameOf(visitor)} should not come, open the pass from Home’s Expected today and cancel it.`

/** A draft was never signed by the server: discarding it deletes only what was typed, and says so. */
export function discardDraftSheet(visitor: string): SheetWords {
  const name = nameOf(visitor)
  return {
    title: `Discard the draft for ${name}?`,
    lines: [`It was never sent, so ${name} cannot enter on it. What you typed is deleted from this phone.`],
    confirmLabel: 'Discard the draft',
    cancelLabel: 'Keep the draft',
  }
}

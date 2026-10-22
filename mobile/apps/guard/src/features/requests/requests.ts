/**
 * Leave, equipment and open shifts — the pure logic (boards guard-app-09 · 10 · 21 · 22).
 *
 * The server's words, not the boards': leave is one of seven types against ONE yearly entitlement
 * (the boards draw separate vacation / sick / casual balances the server does not keep); an open
 * shift is hours, never pay (the boards draw "+4h OT · 1.5×"; a guard's handset shows no rate).
 */

import { dateShort, dateWeekday, siteDayIso, siteNoon, time12 } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import type { OutboxOp } from '@gs/core/offline'

export type GuardRequest = Api.RequestsIndexResponse['items'][number]
export type OpenShift = Api.ShiftsOpenResponse['items'][number]

/* ── dates ───────────────────────────────────────────────────────────────── */

const DAY_MS = 86_400_000
/** Noon AT THE SITE on a calendar day — the instant a formatter can be handed when all there is is a date. */
const noon = (ymd: string, zone?: string) => siteNoon(ymd, zone)

/**
 * The calendar day AT THE SITE that an instant falls on. `zone` is given only where a row belongs to a
 * site that may not be the guard's own — an OPEN shift, which the server sends with its own site's zone.
 * Left out, it is the session's site, which is where everything else on this screen happens.
 */
export const siteDate = (input: Date | string, zone?: string): string => siteDayIso(input, 0, zone)
export const addDays = (ymd: string, days: number): string => new Date(Date.parse(`${ymd}T12:00:00Z`) + days * DAY_MS).toISOString().slice(0, 10)
/** "15 Sep 2026" live (M98's one form, the year with every date); the board's "Sep 15" under the fidelity harness. */
export const dayLabel = (ymd: string, zone?: string): string => dateShort(noon(ymd, zone), zone)
/** "15 Sep 2026" live (M98); the board's "Mon, Sep 15" under the fidelity harness. */
export const dayLabelLong = (ymd: string, zone?: string): string => dateWeekday(noon(ymd, zone), zone)
/** Inclusive, as the server counts a leave request. */
export const daysBetween = (from: string, to: string): number => Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / DAY_MS) + 1
/** The days a date can be chosen from: today and the next `count`. */
export const dayOptions = (at: Date, count = 120): { value: string; label: string }[] => Array.from({ length: count + 1 }, (_, i) => addDays(siteDate(at), i)).map((ymd) => ({ value: ymd, label: dayLabelLong(ymd) }))

/* ── leave ───────────────────────────────────────────────────────────────── */

export const LEAVE_TYPES = [
  { value: 'vacation', label: 'Vacation' },
  { value: 'sick', label: 'Sick' },
  { value: 'bereavement', label: 'Bereavement' },
  { value: 'maternity', label: 'Maternity' },
  { value: 'paternity', label: 'Paternity' },
  { value: 'unpaid', label: 'Unpaid' },
  { value: 'other', label: 'Other' },
] as const
export type LeaveType = (typeof LEAVE_TYPES)[number]['value']
export const REASON_MAX = 190

export type LeaveInput = { type: LeaveType; from: string; to: string; reason: string; certificate: boolean }
export type LeaveErrors = Partial<Record<'from' | 'to' | 'reason', string>>

export function validateLeave(input: LeaveInput, at: Date): LeaveErrors {
  const errors: LeaveErrors = {}
  const today = siteDate(at)
  // Sick leave is often reported the same day or after; any other leave is asked for ahead.
  if (input.type !== 'sick' && input.from < today) errors.from = 'Choose today or a later day.'
  if (input.to < input.from) errors.to = 'The last day cannot be before the first.'
  // Every kind of leave carries a reason (M18C — the server's `required_if:kind,leave`): a supervisor decides on it.
  if (input.reason.trim().length === 0) errors.reason = 'Say briefly why: your supervisor decides on it.'
  if (input.reason.length > REASON_MAX) errors.reason = `Keep the reason under ${REASON_MAX} characters.`
  return errors
}

export const leaveBody = (input: LeaveInput) => ({
  kind: 'leave' as const,
  subject: input.type,
  starts_on: input.from,
  ends_on: input.to,
  ...(input.reason.trim() ? { reason: input.reason.trim() } : {}),
  ...(input.type === 'sick' && input.certificate ? { certificate_attached: true } : {}),
})

/* ── equipment ───────────────────────────────────────────────────────────── */

export const EQUIPMENT = [
  { value: 'Radio', icon: 'eq-item' },
  { value: 'Torch', icon: 'eq-item-2' },
  { value: 'Raincoat', icon: 'eq-item-3' },
  { value: 'Uniform', icon: 'eq-item-4' },
  { value: 'Vehicle', icon: 'veh-row' },
  { value: 'Other', icon: 'eq-item-5' },
] as const
export type EquipmentItem = (typeof EQUIPMENT)[number]['value']
export const QTY_MIN = 1
export const QTY_MAX = 50
export const SUBJECT_MAX = 80

export type EquipmentInput = { item: EquipmentItem | null; other: string; quantity: number; reason: string }
export type EquipmentErrors = Partial<Record<'item' | 'other' | 'reason', string>>

export function validateEquipment(input: EquipmentInput): EquipmentErrors {
  const errors: EquipmentErrors = {}
  if (input.item === null) errors.item = 'Choose what you need.'
  if (input.item === 'Other' && input.other.trim().length === 0) errors.other = 'Name the item.'
  if (input.other.trim().length > SUBJECT_MAX) errors.other = `Keep the item under ${SUBJECT_MAX} characters.`
  if (input.reason.trim().length === 0) errors.reason = 'Say why: stores needs a reason to issue it.'
  if (input.reason.length > REASON_MAX) errors.reason = `Keep the reason under ${REASON_MAX} characters.`
  return errors
}

export const equipmentBody = (input: EquipmentInput) => ({
  kind: 'equipment' as const,
  subject: input.item === 'Other' ? input.other.trim() : String(input.item),
  quantity: Math.min(QTY_MAX, Math.max(QTY_MIN, Math.round(input.quantity))),
  reason: input.reason.trim(),
})

/* ── the list of what was asked for ──────────────────────────────────────── */

export type Tone = 'amber-500' | 'green-400' | 'red-500' | 'navy-500' | 'text-3'
export const STATUS: Record<GuardRequest['status'], { label: string; tone: Tone }> = {
  pending: { label: 'Pending', tone: 'amber-500' },
  approved: { label: 'Approved', tone: 'green-400' },
  // Grey, not red: a denied request is an answer, not an emergency (M12 §4.4, D-185). Red stays for "Not accepted" —
  // a send the server refused, which the guard must act on.
  denied: { label: 'Denied', tone: 'text-3' },
  info_requested: { label: 'More info needed', tone: 'amber-500' },
}

export type RequestRow = { key: string; icon: string; title: string; detail: string; note: string | null; chip: { label: string; tone: Tone }; queued: boolean }

const leaveLabel = (subject: string) => LEAVE_TYPES.find((t) => t.value === subject)?.label ?? subject
const iconFor = (kind: string, subject: string) => (kind === 'leave' ? 'input' : EQUIPMENT.find((e) => e.value.toLowerCase() === subject.toLowerCase())?.icon ?? 'eq-item-5')

function titleOf(kind: string, subject: string, quantity: string | number | null, from: string | null, to: string | null): string {
  if (kind === 'leave') return `${leaveLabel(subject)} leave${from && to ? ` · ${from === to ? dayLabel(from) : `${dayLabel(from)} – ${dayLabel(to)}`}` : ''}`
  return `${subject}${quantity && Number(quantity) > 1 ? ` ×${quantity}` : ''}`
}

/** The server's list with this phone's unsent requests on top — marked as not sent, never as pending approval. */
export function requestRows(items: GuardRequest[], ops: OutboxOp[], kind: 'leave' | 'equipment'): RequestRow[] {
  const held = ops
    .filter((op) => op.endpoint === 'requests.store' && op.body?.kind === kind && op.state !== 'confirmed')
    .map<RequestRow>((op) => {
      const b = op.body as { subject?: string; quantity?: number; starts_on?: string; ends_on?: string }
      const refused = op.state === 'refused'
      return {
        key: `q${op.id}`,
        icon: iconFor(kind, String(b.subject ?? '')),
        title: titleOf(kind, String(b.subject ?? ''), b.quantity ?? null, b.starts_on ?? null, b.ends_on ?? null),
        detail: refused ? 'Not accepted' : 'Saved on this phone, not sent yet',
        note: refused ? (op.error?.message ?? 'The server did not accept this request.') : null,
        chip: refused ? { label: 'Not accepted', tone: 'red-500' } : { label: 'Pending sync', tone: 'amber-500' },
        queued: true,
      }
    })
  const sent = items
    .filter((r) => r.kind === kind)
    .map<RequestRow>((r) => ({
      key: `r${r.id}`,
      icon: iconFor(kind, r.subject),
      title: titleOf(kind, r.subject, r.quantity, r.starts_on, r.ends_on),
      detail: `Requested ${dateShort(r.created_at)}${r.days ? ` · ${r.days} day${String(r.days) === '1' ? '' : 's'}` : ''}${r.certificate_attached ? ' · certificate noted' : ''}`,
      note: r.decision_note,
      chip: STATUS[r.status] ?? { label: String(r.status), tone: 'text-3' },
      queued: false,
    }))
  return [...held, ...sent]
}

/* ── open shifts ─────────────────────────────────────────────────────────── */

export type OpenShiftCard = { id: number | string; title: string; site: string; hours: string; day: string; window: string; restWarning: boolean; claim: 'none' | 'pending' | 'approved' | 'declined' }

/** "Today, 4 Sep 2026" · "Tomorrow, 5 Sep 2026" · "7 Sep 2026" (M98) — and the board's "Today, Sep 4" under the fidelity harness. */
export function relativeDay(iso: string, at: Date, zone?: string): string {
  const day = siteDate(iso, zone)
  const today = siteDate(at, zone)
  const label = dayLabel(day, zone)
  // A label that carries its weekday already has a comma in it; a second one would read "Today, Fri, Sep 4".
  const joint = label.includes(',') ? ' · ' : ', '
  if (day === today) return `Today${joint}${label}`
  if (day === addDays(today, 1)) return `Tomorrow${joint}${label}`
  return dayLabelLong(day, zone)
}

const compactWindow = (from: string, to: string, zone?: string) => `${time12(from, zone)}–${time12(to, zone)}`

/**
 * An open shift is the ONE list in either app whose rows may belong to a site that is not the guard's
 * own, which is why the server sends a zone on EVERY row (`items.*.site.timezone`) and not once at the
 * top. So a card is read end to end on ITS site's clock — day, "Today"/"Tomorrow" and window together —
 * and a guard offered a shift at a site an hour away is told the hours that site will expect them.
 */
export function openShiftCards(items: OpenShift[], at: Date): OpenShiftCard[] {
  return [...items]
    .sort((p, q) => (p.rostered_start < q.rostered_start ? -1 : 1))
    .map((s) => {
      const hours = Number(s.hours)
      const claim = s.claim_status === 'pending' || s.claim_status === 'approved' || s.claim_status === 'declined' ? s.claim_status : 'none'
      // An older server that sends no zone on a row leaves this undefined, and the session's site answers.
      const zone = typeof s.site.timezone === 'string' && s.site.timezone !== '' ? s.site.timezone : undefined
      return { id: s.id, title: s.post.name, site: s.site.name, hours: Number.isFinite(hours) ? `${Number.isInteger(hours) ? hours : hours.toFixed(1)}h` : String(s.hours), day: relativeDay(s.rostered_start, at, zone), window: compactWindow(s.rostered_start, s.rostered_end, zone), restWarning: s.rest_warning === true, claim }
    })
}

/** What a refused claim says — the server's code, in a guard's words. */
export function claimRefusal(code: string | undefined, fallback: string): string {
  if (code === 'shift_not_open') return 'Someone else has this shift now. The list has been refreshed.'
  if (code === 'shift_started') return 'This shift has already started, so it can no longer be claimed.'
  if (code === 'not_found') return 'This shift is no longer offered at your estate.'
  return fallback
}

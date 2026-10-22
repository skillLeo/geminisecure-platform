/**
 * What one `sync.pull` means to a guard, as notifications — pure, so it can be tested.
 *
 * The pull is asked for what changed SINCE the last one, so each item is new by construction; the
 * digest still carries a stable `key` per item so the runtime never shows the same thing twice (a
 * retried pull, two pollers). What it must never do:
 *
 *   - name money, a resident, or a household — a lock screen is read by whoever is standing nearby;
 *   - announce the guard's OWN acts back to them (a clock-in updates a shift; that is not "roster changed");
 *   - say more about an alertness check than that one is waiting.
 */

import { drillTitle } from '../safety/drill'
import type { GuardType } from './links'

export type Pull = {
  messages?: { id: number | string; direction: string; body: string; sent_at: string }[]
  orders?: { set_id: number | string; version_id: number | string; title: string; requires_acknowledgement: boolean }[]
  shifts?: { id: number | string; status: string; post: { name: string }; rostered_start: string; rostered_end: string }[]
  alertness_checks?: { check_id: number | string; respond_by: string }[]
  requests?: { id: number | string; kind: string; status: string }[]
  claims?: { claim_id: number | string; status: string }[]
  /** What a resident says is happening NOW at this site. Read-only: the report is the estate office's. */
  resident_reports?: { report_id: number | string; title: string; location?: string | null }[]
  /** An emergency still open at this site — the same one the push carries, for a phone the push cannot reach. Dispatch owns it. */
  alerts?: { alert_id: number | string; kind: string; where?: string | null; raised_at: string; /** A DRILL testing who a panic reaches (M10 §1.3): it says so first. */ is_drill?: boolean | null }[]
}

export type Note = { key: string; type: GuardType; title: string; body: string; data: { type: GuardType; id?: string; kind?: string }; urgent: boolean }

const clip = (text: string, max = 110) => { const one = text.replace(/\s+/g, ' ').trim(); return one.length > max ? `${one.slice(0, max - 1)}…` : one }

const DECISION: Record<string, string> = { approved: 'approved', denied: 'not approved', declined: 'not approved', info_requested: 'needs more information' }

/** The push's own titles (`PushAnnouncer::alertRaised`), so a guard reads the same words whichever way it arrived. */
const ALERT_TITLE: Record<string, string> = { panic: 'Panic alert', duress: 'Officer duress alert', medical: 'Medical emergency', fire: 'Fire reported', intrusion: 'Intrusion reported' }

/** What kind of alert, in the push's own words — for the banner and for the alert screen it opens. */
export const alertTitle = (kind: unknown): string => (typeof kind === 'string' && Object.prototype.hasOwnProperty.call(ALERT_TITLE, kind) ? ALERT_TITLE[kind]! : 'Emergency alert')

export function guardDigest(pull: Pull, at: Date, when: (iso: string) => string): Note[] {
  const notes: Note[] = []

  /*
   * FIRST, AND URGENT: an emergency at this site. Typed and keyed exactly as the push is (`site_alert`,
   * the alert's id), so a phone that gets both shows one banner. A unit, never a person — the push says
   * "At Lot 1", and so does this; another officer's duress names nobody at all. A DRILL says so before
   * anything else, as the server's own push title does ("DRILL — Panic alert").
   */
  for (const a of pull.alerts ?? []) {
    const where = (a.where ?? '').trim()
    const lead = where ? `At ${where}. ` : a.kind === 'panic' ? 'A resident pressed panic. ' : a.kind === 'duress' ? 'An officer at your site needs help. ' : ''
    notes.push({
      key: `site-alert:${a.alert_id}`,
      type: 'site_alert',
      title: drillTitle(alertTitle(a.kind), a.is_drill),
      body: `${lead}Dispatch has it: respond as your orders say.`,
      data: { type: 'site_alert', id: String(a.alert_id), kind: a.kind },
      urgent: true,
    })
  }

  for (const c of pull.alertness_checks ?? []) {
    if (new Date(c.respond_by).getTime() <= at.getTime()) continue
    notes.push({ key: `alertness:${c.check_id}`, type: 'alertness_check', title: 'Alertness check-in', body: 'Open the app and tap "I\'m here": you have two minutes.', data: { type: 'alertness_check', id: String(c.check_id) }, urgent: true })
  }

  for (const m of pull.messages ?? []) {
    const broadcast = m.direction === 'broadcast'
    const type: GuardType = broadcast ? 'broadcast' : 'message'
    notes.push({ key: `message:${m.id}`, type, title: broadcast ? 'Site broadcast' : 'Control room', body: clip(m.body), data: { type, id: String(m.id) }, urgent: false })
  }

  for (const o of pull.orders ?? []) {
    notes.push({ key: `order:${o.version_id}`, type: 'order_published', title: 'Standing orders updated', body: clip(`${o.title}${o.requires_acknowledgement ? ': read and acknowledge before your next round.' : ''}`), data: { type: 'order_published', id: String(o.set_id) }, urgent: false })
  }

  for (const s of pull.shifts ?? []) {
    // Only a shift still AHEAD is a roster change. One the guard has started or finished changed because of what they did.
    if (s.status !== 'rostered' || new Date(s.rostered_start).getTime() <= at.getTime()) continue
    notes.push({ key: `shift:${s.id}:${s.rostered_start}`, type: 'shift_changed', title: 'Your roster changed', body: clip(`${s.post.name} · ${when(s.rostered_start)}`), data: { type: 'shift_changed', id: String(s.id) }, urgent: false })
  }

  for (const r of pull.requests ?? []) {
    const what = r.kind === 'equipment' ? 'equipment request' : r.kind === 'shift_swap' ? 'shift swap' : 'leave request'
    notes.push({ key: `request:${r.id}:${r.status}`, type: 'request_decided', title: `Your ${what}`, body: r.status === 'info_requested' ? 'It needs more information. Open the app for the note.' : `It was ${DECISION[r.status] ?? r.status}. Open the app for the note.`, data: { type: 'request_decided', id: String(r.id), kind: r.kind }, urgent: false })
  }

  // A resident, at this site, saying it is happening as they write. It is NOT an emergency path and does
  // not ring like one — the resident's own screen says so — and it names a place, never a household.
  for (const r of pull.resident_reports ?? []) {
    const where = (r.location ?? '').trim()
    notes.push({
      key: `resident-report:${r.report_id}`,
      type: 'resident_report',
      title: 'A resident reports something happening now',
      body: clip(where ? `${r.title} · ${where}` : r.title),
      data: { type: 'resident_report', id: String(r.report_id) },
      urgent: false,
    })
  }

  for (const c of pull.claims ?? []) {
    notes.push({ key: `claim:${c.claim_id}:${c.status}`, type: 'claim_decided', title: 'Your open-shift claim', body: c.status === 'approved' ? 'Approved: the shift is on your roster.' : 'Not approved: the shift is not yours.', data: { type: 'claim_decided', id: String(c.claim_id) }, urgent: false })
  }

  return notes
}

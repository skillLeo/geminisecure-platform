/**
 * HOW FAR THE RESPONSE HAS GOT — M10 §1.3's six steps, each drawn from the one field that records it.
 *
 * "The resident's screen still shows the honest state: alerting → acknowledged → dispatched → en route →
 * on scene → resolved." The server now records every one of them — `acknowledged_at`, `dispatched_at`,
 * `en_route_at`, `on_scene_at`, `resolved_at`, on `duress.show` and on the Guard App's `alerts.show` —
 * so a timeline is those fields and nothing else. Each step is in one of four states:
 *
 *   done          its field holds a time. THE ONLY WAY A STEP IS TICKED. Never from `status`, which is one
 *                 word for several facts (`responding` is "sent", or "on the way", or "there").
 *   not_recorded  its field is empty but a LATER step's is not. An officer who ran straight to the lot has
 *                 an on-scene time and no en-route time, a guard on shift may respond without being sent,
 *                 and the server back-fills nothing. The step stays unticked and says "Not recorded" —
 *                 never drawn as done, never quietly dropped.
 *   current       the first step after the last one recorded, while the alert is open.
 *   pending       further on than that.
 *
 * A CLOSED alert leaves nothing pending: every step before its close either happened or was not recorded.
 * A CANCELLED one (the raiser's own ten seconds) is short — sent, and cancelled — because the server
 * refuses a cancel once anybody has acted, so there was never anything to record.
 *
 * "HELP IS ON THE WAY" IS SAID BY `responseHeadline` AND NOTHING ELSE, and only once `dispatched_at`
 * holds a time — the first moment the control room has SENT somebody. Before that the line is "The
 * control room has your alert", which is true from the moment the alert is recorded (`console_alerted`).
 * An officer who said "on my way" without being sent is said as exactly that.
 *
 * Pure: no clock, no network. Times are written by the caller's own site-zone formatter.
 */

import { textsDelivered } from './alertReach'
import { householdEmails, householdTexts } from './householdContacts'
import type { AlertAnswer } from './raiseAlert'

export type ProgressFields = {
  status: string
  acknowledged_at: string | null
  cancelled_at?: string | null
  dispatched_at?: string | null
  en_route_at?: string | null
  on_scene_at?: string | null
  resolved_at?: string | null
}

export type StepKey = 'sent' | 'acknowledged' | 'dispatched' | 'en_route' | 'on_scene' | 'resolved'
export type StepState = 'done' | 'not_recorded' | 'current' | 'pending'
export type ProgressStep = { key: Exclude<StepKey, 'sent'>; state: StepState; at: string | null }

/** What a skipped step says. Plain, and the same everywhere. */
export const NOT_RECORDED = 'Not recorded'

const MIDDLE = [
  ['acknowledged', 'acknowledged_at'],
  ['dispatched', 'dispatched_at'],
  ['en_route', 'en_route_at'],
  ['on_scene', 'on_scene_at'],
] as const

/** A time the server recorded, or null. Anything that is not a readable instant is no time at all. */
export const recorded = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Date.parse(value)) ? value : null)

/** Closed by the server's own word: resolved, a false alarm, or cancelled from the phone. */
export const isClosed = (a: Pick<ProgressFields, 'status' | 'resolved_at' | 'cancelled_at'>): boolean =>
  a.status === 'resolved' || a.status === 'false_alarm' || recorded(a.resolved_at) !== null || recorded(a.cancelled_at) !== null

export const wasCancelled = (a: Pick<ProgressFields, 'cancelled_at'>): boolean => recorded(a.cancelled_at) !== null

/** The four steps between sent and closed, and the close — each with its state and the time that makes it so. */
export function progressStates(a: ProgressFields): ProgressStep[] {
  const closed = isClosed(a)
  const times = MIDDLE.map(([, field]) => recorded(a[field]))
  // The furthest step recorded. On a closed alert the close itself is the furthest, so nothing before it is pending.
  const furthest = closed ? MIDDLE.length : times.reduce<number>((far, at, i) => (at !== null ? i : far), -1)
  const steps: ProgressStep[] = MIDDLE.map(([key], i) => {
    const at = times[i] ?? null
    const state: StepState = at !== null ? 'done' : i < furthest ? 'not_recorded' : i === furthest + 1 ? 'current' : 'pending'
    return { key, at, state }
  })
  /*
   * The close is ticked by `resolved_at` (or `cancelled_at`). An answer SAVED ON THE PHONE before those
   * fields existed has no `resolved_at` key at all, and then the server's `status` is the only record of
   * the close there is; a `resolved_at` the server sent as null is never overruled by the status.
   */
  const closedAt = recorded(a.resolved_at) ?? recorded(a.cancelled_at)
  const closedByStatus = a.status === 'resolved' || a.status === 'false_alarm'
  const closeDone = closedAt !== null || (closedByStatus && a.resolved_at === undefined)
  steps.push({ key: 'resolved', at: closedAt, state: closeDone ? 'done' : closed ? 'not_recorded' : furthest === MIDDLE.length - 1 ? 'current' : 'pending' })
  return steps
}

/* ---- the Resident App's words, and the guard's own duress ------------------------------------------ */

export type AlertStep = { key: StepKey; state: StepState; title: string; detail: string | null; done: boolean; current: boolean }

const blank = (text: string | null | undefined): string | null => (text && text.trim() !== '' ? text : null)

/**
 * The timeline a read-back (`duress.show`) supports — no ETA and no names: the server gives the raiser
 * neither, so none is shown.
 *
 * `reachedNobody` changes the FIRST step, and it has to. "Alert sent to gatehouse & dispatch" is a claim
 * about two places, and when not one handset was reached only the second half of it is true.
 *
 * @param sentAt       the server's time of the alert, already written in the site's zone
 * @param secondsToAck how long the control room took, when it has acknowledged
 * @param when         the site-zone formatter for every other step's time
 */
export function alertSteps(
  a: ProgressFields,
  sentAt: string,
  secondsToAck: number | null,
  reachedNobody = false,
  when: (iso: string) => string = () => '',
): AlertStep[] {
  const step = (key: StepKey, state: StepState, title: string, detail: string | null): AlertStep => ({ key, state, title, detail: blank(detail), done: state === 'done', current: state === 'current' })

  const sent = reachedNobody
    ? step('sent', 'done', 'The control room has your alert', `${sentAt} · no officer’s phone was reached`)
    : step('sent', 'done', 'Alert sent to gatehouse & dispatch', sentAt)

  const ackAt = recorded(a.acknowledged_at)
  const ackDone = () => step('acknowledged', 'done', 'Dispatch acknowledged your alert', secondsToAck === null ? when(ackAt ?? '') : `${secondsToAck} second${secondsToAck === 1 ? '' : 's'} later`)

  // Cancelled from the phone inside the ten seconds: nobody had acted, so there is nothing else to show.
  if (wasCancelled(a)) return [sent, ...(ackAt !== null ? [ackDone()] : []), step('resolved', 'done', 'You cancelled this alert', null)]

  // The consoles' Emergency list words (M98, 12 Oct 2026): Dispatched, On the way, On scene ("at the scene" no more).
  const NAMES = { dispatched: 'Officer dispatched', en_route: 'Officer on the way', on_scene: 'Officer on scene' } as const
  return [
    sent,
    ...progressStates(a).map((s): AlertStep => {
      if (s.key === 'acknowledged') {
        if (s.state === 'done') return ackDone()
        if (s.state === 'not_recorded') return step('acknowledged', s.state, 'Acknowledged by dispatch', NOT_RECORDED)
        return step('acknowledged', s.state, 'Waiting for dispatch to acknowledge', 'They have it on their screen now')
      }
      if (s.key === 'resolved') {
        // The close in the consoles' words: Resolved, or False alarm (M98).
        const title = a.status === 'false_alarm' ? 'False alarm' : 'Resolved'
        if (s.state === 'done') return step('resolved', s.state, title, s.at === null ? null : when(s.at))
        if (s.state === 'not_recorded') return step('resolved', s.state, title, NOT_RECORDED)
        return step('resolved', s.state, 'Resolved', 'Only a responder can close this alert')
      }
      // Dispatched, en route, on scene: the step's name, ticked with its time — or "Not recorded" — or nothing yet.
      return step(s.key, s.state, NAMES[s.key], s.state === 'done' && s.at !== null ? when(s.at) : s.state === 'not_recorded' ? NOT_RECORDED : null)
    }),
  ]
}

/* ---- the one line that says where the response stands ------------------------------------------- */

/** `officer` is true once an officer is RECORDED as sent, on the way or there — the only headlines that are news beside "no officer's phone was reached". */
export type Headline = { headline: string; detail: string | null; officer: boolean }

/**
 * WHAT THE PERSON WHO RAISED IT IS TOLD, in the server's own push words (`PushAnnouncer`) — and "Help is
 * on the way" ONLY once `dispatched_at` holds a time. Null for a closed alert: the closed card speaks. "On scene" is the
 * consoles' word (M98); the server's push still says "at the scene" until the phone API's sentences follow.
 */
export function responseHeadline(a: ProgressFields & { console_alerted?: boolean | null }): Headline | null {
  if (isClosed(a)) return null
  if (recorded(a.on_scene_at) !== null) return { headline: 'An officer has arrived', detail: 'An officer is recorded on scene.', officer: true }
  if (recorded(a.dispatched_at) !== null) {
    return { headline: 'Help is on the way', detail: recorded(a.en_route_at) !== null ? 'The officer the control room sent is recorded as on the way.' : 'The control room has sent an officer to you.', officer: true }
  }
  // Said "on my way" from their own handset, nobody having been sent: exactly that, and no more.
  if (recorded(a.en_route_at) !== null) return { headline: 'An officer is on the way', detail: 'An officer is recorded as heading to you.', officer: true }
  if (a.console_alerted === false) return null
  return { headline: 'The control room has your alert', detail: recorded(a.acknowledged_at) !== null ? 'Dispatch has seen it.' : null, officer: false }
}

/* ---- has the read-back moved? ------------------------------------------------------------------- */

type Comparable = Partial<AlertAnswer> | null | undefined

const n = (value: unknown): unknown => (value === undefined ? null : value)

/**
 * Whether a poll's answer says anything the saved one does not — so the screen re-renders on every step,
 * and never rewrites the phone's copy when nothing moved. EVERY field a screen draws from is here: the
 * status and its times, who it reached, the community's texts delivered, where the household's texting
 * stands (texting → sent or not), and the drill flag. A field the saved copy lacks and the answer sends as
 * null is not a change.
 */
export function answerMoved(before: Comparable, after: Comparable): boolean {
  if (!after) return false
  if (!before) return true
  // `rang_handsets` / `rang_at` land on a later poll than the press (the ring goes after the raise), and `on_duty_officers`
  // with the reach: left out, the screen would go on saying "no officer's phone" while the ring was ringing them (M18A).
  const fields = ['status', 'acknowledged_at', 'cancelled_at', 'dispatched_at', 'en_route_at', 'on_scene_at', 'resolved_at', 'alerted_handsets', 'alerted_via', 'escalated_contacts', 'rang_handsets', 'rang_at', 'on_duty_officers'] as const
  if (fields.some((f) => n(before[f]) !== n(after[f]))) return true
  if ((before.is_drill === true) !== (after.is_drill === true)) return true
  return (
    textsDelivered(before) !== textsDelivered(after) ||
    JSON.stringify(householdTexts(before)) !== JSON.stringify(householdTexts(after)) ||
    // M12 §2.5: the emails to the household's contacts land a moment after the press, on their own polls.
    JSON.stringify(householdEmails(before)) !== JSON.stringify(householdEmails(after)) ||
    // M50 §4.2 #2: a message on the live channel with dispatch, or one of them shown to the other side ("Delivered").
    threadKey(before) !== threadKey(after)
  )
}

/** The live channel with dispatch, as far as a redraw cares: which messages, and which have been seen. */
const threadKey = (answer: Comparable): string => JSON.stringify((answer?.messages ?? []).map((m) => [m.id, m.seen_at]))

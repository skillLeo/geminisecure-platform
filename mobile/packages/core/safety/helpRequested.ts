/**
 * THE PANIC SCREEN, WORD FOR WORD (M18A) — every sentence the resident reads after the press, decided here and only here.
 *
 * "Calm and true at the same time." The rule that does not move: NEVER claim an officer is coming when none was
 * reached. What changed is the order: lead with what IS happening, and put what is not happening below it, in plain
 * type, without shouting. The copy is the client's, used as written; where the product cannot make a sentence true,
 * this file says the true thing instead and the comment beside it says why.
 *
 *   header     "Help requested" · "Lot 47 · sent 6:32 PM · your location was sent"                        (§2.1)
 *   ticks      what genuinely happened, each with its time, the first ALWAYS ticked; empty circles below  (§2.2)
 *   call       "Call 119 as well" — the same card whether officers were reached or not; advice, not alarm (§2.3)
 *   status     then, and only then, what is and is not happening, in plain type                         (§2.4, §2.6)
 *   contacts   what the household and the emergency contacts were told, and the button that texts them  (§2.5, M18C §4.3)
 *
 * THE RULES (§3), each held by a test: the first thing is what happened; red is for the 119 button only and nothing is
 * in capitals (the screen's job); every tick carries a time; nothing says or implies somebody is on their way unless
 * the server has recorded it; the 119 prompt is in every state, resolved included; the screen says it updates by
 * itself; and no state is a dead end.
 *
 * Pure: no clock, no network. Times are written by the caller's site-zone formatter.
 */

import type { AlertReach } from './alertReach'
import { isClosed, recorded, wasCancelled, type ProgressFields } from './alertProgress'
import { householdEmails, householdTexts, type ContactsAnswer } from './householdContacts'

export type HelpTick = {
  key: 'console' | 'officers' | 'acknowledged' | 'dispatched' | 'en_route' | 'on_scene'
  title: string
  /** The time it happened, in the estate's zone. Null on an empty circle — a tick ALWAYS has one. */
  at: string | null
  done: boolean
  /** "Not recorded": a later step has a time and this one does not. Plain, and never drawn as done. */
  note: string | null
}

export type HelpCall = { title: string; body: string; button: string }

export type HelpScreen = {
  title: string
  subline: string
  ticks: HelpTick[]
  call: HelpCall
  /** Plain paragraphs, in order. Never a headline, never red, never capitals. */
  status: string[]
  contacts: string
  /** The alert is closed: what the closing card says, and what to do if the problem is not over. */
  closed: { title: string; lines: string[] } | null
}

export type HelpAnswer = ProgressFields &
  ContactsAnswer & {
    server_time: string
    console_alerted?: boolean | null
    escalated_contacts?: number | null
    rang_at?: string | null
    on_duty_officers?: number | null
  }

export type HelpContext = {
  /** "Lot 47", or null when the phone does not know the unit. */
  unit: string | null
  /** "6:32 PM" — or "26 Sep, 6:32 PM" for an alert from another day. */
  sentAt: string
  withPosition: boolean
  /** The site-zone formatter for every recorded time. */
  when: (iso: string) => string
  /** How many emergency contacts the resident can text from their own phone (the button beneath). */
  textable: number
  /** The real local emergency number the button dials. */
  emergencyNumber: string
}

/** §2.3, word for word. The same card in every state. */
export function callCard(emergencyNumber: string): HelpCall {
  return {
    title: `Call ${emergencyNumber} as well`,
    body: 'Emergency services are the fastest help available. Do not wait for this screen.',
    button: `Call ${emergencyNumber} now`,
  }
}

const NOT_RECORDED = 'Not recorded'

const count = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.trunc(value) : 0)

/** §2.1: "Lot 47 · sent 6:32 PM · your location was sent". */
export function subline(ctx: Pick<HelpContext, 'unit' | 'sentAt' | 'withPosition'>): string {
  return [
    ctx.unit,
    `sent ${ctx.sentAt}`,
    // The client's line says the location was sent; when the phone could not send one, it says that instead.
    ctx.withPosition ? 'your location was sent' : 'your location could not be sent',
  ]
    .filter(Boolean)
    .join(' · ')
}

/**
 * §2.2. The first line is always a tick, because something always happens: the alert exists, so the control room has
 * it (`console_alerted`, true on every alert the server records). "Officers alerted" is a line only when a push was
 * actually sent to an officer's phone — by the raise or by the call-style ring — and it carries that moment.
 */
export function ticks(answer: HelpAnswer, reach: AlertReach, when: (iso: string) => string): HelpTick[] {
  const sent = recorded(answer.server_time)
  const at = (iso: string | null | undefined): string | null => {
    const t = recorded(iso)
    return t === null ? null : when(t)
  }

  const out: HelpTick[] = [{ key: 'console', title: 'Control room has your alert', at: at(sent), done: true, note: null }]

  if (reach.state === 'reached') {
    // The ring rang them: its own first moment. The raise's push goes inside the raise: the alert's own time.
    out.push({ key: 'officers', title: 'Officers alerted', at: at(reach.viaRing ? answer.rang_at ?? sent : sent), done: true, note: null })
  }

  const steps = [
    ['acknowledged', 'Control room acknowledged', answer.acknowledged_at],
    ['dispatched', 'Officer dispatched', answer.dispatched_at],
    ['en_route', 'Officer on the way', answer.en_route_at],
    // "On scene", the consoles' Emergency list word (M98, 12 Oct 2026), where it said "at the scene".
    ['on_scene', 'Officer on scene', answer.on_scene_at],
  ] as const

  // The furthest step with a time: anything before it without one was skipped, and says so plainly.
  const furthest = steps.reduce((far, [, , iso], i) => (recorded(iso) !== null ? i : far), -1)

  steps.forEach(([key, title, iso], i) => {
    const t = at(iso)
    out.push({ key, title, at: t, done: t !== null, note: t === null && i < furthest ? NOT_RECORDED : null })
  })

  return out
}

/**
 * §2.4 AND §2.6: the status in plain type, the furthest thing the server has recorded first. Officers sent, on the
 * way or there replace everything below them — an officer at the lot makes "no officer is on duty" beside the point.
 * Before that, an acknowledgement is said, and then who the alert reached.
 */
export function status(answer: HelpAnswer, reach: AlertReach): string[] {
  if (recorded(answer.on_scene_at) !== null) return ['Your officer has arrived at your lot.']
  // The server gives the resident no officer's name and no estimate, so none is said (§2.6: "only if there is one").
  if (recorded(answer.en_route_at) !== null) return ['Your officer is on the way.']
  if (recorded(answer.dispatched_at) !== null) return ['An officer has been assigned and is being sent to you.']

  const lines: string[] = []
  if (recorded(answer.acknowledged_at) !== null) lines.push('The control room has acknowledged your alert.')
  lines.push(reachLine(answer, reach))
  return lines
}

/** Who the alert reached, in the client's three sentences — and a fourth and fifth only where those three cannot be true. */
export function reachLine(answer: HelpAnswer, reach: AlertReach): string {
  // "…and your community's escalation contact has been emailed" is said only when one was (`escalated_contacts`).
  const emailed = count(answer.escalated_contacts) > 0
  const console = emailed
    ? 'The control room has your alert on screen and your community’s escalation contact has been emailed.'
    : 'The control room has your alert on screen.'

  if (reach.state === 'reached') {
    return 'Officers at your community have been alerted and the control room has your alert on screen. This screen updates by itself as they respond.'
  }

  // The count is not in yet — a moment after the press. Neither "alerted" nor "not alerted" is claimed.
  if (reach.state === 'reaching') return 'The control room has your alert on screen. This screen updates by itself.'

  const onDuty = typeof answer.on_duty_officers === 'number' && Number.isFinite(answer.on_duty_officers) ? Math.trunc(answer.on_duty_officers) : null

  if (onDuty === 0) {
    return `No officer is on duty at your community at the moment, so no officer’s phone could be alerted. ${console}`
  }

  if (onDuty !== null && onDuty > 0) {
    return `Officers are on duty but their phones could not be reached just now. ${console} We are still trying.`
  }

  // An alert raised before the server recorded who was on duty: neither of the client's two sentences is proven.
  return `No officer’s phone could be alerted just now. ${console}`
}

/**
 * §2.5 AND M18C §4.3: what the household and the emergency contacts were told. "Emailed" only from what the mail
 * server ACCEPTED, and to whom it went — the household's members, the emergency contacts, or both; a text only from
 * what a provider accepted, and none is today. The button beneath texts them from the resident's own phone.
 */
export function contactsLine(answer: ContactsAnswer | null | undefined, textable: number): string {
  const emails = householdEmails(answer)
  const texts = householdTexts(answer)
  const texted = texts.state === 'sent' ? count(texts.delivered) : 0
  const household = answer?.email?.household ?? null
  const members = count(household?.members_sent)
  const contacts = emails.state === 'sent' ? Math.max(0, emails.sent - members) : 0

  const told =
    emails.state !== 'sent'
      ? null
      : members > 0 && contacts > 0
        ? 'Your household and your emergency contacts have been emailed.'
        : members > 0
          ? 'Your household has been emailed.'
          : 'Your emergency contacts have been emailed.'

  const textLine = texted > 0
    ? `${texted} of your emergency contacts ${texted === 1 ? 'was' : 'were'} sent a text.`
    : told !== null
      ? textable > 0
        ? 'They are not texted automatically: you can text them from your own phone below.'
        : 'They are not texted automatically.'
      : 'Your emergency contacts are not texted automatically.'

  if (told === null && emails.state === 'emailing' && texts.state !== 'unanswered') {
    return `Emailing your household and emergency contacts… ${textLine}`
  }

  return told === null ? textLine : `${told} ${textLine}`
}

/**
 * §2.6, resolved: "This alert has been closed by the control room." Then what was recorded — the ticks above, each
 * with its time — and how to raise it again if the problem is not over. A cancel from this phone says that instead.
 */
export function closedCard(answer: HelpAnswer, emergencyNumber: string): { title: string; lines: string[] } | null {
  if (!isClosed(answer)) return null

  if (wasCancelled(answer)) {
    return { title: 'You cancelled this alert', lines: ['You cancelled it in time and the control room has been told why. Nothing more is needed.', `If you need help after all, send a new alert, or call ${emergencyNumber}.`] }
  }

  return {
    title: 'This alert has been closed by the control room.',
    lines: [
      answer.status === 'false_alarm' ? 'It was recorded as a false alarm. What was recorded is above.' : 'What was recorded is above.',
      `If the problem is not over, send a new alert below, or call ${emergencyNumber}.`,
    ],
  }
}

/** The whole screen for an alert the server has answered. */
export function helpScreen(answer: HelpAnswer, reach: AlertReach, ctx: HelpContext): HelpScreen {
  const closed = closedCard(answer, ctx.emergencyNumber)

  return {
    title: 'Help requested',
    subline: subline(ctx),
    ticks: ticks(answer, reach, ctx.when),
    call: callCard(ctx.emergencyNumber),
    status: closed ? [] : status(answer, reach),
    contacts: contactsLine(answer, ctx.textable),
    closed,
  }
}

/**
 * §2.7, sent while offline — AND WHY IT IS NOT THE CLIENT'S SENTENCE. The copy given says "your alert was sent by text
 * message instead". This app has no text-message route to the control room: with no signal the alert is saved on the
 * phone and sent the moment a signal returns, and until then nobody has it. Telling a frightened person the control
 * room has been texted, when it has not, is the one thing this screen may not do. So it says what is true, as calmly,
 * with the 119 button directly beneath (DECISIONS D-M18A-OFFLINE).
 */
export function offlineLine(emergencyNumber: string): string {
  return `Your phone has no signal, so your alert is saved on this phone and will be sent the moment any signal returns. The control room does not have it yet. Call ${emergencyNumber} now.`
}

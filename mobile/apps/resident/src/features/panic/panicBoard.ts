/**
 * resident-app-06 IN WORDS — what each slot of the rebuilt panic screen says, from what is TRUE (M42 §5 #5).
 *
 * The board draws one moment: "Alert sent", three steps, a message from dispatch. The screen draws that layout in
 * every state, and this file decides what goes in each slot — the board's own words where they are true, the true
 * part of them where the whole is not, and the app's existing sentences where the board has a sample:
 *
 *   step 1  "Alert sent to gatehouse & dispatch", ticked by the server's own time of the alert ("9:41:02 AM"). When
 *           no officer's phone was reached, only the second half is true: "Alert sent to dispatch", and it says so.
 *   step 2  "Guard Marcus Whyte acknowledged" — the server gives the resident no name, and the acknowledgement is the
 *           control room's (`acknowledged_at`: "when dispatch took it"): "Dispatch acknowledged", "14 seconds later".
 *   step 3  "On the way — ETA 2 min" — the server records an officer as on the way (`en_route_at`) and gives no
 *           estimate: "On the way". "Live location shared" — nothing is shared live; the position goes ONCE, with the
 *           alert: "Location shared", and only when it went.
 *   bubble  the latest thing dispatch has recorded, in the sentences `@gs/core/safety` already says it in — and
 *           nothing while dispatch has recorded nothing. A sentence the PHONE says (held, nobody reached) is never
 *           put in dispatch's mouth: it has no "Dispatch:".
 *
 * A step is ticked only by the time the server recorded for it — never from `status`, never because a later step
 * happened (that earlier step then says "Not recorded"). Nothing here may claim a text, a name or an estimate the
 * server did not send. Pure: no clock, no network; the caller writes the times on the estate's clock.
 */

import { alertSteps, helpStatus, isClosed, NOT_RECORDED, recorded, wasCancelled, type AlertAnswer, type AlertReach } from '@gs/core/safety'

export type BoardPhase = 'arm' | 'sending' | 'active' | 'queued' | 'failed' | 'closed'
export type BoardStep = { title: string; detail: string | null; done: boolean }
export type BoardBubble = { from: string | null; text: string }

export type PanicBoard = {
  /** Under the circle: "Alert sent". */
  title: string
  /** The gold line under it: "General emergency · Sent 9:41 AM". */
  meta: string
  /** The three steps, once there is an alert (held, sent or closed); none before the press. */
  steps: BoardStep[]
  /** The message in "Live channel with dispatch"; null while dispatch has recorded nothing. */
  bubble: BoardBubble | null
  /** "Share live audio". There is no audio: never on a phone. Only the fidelity build draws the board's on-state. */
  audioOn: boolean
}

/** The board's words, and — marked — the few a state the board does not draw needs. */
export const WORDS = {
  sent: 'Alert sent',
  /** Not on the board: an alert a responder has closed. */
  closed: 'Alert closed',
  /** Not on the board: held on the phone for want of any signal — nobody has it. */
  notSent: 'Not sent yet',
  /** Not on the board: held because the answer was slow or bad — the control room may have it. */
  notConfirmed: 'Not confirmed yet',
  /** Not on the board: before the press, and after an alert that will never be sent. */
  hold: 'Hold to send an alert',
  sending: 'Sending…',
  sendingNote: 'Do not close the app',
  stepSent: 'Alert sent to gatehouse & dispatch', // framing-ok: the client's board copy — "dispatch" is the estate's own security control room
  stepSentDispatchOnly: 'Alert sent to dispatch', // framing-ok: the board's words with the untrue half taken out (no officer's phone was reached)
  stepAcknowledged: 'Dispatch acknowledged', // framing-ok: the board's "<who> acknowledged"; the server names no guard, and dispatch is who acknowledges
  /** Not on the board (M57 §5.2): an officer set off before the control room took the alert, which is allowed, and true. */
  ackNotYetOfficerGoing: 'Not yet · an officer is already on the way',
  stepOnTheWay: 'On the way',
  locationShared: 'Location shared',
  dispatch: 'Dispatch:',
  /** Not on the board: a press made while the office had drill mode on (M53 §3A.1). */
  drillSent: 'Drill sent',
  stepDrillSent: 'Drill sent to dispatch',
  nobodyRang: 'no officer’s phone rang',
} as const

export const holdNote = (holdMs: number): string => `Keep holding for ${holdMs / 1000} seconds · release to stop`

/** What `GET /me` says while the office has drill mode on for this account; null when it is off. */
export type DrillMode = { until: string; until_local: string; reason: string | null }

/**
 * DRILL MODE, SAID BEFORE THE PRESS (M53 §3A.1): "Drill mode until 4:30 PM · a press alerts nobody", while the server
 * has it on for this account and its end is still ahead of this phone's clock; null otherwise. The server decides what
 * a press is, at the moment it arrives, so a line that is out of date errs only one way: a press it calls a drill can
 * still be a real alert, never the other way round.
 */
export function drillModeLine(d: DrillMode | null | undefined, nowMs: number): string | null {
  if (!d || typeof d.until !== 'string') return null
  const ends = Date.parse(d.until)
  if (!Number.isFinite(ends) || ends <= nowMs) return null
  return `Drill mode until ${d.until_local} · a press alerts nobody`
}

/** The phone's own sentence for a press raised in drill mode: what did not happen, and 119 in case it is real. */
export const drillModeBubble = (emergencyNumber: string): string =>
  `Drill mode was on, so no officer’s phone rang and nobody was emailed or texted. If this is a real emergency, call ${emergencyNumber} now.`

/** "14 seconds later" — the board's own format; a wait of a minute or more in minutes. */
export function later(seconds: number): string {
  const s = Math.max(0, Math.round(seconds))
  if (s < 60) return `${s} second${s === 1 ? '' : 's'} later`
  const m = Math.round(s / 60)
  return `${m} minute${m === 1 ? '' : 's'} later`
}

/**
 * THE THREE STEPS. Before the server has the alert, all three are pending: the alert exists only on this phone.
 * Afterwards each is ticked by its own field.
 */
export function boardSteps(a: AlertAnswer | null, ctx: { reachedNobody: boolean; withPosition: boolean; atSeconds: (iso: string) => string }): BoardStep[] {
  if (!a) {
    return [
      { title: WORDS.stepSent, detail: null, done: false },
      { title: WORDS.stepAcknowledged, detail: null, done: false },
      { title: WORDS.stepOnTheWay, detail: null, done: false },
    ]
  }

  const sentAt = recorded(a.server_time)
  const time = sentAt ? ctx.atSeconds(sentAt) || null : null
  // Drill mode (M53 §3A.1): only the control room has it, labelled DRILL, and no officer's phone was even tried.
  const sent: BoardStep = a.drill_mode === true
    ? { title: WORDS.stepDrillSent, detail: [time, WORDS.nobodyRang].filter(Boolean).join(' · '), done: true }
    : ctx.reachedNobody
      ? { title: WORDS.stepSentDispatchOnly, detail: [time, 'no officer’s phone was reached'].filter(Boolean).join(' · '), done: true }
      : { title: WORDS.stepSent, detail: time, done: true }

  // Cancelled from the phone inside the server's ten seconds: nobody had acted, so there is nothing more to record.
  if (wasCancelled(a)) return [sent, { title: WORDS.stepAcknowledged, detail: null, done: false }, { title: WORDS.stepOnTheWay, detail: null, done: false }]

  const closed = isClosed(a)
  const ackAt = recorded(a.acknowledged_at)
  const enRouteAt = recorded(a.en_route_at)
  const onSceneAt = recorded(a.on_scene_at)
  const dispatchedAt = recorded(a.dispatched_at)

  // A later step with a time — or the close — leaves an earlier blank step "Not recorded": never ticked, never dropped.
  const ackSkipped = ackAt === null && (dispatchedAt !== null || enRouteAt !== null || onSceneAt !== null || closed)
  // M57 §5.2: an officer may set off before the control room takes the alert (the control room's alarm stays up until it
  // does). While the alert is open that is said as it is, not as a step that was skipped.
  const officerGoing = !closed && (enRouteAt !== null || onSceneAt !== null)
  const acknowledged: BoardStep =
    ackAt !== null
      ? { title: WORDS.stepAcknowledged, detail: sentAt ? later((Date.parse(ackAt) - Date.parse(sentAt)) / 1000) : null, done: true }
      : { title: WORDS.stepAcknowledged, detail: officerGoing ? WORDS.ackNotYetOfficerGoing : ackSkipped ? NOT_RECORDED : null, done: false }

  const wayShown = ctx.withPosition ? WORDS.locationShared : null
  const waySkipped = enRouteAt === null && (onSceneAt !== null || closed)
  const onTheWay: BoardStep = { title: WORDS.stepOnTheWay, detail: waySkipped ? NOT_RECORDED : wayShown, done: enRouteAt !== null }

  return [sent, acknowledged, onTheWay]
}

/**
 * THE MESSAGE IN THE LIVE CARD — the furthest thing dispatch has recorded, in `@gs/core/safety`'s own sentences
 * (helpRequested's status lines, alertProgress's closing step). Before dispatch has done anything there is no
 * message, except the one the phone must say itself: that no officer's phone was reached.
 */
export function boardBubble(a: AlertAnswer | null, reach: AlertReach, emergencyNumber: string): BoardBubble | null {
  if (!a) return null
  if (isClosed(a)) {
    const close = alertSteps(a, '', null).find((s) => s.key === 'resolved')
    if (!close) return null
    return { from: wasCancelled(a) ? null : WORDS.dispatch, text: close.title }
  }
  const officer = recorded(a.on_scene_at) ?? recorded(a.en_route_at) ?? recorded(a.dispatched_at)
  const lines = helpStatus(a, reach)
  if (officer !== null || recorded(a.acknowledged_at) !== null) return lines[0] ? { from: WORDS.dispatch, text: lines[0] } : null
  // Drill mode: nothing was sent to anybody, so no count is coming — the phone says so, with 119 in case it is real.
  if (a.drill_mode === true) return { from: null, text: drillModeBubble(emergencyNumber) }
  // alertReach's own words for a raise that reached no officer's phone — said by the phone, with 119 directly below.
  if (reach.reachedNobody) return { from: null, text: `${reach.headline}. Do not wait for this. Call ${emergencyNumber} now.` }
  return null
}

export type BoardInput = {
  phase: BoardPhase
  /** What kind of emergency, as the hero says it: "General emergency". */
  label: string
  alert: AlertAnswer | null
  /** The moment of the press on this phone. */
  pressedAt: string | null
  withPosition: boolean
  reach: AlertReach
  /** Why a held alert is held — the hook's own sentence (offline, slow, or a bad answer). */
  held: string
  /** Held for want of any signal ("Not sent yet"), not for a slow or bad answer ("Not confirmed yet"). */
  noSignal: boolean
  /** Why an alert will never be sent, in the hook's words; null while it still can be. */
  failure: string | null
  /** A read-only demonstration session: the press is refused before anything is captured, and it says so first. */
  demoRefusal: string | null
  /** `drillModeLine()`: drill mode is on for this account, said before the press; null when it is off. */
  drillMode: string | null
  holdMs: number
  emergencyNumber: string
  /** "9:41 AM" — with the day in front when it is not today at the estate. */
  at: (iso: string) => string
  /** "9:41:02 AM". */
  atSeconds: (iso: string) => string
}

/** Every slot of the screen, for one state. */
export function panicBoard(input: BoardInput): PanicBoard {
  const { phase, label, alert: a } = input
  const sentAt = a ? recorded(a.server_time) : null

  switch (phase) {
    case 'arm':
      return { title: WORDS.hold, meta: input.demoRefusal ?? input.drillMode ?? holdNote(input.holdMs), steps: [], bubble: null, audioOn: false }
    case 'failed':
      // It will never be sent. Said in the gold line, under the way to send a new one.
      return { title: WORDS.hold, meta: input.failure ?? '', steps: [], bubble: null, audioOn: false }
    case 'sending':
      return { title: WORDS.sending, meta: WORDS.sendingNote, steps: [], bubble: null, audioOn: false }
    case 'queued':
      return {
        title: input.noSignal ? WORDS.notSent : WORDS.notConfirmed,
        meta: input.pressedAt ? `${label} · Pressed ${input.at(input.pressedAt)}` : label,
        steps: boardSteps(null, { reachedNobody: false, withPosition: false, atSeconds: input.atSeconds }),
        // Nobody has it yet (or it is not confirmed): the phone's own sentence, never "Dispatch:".
        bubble: { from: null, text: input.held },
        audioOn: false,
      }
    case 'active':
    case 'closed': {
      // A DRILL says so first (M10 §1.3), on the gold line under the title; drill mode says it in the title too.
      const drill = a?.is_drill === true || a?.drill_mode === true ? 'DRILL · ' : ''
      return {
        title: phase === 'closed' ? WORDS.closed : a?.drill_mode === true ? WORDS.drillSent : WORDS.sent,
        meta: `${drill}${sentAt ? `${label} · Sent ${input.at(sentAt)}` : label}`,
        steps: boardSteps(a, { reachedNobody: input.reach.reachedNobody, withPosition: input.withPosition, atSeconds: input.atSeconds }),
        bubble: boardBubble(a, input.reach, input.emergencyNumber),
        audioOn: false,
      }
    }
  }
}

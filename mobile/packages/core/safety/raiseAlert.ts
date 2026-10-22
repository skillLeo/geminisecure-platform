/**
 * RAISING AN ALERT — a resident's panic and a guard's duress are the same act, and this is it.
 *
 * INVARIANT 8 · UNDER THREE SECONDS, BEFORE ANYTHING ELSE. From the confirmed press to the
 * request leaving the phone, nothing is waited for that is not the alert itself:
 *
 *   position   asked for, but only for POSITION_BUDGET_MS. A cold GPS takes ten seconds; an alert
 *              with no position in half a second beats an alert with one in ten. No fix → sent without.
 *   media      never. No photo, no audio, no upload of any kind is part of raising an alert.
 *   the queue  never. A panic does not wait its turn behind queued checkpoint scans: it is sent
 *              DIRECTLY, at once.
 *
 * AND IT CANNOT BE LOST. The alert is captured durably in the outbox FIRST (a disk write,
 * milliseconds), then sent directly with the SAME Idempotency-Key and the SAME body — the server
 * refuses a reused key with a different body, and replays its first answer for an identical one —
 * so if the app is killed mid-send, or the send fails, the queue still holds it and sends it the
 * moment any signal returns, and it can never become two alerts.
 *
 * SAFETY NEVER DEPENDS ON CONNECTIVITY OR CONSENT. No signal, location refused, anything throwing:
 * the outcome is `queued`, said honestly, and the screen offers the phone's own dialler and text
 * message — which need no data — at the same moment.
 *
 * Pure: everything it touches arrives as an argument, so it is tested with the world switched off.
 */

import { isRefusal, type ApiError } from '../api/errors'
import type { WriteStamp } from '../api/write'

export const POSITION_BUDGET_MS = 400
export const SEND_BUDGET_MS = 2500
/** Invariant 8's number. The two budgets above sum to less, with room for the disk write. */
export const ALERT_DEADLINE_MS = 3000

/**
 * WHAT KIND OF EMERGENCY, IF THE PERSON COULD SAY (M10 §1) — the server's own four values for
 * `duress.store`'s optional `type`, and nothing else. `medical` and `fire` become the alert's kind;
 * `security` and `other` stay a plain panic. It is NEVER required and never waited for.
 *
 * Checked against this list before it goes into the body, because the server refuses a value it does
 * not know — and a refused alert is one nobody received. A choice that is not one of these four is
 * dropped, and the alert goes exactly as an unlabelled one does.
 */
export const ALERT_TYPES = ['medical', 'fire', 'security', 'other'] as const
export type AlertType = (typeof ALERT_TYPES)[number]
export const isAlertType = (value: unknown): value is AlertType => typeof value === 'string' && (ALERT_TYPES as readonly string[]).includes(value)

export type AlertBody = { mode: 'silent' | 'audible'; type?: AlertType; latitude?: number; longitude?: number; captured_offline?: true }

/**
 * One message on the panic's live channel with dispatch (M50 §4.2 #2): the resident's (`you`) or the control room's.
 * `seen_at` is when the OTHER side was shown it — "Sent" until then, "Delivered" after. `client_key` is the phone's own
 * id for one of its own messages, how a message it queued is matched to the server's row.
 */
export type AlertMessage = { id: number; from: 'you' | 'dispatch'; name: string | null; body: string; at: string; seen_at: string | null; client_key?: string | null }

export type AlertAnswer = {
  id: number | string
  /** The live channel with dispatch, oldest first. Absent from an older server or an alert saved before it existed. */
  messages?: AlertMessage[]
  /** `panic`, `medical` or `fire` for a resident's alert, `duress` for a guard's. Optional: an alert saved by an older build has none. */
  kind?: string
  status: string
  cancellable_until: string
  acknowledged_at: string | null
  cancelled_at: string | null
  /**
   * HOW FAR THE RESPONSE HAS GOT (M10 §1.3): sent, on the way, there, closed — each a UTC instant or null,
   * and each ticks its own step and nothing else (`alertProgress.ts`). A step may stay null for good (an
   * officer who ran straight there has no en-route time) and is then drawn "Not recorded". Optional for
   * the same reason `alerted_handsets` is: an alert saved on the phone before the fields existed has none.
   */
  dispatched_at?: string | null
  en_route_at?: string | null
  on_scene_at?: string | null
  resolved_at?: string | null
  /** A DRILL raised by `panic:walk` to test the fan-out — never by a handset. Only `true` is a drill (`drill.ts`). */
  is_drill?: boolean | null
  /**
   * Pressed while the office had DRILL MODE on for this account (M53 §3A.1): a drill that rang no officer, emailed and
   * texted nobody and sounded no siren. Only the server decides it. Only `true` counts; absent on an older server.
   */
  drill_mode?: boolean | null
  server_time: string
  /**
   * WHAT THE ALERT ACTUALLY REACHED. The server counts the handsets it sent
   * to and says so; `alertReach.ts` turns that into the sentence a person reads.
   *
   * OPTIONAL, AND NOT BECAUSE IT MIGHT BE MISSING FROM THE SERVER. An alert this phone saved
   * before this field existed comes back off disk without it, and `alertReach` must read that
   * as "not known yet" rather than as zero — the one thing it may never do is invent either
   * answer. `alerted_handsets` is `number | null` for the same reason: null is "still
   * reaching", 0 is "nobody", and they are different sentences.
   */
  console_alerted?: boolean
  alerted_handsets?: number | null
  alerted_via?: string | null
  escalated_contacts?: number | null
  /**
   * THE CALL-STYLE RING (M18 §1.6): officer phones the ring has been SENT to, in any round — including the ring to
   * every officer of the estate that goes at once when the raise reached none. Null while the ring has not begun, and
   * absent from an older server or a saved answer; either way it changes nothing. `alertReach` reads it only to stop
   * saying "no officer's phone was reached" while officers' phones are ringing.
   */
  rang_handsets?: number | null
  /** When the ring first went to a phone — the time the "Officers alerted" tick carries when the ring alerted them (M18A). */
  rang_at?: string | null
  /**
   * WHO WAS CLOCKED ON AT THE RAISE (M18A §1.2). With `alerted_handsets` 0 it tells "nobody is on duty" (0) from
   * "officers are on duty but their phones could not be reached" (above 0). Null or absent: claim neither.
   */
  on_duty_officers?: number | null
  /**
   * THE COMMUNITY'S OWN EMERGENCY NUMBERS, TEXTED (Q-M09). `sms_recipients` is how many
   * were attempted; `sms_delivered` is how many a PROVIDER ACCEPTED — which is 0 on every
   * alert today, because no text-message provider has been bought and the server records
   * the message rather than sending it.
   *
   * NEITHER IS EVER ADDED TO `alerted_handsets`, here or anywhere. A text to a committee
   * member is not an officer being sent, and the one number the panic screen decides
   * "is anybody coming?" on has to stay the number of officer phones.
   */
  sms_recipients?: number | null
  sms_delivered?: number | null
  /**
   * WHAT THE TEXTS ACTUALLY DID (M10 §1, now in the catalogue). `delivered` is the only number that may
   * ever produce a sentence about a text; `provider_connected: false` means nothing can have been
   * delivered, whatever else is said. `note` is the server's own sentence about texts. Optional: an alert
   * saved before the server sent it has none, and the app is honest with the fields above alone.
   */
  sms?: {
    provider_connected?: boolean | null
    attempted?: number | null
    delivered?: number | null
    finished?: boolean | null
    note?: string | null
    /**
     * THE HOUSEHOLD'S OWN CONTACTS, texted on a resident's panic (s1-contacts) — a different audience from
     * everything above, and never added to it. Null on an officer's duress. Read by `householdContacts.ts`
     * and by nothing else.
     */
    household?: { attempted?: number | null; delivered?: number | null; finished?: boolean | null } | null
  } | null
  /**
   * THE SAME CONTACTS BY EMAIL (M12 §2.5), which goes today. `sent` is what the mail server ACCEPTED, and the only
   * number that may put "emailed" on the screen. Null on an officer's duress; absent on an older server. Read by
   * `householdContacts.ts` and by nothing else.
   */
  email?: {
    household?: {
      recipients?: number | null
      sent?: number | null
      failed?: number | null
      finished?: boolean | null
      /** Of `sent`, how many went to the household's own members, and how many to its emergency contacts (M18C §4.3). */
      members_sent?: number | null
      contacts_sent?: number | null
    } | null
  } | null
}

export type RaiseDeps = {
  /** Fixed at the PRESS — before this function is even called. */
  stamp: WriteStamp
  /** What the phone believes about its connection at the press. Advisory: the send is tried either way. */
  online: boolean
  position: () => Promise<{ latitude: number; longitude: number } | null>
  /** Durable capture: resolves only once the alert is on disk. */
  capture: (body: AlertBody, stamp: WriteStamp) => Promise<{ id: string }>
  send: (body: AlertBody, stamp: WriteStamp) => Promise<{ data: AlertAnswer } | { error: ApiError }>
  /** Tell the queue the direct send was answered, so it never sends it again. */
  settle: (opId: string, answer: AlertAnswer) => Promise<void>
  clock: () => number
}

export type RaiseOutcome =
  | { state: 'sent'; alert: AlertAnswer; opId: string | null; elapsedMs: number; withPosition: boolean }
  | { state: 'queued'; opId: string | null; elapsedMs: number; withPosition: boolean; why: 'no_signal' | 'slow' | 'server' | 'refused'; message: string | null }

const within = <T>(work: Promise<T>, ms: number, otherwise: T): Promise<T> =>
  new Promise((resolve) => {
    const timer = setTimeout(() => resolve(otherwise), ms)
    work.then(
      (value) => { clearTimeout(timer); resolve(value) },
      () => { clearTimeout(timer); resolve(otherwise) },
    )
  })

export async function raiseAlert(deps: RaiseDeps, input: { mode: 'silent' | 'audible'; type?: AlertType | null }): Promise<RaiseOutcome> {
  const started = deps.clock()

  // 1 · A position, if the phone has one to hand. Never waited for beyond the budget; never a reason not to send.
  const fix = await within(Promise.resolve().then(deps.position), POSITION_BUDGET_MS, null)

  // The body is fixed ONCE: what is captured and what is sent must be the same request. With no type chosen
  // it is byte for byte the body this function always sent — the key is absent, not null.
  const body: AlertBody = {
    mode: input.mode,
    ...(isAlertType(input.type) ? { type: input.type } : null),
    ...(fix ? { latitude: fix.latitude, longitude: fix.longitude } : null),
    ...(deps.online ? null : { captured_offline: true as const }),
  }

  // 2 · On disk first — a killed app, a dead battery, a crash: the alert is already safe.
  let opId: string | null = null
  try {
    opId = (await deps.capture(body, deps.stamp)).id
  } catch {
    opId = null // a full disk must not stop an alert: it is still sent below
  }

  // 3 · Sent directly, now — not in the queue's turn. Bounded, so the person is never left watching a spinner.
  const direct = Promise.resolve().then(() => deps.send(body, deps.stamp))
  const answer = await within<{ data: AlertAnswer } | { error: ApiError } | 'slow'>(direct, SEND_BUDGET_MS, 'slow')
  const elapsedMs = deps.clock() - started
  const withPosition = fix !== null

  if (answer !== 'slow' && 'data' in answer) {
    if (opId) await deps.settle(opId, answer.data).catch(() => undefined)
    return { state: 'sent', alert: answer.data, opId, elapsedMs, withPosition }
  }

  // Not answered in time, or not at all: the queue holds it and will send it with the same key.
  if (answer === 'slow') {
    /*
     * A SLOW ANSWER IS STILL AN ANSWER. The request did not stop when the budget ran out, and on a
     * slow line (found over a tunnel to a test server, 2026-09-22: 2.6 s) the server had RECORDED the
     * alert while the screen said "not received yet" — and, the phone never having lost its signal,
     * no reconnect ever came to make the queue send it again. So the direct send is kept listening:
     * when it lands, the queue is told, and the screens that watch the queue turn "saved" into "sent".
     */
    if (opId) {
      const id = opId
      void direct.then((late) => ('data' in late ? deps.settle(id, late.data) : undefined)).catch(() => undefined)
    }
    return { state: 'queued', opId, elapsedMs, withPosition, why: 'slow', message: null }
  }
  const e = answer.error
  // The queue's own rule (B126): a 429, a 401 or "still in progress" is not a refusal — the queue goes on sending it.
  const refused = isRefusal(e)
  return { state: 'queued', opId, elapsedMs, withPosition, why: e.status === 'network' || e.status === 'timeout' ? 'no_signal' : refused ? 'refused' : 'server', message: refused ? e.message : null }
}

/* ---- a held alert is offered again, and again, until it is answered ------------------------ */

export const RETRY_HELD_ALERT_MS = 5000
const offering = new Map<string, ReturnType<typeof setInterval>>()

/**
 * WHILE AN ALERT IS HELD ON THE PHONE, KEEP OFFERING IT. The queue drains when the connection comes
 * BACK — but a send can fail with the phone online the whole time (a slow line, a server hiccup),
 * and then there is no "back" to wait for: the alert would sit saved and unsent until something
 * else happened to drain the queue. So every few seconds the held act is sent again, ON ITS OWN
 * (`outbox.offer`, the same key and body) — not by asking the queue to drain, which used to put it
 * back in line behind whatever the drain was busy with (B125). Until it is confirmed or refused, or
 * has left the queue. One timer per act however often this is called; safe from any screen, and
 * from none — a silent duress raised from Home has no screen open at all.
 */
export function keepOffering(opId: string, deps: { state: (id: string) => string | undefined; resend: () => Promise<void> }, every = RETRY_HELD_ALERT_MS): () => void {
  const stop = () => {
    const timer = offering.get(opId)
    if (timer !== undefined) clearInterval(timer)
    offering.delete(opId)
  }
  if (offering.has(opId)) return stop
  offering.set(
    opId,
    setInterval(() => {
      const state = deps.state(opId)
      if (state === undefined || state === 'confirmed' || state === 'refused') return stop()
      void deps.resend().catch(() => undefined)
    }, every),
  )
  return stop
}

/* ---- what the person is told ---------------------------------------------------------------- */

/*
 * THE TIMELINE MOVED TO `alertProgress.ts` (M10 §1.3), and is re-exported here so every import of it
 * keeps working. The first version drew four steps from one `status`, because the server had no field
 * for "dispatched", "en route" or "on scene" and said "Not reported yet" where one would go. The server
 * now records all six steps, and each is ticked by its own field and by nothing else.
 */
export { alertSteps, type AlertStep } from './alertProgress'

/**
 * AN ALERT AT THIS SITE, AS THE OFFICER RESPONDING SEES IT (M10 §1.3) — the pure half, and what is kept.
 *
 * `alerts.show` says where (a LOT, never a person), what kind, how far the response has got, and whether
 * THIS handset may record the next step: `can_en_route`, `can_on_scene`, and `reason` when it may not.
 * `alerts.en_route` / `alerts.on_scene` record "on my way" and "I am there" — writes, captured on the
 * phone first and sent through the one write path every guard write takes (`submit`).
 *
 * THE BUTTONS ARE THE SERVER'S. One is drawn only when its `can_*` is exactly `true`; otherwise, when the
 * server says why, its `reason` is shown as given. The phone never works out for itself who may respond —
 * that rule is `AlertProgress` on the server, and the phone would get it wrong the first time a
 * dispatcher sent somebody else.
 *
 * EVERY REFUSAL IS A SENTENCE (`refusalSentence`). The server's own message is used where it carries the
 * one fact worth having — the officer's name, the time — and is replaced where it is written for a
 * developer ("Follow it with GET /duress/41").
 *
 * A DRILL IS THE ANSWER'S OWN WORD. `alerts.show`, `alerts.en_route` and `alerts.on_scene` carry `is_drill`
 * (server 8c95dfe), so an alert opened straight from the "You are dispatched" push is labelled DRILL with
 * nothing pulled first (`isDrillAlert`). The flag `sync.pull` brought is kept too, and read only for an
 * answer this phone kept before the server sent the field.
 *
 * WHAT IS KEPT ON THE PHONE. The alerts `sync.pull` brought, and the last answer for each with the time it
 * came, so the screen can show it with its age when there is no signal. The last 20, for a day. It goes
 * with the rest of the key-value store at sign-out (`clearEverything`).
 *
 * Pure, except the four store functions at the end.
 */

import type { Api } from '@gs/core/api'
import { getStorage, jsonStore } from '@gs/core/lib'
import { alertTitle } from '@gs/core/notify'
import { NOT_RECORDED, isClosed, progressStates } from '@gs/core/safety'

export type AlertView = Api.AlertsShowResponse

export const KEEP_HOURS = 24
export const KEEP_MOST_RECENT = 20

const ID = /^[A-Za-z0-9_-]{1,40}$/

/** An id this phone will ask the server about — the payload's, checked. */
export const alertId = (value: unknown): string | null => {
  const v = typeof value === 'number' && Number.isFinite(value) ? String(Math.trunc(value)) : typeof value === 'string' ? value : ''
  return ID.test(v) ? v : null
}

const text = (value: unknown, max: number): string | null => {
  if (typeof value !== 'string') return null
  const one = value.replace(/\s+/g, ' ').trim()
  return one === '' ? null : one.slice(0, max)
}

/* ---- what is drawn ------------------------------------------------------------------------------ */

/** "At Lot 47" — a place, never a person. Another officer's duress carries no lot. */
export function whereLine(kind: unknown, where: unknown): string {
  const place = text(where, 60)
  if (place) return `At ${place}`
  return kind === 'duress' ? 'An officer at your site needs help: ask dispatch where' : 'Where: not given: ask dispatch'
}

export const kindTitle = (kind: unknown): string => alertTitle(kind)

/** Who is responding, as the server names them — an officer, never the person who raised it. */
export function responderLine(a: Pick<AlertView, 'responder' | 'responder_is_you' | 'dispatched_to_you'> | null): string | null {
  if (!a) return null
  if (a.responder_is_you === true) return a.dispatched_to_you === true ? 'You were dispatched to this alert.' : 'You are the officer responding to this alert.'
  const name = text(a.responder, 80)
  return name ? `${name} is the officer responding.` : 'No officer is recorded as responding yet.'
}

export type Step = { key: string; title: string; detail: string | null; done: boolean }

/**
 * The response so far, with the same honesty as the resident's screen: a step is ticked only when its time
 * is recorded, and one skipped says "Not recorded". The close is drawn only once it has happened.
 */
export function responseSteps(a: AlertView, when: (iso: string) => string): Step[] {
  const states = progressStates({ status: a.status, acknowledged_at: a.acknowledged_at, dispatched_at: a.dispatched_at, en_route_at: a.en_route_at, on_scene_at: a.on_scene_at, resolved_at: a.resolved_at })
  const NAMES: Record<string, string> = {
    acknowledged: 'Control room acknowledged',
    dispatched: a.dispatched_to_you === true ? 'You were dispatched' : 'Officer dispatched',
    en_route: 'Officer on the way',
    on_scene: 'Officer at the scene',
    resolved: a.status === 'false_alarm' ? 'Closed as a false alarm' : 'Closed',
  }
  const raised: Step = { key: 'raised', title: 'Alert raised', detail: a.raised_at ? when(a.raised_at) : null, done: true }
  return [
    raised,
    ...states
      .filter((s) => s.key !== 'resolved' || s.state === 'done')
      .map((s): Step => ({
        key: s.key,
        title: s.key === 'acknowledged' && s.state === 'current' ? 'Waiting for the control room' : NAMES[s.key]!,
        detail: s.state === 'done' && s.at ? when(s.at) : s.state === 'not_recorded' ? NOT_RECORDED : null,
        done: s.state === 'done',
      })),
  ]
}

/* ---- the two buttons ---------------------------------------------------------------------------- */

export type ResponseStep = 'en_route' | 'on_scene'

export type Actions = {
  /** Draw "En route". Only when the server said `can_en_route: true`, and nothing further is already on its way up. */
  enRoute: boolean
  /** Draw "On scene". Only when the server said `can_on_scene: true`, and "on scene" is not already on its way up. */
  onScene: boolean
  /** The server's sentence for why "I am there" cannot be recorded from here — shown as given, when there is one. */
  reason: string | null
}

/**
 * @param waiting the acts this phone has captured and not yet had answered, by step. A tap already on its
 *   way up is not offered twice: a second one would only earn "already recorded".
 */
export function responseActions(a: Pick<AlertView, 'can_en_route' | 'can_on_scene' | 'reason' | 'status' | 'resolved_at'> | null, waiting: Partial<Record<ResponseStep, boolean>> = {}): Actions {
  if (!a) return { enRoute: false, onScene: false, reason: null }
  const closed = isClosed({ status: a.status, resolved_at: a.resolved_at })
  return {
    enRoute: !closed && a.can_en_route === true && !waiting.en_route && !waiting.on_scene,
    onScene: !closed && a.can_on_scene === true && !waiting.on_scene,
    reason: text(a.reason, 300),
  }
}

/* ---- every refusal, in a sentence --------------------------------------------------------------- */

export type Refusal = { status?: unknown; code?: unknown; message?: unknown } | null | undefined

/** A server sentence fit for the person holding the phone — not empty, not endless, and not written for a developer. */
const plain = (message: unknown): string | null => {
  const m = text(message, 300)
  return m && !/\b(GET|POST)\s*\/|\/api\/|https?:|\{|\}|Exception|SQLSTATE/i.test(m) ? m : null
}

/**
 * THE SENTENCE FOR EVERY CODE `alerts.show`, `alerts.en_route` and `alerts.on_scene` can answer.
 *
 * @param step what was being done: reading the alert, or recording a step
 */
export function refusalSentence(error: Refusal, step: 'show' | ResponseStep): string {
  const code = typeof error?.code === 'string' ? error.code : ''
  const server = plain(error?.message)
  const recording = step !== 'show'
  switch (code) {
    case 'own_alert':
      // The server's own words here point a developer at GET /duress; the officer is pointed at their Duress screen.
      // Nor does it say help is being sent: whether anybody has been is on that screen, from the server.
      return 'This is your own alert, so you cannot be the officer sent to it. Follow it on your Duress screen.'
    case 'not_on_shift':
      return recording
        ? 'This alert was not sent to you, you are not on shift at this estate, so you cannot respond to it from this handset. If you are going, tell dispatch by radio.'
        : 'This alert was not sent to you: you are not on shift at this estate, so this handset cannot follow it.'
    case 'another_officer_responding':
      return server ?? 'Another officer is responding to this alert, so only their handset can record it. If you are going too, tell dispatch by radio.'
    case 'not_found':
      return 'There is no alert with this number at your site. If it matters now, call the control room.'
    case 'alert_closed':
      return server ?? 'This alert is closed, so nothing more can be recorded.'
    case 'already_en_route':
      return server ?? '"On my way" is already recorded for this alert.'
    case 'already_on_scene':
      return server ?? '"At the scene" is already recorded for this alert, and the response only moves forward.'
    case 'wrong_app':
      return 'This phone is not signed in as a Guard App handset, so it cannot do this. Ask your supervisor to enrol it again.'
    default:
      if (error?.status === 'network' || error?.status === 'timeout') {
        return recording ? 'No signal. It is saved on this phone and sent the moment signal returns: radio dispatch meanwhile.' : 'No signal to fetch this alert. Move to where you have signal, and radio dispatch meanwhile.'
      }
      return server ?? (recording ? 'The server did not accept this. Radio dispatch.' : 'This alert could not be fetched. Radio dispatch.')
  }
}

/* ---- what happened to a tap --------------------------------------------------------------------- */

export type Act = { state: 'pending' | 'syncing' | 'confirmed' | 'refused'; attempts: number; error: { status: unknown; code: string; message: string } | null }

export type ActLine = { tone: 'info' | 'bad' | 'ok'; text: string }

const WHAT: Record<ResponseStep, string> = { en_route: '"On my way"', on_scene: '"I am there"' }

/** The line under the buttons after a tap: saved, sent, recorded — or refused, and why. */
export function actLine(act: Act | null | undefined, step: ResponseStep, online: boolean): ActLine | null {
  if (!act) return null
  if (act.state === 'confirmed') return { tone: 'ok', text: `${WHAT[step]} is recorded. Radio dispatch as your orders say.` }
  if (act.state === 'refused') return { tone: 'bad', text: refusalSentence(act.error, step) }
  if (act.state === 'syncing') return { tone: 'info', text: `Sending ${WHAT[step]}…` }
  const offline = !online || act.error?.status === 'network' || act.error?.status === 'timeout'
  if (offline) return { tone: 'info', text: `${WHAT[step]} is saved on this phone and sent the moment signal returns. Radio dispatch meanwhile.` }
  if (act.attempts > 0 && act.error) return { tone: 'info', text: `${WHAT[step]} is not through yet: ${plain(act.error.message) ?? 'the server did not answer'}. It is kept on this phone and tried again. Radio dispatch meanwhile.` }
  return { tone: 'info', text: `${WHAT[step]} is saved on this phone: sending now.` }
}

/* ---- how old the last answer is ----------------------------------------------------------------- */

/**
 * "Last updated 4 minutes ago — this may be out of date." Only when the answer on screen is NOT fresh;
 * the response moves on without this handset, and a guard must not read an old answer as the current one.
 */
export function staleLine(answeredAt: string | null, at: Date, fresh: boolean): string | null {
  if (fresh || !answeredAt) return null
  const then = Date.parse(answeredAt)
  if (Number.isNaN(then)) return null
  const minutes = Math.max(0, Math.floor((at.getTime() - then) / 60_000))
  const age = minutes < 1 ? 'less than a minute ago' : minutes < 60 ? `${minutes} minute${minutes === 1 ? '' : 's'} ago` : `${Math.floor(minutes / 60)} hour${Math.floor(minutes / 60) === 1 ? '' : 's'} ago`
  return `Last updated ${age}: this may be out of date. The response moves on without this handset.`
}

/* ---- what is kept on the phone ------------------------------------------------------------------ */

export type KeptAlert = {
  id: string
  kind: string
  where: string | null
  raisedAt: string
  /** What `sync.pull` or the last answer said about DRILL. Null is "not known". The answer's own `is_drill` outranks it (`isDrillAlert`). */
  drill: boolean | null
  answer: AlertView | null
  answeredAt: string | null
}

/** One row of `sync.pull`'s `alerts`, as loosely as it may arrive. */
export type PulledAlert = { alert_id?: unknown; kind?: unknown; where?: unknown; raised_at?: unknown; is_drill?: unknown }

export function toKept(raw: PulledAlert | null | undefined): KeptAlert | null {
  if (!raw) return null
  const id = alertId(raw.alert_id)
  const raisedAt = typeof raw.raised_at === 'string' && !Number.isNaN(Date.parse(raw.raised_at)) ? raw.raised_at : null
  if (!id || !raisedAt) return null
  return { id, kind: text(raw.kind, 20) ?? 'panic', where: text(raw.where, 60), raisedAt, drill: raw.is_drill === true ? true : raw.is_drill === false ? false : null, answer: null, answeredAt: null }
}

/**
 * WHETHER TO LABEL THIS ALERT DRILL. The answer's own `is_drill` when it carries one — true or false, it
 * is the server speaking about this alert now. Only an answer kept before the field existed (no
 * `is_drill` at all) falls back to what `sync.pull` said. Nothing but a positive `true` is a drill.
 */
export function isDrillAlert(kept: Pick<KeptAlert, 'drill' | 'answer'> | null | undefined): boolean {
  const own = (kept?.answer as { is_drill?: unknown } | null | undefined)?.is_drill
  if (typeof own === 'boolean') return own
  return kept?.drill === true
}

/** One row per alert, newest first, the last day's only, at most `KEEP_MOST_RECENT`. A drill stays a drill; an answer is never lost to a re-pull. */
export function mergeKept(kept: readonly KeptAlert[], incoming: readonly KeptAlert[], at: Date): KeptAlert[] {
  const oldest = at.getTime() - KEEP_HOURS * 3_600_000
  const byId = new Map<string, KeptAlert>()
  for (const row of [...kept, ...incoming]) {
    if (Date.parse(row.raisedAt) < oldest) continue
    const before = byId.get(row.id)
    // The newest word on DRILL wins; a row that says nothing about it (null) never erases what was said.
    byId.set(row.id, before ? { ...before, ...row, drill: row.drill ?? before.drill, answer: row.answer ?? before.answer, answeredAt: row.answer ? row.answeredAt : before.answeredAt } : row)
  }
  return [...byId.values()].sort((a, b) => Date.parse(b.raisedAt) - Date.parse(a.raisedAt)).slice(0, KEEP_MOST_RECENT)
}

const box = () => jsonStore<KeptAlert[]>(getStorage(), 'alerts.kept', 'v1')

const read = (): KeptAlert[] => {
  const stored = box().get()
  return Array.isArray(stored) ? stored : []
}

/** Called by the poller with whatever `sync.pull` returned. Never throws: a banner is worth more than a store. */
export function rememberAlerts(raw: readonly PulledAlert[] | null | undefined, at: Date): void {
  try {
    const incoming = (raw ?? []).map(toKept).filter((row): row is KeptAlert => row !== null)
    if (incoming.length > 0) box().set(mergeKept(read(), incoming, at))
  } catch {
    /* a store that cannot be written never stops the banner */
  }
}

/** What this phone knows of one alert, or null. */
export function keptAlert(id: string, at: Date): KeptAlert | null {
  try {
    return mergeKept(read(), [], at).find((row) => row.id === id) ?? null
  } catch {
    return null
  }
}

/** The server answered about this alert: keep it, with the time, for when there is no signal. Never loses the answer, even if the store cannot be written. */
export function keepAnswer(id: string, answer: AlertView, at: Date): KeptAlert {
  const fromAnswer: KeptAlert = { id, kind: text(answer.kind, 20) ?? 'panic', where: text(answer.where, 60), raisedAt: typeof answer.raised_at === 'string' && !Number.isNaN(Date.parse(answer.raised_at)) ? answer.raised_at : at.toISOString(), drill: typeof answer.is_drill === 'boolean' ? answer.is_drill : null, answer, answeredAt: at.toISOString() }
  try {
    const next = mergeKept(read(), [fromAnswer], at)
    box().set(next)
    return next.find((row) => row.id === id) ?? fromAnswer
  } catch {
    return fromAnswer
  }
}

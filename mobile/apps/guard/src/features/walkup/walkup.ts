/**
 * The walk-up path's pure logic — no React, no UI kit, tested under Jest.
 *
 *   countdown()      the real time left to `respond_by`, corrected for a handset clock that is wrong
 *   waitView()       status + countdown → the ONE state the waiting screen draws. It never hangs:
 *                    a request nobody answered becomes `expired` on the phone's own count, signal or not.
 *   waitCopy()       the words for that state. The server's `guidance` sentence is shown whenever
 *                    it is about the state being drawn.
 *   activityRow()    a gate event → the log row          queuedRows()  this phone's unsent gate acts → rows
 *
 * A household's standing is not in any of these responses today. If it ever is, it is a boolean
 * and the screen shows the one fixed sentence (features/gate/verdict.ts) — nothing here words it.
 */

// From the lib entry, not the package root: this file is pure, and is tested without the UI kit loaded.
import { siteParts, time12 } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

// ───────────────────────────────────────────────────────────── the countdown

/** The server gives the household three minutes; used only when `requested_at` is unknown. */
export const WAIT_FALLBACK_S = 180
/** `stroke-dasharray` of the board's ring (r = 52). */
export const RING_LENGTH = 327
/** Below this a "skew" is only the request's own travel time. */
const SKEW_NOISE_MS = 2_000

export type Countdown = {
  /** False when there is no `respond_by` to count to yet. */
  known: boolean
  remainingS: number
  totalS: number
  /** 1 → the whole window is left, 0 → none. */
  fraction: number
  /** "01:24", as the board draws it. */
  label: string
  over: boolean
}

const two = (n: number) => String(n).padStart(2, '0')
const clamp = (n: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, n))
const ms = (iso: string | null | undefined): number => (iso ? new Date(iso).getTime() : Number.NaN)

/** How far the handset's clock is from the server's, from one answer's `server_time`. */
export function clockSkewMs(serverTime: string | null | undefined, deviceNowMs: number): number {
  const skew = ms(serverTime) - deviceNowMs
  return Number.isFinite(skew) && Math.abs(skew) >= SKEW_NOISE_MS ? skew : 0
}

/**
 * ELAPSED TIME, not a time of day: the only clock that matters here is the handset's own, corrected by the
 * skew the server reported. It has no zone and is never formatted through lib/siteTime.ts — a
 * countdown reads the same in Kingston, Miami and Tokyo.
 */
export function countdown(input: { requestedAt: string | null; respondBy: string | null; nowMs: number; skewMs?: number }): Countdown {
  const end = ms(input.respondBy)
  if (!Number.isFinite(end)) return { known: false, remainingS: 0, totalS: WAIT_FALLBACK_S, fraction: 0, label: '--:--', over: false }

  const start = ms(input.requestedAt)
  const totalMs = Number.isFinite(start) && end > start ? end - start : WAIT_FALLBACK_S * 1000
  const leftMs = Math.max(0, end - (input.nowMs + (input.skewMs ?? 0)))
  const remainingS = Math.ceil(leftMs / 1000)

  return {
    known: true,
    remainingS,
    totalS: Math.round(totalMs / 1000),
    fraction: clamp(leftMs / totalMs, 0, 1),
    label: `${two(Math.floor(remainingS / 60))}:${two(remainingS % 60)}`,
    over: leftMs <= 0,
  }
}

/** The amber arc's `stroke-dashoffset`: 0 draws the whole ring, RING_LENGTH none of it. */
export const ringOffset = (fraction: number): number => Math.round(RING_LENGTH * (1 - clamp(fraction, 0, 1)))

// ───────────────────────────────────────────────────────────── status → view

export type ApprovalStatus = Api.GateApprovalsShowResponse['status']
export type WaitView = 'waiting' | 'approved' | 'denied' | 'expired'

/** The server has given its last word on this request: stop polling. */
export const isTerminal = (status: string | null | undefined): status is 'approved' | 'denied' | 'expired' => status === 'approved' || status === 'denied' || status === 'expired'

/**
 * The server's answer wins. With none — still pending, or no signal to hear one — the phone's own
 * count decides: past `respond_by` the request is treated as expired, exactly as the server will read it.
 */
export function waitView(status: string | null | undefined, count: Countdown): WaitView {
  if (isTerminal(status)) return status
  return count.known && count.over ? 'expired' : 'waiting'
}

/** The server's sentence for an unanswered request, for when the phone gets there first. */
export const EXPIRED_GUIDANCE = "No answer from the household. Apply the estate's policy for unannounced visitors."

export type WaitCopy = { tone: 'amber-500' | 'green-400' | 'red-500'; title: string; body: string }

export function waitCopy(view: WaitView, facts: { household: string | null; respondedBy: string | null; status: string | null | undefined; guidance: string | null }): WaitCopy {
  const household = facts.household?.trim() || 'the household'
  const who = facts.respondedBy?.trim() || household
  // `guidance` describes the SERVER's status; it is only the right sentence when that is the state being drawn.
  const guidance = facts.status === view && facts.guidance?.trim() ? facts.guidance.trim() : null

  switch (view) {
    case 'approved':
      return { tone: 'green-400', title: `Approved by ${who}`, body: guidance ?? 'Approved by the household. Record the entry with this approval.' }
    case 'denied':
      return { tone: 'red-500', title: `Declined by ${who}`, body: guidance ?? 'The household declined. Do not admit.' }
    case 'expired':
      return { tone: 'amber-500', title: `No answer from ${household}`, body: guidance ?? EXPIRED_GUIDANCE }
    default:
      return { tone: 'amber-500', title: `Waiting for ${household}`, body: "They get a notification in the Resident App with the visitor's details.\nIf nobody answers in time, the estate's policy applies." }
  }
}

// ───────────────────────────────────────────────────────────── what the gate log is told

/** `subject` is capped at 120 by the server (gate.entry and gate-events alike). */
export const SUBJECT_MAX = 120

/** Who the gate log names when there is no pass and no approval: the visitor, and where they were going. */
export function subjectOf(visitorName: string, unit: string | null | undefined): string {
  const name = visitorName.trim().replace(/\s+/g, ' ')
  const where = unit?.trim() ?? ''
  return (where ? `${name} · ${where}` : name).slice(0, SUBJECT_MAX).trim()
}

/** A walk-up request's id is an integer on the server; a route param is a string. */
export function approvalIdOf(raw: string | number | null | undefined): number | null {
  const n = typeof raw === 'number' ? raw : raw && /^\d+$/.test(raw.trim()) ? Number(raw.trim()) : Number.NaN
  return Number.isSafeInteger(n) && n > 0 ? n : null
}

// ───────────────────────────────────────────────────────────── the gate log

export type ActivityItem = Api.GateActivityResponse['items'][number]

export type ActivityResult = 'admit' | 'deny' | 'override' | 'exit' | 'other' | 'pending' | 'syncing' | 'refused'

export type ActivityRow = {
  key: string
  initials: string
  title: string
  sub: string
  time: string
  result: ActivityResult
  /** The result in words — colour and a glyph are never the only signal. */
  resultLabel: string
  /** The server's sentence for an act it refused. */
  note: string | null
  /** This phone's unsent act: the row opens the sync queue. A server row opens nothing (append-only, no detail). */
  queued: boolean
}

export function initialsOf(name: string): string {
  const letters = name.split(/\s+/).map((w) => /[\p{L}\p{N}]/u.exec(w)?.[0]).filter((ch): ch is string => !!ch)
  return letters.length ? letters.slice(0, 2).join('').toUpperCase() : '?'
}

const RESULT_WORD: Record<string, string> = { admit: 'Admitted', deny: 'Denied', override: 'Admitted on override', exit: 'Exit' }
const sentence = (text: string) => (text ? text[0]!.toUpperCase() + text.slice(1) : text)

export function activityRow(item: ActivityItem): ActivityRow {
  // The gate log writes a subject as "who · where" (the server does, and so does this app).
  const [who, ...rest] = item.subject.split(' · ')
  const where = rest.join(' · ').trim()
  const result: ActivityResult = item.verdict === 'admit' || item.verdict === 'deny' || item.verdict === 'override' || item.verdict === 'exit' ? item.verdict : 'other'
  const title = who?.trim() || 'Not named'
  return {
    key: `e:${item.id}`,
    initials: initialsOf(title),
    title,
    sub: [result === 'exit' ? 'Exit' : null, sentence(item.basis.trim()), where || item.category].filter(Boolean).join(' · '),
    time: time12(item.occurred_at),
    result,
    resultLabel: RESULT_WORD[item.verdict] ?? sentence(item.verdict),
    note: null,
    queued: false,
  }
}

const same = (a: string, b: string) => a.trim().replace(/\s+/g, ' ').toLowerCase() === b.trim().replace(/\s+/g, ' ').toLowerCase()

/** The guard's own rows: the log names who recorded each event, and that name is the only key it gives. */
export function mineOnly(items: readonly ActivityItem[], guardName: string | null | undefined): ActivityItem[] {
  const name = guardName?.trim()
  return name ? items.filter((item) => same(item.guard_name ?? '', name)) : []
}

/** What this file needs of an outbox record (structurally the store's `OutboxView`). */
export type QueuedAct = { id: string; label: string; kind: string; state: 'pending' | 'syncing' | 'confirmed' | 'refused'; capturedAt: string; error: { message: string } | null }

const QUEUE_WORD = { pending: 'Pending sync', syncing: 'Syncing', refused: 'Not accepted' } as const

/**
 * This phone's gate acts the server has not taken: waiting, being sent, or refused. Newest first.
 * A confirmed act is in the server's log already and is not drawn twice.
 */
export function queuedRows(ops: readonly QueuedAct[]): ActivityRow[] {
  return ops
    .filter((op): op is QueuedAct & { state: 'pending' | 'syncing' | 'refused' } => op.kind === 'gate' && op.state !== 'confirmed')
    .slice()
    .sort((a, b) => new Date(b.capturedAt).getTime() - new Date(a.capturedAt).getTime())
    .map((op) => {
      // Labels are written "What · who · where" by the screen that captured the act.
      const [act, who, ...rest] = op.label.split(' · ')
      const title = who?.trim() || op.label
      const where = rest.join(' · ').trim()
      return {
        key: `q:${op.id}`,
        initials: initialsOf(title),
        title,
        sub: [QUEUE_WORD[op.state], who ? act : null, where].filter(Boolean).join(' · '),
        time: time12(op.capturedAt),
        result: op.state,
        resultLabel: QUEUE_WORD[op.state],
        note: op.state === 'refused' ? (op.error?.message ?? 'The server did not accept this.') : null,
        queued: true,
      }
    })
}

export type CountTile = { key: string; label: string; value: string; tone: 'green-400' | 'red-500' | 'amber-500' | 'white' }

const count = (n: string | number | null | undefined) => String(Math.max(0, Math.trunc(Number(n)) || 0))

/** The estate's totals for today, as the server counted them. With no log there is no count — a dash, never a zero. */
export function countTiles(counts: Api.GateActivityResponse['counts'] | null | undefined): CountTile[] {
  const value = (n: string | number | undefined) => (counts ? count(n) : '—')
  return [
    { key: 'admitted', label: 'ADMITTED', value: value(counts?.admitted), tone: 'green-400' },
    { key: 'exited', label: 'EXITED', value: value(counts?.exited), tone: 'white' },
    { key: 'overridden', label: 'OVERRIDDEN', value: value(counts?.overridden), tone: 'amber-500' },
    { key: 'denied', label: 'DENIED', value: value(counts?.denied), tone: 'red-500' },
  ]
}

// ───────────────────────────────────────────────────────────── who is on site now (M54 §2.4)

/** A visitor admitted today and not recorded leaving: what "Mark exited" records against. */
export type OnSiteRow = { key: string; initials: string; title: string; sub: string; category: string; subject: string }

/** The label an exit is queued under, as every gate act's is ("What · who · where"), so the list can read it back. */
export const exitLabel = (subject: string): string => `Exit · ${subject}`

/** The subject of a queued exit, from its label; null for any other act. */
export function queuedExitSubject(label: string): string | null {
  return label.startsWith('Exit · ') ? label.slice('Exit · '.length) : null
}

/**
 * WHO IS STILL INSIDE, from today's log (M54 §2.4: "Guard App exit action"). Every admission, on a pass, a decision or
 * an override, not matched by a later exit of the same visitor, estate-wide: a visitor may leave by another gate. The
 * log names a visitor by its subject ("who · where"), so that is the match. An exit this phone has queued and not yet
 * sent counts as recorded, so a row goes the moment it is pressed. Newest arrival first.
 */
export function onSiteNow(items: readonly ActivityItem[], queuedExits: readonly string[]): OnSiteRow[] {
  const key = (subject: string) => subject.trim().replace(/\s+/g, ' ').toLowerCase()
  const inside = new Map<string, ActivityItem[]>()

  for (const item of [...items].sort((p, q) => new Date(p.occurred_at).getTime() - new Date(q.occurred_at).getTime())) {
    const k = key(item.subject)
    if (item.verdict === 'admit' || item.verdict === 'override') inside.set(k, [...(inside.get(k) ?? []), item])
    else if (item.verdict === 'exit') inside.set(k, (inside.get(k) ?? []).slice(1))
  }

  for (const subject of queuedExits) {
    const k = key(subject)
    inside.set(k, (inside.get(k) ?? []).slice(1))
  }

  return [...inside.values()]
    .flat()
    .sort((p, q) => new Date(q.occurred_at).getTime() - new Date(p.occurred_at).getTime())
    .map((item) => {
      const [who, ...rest] = item.subject.split(' · ')
      const title = who?.trim() || 'Not named'
      const where = rest.join(' · ').trim()
      return {
        key: `in:${item.id}`,
        initials: initialsOf(title),
        title,
        sub: [`In since ${time12(item.occurred_at)}`, where || item.category].filter(Boolean).join(' · '),
        category: item.category,
        subject: item.subject,
      }
    })
}

/** A log saved on the phone is only today's log if its date is today's, in Jamaica. */
export function isLogOfToday(date: string | null | undefined, nowDate: Date): boolean {
  const p = siteParts(nowDate)
  return date === `${p.year}-${two(p.month + 1)}-${two(p.day)}`
}

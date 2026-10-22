/**
 * Patrol — the pure part: what a tag read means, how far round the tour the guard is, and
 * what each checkpoint row says. No React, no UI kit: only `@gs/core/lib` and `@gs/core/api`,
 * so every rule here is tested under Jest (__tests__/patrolLogic.test.ts).
 *
 * WHAT THE SERVER GIVES, AND WHAT IT DOES NOT (patrol.checkpoints / patrol.scan):
 *   - the site's checkpoints in tour order, each with this guard's last scan TODAY;
 *   - NOT the tag codes. "The tag itself carries its code": the phone cannot tell whose tag
 *     it has read. A scan is sent against the checkpoint the guard is AT (the next one on the
 *     tour, or the row they pressed) and THE SERVER decides — `422 code_mismatch` is the
 *     wrong-tag answer, in the server's own sentence.
 *   - the one thing the phone does learn: a scan the server CONFIRMED proves code → checkpoint.
 *     While that act is still in the outbox (a day), the same tag read again is recognised,
 *     so a guard standing at Main Gate is recorded at Main Gate even if the phone expected
 *     the Pool Deck. Nothing is stored for this beyond the outbox itself.
 *   - where each checkpoint stands, once the office has placed it on the map (M11 §6), and the site's
 *     placed posts with their geofence — drawn on the tour's map, never compared with this phone's own
 *     position here: the SERVER compares (below), so the phone never claims a distance of its own;
 *   - THE SCAN'S OWN RULES ARE THE SERVER'S (M21 §1.1). The scan carries the newest fix on-duty sharing
 *     has already taken (`sharedFix`, dutyTracker — no new reading, never a wait); the server refuses a
 *     scan made off shift (`not_on_shift`) or outside the checkpoint's circle (`outside_checkpoint`), and
 *     records — and says so in its `note` — a scan made early, without a position, or at a checkpoint not
 *     placed yet. The phone shows those sentences; it derives no due time and no missed flag of its own.
 */

import type { Api } from '@gs/core/api'
import { siteParts, timeCompact } from '@gs/core/lib'

export type CheckpointId = number | string

/** `latitude`/`longitude` are null (or absent, on a board fixture) until the office places the checkpoint on the map. */
export type Checkpoint = { id: CheckpointId; label: string; sequence: number; lastScannedAt: string | null; latitude?: number | null; longitude?: number | null }

/** The part of an outbox act this feature reads (structurally an `OutboxOp`). */
export type ScanOp = {
  id: string
  endpoint: string
  params: Record<string, unknown>
  body: Record<string, unknown> | null
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  attempts: number
  capturedAt: string
  error: { code: string; message: string } | null
  result: unknown
}

/** The server's own rule: `'code' => ['required', 'string', 'max:64']`. */
export const TAG_MAX = 64

/** The words the boards use for a record's sync state. */
export const SYNC_LABEL: Record<ScanOp['state'], string> = { pending: 'Pending sync', syncing: 'Syncing', confirmed: 'Confirmed', refused: 'Not accepted' }

const sameId = (x: unknown, y: unknown) => String(x) === String(y)
const ms = (iso: string) => new Date(iso).getTime()

/** The generated types infer `sequence` as a string; the server sends an integer. The server's order IS the tour order. */
export function toCheckpoints(response: Api.PatrolCheckpointsResponse | null | undefined): Checkpoint[] {
  return (response?.items ?? []).map((item) => ({ id: item.id, label: item.label, sequence: Number(item.sequence), lastScannedAt: item.last_scanned_at, latitude: item.latitude, longitude: item.longitude }))
}

/** "Today" is the server's day — Jamaica's. A list cached yesterday must not show yesterday's tour as walked. */
export function isToday(at: string | null | undefined, nowDate: Date): at is string {
  if (!at) return false
  const x = siteParts(at)
  const n = siteParts(nowDate)
  return x.year === n.year && x.month === n.month && x.day === n.day
}

/** Changes exactly when the site’s day does — the moment the server starts a new tour (a night patrol walks through it). */
export function dayKey(nowDate: Date): string {
  const p = siteParts(nowDate)
  return `${p.year}-${p.month + 1}-${p.day}`
}

/* ---- the tag ------------------------------------------------------------------ */

export type TagVerdict =
  | { kind: 'not_a_tag'; why: 'empty' | 'too_long' }
  /** Record the scan against `checkpointId`. `redirectedFrom` is set when a tag the server has already confirmed belongs elsewhere than the guard's target. */
  | { kind: 'record'; checkpointId: CheckpointId; code: string; redirectedFrom: CheckpointId | null }

/** code → checkpoint, from scans the SERVER confirmed (and only while they are still in the outbox). */
export function learnedCodes(ops: readonly ScanOp[]): Map<string, CheckpointId> {
  const known = new Map<string, CheckpointId>()
  for (const op of ops) {
    const code = op.body?.code
    const checkpoint = op.params.checkpoint
    if (op.endpoint === 'patrol.scan' && op.state === 'confirmed' && typeof code === 'string' && (typeof checkpoint === 'string' || typeof checkpoint === 'number')) known.set(code, checkpoint)
  }
  return known
}

export function judgeTag(raw: string, targetId: CheckpointId, checkpoints: readonly Checkpoint[], ops: readonly ScanOp[]): TagVerdict {
  const code = raw.trim()
  if (code.length === 0) return { kind: 'not_a_tag', why: 'empty' }
  if (code.length > TAG_MAX) return { kind: 'not_a_tag', why: 'too_long' }
  const owner = learnedCodes(ops).get(code)
  const known = owner === undefined ? undefined : checkpoints.find((cp) => sameId(cp.id, owner))
  if (known && !sameId(known.id, targetId)) return { kind: 'record', checkpointId: known.id, code, redirectedFrom: targetId }
  return { kind: 'record', checkpointId: targetId, code, redirectedFrom: null }
}

/** The longest a tag read waits for the fix asked for when the camera opened (usePatrol) — then it goes without. */
export const SCAN_FIX_WAIT_MS = 2500

/** Where the phone was when the tag was read — the fix on-duty sharing already holds, or none. */
export type ScanFix = { latitude: number; longitude: number; accuracy_m: number | null }

/** What `patrol.scan` is sent: the tag's text, whether it was read with no signal, and the fix when there is one. */
export function scanBody(code: string, offline: boolean, fix: ScanFix | null): Record<string, unknown> {
  const body: Record<string, unknown> = { code, captured_offline: offline }
  if (fix && Number.isFinite(fix.latitude) && Number.isFinite(fix.longitude)) {
    body.latitude = fix.latitude
    body.longitude = fix.longitude
    if (fix.accuracy_m !== null && Number.isFinite(fix.accuracy_m)) body.accuracy_m = Math.max(0, Math.round(fix.accuracy_m))
  }
  return body
}

/* ---- the rows ----------------------------------------------------------------- */

export type RowState = 'scanned' | 'refused' | 'next' | 'upcoming'

export type PatrolRow = {
  id: CheckpointId
  key: string
  label: string
  /** 1-based place on the tour. */
  position: number
  state: RowState
  detail: string | null
  /** Set while the row's latest scan is an act in the outbox. */
  sync: ScanOp['state'] | null
  /** The latest accepted scan today — the server's, or one captured on this phone and not refused. */
  scannedAt: string | null
}

export type Tour = { rows: PatrolRow[]; nextId: CheckpointId | null; scanned: number; total: number; percent: number; lastScanAt: string | null; allScanned: boolean }

export const NEXT_DETAIL = 'Next · scan the tag at the checkpoint'

export function buildTour(checkpoints: readonly Checkpoint[], ops: readonly ScanOp[], nowDate: Date, nextDetail: string = NEXT_DETAIL): Tour {
  const todays = ops.filter((op) => op.endpoint === 'patrol.scan' && isToday(op.capturedAt, nowDate))

  const draft = checkpoints.map((cp, index) => {
    const mine = todays.filter((op) => sameId(op.params.checkpoint, cp.id))
    const latest = mine[mine.length - 1] ?? null // the outbox is append-only and ordered
    const accepted = [...mine].reverse().find((op) => op.state !== 'refused') ?? null
    const serverAt = isToday(cp.lastScannedAt, nowDate) ? cp.lastScannedAt : null
    // `capturedAt` is THIS PHONE's clock and `last_scanned_at` is the SERVER's (the server has a `clock_skewed`
    // flag because the two do disagree). So the two are compared only where either answer is true:
    //   - an act the server has not answered cannot be in the server's list — it is this phone's news, whatever the clocks say;
    //   - a confirmed act and the server's list are the same fact — the later of the two times is shown.
    const unanswered = accepted !== null && accepted.state !== 'confirmed'
    const fromPhone = accepted !== null && (unanswered || serverAt === null || ms(accepted.capturedAt) >= ms(serverAt))
    const scannedAt = fromPhone && accepted ? accepted.capturedAt : serverAt
    // A refusal with nothing after it in the (ordered) outbox is the last word on this checkpoint. Decided by that order,
    // never by the clocks: a phone running behind the server must not hide a refusal behind the server's earlier tick.
    const refused = latest !== null && latest.state === 'refused' ? latest : null
    return { cp, index, scannedAt, source: fromPhone ? accepted : null, refused }
  })

  const firstOpen = draft.find((d) => d.scannedAt === null) ?? null
  // Every checkpoint scanned today: the next round starts where the last one started — the oldest scan.
  const oldest = draft.reduce<(typeof draft)[number] | null>((min, d) => (d.scannedAt !== null && (min === null || min.scannedAt === null || ms(d.scannedAt) < ms(min.scannedAt)) ? d : min), null)
  const next = firstOpen ?? oldest

  const rows: PatrolRow[] = draft.map((d) => {
    const base = { id: d.cp.id, key: String(d.cp.id), label: d.cp.label, position: d.index + 1, scannedAt: d.scannedAt }
    if (d.refused) return { ...base, state: 'refused', sync: 'refused', detail: `${SYNC_LABEL.refused} · ${d.refused.error?.message ?? 'The server did not accept this scan.'}` }
    if (d.scannedAt !== null) return { ...base, state: 'scanned', sync: d.source?.state ?? null, detail: `Scanned ${timeCompact(d.scannedAt)}${d.source ? ` · ${SYNC_LABEL[d.source.state]}` : ''}` }
    if (next !== null && d === next) return { ...base, state: 'next', sync: null, detail: nextDetail }
    return { ...base, state: 'upcoming', sync: null, detail: null }
  })

  const scanned = draft.filter((d) => d.scannedAt !== null).length
  const total = draft.length
  const lastScanAt = draft.reduce<string | null>((max, d) => (d.scannedAt !== null && (max === null || ms(d.scannedAt) > ms(max)) ? d.scannedAt : max), null)

  return { rows, nextId: next?.cp.id ?? null, scanned, total, percent: total === 0 ? 0 : Math.round((scanned / total) * 100), lastScanAt, allScanned: total > 0 && scanned === total }
}

/* ---- the map -------------------------------------------------------------------- */

/** Structurally the kit's MapPin (this file imports no UI): a checkpoint where it stands, coloured by its row. */
export type TourPin = { id: string; latitude: number; longitude: number; title: string; detail?: string; tone: 'danger' | 'ok' | 'neutral' | 'stale' }
export type TourFence = { latitude: number; longitude: number; radiusM: number }
export type TourMap = { pins: TourPin[]; fence: TourFence | null; unplaced: number }

const PIN_TONE: Record<RowState, TourPin['tone']> = { scanned: 'ok', refused: 'danger', next: 'neutral', upcoming: 'stale' }

const placed = (lat: unknown, lng: unknown): boolean => typeof lat === 'number' && typeof lng === 'number' && Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180

/**
 * The tour on a map: each PLACED checkpoint as a pin in its row's colour, titled with its place on the tour, and
 * the geofence of the post this guard is rostered at. null when nothing is placed — the board's illustration
 * stays, and no map is drawn of nothing. `unplaced` is said in words, so a half-mapped tour is never read as whole.
 */
export function tourMap(rows: readonly PatrolRow[], checkpoints: readonly Checkpoint[], posts: Api.PatrolCheckpointsResponse['posts'] | undefined, postId: CheckpointId | null): TourMap | null {
  const pins: TourPin[] = []
  for (const row of rows) {
    const cp = checkpoints.find((c) => sameId(c.id, row.id))
    if (!cp || !placed(cp.latitude, cp.longitude)) continue
    pins.push({ id: `cp:${row.key}`, latitude: cp.latitude as number, longitude: cp.longitude as number, title: `${row.position}. ${row.label}`, detail: row.detail ?? undefined, tone: PIN_TONE[row.state] })
  }
  if (pins.length === 0) return null
  const post = postId === null ? undefined : (posts ?? []).find((p) => sameId(p.id, postId))
  const fence = post && placed(post.latitude, post.longitude) && post.radius_m > 0 ? { latitude: post.latitude, longitude: post.longitude, radiusM: post.radius_m } : null
  return { pins, fence, unplaced: rows.length - pins.length }
}

/** "2 checkpoints are not on the map yet — the office places them." */
export function unplacedNote(unplaced: number): string | null {
  if (unplaced <= 0) return null
  return `${unplaced === 1 ? '1 checkpoint is' : `${unplaced} checkpoints are`} not on the map yet: the office places them.`
}

/* ---- the result panel ----------------------------------------------------------- */

export type ScanOutcome =
  | { kind: 'not_a_tag'; why: 'empty' | 'too_long' }
  /** The phone could not write the act to its own storage — so there is nothing to sync, and the guard must know. */
  | { kind: 'not_saved' }
  | { kind: 'recorded'; opId: string; checkpointId: CheckpointId; label: string; expectedLabel: string | null }

export type Panel = { tone: 'green' | 'amber' | 'red' | 'neutral'; headline: string; title: string; body: string; settled: boolean }

/** How far AHEAD of the server's a phone's clock must be before the guard is told it is wrong (the server's rule). */
export const CLOCK_AHEAD_MS = 120_000

/*
 * "THIS PHONE'S CLOCK DISAGREES" ONLY WHEN THE ANSWER SHOWS IT AHEAD (B066). `clock_skewed` was "the two times differ
 * by two minutes", so a scan made with no signal and synced twenty minutes later came back skewed — and the guard, whose
 * clock was right, was told to report it. The server now flags only a clock AHEAD of its own (no queue can put an act
 * in the future); the phone holds the same line by itself, from the two times every write answers with, so it stays
 * right whichever server it talks to. With either time missing it says nothing: never "your clock is wrong" on a guess.
 */
export function clockAhead(result: { clock_skewed?: unknown; device_time?: unknown; server_time?: unknown }): boolean {
  if (result.clock_skewed !== true) return false
  const device = typeof result.device_time === 'string' ? Date.parse(result.device_time) : Number.NaN
  const server = typeof result.server_time === 'string' ? Date.parse(result.server_time) : Number.NaN
  return Number.isFinite(device) && Number.isFinite(server) && device - server > CLOCK_AHEAD_MS
}

const tourOf = (result: unknown): { scanned: number; total: number; skewed: boolean } | null => {
  if (typeof result !== 'object' || result === null) return null
  const r = result as { tour?: { total?: unknown; scanned_today?: unknown }; clock_skewed?: unknown; device_time?: unknown; server_time?: unknown }
  const total = Number(r.tour?.total)
  const scanned = Number(r.tour?.scanned_today)
  return Number.isFinite(total) && Number.isFinite(scanned) ? { scanned, total, skewed: clockAhead(r) } : null
}

/** The server's one further sentence on a recorded scan (early, no position, not placed), and whether it was early. */
const noteOf = (result: unknown): { note: string | null; early: boolean } => {
  if (typeof result !== 'object' || result === null) return { note: null, early: false }
  const r = result as { note?: unknown; early?: unknown }
  return { note: typeof r.note === 'string' && r.note.trim() !== '' ? r.note.trim() : null, early: r.early === true }
}

/** What the guard does next, after each of the server's refusals — beside its own sentence, never instead of it. */
const REFUSAL_HINT: Record<string, string> = {
  code_mismatch: ' If you are standing at a different checkpoint, choose it in the list and scan again.',
  not_on_shift: ' Clock in on the Shift tab, then scan the tag again.',
  outside_checkpoint: ' Walk to the tag and scan it there.',
}

/** Being sent, or about to be: captured a moment ago and not tried yet (online) is the same thing to the guard. */
export function isSending(op: ScanOp | null, online: boolean): boolean {
  return op !== null && (op.state === 'syncing' || (op.state === 'pending' && online && op.attempts === 0))
}

/** How long "Checking the tag…" may stand before the guard is told to walk on (the act is safe on the phone either way). */
export const PATIENCE_MS = 8000

/**
 * What the guard reads at arm's length after a tag is read. `op` is the outbox act as it is NOW.
 * `waited` — PATIENCE_MS have passed without an answer. The queue sends in order and stops at the first act that
 * fails, so a scan behind a stuck act is never even tried: without this, "Sending it now" would stand for good.
 */
export function panelFor(outcome: ScanOutcome, op: ScanOp | null, online: boolean, waited = false): Panel {
  if (outcome.kind === 'not_a_tag') {
    return {
      tone: 'red',
      headline: 'NOT A CHECKPOINT TAG',
      title: 'Nothing was recorded',
      body: outcome.why === 'empty' ? 'The code read was empty. Hold the phone steady over the tag fixed at the checkpoint and scan again.' : 'A checkpoint tag holds a short code; this is something else: a visitor pass, perhaps. Scan the tag fixed at the checkpoint.',
      settled: true,
    }
  }

  if (outcome.kind === 'not_saved') {
    return { tone: 'red', headline: 'NOT SAVED', title: 'Nothing was recorded', body: 'The scan could not be saved on this phone. Scan the tag again; if it keeps happening, tell your supervisor.', settled: true }
  }

  const moved = outcome.expectedLabel ? ` This is ${outcome.label}'s tag, so it was recorded there, not for ${outcome.expectedLabel}.` : ''

  if (op === null) return { tone: 'neutral', headline: 'SCAN SAVED', title: outcome.label, body: `It is in the sync queue.${moved}`, settled: true }

  if (op.state === 'confirmed') {
    const tour = tourOf(op.result)
    const count = tour ? `${tour.scanned} of ${tour.total} checkpoints scanned today.` : 'The server has it.'
    const skew = tour?.skewed ? " This phone's clock disagrees with the server's: tell your supervisor." : ''
    const { note, early } = noteOf(op.result)
    // Early is recorded, not refused — so it is amber, with the server's own sentence saying when the next round is due.
    return { tone: early ? 'amber' : 'green', headline: early ? 'RECORDED: EARLY' : 'CHECKPOINT CONFIRMED', title: outcome.label, body: `${count}${moved}${note ? ` ${note}` : ''}${skew}`, settled: true }
  }

  if (op.state === 'refused') {
    const sentence = op.error?.message ?? 'The server did not accept this scan.'
    const hint = REFUSAL_HINT[op.error?.code ?? ''] ?? ''
    return { tone: 'red', headline: 'NOT ACCEPTED: NOTHING RECORDED', title: outcome.label, body: `${sentence}${hint}`, settled: true }
  }

  // Captured a moment ago and not tried yet (online) is the same thing to the guard as being sent.
  const sending = isSending(op, online)
  if (sending && !waited) return { tone: 'neutral', headline: 'CHECKING THE TAG…', title: outcome.label, body: `Saved on this phone. Sending it now.${moved}`, settled: false }

  const why = sending
    ? "Saved on this phone with the time of the scan. The server has not answered yet: it is sent by itself, and the checkpoint's row shows the answer. You can walk on."
    : online
      ? 'Saved on this phone with the time of the scan. It could not be sent just now and is sent again by itself.'
      : 'No signal. Saved on this phone with the time of the scan; the server checks the tag when it syncs.'
  return { tone: 'amber', headline: 'SAVED: PENDING SYNC', title: outcome.label, body: `${why}${moved}`, settled: true }
}

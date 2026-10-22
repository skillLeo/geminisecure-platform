/**
 * ON-DUTY LOCATION SHARING — the pure part (M11 §3; the server's D-174). No React, no native module: what the
 * officer is told, whether the phone may share at all, how often it samples, and what the queue keeps. Every rule
 * here is tested under Jest (__tests__/trackingPolicy.test.ts).
 *
 * THE SERVER IS THE RULE. It refuses a fix taken outside the shift, a fix without consent to the current policy,
 * and every fix while the feature is off. The phone follows the same lines so it never samples for nothing, but
 * nothing here is what makes a position lawful to keep — the server is.
 */

import type { Api } from '@gs/core/api'

/**
 * The version of the words below. The server names the version it asks about (`tracking.status.policy_version`);
 * when the two differ, the phone does not have the words the server wants answered, so it asks for an update
 * rather than answering words the officer never read.
 */
export const POLICY_VERSION = '2026-10-10'

/**
 * What the officer reads before agreeing. Plain words, in the order the order asks for them (§3.2).
 *
 * THE LEAD IS THE SENTENCE THE STORES LOOK FOR (M17 §3.2). Google Play's prominent-disclosure rule for background
 * location is checked, near word for word, against "[app] collects location data to enable [features] even when the
 * app is closed or not in use", shown in the app before the permission is asked; a disclosure that says it only in a
 * later paragraph is the most common rejection for an app like this. So the first thing on the screen says what is
 * collected, what for, that it goes on when the app is closed — and that it is only on duty. Changing these words
 * changes POLICY_VERSION, here and in the server's config/tracking.php together, so every officer is asked again.
 */
export const POLICY = {
  title: 'Sharing your location while on duty',
  lead: 'GeminiSecure Guard collects your location to send the nearest officer to an alarm, to find you if you raise a duress alert, and to show the control room who is on post: even when the app is closed or not in use, but only from clock-in to clock-out.',
  sections: [
    { heading: 'What is recorded', body: 'Your phone’s location, how accurate it is, the time, and your battery level: about once a minute while you move, less often while you stand still or your battery is low, and each time you clock in, scan a gate pass, scan a checkpoint, raise a duress alert or clock out.' },
    { heading: 'Only during your shift', body: 'Sharing starts when you clock in and stops when you clock out. While you are on duty it carries on in the background and when the app is closed, and your phone shows a notification that says so the whole time. Off duty, nothing is recorded and the server refuses anything a phone sends.' },
    { heading: 'Who sees it', body: 'The GeminiSecure control room and your supervisors. The community you guard sees how many officers are on site and their names, not where you are standing, unless its contract says otherwise.' },
    { heading: 'How long it is kept', body: 'Positions are deleted after {days} days.' },
    { heading: 'Raising a concern', body: 'Speak to your supervisor, or write to the GeminiSecure office. Saying no is recorded as your answer and does not stop you working: the app goes on as before, and the control room sees “position not shared”. You can change your answer later under More → Location sharing.' },
  ],
  agree: 'I agree: share my location on duty',
  refuse: 'No: do not share my location',
} as const

export type TrackingStatus = Api.TrackingStatusResponse
export type Sampling = TrackingStatus['sampling']
export type Source = 'interval' | 'movement' | 'clock_in' | 'checkpoint' | 'gate' | 'duress' | 'clock_out'

/** One fix, as it waits on the phone and as it is sent. `captured_at` is the PHONE's clock; the server corrects it. */
export type Fix = {
  id: string
  latitude: number
  longitude: number
  accuracy_m: number | null
  source: Source
  battery_pct: number | null
  captured_at: string
}

/** A fix waiting to be sent, with the shift it belongs to: fixes from a shift that has ended still go. */
export type QueuedFix = { shiftId: number | string; fix: Fix }

/** The server's defaults, for a phone that has not heard from it yet. */
export const DEFAULT_SAMPLING: Sampling = { interval_s: 45, distance_m: 25, stationary_interval_s: 180, low_battery_pct: 20, low_battery_interval_s: 180 }

/** The words, with the server's retention filled in. */
export function policyText(retentionDays: number | null | undefined): { heading: string; body: string }[] {
  const days = Number.isFinite(retentionDays) && (retentionDays ?? 0) > 0 ? String(retentionDays) : '90'
  return POLICY.sections.map((s) => ({ heading: s.heading, body: s.body.replace('{days}', days) }))
}

/**
 * Whether the officer must be shown the policy: sharing is on, and they have not answered THESE words. A refusal
 * is an answer — they are not asked again at every clock-in, only when the words change or they choose to.
 */
export function consentNeeded(status: TrackingStatus | null | undefined): boolean {
  if (!status || !status.enabled) return false
  return status.consent === null || status.consent.policy_version !== status.policy_version
}

/** The server asks about words this phone does not have: the app must be updated before it can be answered. */
export function policyOutOfDate(status: TrackingStatus | null | undefined): boolean {
  return !!status && status.enabled && status.policy_version !== POLICY_VERSION
}

/** Whether this phone may share at all: the feature is on and the officer agreed to the current words. */
export function mayShare(status: TrackingStatus | null | undefined): boolean {
  return !!status && status.enabled && status.policy_version === POLICY_VERSION && status.consent?.decision === 'granted' && status.consent.policy_version === status.policy_version
}

/* ---- sampling ------------------------------------------------------------------------------------------------ */

export type PlanMode = 'moving' | 'stationary' | 'low_battery'
export type Plan = { mode: PlanMode; timeIntervalMs: number; distanceIntervalM: number; accuracy: 'high' | 'balanced' }

/**
 * How the phone samples now (§3.1): about every 45 s moving; slower standing still, and slower again below the
 * low-battery line (unless it is charging). Low battery wins over everything: a guard app that flattens the phone by
 * hour eight gets switched off, and then it is worth nothing.
 *
 * ON THE CLOCK, NEVER ON DISTANCE (M22 §1.1, found on the emulator). The request used to carry a 25 m minimum distance,
 * which Android's fused provider applies literally: a phone that has not moved 25 m is sent NOTHING, whatever the
 * interval. An officer standing at a gate recorded no position for as long as they stood there — the control room
 * saw their pin go stale, and the shift's record showed a gap for an officer who was at their post. So the distance
 * is always 0: a fix every interval, moving or still; "moving" is judged from the fixes (`isStationary`) and only
 * sets how often. `sampling.distance_m` still names a movement in `sourceOf`.
 */
export function planFor(sampling: Sampling, state: { stationary: boolean; batteryPct: number | null; charging: boolean }): Plan {
  const low = state.batteryPct !== null && state.batteryPct < sampling.low_battery_pct && !state.charging
  if (low) return { mode: 'low_battery', timeIntervalMs: sampling.low_battery_interval_s * 1000, distanceIntervalM: 0, accuracy: 'balanced' }
  if (state.stationary) return { mode: 'stationary', timeIntervalMs: sampling.stationary_interval_s * 1000, distanceIntervalM: 0, accuracy: 'balanced' }
  return { mode: 'moving', timeIntervalMs: sampling.interval_s * 1000, distanceIntervalM: 0, accuracy: 'high' }
}

export const samePlan = (a: Plan | null, b: Plan): boolean => a !== null && a.mode === b.mode && a.timeIntervalMs === b.timeIntervalMs && a.distanceIntervalM === b.distanceIntervalM && a.accuracy === b.accuracy

/** Metres between two points (haversine) — enough for "has the officer moved". */
export function metresBetween(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const r = 6_371_000
  const toRad = (d: number) => (d * Math.PI) / 180
  const dLat = toRad(b.latitude - a.latitude)
  const dLng = toRad(b.longitude - a.longitude)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.latitude)) * Math.cos(toRad(b.latitude)) * Math.sin(dLng / 2) ** 2
  return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)))
}

/** Within this many metres of each other over the last few minutes, an officer is standing still. */
export const STATIONARY_M = 20
export const STATIONARY_WINDOW_MS = 4 * 60_000

/**
 * Standing still: at least three fixes in the last few minutes, none further than STATIONARY_M (or its own
 * accuracy, if worse) from the first of them. Too few fixes is not "still" — a phone that has only just started
 * samples at the moving rate until it knows.
 */
export function isStationary(fixes: readonly Fix[], nowMs: number): boolean {
  const recent = fixes.filter((f) => nowMs - Date.parse(f.captured_at) <= STATIONARY_WINDOW_MS)
  if (recent.length < 3) return false
  const first = recent[0]!
  return recent.every((f) => metresBetween(first, f) <= Math.max(STATIONARY_M, f.accuracy_m ?? 0))
}

/** How old an on-duty fix may be and still say where a checkpoint scan was made (M21 §1.1). */
export const SCAN_FIX_MAX_AGE_MS = 2 * 60_000

/**
 * The newest fix already taken on duty, if it is recent enough to stand for "where the officer is now" — for a
 * checkpoint scan's position (M21 §1.1). Takes no new reading: the scan never waits on GPS, and never reads a
 * position the tracker has not already taken with the officer's consent. A fix from the future (a phone whose
 * clock moved back) is not "recent"; it is nothing.
 */
export function latestFix(fixes: readonly Fix[], nowMs: number, maxAgeMs = SCAN_FIX_MAX_AGE_MS): Fix | null {
  const last = fixes.at(-1)
  if (!last) return null
  const age = nowMs - Date.parse(last.captured_at)
  return Number.isFinite(age) && age >= -5_000 && age <= maxAgeMs ? last : null
}

/**
 * Longer than this with no position taken on duty is a gap (M22 §1.1) — the server's `tracking.gap_after_seconds`, the
 * same ten minutes, so the phone and the control room call the same stretch a gap.
 */
export const GAP_MS = 10 * 60_000

export type RecordingLine = { text: string; warn: boolean }

/**
 * WHAT LOCATION SHARING SAYS IS HAPPENING, RIGHT NOW (M22 §1.1). The notification said "location shared" through a
 * whole run in which nothing was recorded, so this line says only what the phone can show: when a position was last
 * TAKEN, whether it has reached the control room — and, once nothing has been taken for GAP_MS on a shift that is
 * sharing, that it has not. Null when there is nothing to say (not on duty, or not sharing). `clock` prints a moment on
 * the estate's clock, tagged.
 */
export function recordingLine(input: { sharing: boolean; startedMs: number | null; lastTakenMs: number | null; lastSentMs: number | null; nowMs: number; clock: (ms: number) => string }): RecordingLine | null {
  const { sharing, startedMs, lastTakenMs, lastSentMs, nowMs, clock } = input
  if (!sharing) return null
  if (lastTakenMs === null) {
    const late = startedMs !== null && nowMs - startedMs > GAP_MS
    return late
      ? { text: `No position has been recorded since sharing started at ${clock(startedMs)}. Check that location is on for this phone.`, warn: true }
      : { text: 'Sharing started. The first position is being taken.', warn: false }
  }
  if (nowMs - lastTakenMs > GAP_MS) {
    return { text: `No position has been recorded since ${clock(lastTakenMs)}. Check that location is on: the control room can see there is a gap.`, warn: true }
  }
  // Recorded, but not reaching the control room: out of signal. Nothing is lost — say so, and why the map is behind.
  const quietSince = lastSentMs ?? startedMs
  if (quietSince !== null && nowMs - quietSince > GAP_MS) {
    return {
      text: `Last position recorded ${clock(lastTakenMs)}, but none has reached the control room since ${lastSentMs === null ? 'sharing started' : clock(lastSentMs)}. They are kept on this phone and sent when there is a connection.`,
      warn: true,
    }
  }
  return { text: `Last position recorded ${clock(lastTakenMs)}${lastSentMs === null ? '' : ` · last sent to the control room ${clock(lastSentMs)}`}`, warn: false }
}

/** `movement` when this fix came sooner than the interval because the officer moved; `interval` otherwise. */
export function sourceOf(previous: Fix | null, next: { latitude: number; longitude: number; atMs: number }, sampling: Sampling): 'interval' | 'movement' {
  if (!previous) return 'interval'
  const sooner = next.atMs - Date.parse(previous.captured_at) < sampling.interval_s * 1000 * 0.9
  return sooner && metresBetween(previous, next) >= sampling.distance_m ? 'movement' : 'interval'
}

/* ---- the queue ----------------------------------------------------------------------------------------------- */

/** A shift is twelve hours; at the moving rate that is under a thousand fixes. Past this the oldest samples go. */
export const QUEUE_MAX = 3000
export const BATCH_MAX = 100

/**
 * Bounded, oldest samples first. A named moment (clock-in, a scan, a duress, clock-out) is the last to go: it is
 * the fix a supervisor will ask about.
 */
export function bounded(queue: readonly QueuedFix[], max = QUEUE_MAX): QueuedFix[] {
  if (queue.length <= max) return [...queue]
  let over = queue.length - max
  const sample = (q: QueuedFix) => q.fix.source === 'interval' || q.fix.source === 'movement'
  const kept = queue.filter((q) => {
    if (over > 0 && sample(q)) {
      over--
      return false
    }
    return true
  })
  return kept.length > max ? kept.slice(kept.length - max) : kept
}

/** The next batch to send: the oldest shift's fixes, oldest first — one request is one shift. */
export function nextBatch(queue: readonly QueuedFix[], max = BATCH_MAX): { shiftId: number | string; fixes: Fix[] } | null {
  const first = queue[0]
  if (!first) return null
  const fixes = queue.filter((q) => String(q.shiftId) === String(first.shiftId)).slice(0, max).map((q) => q.fix)
  return { shiftId: first.shiftId, fixes }
}

/** What stays after the server answered: every fix it kept or refused leaves. A refused fix is never resent. */
export function afterAnswer(queue: readonly QueuedFix[], answered: { accepted: readonly (string | number)[]; refused: readonly { id: string | number }[] }): QueuedFix[] {
  const done = new Set([...answered.accepted.map(String), ...answered.refused.map((r) => String(r.id))])
  return queue.filter((q) => !done.has(q.fix.id))
}

/** A shift the server says is not this officer's, or not a shift at all: its fixes can never be kept. */
export function withoutShift(queue: readonly QueuedFix[], shiftId: number | string): QueuedFix[] {
  return queue.filter((q) => String(q.shiftId) !== String(shiftId))
}

/** Backoff after a failure: 30 s, doubling, at most ten minutes. */
export function retryDelayMs(failures: number): number {
  return Math.min(10 * 60_000, 30_000 * 2 ** Math.max(0, failures - 1))
}

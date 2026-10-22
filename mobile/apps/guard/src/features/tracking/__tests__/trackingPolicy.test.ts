/**
 * On-duty sharing's rules (M11 §3): who may share, how often the phone samples, and what the queue keeps.
 */
import { BATCH_MAX, DEFAULT_SAMPLING, GAP_MS, POLICY, POLICY_VERSION, QUEUE_MAX, SCAN_FIX_MAX_AGE_MS, afterAnswer, bounded, consentNeeded, isStationary, latestFix, mayShare, metresBetween, nextBatch, planFor, policyOutOfDate, policyText, recordingLine, retryDelayMs, sourceOf, withoutShift, type Fix, type QueuedFix, type TrackingStatus } from '../trackingPolicy'

const status = (over: Partial<TrackingStatus> = {}): TrackingStatus => ({
  enabled: true, policy_version: POLICY_VERSION, consent: null, retention_days: 90, sampling: DEFAULT_SAMPLING, ...over,
})
const granted = { decision: 'granted' as const, policy_version: POLICY_VERSION, decided_at: '2026-10-03T12:00:00+00:00' }
const refused = { decision: 'refused' as const, policy_version: POLICY_VERSION, decided_at: '2026-10-03T12:00:00+00:00' }

let n = 0
const fix = (over: Partial<Fix> = {}): Fix => ({ id: `f${++n}`, latitude: 18.0179, longitude: -76.8099, accuracy_m: 8, source: 'interval', battery_pct: 80, captured_at: '2026-10-03T12:00:00-05:00', ...over })
const q = (shiftId: number, over: Partial<Fix> = {}): QueuedFix => ({ shiftId, fix: fix(over) })

describe('the disclosure the stores check (M17 §3.2)', () => {
  it('opens with what is collected, what for, that it goes on when the app is closed, and only on duty', () => {
    // Google Play's prominent disclosure for background location, as its reviewers read it: the FIRST thing shown,
    // before the permission is asked, not a line buried in a later paragraph.
    expect(POLICY.lead).toMatch(/collects your location/)
    expect(POLICY.lead).toMatch(/even when the app is closed or not in use/)
    expect(POLICY.lead).toMatch(/send the nearest officer/)
    expect(POLICY.lead).toMatch(/only from clock-in to clock-out/)
    // The phone's two dialogs follow the officer's own tap, never the screen opening (dutyTracker.askPermissions).
    expect(POLICY.agree).toMatch(/I agree/)
    expect(POLICY.refuse).toMatch(/^No/)
  })
})

describe('the fix a checkpoint scan carries (M21 §1.1)', () => {
  const now = Date.parse('2026-10-03T12:00:00-05:00')
  it('is the newest fix already taken, and only while it is recent', () => {
    expect(latestFix([], now)).toBeNull()
    const newest = fix({ captured_at: '2026-10-03T11:59:10-05:00', latitude: 18.02 })
    expect(latestFix([fix({ captured_at: '2026-10-03T11:58:00-05:00' }), newest], now)).toBe(newest)
    // Older than the limit: not "where the officer is now".
    expect(latestFix([fix({ captured_at: '2026-10-03T11:57:59-05:00' })], now, SCAN_FIX_MAX_AGE_MS)).toBeNull()
    // From the future (a clock moved back) or unreadable: nothing, never a guess.
    expect(latestFix([fix({ captured_at: '2026-10-03T12:05:00-05:00' })], now)).toBeNull()
    expect(latestFix([fix({ captured_at: 'not a time' })], now)).toBeNull()
  })
})

/*
 * M22 §1.1: the notification said "location shared" through a run in which nothing was recorded. Location sharing
 * now says what the phone can show — and, once nothing has been taken for ten minutes, that it has not.
 */
describe('what Location sharing says is being recorded', () => {
  const t0 = Date.parse('2026-10-03T12:00:00Z')
  const clock = (ms: number) => `T+${Math.round((ms - t0) / 60_000)}m`
  const line = (over: Partial<Parameters<typeof recordingLine>[0]>) => recordingLine({ sharing: true, startedMs: t0, lastTakenMs: null, lastSentMs: null, nowMs: t0, clock, ...over })

  it('says nothing when not sharing, and that the first position is coming just after it starts', () => {
    expect(line({ sharing: false })).toBeNull()
    expect(line({ nowMs: t0 + 60_000 })).toEqual({ text: 'Sharing started. The first position is being taken.', warn: false })
  })

  it('says when a position was last recorded and last reached the control room', () => {
    expect(line({ lastTakenMs: t0 + 5 * 60_000, lastSentMs: t0 + 4 * 60_000, nowMs: t0 + 6 * 60_000 })).toEqual({ text: 'Last position recorded T+5m · last sent to the control room T+4m', warn: false })
  })

  it('warns once nothing has been recorded for ten minutes: the same stretch the control room calls a gap', () => {
    expect(GAP_MS).toBe(600_000)
    expect(line({ lastTakenMs: t0, lastSentMs: t0, nowMs: t0 + GAP_MS + 60_000 })).toMatchObject({ warn: true, text: expect.stringContaining('No position has been recorded since T+0m') })
    expect(line({ nowMs: t0 + GAP_MS + 60_000 })).toMatchObject({ warn: true, text: expect.stringContaining('since sharing started at T+0m') })
  })

  it('out of signal is not "not recorded": the positions are kept and wait for a connection', () => {
    const out = line({ lastTakenMs: t0 + 14 * 60_000, lastSentMs: t0 + 2 * 60_000, nowMs: t0 + 15 * 60_000 })
    expect(out).toMatchObject({ warn: true })
    expect(out!.text).toContain('none has reached the control room since T+2m')
    expect(out!.text).toContain('kept on this phone and sent when there is a connection')
  })
})

describe('who may share', () => {
  it('only with the feature on and consent to THESE words', () => {
    expect(mayShare(status({ consent: granted }))).toBe(true)
    expect(mayShare(status({ consent: refused }))).toBe(false)
    expect(mayShare(status({ consent: null }))).toBe(false)
    expect(mayShare(status({ enabled: false, consent: granted }))).toBe(false)
    expect(mayShare(null)).toBe(false)
    // The server asks about newer words than this phone has: nothing is shared on the old answer.
    expect(mayShare(status({ policy_version: '2027-01-01', consent: { ...granted, policy_version: '2027-01-01' } }))).toBe(false)
  })

  it('asks when there is no answer to these words, and a refusal is an answer, not asked again at every shift', () => {
    expect(consentNeeded(status())).toBe(true)
    expect(consentNeeded(status({ consent: granted }))).toBe(false)
    expect(consentNeeded(status({ consent: refused }))).toBe(false)
    expect(consentNeeded(status({ consent: { ...granted, policy_version: '2020-01-01' } }))).toBe(true)
    expect(consentNeeded(status({ enabled: false }))).toBe(false)
    expect(policyOutOfDate(status({ policy_version: '2027-01-01' }))).toBe(true)
    expect(policyOutOfDate(status())).toBe(false)
  })

  it('says what is recorded, when, who sees it, how long, and that no is an answer', () => {
    const words = policyText(90).map((s) => `${s.heading}: ${s.body}`).join('\n')
    expect(words).toMatch(/clock in/)
    expect(words).toMatch(/stops when you clock out/)
    expect(words).toMatch(/in the background and when the app is closed/)
    expect(words).toMatch(/control room/)
    expect(words).toMatch(/deleted after 90 days/)
    expect(words).toMatch(/Saying no is recorded as your answer and does not stop you working/)
    expect(policyText(30).find((s) => s.heading === 'How long it is kept')?.body).toBe('Positions are deleted after 30 days.')
    expect(POLICY.refuse).toMatch(/^No/)
  })
})

describe('how often the phone samples', () => {
  it('about every 45 s moving, slower standing still, slower again on a low battery unless charging', () => {
    expect(planFor(DEFAULT_SAMPLING, { stationary: false, batteryPct: 80, charging: false })).toEqual({ mode: 'moving', timeIntervalMs: 45_000, distanceIntervalM: 0, accuracy: 'high' })
    expect(planFor(DEFAULT_SAMPLING, { stationary: true, batteryPct: 80, charging: false })).toMatchObject({ mode: 'stationary', timeIntervalMs: 180_000, accuracy: 'balanced' })
    expect(planFor(DEFAULT_SAMPLING, { stationary: false, batteryPct: 19, charging: false })).toMatchObject({ mode: 'low_battery', timeIntervalMs: 180_000 })
    expect(planFor(DEFAULT_SAMPLING, { stationary: false, batteryPct: 19, charging: true }).mode).toBe('moving')
    expect(planFor(DEFAULT_SAMPLING, { stationary: false, batteryPct: null, charging: false }).mode).toBe('moving')
  })

  /*
   * M22 §1.1, on the emulator: a 25 m minimum distance meant Android sent a still phone nothing at all — an officer
   * standing at a gate recorded no position, and read as a gap. Every plan samples on the clock alone.
   */
  it('never asks for a minimum distance, so an officer standing still is still recorded', () => {
    for (const state of [{ stationary: false, batteryPct: 80, charging: false }, { stationary: true, batteryPct: 80, charging: false }, { stationary: false, batteryPct: 5, charging: false }]) {
      expect(planFor(DEFAULT_SAMPLING, state).distanceIntervalM).toBe(0)
    }
  })

  it('knows standing still from moving, and needs three fixes before it decides', () => {
    const at = Date.parse('2026-10-03T12:03:00-05:00')
    const still = [0, 1, 2].map((m) => fix({ captured_at: `2026-10-03T12:0${m}:00-05:00`, latitude: 18.0179 + m * 0.00002 }))
    expect(isStationary(still, at)).toBe(true)
    expect(isStationary(still.slice(0, 2), at)).toBe(false)
    const walking = [0, 1, 2].map((m) => fix({ captured_at: `2026-10-03T12:0${m}:00-05:00`, latitude: 18.0179 + m * 0.001 }))
    expect(isStationary(walking, at)).toBe(false)
    expect(metresBetween({ latitude: 18, longitude: -76.8 }, { latitude: 18.001, longitude: -76.8 })).toBeGreaterThan(100)
  })

  it('names a fix that came early because the officer moved', () => {
    const prev = fix({ captured_at: '2026-10-03T12:00:00-05:00' })
    expect(sourceOf(null, { latitude: 18, longitude: -76.8, atMs: 0 }, DEFAULT_SAMPLING)).toBe('interval')
    expect(sourceOf(prev, { latitude: 18.0189, longitude: -76.8099, atMs: Date.parse('2026-10-03T12:00:10-05:00') }, DEFAULT_SAMPLING)).toBe('movement')
    expect(sourceOf(prev, { latitude: 18.0189, longitude: -76.8099, atMs: Date.parse('2026-10-03T12:00:50-05:00') }, DEFAULT_SAMPLING)).toBe('interval')
  })
})

describe('the queue', () => {
  it('sends the oldest shift first, a batch at a time', () => {
    const queue = [q(7), q(7), q(9), q(7)]
    expect(nextBatch(queue)).toEqual({ shiftId: 7, fixes: [queue[0]!.fix, queue[1]!.fix, queue[3]!.fix] })
    expect(nextBatch(Array.from({ length: BATCH_MAX + 5 }, () => q(1)))!.fixes).toHaveLength(BATCH_MAX)
    expect(nextBatch([])).toBeNull()
  })

  it('drops every fix the server kept or refused: a refused fix is never resent', () => {
    const queue = [q(7), q(7), q(7)]
    const left = afterAnswer(queue, { accepted: [queue[0]!.fix.id], refused: [{ id: queue[2]!.fix.id }] })
    expect(left.map((x) => x.fix.id)).toEqual([queue[1]!.fix.id])
    expect(withoutShift([q(7), q(9)], '7').map((x) => x.shiftId)).toEqual([9])
  })

  it('is bounded, and the named moments are the last to go', () => {
    const moments = [q(1, { source: 'clock_in' }), q(1, { source: 'duress' })]
    const samples = Array.from({ length: QUEUE_MAX + 10 }, () => q(1))
    const kept = bounded([...moments, ...samples])
    expect(kept).toHaveLength(QUEUE_MAX)
    expect(kept.filter((x) => x.fix.source !== 'interval')).toHaveLength(2)
  })

  it('backs off after a failure: 30 s, doubling, at most ten minutes', () => {
    expect(retryDelayMs(1)).toBe(30_000)
    expect(retryDelayMs(2)).toBe(60_000)
    expect(retryDelayMs(20)).toBe(600_000)
  })
})

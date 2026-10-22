/**
 * THE ON-DUTY TRACKER — the one file in either app that may keep taking a position in the background (M11 §3;
 * tools/gates/location-discipline.mjs names it, and nothing else).
 *
 * FROM CLOCK-IN TO CLOCK-OUT, AND ONLY WITH CONSENT.
 *   startDuty    at clock-in, when the officer agreed to the current policy and the phone allows location "all
 *                the time": the duty is written to the phone, and the platform's own background service starts —
 *                on Android a foreground service whose notification says "On duty · location shared with control
 *                room" for as long as it runs; on iOS background location mode, with the blue indicator on.
 *   the task     each batch of positions the platform hands over is kept on the phone with its real capture time
 *                and sent in batches (positionSender). It reads the duty FIRST, every time: with none — clocked
 *                out, signed out, another officer — it stops the service and keeps nothing.
 *   moments      clock-in, every checkpoint and gate scan, a duress and clock-out take one extra fix at once.
 *   endDuty      at clock-out: the last fix, then the service stops and the duty is gone. What is still queued is
 *                sent afterwards — each fix was taken on the shift, and the server checks that.
 *   stopDuty     at sign-out: the service stops and nothing of the officer's sharing stays on the phone.
 *
 * Sampling adapts (trackingPolicy.planFor): about every 45 s moving, every three minutes standing still or below
 * 20% battery. A refusal, the feature switched off, or a missing permission starts nothing — the app works on and
 * the control room reads "position not shared".
 *
 * NEVER ASKS BEHIND THE OFFICER'S BACK. The phone's permission dialogs are raised only by `askPermissions`, which
 * only the consent screen's Agree button calls, after the officer read the words (TrackingConsentScreen).
 */

import { AppState, Platform } from 'react-native'
import * as Battery from 'expo-battery'
import * as Location from 'expo-location'
import * as TaskManager from 'expo-task-manager'
import { REQUEST_TIMEOUT_MS } from '@gs/core/api'
import { deviceTimeIso, getStorage, newUuid } from '@gs/core/lib'
import { dismiss, show } from '@gs/core/notify'
import { OUTBOX_PREFIX } from '@gs/core/offline'
import { palette } from '@gs/core/theme'
import { sendPositions } from './positionSender'
import { trackingStore, type Duty } from './trackingStore'
import { afterAnswer, DEFAULT_SAMPLING, GAP_MS, isStationary, latestFix, mayShare, nextBatch, planFor, retryDelayMs, samePlan, sourceOf, withoutShift, type Fix, type Plan, type Sampling, type Source } from './trackingPolicy'

export const DUTY_TASK = 'gs-guard-on-duty-position'

/** The words on the permanent notification while the service runs (Android), and why. */
export const DUTY_NOTICE = {
  notificationTitle: 'On duty · location shared with control room',
  notificationBody: 'GeminiSecure Guard shares your location with the control room until you clock out.',
  notificationColor: palette['surface-900'],
  // Swiping the app away does not end a shift; clocking out does.
  killServiceOnDestroy: false,
} as const

/** How long a named moment waits for a fresh fix before it takes the phone's last known one. */
export const MOMENT_WAIT_MS = 8000

/** Between routine sends, at least this long: every send wakes the radio, and the radio is the battery. */
export const SEND_EVERY_MS = 60_000

const native = Platform.OS === 'ios' || Platform.OS === 'android'

/*
 * A SEND THAT HAS NOT ANSWERED IN THIS LONG IS ABANDONED (M21 §1.2, found on the emulator). One send at a time, and
 * a request that never settled — the connection went away under it — held that one slot for good: seventeen minutes
 * of positions, then nothing until the app was restarted. Judged by the clock when the next send is wanted, not by a
 * timer, because a phone asleep in a pocket does not run timers. Twice the request's own time-out, and a little more.
 */
export const STUCK_SEND_MS = 2 * REQUEST_TIMEOUT_MS + 5_000

let currentPlan: Plan | null = null
let failures = 0
let notBefore = 0
let lastSentAt = 0
let sending: Promise<void> | null = null
let sendingSince = 0
let sendSeq = 0

const readDuty = (): Duty | null => trackingStore.duty()
const samplingOf = (duty: Duty): Sampling => trackingStore.status(duty.owner)?.sampling ?? DEFAULT_SAMPLING

function optionsFor(plan: Plan): Location.LocationTaskOptions {
  return {
    accuracy: plan.accuracy === 'high' ? Location.Accuracy.High : Location.Accuracy.Balanced,
    timeInterval: plan.timeIntervalMs,
    distanceInterval: plan.distanceIntervalM,
    // iOS hands positions over in batches no more often than this, which is most of the saving there.
    deferredUpdatesInterval: plan.timeIntervalMs,
    pausesUpdatesAutomatically: false,
    activityType: Location.ActivityType.Other,
    showsBackgroundLocationIndicator: true,
    foregroundService: DUTY_NOTICE,
  }
}

async function battery(): Promise<{ pct: number | null; charging: boolean }> {
  try {
    const [level, state] = await Promise.all([Battery.getBatteryLevelAsync(), Battery.getBatteryStateAsync()])
    return { pct: level >= 0 ? Math.round(level * 100) : null, charging: state === Battery.BatteryState.CHARGING || state === Battery.BatteryState.FULL }
  } catch {
    return { pct: null, charging: false }
  }
}

/** A clock-in captured on this phone and not yet on the server: its shift's fixes wait, or the server would refuse them. */
function clockInWaiting(): boolean {
  try {
    const raw = getStorage().getString(`${OUTBOX_PREFIX}v1`)
    const ops: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(ops) && ops.some((o: { endpoint?: string; state?: string } | null) => o?.endpoint === 'shifts.clock_in' && (o.state === 'pending' || o.state === 'syncing'))
  } catch {
    return false
  }
}

async function keep(duty: Duty, at: Location.LocationObject, source: Source, pct: number | null): Promise<Fix> {
  const fix: Fix = {
    id: newUuid(),
    latitude: at.coords.latitude,
    longitude: at.coords.longitude,
    accuracy_m: at.coords.accuracy === null || at.coords.accuracy === undefined ? null : Math.round(at.coords.accuracy),
    source,
    battery_pct: pct,
    // When the fix was TAKEN (the platform's own time for it), never when it happened to be handed over — on the
    // PHONE's clock with its offset, like every device time: the server corrects it by the batch's X-Device-Time.
    captured_at: deviceTimeIso(new Date(at.timestamp)),
  }
  await trackingStore.push(duty.owner, { shiftId: duty.shiftId, fix })
  return fix
}

async function stopUpdates(): Promise<void> {
  currentPlan = null
  try {
    if (await Location.hasStartedLocationUpdatesAsync(DUTY_TASK)) await Location.stopLocationUpdatesAsync(DUTY_TASK)
  } catch {
    // Not running, or no native module: nothing to stop.
  }
}

async function startUpdates(duty: Duty): Promise<void> {
  const { pct, charging } = await battery()
  const plan = planFor(samplingOf(duty), { stationary: isStationary(trackingStore.recent(), Date.now()), batteryPct: pct, charging })
  await Location.startLocationUpdatesAsync(DUTY_TASK, optionsFor(plan))
  currentPlan = plan
  // Started with a screen: sharing is back, so "open the app" is no longer true.
  if (screenThisRun) void dismiss(resumeKey(duty)).catch(() => undefined)
}

/*
 * ANDROID RESTARTED THE APP WITH NO SCREEN, ON DUTY (M22 §1.1, D-214). After an update or a phone restart Android
 * starts the app for its background work, and will not let it bring on-duty sharing back until the officer opens it:
 * a location service started from the background gets no location. So nothing is recorded — no false "location
 * shared" notice either — and the officer is told once, on the phone, what to do. The server sees the gap and pushes
 * too (`tracking:watch`, D-207), and the dispatcher's alertness board shows it.
 */
let screenThisRun = false
let remindedThisRun = false
const resumeKey = (duty: Duty): string => `tracking-resume-${duty.shiftId}`

/** The app has a screen in this run (TrackingRuntime mounts): whatever needs opening is open. */
export function screenShown(): void {
  screenThisRun = true
}

/**
 * Called by the queue's sync, which Android runs with no screen right after an update or a restart. On duty, sharing,
 * and nothing taken for GAP_MS: say that sharing waits for the app to be opened. A request Android restored can still
 * hand over a position now and then; while positions are being taken, nothing is said.
 */
export async function remindIfHeadless(nowMs: number = Date.now(), os: string = Platform.OS): Promise<void> {
  // Android's rule, so Android's notice. (There is no iPhone app yet: KNOWN_ISSUES KI-07.)
  if (os !== 'android' || screenThisRun || remindedThisRun) return
  await getStorage().hydrate().catch(() => undefined)
  const duty = readDuty()
  if (!duty || !mayShare(trackingStore.status(duty.owner))) return
  const newest = trackingStore.recent().at(-1)
  const takenMs = newest ? Date.parse(newest.captured_at) : Number.NaN
  if (Number.isFinite(takenMs) && nowMs - takenMs < GAP_MS) return
  remindedThisRun = true
  await show(
    {
      key: resumeKey(duty),
      title: 'Open GeminiSecure Guard to share your location',
      body: 'Your phone restarted the app (an update or a restart). Android lets it share your position again only once you open it. Until then the control room sees a gap.',
      data: { type: 'tracking_gap', id: duty.shiftId },
    },
    true,
  ).catch(() => false)
}

/** Sampling follows the officer: slower standing still or on a low battery, back up when they move or charge. */
async function adapt(duty: Duty, pct: number | null, charging: boolean): Promise<void> {
  const plan = planFor(samplingOf(duty), { stationary: isStationary(trackingStore.recent(), Date.now()), batteryPct: pct, charging })
  if (samePlan(currentPlan, plan)) return
  await Location.startLocationUpdatesAsync(DUTY_TASK, optionsFor(plan))
  currentPlan = plan
}

/**
 * SIGNED OUT IN THE MIDDLE OF A SHIFT (M22 §1.3, found on the emulator). A position send answered 401: this phone's
 * session is gone — the office ended its access, or another phone took the officer's place — and the server will
 * keep nothing it sends. The task went on sampling and sending every 45 seconds, each one refused, while the
 * notification still said "location shared". Now sampling stops, so the notice goes with it, and the officer is told
 * once, on the phone. What was already taken stays queued (KEEP_ON_SESSION_END) and goes when the same officer signs
 * in again. The server sees the gap too (`tracking:watch`), and the dispatcher's alertness board shows it. (A guard ON
 * SHIFT is no longer timed out for being idle: the server keeps the session for the shift — D-206.)
 */
async function signedOutOnDuty(): Promise<void> {
  const duty = readDuty()
  if (!duty) return
  trackingStore.clearDuty()
  await stopUpdates()
  await show(
    {
      key: `tracking-signed-out-${duty.shiftId}`,
      title: 'Location sharing has stopped',
      body: 'This phone is signed out, so your position is not reaching the control room. Open GeminiSecure Guard and sign in again: the positions already taken are kept and sent then.',
      data: { type: 'tracking_gap', id: duty.shiftId },
    },
    true,
  ).catch(() => false)
}

/**
 * Send what is waiting, a batch at a time, until the queue is empty or the server cannot be reached. One send at
 * a time; after a failure the next routine try waits (30 s, doubling, at most ten minutes). `force` — a named
 * moment, the connection coming back, the app coming to the front — goes at once.
 */
export function flush(options: { force?: boolean } = {}): Promise<void> {
  if (sending && Date.now() - sendingSince < STUCK_SEND_MS) return sending
  if (!options.force && Date.now() < notBefore) return Promise.resolve()
  const mine = ++sendSeq
  sendingSince = Date.now()
  const run = (async () => {
    // Nothing runs before this: a run that returned at once (an empty queue, a clock-in still waiting) cleared the slot
    // before it was taken, and its finished answer then stood in for every send — a scan's too — for STUCK_SEND_MS.
    await Promise.resolve()
    try {
      for (let round = 0; round < 20; round++) {
        const owner = trackingStore.queueOwner()
        if (owner === null) return
        const items = trackingStore.queue(owner)
        const batch = nextBatch(items)
        if (batch === null) return
        if (clockInWaiting()) return

        const answer = await sendPositions(batch.shiftId, batch.fixes)
        if (answer.kind === 'answered') {
          await trackingStore.setQueue(owner, afterAnswer(trackingStore.queue(owner), answer))
          failures = 0
          notBefore = 0
          lastSentAt = Date.now()
          trackingStore.markSent()
          continue
        }
        if (answer.kind === 'not_this_shift') {
          await trackingStore.setQueue(owner, withoutShift(trackingStore.queue(owner), batch.shiftId))
          continue
        }
        if (answer.kind === 'stop') {
          // The server will keep nothing: sampling on would only flatten the battery. The officer is asked again
          // (a refused or changed policy) the next time the app reads the status.
          await trackingStore.setQueue(owner, [])
          trackingStore.clearDuty()
          await stopUpdates()
          return
        }
        if (answer.kind === 'signed_out') {
          await signedOutOnDuty()
          return
        }
        failures += 1
        notBefore = Date.now() + retryDelayMs(failures)
        return
      }
    } finally {
      // An abandoned send that answers late must not clear the one that replaced it.
      if (sendSeq === mine) sending = null
    }
  })()
  sending = run
  return run
}

/** What the platform hands the task: positions, in order. The duty is read first, every time. */
export async function onPositions(locations: readonly Location.LocationObject[]): Promise<void> {
  // AsyncStorage (Expo Go) must be read into memory first; MMKV, in every real build, needs nothing.
  await getStorage().hydrate().catch(() => undefined)
  const duty = readDuty()
  if (!duty) return stopUpdates()
  const status = trackingStore.status(duty.owner)
  if (status !== null && !mayShare(status)) {
    trackingStore.clearDuty()
    return stopUpdates()
  }

  const sampling = samplingOf(duty)
  const { pct, charging } = await battery()
  let previous: Fix | null = trackingStore.recent().at(-1) ?? null
  for (const at of locations) {
    previous = await keep(duty, at, sourceOf(previous, { latitude: at.coords.latitude, longitude: at.coords.longitude, atMs: at.timestamp }, sampling), pct)
  }

  // Not awaited: the task hands its batch back to the platform at once, whatever the network is doing. A task waiting
  // on a send that never answered held every later batch back, and those positions were never kept (M21 §1.2).
  if (Date.now() - lastSentAt >= SEND_EVERY_MS) void flush()
  await adapt(duty, pct, charging).catch(() => undefined)
}

// Defined at the top of the bundle (apps/guard/index.ts imports this file first), so it exists also when Android
// starts the app with no screen to hand over a position.
if (native) {
  try {
    TaskManager.defineTask<{ locations?: Location.LocationObject[] }>(DUTY_TASK, async ({ data, error }) => {
      if (error) return
      await onPositions(data?.locations ?? [])
    })
  } catch {
    // A build without the native module: nothing can start, so nothing is defined.
  }
}

/* ---- what the screens call ------------------------------------------------------------------------------------ */

export type StartOutcome = 'started' | 'not_shared' | 'no_permission' | 'unavailable'

/**
 * At clock-in (and when a shift already under way is found with no service running: a reboot, a reinstall, a
 * consent given mid-shift). Asks nothing: with no consent or no "all the time" permission it starts nothing.
 */
export async function startDuty(input: { owner: string; shiftId: number | string; moment?: boolean }): Promise<StartOutcome> {
  if (!native) return 'unavailable'
  if (!mayShare(trackingStore.status(input.owner))) return 'not_shared'
  try {
    const [foreground, background] = await Promise.all([Location.getForegroundPermissionsAsync(), Location.getBackgroundPermissionsAsync()])
    if (!foreground.granted || !background.granted) return 'no_permission'
    const duty: Duty = { owner: input.owner, shiftId: input.shiftId, startedAt: new Date().toISOString() }
    await trackingStore.setDuty(duty)
    await startUpdates(duty)
    if (input.moment !== false) void recordMoment('clock_in')
    return 'started'
  } catch {
    trackingStore.clearDuty()
    return 'unavailable'
  }
}

const giveUp = (ms: number): Promise<null> => new Promise((resolve) => setTimeout(() => resolve(null), ms))

/** One fix NOW, for a named moment of the shift — only while on duty and sharing. Never asks for anything. */
export async function recordMoment(source: Exclude<Source, 'interval' | 'movement'>): Promise<void> {
  if (!native) return
  const duty = readDuty()
  if (!duty || !mayShare(trackingStore.status(duty.owner))) return
  try {
    if (!(await Location.getForegroundPermissionsAsync()).granted) return
    // A fresh fix if one comes in time; otherwise the phone's own last one, if it is recent. Never nothing made up.
    const fresh = await Promise.race([Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }), giveUp(MOMENT_WAIT_MS)])
    const at = fresh ?? (await Location.getLastKnownPositionAsync({ maxAge: 2 * 60_000 }))
    if (!at) return
    const { pct } = await battery()
    await keep(duty, at, source, pct)
    void flush({ force: true })
  } catch {
    // No fix: the moment is recorded without one on its own record, as it always was.
  }
}

/**
 * Where the officer is, from the fixes on-duty sharing has ALREADY taken — for a checkpoint scan to carry (M21 §1.1).
 * Reads nothing new and never waits: null when not on duty, not sharing, or when the newest fix is older than
 * SCAN_FIX_MAX_AGE_MS. The scan is then recorded with "no position", which the server says, never refuses.
 */
export function sharedFix(nowMs: number = Date.now()): { latitude: number; longitude: number; accuracy_m: number | null } | null {
  const duty = readDuty()
  if (!duty || !mayShare(trackingStore.status(duty.owner))) return null
  const fix = latestFix(trackingStore.recent(), nowMs)
  return fix ? { latitude: fix.latitude, longitude: fix.longitude, accuracy_m: fix.accuracy_m } : null
}

/** At clock-out: the last fix of the shift, then the service stops. What is queued is still sent. */
export async function endDuty(): Promise<void> {
  if (!native) return
  if (readDuty()) await recordMoment('clock_out')
  trackingStore.clearDuty()
  await stopUpdates()
  void flush({ force: true })
}

/** The officer said no (or changed their mind): anything running stops. What was taken before stays theirs to send. */
export async function stopSharing(): Promise<void> {
  trackingStore.clearDuty()
  if (native) await stopUpdates()
}

/** At sign-out, or when the phone finds a duty that is not this officer's: stop, and keep nothing. */
export async function stopDuty(): Promise<void> {
  trackingStore.clearAll()
  if (native) await stopUpdates()
}

/**
 * When the app starts, comes back, or reads the shift again: make the phone agree with the shift. A duty whose shift
 * has ended elsewhere (a supervisor clocked the officer out) stops; a shift under way with sharing agreed and no
 * service running (a reboot ends a foreground service) starts again, without a clock-in fix — it is not a clock-in.
 *
 *   readAtMs  when the shift was read, on the phone's clock. Only a read taken AFTER the duty began can end it: for a
 *             moment after a clock-in has synced, the read from before it still says "not on duty", and the service
 *             was stopped and started again within 70 ms of every clock-in (M21 §1.2, found on the emulator). That
 *             read is waited out; the next one decides. Left out, any read decides.
 *   rearm     the app has just come to the front: ask again for the positions of a duty already running (below).
 */
export async function reconcileDuty(input: { owner: string | null; shiftId: number | string | null; onDuty: boolean; readAtMs?: number; rearm?: boolean }): Promise<void> {
  if (!native) return
  const duty = readDuty()
  if (duty && (input.owner === null || duty.owner !== input.owner)) return stopDuty()
  if (duty && (!input.onDuty || input.shiftId === null || String(duty.shiftId) !== String(input.shiftId))) {
    const began = Date.parse(duty.startedAt)
    if (input.readAtMs !== undefined && Number.isFinite(began) && input.readAtMs <= began) return
    trackingStore.clearDuty()
    await stopUpdates()
    return
  }
  if (input.owner === null || !input.onDuty || input.shiftId === null) return
  try {
    /*
     * RE-ARMED WHEN THE APP COMES FORWARD, NOT ONLY WHEN "NOT STARTED" (M21 §1.2, found on the emulator).
     * `hasStartedLocationUpdatesAsync` answers whether the TASK is registered — it survives in storage — not whether
     * the platform is still sending positions for it. After an update install and a restart the task stayed "started"
     * while no position was requested at all: the notification said "location shared" and nothing was taken. Asking
     * again for a task that is running replaces its request with the same one. It is done only with the app in front,
     * where the platform lets the service start — and not on every read of the shift, which restarted the request
     * each minute.
     */
    if (!duty) await startDuty({ owner: input.owner, shiftId: input.shiftId, moment: false })
    else if (input.rearm && AppState.currentState === 'active' && mayShare(trackingStore.status(input.owner))) await startUpdates(duty)
  } catch {
    // Nothing started; the next reconcile tries again.
  }
}

/** Whether the service is running now — what the Home card and the settings row say. */
export async function isSharing(): Promise<boolean> {
  if (!native || !readDuty()) return false
  try {
    return await Location.hasStartedLocationUpdatesAsync(DUTY_TASK)
  } catch {
    return false
  }
}

/** What the phone allows now, without asking. */
export async function permissionState(): Promise<{ foreground: boolean; background: boolean }> {
  if (!native) return { foreground: false, background: false }
  try {
    const [foreground, background] = await Promise.all([Location.getForegroundPermissionsAsync(), Location.getBackgroundPermissionsAsync()])
    return { foreground: foreground.granted, background: background.granted }
  } catch {
    return { foreground: false, background: false }
  }
}

/**
 * The phone's two dialogs, in the order the platforms require — "while using the app", then "all the time". Called
 * ONLY from the consent screen's Agree button, after the officer read what is recorded, when, who sees it and for
 * how long. `canAskAgain` false means the phone will not show the dialog again: the screen offers its Settings.
 */
export async function askPermissions(): Promise<{ foreground: boolean; background: boolean; canAskAgain: boolean }> {
  if (!native) return { foreground: false, background: false, canAskAgain: false }
  const foreground = await Location.requestForegroundPermissionsAsync()
  if (!foreground.granted) return { foreground: false, background: false, canAskAgain: foreground.canAskAgain }
  const background = await Location.requestBackgroundPermissionsAsync()
  return { foreground: true, background: background.granted, canAskAgain: background.canAskAgain }
}

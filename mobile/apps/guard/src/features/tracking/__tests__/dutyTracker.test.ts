/**
 * THE ON-DUTY TRACKER (M11 §3) — the one file allowed to run location in the background, held to what it promises:
 *   - it starts nothing without consent to the current policy and "all the time" permission, and never asks;
 *   - its background task reads the duty first and stops the service when there is none;
 *   - clock-out takes the last fix and stops it; what is queued is still sent; sign-out keeps nothing;
 *   - the server's "stop" stops it; a clock-in still waiting on the phone holds the fixes back.
 */
import { AppState } from 'react-native'
import * as Location from 'expo-location'
import * as TaskManager from 'expo-task-manager'
import { getStorage } from '@gs/core/lib'
import { OUTBOX_PREFIX } from '@gs/core/offline'
import { sendPositions } from '../positionSender'
import { trackingStore } from '../trackingStore'
import { DEFAULT_SAMPLING, POLICY_VERSION, type TrackingStatus } from '../trackingPolicy'
import { DUTY_NOTICE, DUTY_TASK, STUCK_SEND_MS, endDuty, flush, onPositions, reconcileDuty, recordMoment, startDuty, stopDuty } from '../dutyTracker'

jest.mock('expo-location', () => ({
  Accuracy: { Balanced: 3, High: 4 },
  ActivityType: { Other: 1 },
  getForegroundPermissionsAsync: jest.fn(),
  getBackgroundPermissionsAsync: jest.fn(),
  requestForegroundPermissionsAsync: jest.fn(),
  requestBackgroundPermissionsAsync: jest.fn(),
  startLocationUpdatesAsync: jest.fn(async () => undefined),
  stopLocationUpdatesAsync: jest.fn(async () => undefined),
  hasStartedLocationUpdatesAsync: jest.fn(async () => true),
  getCurrentPositionAsync: jest.fn(),
  getLastKnownPositionAsync: jest.fn(async () => null),
}))
jest.mock('expo-task-manager', () => ({ defineTask: jest.fn() }))
jest.mock('expo-battery', () => ({ BatteryState: { UNKNOWN: 0, UNPLUGGED: 1, CHARGING: 2, FULL: 3 }, getBatteryLevelAsync: jest.fn(async () => 0.8), getBatteryStateAsync: jest.fn(async () => 1) }))
jest.mock('../positionSender', () => ({ sendPositions: jest.fn(async () => ({ kind: 'answered', accepted: [], refused: [] })) }))
jest.mock('@gs/core/notify', () => ({ show: jest.fn(async () => true), dismiss: jest.fn(async () => undefined) }))

const L = Location as jest.Mocked<typeof Location>
const send = sendPositions as jest.MockedFunction<typeof sendPositions>
const notice = (jest.requireMock('@gs/core/notify') as { show: jest.Mock }).show
const OWNER = 'GS-0412'
const status = (over: Partial<TrackingStatus> = {}): TrackingStatus => ({ enabled: true, policy_version: POLICY_VERSION, consent: { decision: 'granted', policy_version: POLICY_VERSION, decided_at: '2026-10-03T12:00:00+00:00' }, retention_days: 90, sampling: DEFAULT_SAMPLING, ...over })
const perm = (granted: boolean) => ({ granted, status: granted ? 'granted' : 'denied', canAskAgain: true, expires: 'never' }) as unknown as Location.LocationPermissionResponse
const at = (lat: number, time = Date.now()) => ({ coords: { latitude: lat, longitude: -76.81, accuracy: 7, altitude: null, altitudeAccuracy: null, heading: null, speed: null }, timestamp: time }) as Location.LocationObject

beforeEach(async () => {
  jest.clearAllMocks()
  await getStorage().clearAll()
  getStorage().remove(`${OUTBOX_PREFIX}v1`)
  L.getForegroundPermissionsAsync.mockResolvedValue(perm(true))
  L.getBackgroundPermissionsAsync.mockResolvedValue(perm(true))
  L.getCurrentPositionAsync.mockResolvedValue(at(18.0181))
  send.mockResolvedValue({ kind: 'answered', accepted: [], refused: [] })
})

it('defines its background task at the top of the bundle', () => {
  // Called when the module loaded (jest.clearAllMocks runs after), so it is read from the module registry instead.
  jest.isolateModules(() => {
    require('../dutyTracker')
    expect((require('expo-task-manager') as typeof TaskManager).defineTask).toHaveBeenCalledWith(DUTY_TASK, expect.any(Function))
  })
})

describe('starting', () => {
  it('starts nothing without consent to the current words, and asks for nothing', async () => {
    expect(await startDuty({ owner: OWNER, shiftId: 7 })).toBe('not_shared')
    trackingStore.setStatus(OWNER, status({ consent: { decision: 'refused', policy_version: POLICY_VERSION, decided_at: 'x' } }))
    expect(await startDuty({ owner: OWNER, shiftId: 7 })).toBe('not_shared')
    expect(L.startLocationUpdatesAsync).not.toHaveBeenCalled()
    expect(L.requestForegroundPermissionsAsync).not.toHaveBeenCalled()
    expect(L.requestBackgroundPermissionsAsync).not.toHaveBeenCalled()
    expect(trackingStore.duty()).toBeNull()
  })

  it('starts nothing without "all the time" permission', async () => {
    trackingStore.setStatus(OWNER, status())
    L.getBackgroundPermissionsAsync.mockResolvedValue(perm(false))
    expect(await startDuty({ owner: OWNER, shiftId: 7 })).toBe('no_permission')
    expect(L.startLocationUpdatesAsync).not.toHaveBeenCalled()
  })

  it('at clock-in: the duty on the phone, the service with its notification, and a clock-in fix', async () => {
    trackingStore.setStatus(OWNER, status())
    expect(await startDuty({ owner: OWNER, shiftId: 7 })).toBe('started')
    expect(trackingStore.duty()).toMatchObject({ owner: OWNER, shiftId: 7 })
    const [task, options] = L.startLocationUpdatesAsync.mock.calls[0]!
    expect(task).toBe(DUTY_TASK)
    expect(options?.foregroundService).toEqual(DUTY_NOTICE)
    expect(DUTY_NOTICE.notificationTitle).toBe('On duty · location shared with control room')
    // On the clock, never on distance (M22 §1.1): a still phone is sampled too.
    expect(options).toMatchObject({ timeInterval: 45_000, distanceInterval: 0, showsBackgroundLocationIndicator: true, pausesUpdatesAutomatically: false })
    await new Promise((r) => setTimeout(r, 0))
    await flush({ force: true })
    expect(trackingStore.recent().map((f) => f.source)).toContain('clock_in')
  })
})

describe('the background task', () => {
  it('with no duty on the phone, stops the service and keeps nothing', async () => {
    await onPositions([at(18.02)])
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalledWith(DUTY_TASK)
    expect(trackingStore.queue(OWNER)).toEqual([])
  })

  it('keeps each position with the time it was TAKEN, and sends', async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    const taken = Date.parse('2026-10-03T12:00:00Z')
    await onPositions([at(18.02, taken), at(18.0201, taken + 45_000)])
    // The task sends at most once a minute (every send wakes the radio); a forced send goes now.
    await flush({ force: true })
    expect(send).toHaveBeenCalledWith(7, expect.any(Array))
    const sent = send.mock.calls[0]![1]
    expect(sent.map((f) => new Date(f.captured_at).getTime())).toEqual([taken, taken + 45_000])
    expect(sent.every((f) => f.battery_pct === 80 && f.accuracy_m === 7)).toBe(true)
  })

  it('stops when the officer withdrew consent, whatever the duty says', async () => {
    trackingStore.setStatus(OWNER, status({ consent: { decision: 'refused', policy_version: POLICY_VERSION, decided_at: 'x' } }))
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    await onPositions([at(18.02)])
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalled()
    expect(trackingStore.duty()).toBeNull()
  })
})

describe('ending', () => {
  it('clock-out takes the last fix, stops the service, and what is queued is still sent', async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    send.mockResolvedValue({ kind: 'failed' })
    await endDuty()
    expect(trackingStore.duty()).toBeNull()
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalledWith(DUTY_TASK)
    expect(trackingStore.queue(OWNER).map((x) => x.fix.source)).toContain('clock_out')
    // After the shift a moment records nothing: there is no duty.
    L.getCurrentPositionAsync.mockClear()
    await recordMoment('gate')
    expect(L.getCurrentPositionAsync).not.toHaveBeenCalled()
  })

  it('sign-out keeps nothing', async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    await trackingStore.push(OWNER, { shiftId: 7, fix: { id: 'f', latitude: 1, longitude: 1, accuracy_m: null, source: 'interval', battery_pct: null, captured_at: 'x' } })
    await stopDuty()
    expect(trackingStore.duty()).toBeNull()
    expect(trackingStore.queueOwner()).toBeNull()
    expect(trackingStore.status(OWNER)).toBeNull()
  })

  /*
   * M21 §1.2, on the emulator: after an update install the task stayed registered — "started" — while the platform
   * requested no position at all, and the notification went on saying "location shared".
   */
  it('re-arms a shift under way every time the app comes forward, even when the task says it is started', async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    L.hasStartedLocationUpdatesAsync.mockResolvedValue(true)
    const state = Object.getOwnPropertyDescriptor(AppState, 'currentState')
    Object.defineProperty(AppState, 'currentState', { value: 'background', configurable: true })
    // In the background the platform refuses to start the service: nothing is asked for there.
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: true, rearm: true })
    expect(L.startLocationUpdatesAsync).not.toHaveBeenCalled()
    Object.defineProperty(AppState, 'currentState', { value: 'active', configurable: true })
    // A read of the shift is not the app coming forward: a running request is not restarted each minute.
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: true })
    expect(L.startLocationUpdatesAsync).not.toHaveBeenCalled()
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: true, rearm: true })
    expect(L.startLocationUpdatesAsync).toHaveBeenCalledWith(DUTY_TASK, expect.objectContaining({ foregroundService: DUTY_NOTICE }))
    // Without consent to the current words nothing is asked for.
    L.startLocationUpdatesAsync.mockClear()
    trackingStore.setStatus(OWNER, status({ consent: { decision: 'refused', policy_version: POLICY_VERSION, decided_at: 'x' } }))
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: true, rearm: true })
    expect(L.startLocationUpdatesAsync).not.toHaveBeenCalled()
    if (state) Object.defineProperty(AppState, 'currentState', state)
  })

  /*
   * M21 §1.2, on the emulator: for a moment after the clock-in synced, the shift read from BEFORE it still said "not
   * on duty" — the service was stopped and started again within 70 ms of every clock-in.
   */
  it('only a read of the shift taken after the duty began can end it', async () => {
    trackingStore.setStatus(OWNER, status())
    const began = Date.parse('2026-10-03T17:00:00Z')
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: new Date(began).toISOString() })
    await reconcileDuty({ owner: OWNER, shiftId: null, onDuty: false, readAtMs: began - 5_000 })
    expect(trackingStore.duty()).not.toBeNull()
    expect(L.stopLocationUpdatesAsync).not.toHaveBeenCalled()
    // The shift from the cache alone (read before this session) never ends it either.
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: false, readAtMs: 0 })
    expect(trackingStore.duty()).not.toBeNull()
    // A read from after it began says the shift ended elsewhere: sharing stops.
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: false, readAtMs: began + 60_000 })
    expect(trackingStore.duty()).toBeNull()
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalledWith(DUTY_TASK)
  })

  it('a duty that is not this officer\'s is stopped when the phone finds it', async () => {
    await trackingStore.setDuty({ owner: 'SOMEONE-ELSE', shiftId: 7, startedAt: 'x' })
    await reconcileDuty({ owner: OWNER, shiftId: 7, onDuty: true })
    expect(trackingStore.duty()).toBeNull()
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalled()
  })
})

describe('sending', () => {
  const queued = async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    await trackingStore.push(OWNER, { shiftId: 7, fix: { id: 'f1', latitude: 18, longitude: -76.8, accuracy_m: 5, source: 'interval', battery_pct: 50, captured_at: '2026-10-03T12:00:00-05:00' } })
  }

  it('a kept fix leaves the phone', async () => {
    await queued()
    send.mockResolvedValue({ kind: 'answered', accepted: ['f1'], refused: [] })
    await flush({ force: true })
    expect(trackingStore.queue(OWNER)).toEqual([])
  })

  it('the server\'s "keep nothing" stops the service and empties the queue', async () => {
    await queued()
    send.mockResolvedValue({ kind: 'stop', code: 'consent_required' })
    await flush({ force: true })
    expect(trackingStore.queue(OWNER)).toEqual([])
    expect(trackingStore.duty()).toBeNull()
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalled()
  })

  it('holds the fixes back while the clock-in is still on the phone: the server would refuse them', async () => {
    await queued()
    getStorage().set(`${OUTBOX_PREFIX}v1`, JSON.stringify([{ endpoint: 'shifts.clock_in', state: 'pending' }]))
    await flush({ force: true })
    expect(send).not.toHaveBeenCalled()
    expect(trackingStore.queue(OWNER)).toHaveLength(1)
  })

  /*
   * M22 §1.3, on the emulator: signed out two hours into a shift, the task went on sampling and sending every 45
   * seconds, each send refused with 401, while the notification still said "location shared".
   */
  it('signed out mid-shift: sharing stops, the officer is told once, and what was taken is kept', async () => {
    await queued()
    send.mockResolvedValue({ kind: 'signed_out' })
    await flush({ force: true })

    expect(trackingStore.duty()).toBeNull()
    expect(L.stopLocationUpdatesAsync).toHaveBeenCalledWith(DUTY_TASK)
    expect(notice).toHaveBeenCalledTimes(1)
    expect(notice.mock.calls[0]![0]).toMatchObject({ title: 'Location sharing has stopped', data: { type: 'tracking_gap', id: 7 } })
    expect(notice.mock.calls[0]![0].body).toContain('signed out')
    // Taken on the shift: kept for the same officer's next sign-in.
    expect(trackingStore.queue(OWNER)).toHaveLength(1)

    // Nothing more is said: there is no duty left to stop.
    await flush({ force: true })
    expect(notice).toHaveBeenCalledTimes(1)
  })

  it('remembers when the control room last had this phone\'s positions', async () => {
    await queued()
    expect(trackingStore.lastSentAt()).toBeNull()
    send.mockResolvedValue({ kind: 'answered', accepted: ['f1'], refused: [] })
    await flush({ force: true })
    expect(trackingStore.lastSentAt()).not.toBeNull()
  })

  it('a failure keeps everything for the next try', async () => {
    await queued()
    send.mockResolvedValue({ kind: 'failed' })
    await flush({ force: true })
    expect(trackingStore.queue(OWNER)).toHaveLength(1)
  })

  /*
   * M21 §1.2, on the emulator: the connection went away under a send, the send never settled, and it held the one
   * send slot — nothing more left the phone, and the background task, waiting on it, kept no more positions.
   */
  it('a send that never answers is abandoned, and the next one goes', async () => {
    await queued()
    send.mockImplementationOnce(() => new Promise(() => undefined))
    const now = jest.spyOn(Date, 'now')
    try {
      const t0 = Date.parse('2026-10-03T17:00:00Z')
      now.mockReturnValue(t0)
      void flush({ force: true })
      await new Promise((r) => setTimeout(r, 0))
      expect(send).toHaveBeenCalledTimes(1)

      // Inside the limit it is still the send in flight: nothing is sent twice.
      now.mockReturnValue(t0 + STUCK_SEND_MS - 1_000)
      void flush({ force: true })
      await new Promise((r) => setTimeout(r, 0))
      expect(send).toHaveBeenCalledTimes(1)

      // Past it, the next send goes, and what it answers leaves the phone.
      now.mockReturnValue(t0 + STUCK_SEND_MS + 1_000)
      send.mockResolvedValue({ kind: 'answered', accepted: ['f1'], refused: [] })
      await flush({ force: true })
      expect(send).toHaveBeenCalledTimes(2)
      expect(trackingStore.queue(OWNER)).toEqual([])
    } finally {
      now.mockRestore()
    }
  })

  /*
   * M21 §1.2: a run that returned at once — nothing queued yet — cleared the send slot before it was taken, and its
   * finished answer then stood in for every send for STUCK_SEND_MS: a checkpoint's fix waited a minute to leave.
   */
  it('a send with nothing to send leaves the slot free for the next one', async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    await flush({ force: true })
    expect(send).not.toHaveBeenCalled()
    await trackingStore.push(OWNER, { shiftId: 7, fix: { id: 'f2', latitude: 18, longitude: -76.8, accuracy_m: 5, source: 'checkpoint', battery_pct: 50, captured_at: '2026-10-03T12:00:00-05:00' } })
    send.mockResolvedValue({ kind: 'answered', accepted: ['f2'], refused: [] })
    await flush({ force: true })
    expect(send).toHaveBeenCalledTimes(1)
    expect(trackingStore.queue(OWNER)).toEqual([])
  })

  it('the background task keeps every position it is handed while a send hangs', async () => {
    trackingStore.setStatus(OWNER, status())
    await trackingStore.setDuty({ owner: OWNER, shiftId: 7, startedAt: 'x' })
    send.mockImplementation(() => new Promise(() => undefined))
    const taken = Date.now()
    // Each call returns: the task never waits on the network.
    await onPositions([at(18.02, taken)])
    await onPositions([at(18.021, taken + 45_000)])
    await onPositions([at(18.022, taken + 90_000)])
    expect(trackingStore.queue(OWNER)).toHaveLength(3)
  })
})

/*
 * M22 §1.1 (D-214): after an update or a phone restart Android runs the app with no screen, and will not let sharing
 * come back until the officer opens it. The queue's sync, which Android runs then, says so once, and only when
 * nothing is being taken. The run's own state is per process, so each case has a fresh one.
 */
describe('restarted with no screen, on duty', () => {
  const run = () => {
    let tracker!: typeof import('../dutyTracker')
    let store!: typeof import('../trackingStore')['trackingStore']
    let notify!: { show: jest.Mock; dismiss: jest.Mock }
    jest.isolateModules(() => {
      tracker = require('../dutyTracker')
      store = require('../trackingStore').trackingStore
      notify = require('@gs/core/notify')
    })
    return { tracker, store, notify }
  }
  const NOW = Date.parse('2026-10-03T12:30:00-05:00')
  const onDuty = async (store: ReturnType<typeof run>['store'], lastTaken?: string) => {
    store.setStatus(OWNER, status())
    await store.setDuty({ owner: OWNER, shiftId: 7, startedAt: '2026-10-03T12:00:00-05:00' })
    if (lastTaken) await store.push(OWNER, { shiftId: 7, fix: { id: 'f9', latitude: 18, longitude: -76.8, accuracy_m: 5, source: 'interval', battery_pct: 50, captured_at: lastTaken } })
  }

  it('tells the officer once to open the app when nothing has been taken for ten minutes', async () => {
    const { tracker, store, notify } = run()
    await onDuty(store, '2026-10-03T12:15:00-05:00')
    await tracker.remindIfHeadless(NOW, 'android')
    await tracker.remindIfHeadless(NOW + 60_000, 'android')
    expect(notify.show).toHaveBeenCalledTimes(1)
    expect(notify.show.mock.calls[0][0]).toMatchObject({ key: 'tracking-resume-7', title: 'Open GeminiSecure Guard to share your location', data: { type: 'tracking_gap', id: 7 } })
  })

  it('says nothing while positions are still being taken, off duty, with a screen, or on another platform', async () => {
    const taking = run()
    await onDuty(taking.store, '2026-10-03T12:25:00-05:00')
    await taking.tracker.remindIfHeadless(NOW, 'android')
    expect(taking.notify.show).not.toHaveBeenCalled()

    const offDuty = run()
    await offDuty.tracker.remindIfHeadless(NOW, 'android')
    expect(offDuty.notify.show).not.toHaveBeenCalled()

    const opened = run()
    await onDuty(opened.store)
    opened.tracker.screenShown()
    await opened.tracker.remindIfHeadless(NOW, 'android')
    expect(opened.notify.show).not.toHaveBeenCalled()

    const iphone = run()
    await onDuty(iphone.store)
    await iphone.tracker.remindIfHeadless(NOW, 'ios')
    expect(iphone.notify.show).not.toHaveBeenCalled()
  })

  it('takes the notice down once the app is opened and sharing is started again', async () => {
    const { tracker, store, notify } = run()
    const location = jest.requireMock('expo-location') as jest.Mocked<typeof Location>
    location.getForegroundPermissionsAsync.mockResolvedValue(perm(true))
    location.getBackgroundPermissionsAsync.mockResolvedValue(perm(true))
    await onDuty(store)
    tracker.screenShown()
    expect(await tracker.startDuty({ owner: OWNER, shiftId: 7, moment: false })).toBe('started')
    expect(notify.dismiss).toHaveBeenCalledWith('tracking-resume-7')
  })
})

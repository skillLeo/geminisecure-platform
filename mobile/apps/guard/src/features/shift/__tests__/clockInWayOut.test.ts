/**
 * CLOCK IN IS NEVER A DEAD END, A QUEUED CLOCK-IN IS A SHIFT UNDER WAY, AND RETRY RETRIES (B127, B129, B131).
 *
 *   B127  the splash and the end of setup land on Clock In, which had no way Home: a guard between shifts could
 *         not reach Home, Patrol, the sync queue or the duress shield without clocking in, and Back left the app.
 *         A clock-in still queued on the phone after a restart sent the guard back to clock in again.
 *   B129  a guard who clocked in with no signal could not reach clock-out until that clock-in synced.
 *   B131  Retry and pull-to-refresh ran the geofence check alone, and never reloaded a shift that failed to load.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { getStorage } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { clockInWaitingOnPhone, nextRoute } from '@/features/boot/nextRoute'

const feature = (rel: string) => readFileSync(path.join(__dirname, '..', '..', rel), 'utf8')
const shift = (status: string) => ({ shift: { id: 7, status } })
const setShift = (status: string) => getStorage().set('v1:cache:shift.current', JSON.stringify(shift(status)))
const setQueue = (ops: { endpoint: string; state: string }[]) => getStorage().set('outbox:v1', JSON.stringify(ops))

beforeEach(async () => {
  await getStorage().clearAll()
  const at = '2026-09-24T12:00:00Z'
  onboarding.update({ detailsConfirmedAt: at, identity: 'confirmed', kitAcknowledgedAt: at, notificationsAskedAt: at, locationDisclosedAt: at, finishedAt: at })
})

describe('B127: where the splash hands over', () => {
  it('a clock-in queued on this phone is a shift under way: Home, not "clock in again"', () => {
    setShift('rostered')
    setQueue([{ endpoint: 'shifts.clock_in', state: 'pending' }])
    expect(clockInWaitingOnPhone()).toBe(true)
    expect(nextRoute('active')).toBe('/(tabs)')
  })

  it('a clock-in the server refused, or one already confirmed, is not a shift under way', () => {
    setShift('rostered')
    setQueue([{ endpoint: 'shifts.clock_in', state: 'refused' }, { endpoint: 'patrol.scan', state: 'pending' }])
    expect(clockInWaitingOnPhone()).toBe(false)
    expect(nextRoute('active')).toBe('/clock-in')
  })

  it('on duty goes Home; signed out goes to enrolment; unfinished setup goes back to its step', () => {
    setShift('on_duty')
    expect(nextRoute('active')).toBe('/(tabs)')
    expect(nextRoute('signedOut')).toBe('/enrol')
    onboarding.update({ finishedAt: null, kitAcknowledgedAt: null })
    expect(nextRoute('active')).toBe('/onboarding/3')
  })

  it('a queue on disk that cannot be read is no clock-in, never a crash', () => {
    getStorage().set('outbox:v1', '{not json')
    expect(clockInWaitingOnPhone()).toBe(false)
  })
})

describe('B127: Clock In has a way Home, and the shield, in every state', () => {
  const screen = feature('shift/screens/ClockInScreen.tsx')

  it('a Home button on the bar, and Back goes Home when there is nowhere to go back to', () => {
    expect(screen).toContain("left={isFidelity ? null : { icon: 'chevron-left', iconSize: 18, label: 'Home', onPress: goHome }}")
    expect(screen).toMatch(/BackHandler\.addEventListener\('hardwareBackPress', \(\) => \{\s*if \(router\.canGoBack\(\)\) return false\s*router\.replace\('\/\(tabs\)'\)\s*return true/)
  })

  it('the duress shield is drawn outside the ready state, not only once the supervisor route opens', () => {
    expect(screen).toContain("{m.status === 'loading' || (isFidelity && !m.override) ? null : <DuressShield />}")
    expect(screen).not.toContain('{m.override ? <DuressShield /> : null}')
  })
})

describe('B129: a clock-in queued with no signal can be clocked out of', () => {
  it('Home’s card opens clock-out whether the server or this phone started the shift', () => {
    const dash = feature('dashboard/useDashboard.ts')
    expect(dash).toMatch(/if \(shift\.status === 'on_duty' \|\| \(upcoming && pendingClockIn\)\) \{[\s\S]*?action: 'clock-out',/)
    expect(dash).not.toContain("action: shift.status === 'on_duty' ? 'clock-out' : null")
    // …and clock-out itself counts a start made on this phone, and queues after it.
    const out = feature('clockout/useClockOut.ts')
    expect(out).toContain("(shift.status === 'rostered' || shift.status === 'open') && queue.startedOnPhone")
    expect(out).toContain('after: queue.clockInWaitingId')
  })
})

describe('B131: Retry and pull-to-refresh reload the shift', () => {
  it('on Clock In and on the supervisor override, both the Retry and the pull ask for the shift again', () => {
    for (const [hook, screenFile] of [['shift/useClockIn.ts', 'shift/screens/ClockInScreen.tsx'], ['shift/useOverride.ts', 'shift/screens/ClockInOverrideScreen.tsx']] as const) {
      expect(feature(hook)).toMatch(/const refresh = useCallback\(\(\) => \{[\s\S]*?refetchShift\(\)[\s\S]*?recheck\(\)/)
      const screen = feature(screenFile)
      expect(screen).toContain('onRefresh={m.refresh}')
      expect(screen).toContain('onRetry={m.refresh}')
    }
  })
})

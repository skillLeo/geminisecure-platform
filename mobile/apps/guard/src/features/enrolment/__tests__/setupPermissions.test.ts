/**
 * Setup step 4 — "Before you start work" (setupPermissions.ts).
 *
 * Three promises are tested here, because all three were broken before it existed:
 *   1  the phone IS asked to allow alerts, in setup, once — not only in a screen nobody opens;
 *   2  a guard who says no still finishes setup and still works, and is told plainly what will not
 *      happen rather than left to find out when a duress alert does not arrive;
 *   3  the location prompt cannot appear before the disclosure has been read — proved against the
 *      source of every file in this app that asks the phone for the location permission.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { getStorage } from '@gs/core/lib'
import { onboarding } from '../onboardingState'
import { ALERTS, LOCATION_DISCLOSURE, LOCATION_WITHOUT, SKIPPED, blocksSetup, outcomeOf, shouldAsk, stepCopy, type Outcome } from '../setupPermissions'

const ALL: Outcome[] = ['idle', 'granted', 'declined', 'blocked', 'unavailable']
/** `rel` is from the repo root: __tests__ → enrolment → features → src → guard → apps → root. */
const source = (rel: string) => readFileSync(path.resolve(__dirname, '..', '..', '..', '..', '..', '..', rel), 'utf8')

describe('what the step says it will send', () => {
  it('names the four things this app really pushes, in a guard’s own words', () => {
    const titles = ALERTS.map((a) => a.title.toLowerCase())
    expect(titles.some((t) => /duress|panic/.test(t))).toBe(true)
    expect(titles.some((t) => /gate/.test(t))).toBe(true)
    expect(titles.some((t) => /control room/.test(t))).toBe(true)
    expect(titles.some((t) => /alertness/.test(t))).toBe(true)
    for (const row of ALERTS) expect(row.detail.length).toBeGreaterThan(8)
  })

  it('promises nothing the app does not send, and sells nothing', () => {
    const words = JSON.stringify(ALERTS) + JSON.stringify(stepCopy('ask', 'idle'))
    expect(words).not.toMatch(/marketing|advertis|promotion|offer|news|survey/i)
    // The alert reaches the community's own officers; it is not an emergency service.
    expect(words).not.toMatch(/police|ambulance|fire service|999|911/i)
  })
})

describe('the phone’s answer', () => {
  it('is read as it really is, and a phone that cannot answer is not a refusal', () => {
    expect(outcomeOf({ granted: true, status: 'granted', canAskAgain: false })).toBe('granted')
    expect(outcomeOf({ granted: false, status: 'undetermined', canAskAgain: true })).toBe('declined')
    expect(outcomeOf({ granted: false, status: 'denied', canAskAgain: false })).toBe('blocked')
    expect(outcomeOf(null)).toBe('unavailable')
  })
})

describe('the words on the step', () => {
  it('explains BEFORE the phone is asked, and offers to skip', () => {
    const copy = stepCopy('ask', 'idle')
    expect(copy.alerts.note).toMatch(/Your phone will ask you next/)
    expect(copy.primaryLabel).toBe('Allow alerts')
    expect(copy.secondary).toEqual({ label: 'Skip for now', action: 'skip' })
    // Nothing is claimed about a state the phone has not reached.
    expect(copy.alerts.line).toBeNull()
  })

  it('says plainly that alerts are OFF, and where to turn them on', () => {
    for (const outcome of ['declined', 'blocked'] as const) {
      const copy = stepCopy('answered', outcome)
      expect(copy.alerts.line?.tone).toBe('amber-500')
      expect(copy.alerts.line?.text).toMatch(/off/i)
      expect(copy.alerts.line?.text).toMatch(/More . Notifications/)
    }
    expect(stepCopy('answered', 'blocked').secondary).toEqual({ label: 'Open phone settings', action: 'settings' })
    expect(stepCopy('answered', 'declined').secondary).toEqual({ label: 'Ask me again', action: 'ask' })
  })

  it('never claims a closed app is reached inside Expo Go, where remote push does not exist', () => {
    expect(stepCopy('ask', 'idle', false).alerts.note).toMatch(/even when the app is closed/)
    expect(stepCopy('ask', 'idle', true).alerts.note).toMatch(/only alert you while it is open/)
    expect(stepCopy('ask', 'idle', true).alerts.note).not.toMatch(/even when the app is closed/)
  })

  it('a decline never blocks setup: every outcome continues, and nothing is disabled', () => {
    for (const outcome of ALL) {
      expect(blocksSetup('answered', outcome)).toBe(false)
      expect(stepCopy('answered', outcome).primaryLabel).toBe('Finish setup')
    }
    expect(stepCopy('answered', 'granted').secondary).toBeNull()
  })
})

describe('the location disclosure: said before any prompt, and true', () => {
  it('says what is read, when, for whom, and that on-duty sharing in the background is asked separately', () => {
    expect(LOCATION_DISCLOSURE).toMatch(/start a shift/)
    expect(LOCATION_DISCLOSURE).toMatch(/end one/)
    expect(LOCATION_DISCLOSURE).toMatch(/report an incident/)
    expect(LOCATION_DISCLOSURE).toMatch(/raise an alert/)
    expect(LOCATION_DISCLOSURE).toMatch(/only while this app is open/)
    // M11 §3: it no longer promises "never in the background" to an officer who may agree to exactly that on duty.
    expect(LOCATION_DISCLOSURE).not.toMatch(/never in the background/)
    expect(LOCATION_DISCLOSURE).toMatch(/background while you are on duty is a separate question, asked on its own screen/)
    expect(LOCATION_DISCLOSURE).toMatch(/nothing is read after you clock out/)
  })

  it('does NOT claim a position is read when a checkpoint is scanned, because none is', () => {
    expect(`${LOCATION_DISCLOSURE} ${LOCATION_WITHOUT}`).not.toMatch(/checkpoint|patrol|scan/i)
    // The file that would do it says in as many words that it does not.
    expect(source('apps/guard/src/features/patrol/usePatrol.ts')).toMatch(/location is never asked for here/)
  })

  it('says what a refusal really costs, and that the guard can still work', () => {
    expect(LOCATION_WITHOUT).toMatch(/still work/)
    expect(LOCATION_WITHOUT).toMatch(/supervisor's PIN/)
    expect(LOCATION_WITHOUT).toMatch(/duress alert is still sent/)
  })

  it('is on the step itself, both halves of it', () => {
    const copy = stepCopy('ask', 'idle')
    expect(copy.location.disclosure).toBe(LOCATION_DISCLOSURE)
    expect(copy.location.without).toBe(LOCATION_WITHOUT)
  })
})

describe('the location prompt cannot appear before the disclosure', () => {
  const ASKERS = [
    'apps/guard/src/features/shift/useGeofence.ts',
    'apps/guard/src/features/clockout/useClockOut.ts',
    'apps/guard/src/features/incidents/useIncidentReport.ts',
  ]

  it('every request for the location permission in this app is behind onboarding.locationDisclosed()', () => {
    let guarded = 0
    for (const rel of ASKERS) {
      const lines = source(rel).split('\n')
      lines.forEach((line, i) => {
        if (!/requestForegroundPermissionsAsync/.test(line) || /^\s*(\*|\/\/)/.test(line)) return
        guarded++
        expect(`${rel}:${i + 1} ${line}`).toMatch(/onboarding\.locationDisclosed\(\)/)
      })
    }
    expect(guarded).toBe(ASKERS.length)
  })

  it('a duress alert never puts a dialog up at all: it uses what the phone already allows', () => {
    const duress = source('apps/guard/src/features/duress/useDuress.ts')
    expect(duress).toMatch(/getForegroundPermissionsAsync/)
    expect(duress).not.toMatch(/requestForegroundPermissionsAsync/)
  })
})

describe('setup remembers, so nothing is asked twice', () => {
  beforeEach(async () => {
    await getStorage().clearAll()
  })

  it('asks only while it has never been asked', () => {
    expect(shouldAsk(null)).toBe(true)
    expect(shouldAsk(undefined)).toBe(true)
    expect(shouldAsk('2026-09-20T10:00:00.000Z')).toBe(false)
  })

  it('walks the four steps in order and is not finished until the last one', () => {
    expect(onboarding.nextStep()).toBe(1)
    onboarding.update({ detailsConfirmedAt: 'a' })
    expect(onboarding.nextStep()).toBe(2)
    onboarding.update({ identity: 'confirmed' })
    expect(onboarding.nextStep()).toBe(3)
    onboarding.update({ kitAcknowledgedAt: 'a' })
    expect(onboarding.nextStep()).toBe(4)
    expect(onboarding.isFinished()).toBe(false)
    expect(onboarding.locationDisclosed()).toBe(false)
  })

  it('a guard who refuses alerts still finishes setup, and is never asked again', () => {
    onboarding.update({ detailsConfirmedAt: 'a', identity: 'confirmed', kitAcknowledgedAt: 'a' })
    onboarding.update({ notifications: SKIPPED, notificationsAskedAt: 'b' })
    onboarding.update({ locationDisclosedAt: 'b', finishedAt: 'b' })
    expect(onboarding.isFinished()).toBe(true)
    expect(onboarding.locationDisclosed()).toBe(true)
    expect(shouldAsk(onboarding.get().notificationsAskedAt)).toBe(false)
    expect(onboarding.get().notifications).toBe('declined')
  })

  it('a handset that finished the old three-step setup is walked through step 4 once', () => {
    onboarding.update({ detailsConfirmedAt: 'a', identity: 'confirmed', kitAcknowledgedAt: 'a', finishedAt: 'a' })
    expect(onboarding.isFinished()).toBe(false)
    expect(onboarding.nextStep()).toBe(4)
    // …and until it has been, nothing in the app may put the location dialog up.
    expect(onboarding.locationDisclosed()).toBe(false)
    onboarding.update({ notifications: 'granted', notificationsAskedAt: 'b', locationDisclosedAt: 'b' })
    expect(onboarding.isFinished()).toBe(true)
  })

  it('the ask registers the push token on the path that already exists: there is no second one', () => {
    const hook = source('apps/guard/src/features/enrolment/useOnboarding.ts')
    expect(hook).toMatch(/notificationPermission\(true\)/)
    expect(hook).not.toMatch(/pushToken|registerFor|getExpoPushTokenAsync|expo-notifications/)
  })
})

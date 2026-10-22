/**
 * First-run setup — boards guard-app-29 (your details), -17 (this device and your identity)
 * and -30 (uniform and equipment), and then a FOURTH step the boards do not draw:
 * "Before you start work" (setupPermissions.ts). It asks this phone to let
 * the app alert the guard — a duress or panic alert, somebody at the gate, the control room,
 * an alertness check — and it states what a position is read for BEFORE any screen in this app
 * is allowed to put the operating system's location prompt up. Refusing either is fine and
 * setup finishes anyway; what will not work is said plainly instead of glossed over.
 *
 * In a board-fidelity run there are still three steps and the boards are untouched: the fourth
 * exists only in the live app, which is the only place a permission means anything.
 *
 * WHAT THE SERVER GIVES, AND WHAT IT DOES NOT. Enrolment answers with the guard's name,
 * employee number and site. There is no endpoint for HR details (date of birth, TRN, NIS,
 * salary account, emergency contact), no PSRA number and no issued-kit list. The live
 * screens show the three facts the server does give and say plainly where the rest is
 * kept; the boards' full sample data appears only in the fidelity fixture.
 *
 * IDENTITY (step 2). The phone's own fingerprint/face prompt is run once so the guard
 * learns what clock-in will ask. The app receives a yes or a no — never an image, a
 * template or a score — and keeps only which of the three outcomes setup ended with.
 * A phone with no biometrics is never a dead end: setup continues, and clock-in then goes
 * through the SUPERVISOR OVERRIDE rather than starting an unconfirmed shift silently
 * (the old `method: manual` start is gone). My Profile says which it will be.
 */

import { useCallback, useState } from 'react'
import { Linking, Platform } from 'react-native'
import * as LocalAuthentication from 'expo-local-authentication'
import { deviceTimeIso, fidelityScreenId, isFidelity } from '@gs/core'
import { isExpoGo } from '@gs/core/lib'
import { notificationPermission } from '@gs/core/notify'
import { onboardingFixtures, type DetailRow, type KitItem } from '@/fidelity/enrolment'
// The one rule for what an OS identity prompt may accept, shared with the clock-in.
import { identityPrompt } from '@/features/shift/override'
import { useAppSelector } from '@/store'
import { onboarding, type OnboardingProgress } from './onboardingState'
import { outcomeOf, SKIPPED, stepCopy, type Outcome, type Phase, type StepCopy } from './setupPermissions'

export const MAX_IDENTITY_ATTEMPTS = 3

export type Identity = NonNullable<OnboardingProgress['identity']> | 'idle' | 'failed'

export type OnboardingModel = {
  step: 1 | 2 | 3 | 4
  /** 3 on a board-fidelity run, 4 in the live app. */
  steps: 3 | 4
  firstName: string
  rows: DetailRow[]
  /** True when the server gave fewer details than the board draws — the screen then says where the rest is kept. */
  detailsPartial: boolean
  deviceName: string
  identity: Identity
  identityAttempts: number
  identityBusy: boolean
  confirmIdentity: () => void
  /** null: the server has no issued-kit list. */
  kit: KitItem[] | null
  acknowledged: boolean
  toggleAcknowledged: () => void
  /** Step 4 only: the words on screen and the state of the alerts question. */
  permissions: StepCopy
  /** Step 4's second control — allow/skip before the answer, ask-again or the phone's Settings after. */
  secondaryPress: (() => void) | null
  primaryLabel: string
  primaryBlockedReason: string | null
  busy: boolean
  next: () => void
}

const FIDELITY_STEP: Record<string, 1 | 2 | 3> = { 'guard-app-29': 1, 'guard-app-17': 2, 'guard-app-30': 3 }

export function useOnboarding(routeStep: 1 | 2 | 3 | 4, go: (to: 2 | 3 | 4 | 'done') => void): OnboardingModel {
  const id = isFidelity ? (fidelityScreenId() ?? '') : ''
  const fixture = isFidelity ? (onboardingFixtures[id] ?? null) : null
  const step = fixture ? (FIDELITY_STEP[id] ?? routeStep) : routeStep
  const principal = useAppSelector((s) => s.session.principal)

  const [progress, setProgress] = useState<OnboardingProgress>(() => onboarding.get())
  const [attempts, setAttempts] = useState(0)
  const [identityBusy, setIdentityBusy] = useState(false)
  const [acknowledged, setAcknowledged] = useState(fixture ? fixture.acknowledged : progress.kitAcknowledgedAt !== null)

  const save = useCallback((patch: Partial<OnboardingProgress>) => setProgress(onboarding.update(patch)), [])

  /** Resolves to the outcome, so "Continue" can run the check and carry on in one press. */
  const runIdentity = useCallback(async (): Promise<Identity> => {
    if (fixture) return 'idle'
    setIdentityBusy(true)
    try {
      const available = Platform.OS !== 'web' && (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync())
      if (!available) {
        save({ identity: 'unavailable' })
        return 'unavailable'
      }
      /*
       * THE SAME RULE AS THE CLOCK-IN, AND FOR A HEAVIER REASON (`identityPrompt`).
       * This check is what BINDS this handset to a named guard, so it decides whose phone this is
       * from here on. The phone's passcode is six digits that can be told to a colleague; it is not
       * a thing only this guard has, and it must not be what enrols them. There is no
       * `passcode_identity` to weigh here — no shift, no pre-flight, no token yet — so it is false.
       * A phone with nothing enrolled never reaches this line: it answered `unavailable` above.
       */
      const result = await LocalAuthentication.authenticateAsync(identityPrompt(false, 'Confirm it is you'))
      if (result.success) {
        save({ identity: 'confirmed' })
        return 'confirmed'
      }
      const tried = attempts + 1
      setAttempts(tried)
      if (tried >= MAX_IDENTITY_ATTEMPTS) {
        save({ identity: 'not_confirmed' })
        return 'not_confirmed'
      }
      return 'failed'
    } catch {
      save({ identity: 'unavailable' })
      return 'unavailable'
    } finally {
      setIdentityBusy(false)
    }
  }, [fixture, attempts, save])

  const identity: Identity = fixture ? 'idle' : (progress.identity ?? (attempts > 0 ? 'failed' : 'idle'))

  /*
   * STEP 4. The phone is asked about alerts here and nowhere else in setup; the location prompt is
   * NOT put up here — this step only states what a position is read for, and passing it is what
   * later allows the clock-in screen to ask, in context, at a post.
   *
   * `notificationPermission(true)` is the one door the apps have (`@gs/core/notify`): it puts the
   * system prompt up, and on a yes it nudges the push registration that `usePushRegistration`
   * already runs, so the token reaches the server on the path that exists. Nothing new registers.
   */
  const [phase, setPhase] = useState<Phase>(progress.notificationsAskedAt ? 'answered' : 'ask')
  const [alerts, setAlerts] = useState<Outcome>(progress.notifications ?? 'idle')
  const [permissionBusy, setPermissionBusy] = useState(false)

  const askAlerts = useCallback(async () => {
    if (fixture || permissionBusy) return
    setPermissionBusy(true)
    try {
      const outcome = outcomeOf(await notificationPermission(true).catch(() => null))
      setAlerts(outcome)
      // Recorded whatever the answer: this question is put once per enrolment, never on a launch.
      save({ notifications: outcome, notificationsAskedAt: progress.notificationsAskedAt ?? deviceTimeIso() })
    } finally {
      setPhase('answered')
      setPermissionBusy(false)
    }
  }, [fixture, permissionBusy, save, progress.notificationsAskedAt])

  /** "Skip for now": the phone is never asked, the answer is recorded as the guard's, and the step says so. */
  const skipAlerts = useCallback(() => {
    if (fixture) return
    setAlerts(SKIPPED)
    save({ notifications: SKIPPED, notificationsAskedAt: deviceTimeIso() })
    setPhase('answered')
  }, [fixture, save])

  const permissions = stepCopy(phase, alerts, isExpoGo)

  const next = useCallback(async () => {
    if (fixture) return
    if (step === 1) {
      save({ detailsConfirmedAt: deviceTimeIso() })
      return go(2)
    }
    if (step === 2) {
      const outcome = progress.identity ?? (await runIdentity())
      if (outcome === 'confirmed' || outcome === 'unavailable' || outcome === 'not_confirmed') go(3)
      return
    }
    if (step === 3) {
      if (!acknowledged) return
      save({ kitAcknowledgedAt: deviceTimeIso() })
      return go(4)
    }
    // Step 4's primary. Before an answer it puts the question; after one it ends setup — always, whatever
    // the answer was. Passing this step is what records the location disclosure as read.
    if (phase === 'ask') return await askAlerts()
    const at = deviceTimeIso()
    save({ locationDisclosedAt: at, finishedAt: at })
    go('done')
  }, [fixture, step, save, go, progress.identity, runIdentity, acknowledged, phase, askAlerts])

  const name = principal?.name ?? ''
  const liveRows: DetailRow[] = [
    { label: step === 1 ? 'Full name' : 'Name', value: name || '—' },
    { label: 'Employee number', value: principal?.reference ?? '—' },
    { label: 'Assigned site', value: principal?.siteName ?? '—' },
  ]

  const secondaryPress = (): (() => void) | null => {
    if (step !== 4 || fixture || !permissions.secondary) return null
    const action = permissions.secondary.action
    if (action === 'skip') return skipAlerts
    if (action === 'ask') return () => void askAlerts()
    return () => void Linking.openSettings().catch(() => undefined)
  }

  return {
    step,
    steps: fixture ? 3 : 4,
    firstName: fixture ? fixture.firstName : (name.split(' ')[0] ?? ''),
    rows: fixture ? fixture.rows : liveRows,
    detailsPartial: !fixture,
    deviceName: fixture ? fixture.deviceName : Platform.OS === 'ios' ? 'iPhone' : 'phone',
    identity,
    identityAttempts: attempts,
    identityBusy,
    confirmIdentity: () => void runIdentity(),
    kit: fixture ? fixture.kit : null,
    acknowledged,
    toggleAcknowledged: () => setAcknowledged((v) => !v),
    permissions,
    secondaryPress: secondaryPress(),
    // Step 3 is the board's last step and keeps the board's word there; in the live app step 4 follows it.
    primaryLabel: step === 1 ? 'Looks correct, continue' : step === 2 ? 'Continue' : step === 3 ? (fixture ? 'Finish setup' : 'Continue') : permissions.primaryLabel,
    // Step 4 never blocks: a guard who refuses alerts still finishes setup.
    primaryBlockedReason: step === 3 && !acknowledged ? 'Tick the box to confirm you have received your equipment' : null,
    busy: identityBusy || permissionBusy,
    next: () => void next(),
  }
}

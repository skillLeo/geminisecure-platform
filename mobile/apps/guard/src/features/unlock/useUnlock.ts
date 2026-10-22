/**
 * THE UNLOCK SCREEN'S DECISIONS (M32 F3): a guard whose session ended on a phone that is still theirs signs back in
 * with a fingerprint or face — or, when the phone cannot match one, with a supervisor's PIN. Never a code.
 *
 *   ON OPENING, with signal, the phone asks the server whether it is still this guard's (`check`: signed, no
 *   fingerprint needed). Removed → the enrolment screen, which says when and by whom. Unknown → the enrolment screen's
 *   "not set up" line. Its own key gone → the enrolment screen, binding kept, saying its own code sets it up again
 *   (M46 §1). Suspended → said here. Still bound, no signal, or a key that could not be read just now → the sign-in
 *   below, as it was.
 *   THE FINGERPRINT is asked by the phone (the clock-in's rule: no passcode, except on the app-store reviewer's
 *   demonstration account, which the server flagged at enrolment). Three misses, or a phone with nothing set up, open
 *   the supervisor's PIN — which the server checks, records and keeps a count against, as at clock-in.
 *   THE PIN is held in state while it is typed and dropped on every answer, when the app goes to the background, and
 *   when the screen is left; the request is never tracked in the data layer, so no copy of it stays behind.
 *
 * Signing in is online only, and the screen says so rather than trying.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { AppState, Platform } from 'react-native'
import { router } from 'expo-router'
import * as LocalAuthentication from 'expo-local-authentication'
import { nextRoute } from '@/features/boot/nextRoute'
import { binding, type Binding } from '@/features/enrolment/binding'
import { openSession } from '@/features/enrolment/openSession'
import { MAX_ATTEMPTS, cleanPin, cleanSupervisor, identityPrompt, validateOverride, type Biometrics, type FieldErrors, type OverrideFields } from '@/features/shift/override'
import { useAppDispatch, useAppSelector } from '@/store'
import { OFFLINE, askServer, type ResumeAnswer } from './resume'

export type UnlockModel = {
  /** Whose phone this is, from the binding — the screen greets them by name. */
  who: Binding | null
  /** Why the guard is here, in one sentence. */
  why: string
  /** The server said this guard is not on active duty: nothing on this screen can let them in. */
  notActive: string | null
  biometrics: Biometrics
  /** The reviewer's demonstration account only: the phone's passcode stands in for a fingerprint. */
  passcode: boolean
  attemptsLeft: number
  busy: boolean
  online: boolean
  problem: string | null
  /** The supervisor's PIN route is open: three misses, nothing set up, or the guard chose it. */
  pinOpen: boolean
  fields: OverrideFields
  fieldErrors: FieldErrors
  setSupervisor: (text: string) => void
  setPin: (text: string) => void
  openPin: () => void
  unlock: () => void
  vouch: () => void
  /** Not this guard's phone: enrol it for somebody else, with a code. */
  notMine: () => void
}

const EMPTY: OverrideFields = { supervisor: '', pin: '' }

/** Can this phone check a fingerprint or a face at all? The OS answers yes or no and nothing more. */
async function biometricsOf(): Promise<Biometrics> {
  if (Platform.OS === 'web') return 'none'
  try {
    return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync()) ? 'available' : 'none'
  } catch {
    return 'none'
  }
}

export function whyHere(reason: 'revoked' | 'expired' | 'signed_out' | null): string {
  if (reason === 'expired') return 'You were signed out after 30 minutes with nothing sent from this phone. Nothing you saved on it has been lost.'
  if (reason === 'signed_out') return 'You signed out of this phone. Sign back in to carry on.'
  return 'Sign in to carry on.'
}

export function useUnlock(): UnlockModel {
  const dispatch = useAppDispatch()
  const online = useAppSelector((s) => s.network.online)
  const endedReason = useAppSelector((s) => s.session.endedReason)
  // Held as state so the opening check's answer redraws the screen: it can learn the server's reviewer flag, which a
  // binding made from an earlier version's session never had.
  const [who, setWho] = useState<Binding | null>(() => binding.peek())

  const [biometrics, setBiometrics] = useState<Biometrics>('checking')
  const [misses, setMisses] = useState(0)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [notActive, setNotActive] = useState<string | null>(null)
  const [chosePin, setChosePin] = useState(false)
  const [fields, setFields] = useState<OverrideFields>(EMPTY)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const sending = useRef(false)
  const alive = useRef(true)

  const passcode = who?.reviewer === true

  /** What every answer leads to — the same whichever way the guard asked. */
  const settle = useCallback(
    async (answer: ResumeAnswer): Promise<void> => {
      if (answer.kind === 'resumed') {
        await openSession(dispatch, answer.token, { guard: answer.guard, site: answer.site, reviewer: answer.reviewer })
        router.replace(nextRoute('active') as never)
        return
      }
      if (answer.kind === 'removed') {
        await binding.markRemoved({ message: answer.message, at: answer.at })
        router.replace('/enrol' as never)
        return
      }
      if (answer.kind === 'not_enrolled') {
        await binding.forget()
        router.replace('/enrol' as never)
        return
      }
      if (answer.kind === 'key_lost') {
        // The phone's own key is gone, not its binding: only the server ends that. Its own code sets it up again (M46 §1).
        binding.markKeyLost()
        router.replace('/enrol' as never)
        return
      }
      if (!alive.current) return
      if (answer.kind === 'not_active') return setNotActive(answer.message)
      if (answer.kind === 'refused') {
        setProblem(answer.message)
        setFieldErrors(answer.fields)
        return
      }
      if (answer.kind === 'bound') return
      setProblem(answer.message)
    },
    [dispatch],
  )

  useEffect(() => {
    alive.current = true
    void biometricsOf().then((found) => {
      if (alive.current) setBiometrics(found)
    })
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active') setFields((f) => ({ ...f, pin: '' }))
    })
    return () => {
      alive.current = false
      sub.remove()
      setFields(EMPTY)
    }
  }, [])

  // Is this install still this guard's? Asked once, on opening, with signal — signed, and no fingerprint needed.
  useEffect(() => {
    if (!online) return
    let current = true
    void askServer(dispatch, 'check').then((answer) => {
      if (!current) return
      // Only what changes the screen: a failure to ask is not an answer, and the sign-in below still tries.
      if (answer.kind === 'removed' || answer.kind === 'not_enrolled' || answer.kind === 'key_lost' || answer.kind === 'not_active') void settle(answer)
      if (answer.kind === 'bound' && who) {
        const next: Binding = { ...who, name: answer.guard.name, reference: answer.guard.employee_number, siteName: answer.site.name, reviewer: answer.reviewer }
        void binding.set(next)
        setWho(next)
      }
    })
    return () => {
      current = false
    }
    // Once per opening; `who` is read for its display facts only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online, dispatch, settle])

  const unlock = useCallback(async () => {
    if (busy || sending.current || notActive) return
    if (!online) return setProblem(OFFLINE)
    setProblem(null)
    // Asked again at the tap: a fingerprint may have been removed since the screen opened.
    if (!passcode && (await biometricsOf()) !== 'available') return setBiometrics('none')
    const result = await LocalAuthentication.authenticateAsync(identityPrompt(passcode, 'Confirm it is you to sign back in'))
    if (!result.success) return setMisses((n) => n + 1)

    sending.current = true
    setBusy(true)
    try {
      await settle(await askServer(dispatch, 'biometric'))
    } finally {
      sending.current = false
      if (alive.current) setBusy(false)
    }
  }, [busy, notActive, online, passcode, dispatch, settle])

  const vouch = useCallback(async () => {
    if (busy || sending.current || notActive) return
    if (!online) return setProblem(OFFLINE)
    const errors = validateOverride(fields)
    setFieldErrors(errors)
    setProblem(null)
    if (Object.keys(errors).length > 0) return

    sending.current = true
    setBusy(true)
    try {
      await settle(await askServer(dispatch, 'supervisor_pin', { supervisor: cleanSupervisor(fields.supervisor), pin: fields.pin }))
    } finally {
      // Whatever the answer was, the PIN is gone from this phone before anything else happens.
      if (alive.current) setFields((f) => ({ supervisor: f.supervisor, pin: '' }))
      sending.current = false
      if (alive.current) setBusy(false)
    }
  }, [busy, notActive, online, fields, dispatch, settle])

  const pinOpen = !passcode && (chosePin || misses >= MAX_ATTEMPTS || biometrics === 'none')

  return {
    who,
    why: whyHere(endedReason),
    notActive,
    biometrics,
    passcode,
    attemptsLeft: Math.max(0, MAX_ATTEMPTS - misses),
    busy,
    online,
    problem,
    pinOpen,
    fields,
    fieldErrors,
    setSupervisor: (text) => setFields((f) => ({ ...f, supervisor: text })),
    setPin: (text) => setFields((f) => ({ ...f, pin: cleanPin(text) })),
    openPin: () => setChosePin(true),
    unlock: () => void unlock(),
    vouch: () => void vouch(),
    notMine: () => router.push('/enrol' as never),
  }
}

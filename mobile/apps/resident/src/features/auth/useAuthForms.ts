/**
 * What Log in, Create account and "Trouble signing in?" hold and do (M47 §1–§2, M50 §7), so the three rebuilt screens
 * only draw. The fidelity build's board data is swapped in here and nowhere in the screens (one path, M42 Stage 0 §4.1).
 *
 * NO SERVER WORK: signing in is still an email address, the estate, and a six-digit code sent by email — the paused
 * password branch is not needed for any of this.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import type { TextInput } from 'react-native'
import { router, useLocalSearchParams } from 'expo-router'
import { isFidelity, openLegal, suggestEmail } from '@gs/core'
import { legalAddress, type LegalPage } from '@gs/core/lib'
import { logInFixture, signUpFixture } from '@/fidelity/auth'
import { goAfterSignIn } from './afterSignIn'
import { fingerprintFor, type FingerprintFor } from './fingerprint'
import { normaliseEstate, signInDraft, useFingerprintSignIn, useRequestCode, validateDetails, type FieldErrors } from './useSignIn'
import { useEstateChoice } from './useEstateChoice'
import { useAppSelector } from '@/store'

/** The board's own samples, as grey placeholders (the user, 7 October: the design's text stays on the screen). */
export const LOG_IN_EMAIL_PLACEHOLDER = logInFixture.email
export const FULL_NAME_PLACEHOLDER = signUpFixture.fullName
export const EMAIL_PLACEHOLDER = 'you@email.com'

/**
 * "Log in with biometrics" (the board's own button) WORKS once "Face ID / fingerprint" is on for this phone (M49, M50 §3):
 * the phone's prompt opens the key it keeps, and the key signs the phone in — no email, no code (fingerprint.ts). Where it
 * is not set up the button is drawn disabled, with no excuse under it (§3.2c); this sentence is only what a screen reader
 * says about it.
 */
export const BIOMETRICS_NOTE = 'Log in with your email first, then turn on Face ID / fingerprint in Settings → Security.'

const goBack = (fallback: string) => () => (router.canGoBack() ? router.back() : router.replace(fallback as never))

export function useLogInForm() {
  const { request, busy, rememberedEstate } = useRequestCode()
  /*
   * WHERE THE ADDRESS COMES FROM (M18G §5). The invite email's "Open the Resident App" button opens gs://log-in with the
   * estate and the invited address, so the resident types neither; "Not your email? Change it" on the code screen comes
   * back here with what was typed still in the draft. Otherwise, empty — and the estate this phone last used.
   */
  const params = useLocalSearchParams<{ email?: string; estate?: string }>()
  const draft = signInDraft.get()
  const linkEmail = typeof params.email === 'string' ? params.email.trim() : ''
  const linkEstate = typeof params.estate === 'string' ? normaliseEstate(params.estate) : ''
  const [email, setEmailRaw] = useState<string>(isFidelity ? logInFixture.email : linkEmail || draft.email || '')
  const [estate, setEstate] = useState(linkEstate || draft.estate || rememberedEstate?.id || '')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<string | null>(null)

  /*
   * FINGERPRINT SIGN-IN (M50 §3.2). `undefined` until the phone has said whether a key is kept here; then who it is for,
   * or null. With one, the phone's prompt is asked AT ONCE, the first time the screen opens (§3.2a) — and again by the
   * button. A cancel leaves the email and code exactly as they were (§3.2d); a key that no longer works is forgotten and
   * said, and the button turns disabled.
   */
  const fp = useFingerprintSignIn()
  const [fingerprint, setFingerprint] = useState<FingerprintFor | null | undefined>(isFidelity ? null : undefined)
  const prompted = useRef(false)
  useEffect(() => {
    if (isFidelity) return
    let alive = true
    void fingerprintFor().then((held) => { if (alive) setFingerprint(held) })
    return () => { alive = false }
  }, [])
  const byFingerprint = useCallback(async () => {
    if (!fingerprint || fp.busy) return
    setProblem(null)
    const outcome = await fp.run()
    if (outcome.ok) return goAfterSignIn(outcome.next)
    if (outcome.message) {
      setProblem(outcome.message)
      setFingerprint(await fingerprintFor())
    }
  }, [fingerprint, fp])
  useEffect(() => {
    if (fingerprint && !prompted.current) {
      prompted.current = true
      void byFingerprint()
    }
  }, [fingerprint, byFingerprint])

  const pickEstate = useCallback((next: string) => {
    setEstate(next)
    setErrors((e) => ({ ...e, estate: undefined }))
  }, [])
  const estates = useEstateChoice({ value: estate, onChange: pickEstate, remembered: rememberedEstate })
  const estateName = isFidelity ? logInFixture.estateName : estates.name

  const setEmail = (text: string) => {
    setEmailRaw(text)
    setErrors((e) => ({ ...e, email: undefined }))
  }
  // M18G §3: "andrea@gmail.comm" → "Did you mean andrea@gmail.com?" — offered, never forced.
  const meant = isFidelity ? null : suggestEmail(email)

  const submit = async () => {
    if (isFidelity || busy) return
    const found = validateDetails({ estate, email })
    setErrors(found)
    setProblem(null)
    if (Object.keys(found).length > 0) return
    // A sign-in: the address is looked up first, and one the register does not hold is said beside the field (M80 §2.1).
    const answer = await request({ estate, email, intent: 'sign_in' })
    if (answer.ok) return router.push('/(auth)/code' as never)
    if (answer.field) setErrors({ [answer.field]: answer.message })
    else setProblem(answer.message)
  }

  return {
    subtitle: `Log in to access ${estateName ?? 'your community'}`,
    email,
    setEmail,
    // The board draws the email box focused, with its gold ring; a phone draws it so once it is tapped.
    emailLit: isFidelity,
    meant,
    estateName,
    estates,
    errors,
    problem,
    busy,
    // The button works exactly when a key is kept here; otherwise it is disabled, and only a screen reader hears why.
    // The design view draws it as the board does — a phone where it is set up (it signs nobody in there).
    biometricsReady: isFidelity || !!fingerprint,
    biometricsBusy: fp.busy,
    biometricsHint: fingerprint ? undefined : BIOMETRICS_NOTE,
    submit: () => void submit(),
    biometrics: () => void byFingerprint(),
    trouble: () => router.push('/(auth)/forgot' as never),
    signUp: () => router.replace('/(auth)/sign-up' as never),
    // The board's Log In follows Sign Up, so its back chevron returns there when nothing came before it.
    back: goBack('/(auth)/sign-up'),
  }
}

/**
 * "Trouble signing in?" — board 04's place (Forgot Password; M50 §7). There is no password to forget: the answer is a
 * fresh six-digit code, sent to the email address for the estate chosen, exactly as Log in sends one. The board's state
 * is its email box empty and lit.
 */
export function useTroubleForm() {
  const { request, busy, rememberedEstate } = useRequestCode()
  const online = useAppSelector((s) => s.network.online)
  const draft = signInDraft.get()
  const [email, setEmailRaw] = useState<string>(isFidelity ? '' : draft.email)
  const [estate, setEstate] = useState(isFidelity ? '' : draft.estate || (rememberedEstate?.id ?? ''))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<string | null>(null)

  const pickEstate = useCallback((next: string) => {
    setEstate(next)
    setErrors((e) => ({ ...e, estate: undefined }))
  }, [])
  const estates = useEstateChoice({ value: estate, onChange: pickEstate, remembered: rememberedEstate })

  const submit = async () => {
    if (isFidelity || busy) return
    const found = validateDetails({ estate, email })
    setErrors(found)
    setProblem(null)
    if (Object.keys(found).length > 0) return
    // A sign-in, as Log in's: it ends where logging in ends, on the code screen, or beside the field (M80 §2.1).
    const answer = await request({ estate, email, intent: 'sign_in' })
    if (answer.ok) return router.push('/(auth)/code' as never)
    if (answer.field) setErrors({ [answer.field]: answer.message })
    else setProblem(answer.message)
  }

  return {
    email,
    setEmail: (text: string) => {
      setEmailRaw(text)
      setErrors((e) => ({ ...e, email: undefined }))
    },
    // The board draws the email box lit and empty; a phone draws it so once it is tapped.
    emailLit: isFidelity,
    meant: isFidelity ? null : suggestEmail(email),
    // What a phone settles on — its estate chosen without asking — as Log in's board picture shows it.
    estateName: isFidelity ? logInFixture.estateName : estates.name,
    estates,
    errors,
    problem,
    // Said only when the phone knows it has no connection.
    offline: online === false && !isFidelity,
    busy,
    submit: () => void submit(),
    logIn: () => router.replace('/(auth)/log-in' as never),
    back: goBack('/(auth)/log-in'),
  }
}

export function useSignUpForm() {
  const { request, busy, rememberedEstate } = useRequestCode()
  const draft = signInDraft.get()
  const [estate, setEstate] = useState(isFidelity ? '' : draft.estate || (rememberedEstate?.id ?? ''))
  const [fullName, setFullNameRaw] = useState<string>(isFidelity ? signUpFixture.fullName : draft.fullName)
  const [email, setEmailRaw] = useState<string>(isFidelity ? signUpFixture.email : draft.email)
  const [agreed, setAgreed] = useState<boolean>(isFidelity ? signUpFixture.agreed : false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [problem, setProblem] = useState<string | null>(null)
  const nameInput = useRef<TextInput>(null)
  const emailInput = useRef<TextInput>(null)

  const clear = (field: keyof FieldErrors) => setErrors((e) => ({ ...e, [field]: undefined }))
  const pickEstate = useCallback((next: string) => {
    setEstate(next)
    setErrors((e) => ({ ...e, estate: undefined }))
  }, [])
  const estates = useEstateChoice({ value: estate, onChange: pickEstate, remembered: rememberedEstate })

  // A document that will not open says where it is, so the person can still read it before agreeing.
  const open = (page: LegalPage) => {
    setProblem(null)
    void openLegal(page).then((opened) => (opened ? undefined : setProblem(`This phone could not open it. In any browser, go to ${legalAddress(page)}`)))
  }

  const submit = async () => {
    if (isFidelity || busy) return
    const found = validateDetails({ estate, email, fullName, needName: true, agreed, needTerms: true })
    setErrors(found)
    setProblem(null)
    if (Object.keys(found).length > 0) return
    // Create account starts from an address nobody holds yet: never looked up, answered alike either way.
    const answer = await request({ estate, email, fullName, intent: 'sign_up' })
    if (answer.ok) return router.push('/(auth)/code' as never)
    if (answer.field) setErrors({ [answer.field]: answer.message })
    else setProblem(answer.message)
  }

  return {
    estates,
    // What a phone settles on — its estate chosen without asking — as Log in's board picture shows it.
    estateName: isFidelity ? logInFixture.estateName : estates.name,
    fullName,
    setFullName: (text: string) => {
      setFullNameRaw(text)
      clear('fullName')
    },
    email,
    setEmail: (text: string) => {
      setEmailRaw(text)
      clear('email')
    },
    // The board draws the email box focused and empty; a phone draws it so once it is tapped.
    emailLit: isFidelity,
    meant: isFidelity ? null : suggestEmail(email),
    agreed,
    toggleAgreed: () => {
      if (isFidelity) return
      setAgreed((v) => !v)
      clear('terms')
    },
    terms: () => open('terms'),
    privacy: () => open('privacy'),
    errors,
    problem,
    busy,
    nameInput,
    emailInput,
    submit: () => void submit(),
    logIn: () => router.replace('/(auth)/log-in' as never),
    back: goBack('/(auth)/log-in'),
  }
}

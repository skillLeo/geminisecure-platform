/**
 * Signing in to the Resident App — what the server actually does, behind what the boards draw.
 *
 * THE BOARDS draw an email and a PASSWORD. THE SERVER has no passwords at all: a resident proves
 * they own an email address with a six-digit one-time code, every time (DOC_NOTES §3d). So:
 *
 *   Sign Up / Log In   community code + email  →  POST /auth/otp/request
 *   Code               six digits              →  POST /auth/otp/verify  →  token (secure store only)
 *   then               `next` from the server: claim a unit · await the estate's approval · home
 *
 * A SIGN-IN LOOKS THE EMAIL UP FIRST (M80 §2.1, EST-B-03b): Log in and "Trouble signing in?" ask
 * with `intent: sign_in`, and an address the register does not hold is answered `email_not_found`,
 * said beside the email field, with no code screen. Create account asks with `sign_up` and is
 * answered the same whether or not an account exists. The install id rides with a sign-in only, to
 * count its attempts: a claim the phone makes, never an identity. Text-message codes are not
 * available on this platform (503): email only.
 *
 * Everything here is ONLINE ONLY by the server's contract. Nothing is queued, nothing is faked.
 * What the resident typed travels between the screens in memory — never in a URL.
 */

import { useCallback, useEffect, useState } from 'react'
import { Platform } from 'react-native'
import { now } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
// The id alone — never the `@gs/core/crypto` index, which brings the Guard App's pass verifier and tweetnacl.
import { deviceUid } from '@gs/core/crypto/deviceUid'
import { settingsPrefs } from '@/features/settings/prefs'
import { useAppDispatch } from '@/store'
import { fingerprintFor, forgetKey, handsetId, phoneName, readKey } from './fingerprint'
import { deletedAccount, deletedAccountOf, restoreProblem, restoredNotice } from './restore'
import { lastEstate, openSession } from './session'

/**
 * What every sign-in says about the phone: this install, its platform, what it calls itself ("Galaxy S25 Ultra", else its
 * model) and — new (M50 §3.2f) — the HANDSET's own id, so the server ends this phone's earlier sign-ins instead of keeping
 * a new one beside each (a reinstall used to make the same phone a stranger: four sessions for one S25).
 */
async function phoneFields(): Promise<{ device_uid: string; platform: 'ios' | 'android'; device_name?: string; hardware_id?: string }> {
  const name = phoneName()
  const hardware = await handsetId()
  return { device_uid: await deviceUid(), platform: Platform.OS === 'ios' ? 'ios' : 'android', ...(name ? { device_name: name } : {}), ...(hardware ? { hardware_id: hardware } : {}) }
}

/**
 * After ANY sign-in: the fingerprint key on this phone and the server agree (M50 §3). A key that is another account's, or
 * that the server says is no longer live, is forgotten here; one that is, keeps "Face ID / fingerprint" on — and the app
 * lock with it, which the sign-out had wiped with the app's storage.
 */
async function settleFingerprint(answer: { account: { id: number | string }; fingerprint_sign_in?: boolean }): Promise<void> {
  const held = await fingerprintFor()
  const live = !!held && held.accountId === Number(answer.account.id) && answer.fingerprint_sign_in === true
  if (held && !live) await forgetKey()
  settingsPrefs.set({ appLock: live })
}

/** What a code is asked for: Log in and "Trouble signing in?" sign in; Create account signs up. */
export type SignInIntent = 'sign_in' | 'sign_up'

export type SignInDraft = { estate: string; email: string; fullName: string; intent: SignInIntent | null; hint: string | null; expiresAt: string | null; resendAfter: string | null }

let draft: SignInDraft = { estate: '', email: '', fullName: '', intent: null, hint: null, expiresAt: null, resendAfter: null }
export const signInDraft = {
  get: (): SignInDraft => draft,
  set: (patch: Partial<SignInDraft>) => (draft = { ...draft, ...patch }),
  clear: () => (draft = { estate: '', email: '', fullName: '', intent: null, hint: null, expiresAt: null, resendAfter: null }),
}

export const CODE_LENGTH = 6
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

/** The community code is the estate's id as the committee gives it: lower case, no spaces. */
export const normaliseEstate = (text: string): string => text.trim().toLowerCase().replace(/[^a-z0-9-]/g, '')

export type FieldErrors = { estate?: string; email?: string; fullName?: string; terms?: string }

export function validateDetails(input: { estate: string; email: string; fullName?: string; needName?: boolean; agreed?: boolean; needTerms?: boolean }): FieldErrors {
  const errors: FieldErrors = {}
  // Chosen from the list: there is no code to type any more (M47 §1.2).
  if (normaliseEstate(input.estate).length < 3) errors.estate = 'Choose your estate from the list.'
  if (/^[+\d][\d\s()-]{6,}$/.test(input.email.trim())) errors.email = 'Sign-in codes are sent by email on this platform: enter your email address.'
  else if (!EMAIL.test(input.email.trim())) errors.email = 'Enter a valid email address, like you@email.com.'
  if (input.needName && (input.fullName ?? '').trim().length < 2) errors.fullName = 'Enter your full name, as the estate knows you.'
  if (input.needTerms && !input.agreed) errors.terms = 'Please agree to the terms to continue.'
  return errors
}

const sentence = (e: ApiError): string =>
  e.status === 'network' || e.status === 'timeout' ? "You're offline, or the server can't be reached. Signing in needs a connection: check it and try again." : e.message

/* ---- step 1: ask for a code ---------------------------------------------------------------- */

export function useRequestCode() {
  const [requestCode] = residentApi.useAuthOtpRequestMutation()
  const [busy, setBusy] = useState(false)

  const request = useCallback(
    async (input: { estate: string; email: string; fullName?: string; intent?: SignInIntent }): Promise<{ ok: true } | { ok: false; field: 'estate' | 'email' | null; message: string }> => {
      const estate = normaliseEstate(input.estate)
      const email = input.email.trim().toLowerCase()
      const intent = input.intent
      setBusy(true)
      try {
        // A call that names no intent sends exactly what it always did; a sign-in adds the install id, to count attempts.
        const answer = await requestCode({
          estate,
          channel: 'email',
          destination: email,
          ...(intent ? { intent } : {}),
          ...(intent === 'sign_in' ? { device_uid: await deviceUid() } : {}),
        })
        if ('error' in answer && answer.error) {
          const e = answer.error as ApiError
          if (e.code === 'estate_not_found') return { ok: false, field: 'estate', message: 'That estate is not taking sign-ins in the app. Choose yours from the list, or ask your estate office.' }
          // M18G: the address's domain takes no mail at all (gmail.comm) — said beside the field, and no code "sent".
          if (e.code === 'email_domain_unreachable') return { ok: false, field: 'email', message: e.message }
          // M80 §2.1: the register does not hold this address — said beside the field, and the code screen is not reached.
          if (e.code === 'email_not_found') return { ok: false, field: 'email', message: e.message }
          if (e.status === 422 && e.fieldErrors.destination?.[0]) return { ok: false, field: 'email', message: e.fieldErrors.destination[0] }
          return { ok: false, field: null, message: e.status === 429 ? `Too many tries. ${e.retryAfter ? `Wait ${e.retryAfter} seconds` : 'Wait a minute'} and try again.` : sentence(e) }
        }
        // The intent stays with the draft, so the code screen's resend asks the same way the first request did.
        signInDraft.set({ estate, email, fullName: input.fullName?.trim() ?? draft.fullName, intent: intent ?? draft.intent, hint: answer.data?.destination_hint ?? null, expiresAt: answer.data?.expires_at ?? null, resendAfter: answer.data?.resend_after ?? null })
        return { ok: true }
      } finally {
        setBusy(false)
      }
    },
    [requestCode],
  )

  return { request, busy, rememberedEstate: lastEstate.get() }
}

/* ---- step 2: prove the code ---------------------------------------------------------------- */

/** `deleted`: the account at this address was deleted and can still come back — the restore screen has what it needs (M18H). */
export type VerifyOutcome = { ok: true; next: 'claim_unit' | 'await_approval' | 'home' } | { ok: false; message: string; locked: boolean; expired: boolean; deleted?: boolean }

export function useVerifyCode() {
  const dispatch = useAppDispatch()
  const [verify] = residentApi.useAuthOtpVerifyMutation()
  const [busy, setBusy] = useState(false)

  const submit = useCallback(
    async (code: string): Promise<VerifyOutcome> => {
      const d = signInDraft.get()
      setBusy(true)
      try {
        // The phone's name and its handset id ride with the code (phoneFields): one sign-in per phone, named as a person reads it.
        const answer = await verify({ estate: d.estate, channel: 'email', destination: d.email, code, ...(await phoneFields()) })
        if ('error' in answer && answer.error) {
          const e = answer.error as ApiError
          // M18H: proven by the code, and deleted — no token until the person chooses to restore it.
          const deleted = deletedAccountOf(e)
          deletedAccount.set(deleted)
          if (deleted) return { ok: false, message: e.message, locked: false, expired: false, deleted: true }
          return {
            ok: false,
            // `otp_invalid` says how many tries are left — the server's sentence is shown as it is.
            message: e.code === 'account_suspended' ? e.message : sentence(e),
            locked: e.code === 'otp_locked',
            expired: e.code === 'otp_expired',
          }
        }
        if (!answer.data) return { ok: false, message: 'The server gave an answer this version of the app does not understand.', locked: false, expired: false }
        const next = await openSession(answer.data, dispatch)
        await settleFingerprint(answer.data)
        return { ok: true, next }
      } finally {
        setBusy(false)
      }
    },
    [verify, dispatch],
  )

  return { submit, busy }
}

/* ---- step 2b: "Restore my account" (M18H §2.3) --------------------------------------------- */

export type RestoreOutcome = { ok: true; next: 'claim_unit' | 'await_approval' | 'home' } | { ok: false; message: string; officeOnly: boolean }

export function useRestoreAccount() {
  const dispatch = useAppDispatch()
  const [restore] = residentApi.useAuthRestoreMutation()
  const [busy, setBusy] = useState(false)

  const submit = useCallback(async (): Promise<RestoreOutcome> => {
    const d = signInDraft.get()
    const held = deletedAccount.get()
    if (!held?.ticket) return { ok: false, message: 'Sign in again with your email address and the app will offer to restore the account.', officeOnly: false }
    setBusy(true)
    try {
      // The SAME install id the sign-in sent: the ticket works on the phone it was issued to and no other.
      const answer = await restore({ estate: d.estate, ticket: held.ticket, ...(await phoneFields()) })
      if ('error' in answer && answer.error) {
        const e = answer.error as ApiError
        // The person's own window closed while the screen was open: the office-only version, with the office's contact.
        const officeOnly = deletedAccountOf(e)
        if (officeOnly) deletedAccount.set(officeOnly)
        return { ok: false, message: restoreProblem(e), officeOnly: officeOnly !== null }
      }
      if (!answer.data) return { ok: false, message: 'The server gave an answer this version of the app does not understand.', officeOnly: false }
      deletedAccount.clear()
      if (answer.data.restored) restoredNotice.set()
      const next = await openSession(answer.data, dispatch)
      await settleFingerprint(answer.data)
      return { ok: true, next }
    } finally {
      setBusy(false)
    }
  }, [restore, dispatch])

  return { submit, busy }
}

/* ---- "Log in with biometrics" (M49, M50 §3) ------------------------------------------------------------------------ */

/**
 * `ok: false` with a null message is a quiet "no": the person cancelled the phone's prompt, and the email and code are
 * there, as they were. A message is something to say — the key no longer works here, or there is no connection.
 */
export type FingerprintOutcome = { ok: true; next: 'claim_unit' | 'await_approval' | 'home' } | { ok: false; message: string | null }

const KEY_GONE = 'Fingerprint sign-in is not set up on this phone any more. Log in with your email address, then turn it on again in Settings → Security.'

export function useFingerprintSignIn() {
  const dispatch = useAppDispatch()
  const [signIn] = residentApi.useAuthDeviceKeyMutation()
  const [busy, setBusy] = useState(false)

  const run = useCallback(async (): Promise<FingerprintOutcome> => {
    const held = await fingerprintFor()
    if (!held) return { ok: false, message: null }
    setBusy(true)
    try {
      // The phone's own prompt opens its secure store: the app sees a key, never a fingerprint.
      const read = await readKey()
      if ('why' in read) {
        // New fingerprints on the phone, or its screen lock removed, make the key unreadable for good: forgotten, and said.
        if (read.why === 'unreadable') {
          await forgetKey()
          settingsPrefs.set({ appLock: false })
          return { ok: false, message: KEY_GONE }
        }
        return { ok: false, message: null }
      }
      const answer = await signIn({ estate: held.estate, key: read.key, ...(await phoneFields()) })
      if ('error' in answer && answer.error) {
        const e = answer.error as ApiError
        // The server no longer takes the key (turned off elsewhere, signed out from another phone, the account closed):
        // forgotten here too, so the button does not offer it again.
        if (e.code === 'device_key_invalid' || e.code === 'account_revoked' || e.code === 'account_suspended') {
          await forgetKey()
          settingsPrefs.set({ appLock: false })
          return { ok: false, message: e.code === 'device_key_invalid' ? KEY_GONE : e.message }
        }
        return { ok: false, message: sentence(e) }
      }
      if (!answer.data) return { ok: false, message: 'The server gave an answer this version of the app does not understand.' }
      const next = await openSession(answer.data, dispatch)
      await settleFingerprint(answer.data)
      return { ok: true, next }
    } finally {
      setBusy(false)
    }
  }, [signIn, dispatch])

  return { run, busy }
}

/** Seconds until the server will send a NEW code (before that it re-uses the one already sent). */
export function useResendCountdown(resendAfter: string | null): number {
  const left = () => (resendAfter ? Math.max(0, Math.ceil((new Date(resendAfter).getTime() - now().getTime()) / 1000)) : 0)
  const [seconds, setSeconds] = useState(left)
  useEffect(() => {
    setSeconds(left())
    const timer = setInterval(() => setSeconds(left()), 1000)
    return () => clearInterval(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resendAfter])
  return seconds
}

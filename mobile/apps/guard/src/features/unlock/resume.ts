/**
 * SIGNING BACK IN WITHOUT A CODE (M32 F3). The enrolment code bound this phone once; after that, a guard who has been
 * signed out — the 30-minute rule, a 401, a sign-out they chose — gets back in on this phone alone:
 *
 *   1. POST /devices/challenge   a one-time challenge for this install (two minutes, one use)
 *   2. the phone signs it with its own key, the one enrolment bound. The secret half never leaves the secure store;
 *      the signature is what proves to the server that this is the install it knows.
 *   3. POST /devices/resume      `check`           is this install still bound? (no token — what the screens say)
 *                                `biometric`       the phone has just matched the guard's fingerprint or face
 *                                `supervisor_pin`  the server checks a supervisor's PIN instead (the clock-in's rule)
 *
 * Every answer comes back as one of a handful of plain cases, and each screen says its own words for it. Nothing here
 * is queued: a sign-in is now or not at all.
 *
 * ONLY THE SERVER SAYS A PHONE IS NOT SET UP (M46 §1). A key that could not be read just now is tried again, and a
 * key that is gone is said as that — the phone's own spent code sets it up again on this handset. Neither used to be
 * told apart from "never enrolled", which sent a bound phone to the enrolment screen to be refused its own code.
 * Every sign-back-in carries the handset's identity (`handset.ts`), so a phone bound before it was recorded has it now.
 */

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit'
import type { ApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { deviceUid, hasDeviceKey, resumeMessage, signWithDeviceKey } from '@gs/core/crypto'
import { handsetId } from '@/features/enrolment/handset'
import type { BoundTo } from '@/features/enrolment/openSession'

export type Method = 'check' | 'biometric' | 'supervisor_pin'

export type ResumeAnswer =
  /** A new token: the guard is back in. */
  | { kind: 'resumed'; token: string; reviewer: boolean } & BoundTo
  /** `check` only: this install is still this guard's — with the server's reviewer flag, as a sign-in carries it. */
  | { kind: 'bound'; reviewer: boolean } & BoundTo
  /** The server took this install out of service; its own sentence names when. */
  | { kind: 'removed'; message: string; at: string | null }
  /** The server knows no binding for this install. */
  | { kind: 'not_enrolled'; message: string }
  /** This phone holds no key to prove itself with (its data was cleared). The server may still know it: its own spent code sets it up again here. */
  | { kind: 'key_lost'; message: string }
  /** The guard is suspended or no longer employed. */
  | { kind: 'not_active'; message: string }
  /** A supervisor's address and PIN were not accepted. */
  | { kind: 'refused'; message: string; fields: { supervisor?: string; pin?: string } }
  | { kind: 'offline'; message: string }
  | { kind: 'failed'; message: string }

/**
 * §6.3's first case: a new phone, or one whose data was cleared. "Ask the office" was no route back at 2am with the
 * office shut (M46 §1.3): a phone set up before takes its own code again.
 */
export const NOT_SET_UP = 'This phone is not set up for GeminiSecure yet. If it was set up before, type the code you used then: it works again on the same phone. A new phone needs a new code from your supervisor.'

/** A phone that is still somebody's, whose own key is gone: the way back is the code it was set up with (M46 §1). */
export const KEY_LOST = 'This phone lost the key it was set up with: the app was reinstalled, or its storage was cleared. Type the code you set it up with: on the same phone it works again.'

/** A read that failed is not an answer: nothing is forgotten, and the next try may well read it. */
export const UNREADABLE = 'This phone could not read its own sign-in key just now. Try again in a moment: nothing has been lost.'

export const OFFLINE = "Signing back in needs a connection: the office's server checks that this is your phone. Move to where there is signal and try again."

type Dispatch = ThunkDispatch<unknown, unknown, UnknownAction>

export async function askServer(dispatch: Dispatch, method: Method, vouch?: { supervisor: string; pin: string }): Promise<ResumeAnswer> {
  let hasKey: boolean
  let uid: string
  try {
    hasKey = await hasDeviceKey()
    uid = hasKey ? await deviceUid() : ''
  } catch {
    return { kind: 'failed', message: UNREADABLE }
  }
  // No key at all: never enrolled, or its data was cleared. There is nothing to prove, so nothing is asked.
  if (!hasKey) return { kind: 'key_lost', message: KEY_LOST }

  const asked = await dispatch(guardApi.endpoints.devicesChallenge.initiate({ device_uid: uid }, { track: false }))
  if ('error' in asked && asked.error) return answerOf(asked.error as ApiError)
  const challenge = asked.data?.challenge
  if (!challenge) return { kind: 'failed', message: UNKNOWN }

  let signature: string | null
  try {
    signature = await signWithDeviceKey(resumeMessage(uid, challenge))
  } catch {
    return { kind: 'failed', message: UNREADABLE }
  }
  if (signature === null) return { kind: 'key_lost', message: KEY_LOST }

  const hardware = handsetId()
  const answer = await dispatch(
    guardApi.endpoints.devicesResume.initiate(
      { device_uid: uid, challenge, signature, method, ...(vouch ? { supervisor: vouch.supervisor, pin: vouch.pin } : null), ...(hardware ? { hardware_id: hardware } : null) },
      { track: false },
    ),
  )
  if ('error' in answer && answer.error) return answerOf(answer.error as ApiError)

  const data = answer.data
  if (!data) return { kind: 'failed', message: UNKNOWN }
  const bound = { guard: data.guard, site: data.site }
  if (data.state === 'resumed' && data.token) return { kind: 'resumed', token: data.token, reviewer: data.reviewer === true, ...bound }
  if (data.state === 'bound') return { kind: 'bound', reviewer: data.reviewer === true, ...bound }
  return { kind: 'failed', message: UNKNOWN }
}

const UNKNOWN = 'The server gave an answer this version of the app does not understand. Ask the office for help.'

/** A refusal, in the case it is. The server's sentence is shown as it wrote it wherever it has one to say. */
export function answerOf(e: ApiError): ResumeAnswer {
  if (e.status === 'network' || e.status === 'timeout') return { kind: 'offline', message: OFFLINE }
  if (e.status === 403 && e.code === 'device_removed') return { kind: 'removed', message: e.message, at: typeof e.details?.removed_at === 'string' ? e.details.removed_at : null }
  if (e.status === 403 && e.code === 'device_not_enrolled') return { kind: 'not_enrolled', message: NOT_SET_UP }
  if (e.status === 403 && e.code === 'guard_not_active') return { kind: 'not_active', message: e.message }
  // The clock-in override's own refusal, one sentence for every kind of "no" (403, as there).
  if ((e.status === 403 || e.status === 422) && e.code === 'override_refused') return { kind: 'refused', message: e.message, fields: {} }
  if (e.status === 422 && e.code === 'validation_failed') {
    return { kind: 'refused', message: 'Check what was entered and try again.', fields: { supervisor: e.fieldErrors.supervisor?.[0], pin: e.fieldErrors.pin?.[0] } }
  }
  if (e.status === 422 && e.code === 'challenge_expired') return { kind: 'failed', message: 'That took too long to reach the server. Try again.' }
  if (e.status === 429) return { kind: 'failed', message: 'Too many tries from this phone. Wait a minute, then try again.' }
  if (typeof e.status === 'number' && e.status >= 500) return { kind: 'failed', message: "Something went wrong at the office's end. Try again in a moment, or call the control room." }
  return { kind: 'failed', message: e.message }
}

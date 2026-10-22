/**
 * The supervisor PIN override at clock-in — the pure rules.
 *
 *   "A guard is never stranded, and an unverified clock-in is never silently accepted."
 *
 * WHEN the override is the way forward, and nothing else is:
 *   - the phone has no fingerprint or face set up  → the ordinary start is BLOCKED (it used to go through
 *     silently as `method: manual`); the supervisor route is the only one offered
 *   - three identity checks in a row did not match  → the same route (board guard-app-39)
 *   - WHERE, since M12 §1.3: outside the rostered post's geofence (`outside_geofence`), or the position could not
 *     be established — refused, switched off, too inaccurate, no fix (`position_unconfirmed`). The supervisor
 *     vouches that the guard is on post; the server records the fix, flags the shift and tells the Head of Security.
 *
 * WHAT the phone does with the PIN: holds it in memory while it is being typed, sends it once, and lets go
 * of it — on every answer, when the screen is left, when the app goes to the background. It is never
 * stored, never queued, never logged, and the server's refusal never says how close a guess was.
 */

import type { ApiError } from '@gs/core/api'
import type { Geo } from './useGeofence'

export const MAX_ATTEMPTS = 3
export const PIN_LENGTH = 6

export type OverrideReason = 'biometric_failed' | 'no_biometrics' | 'outside_geofence' | 'position_unconfirmed'

export const LOCATION_REASONS: readonly OverrideReason[] = ['outside_geofence', 'position_unconfirmed']

/**
 * Whether WHERE the phone is closes the ordinary start, and what a supervisor's PIN would be recorded as. No signal
 * with no fence saved is not a reason: the clock-in is captured and the server judges it on arrival.
 */
export function locationReasonOf(geo: Geo): 'outside_geofence' | 'position_unconfirmed' | null {
  if (geo.state === 'outside') return 'outside_geofence'
  if (geo.state === 'poor_fix') return 'position_unconfirmed'
  if (geo.state === 'unavailable' && geo.why !== 'offline') return 'position_unconfirmed'
  return null
}

/** What the phone can do about identity: still finding out, able to check, or not able to at all. */
export type Biometrics = 'checking' | 'available' | 'none'

export type IdentityStep =
  /** Still asking the phone what it has — nothing is offered yet. */
  | { step: 'wait' }
  /** The ordinary path: the OS prompt, then the clock-in. */
  | { step: 'confirm'; attemptsLeft: number }
  /** The ordinary path is closed; a supervisor's PIN is the way to start the shift. */
  | { step: 'override'; reason: OverrideReason }

/**
 * `passcodeIdentity` is the SERVER's word, and only ever for an app-store reviewer's
 * demonstration account (`shifts.preflight.passcode_identity`). It says the phone's own
 * PASSCODE is an acceptable confirmation, so a review handset with no fingerprint or face
 * enrolled is not sent to fetch a supervisor it can never reach. It is false for every real
 * guard, no build flag sets it, and it changes nothing else: three failures still end in the
 * override, and the geofence is untouched.
 */
export function identityStep(biometrics: Biometrics, failedAttempts: number, passcodeIdentity = false): IdentityStep {
  if (biometrics === 'checking') return { step: 'wait' }
  if (biometrics === 'none' && !passcodeIdentity) return { step: 'override', reason: 'no_biometrics' }
  if (failedAttempts >= MAX_ATTEMPTS) return { step: 'override', reason: 'biometric_failed' }
  return { step: 'confirm', attemptsLeft: MAX_ATTEMPTS - failedAttempts }
}

/**
 * WHAT THE OS PROMPT IS ALLOWED TO ACCEPT.
 *
 * A fingerprint or a face is a thing only the guard has. THE PHONE'S PASSCODE IS NOT: it is six digits
 * that can be told to a colleague down a telephone line, and a start confirmed with it is recorded as
 * `app` — "the start the PHONE CONFIRMED" — with no flag, no supervisor and nothing for the Head of
 * Security to read. That is the silent unconfirmed start this app closed, wearing another coat.
 *
 * So the device fallback is OFF for every real guard. It is opened by ONE thing and nothing else: the
 * SERVER saying `passcode_identity` on `shifts.preflight`, which is true only for an app-store reviewer's
 * demonstration account. That is also exactly what the clock-in screen's words promise — a real
 * guard is told "Touch the sensor or look at the camera", and now that is all they are asked for.
 *
 * NOBODY IS STRANDED BY THIS. A face that will not match is a failed attempt like any other; three of
 * them close the ordinary path and open the supervisor's PIN, which is flagged, logged and reported.
 *
 * EVERY GUARD-APP IDENTITY CHECK ASKS THROUGH HERE, not only the clock-in — enrolment binds the handset
 * to a named guard, so a passcode there decides whose phone this is from then on. Enrolment has no
 * `passcode_identity` to pass: there is no shift, no pre-flight and no token yet, so it is always false.
 *
 * The one place in the estate that is NOT this rule is the RESIDENT app's own screen lock, where the
 * passcode fallback is open on purpose — see the note at `apps/resident/src/features/settings/useSettings.ts`.
 */
export function identityPrompt(passcodeIdentity: boolean, promptMessage = 'Confirm your identity to clock in'): { promptMessage: string; cancelLabel: string; disableDeviceFallback: boolean } {
  return {
    promptMessage,
    cancelLabel: 'Cancel',
    disableDeviceFallback: !passcodeIdentity,
  }
}

/** A route parameter is whatever somebody typed into a link: anything that is not a known reason is the safe one. */
export const reasonFrom = (param: unknown): OverrideReason =>
  param === 'no_biometrics' || param === 'outside_geofence' || param === 'position_unconfirmed' ? param : 'biometric_failed'

/** The screen's heading for each reason. */
export const HEADING: Record<OverrideReason, string> = {
  biometric_failed: 'This phone cannot confirm it is you',
  no_biometrics: 'This phone cannot confirm it is you',
  outside_geofence: 'You are outside the geofence',
  position_unconfirmed: 'Your position could not be established',
}

/** Said at the top of the override screen: what is happening, in the guard's words. */
export const WHY: Record<OverrideReason, string> = {
  biometric_failed: 'This phone could not match your fingerprint or face after 3 tries, so it cannot start your shift on its own.',
  no_biometrics: 'This phone has no fingerprint or face set up, so it cannot confirm it is you and cannot start your shift on its own.',
  outside_geofence: 'This phone places you outside the geofence of the post you are rostered to, so it cannot start your shift on its own. If you are at your post, your supervisor can vouch for you.',
  position_unconfirmed: 'This phone could not establish where you are, so it cannot start your shift on its own. If you are at your post, your supervisor can vouch for you.',
}

export const WHAT_HAPPENS = 'Your supervisor can start this shift with their PIN. It is recorded and reported.'

/**
 * THE DEMONSTRATION ACCOUNT HAS NO SUPERVISOR TO FETCH.
 *
 * An App Store or Play reviewer signs in on the demonstration account, taps Clock in, and — if they
 * cancel the phone's confirmation three times, which a reviewer holding somebody else's test handset
 * very reasonably does — the rules above send them here. There is nobody in Jamaica whose PIN they
 * can ask for, no estate office to telephone, and no supervisor account on the demonstration estate
 * at all: the form CANNOT work for them. Drawing it anyway is a dead control on the one screen that
 * stands between a reviewer and the product, and a reviewer who cannot get past it rejects the app
 * under Guideline 2.1.
 *
 * So for that account — and only for it, on the SERVER's word (`shifts.preflight.passcode_identity`),
 * never a build flag — no PIN form is drawn. The screen says why in one sentence and sends them back
 * to the clock-in screen, where the same server has already said the phone's own PASSCODE is an
 * acceptable confirmation. Nothing changes for a real guard, whose `passcodeIdentity` is always false.
 */
export const NO_SUPERVISOR =
  'This is the demonstration account used for app review. No supervisor holds a PIN for it, so this form could never start the shift. Go back to Clock in and confirm with this phone’s passcode instead: on this account that is accepted.'

export const OFFLINE = 'This needs a connection. A supervisor’s PIN can only be checked by the office’s server, and this phone has no signal. Nothing has been sent or saved. Move to where there is signal and try again, or call your supervisor or the control room now, so they know you are at your post.'

/** Digits only, never more than the PIN is long. */
export const cleanPin = (text: string): string => text.replace(/\D/g, '').slice(0, PIN_LENGTH)

export const cleanSupervisor = (text: string): string => text.trim().toLowerCase()

/**
 * WHAT THE OVERRIDE REPLACES.
 *
 * WHO, and — since M12 §1.3 — WHERE. The supervisor's PIN stands in for the identity check, and for the geofence
 * when the phone places the guard outside it or cannot say where they are: the supervisor is vouching that this
 * guard is on post. So being outside no longer closes this form. What the phone saw is sent anyway and judged by
 * the server's rule, and the shift is flagged and reported with the distance. A mocked location does not block
 * either path — it is sent, recorded and flagged — and the screen says so before the PIN is typed. With no signal
 * nothing is queued and nothing is saved: a PIN can only be checked by the server.
 *
 * Returns null only when a PIN can really be checked; otherwise the sentence the guard reads.
 */
export type OverrideGate = {
  hasShift: boolean
  alreadyStarted: boolean
  /** Kept for the screen's words; it no longer closes the form (M12 §1.3). */
  outside: boolean
  /** The position is still being worked out. */
  checking: boolean
  online: boolean
  postName: string
  /**
   * The SERVER said this phone may confirm with its own passcode — true only for the app-store
   * reviewer's demonstration account. There is no supervisor to fetch for that account,
   * so a PIN can never be checked for it and the form is not drawn at all.
   */
  passcodeIdentity: boolean
}

export function overrideBlocked(gate: OverrideGate): string | null {
  if (!gate.hasShift) return 'There is no shift on this phone to start.'
  if (gate.alreadyStarted) return 'This shift has already started. Nothing was recorded against a supervisor.'
  if (gate.passcodeIdentity) return NO_SUPERVISOR
  if (!gate.online) return OFFLINE
  if (gate.checking) return 'Checking where this phone is.'
  return null
}

export type OverrideFields = { supervisor: string; pin: string }
export type FieldErrors = Partial<Record<keyof OverrideFields, string>>

export function validateOverride(fields: OverrideFields): FieldErrors {
  const errors: FieldErrors = {}
  const who = cleanSupervisor(fields.supervisor)
  if (who === '') errors.supervisor = 'Enter your supervisor’s sign-in address.'
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(who)) errors.supervisor = 'That does not look like a sign-in address. It is the email your supervisor signs in to the office console with.'
  if (fields.pin.length === 0) errors.pin = 'Your supervisor enters their PIN here.'
  else if (fields.pin.length !== PIN_LENGTH) errors.pin = `The PIN is ${PIN_LENGTH} digits.`
  return errors
}

/** Exactly what the ordinary clock-in carries about the position, plus who is vouching and why. */
export function overrideBody(reason: OverrideReason, fields: OverrideFields, fix: { distance: number | null; mocked: boolean; latitude: number | null; longitude: number | null; accuracyM: number | null }) {
  return {
    supervisor: cleanSupervisor(fields.supervisor),
    pin: fields.pin,
    reason,
    ...(fix.latitude === null || fix.longitude === null ? null : { latitude: fix.latitude, longitude: fix.longitude }),
    ...(fix.accuracyM === null ? null : { accuracy_m: fix.accuracyM }),
    ...(fix.distance === null ? null : { geofence_distance_m: Math.round(fix.distance) }),
    mock_location: fix.mocked,
  }
}

export type Refusal = {
  /** The sentence to show. */
  message: string
  /** Per-field sentences from a 422. */
  fields: FieldErrors
  /** The shift has a start already (a lost answer, or started another way): go and look at it. */
  alreadyStarted: boolean
}

/**
 * The server's answer, in plain words. A refusal of the PIN is shown as the SERVER wrote it — one sentence
 * for every kind of "no", which never says which part was wrong or how close it was. Nothing here adds to it.
 */
export function refusalOf(error: ApiError): Refusal {
  const none = { fields: {}, alreadyStarted: false }
  if (error.status === 'network' || error.status === 'timeout') {
    return { ...none, message: 'The server could not be reached, so the PIN was not checked. Nothing has been saved on this phone. Check the signal and try again: your supervisor will need to enter the PIN again.' }
  }
  if (error.status === 429) return { ...none, message: 'Too many tries from this phone. Wait a minute, then try again.' }
  if (error.status === 409) return { ...none, alreadyStarted: true, message: error.message }
  if (error.status === 404) return { ...none, message: 'This shift is no longer yours to start. Go back and pull down to refresh your roster.' }
  if (error.status === 422) {
    const fields: FieldErrors = {}
    if (error.fieldErrors.supervisor?.[0]) fields.supervisor = error.fieldErrors.supervisor[0]
    if (error.fieldErrors.pin?.[0]) fields.pin = error.fieldErrors.pin[0]
    return { message: Object.keys(fields).length > 0 ? 'Check what was entered and try again.' : error.message, fields, alreadyStarted: false }
  }
  if (typeof error.status === 'number' && error.status >= 500) return { ...none, message: 'Something went wrong at the office’s end, so the PIN was not checked. Try again in a moment, or call the control room.' }
  return { ...none, message: error.message }
}

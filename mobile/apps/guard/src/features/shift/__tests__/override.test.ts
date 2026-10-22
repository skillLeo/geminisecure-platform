import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import type { ApiError } from '@gs/core/api'
import {
  MAX_ATTEMPTS,
  OFFLINE,
  PIN_LENGTH,
  cleanPin,
  cleanSupervisor,
  NO_SUPERVISOR,
  identityPrompt,
  identityStep,
  locationReasonOf,
  overrideBlocked,
  overrideBody,
  reasonFrom,
  refusalOf,
  validateOverride,
  type OverrideGate,
} from '../override'

const err = (over: Partial<ApiError>): ApiError => ({ status: 500, code: 'x', message: 'x', fieldErrors: {}, retryAfter: null, ...over })
const gate = (over: Partial<OverrideGate> = {}): OverrideGate => ({ hasShift: true, alreadyStarted: false, outside: false, checking: false, online: true, postName: 'Main Gate', passcodeIdentity: false, ...over })
const fix = { distance: 3.4, mocked: false, latitude: 18.0179, longitude: -76.8099, accuracyM: 4 }

describe('when the supervisor override is the way forward', () => {
  it('a phone with no fingerprint or face set up NEVER starts a shift on its own', () => {
    expect(identityStep('none', 0)).toEqual({ step: 'override', reason: 'no_biometrics' })
    expect(identityStep('none', 2)).toEqual({ step: 'override', reason: 'no_biometrics' })
  })

  it('three checks that did not match lead to the same place, and not one earlier', () => {
    expect(identityStep('available', 0)).toEqual({ step: 'confirm', attemptsLeft: MAX_ATTEMPTS })
    expect(identityStep('available', MAX_ATTEMPTS - 1)).toEqual({ step: 'confirm', attemptsLeft: 1 })
    expect(identityStep('available', MAX_ATTEMPTS)).toEqual({ step: 'override', reason: 'biometric_failed' })
  })

  it('nothing is offered while the phone is still being asked what it has', () => {
    expect(identityStep('checking', 0)).toEqual({ step: 'wait' })
    expect(identityStep('checking', 9)).toEqual({ step: 'wait' })
  })

  /*
   * THE REVIEWER'S PASSCODE PATH. An App Store or Play reviewer's handset has nobody's
   * face enrolled, and a supervisor in Jamaica is not somebody they can telephone — so for that
   * ONE account, and only because the SERVER said so on `shifts.preflight`, the phone's passcode
   * is an acceptable confirmation.
   */
  it('a reviewer\'s demonstration account may confirm with the phone\'s passcode instead', () => {
    expect(identityStep('none', 0, true)).toEqual({ step: 'confirm', attemptsLeft: MAX_ATTEMPTS })
    // It is the SERVER's word and nothing else. Without it the same phone goes to the supervisor.
    expect(identityStep('none', 0, false)).toEqual({ step: 'override', reason: 'no_biometrics' })
    expect(identityStep('none', 0)).toEqual({ step: 'override', reason: 'no_biometrics' })
  })

  it('and it changes NOTHING else: three failures still end at the supervisor, and waiting still waits', () => {
    expect(identityStep('none', MAX_ATTEMPTS, true)).toEqual({ step: 'override', reason: 'biometric_failed' })
    expect(identityStep('available', MAX_ATTEMPTS, true)).toEqual({ step: 'override', reason: 'biometric_failed' })
    expect(identityStep('checking', 0, true)).toEqual({ step: 'wait' })
  })

  /*
   * THE PHONE'S PASSCODE IS NOT A FINGERPRINT. `disableDeviceFallback: false` was passed to the OS
   * unconditionally, so on any handset the prompt offered "Enter passcode" and a colleague who knew
   * six digits could start somebody else's shift — recorded as `app`, the start the PHONE CONFIRMED,
   * with no flag, no supervisor and nothing reported. It also walked around the three-strikes route
   * to the override entirely, because a passcode never fails three times.
   */
  it('a REAL guard is asked for a fingerprint or a face, and this phone\'s passcode is not accepted in their place', () => {
    expect(identityPrompt(false).disableDeviceFallback).toBe(true)
  })

  it('only the SERVER\'s reviewer flag opens the passcode: the same flag the screen\'s words follow', () => {
    expect(identityPrompt(true).disableDeviceFallback).toBe(false)
    expect(identityPrompt(true).promptMessage).toBe(identityPrompt(false).promptMessage)
  })

  it('and the clock-in asks the OS for exactly that, rather than deciding it again at the call site', () => {
    const source = readFileSync(join(__dirname, '..', 'useClockIn.ts'), 'utf8')
    expect(source).toMatch(/authenticateAsync\(identityPrompt\(passcodeIdentity\)\)/)
    // The rule lives in one place: no second, hard-coded answer beside the call.
    expect(source).not.toMatch(/disableDeviceFallback\s*:/)
  })

  /*
   * ENROLMENT IS THE HEAVIER HALF OF THE SAME BUG. The clock-in decides who worked a shift;
   * enrolment decides whose handset this IS, for every shift afterwards. It passed
   * `disableDeviceFallback: false` too, so the phone's passcode could bind the app to a guard
   * who was not in the room. There is no reviewer flag to weigh at enrolment — no shift, no
   * pre-flight, no token — so the fallback is simply closed.
   */
  it('the ENROLMENT check asks the same way, and never opens the passcode', () => {
    const source = readFileSync(join(__dirname, '..', '..', 'enrolment', 'useOnboarding.ts'), 'utf8')
    expect(source).toMatch(/authenticateAsync\(identityPrompt\(false, 'Confirm it is you'\)\)/)
    expect(source).not.toMatch(/disableDeviceFallback\s*:/)
    expect(identityPrompt(false, 'Confirm it is you')).toEqual({
      promptMessage: 'Confirm it is you',
      cancelLabel: 'Cancel',
      disableDeviceFallback: true,
    })
  })

  it('no guard-app identity check anywhere hard-codes its own answer', () => {
    const shift = join(__dirname, '..')
    for (const file of ['useClockIn.ts', join('..', 'enrolment', 'useOnboarding.ts')]) {
      expect(readFileSync(join(shift, file), 'utf8')).not.toMatch(/disableDeviceFallback\s*:/)
    }
  })

  it('a reason that arrived in a link is never trusted', () => {
    expect(reasonFrom('no_biometrics')).toBe('no_biometrics')
    expect(reasonFrom('biometric_failed')).toBe('biometric_failed')
    expect(reasonFrom('anything-else')).toBe('biometric_failed')
    expect(reasonFrom(undefined)).toBe('biometric_failed')
  })
})

/*
 * THE DEMONSTRATION ACCOUNT IS NEVER SHOWN A PIN FORM.
 *
 * An App Store or Play reviewer signs in on the reviewer account and cancels the phone's identity
 * prompt three times — a perfectly ordinary thing to do with somebody else's test handset — and the
 * rules above route them to the supervisor override. There is no supervisor on the demonstration
 * estate, so a PIN entered there could never be accepted: it is a dead control on the one screen
 * standing between a reviewer and the product, and a reviewer stuck there rejects under 2.1.
 *
 * The SERVER's `passcode_identity` is what says which account this is. Nothing on the phone decides.
 */
describe('the app-store reviewer\'s demonstration account and the supervisor override', () => {
  it('is never asked for a supervisor\'s PIN: the form is blocked and the reason says why', () => {
    const blocked = overrideBlocked(gate({ passcodeIdentity: true }))
    expect(blocked).toBe(NO_SUPERVISOR)
    expect(blocked).toContain('No supervisor holds a PIN for it')
    // …and it points at the way that does work, rather than leaving them on a screen with no exit.
    expect(blocked).toContain('passcode')
  })

  it('stays blocked however good everything else is: there is no combination that draws the form', () => {
    for (const extra of [{}, { online: true }, { checking: false }, { outside: false }, { postName: '' }] satisfies Partial<OverrideGate>[]) {
      expect(overrideBlocked(gate({ passcodeIdentity: true, ...extra }))).toBe(NO_SUPERVISOR)
    }
  })

  it('and a REAL guard is unaffected: without the server\'s flag the PIN form is offered exactly as before', () => {
    expect(overrideBlocked(gate({ passcodeIdentity: false }))).toBeNull()
    expect(overrideBlocked(gate())).toBeNull()
  })

  it('the SCREEN draws no supervisor fields for it, not a disabled form, no fields at all', () => {
    const screen = readFileSync(join(__dirname, '..', 'screens', 'ClockInOverrideScreen.tsx'), 'utf8')
    // The two TextInputs live inside the branch taken only when the server did NOT set the flag.
    expect(screen).toMatch(/m\.passcodeIdentity \?[\s\S]{0,600}?NO_SUPERVISOR/)
    expect(screen).toMatch(/Back to clock in/)
    // And "your supervisor can start this shift with their PIN" is not said to an account that has none.
    expect(screen).toMatch(/m\.passcodeIdentity \? null : <Text[^>]*>\{WHAT_HAPPENS\}/)
  })

  it('the hook takes the flag from the SERVER\'s pre-flight and nowhere else', () => {
    const hook = readFileSync(join(__dirname, '..', 'useOverride.ts'), 'utf8')
    expect(hook).toMatch(/const \{[^}]*passcodeIdentity[^}]*\} = useGeofence\(/)
    expect(hook).toMatch(/passcodeIdentity,/)
    // No build flag, no __DEV__ branch, nothing the phone decides for itself.
    expect(hook).not.toMatch(/__DEV__|EXPO_PUBLIC_REVIEWER/)
  })
})

describe('the override vouches for WHO: and, since M12 §1.3, for WHERE', () => {
  it('outside the geofence the PIN form is open: the supervisor vouches the guard is on post, and the distance goes with it', () => {
    expect(overrideBlocked(gate({ outside: true }))).toBeNull()
    expect(overrideBody('outside_geofence', { supervisor: 'a@b.com', pin: '482913' }, { ...fix, distance: 500 })).toMatchObject({ reason: 'outside_geofence', geofence_distance_m: 500 })
  })

  it('names the location reason from where the phone is, and none when the phone is fine or simply has no signal', () => {
    expect(locationReasonOf({ state: 'outside', boundaryM: 450, accuracyM: 6, radiusM: 50, distanceM: 500 })).toBe('outside_geofence')
    expect(locationReasonOf({ state: 'poor_fix', accuracyM: 320 })).toBe('position_unconfirmed')
    expect(locationReasonOf({ state: 'unavailable', why: 'permission' })).toBe('position_unconfirmed')
    expect(locationReasonOf({ state: 'unavailable', why: 'services_off' })).toBe('position_unconfirmed')
    expect(locationReasonOf({ state: 'unavailable', why: 'no_fix' })).toBe('position_unconfirmed')
    expect(locationReasonOf({ state: 'unavailable', why: 'offline' })).toBeNull()
    expect(locationReasonOf({ state: 'inside', boundaryM: 3, accuracyM: 5, radiusM: 50 })).toBeNull()
    expect(locationReasonOf({ state: 'no_geofence' })).toBeNull()
  })

  it('reads the two location reasons from a link, and anything else as the safe identity one', () => {
    expect(reasonFrom('outside_geofence')).toBe('outside_geofence')
    expect(reasonFrom('position_unconfirmed')).toBe('position_unconfirmed')
    expect(reasonFrom('anything')).toBe('biometric_failed')
  })

  it('a MOCKED location never blocks it: it is sent, recorded and flagged, exactly as an ordinary clock-in is', () => {
    expect(overrideBlocked(gate())).toBeNull()
    expect(overrideBody('biometric_failed', { supervisor: 'a@b.com', pin: '482913' }, { ...fix, mocked: true }).mock_location).toBe(true)
  })

  it('offline it says so plainly and nothing is queued or saved', () => {
    expect(overrideBlocked(gate({ online: false }))).toBe(OFFLINE)
    expect(OFFLINE).toContain('Nothing has been sent or saved')
  })

  it('a shift that has already started has nothing to override', () => {
    expect(overrideBlocked(gate({ alreadyStarted: true }))).toContain('already started')
    expect(overrideBlocked(gate({ hasShift: false }))).toContain('no shift')
  })

  it('a PIN is only asked for once a position is known', () => {
    expect(overrideBlocked(gate({ checking: true }))).toContain('Checking where this phone is')
  })
})

describe('what leaves the phone', () => {
  it('carries the position the clock-in carries, plus WHY, and nothing from the biometric check itself', () => {
    const body = overrideBody('no_biometrics', { supervisor: ' Owen.Grant@Example.COM ', pin: '482913' }, fix)
    expect(body).toEqual({
      supervisor: 'owen.grant@example.com',
      pin: '482913',
      reason: 'no_biometrics',
      latitude: 18.0179,
      longitude: -76.8099,
      accuracy_m: 4,
      geofence_distance_m: 3,
      mock_location: false,
    })
    // Invariant 14: no score, no template, no image, no attempt count.
    const keys = Object.keys(body).join(' ')
    expect(keys).not.toMatch(/score|template|image|photo|finger|face|attempt|biometric/i)
  })

  it('leaves out what the phone does not know, rather than sending a zero', () => {
    const body = overrideBody('biometric_failed', { supervisor: 'a@b.com', pin: '482913' }, { distance: null, mocked: false, latitude: null, longitude: null, accuracyM: null })
    expect(body).toEqual({ supervisor: 'a@b.com', pin: '482913', reason: 'biometric_failed', mock_location: false })
  })
})

describe('the PIN itself', () => {
  it('is digits only and never longer than the PIN is', () => {
    expect(cleanPin('4a8b2c9d1e3f7')).toBe('482913')
    expect(cleanPin('48 29-13')).toBe('482913')
    expect(cleanPin('')).toBe('')
    expect(cleanPin('482913').length).toBe(PIN_LENGTH)
  })

  it('a short PIN and a missing address are caught before anything is sent', () => {
    expect(validateOverride({ supervisor: '', pin: '' })).toEqual({
      supervisor: 'Enter your supervisor’s sign-in address.',
      pin: 'Your supervisor enters their PIN here.',
    })
    expect(validateOverride({ supervisor: 'owen', pin: '4829' }).supervisor).toContain('sign-in address')
    expect(validateOverride({ supervisor: 'owen', pin: '4829' }).pin).toBe(`The PIN is ${PIN_LENGTH} digits.`)
    expect(validateOverride({ supervisor: 'owen.grant@example.com', pin: '482913' })).toEqual({})
  })

  it('the address is trimmed and lower-cased, so what the supervisor typed matches the console', () => {
    expect(cleanSupervisor('  Owen.Grant@Example.COM ')).toBe('owen.grant@example.com')
  })

  /*
   * THE DATA LAYER KEEPS THE ARGUMENTS OF THE LAST MUTATION. Those arguments are the PIN, so clearing
   * the field is not enough: without `reset()` the six digits sit in the store's mutation cache
   * (`originalArgs`) after the request has finished, for as long as the screen is mounted. It is
   * dropped in a `finally`, so a refusal and a lost connection drop it exactly as a success does.
   */
  it('is dropped from the store\'s record of what was sent, whatever the answer was', () => {
    const source = readFileSync(join(__dirname, '..', 'useOverride.ts'), 'utf8')
    expect(source).toMatch(/useShiftsClockInOverrideMutation\(\)/)
    // The mutation's `reset` is taken, and called — in a `finally`, not only on the happy path.
    expect(source).toMatch(/reset:\s*forget/)
    expect(source).toMatch(/finally\s*\{[\s\S]*?forget\(\)/)
  })

  it('and is never written anywhere that outlives the screen', () => {
    // The CODE, not the prose about it: the file's own header says "never put in the outbox".
    const source = readFileSync(join(__dirname, '..', 'useOverride.ts'), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/\/\/[^\n]*/g, ' ')
    expect(source).not.toMatch(/SecureStore|AsyncStorage|MMKV|getStorage|jsonStore|useOfflineCache\(\s*['"]?override/)
    /*
     * Never queued. A PIN can only be checked by the server, so the override never reaches the
     * offline runtime — no `submit()` from `@/offline/runtime`, no outbox, no enqueue. (The model's
     * own `submit` handler is a different thing entirely: it posts, online, and waits for an answer.)
     */
    expect(source).not.toMatch(/offline\/runtime|outbox|enqueue/)
    expect(source).not.toMatch(/console\.|Sentry|logger/)
  })
})

describe("the server's refusal is shown as the server wrote it", () => {
  it('never says which part was wrong or how close a guess was', () => {
    const one = 'That supervisor and PIN were not accepted. Check both and try again.'
    const shown = refusalOf(err({ status: 403, code: 'override_refused', message: one }))
    expect(shown.message).toBe(one)
    expect(shown.message).not.toMatch(/digit|correct|close|wrong pin|unknown/i)
    expect(shown.alreadyStarted).toBe(false)
  })

  it('a lost connection says nothing was saved, never that it worked', () => {
    for (const status of ['network', 'timeout'] as const) {
      const shown = refusalOf(err({ status }))
      expect(shown.message).toContain('Nothing has been saved on this phone')
      // Never an offline success: nothing is queued, and the shift is never claimed to have started.
      expect(shown.message).not.toMatch(/queued|sent when|sync|started/i)
    }
  })

  it('a 409 sends the guard to the shift instead of trying again', () => {
    expect(refusalOf(err({ status: 409, code: 'shift_already_started', message: 'This shift has already started.' })).alreadyStarted).toBe(true)
  })

  it('too many tries from this phone is said as what it is', () => {
    expect(refusalOf(err({ status: 429, code: 'rate_limited', message: 'x' })).message).toContain('Too many tries from this phone')
  })

  it('a 422 is put on the fields and never echoes what was typed', () => {
    const shown = refusalOf(err({ status: 422, code: 'validation_failed', message: 'x', fieldErrors: { pin: ['A supervisor PIN is 6 digits.'] } }))
    expect(shown.fields.pin).toBe('A supervisor PIN is 6 digits.')
    expect(shown.message).toBe('Check what was entered and try again.')
  })

  it('a 404 means the shift is no longer this guard’s to start', () => {
    expect(refusalOf(err({ status: 404, code: 'not_found', message: 'No such shift.' })).message).toContain('no longer yours to start')
  })
})

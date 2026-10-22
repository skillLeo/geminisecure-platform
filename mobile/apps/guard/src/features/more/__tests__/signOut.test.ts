/**
 * "SIGN OUT" HAS TO BE TRUE — M6, Q-M42, and the client's ruling on the idle window.
 *
 * Until this, tapping Sign out deleted the phone's own copy of the token and left the row on
 * the server valid for ever: a token copied off a handset — a backup, a cable, a sold phone —
 * kept working. The button said a thing that was not true.
 *
 * Read from the SOURCE rather than rendered, because what is being held is an ORDER of
 * network calls, and the order is the whole of the correctness: everything that needs the
 * token happens while the token still works, and killing it is the last act before the phone
 * is wiped. A rendered test would pass just as happily with the calls the wrong way round.
 * (The same pattern as `panic/__tests__/panicReach.test.ts`.)
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

const source = readFileSync(path.join(__dirname, '..', 'useSignOut.ts'), 'utf8')
const screen = readFileSync(path.join(__dirname, '..', 'screens', 'MoreScreen.tsx'), 'utf8')
const unlock = readFileSync(path.join(__dirname, '..', '..', 'unlock', 'screens', 'UnlockScreen.tsx'), 'utf8')
const unlockLogic = readFileSync(path.join(__dirname, '..', '..', 'unlock', 'useUnlock.ts'), 'utf8')

/** The body of `confirm`, so the import list at the top of the file cannot pass the order check for it. */
const body = source.slice(source.indexOf('const confirm = useCallback'))

const at = (needle: string): number => {
  const i = body.indexOf(needle)
  expect(i).toBeGreaterThan(-1)
  return i
}

describe('signing out of a guard handset', () => {
  it('tells the SERVER, on the endpoint the catalogue names', () => {
    expect(source).toContain('authSignOut')
    expect(source).toContain('endServerSession')
    // Never by hand: the generated client is what carries the ability, the allowlist and "never retried".
    expect(source).toContain("guardApi.endpoints.authSignOut.initiate")
    expect(source).not.toMatch(/fetch\(|axios/)
  })

  it('does everything that NEEDS the token before it destroys the token, and wipes the phone last', () => {
    expect(at('drainNow()')).toBeLessThan(at('pushRegistration.signOut()'))
    expect(at('pushRegistration.signOut()')).toBeLessThan(at('endServerSession'))
    expect(at('endServerSession')).toBeLessThan(at('clearEverything'))
    expect(at('clearEverything')).toBeLessThan(at('forgetQueue()'))
  })

  it('clears this phone as a CHOSEN sign-out, which is the one ending that deletes the queue', () => {
    // The guard was warned and decided with the number in front of them; nothing may be inherited
    // by whoever signs in on this phone next.
    expect(source).toContain("clearEverything('guard', dispatch, 'signed_out')")
    expect(source).toContain("dispatch(signedOut())")
  })

  it('is still refused while a duress alert is LIVE, not for the leftover record of a closed one (B124)', () => {
    expect(source).toContain('const live = isLive(duress)')
    expect(source).toContain('if (working || live) return')
    expect(source).toContain('active duress alert')
  })

  it('says beforehand that a phone with no signal cannot tell the server, and never claims afterwards that it did', () => {
    expect(screen).toContain('the server cannot be told to end this session now')
    expect(screen).toContain('ends by itself within 30 minutes')
    // No sentence anywhere that asserts the server WAS told.
    expect(screen).not.toMatch(/signed out of the server|the server has been told/i)
  })

  it('says the true way back before the tap: a fingerprint or a supervisor PIN, no new code (M32 F3)', () => {
    expect(screen).toContain("To sign back in on this phone you use your fingerprint, or a supervisor's PIN: no new code.")
    expect(screen).not.toMatch(/NEW enrolment code/)
    // And it goes where that sentence says: the unlock screen while the phone is still the guard's.
    expect(source).toContain('router.replace(signedOutRoute() as never)')
  })
})

describe('coming back after the idle window closes (M32 F3: a fingerprint, never a code)', () => {
  it('does not accuse the office of anything: a timeout is not a revocation', () => {
    expect(unlockLogic).toContain("if (reason === 'expired') return 'You were signed out after 30 minutes with nothing sent from this phone. Nothing you saved on it has been lost.'")
    expect(unlockLogic).not.toMatch(/office (took|revoked|removed)/i)
  })

  it('asks for the fingerprint and nothing else, no code field, and puts the emergency number in front of the officer', () => {
    /*
     * The guard signs back in on this phone alone: the fingerprint, or a supervisor's PIN when the phone cannot match
     * one. No enrolment code is asked for, and at 2 a.m. at a gate the two numbers that need no data and no session
     * are on the screen too. (Q-M46 is the client's decision about whether an on-duty handset is exempt from the window.)
     */
    expect(unlock).toContain("'Sign in with your fingerprint'")
    expect(unlock).not.toMatch(/enrolment code|Enrolment code|XXXX-XXXX/)
    expect(unlock).toContain('call 119 for the police')
    expect(unlock).toContain('110 for fire and ambulance')
  })

  it('never names what is waiting in the queue: a SILENT duress alert must stay silent', () => {
    expect(unlock).not.toMatch(/duress|alert you raised|panic/i)
    expect(unlockLogic.slice(unlockLogic.indexOf('export function whyHere'))).not.toMatch(/duress|panic/i)
  })
})

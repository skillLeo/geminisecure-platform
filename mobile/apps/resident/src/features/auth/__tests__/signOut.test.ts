/**
 * "SIGN OUT" HAS TO BE TRUE ON A RESIDENT'S PHONE TOO — M6, Q-M42.
 *
 * A resident signs in on a borrowed phone, or sells one. Until this, signing out deleted the
 * phone's own copy of the token and left the row on the server valid for ever, so the live
 * credential — and with it the power to issue signed visitor passes to that estate's gate —
 * went with the handset.
 *
 * Read from the SOURCE rather than rendered, because what is being held is an ORDER of
 * network calls: everything that needs the token happens while the token still works, and
 * killing it is the last act before the phone is wiped. A rendered test would pass just as
 * happily with the calls the wrong way round.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

const source = readFileSync(path.join(__dirname, '..', 'session.ts'), 'utf8')
// The sign-out question's sentences (Profile no longer asks it — board 17 draws no Sign out; they are kept here).
const profile = readFileSync(path.join(__dirname, '..', '..', 'profile', 'signOutWords.ts'), 'utf8')
const logIn = readFileSync(path.join(__dirname, '..', 'screens', 'LogInScreen.tsx'), 'utf8')

/** The body of `closeSession`, so the import list at the top cannot pass the order check for it. */
const body = source.slice(source.indexOf('export async function closeSession'))

const at = (needle: string): number => {
  const i = body.indexOf(needle)
  expect(i).toBeGreaterThan(-1)
  return i
}

describe('closeSession', () => {
  it('tells the server, through the generated client and not by hand', () => {
    expect(source).toContain('residentApi.endpoints.authSignOut.initiate')
    expect(source).toContain('endServerSession')
    expect(source).not.toMatch(/fetch\(|axios/)
  })

  it('does everything that NEEDS the token first, kills the token, then wipes the phone', () => {
    expect(at('pushRegistration.signOut()')).toBeLessThan(at('clearNotifications()'))
    expect(at('clearNotifications()')).toBeLessThan(at('endServerSession'))
    expect(at('endServerSession')).toBeLessThan(at('clearEverything'))
    expect(at('clearEverything')).toBeLessThan(at('forgetQueue()'))
  })

  it('is a CHOSEN sign-out, which is the one ending that deletes what was saved with no connection', () => {
    expect(body).toContain("clearEverything('resident', dispatch, 'signed_out')")
    // …and the estate the person lives on is a preference, not a credential: it survives on purpose.
    expect(body).toContain('if (estate) lastEstate.set(estate)')
  })

  it('says beforehand that a phone with no connection cannot tell the estate, and never claims afterwards that it did', () => {
    expect(profile).toContain('the estate cannot be told to end this session now')
    expect(profile).toContain('ends by itself within 30 minutes')
    expect(profile).not.toMatch(/signed out of the server|the estate has been told/i)
  })
})

describe('coming back after the idle window closes', () => {
  /*
   * M47 §1.1: Log in is the board's screen and nothing else — so the two "you were signed out" notes are gone from it.
   * What they promised still holds: a session that ended by itself keeps the queue (clearEverything's `keep`), and it is
   * sent once the same account is back in.
   */
  it('draws the board alone: no note about how the last session ended', () => {
    expect(logIn).not.toMatch(/endedReason|signed itself out|signed in on another phone/)
  })
})

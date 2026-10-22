/**
 * Deleting the account. Both stores allow records to be kept "for legitimate reasons …
 * when clearly disclosed" — so the disclosure IS the feature, and this holds it to that.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { AFTER, CONFIRMATION, DEFAULT_WINDOWS, DELETED_NOW, DEMONSTRATION, KEPT, NEEDS_CONNECTION, confirmationTyped, debtLine, deleteProblem, restoreLine } from '../deleteAccount'

describe('what the person is told before anything happens (M18H §2.1)', () => {
  it('itemises what stops at once, and what is kept with the reason why', () => {
    expect(DELETED_NOW.items.length).toBeGreaterThanOrEqual(3)
    expect(KEPT.items.length).toBeGreaterThanOrEqual(3)
    // Every "kept" line must say WHY it is kept — a bare list is not a disclosure.
    for (const item of KEPT.items) expect(item).toMatch(/because|belongs?|law|record|held by|kept/i)
  })

  it('names what a store asks about and what M18H adds: access, the passes, bookings, notifications, the list, the phone', () => {
    const text = DELETED_NOW.items.join(' ').toLowerCase()
    expect(text).toContain('signed out')
    expect(text).toMatch(/e-pass/)
    expect(text).toMatch(/visitor pass/)
    expect(text).toMatch(/bookings are cancelled.*refunded/)
    expect(text).toContain('notifications')
    expect(text).toMatch(/list of current residents/)
    expect(text).toMatch(/saved on this phone|settings/)
  })

  it('says a debt stays with the home: in the kept list, and on its own line when the home owes', () => {
    expect(KEPT.items.join(' ')).toMatch(/does not clear a debt/)
    expect(debtLine('$3,400.00')).toBe("Your home owes $3,400.00. That does not go away when the account does: it stays on the home's account with the estate.")
    expect(debtLine(null)).toBeNull()
  })

  it('says how it comes back, with the estate\'s own windows, and when it cannot be undone', () => {
    expect(restoreLine(DEFAULT_WINDOWS)).toBe('Changed your mind? Sign in again with the same email within 7 days and the app offers to restore everything. After that, the estate office can restore it until day 30. After 30 days it cannot be undone: nothing of you is left on the account.')
    expect(restoreLine({ self_days: 14, office_days: 60 })).toMatch(/within 14 days.*until day 60.*After 60 days/)
  })

  it('promises nothing about the community register except who really holds it', () => {
    const register = KEPT.items.find((i) => /register/i.test(i)) ?? ''
    expect(register).toMatch(/estate office/i)
    expect(register).not.toMatch(/\bdeleted?\b/i)
  })

  it('never says a record is deleted when it is kept', () => {
    for (const item of KEPT.items) expect(item).not.toMatch(/\b(is|are) deleted\b/i)
  })
})

describe('the confirmation', () => {
  it('is the exact word, whatever case it is typed in, with stray spaces forgiven', () => {
    expect(confirmationTyped('DELETE')).toBe(true)
    expect(confirmationTyped('  delete ')).toBe(true)
    expect(confirmationTyped('Delete')).toBe(true)
  })

  it('is not satisfied by anything else: a mis-tap can never do this', () => {
    for (const typed of ['', ' ', 'D', 'DELET', 'DELETED', 'yes', 'OK', 'delete my account']) {
      expect(confirmationTyped(typed)).toBe(false)
    }
  })

  it('is the same word the server demands', () => {
    expect(CONFIRMATION).toBe('DELETE')
  })
})

describe('when it cannot be done', () => {
  it('says a connection is needed, and that it is never saved for later', () => {
    expect(NEEDS_CONNECTION).toMatch(/connection/i)
    expect(NEEDS_CONNECTION).toMatch(/never saved|not.*later|while you watch/i)
  })

  it('gives the reference back afterwards, with until when signing in restores it and after when it cannot', () => {
    const after = AFTER('GS-7K2M9Q', '2026-10-04', '2026-10-27')
    expect(after).toContain('GS-7K2M9Q')
    // Dates in the one form (M98).
    expect(after).toContain('until 4 Oct 2026')
    expect(after).toContain('After 27 Oct 2026 it cannot be undone')
    // An answer without the dates (an older server) still gives the reference, and promises nothing.
    expect(AFTER('GS-7K2M9Q')).toBe('Your account is deleted. Your reference is GS-7K2M9Q: keep it if you ever need to ask us about this.')
  })
})

/*
 * THE DEMONSTRATION SESSION, ON THE ONE SCREEN BOTH STORES GO LOOKING FOR.
 *
 * "Explore a demo community" hands the phone a READ-ONLY token, so this screen's request comes back
 * `403 missing_ability` — whose own sentence is "This handset's token does not carry the ability this
 * endpoint needs." An App Store reviewer reads a developer's error on the account-deletion screen as
 * a broken app, and they are not wrong to. The true sentence is shown instead.
 */
describe('a demonstration session has no account of its own to delete', () => {
  it('says so in plain words, and never shows the token error', () => {
    const shown = deleteProblem({ demo: true, offline: false, code: 'missing_ability', message: 'This handset\'s token does not carry the ability this endpoint needs.' })

    expect(shown).toBe(DEMONSTRATION)
    expect(shown).not.toMatch(/token|ability|endpoint|handset/i)
    expect(shown).toMatch(/demonstration/i)
    // …and it says what to do instead, rather than leaving them on a screen with no way on.
    expect(shown).toMatch(/Leave/)
    expect(shown).toMatch(/sign in/i)
  })

  it('says it BEFORE the word is typed, on the screen itself', () => {
    const screen = readFileSync(join(__dirname, '..', 'screens', 'DeleteAccountScreen.tsx'), 'utf8')
    expect(screen).toMatch(/m\.demo \? <Note[^>]*text=\{DEMONSTRATION\}/)
  })

  it('leaves a REAL resident\'s answer exactly as the server wrote it', () => {
    const real = 'The estate has withdrawn this account\'s access.'
    expect(deleteProblem({ demo: false, offline: false, code: 'account_suspended', message: real })).toBe(real)
    // Not even for a demonstration session: only the ability refusal is translated, nothing else.
    expect(deleteProblem({ demo: true, offline: false, code: 'rate_limited', message: 'Too many requests.' })).toBe('Too many requests.')
  })

  it('and the reviewer\'s OWN account is refused by the server with its own full sentence, shown as sent', () => {
    // `demo_account_kept`: the demonstration account is never erased, or the next reviewer
    // finds the credentials in STORE_REVIEW_NOTES.md dead. The server's sentence explains it.
    const kept = 'This is the demonstration account used for App Store and Play review, so it is kept for the next reviewer and nothing has been deleted.'
    expect(deleteProblem({ demo: false, offline: false, code: 'demo_account_kept', message: kept })).toBe(kept)
  })

  it('no signal still wins over everything: nothing is ever saved to happen later', () => {
    expect(deleteProblem({ demo: true, offline: true, code: 'missing_ability', message: 'x' })).toBe(NEEDS_CONNECTION)
  })

  it('an answer this app cannot read is never reported as a deletion', () => {
    expect(deleteProblem({ demo: false, offline: false, code: null, message: '' })).toMatch(/Nothing was deleted/)
  })
})

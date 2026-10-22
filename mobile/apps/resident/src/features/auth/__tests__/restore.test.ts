/**
 * Signing in to a deleted account (M18H §2.3, §2.4): what `409 account_deleted` carried, and the screen's words — the
 * order's own, word for word.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { dayMonth, deletedAccount, deletedAccountOf, officeLink, restoreCopy, restoreProblem, restoredNotice } from '../restore'

const details = (over: Record<string, unknown> = {}) => ({
  deleted_on: '2026-09-27', restore_until: '2026-10-04', office_until: '2026-10-27', can_restore: true,
  office_email: 'office@phoenixpark.example', office_phone: '+1 876 555 0100', ticket: 'eyJpdiI6…', ticket_expires_at: '2026-09-29T15:15:00Z', ...over,
})

describe('what the refusal carried', () => {
  it('reads account_deleted, and only it (and restore_office_only, which has the same shape)', () => {
    const d = deletedAccountOf({ code: 'account_deleted', details: details() })
    expect(d).toEqual({ deletedOn: '2026-09-27', restoreUntil: '2026-10-04', officeUntil: '2026-10-27', canRestore: true, ticket: 'eyJpdiI6…', officeEmail: 'office@phoenixpark.example', officePhone: '+1 876 555 0100' })
    expect(deletedAccountOf({ code: 'restore_office_only', details: details({ can_restore: false, ticket: null }) })?.canRestore).toBe(false)
    expect(deletedAccountOf({ code: 'account_suspended', details: details() })).toBeNull()
  })

  it('never offers "Restore my account" without a ticket to restore it with', () => {
    expect(deletedAccountOf({ code: 'account_deleted', details: details({ ticket: null }) })?.canRestore).toBe(false)
    expect(deletedAccountOf({ code: 'account_deleted', details: details({ can_restore: false }) })?.ticket).toBeNull()
  })

  it('refuses a shape it cannot read rather than inventing dates', () => {
    expect(deletedAccountOf({ code: 'account_deleted', details: undefined })).toBeNull()
    expect(deletedAccountOf({ code: 'account_deleted', details: details({ deleted_on: '27 Sep' }) })).toBeNull()
  })
})

describe('the screen, word for word', () => {
  it('days 1–7: deleted on, restore until, and nothing lost (§2.3)', () => {
    const copy = restoreCopy(deletedAccountOf({ code: 'account_deleted', details: details() })!)
    // Dates in the one form (M98: the consoles' "9 Oct 2026").
    expect(copy.title).toBe('This account was deleted on 27 Sep 2026.')
    expect(copy.body).toBe('You can restore it until 4 Oct 2026 and nothing will be lost.')
    expect(copy.actionLabel).toBe('Restore my account')
    expect(copy.notNow).toBe('Not now')
  })

  it('days 8–30: only the office, until the last day (§2.4)', () => {
    const copy = restoreCopy(deletedAccountOf({ code: 'account_deleted', details: details({ can_restore: false, ticket: null }) })!)
    expect(copy.title).toBe('This account was deleted on 27 Sep 2026.')
    expect(copy.body).toBe('Only the estate office can restore it now. Contact them and they can bring it back until 27 Oct 2026.')
    expect(copy.actionLabel).toBe('Contact the estate office')
    expect(copy.notNow).toBeNull()
  })

  it('writes a calendar date with no clock to shift it', () => {
    expect(dayMonth('2026-10-04')).toBe('4 Oct 2026')
    expect(dayMonth('2026-01-31')).toBe('31 Jan 2026')
  })

  it('contacts the office by email first, then by phone', () => {
    const d = deletedAccountOf({ code: 'account_deleted', details: details() })!
    expect(officeLink(d)?.url).toMatch(/^mailto:office@phoenixpark\.example\?subject=/)
    expect(officeLink({ ...d, officeEmail: null })?.url).toBe('tel:+18765550100')
    expect(officeLink({ ...d, officeEmail: null, officePhone: null })).toBeNull()
  })
})

describe('around it', () => {
  it('holds the refusal in memory only, and a restore is said once', () => {
    deletedAccount.set(deletedAccountOf({ code: 'account_deleted', details: details() }))
    expect(deletedAccount.get()?.ticket).toBe('eyJpdiI6…')
    deletedAccount.clear()
    expect(deletedAccount.get()).toBeNull()

    restoredNotice.set()
    expect(restoredNotice.take()).toBe(true)
    expect(restoredNotice.take()).toBe(false)
  })

  it('says a connection is needed when there is none, and the server\'s own sentence otherwise', () => {
    expect(restoreProblem({ code: 'network_unreachable', message: 'x', status: 'network' })).toMatch(/needs a connection/)
    expect(restoreProblem({ code: 'restore_ticket_invalid', message: 'That sign-in has run out.', status: 422 })).toBe('That sign-in has run out.')
  })

  it('the code screen sends a deleted account to the restore screen, and nowhere near a token', () => {
    const screen = readFileSync(join(__dirname, '..', 'screens', 'CodeScreen.tsx'), 'utf8')
    expect(screen).toMatch(/if \(outcome\.deleted\) return router\.replace\('\/\(auth\)\/restore'/)
    const hook = readFileSync(join(__dirname, '..', 'useSignIn.ts'), 'utf8')
    // The ticket goes with the SAME install id the sign-in sent.
    // The SAME install id the sign-in sent: both go through phoneFields(), whose device_uid is this install's.
  expect(hook).toMatch(/ticket: held\.ticket, \.\.\.\(await phoneFields\(\)\)/)
  expect(hook).toMatch(/device_uid: await deviceUid\(\)/)
  })
})

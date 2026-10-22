/**
 * Who may add and remove household members: the PRIMARY resident only. Household members
 * cannot invite, and cannot remove one another.
 */

import * as household from '../household'
import {
  INVITE_OFFLINE, NOT_PRIMARY_NOTE, REMOVE_OFFLINE, addRecordsOf, canManageMembers, cancelInviteSentence, inviteActions, inviteLine, memberRows, mmss, removable, removeSentence, resendWait,
  type HouseholdOp, type Invite,
} from '../household'
import { cancelInviteSheet, removeSheet } from '../sheets'

describe('household members: the primary resident only', () => {
  it("takes the server's word first", () => {
    expect(canManageMembers(true, { is_primary: false })).toBe(true)
    expect(canManageMembers(false, { is_primary: true })).toBe(false)
  })

  it('before the server has ever answered, the register entry says the same thing', () => {
    expect(canManageMembers(undefined, { is_primary: true })).toBe(true)
    expect(canManageMembers(null, { is_primary: '1' })).toBe(true)
    expect(canManageMembers(undefined, { is_primary: false })).toBe(false)
  })

  it('draws NEITHER the controls nor the "only the primary can" note while nothing is known', () => {
    expect(canManageMembers(undefined, null)).toBeNull()
    expect(canManageMembers(undefined, undefined)).toBeNull()
  })

  it('never offers "Remove" beside the primary, beside yourself, or beside a row that is only on this phone', () => {
    expect(removable({ id: 7, is_primary: false }, 3, false)).toBe(true)
    expect(removable({ id: 7, is_primary: true }, 3, false)).toBe(false) // the primary stays
    expect(removable({ id: 3, is_primary: false }, 3, false)).toBe(false) // yourself
    expect(removable({ id: 3, is_primary: false }, '3', false)).toBe(false)
    expect(removable({ id: 7, is_primary: false }, 3, true)).toBe(false) // still waiting to be sent
    expect(removable({ id: 'op_1f3a' as unknown as number, is_primary: false }, 3, false)).toBe(false) // a queued row's key, not a server id
  })

  it('says what removing means BEFORE it is done: in plain words, with no promise it cannot keep', () => {
    const sentence = removeSentence('  Devon   Morgan ')
    expect(sentence).toContain('Devon Morgan leaves your household')
    expect(sentence).toContain('at once')
    expect(sentence).toContain('stays') // history is history — it does not say "deleted"
    expect(sentence).not.toMatch(/deleted|erased/i)
    expect(removeSentence('')).toContain('This person')
  })

  it('removing is online only and says why', () => {
    expect(REMOVE_OFFLINE).toMatch(/needs a connection/)
    expect(REMOVE_OFFLINE).toMatch(/never saved to do later/)
  })

  it('tells a household member who CAN, instead of showing them a button that refuses', () => {
    expect(NOT_PRIMARY_NOTE).toMatch(/primary resident/)
    expect(NOT_PRIMARY_NOTE).toMatch(/estate office/)
  })

  it('a removed member is not drawn back by the queue\'s own record of having added them (found live)', () => {
    const op = (id: string, state: string, result: unknown, endpoint = 'members.store') => ({ id, endpoint, state, result, body: {}, error: null }) as unknown as HouseholdOp
    const ops = [op('a', 'confirmed', { id: 441, full_name: 'Zed' }), op('b', 'confirmed', { id: 442, full_name: 'Other' }), op('c', 'pending', null), op('d', 'confirmed', { id: 441, plate: 'X' }, 'vehicles.store')]
    expect(addRecordsOf(ops, 441)).toEqual(['a'])
    expect(addRecordsOf(ops, '442')).toEqual(['b'])
    expect(addRecordsOf(ops, 999)).toEqual([])
    // …and with that record gone, the list alone decides who is drawn.
    expect(memberRows([], ops.filter((o) => o.id !== 'a')).map((r) => String(r.item.id))).not.toContain('441')
  })

  // J-CS-4. This test used to PIN the bug: "uses a first name on the buttons" — the user's screenshot read
  // "Remove Soft" / "Keep Soft" for "Soft Dev". Both questions now name the person in full, on the title and the button.
  it('names the person IN FULL on the buttons, never the first word of their name', () => {
    const remove = removeSheet({ full_name: 'Devon Morgan' }, true)
    expect(remove.confirmLabel).toBe('Remove Devon Morgan')
    expect(remove.title).toBe('Remove Devon Morgan from your household?')
    expect(remove.cancelLabel).toBe('Cancel')
    const cancel = cancelInviteSheet({ full_name: 'Devon Morgan' }, true)
    expect(cancel.title).toBe("Cancel Devon Morgan's invite?")
    expect([cancel.confirmLabel, cancel.cancelLabel]).toEqual(['Cancel the invite', 'Keep the invite'])
    for (const words of [remove, cancel]) {
      for (const label of [words.title, words.confirmLabel, words.cancelLabel]) expect(label).not.toMatch(/\bDevon\b(?! Morgan)/)
    }
    // …and the helper that cut a name to its first word is gone, so nothing can use it again.
    expect(household).not.toHaveProperty('firstName')
  })
})

describe('the invite to the Resident App: the server\'s word, never the phone\'s', () => {
  // 2:14 PM on 25 September at the estate (Kingston, UTC−5).
  const NOW = Date.parse('2026-09-25T19:14:00Z')
  const invite = (over: Partial<Invite>): Invite => ({ status: 'sent', email: 'devon@example.com', sent_at: '2026-09-25T19:10:00Z', last_attempt_at: '2026-09-25T19:10:00Z', reason: null, expires_at: '2026-10-09T19:10:00Z', resend_after: null, attempts: 1, ...over })
  const pending = (over: Partial<Invite> | null, email: string | null = 'devon@example.com') => ({ status: 'pending', email, invite: over === null ? null : invite(over) })

  it('says "emailed" only when the server says the mail server accepted it: with where and when', () => {
    // M19A §2.3, §4.1: when it went, in words that belong to no clock — the client read "at 9:38 AM" from Pakistan at
    // 7:39 PM and took it for a fault. Older than that, the day with its weekday.
    expect(inviteLine(pending({}), NOW)).toEqual({ text: expect.stringContaining('Invite emailed to devon@example.com 4 minutes ago'), tone: 'ok' })
    expect(inviteLine(pending({ sent_at: '2026-09-24T15:00:00Z' }), NOW)?.text).toContain('devon@example.com yesterday at 10:00 AM')
    expect(inviteLine(pending({ sent_at: '2026-09-24T15:00:00Z' }), NOW)?.text).not.toContain(' JA')
    expect(inviteLine(pending({ sent_at: '2026-09-22T15:00:00Z' }), NOW)?.text).toContain('devon@example.com on 22 Sep 2026')
  })

  it('a send that did not go says so, in the server\'s own words', () => {
    const line = inviteLine(pending({ status: 'not_sent', sent_at: null, reason: 'Nothing answered at the mail server’s address.' }), NOW)
    expect(line).toEqual({ text: 'Invite not sent. Nothing answered at the mail server’s address.', tone: 'bad' })
    expect(line?.text).not.toMatch(/emailed|sent to/i)
    expect(inviteLine(pending({ status: 'not_sent', reason: null }), NOW)?.text).toBe('Invite not sent. The mail server did not accept it.')
  })

  it('lapsed, cancelled, claimed and never sent each say what is true', () => {
    expect(inviteLine(pending({ status: 'expired' }), NOW)?.tone).toBe('warn')
    expect(inviteLine(pending({ status: 'cancelled' }), NOW)?.text).toBe('Invite cancelled.')
    expect(inviteLine(pending({ status: 'claimed' }), NOW)?.text).toMatch(/estate office confirms them next/)
    expect(inviteLine(pending({ status: 'none', email: null }), NOW)?.text).toBe('No invite has been sent to devon@example.com yet.')
    expect(inviteLine(pending({ status: 'none', email: null }, null), NOW)?.text).toMatch(/^No email address, so no invite can be sent/)
  })

  it('draws no line for a verified member, or where the server has not said', () => {
    expect(inviteLine({ ...pending({}), status: 'verified' }, NOW)).toBeNull()
    expect(inviteLine(pending(null), NOW)).toBeNull()
    expect(inviteLine({ status: 'pending', email: 'devon@example.com' }, NOW)).toBeNull() // a list saved before invites
  })

  it('counts down to the server\'s resend_after, and unlocks when it runs out', () => {
    expect(resendWait(invite({ resend_after: '2026-09-25T19:15:45Z' }), NOW)).toBe(105)
    expect(resendWait(invite({ resend_after: '2026-09-25T19:13:00Z' }), NOW)).toBe(0)
    expect(resendWait(invite({ resend_after: null }), NOW)).toBe(0)
    expect(resendWait(invite({ resend_after: 'not a time' }), NOW)).toBe(0)
    expect(mmss(105)).toBe('1:45')
    expect(mmss(300)).toBe('5:00')
    expect(mmss(9)).toBe('0:09')
  })

  it('Resend shows its wait on the button, and Cancel is offered only for an invite that stands', () => {
    expect(inviteActions(pending({ resend_after: '2026-09-25T19:15:45Z' }), NOW)).toEqual({ send: { label: 'Resend in 1:45', wait: 105 }, cancel: true })
    expect(inviteActions(pending({}), NOW)).toEqual({ send: { label: 'Resend invite', wait: 0 }, cancel: true })
    expect(inviteActions(pending({ status: 'not_sent' }), NOW).send?.label).toBe('Resend invite')
    // A new invite has no wait, whatever the old one's timer said.
    expect(inviteActions(pending({ status: 'cancelled', resend_after: '2026-09-25T19:15:45Z' }), NOW)).toEqual({ send: { label: 'Send invite', wait: 0 }, cancel: false })
    expect(inviteActions(pending({ status: 'none', email: null }), NOW)).toEqual({ send: { label: 'Send invite', wait: 0 }, cancel: false })
  })

  it('offers nothing where there is nothing to send: verified, claimed, or no address', () => {
    expect(inviteActions({ ...pending({}), status: 'verified' }, NOW)).toEqual({ send: null, cancel: false })
    expect(inviteActions(pending({ status: 'claimed' }), NOW)).toEqual({ send: null, cancel: false })
    expect(inviteActions(pending({ status: 'none', email: null }, null), NOW)).toEqual({ send: null, cancel: false })
    expect(inviteActions(pending(null), NOW)).toEqual({ send: null, cancel: false })
  })

  it('cancelling and sending say what they do, and nothing they do not', () => {
    const sentence = cancelInviteSentence('Devon Morgan')
    expect(sentence).toContain('Devon Morgan stays on your household as Pending')
    expect(sentence).toContain('cannot be taken back') // an email that arrived is not recalled
    expect(sentence).not.toMatch(/reminder|revoked|deleted/i)
    expect(INVITE_OFFLINE).toMatch(/needs a connection/)
  })
})

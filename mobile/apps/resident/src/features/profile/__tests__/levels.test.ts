/**
 * J-HH-1 — the household's pure logic after household invites, stage 2.
 *
 *   levels        the three, what each keeps, which one a relationship suggests, what narrowing takes away
 *   the pill      the LEVEL when the server sends one, else the relationship (an older server)
 *   the add       `access_level` carried; and NO invite from an add captured offline (D-158)
 *   the words     no line promises a claim form any more; a phone-only member is told invites go by email (D-157)
 *   contacts      one number, one contact, by the panic screen's own rule
 *   refusals      a level refusal is shown in the server's own words
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import {
  LEVELS, contactAlreadyListed, contactAlreadySentence, deniedSentence, inviteLine, levelFor, levelLabel, lostWith, memberBody, memberRows, memberTag, narrowing,
  type HouseholdOp, type Member,
} from '../household'

const NOW = Date.parse('2026-09-25T15:00:00Z')

const invite = (status: string, extra: Record<string, unknown> = {}) => ({ status, email: 'soft@example.com', sent_at: '2026-09-25T14:00:00Z', last_attempt_at: null, reason: null, expires_at: null, resend_after: null, attempts: 1, ...extra })

describe('levels', () => {
  it('are the three the server has, each with what it may do', () => {
    expect(LEVELS.map((l) => l.value)).toEqual(['member', 'dependent', 'helper'])
    expect(LEVELS.find((l) => l.value === 'helper')?.sentence).toContain("the household's emergency contacts to text or call from it")
    expect(levelLabel('dependent')).toBe('Dependent')
    expect(levelLabel('nonsense')).toBe('Member')
  })

  it('a relationship suggests one: the primary may choose another', () => {
    expect(levelFor('child')).toBe('dependent')
    expect(levelFor('domestic_staff')).toBe('helper')
    expect(levelFor('spouse')).toBe('member')
    expect(levelFor('')).toBe('member')
  })

  it('narrowing is anything that takes away; what is lost is said', () => {
    expect(narrowing('member', 'dependent')).toBe(true)
    expect(narrowing('dependent', 'helper')).toBe(true)
    expect(narrowing('helper', 'member')).toBe(false)
    expect(narrowing(undefined, 'helper')).toBe(true)
    expect(lostWith('dependent')).toBe('They lose visitor passes, dues, payments, meetings, voting and bookings at once. Panic and their own e-pass stay.')
  })
})

describe('the pill beside a member', () => {
  const member = { is_primary: false, relationship: 'child' }

  it('is "Primary", else the access level, never the relationship (M47 §14.1)', () => {
    expect(memberTag({ ...member, is_primary: true, access_level: 'member' })).toBe('Primary')
    expect(memberTag({ ...member, access_level: 'dependent' })).toBe('Dependent')
    expect(memberTag({ ...member, access_level: 'helper' })).toBe('Helper')
    // No level sent: the server's default, Member — not "Child".
    expect(memberTag(member)).toBe('Member')
  })
})

describe('adding a member', () => {
  const values = { full_name: '  Soft   Dev ', relationship: 'child', phone: '876 555 0148', email: 'Soft@Example.com', invite: true }

  it('carries the chosen level, and leaves it out when none was chosen', () => {
    expect(memberBody({ ...values, access_level: 'dependent' })).toEqual({ full_name: 'Soft Dev', relationship: 'child', phone: '+1 876 555 0148', email: 'soft@example.com', invite: true, access_level: 'dependent' })
    expect(memberBody(values)).not.toHaveProperty('access_level')
  })

  it('sends NO invite from an add captured offline, whatever the switch said (D-158)', () => {
    expect(memberBody(values, { online: false }).invite).toBe(false)
    expect(memberBody(values, { online: true }).invite).toBe(true)
    expect(memberBody({ ...values, invite: false }, { online: true }).invite).toBe(false)
  })

  it('a queued add is drawn at the level it was saved with', () => {
    const op: HouseholdOp = { id: 'op1', endpoint: 'members.store', body: { full_name: 'Soft Dev', relationship: 'child', access_level: 'dependent' }, state: 'pending', error: null, result: null }
    const [row] = memberRows([], [op])
    expect(memberTag(row!.item as Member & { access_level?: string })).toBe('Dependent')
  })
})

describe('what the invite line says', () => {
  const pending = { status: 'pending', email: 'soft@example.com' } as Pick<Member, 'status' | 'email'>

  it('no line promises a claim form: signing in with the address IS the claim', () => {
    for (const status of ['sent', 'not_sent', 'expired', 'cancelled', 'claimed', 'none']) {
      const line = inviteLine({ ...pending, invite: invite(status) as never }, NOW)?.text ?? ''
      expect(line).not.toMatch(/claim(ed)? your home/i)
    }
  })

  it('sent: the estate office confirms them, usually with one tap', () => {
    expect(inviteLine({ ...pending, invite: invite('sent') as never }, NOW)?.text).toBe(
      // M19A §2.3: "an hour ago" belongs to no clock — "at 9:00 AM" read from Pakistan looked broken.
      "Invite emailed to soft@example.com an hour ago. They sign in with that address; the estate office confirms them, usually with one tap (or at once, where the estate trusts its residents' invites).",
    )
  })

  it('claimed: they have signed in with that address', () => {
    expect(inviteLine({ ...pending, invite: invite('claimed') as never }, NOW)?.text).toBe('They have signed in with that address. The estate office confirms them next, usually with one tap.')
  })

  it('a member with a phone and no email is told invites go by email: true on every estate (D-157)', () => {
    const line = inviteLine({ status: 'pending', email: null, phone: '876 555 0148', invite: invite('none', { email: null }) as never }, NOW)
    expect(line?.text).toBe('Invites go by email for now: add an email to invite them.')
  })
})

describe('emergency contacts', () => {
  it('one number is one contact, however it was written', () => {
    expect(contactAlreadyListed('876-555-0148', [{ phone: '+1 876 555 0148' }])).toBe(true)
    expect(contactAlreadyListed('(876) 555 0148', [{ phone: '8765550148' }])).toBe(true)
    expect(contactAlreadyListed('876 555 0149', [{ phone: '876 555 0148' }])).toBe(false)
    expect(contactAlreadyListed('', [{ phone: '' }])).toBe(false)
    expect(contactAlreadySentence(' 876-555-0148 ')).toBe('876-555-0148 is already one of your emergency contacts.')
  })
})

describe('a refusal because of the level', () => {
  it('is shown in the server\'s own words', () => {
    const said = "Dues and payments are for the household's Members. Your access in the Fletcher household is Dependent: ask Andrea Fletcher, or the estate office, if that should change."
    expect(deniedSentence('not_for_your_access_level', said)).toBe(said)
    expect(deniedSentence('account_pending')).toContain('not linked to a home')
    expect(deniedSentence('something_else', 'anything')).toBe('Your account cannot see this household. Ask your estate office to check your access.')
  })
})

describe('the add-member screen', () => {
  it('no longer promises a claim form', () => {
    const source = readFileSync(join(__dirname, '..', 'screens', 'AddMemberScreen.tsx'), 'utf8')
    expect(source).not.toMatch(/claim your home/i)
    // A save with no connection sends no invite (D-158) — said after Save, never in a dialog (M47 U7).
    expect(source).toContain("Saved on this phone while offline, so no invite goes with it. Once they're added, send it from Profile.")
  })
})

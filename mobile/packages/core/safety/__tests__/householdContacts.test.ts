/**
 * THE HOUSEHOLD'S OWN EMERGENCY CONTACTS — one decision, from `sms.household` (s1-contacts, confirmed
 * by the lead 2026-09-24: `{ attempted, delivered, finished }`, or null on an officer's duress).
 *
 * M10 §1: "nothing may tell a resident in an emergency that a text went out when none did." Each case the
 * lead set is a test below, and so is the one this file adds: no provider, no "Texting…".
 */
import { CONTACTS_BEFORE_PRESS, CONTACTS_NOT_TEXTED, contactsLineFor, householdContactsLine, householdTexts, householdTextsDelivered, type ContactsAnswer } from '../index'

const OR_TEXT = 'Your emergency contacts are not texted automatically: call them, or text them from your own phone below.'

type Household = NonNullable<ContactsAnswer['sms']>['household']
const answer = (household: Household, provider: boolean | null = true): ContactsAnswer => ({ sms: { provider_connected: provider, household } })

/*
 * The lead, 2026-09-24: "The phone must not claim either state before the server has answered."
 */
describe('before the server has answered, neither state is claimed', () => {
  it('the arming card, before any press: true whether or not a provider is connected', () => {
    // M12 §2.5: the contacts are emailed too now, so the promise names both — and claims neither.
    expect(CONTACTS_BEFORE_PRESS).toBe('After you press, this screen tells you whether your emergency contacts were emailed or sent a text.')
  })

  it('an alert held on the phone, or slow to be answered: the screen will say, once it knows', () => {
    for (const none of [null, undefined]) {
      expect(householdTexts(none)).toEqual({ state: 'unanswered' })
      expect(householdContactsLine(none, 2)).toBe('When your alert gets through, this screen tells you whether your emergency contacts were emailed or sent a text. Call them now, or text them from your own phone below.')
      expect(householdContactsLine(none, 0)).toBe('When your alert gets through, this screen tells you whether your emergency contacts were emailed or sent a text. Call them now.')
    }
  })

  it('says neither "texted" nor "not texted": in any of those three sentences', () => {
    for (const line of [CONTACTS_BEFORE_PRESS, householdContactsLine(null, 2), householdContactsLine(null, 0)]) {
      expect(line).not.toMatch(/not texted|\d+ of your emergency contacts|Texting/i)
      expect(line).toMatch(/whether/)
    }
  })
})

describe('sms.household null or absent: today\'s line', () => {
  it('an answer saved before the field existed, and an officer\'s duress (null)', () => {
    for (const a of [{}, { sms: null }, { sms: { provider_connected: true } }, answer(null)] as ContactsAnswer[]) {
      expect(householdTexts(a)).toEqual({ state: 'unreported' })
      expect(householdContactsLine(a, 0)).toBe(CONTACTS_NOT_TEXTED)
      expect(householdContactsLine(a, 2)).toBe(OR_TEXT)
    }
    expect(CONTACTS_NOT_TEXTED).toBe('Your emergency contacts are not texted automatically: call them.')
  })
})

describe('not finished: "Texting your emergency contacts…"', () => {
  it('while the leg runs, whatever has been delivered so far', () => {
    for (const household of [{ attempted: 0, delivered: 0, finished: false }, { attempted: 2, delivered: 1, finished: false }, { attempted: 2, delivered: 0 }]) {
      expect(householdTexts(answer(household))).toEqual({ state: 'texting' })
      expect(householdContactsLine(answer(household), 0)).toBe('Texting your emergency contacts…')
      expect(householdContactsLine(answer(household), 2)).toBe('Texting your emergency contacts…')
    }
  })

  it('but never with no provider connected and nothing delivered: no text can be on its way', () => {
    // Today's platform: the log driver writes the message down and the leg finishes a moment later.
    const today = answer({ attempted: 0, delivered: 0, finished: false }, false)
    expect(householdTexts(today)).toEqual({ state: 'not_sent' })
    expect(householdContactsLine(today, 0)).toBe(CONTACTS_NOT_TEXTED)
    expect(householdContactsLine(today, 2)).toBe(OR_TEXT)
  })
})

describe('finished and delivered above zero: "N of your emergency contacts were sent a text."', () => {
  it('says SENT, a provider accepted it, and never "received"', () => {
    expect(householdContactsLine(answer({ attempted: 3, delivered: 2, finished: true }), 2)).toBe('2 of your emergency contacts were sent a text.')
    expect(householdContactsLine(answer({ attempted: 1, delivered: 1, finished: true }), 1)).toBe('1 of your emergency contacts was sent a text.')
    for (const n of [1, 2, 5]) expect(householdContactsLine(answer({ attempted: n, delivered: n, finished: true }), 0)).not.toMatch(/receiv|read|delivered to/i)
    expect(householdTextsDelivered(answer({ attempted: 3, delivered: 2, finished: true }))).toBe(2)
  })

  it('a text a provider accepted went, even if the provider has since been disconnected', () => {
    expect(householdContactsLine(answer({ attempted: 2, delivered: 2, finished: true }, false), 0)).toBe('2 of your emergency contacts were sent a text.')
  })

  it('reads `delivered`, never `attempted`: handed over is not sent', () => {
    expect(householdContactsLine(answer({ attempted: 4, delivered: 0, finished: true }), 0)).toBe(CONTACTS_NOT_TEXTED)
  })
})

describe('finished and delivered zero: today\'s honest line, with the "text them from your own phone" button', () => {
  it('with somebody to text, the line points at the button; with nobody, it does not', () => {
    const none = answer({ attempted: 0, delivered: 0, finished: true })
    expect(householdTexts(none)).toEqual({ state: 'not_sent' })
    expect(householdContactsLine(none, 2)).toBe(OR_TEXT)
    expect(householdContactsLine(none, 0)).toBe(CONTACTS_NOT_TEXTED)
    expect(householdTextsDelivered(none)).toBe(0)
  })

  it('a nonsense count is zero', () => {
    for (const bad of [-1, Number.NaN, Number.POSITIVE_INFINITY, '2', null]) {
      expect(householdContactsLine(answer({ attempted: 2, delivered: bad as number, finished: true }), 0)).toBe(CONTACTS_NOT_TEXTED)
    }
  })
})

describe('what it never mixes up', () => {
  it('the COMMUNITY\'s texts (`sms.delivered`) are never counted as the household\'s', () => {
    const community = { sms: { provider_connected: true, attempted: 4, delivered: 4, finished: true, household: null } } as ContactsAnswer
    expect(householdContactsLine(community, 0)).toBe(CONTACTS_NOT_TEXTED)
    const noHouseholdLeg = { sms: { provider_connected: true, attempted: 4, delivered: 4, finished: true } } as ContactsAnswer
    expect(householdContactsLine(noHouseholdLeg, 2)).toBe(OR_TEXT)
  })

  it('the decision takes the state, not the numbers, so a caller cannot skip a case', () => {
    expect(contactsLineFor({ state: 'unanswered' }, 1)).toMatch(/^When your alert gets through/)
    expect(contactsLineFor({ state: 'unreported' }, 1)).toBe(OR_TEXT)
    expect(contactsLineFor({ state: 'texting' }, 1)).toBe('Texting your emergency contacts…')
    expect(contactsLineFor({ state: 'sent', delivered: 3 }, 1)).toBe('3 of your emergency contacts were sent a text.')
    expect(contactsLineFor({ state: 'sent', delivered: 0 }, 1)).toBe(OR_TEXT)
    expect(contactsLineFor({ state: 'not_sent' }, 0)).toBe(CONTACTS_NOT_TEXTED)
  })
})

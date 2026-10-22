import { readFileSync } from 'node:fs'
import path from 'node:path'
import { CONTACTS_BEFORE_PRESS, CONTACTS_NOT_TEXTED, callLink, contactsNotTexted, dialable, numberKey, smsBody, smsLink, uniqueContacts, WHATSAPP_NOTE } from '../panicSms'

const WHO = { name: 'Andrea Fletcher', unit: 'Lot 47', estate: 'Phoenix Park Village 1' }
const CONTACTS = [{ name: 'Mark Fletcher', phone: '(876) 555-0142' }, { name: 'Dr. Ann Lee', phone: '+1 876 555 0199' }]

describe('the no-data fallback for a panic: a text from the phone itself', () => {
  it('says who, where and when, and that it is an emergency', () => {
    expect(smsBody(WHO, '9:41 AM')).toBe('EMERGENCY: I need help. Andrea Fletcher, Lot 47, Phoenix Park Village 1. Sent 9:41 AM from the GeminiSecure app.')
    expect(smsBody({}, '9:41 AM')).toBe('EMERGENCY: I need help. Sent 9:41 AM from the GeminiSecure app.')
  })

  it('opens the Messages app addressed to every contact: Android\'s way and iPhone\'s way', () => {
    const body = smsBody(WHO, '9:41 AM')
    // Android: RFC 5724's comma list. iPhone: its own `open?addresses=` form, which is the only one it reads as a group.
    expect(smsLink(CONTACTS, body, 'android')).toBe(`sms:8765550142,+18765550199?body=${encodeURIComponent(body)}`)
    expect(smsLink(CONTACTS, body, 'ios')).toBe(`sms://open?addresses=8765550142,+18765550199&body=${encodeURIComponent(body)}`)
    // One person on an iPhone keeps the plain form.
    expect(smsLink([CONTACTS[0]!], body, 'ios')).toBe(`sms:8765550142&body=${encodeURIComponent(body)}`)
  })

  it('the words survive the trip: nothing in the body can break out of the link', () => {
    const link = smsLink(CONTACTS, smsBody({ name: 'A & B?=#', unit: 'Lot 4/7' }, '9:41 AM'), 'android')!
    const body = decodeURIComponent(link.split('body=')[1]!)
    expect(body).toContain('A & B?=#, Lot 4/7')
    expect(link.split('?')).toHaveLength(2)
  })

  it('a number that cannot be dialled is left out; with nobody to text there is NO link (and no button)', () => {
    expect(dialable('555')).toBeNull()
    expect(dialable('n/a')).toBeNull()
    expect(dialable('+1 (876) 555-0142')).toBe('+18765550142')
    expect(dialable('876+555+0142')).toBe('8765550142')
    expect(smsLink([{ name: 'x', phone: 'n/a' }, CONTACTS[0]!], 'help', 'android')).toBe('sms:8765550142?body=help')
    expect(smsLink([{ name: 'x', phone: 'n/a' }], 'help', 'android')).toBeNull()
    expect(smsLink([], 'help', 'ios')).toBeNull()
  })
})

describe('M01: each person once, and the resident chooses who', () => {
  // The user's own household: five contacts, four of them the same spouse.
  const HOUSEHOLD = [
    { name: 'Andre Brown', phone: '876-555-0101', relationship: 'Brother' },
    { name: 'Marcia Ahmed', phone: '876-555-0148', relationship: 'Spouse' },
    { name: 'Marcia Ahmed', phone: '(876) 555 0148', relationship: 'Spouse' },
    { name: 'Marcia A.', phone: '+1 876 555 0148', relationship: 'Wife' },
    { name: 'Marcia Ahmed', phone: '18765550148', relationship: 'Spouse' },
  ]

  it('the same number however it was written is one phone', () => {
    expect(numberKey('876-555-0148')).toBe(numberKey('+1 (876) 555-0148'))
    expect(numberKey('18765550148')).toBe('8765550148')
    expect(numberKey('876-555-0101')).not.toBe(numberKey('876-555-0148'))
  })

  it('five entries, two people: the list and the count are of people, the first name and relationship kept', () => {
    const people = uniqueContacts(HOUSEHOLD)
    expect(people).toHaveLength(2)
    expect(people.map((p) => [p.name, p.relationship])).toEqual([['Andre Brown', 'Brother'], ['Marcia Ahmed', 'Spouse']])
  })

  it('texting them all addresses each phone once', () => {
    expect(smsLink(HOUSEHOLD, 'help', 'android')).toBe('sms:8765550101,8765550148?body=help')
  })

  it('Call is the phone’s own dialler, for one person', () => {
    expect(callLink('(876) 555-0148')).toBe('tel:8765550148')
    expect(callLink('n/a')).toBeNull()
  })

  it('the buttons open the choosing sheet, over the panic screen, and the sheet sends through the same link', () => {
    const dir = path.resolve(__dirname, '..')
    const screen = readFileSync(path.join(dir, 'screens', 'PanicScreen.tsx'), 'utf8')
    const sheet = readFileSync(path.join(dir, 'components', 'ContactsSheet.tsx'), 'utf8')
    expect(screen).toContain('<ContactsSheet')
    expect(screen).toMatch(/onText=\{\(chosen\) => \{\s*setContactsOpen\(false\)\s*m\.textContacts\(chosen\)/)
    // A modal, never below the fold; everyone ticked each time it opens; Text and Call beside each; 119 in it too.
    expect(sheet).toMatch(/<Modal visible=\{visible\} transparent/)
    expect(sheet).toContain('if (visible) setChosen(new Set(contacts.map((x) => numberKey(x.phone))))')
    expect(sheet).toContain('role="checkbox" aria-checked={on}')
    expect(sheet).toContain('onPress={() => onText([contact])}')
    expect(sheet).toContain('onPress={() => onCall(contact)}')
    expect(sheet).toContain('onPress={onCallEmergency}')
    expect(sheet).toContain('{WHATSAPP_NOTE}')
    expect(WHATSAPP_NOTE).toMatch(/one person at a time/)
  })
})

describe('it is never passed off as something it is not', () => {
  const dir = path.resolve(__dirname, '..')
  const screen = readFileSync(path.join(dir, 'screens', 'PanicScreen.tsx'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
  const hook = readFileSync(path.join(dir, 'usePanic.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

  it('the screen never claims a text reached the control room, dispatch or security', () => {
    expect(screen).not.toMatch(/(sent|texted|SMS)[^'"`\n]{0,40}(to|reached)[^'"`\n]{0,20}(dispatch|control room|security|the gate)/i)
  })

  it('the emergency number is a plain tel: link that nothing can disable', () => {
    expect(screen + hook).toMatch(/tel:/)
    expect(screen).not.toMatch(/tel:[^\n]*\n?[^\n]*disabled=\{/)
  })

  it('the text goes through the phone\'s own Messages app: the app sends no SMS itself', () => {
    expect(hook).toMatch(/smsLink\(/)
    expect(hook + screen).not.toMatch(/expo-sms|sendSMSAsync|twilio/i)
  })
})

/*
 * M10 §1: "nothing may tell a resident in an emergency that a text went out when none did." No SMS
 * provider is connected, and no server code texts a household's emergency contacts at all.
 */
describe('nobody is told their family was texted', () => {
  const dir = path.resolve(__dirname, '..')
  const strip = (s: string) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '').replace(/^\s*\/\/.*$/gm, '')
  const screen = strip(readFileSync(path.join(dir, 'screens', 'PanicScreen.tsx'), 'utf8'))
  const hook = strip(readFileSync(path.join(dir, 'usePanic.ts'), 'utf8'))
  const copy = strip(readFileSync(path.join(dir, 'panicSms.ts'), 'utf8')) + strip(readFileSync(path.join(dir, 'panicType.ts'), 'utf8'))

  it('says so plainly, with the one route that exists when there is one', () => {
    expect(CONTACTS_NOT_TEXTED).toBe('Your emergency contacts are not texted automatically: call them.')
    expect(contactsNotTexted(0)).toBe(CONTACTS_NOT_TEXTED)
    expect(contactsNotTexted(2)).toMatch(/^Your emergency contacts are not texted automatically: call them, or text them from your own phone below\.$/)
  })

  it('the rebuilt board says nothing about the household’s contacts, so it can never say they were texted (M42 §5 #5)', () => {
    // The ONE decision (packages/core/safety/householdContacts.ts), fed the server's answer, stays in the hook for the
    // day a screen draws it again; the board does not, so no contacts line is drawn in any phase.
    expect(hook).toMatch(/contactsLine: householdContactsLine\(a, contacts\.length\)/)
    expect(screen).not.toMatch(/contactsLine|contactsBlock|help\.contacts|CONTACTS_BEFORE_PRESS|CONTACTS_NOT_TEXTED/)
    expect(CONTACTS_BEFORE_PRESS).toBe('After you press, this screen tells you whether your emergency contacts were emailed or sent a text.')
  })

  it('the only "text" button opens the phone\'s own Messages app, offered only while the alert is held with no signal, and only with someone to text', () => {
    // One button — the offline fallback, beside the call button — opens the choosing sheet (M01), which then opens the
    // Messages app through `m.textContacts`.
    expect(screen.match(/onPress=\{openContacts\}/g)?.length).toBe(1)
    expect(screen.match(/phase === 'queued' && m\.contacts\.length > 0 \? \(\s*<Pressable onPress=\{openContacts\}/g)?.length).toBe(1)
    expect(screen.match(/m\.textContacts\(/g)?.length).toBe(1)
  })

  it('no copy on the panic screen says a text or an SMS was sent', () => {
    const SENT = /\b(texted|sent (a|an|the)? ?(text|sms)|text (message )?(was|were|has been|have been) sent|sms (was|were|has been) sent|we(’|')?ve texted|we texted)\b/i
    for (const [name, src] of [['PanicScreen', screen], ['usePanic', hook], ['panicSms/panicType', copy]] as const) {
      const lines = src.split('\n').filter((line) => SENT.test(line) && !/not texted/i.test(line))
      expect([name, lines]).toEqual([name, []])
    }
  })
})

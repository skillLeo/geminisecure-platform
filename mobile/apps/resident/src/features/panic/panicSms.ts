/**
 * The no-data fallback for a panic — pure, so it can be proven.
 *
 * The platform has no SMS provider and no control-room SMS number, so the app cannot
 * text dispatch and never says it did. What it CAN do with no data connection is open the phone's own
 * Messages app, addressed to the household's emergency contacts, with the words already written — the
 * resident presses send, and it goes over the mobile network like any text. The screen calls it exactly
 * that: "Text my emergency contacts". The alert itself stays saved and goes to the control room the
 * moment there is a connection; 119 is one tap away throughout.
 *
 * NOTHING SAYS THE HOUSEHOLD'S CONTACTS WERE TEXTED UNLESS THE SERVER SAYS A PROVIDER ACCEPTED IT (M10 §1:
 * "nothing may tell a resident in an emergency that a text went out when none did"). The server texts
 * them on a resident's panic (s1-contacts) and reports it as `sms.household`; with no SMS provider
 * connected — today — nothing goes, and the screen says they are not texted automatically, in every
 * state, beside the one route that does exist: a text from the resident's own phone.
 *
 * THE SENTENCE IS DECIDED IN ONE PLACE, `@gs/core/safety`'s `householdContactsLine`. These two are its
 * answer when nothing was delivered, kept for the arming card and for the tests.
 */

import { CONTACTS_BEFORE_PRESS, CONTACTS_NOT_TEXTED, contactsLineFor } from '@gs/core/safety'

/** `CONTACTS_BEFORE_PRESS` is the arming card's: before the press the phone cannot know either way, so it claims neither. */
export { CONTACTS_BEFORE_PRESS, CONTACTS_NOT_TEXTED }

/** The sentence beside the "Text my emergency contacts" button — which is only drawn when somebody can be texted — while nothing was delivered. */
export const contactsNotTexted = (textable: number): string => contactsLineFor({ state: 'not_sent' }, textable)

export type SmsContact = { name: string; phone: string; relationship?: string | null }

/** Digits and a leading +, nothing else. A number with fewer than 7 digits is not a number. */
export const dialable = (phone: string): string | null => {
  const clean = phone.replace(/[^\d+]/g, '').replace(/(?!^)\+/g, '')
  return clean.replace(/\D/g, '').length >= 7 ? clean : null
}

/**
 * The same NUMBER however it was written: "876-555-0148", "(876) 555 0148" and "+1 876 555 0148" are one phone.
 * Digits only, and the North American country code dropped from an eleven-digit 1-number, which is how a Jamaican
 * number is written both ways.
 */
export const numberKey = (phone: string): string => {
  const digits = phone.replace(/\D/g, '')
  return digits.length === 11 && digits.startsWith('1') ? digits.slice(1) : digits
}

/**
 * EACH PERSON ONCE (M01). A household that entered the same spouse four times was offered "Text my emergency
 * contacts (5)" and a text to the same phone four times over; the count and the list are now of people's phones.
 * Only numbers that can be dialled, the first name and relationship kept for each.
 */
export function uniqueContacts(contacts: readonly SmsContact[]): SmsContact[] {
  const seen = new Set<string>()
  const out: SmsContact[] = []
  for (const c of contacts) {
    if (!c.phone || dialable(String(c.phone)) === null) continue
    const key = numberKey(String(c.phone))
    if (seen.has(key)) continue
    seen.add(key)
    out.push({ name: c.name, phone: String(c.phone), relationship: c.relationship ?? null })
  }
  return out
}

type ContactLike = { name: string; phone?: string | number | null; relationship?: string | null }

/**
 * WHO THE PANIC SCREEN TEXTS AND CALLS, AT EVERY ACCESS LEVEL (household invites, G2). Panic is never taken
 * from anybody, and a Helper may not read `me.household` — so the household's contacts come from
 * `panic.contacts`, which every level reads. When that has never answered (an older server, which has no such
 * endpoint), the household's own list is used as before. Each person's phone once, as always (`uniqueContacts`).
 */
export function panicContactsOf(fromPanic: readonly ContactLike[] | null | undefined, fromHousehold: readonly ContactLike[] | null | undefined): SmsContact[] {
  const source = fromPanic ?? fromHousehold ?? []
  return uniqueContacts(source.map((c) => ({ name: c.name, phone: String(c.phone ?? ''), relationship: c.relationship ?? null })))
}

/** What the selection sheet says about WhatsApp, which the phone may offer when a text is opened (M01). */
export const WHATSAPP_NOTE = 'WhatsApp can only send to one person at a time. To text everyone at once, choose Messages, or text each person here.'

/** One person, on the phone's own dialler. */
export const callLink = (phone: string): string | null => {
  const n = dialable(phone)
  return n ? `tel:${n}` : null
}

export function smsBody(who: { name?: string | null; unit?: string | null; estate?: string | null }, pressedAt: string): string {
  const where = [who.name, who.unit, who.estate].filter(Boolean).join(', ')
  return `EMERGENCY: I need help.${where ? ` ${where}.` : ''} Sent ${pressedAt} from the GeminiSecure app.`
}

/**
 * The link that opens the Messages app, addressed to every one of these people once, with the words written.
 * Null when nobody can be texted — the button is then not offered at all.
 *
 *   Android   RFC 5724: `sms:+1…,+1…?body=…` — recipients separated by commas. It used ";", the old Samsung
 *             Messages habit; Google Messages, now the default on Samsung phones too, reads the standard.
 *   iPhone    one person: `sms:+1…&body=…`. More than one: `sms://open?addresses=+1…,+1…&body=…` — iOS does not
 *             implement RFC 5724's list, and read the first number of one as the whole address.
 *
 * A messaging app that still takes only the first number is why the sheet offers Text beside each person too.
 */
export function smsLink(contacts: readonly SmsContact[], body: string, platform: 'ios' | 'android' | string): string | null {
  const to = uniqueContacts(contacts).map((c) => dialable(c.phone)).filter((n): n is string => n !== null)
  if (to.length === 0) return null
  const text = encodeURIComponent(body)
  if (platform === 'ios') return to.length === 1 ? `sms:${to[0]}&body=${text}` : `sms://open?addresses=${to.join(',')}&body=${text}`
  return `sms:${to.join(',')}?body=${text}`
}

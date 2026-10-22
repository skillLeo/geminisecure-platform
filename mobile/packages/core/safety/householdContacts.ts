/**
 * WHAT THE PANIC SCREEN SAYS ABOUT THE HOUSEHOLD'S OWN EMERGENCY CONTACTS — decided here, and only here.
 *
 * The server texts the household's nominated contacts on a resident's panic (M10 §1.2 item 7, s1-contacts)
 * and reports it on `duress.store` / `show` / `cancel` as `sms.household` — `{ attempted, delivered,
 * finished }`, or null on an officer's duress, which texts no household. Confirmed by the lead,
 * 2026-09-24. The rules, each a case in the tests:
 *
 *   no answer from the server yet         "When your alert gets through, this screen tells you whether your
 *                                         emergency contacts were sent a text" — neither state is claimed.
 *   `sms.household` null or absent        today's line: "Your emergency contacts are not texted
 *                                         automatically — call them". An answer saved before the field
 *                                         existed is here.
 *   not `finished`                        "Texting your emergency contacts…" — the texting is running and
 *                                         nothing is claimed yet.
 *   `finished`, `delivered` above zero    "N of your emergency contacts were sent a text." SENT — a provider
 *                                         ACCEPTED it — and never "received": the carrier and the phone are
 *                                         downstream of anything the platform can know.
 *   `finished`, `delivered` zero          today's honest line, with the "text them from your own phone"
 *                                         button beside it whenever there is somebody to text.
 *
 * AND NO PROVIDER, NO "TEXTING". With `sms.provider_connected: false` and nothing delivered, the answer is
 * today's line even before the leg has finished: no text can be on its way, so "Texting…" would be the one
 * claim this file exists to stop. It is the server's own order (`PanicSmsOutcome`: no provider means no
 * text, whatever else is true — but a text a provider accepted went, even if the provider has since gone).
 *
 * `attempted` is never read, for the reason `sms_recipients` is not: a number handed over is not a text
 * that went. The COMMUNITY's texts (`sms.delivered`) are a different audience and are never counted here.
 *
 * BEFORE THE SERVER HAS ANSWERED, NEITHER STATE IS CLAIMED (the lead, 2026-09-24). Before the press, and
 * while an alert is held on the phone or slow to be answered, the phone cannot know whether a provider is
 * connected or whether the server — which may already have the alert — is texting. So the arming card says
 * `CONTACTS_BEFORE_PRESS`, and a held alert says the screen will tell them once it gets through; neither
 * says "texted" or "not texted". The "text them from your own phone" button stays wherever it was.
 *
 * Pure: no clock, no network.
 */

/** The part of a duress answer this file reads. */
export type ContactsAnswer = {
  sms?: {
    provider_connected?: boolean | null
    household?: { attempted?: number | null; delivered?: number | null; finished?: boolean | null } | null
  } | null
  /** M12 §2.5: the same contacts by EMAIL — `sent` is what the mail server accepted. */
  email?: {
    household?: {
      recipients?: number | null
      sent?: number | null
      failed?: number | null
      finished?: boolean | null
      /** M18C §4.3: of `sent`, how many went to the household's own members. */
      members_sent?: number | null
      contacts_sent?: number | null
    } | null
  } | null
}

/**
 * WHERE THE HOUSEHOLD'S EMAILS STAND (M12 §2.5) — by the texts' rules. "Emailed" is said only from `sent`, a count of
 * mails the mail server ACCEPTED; never from `recipients`, which is how many were queued.
 */
export type HouseholdEmails =
  /** No answer from the server, an older server, or an officer's duress: nothing is said about email. */
  | { state: 'unreported' }
  /** The fan-out has not reached the leg yet, or the mails are being sent and none has been accepted. */
  | { state: 'emailing' }
  /** Nobody on file has an email address. */
  | { state: 'none' }
  | { state: 'sent'; sent: number; recipients: number; finished: boolean }
  /** Every mail refused, or every address gone: nothing went. */
  | { state: 'not_sent' }

export function householdEmails(answer: ContactsAnswer | null | undefined): HouseholdEmails {
  const email = answer !== null && typeof answer === 'object' && answer.email !== null && typeof answer.email === 'object' ? answer.email : null
  const household = email?.household !== null && typeof email?.household === 'object' ? email.household : null
  if (!household) return { state: 'unreported' }
  if (household.recipients === null || household.recipients === undefined) return { state: 'emailing' }
  const recipients = count(household.recipients)
  if (recipients === 0) return { state: 'none' }
  const sent = count(household.sent)
  if (sent > 0) return { state: 'sent', sent, recipients, finished: household.finished === true }
  return household.finished === true ? { state: 'not_sent' } : { state: 'emailing' }
}

/** How many of the household's contacts the mail server accepted an email for. 0 unless it positively says so. */
export const householdEmailsSent = (answer: ContactsAnswer | null | undefined): number => {
  const emails = householdEmails(answer)
  return emails.state === 'sent' ? emails.sent : 0
}

/** Where the household's texting stands, as the server reported it. */
export type HouseholdTexts =
  /** The server has not answered at all (held on the phone, or slow): nothing can be said either way. */
  | { state: 'unanswered' }
  /** An answer with no `sms.household` (saved before the field existed, or an officer's duress): today's line. */
  | { state: 'unreported' }
  | { state: 'texting' }
  | { state: 'sent'; delivered: number }
  /** Finished with nothing delivered — or no provider connected and nothing delivered. */
  | { state: 'not_sent' }

const count = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.trunc(value) : 0)

export function householdTexts(answer: ContactsAnswer | null | undefined): HouseholdTexts {
  if (answer === null || answer === undefined || typeof answer !== 'object') return { state: 'unanswered' }
  const sms = answer?.sms !== null && typeof answer?.sms === 'object' ? answer.sms : null
  const household = sms?.household !== null && typeof sms?.household === 'object' ? sms.household : null
  if (!household) return { state: 'unreported' }
  const delivered = count(household.delivered)
  if (delivered === 0 && sms?.provider_connected === false) return { state: 'not_sent' }
  if (household.finished !== true) return { state: 'texting' }
  return delivered > 0 ? { state: 'sent', delivered } : { state: 'not_sent' }
}

/** How many of the household's contacts a provider ACCEPTED a text for, once the texting has finished. 0 otherwise. */
export function householdTextsDelivered(answer: ContactsAnswer | null | undefined): number {
  const texts = householdTexts(answer)
  return texts.state === 'sent' ? texts.delivered : 0
}

/** Today's line, once the server has answered, wherever there is nobody the resident can text from their own phone. */
export const CONTACTS_NOT_TEXTED = 'Your emergency contacts are not texted automatically: call them.'

/** The arming card, before any press: true whether or not a provider is connected, and claiming neither. */
export const CONTACTS_BEFORE_PRESS = 'After you press, this screen tells you whether your emergency contacts were emailed or sent a text.'

/** A held or slow alert: the server may or may not have it yet, so the screen promises only to say, once it knows. */
const CONTACTS_UNANSWERED = 'When your alert gets through, this screen tells you whether your emergency contacts were emailed or sent a text.'

/** Today's line beside the "Text my emergency contacts" button, which is drawn only when somebody can be texted. */
const CONTACTS_NOT_TEXTED_OR_TEXT = 'Your emergency contacts are not texted automatically: call them, or text them from your own phone below.'

const were = (n: number): string => `${n} of your emergency contacts ${n === 1 ? 'was' : 'were'}`

/**
 * THE DECISION.
 *
 * EMAIL FIRST WHEN ONE WENT (M12 §2.5): it is the channel that works today. "Emailed" only from what the mail server
 * accepted; a text only from what a provider accepted; neither said of the other.
 *
 * @param texts    where the household's texting stands (`householdTexts`)
 * @param textable how many of the contacts the resident can text from their own phone (the button below)
 * @param emails   where the household's emails stand (`householdEmails`); left out, as before M12
 */
export function contactsLineFor(texts: HouseholdTexts, textable: number, emails: HouseholdEmails = { state: 'unreported' }): string {
  const texted = texts.state === 'sent' && count(texts.delivered) > 0 ? count(texts.delivered) : 0
  const others = textable > 0 ? 'Call the others, or text them from your own phone below.' : 'Call the others.'

  if (emails.state === 'sent') {
    const lines = [`${were(emails.sent)} emailed.`]
    if (texted > 0) lines.push(`${were(texted)} also sent a text.`)
    if (!emails.finished) lines.push('Emailing the rest…')
    else if (emails.sent < emails.recipients) lines.push(`The rest could not be emailed. ${others}`)
    return lines.join(' ')
  }
  if (texted > 0) return `${were(texted)} sent a text.`
  if (emails.state === 'emailing' && texts.state !== 'unanswered') return 'Emailing your emergency contacts…'
  if (emails.state === 'not_sent') return `Your emergency contacts could not be emailed: call them${textable > 0 ? ', or text them from your own phone below' : ''}.`
  if (texts.state === 'texting') return 'Texting your emergency contacts…'
  if (texts.state === 'unanswered') return `${CONTACTS_UNANSWERED} ${textable > 0 ? 'Call them now, or text them from your own phone below.' : 'Call them now.'}`
  return textable > 0 ? CONTACTS_NOT_TEXTED_OR_TEXT : CONTACTS_NOT_TEXTED
}

/** The sentence for an alert the server has answered — or for one it has not (`answer` null: held or slow). */
export const householdContactsLine = (answer: ContactsAnswer | null | undefined, textable: number): string =>
  contactsLineFor(householdTexts(answer), textable, householdEmails(answer))

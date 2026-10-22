/**
 * WHAT THE PANIC SCREEN SAYS TO CALM SOMEBODY DOWN — and the line it never crosses (M12 §2.4).
 *
 * "Rewrite the panic status screen to reassure. 'All relevant people have been notified', 'Officers alerted',
 * 'Waiting for dispatch to acknowledge', 'Call 119 if you can'. The limit that does not move: never say an officer
 * is on the way when none was reached. Say what is true — the control room has it, the escalation contact was
 * emailed — and keep 119 prominent. A sentence that soothes someone into not calling for help is the one failure
 * this product cannot have."
 *
 * So every line below is a FACT the server recorded, ticked only when it recorded it:
 *
 *   the control room has it      from the moment the alert exists (`console_alerted`)
 *   officers alerted             only from `alerted_handsets` > 0 — "reaching" while the count is not in, and
 *                                "no officer's phone could be reached" when it is 0, said plainly
 *   the estate was emailed       `escalated_contacts` > 0 — its Head of Security and committee
 *   your contacts were emailed   the mail server's accepted count (`householdEmails`), never the queue
 *   dispatch has seen it         `acknowledged_at`; before that, "waiting for dispatch to acknowledge"
 *   an officer is on the way     `responseHeadline`'s own rule: only once an officer is RECORDED as sent or going
 *
 * "ALL RELEVANT PEOPLE HAVE BEEN NOTIFIED" is the title only when every one of those that can be true is: officers'
 * phones reached, the control room, the estate emailed, and the household's contacts emailed (or nobody on file to
 * tell). Otherwise the title is the strongest thing that IS true — "Officers alerted", "Your alert is recorded" —
 * and when no officer was reached it stays "No officer’s phone was reached".
 *
 * AND 119, EVERY TIME. "Call 119 if you can" while officers were reached — gentle, never "you do not need to" —
 * and "Call 119 now" when none was. The screen's call button is in every state; this is the sentence beside it.
 *
 * Pure: no clock, no network.
 */

import type { AlertReach } from './alertReach'
import { responseHeadline, recorded, isClosed, type ProgressFields } from './alertProgress'
import { householdEmails, householdTexts, type ContactsAnswer } from './householdContacts'

export type ReassuranceLine = { text: string; state: 'done' | 'waiting' | 'missing' }

export type Reassurance = {
  title: string
  /** One calm sentence under the title: what to do now. */
  lead: string
  lines: ReassuranceLine[]
  /** The sentence beside the call button — "if you can" or "now", never "you do not need to". */
  call: string
  /** True only when the title is "All relevant people have been notified". */
  everyoneTold: boolean
}

export const ALL_NOTIFIED = 'All relevant people have been notified'

type Answer = ProgressFields & ContactsAnswer & { console_alerted?: boolean | null; escalated_contacts?: number | null }

const count = (value: unknown): number => (typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.trunc(value) : 0)

/**
 * @param answer          the server's read-back of the alert
 * @param reach           `alertReach` of the same answer
 * @param contactsOnFile  how many emergency contacts this phone knows the household has (0: nobody to tell)
 * @param emergencyNumber the number the call button dials (119 in Jamaica)
 */
export function panicReassurance(answer: Answer | null | undefined, reach: AlertReach, contactsOnFile: number, emergencyNumber: string): Reassurance | null {
  if (!answer || isClosed(answer)) return null

  const headline = responseHeadline(answer)
  const lines: ReassuranceLine[] = []

  lines.push(reach.consoleAlerted ? { text: 'The control room has your alert', state: 'done' } : { text: 'Reaching the control room', state: 'waiting' })

  // M18 §1.6: nobody on duty at the raise, so every officer is being rung — said as that, never as "on duty".
  if (reach.state === 'reached' && reach.viaRing) lines.push({ text: `Every officer being rung: ${reach.handsets} phone${reach.handsets === 1 ? '' : 's'} at your community`, state: 'done' })
  else if (reach.state === 'reached') lines.push({ text: `Officers alerted: ${reach.handsets} phone${reach.handsets === 1 ? '' : 's'} at your community`, state: 'done' })
  else if (reach.state === 'reaching') lines.push({ text: 'Alerting your community’s officers', state: 'waiting' })
  else lines.push({ text: 'No officer’s phone could be reached', state: 'missing' })

  const escalated = count(answer.escalated_contacts)
  if (escalated > 0) lines.push({ text: 'Your estate’s security and committee have been emailed', state: 'done' })

  const emails = householdEmails(answer)
  const texts = householdTexts(answer)
  const texted = texts.state === 'sent' ? count(texts.delivered) : 0
  let contactsTold = contactsOnFile === 0
  if (emails.state === 'sent') {
    lines.push({ text: `${emails.sent} of your emergency contacts ${emails.sent === 1 ? 'was' : 'were'} emailed`, state: 'done' })
    contactsTold = emails.finished && emails.sent >= emails.recipients
  } else if (texted > 0) {
    lines.push({ text: `${texted} of your emergency contacts ${texted === 1 ? 'was' : 'were'} sent a text`, state: 'done' })
    contactsTold = true
  } else if (emails.state === 'emailing') {
    lines.push({ text: 'Emailing your emergency contacts', state: 'waiting' })
  } else if (contactsOnFile > 0) {
    lines.push({ text: 'Your emergency contacts were not told automatically: call them', state: 'missing' })
  }

  const acknowledged = recorded(answer.acknowledged_at) !== null
  lines.push(acknowledged ? { text: 'Dispatch has seen your alert', state: 'done' } : { text: 'Waiting for dispatch to acknowledge', state: 'waiting' })

  // An officer on the way: responseHeadline's own words, only when it says an officer is recorded as going.
  if (headline?.officer) lines.push({ text: headline.headline, state: 'done' })

  const everyoneTold = reach.state === 'reached' && reach.consoleAlerted && escalated > 0 && contactsTold

  if (reach.state === 'nobody') {
    return {
      title: 'No officer’s phone was reached',
      lead: headline?.officer ? headline.headline : 'Your alert is recorded, but no officer’s phone could be reached.',
      lines,
      call: `Call ${emergencyNumber} now: do not wait for this.`,
      everyoneTold: false,
    }
  }

  return {
    title: headline?.officer ? headline.headline : everyoneTold ? ALL_NOTIFIED : reach.state === 'reached' ? (reach.viaRing ? 'Every officer is being rung' : 'Officers alerted') : 'Your alert is recorded',
    lead: 'Stay somewhere safe and keep your phone with you: the control room may call you.',
    lines,
    call: `Call ${emergencyNumber} if you can.`,
    everyoneTold,
  }
}

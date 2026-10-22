/**
 * THE PANIC SCREEN REASSURES — AND NEVER PROMISES HELP THAT DID NOT GO OUT (M12 §2.4, §2.5).
 *
 * "Never say an officer is on the way when none was reached. Say what is true — the control room has it, the
 * escalation contact was emailed — and keep 119 prominent." Every case below is one of the states a resident can be
 * looking at, and what each is allowed to say.
 */

import { alertReach } from '../alertReach'
import { contactsLineFor, householdContactsLine, householdEmails } from '../householdContacts'
import { ALL_NOTIFIED, panicReassurance } from '../reassurance'

const BASE = { status: 'open', acknowledged_at: null, dispatched_at: null, en_route_at: null, on_scene_at: null, resolved_at: null, cancelled_at: null, console_alerted: true }
const emailed = (sent: number, recipients = sent, finished = true) => ({ email: { household: { recipients, sent, failed: recipients - sent, finished } } })
const says = (r: ReturnType<typeof panicReassurance>) => [r?.title, r?.lead, ...(r?.lines ?? []).map((l) => l.text), r?.call].join(' | ')
const ON_THE_WAY = /on the way|on their way|coming|help is/i

describe('what the screen may say, state by state', () => {
  it('officers reached, the estate emailed, the contacts emailed: "All relevant people have been notified", and still "Call 119 if you can"', () => {
    const answer = { ...BASE, alerted_handsets: 3, alerted_via: 'on_duty', escalated_contacts: 2, ...emailed(2) }
    const r = panicReassurance(answer, alertReach(answer, '119'), 2, '119')!
    expect(r.title).toBe(ALL_NOTIFIED)
    expect(r.everyoneTold).toBe(true)
    expect(r.lines.map((l) => [l.text, l.state])).toEqual([
      ['The control room has your alert', 'done'],
      ['Officers alerted: 3 phones at your community', 'done'],
      ['Your estate’s security and committee have been emailed', 'done'],
      ['2 of your emergency contacts were emailed', 'done'],
      ['Waiting for dispatch to acknowledge', 'waiting'],
    ])
    expect(r.call).toBe('Call 119 if you can.')
    // Notified is not "on the way": nothing says anybody is coming before an officer is recorded as sent.
    expect(says(r)).not.toMatch(ON_THE_WAY)
  })

  it('officers reached but the contacts not yet emailed: "Officers alerted", never "all notified"', () => {
    const answer = { ...BASE, alerted_handsets: 1, escalated_contacts: 1, email: { household: { recipients: 2, sent: 0, failed: 0, finished: false } } }
    const r = panicReassurance(answer, alertReach(answer, '119'), 2, '119')!
    expect(r.title).toBe('Officers alerted')
    expect(r.everyoneTold).toBe(false)
    expect(r.lines).toContainEqual({ text: 'Emailing your emergency contacts', state: 'waiting' })
  })

  it('the count not in yet: "Your alert is recorded", the officers still being alerted', () => {
    const answer = { ...BASE, alerted_handsets: null }
    const r = panicReassurance(answer, alertReach(answer, '119'), 0, '119')!
    expect(r.title).toBe('Your alert is recorded')
    expect(r.lines[1]).toEqual({ text: 'Alerting your community’s officers', state: 'waiting' })
    expect(says(r)).not.toMatch(ON_THE_WAY)
  })

  it('NO OFFICER REACHED: said plainly, 119 NOW, and nothing soothing that is not true: even with everything else done', () => {
    const answer = { ...BASE, alerted_handsets: 0, alerted_via: 'escalation', escalated_contacts: 2, ...emailed(2) }
    const r = panicReassurance(answer, alertReach(answer, '119'), 2, '119')!
    expect(r.title).toBe('No officer’s phone was reached')
    expect(r.everyoneTold).toBe(false)
    expect(r.call).toBe('Call 119 now: do not wait for this.')
    expect(r.lines).toContainEqual({ text: 'No officer’s phone could be reached', state: 'missing' })
    // What IS true is still said: the control room has it, the estate and the contacts were emailed.
    expect(r.lines).toContainEqual({ text: 'The control room has your alert', state: 'done' })
    expect(r.lines).toContainEqual({ text: 'Your estate’s security and committee have been emailed', state: 'done' })
    expect(says(r)).not.toMatch(ON_THE_WAY)
    expect(says(r)).not.toContain(ALL_NOTIFIED)
  })

  it('"on the way" only once an officer is RECORDED as sent: responseHeadline\'s own rule', () => {
    const sent = { ...BASE, alerted_handsets: 2, escalated_contacts: 1, acknowledged_at: '2026-09-26T20:00:05Z', dispatched_at: '2026-09-26T20:01:00Z', ...emailed(1) }
    const r = panicReassurance(sent, alertReach(sent, '119'), 1, '119')!
    expect(r.title).toBe('Help is on the way')
    expect(r.lines).toContainEqual({ text: 'Dispatch has seen your alert', state: 'done' })
    expect(r.lines).toContainEqual({ text: 'Help is on the way', state: 'done' })
    expect(r.call).toBe('Call 119 if you can.')
  })

  it('contacts on file who were not told are said so, "call them", and never counted as told', () => {
    const answer = { ...BASE, alerted_handsets: 2, escalated_contacts: 1, email: { household: { recipients: 0, sent: 0, failed: 0, finished: true } } }
    const r = panicReassurance(answer, alertReach(answer, '119'), 2, '119')!
    expect(r.everyoneTold).toBe(false)
    expect(r.lines).toContainEqual({ text: 'Your emergency contacts were not told automatically: call them', state: 'missing' })
    // With nobody on file, there is nobody left untold.
    expect(panicReassurance(answer, alertReach(answer, '119'), 0, '119')!.title).toBe(ALL_NOTIFIED)
  })

  it('nothing at all before the server has answered, or once the alert is closed', () => {
    expect(panicReassurance(null, alertReach(null, '119'), 1, '119')).toBeNull()
    const closed = { ...BASE, status: 'resolved', resolved_at: '2026-09-26T21:00:00Z', alerted_handsets: 2 }
    expect(panicReassurance(closed, alertReach(closed, '119'), 1, '119')).toBeNull()
  })
})

describe('the contacts sentence, with email (M12 §2.5)', () => {
  it('"emailed" only from what the mail server accepted', () => {
    expect(householdEmails({ email: { household: { recipients: 2, sent: 0, failed: 0, finished: false } } })).toEqual({ state: 'emailing' })
    expect(householdEmails({ email: { household: { recipients: 2, sent: 2, failed: 0, finished: true } } })).toEqual({ state: 'sent', sent: 2, recipients: 2, finished: true })
    expect(householdEmails({ email: { household: { recipients: 2, sent: 0, failed: 2, finished: true } } })).toEqual({ state: 'not_sent' })
    expect(householdEmails({ email: { household: { recipients: 0, sent: 0, failed: 0, finished: true } } })).toEqual({ state: 'none' })
    expect(householdEmails({ email: { household: null } })).toEqual({ state: 'unreported' })
    expect(householdEmails({})).toEqual({ state: 'unreported' })
  })

  it('says what went, and what did not, in the resident\'s words', () => {
    expect(householdContactsLine({ ...emailed(2), sms: { provider_connected: false, household: { attempted: 0, delivered: 0, finished: true } } }, 2)).toBe('2 of your emergency contacts were emailed.')
    expect(householdContactsLine(emailed(1, 2, true), 2)).toBe('1 of your emergency contacts was emailed. The rest could not be emailed. Call the others, or text them from your own phone below.')
    expect(householdContactsLine(emailed(1, 2, false), 0)).toBe('1 of your emergency contacts was emailed. Emailing the rest…')
    expect(householdContactsLine({ email: { household: { recipients: 1, sent: 0, failed: 0, finished: false } }, sms: { household: null } }, 1)).toBe('Emailing your emergency contacts…')
    expect(householdContactsLine({ email: { household: { recipients: 2, sent: 0, failed: 2, finished: true } }, sms: { household: null } }, 2)).toBe('Your emergency contacts could not be emailed: call them, or text them from your own phone below.')
    // No address on file: today's text line, unchanged.
    expect(householdContactsLine({ email: { household: { recipients: 0, sent: 0, failed: 0, finished: true } }, sms: { provider_connected: false, household: { attempted: 0, delivered: 0, finished: true } } }, 0)).toBe('Your emergency contacts are not texted automatically: call them.')
  })

  it('before the server answers, neither channel is claimed', () => {
    expect(contactsLineFor({ state: 'unanswered' }, 1)).toBe('When your alert gets through, this screen tells you whether your emergency contacts were emailed or sent a text. Call them now, or text them from your own phone below.')
  })
})

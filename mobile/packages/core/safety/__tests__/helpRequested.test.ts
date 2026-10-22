/**
 * THE PANIC SCREEN, WORD FOR WORD (M18A) — held sentence by sentence.
 *
 * What is proven here is the copy, in every state the server can report: the header, the ticks (the first always
 * ticked, every tick with its time), the 119 card (the same in every state), the plain status beneath it — and the
 * difference the client asked for between "no officer on duty" and "on duty, phones unreachable". Nothing may say or
 * imply somebody is on their way unless the server has recorded it.
 */

import { alertReach } from '../alertReach'
import { callCard, closedCard, contactsLine, helpScreen, offlineLine, reachLine, status, subline, ticks, type HelpAnswer } from '../helpRequested'

const SENT = '2026-09-27T14:38:00Z'
const when = (iso: string) => `at:${iso.slice(11, 16)}`

const answer = (over: Partial<HelpAnswer> & Record<string, unknown> = {}): HelpAnswer & Record<string, unknown> => ({
  status: 'open', acknowledged_at: null, dispatched_at: null, en_route_at: null, on_scene_at: null, resolved_at: null, cancelled_at: null,
  server_time: SENT, console_alerted: true, escalated_contacts: 0, alerted_handsets: 2, alerted_via: 'on_duty', rang_handsets: 0, rang_at: null, on_duty_officers: 2,
  ...over,
})
const reachOf = (a: ReturnType<typeof answer>) => alertReach(a as never, '119')

/** Anything that would tell a frightened person somebody is on their way. */
const PROMISES = /help is coming|help is on the way|someone is on (their|the) way|an officer is coming|is responding now/i

describe('the header (§2.1)', () => {
  it('names the unit, the time it was sent and whether the location went', () => {
    // The time as callers write it since M98: the digits, no tag.
    expect(subline({ unit: 'Lot 47', sentAt: '9:38 AM', withPosition: true })).toBe('Lot 47 · sent 9:38 AM · your location was sent')
    expect(subline({ unit: 'Lot 47', sentAt: '9:38 AM', withPosition: false })).toBe('Lot 47 · sent 9:38 AM · your location could not be sent')
    expect(subline({ unit: null, sentAt: '9:38 AM', withPosition: true })).toBe('sent 9:38 AM · your location was sent')
  })

  it('titles every state "Help requested", never "Emergency", never in capitals', () => {
    const screen = helpScreen(answer(), reachOf(answer()), { unit: 'Lot 47', sentAt: '9:38 AM', withPosition: true, when, textable: 1, emergencyNumber: '119' })
    expect(screen.title).toBe('Help requested')
    const all = JSON.stringify(screen)
    expect(all).not.toMatch(/emergency alert|EMERGENCY|PANIC/)
  })
})

describe('the ticks (§2.2)', () => {
  it('always ticks "Control room has your alert" first, with its time', () => {
    for (const a of [answer(), answer({ alerted_handsets: 0, on_duty_officers: 0 }), answer({ alerted_handsets: null })]) {
      const [first] = ticks(a, reachOf(a), when)
      expect(first).toEqual({ key: 'console', title: 'Control room has your alert', at: 'at:14:38', done: true, note: null })
    }
  })

  it('says officers were alerted only when a phone was sent the alert, and when', () => {
    expect(ticks(answer(), reachOf(answer()), when).map((t) => t.key)).toContain('officers')
    const nobody = answer({ alerted_handsets: 0, on_duty_officers: 0 })
    expect(ticks(nobody, reachOf(nobody), when).map((t) => t.key)).not.toContain('officers')
    // The call-style ring rang them: its own moment, not the raise's.
    const rung = answer({ alerted_handsets: 0, rang_handsets: 3, rang_at: '2026-09-27T14:38:20Z' })
    expect(ticks(rung, reachOf(rung), when).find((t) => t.key === 'officers')).toEqual({ key: 'officers', title: 'Officers alerted', at: 'at:14:38', done: true, note: null })
  })

  it('gives every tick a time, and a skipped step says "Not recorded", never drawn as done', () => {
    const a = answer({ acknowledged_at: null, dispatched_at: '2026-09-27T14:41:00Z' })
    const all = ticks(a, reachOf(a), when)
    for (const t of all.filter((x) => x.done)) expect(t.at).not.toBeNull()
    expect(all.find((t) => t.key === 'acknowledged')).toMatchObject({ done: false, at: null, note: 'Not recorded' })
    expect(all.find((t) => t.key === 'dispatched')).toMatchObject({ done: true, at: 'at:14:41' })
    // Steps still ahead are empty circles, with nothing written beside them; "On scene" in the consoles' word (M98).
    expect(all.find((t) => t.key === 'on_scene')).toMatchObject({ title: 'Officer on scene', done: false, at: null, note: null })
  })
})

describe('the 119 card (§2.3)', () => {
  it('is the same card in every state, advice and not alarm', () => {
    expect(callCard('119')).toEqual({ title: 'Call 119 as well', body: 'Emergency services are the fastest help available. Do not wait for this screen.', button: 'Call 119 now' })
    const ctx = { unit: 'Lot 47', sentAt: '9:38 AM', withPosition: true, when, textable: 0, emergencyNumber: '119' }
    const cards = [answer(), answer({ alerted_handsets: 0, on_duty_officers: 0 }), answer({ status: 'resolved', resolved_at: '2026-09-27T15:00:00Z' })].map((a) => helpScreen(a, reachOf(a), ctx).call)
    expect(new Set(cards.map((c) => JSON.stringify(c))).size).toBe(1)
  })
})

describe('what is and is not happening (§2.4, §2.6)', () => {
  it('officers reached: says so, and that the screen updates by itself', () => {
    expect(reachLine(answer(), reachOf(answer()))).toBe('Officers at your community have been alerted and the control room has your alert on screen. This screen updates by itself as they respond.')
  })

  it('NOBODY ON DUTY and ON DUTY BUT UNREACHABLE are different sentences (the client, M18A)', () => {
    const none = answer({ alerted_handsets: 0, on_duty_officers: 0 })
    expect(reachLine(none, reachOf(none))).toBe('No officer is on duty at your community at the moment, so no officer’s phone could be alerted. The control room has your alert on screen.')
    const unreachable = answer({ alerted_handsets: 0, on_duty_officers: 2 })
    expect(reachLine(unreachable, reachOf(unreachable))).toBe('Officers are on duty but their phones could not be reached just now. The control room has your alert on screen. We are still trying.')
  })

  it('an alert raised before the server counted who was on duty claims neither', () => {
    const older = answer({ alerted_handsets: 0, on_duty_officers: null })
    expect(reachLine(older, reachOf(older))).toBe('No officer’s phone could be alerted just now. The control room has your alert on screen.')
  })

  it('says the escalation contact was emailed only when one was', () => {
    const emailed = answer({ alerted_handsets: 0, on_duty_officers: 0, escalated_contacts: 1 })
    expect(reachLine(emailed, reachOf(emailed))).toContain('your community’s escalation contact has been emailed')
    const not = answer({ alerted_handsets: 0, on_duty_officers: 0, escalated_contacts: 0 })
    expect(reachLine(not, reachOf(not))).not.toContain('escalation')
  })

  it('a moment after the press, before the count is in: neither alerted nor not alerted', () => {
    const early = answer({ alerted_handsets: null })
    expect(reachLine(early, reachOf(early))).toBe('The control room has your alert on screen. This screen updates by itself.')
  })

  it('an officer is said to be coming only once the server has recorded it', () => {
    expect(status(answer({ dispatched_at: '2026-09-27T14:41:00Z' }), reachOf(answer()))).toEqual(['An officer has been assigned and is being sent to you.'])
    expect(status(answer({ en_route_at: '2026-09-27T14:42:00Z' }), reachOf(answer()))).toEqual(['Your officer is on the way.'])
    expect(status(answer({ on_scene_at: '2026-09-27T14:50:00Z' }), reachOf(answer()))).toEqual(['Your officer has arrived at your lot.'])
    expect(status(answer({ acknowledged_at: '2026-09-27T14:39:00Z' }), reachOf(answer()))[0]).toBe('The control room has acknowledged your alert.')
  })

  it('promises nothing in any state the server has not recorded', () => {
    for (const a of [answer(), answer({ alerted_handsets: 0, on_duty_officers: 0 }), answer({ alerted_handsets: 0, on_duty_officers: 3 }), answer({ alerted_handsets: null }), answer({ acknowledged_at: '2026-09-27T14:39:00Z' })]) {
      expect(status(a, reachOf(a)).join(' ')).not.toMatch(PROMISES)
    }
  })
})

describe('the household and the emergency contacts (§2.5, M18C §4.3)', () => {
  const mailed = (members: number, contacts: number) => ({ email: { household: { recipients: members + contacts, sent: members + contacts, failed: 0, finished: true, members_sent: members, contacts_sent: contacts } }, sms: { provider_connected: false, household: null } })

  it('says who was emailed, in the client\'s words, and that nobody was texted', () => {
    expect(contactsLine(mailed(2, 1), 1)).toBe('Your household and your emergency contacts have been emailed. They are not texted automatically: you can text them from your own phone below.')
    expect(contactsLine(mailed(2, 0), 0)).toBe('Your household has been emailed. They are not texted automatically.')
    expect(contactsLine(mailed(0, 2), 2)).toBe('Your emergency contacts have been emailed. They are not texted automatically: you can text them from your own phone below.')
  })

  it('says "emailed" only from what the mail server accepted', () => {
    const none = { email: { household: { recipients: 2, sent: 0, failed: 2, finished: true } }, sms: { provider_connected: false, household: null } }
    expect(contactsLine(none, 1)).not.toMatch(/emailed/)
  })
})

describe('closed (§2.6, §3.5)', () => {
  it('says the control room closed it, and how to raise it again: 119 named', () => {
    expect(closedCard(answer({ status: 'resolved', resolved_at: '2026-09-27T15:00:00Z' }), '119')).toEqual({
      title: 'This alert has been closed by the control room.',
      lines: ['What was recorded is above.', 'If the problem is not over, send a new alert below, or call 119.'],
    })
  })

  it('a cancel from this phone says that instead', () => {
    expect(closedCard(answer({ status: 'false_alarm', cancelled_at: '2026-09-27T14:39:00Z' }), '119')?.title).toBe('You cancelled this alert')
  })

  it('an open alert has no closing card, and a closed one no status beneath the ticks', () => {
    expect(closedCard(answer(), '119')).toBeNull()
    const closed = answer({ status: 'resolved', resolved_at: '2026-09-27T15:00:00Z' })
    expect(helpScreen(closed, reachOf(closed), { unit: null, sentAt: 'x', withPosition: false, when, textable: 0, emergencyNumber: '119' }).status).toEqual([])
  })
})

describe('held on the phone (§2.7)', () => {
  it('never says the control room was texted, there is no such route, and puts 119 in the sentence', () => {
    const line = offlineLine('119')
    expect(line).toBe('Your phone has no signal, so your alert is saved on this phone and will be sent the moment any signal returns. The control room does not have it yet. Call 119 now.')
    expect(line).not.toMatch(/sent by text|texted/i)
  })
})

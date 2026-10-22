/**
 * The rule: never show "help is coming" when it is not.
 *
 * The whole point of this module is one sentence that must never be said, so most of what
 * follows is about what the words may NOT contain. A panic that silently reaches nobody is the
 * worst failure this product can have, and the second worst is one that reaches nobody and
 * says otherwise.
 */
import { alertReach, alertSteps, textsDelivered, type AlertAnswer } from '../index'

const EMERGENCY = '119'

const answer = (over: Partial<AlertAnswer> = {}): AlertAnswer => ({
  id: 41,
  status: 'open',
  cancellable_until: '2026-09-21T14:41:12Z',
  acknowledged_at: null,
  cancelled_at: null,
  server_time: '2026-09-21T14:41:02Z',
  console_alerted: true,
  alerted_handsets: 2,
  alerted_via: 'on_duty',
  escalated_contacts: 0,
  ...over,
})

/** Anything that would tell a person somebody is coming. */
const PROMISES = /help is coming|help is on the way|on (their|the) way|someone is responding|responding now|officer is coming/i

describe('what the person is told about who this reached', () => {
  it('reads a missing count as STILL REACHING, never as nobody', () => {
    for (const missing of [undefined, null]) {
      const r = alertReach(answer({ alerted_handsets: missing as number | null | undefined }), EMERGENCY)
      expect(r.state).toBe('reaching')
      expect(r.reachedNobody).toBe(false)
      expect(r.handsets).toBeNull()
      expect(r.mayPromiseHelp).toBe(false)
    }

    // An alert saved by a build that predates the field at all.
    const old = alertReach({ id: 1, status: 'open', cancellable_until: '', acknowledged_at: null, cancelled_at: null, server_time: '' } as AlertAnswer, EMERGENCY)
    expect(old.state).toBe('reaching')
    expect(old.reachedNobody).toBe(false)
    // …and the control room is still credited, because that was true before the field existed too.
    expect(old.consoleAlerted).toBe(true)
  })

  it('says how many phones it reached, and only then may the screen imply anyone is coming', () => {
    const one = alertReach(answer({ alerted_handsets: 1 }), EMERGENCY)
    expect(one.state).toBe('reached')
    expect(one.headline).toBe('Sent to 1 officer phone')
    expect(one.mayPromiseHelp).toBe(true)
    expect(one.reachedNobody).toBe(false)

    const many = alertReach(answer({ alerted_handsets: 4 }), EMERGENCY)
    expect(many.headline).toBe('Sent to 4 officer phones')

    // Even here it never claims anybody has SEEN it: a queued push is not a person.
    expect(`${many.headline} ${many.detail}`).not.toMatch(PROMISES)
    expect(`${many.headline} ${many.detail}`).not.toMatch(/seen|read|acknowledged/i)
  })

  it('says plainly when nobody was reached, names the emergency number, and promises nothing', () => {
    const r = alertReach(answer({ alerted_handsets: 0, alerted_via: 'none', escalated_contacts: 0 }), EMERGENCY)

    expect(r.state).toBe('nobody')
    expect(r.reachedNobody).toBe(true)
    expect(r.mayPromiseHelp).toBe(false)
    expect(r.headline).toBe('No officer’s phone was reached')
    expect(r.detail).toContain(`Call ${EMERGENCY} now`)
    expect(r.detail).toContain('There was nobody to escalate to either.')
    expect(`${r.headline} ${r.detail}`).not.toMatch(PROMISES)
  })

  it('an emailed escalation contact is a fact, not a rescue', () => {
    const r = alertReach(answer({ alerted_handsets: 0, alerted_via: 'escalation', escalated_contacts: 2 }), EMERGENCY)

    expect(r.state).toBe('nobody')
    expect(r.reachedNobody).toBe(true)
    expect(r.mayPromiseHelp).toBe(false)
    expect(r.escalated).toBe(2)
    expect(r.detail).toContain('escalation contact has been emailed')
    expect(r.detail).toContain('not the same as somebody being on their way')
    expect(r.detail).toContain(`Call ${EMERGENCY} now`)
  })

  /*
   * M18 §1.6, found on production (alerts 18 and 19): the raise reached no phone, the ring then rang every officer of
   * the estate at once, and this screen went on saying "No officer's phone was reached" while a phone was ringing.
   */
  it('stops saying nobody was reached once the ring is ringing every officer, and still names the number', () => {
    const r = alertReach(answer({ alerted_handsets: 0, alerted_via: 'none', rang_handsets: 3 }), EMERGENCY)

    expect(r.state).toBe('reached')
    expect(r.reachedNobody).toBe(false)
    expect(r.viaRing).toBe(true)
    expect(r.handsets).toBe(3)
    expect(r.headline).toBe('Ringing 3 officer phones')
    expect(r.detail).toContain('every officer of your community is being rung')
    expect(r.detail).toContain(`call ${EMERGENCY} as well`)
    expect(`${r.headline} ${r.detail}`).not.toMatch(PROMISES)

    // A ring that has not begun, or has sent to nobody yet, changes nothing: still said plainly.
    for (const none of [null, 0, undefined]) {
      const n = alertReach(answer({ alerted_handsets: 0, alerted_via: 'none', rang_handsets: none as number | null | undefined }), EMERGENCY)
      expect(n.state).toBe('nobody')
      expect(n.viaRing).toBe(false)
    }

    // And a raise that DID reach phones is told as before, whatever the ring later adds.
    expect(alertReach(answer({ alerted_handsets: 2, rang_handsets: 5 }), EMERGENCY).headline).toBe('Sent to 2 officer phones')
  })

  it('names the number it was given, so the sentence and the button can never disagree', () => {
    expect(alertReach(answer({ alerted_handsets: 0 }), '110').detail).toContain('Call 110 now')
  })

  it('says when nobody was clocked on and it went to today\'s roster instead', () => {
    const r = alertReach(answer({ alerted_handsets: 2, alerted_via: 'rostered' }), EMERGENCY)
    expect(r.state).toBe('reached')
    expect(r.tier).toBe('rostered')
    expect(r.detail).toContain('Nobody was clocked on')
    expect(r.detail).toContain('rostered at your community today')
  })

  it('ignores a tier it does not know rather than printing it', () => {
    const r = alertReach(answer({ alerted_via: 'something_newer' }), EMERGENCY)
    expect(r.tier).toBeNull()
    expect(r.state).toBe('reached')
  })

  /*
   * THE COMMUNITY'S EMERGENCY NUMBERS (Q-M09). The client ruled that a panic must also
   * text four numbers. Those texts must never move the one number this screen decides
   * "is anybody coming?" on, and the count shown must be what a PROVIDER ACCEPTED —
   * never how many numbers were tried.
   */
  it('counts only the texts a provider accepted, and never the numbers that were merely tried', () => {
    // Four numbers attempted, none delivered — which is every alert on this platform
    // today, because no text-message provider has been bought.
    const none = alertReach(answer({ alerted_handsets: 2, sms_recipients: 4, sms_delivered: 0 }), EMERGENCY)
    expect(none.texts).toBe(0)
    expect(none.detail).not.toMatch(/text/i)

    // Still running: attempted is known, delivered is not. Nothing is claimed.
    const pending = alertReach(answer({ alerted_handsets: 2, sms_recipients: 4, sms_delivered: null }), EMERGENCY)
    expect(pending.texts).toBe(0)
    expect(pending.detail).not.toMatch(/text/i)

    // Delivered, and said as its own fact.
    const sent = alertReach(answer({ alerted_handsets: 2, sms_recipients: 4, sms_delivered: 4 }), EMERGENCY)
    expect(sent.texts).toBe(4)
    expect(sent.detail).toContain('4 of your community’s emergency numbers were also sent a text message.')
    expect(`${sent.headline} ${sent.detail}`).not.toMatch(PROMISES)
  })

  it('never lets a text stand in for an officer', () => {
    // Nobody on a handset, but every text delivered. The answer is still NOBODY: a text
    // is a message waiting to be read, and 119 keeps its prominence.
    const r = alertReach(answer({ alerted_handsets: 0, alerted_via: 'none', escalated_contacts: 0, sms_recipients: 4, sms_delivered: 4 }), EMERGENCY)

    expect(r.state).toBe('nobody')
    expect(r.reachedNobody).toBe(true)
    expect(r.mayPromiseHelp).toBe(false)
    expect(r.handsets).toBe(0)
    expect(r.texts).toBe(4)
    expect(r.headline).toBe('No officer’s phone was reached')
    expect(r.detail).toContain('No officer’s phone could be sent a notification')
    // Zero handsets is not proof that nobody is on duty: an officer at the gate may have a phone with no push address.
    expect(r.detail).not.toMatch(/^Nobody was on duty/)
    expect(r.detail).toContain('also sent a text message')
    expect(r.detail).toContain(`Call ${EMERGENCY} now`)
    expect(`${r.headline} ${r.detail}`).not.toMatch(PROMISES)
  })

  /*
   * M10 §1: "nothing may tell a resident in an emergency that a text went out when none did." No SMS
   * provider is connected, and a server change may add `sms: { provider_connected, attempted, delivered }`.
   * The app must be honest without it and use it only if it is there.
   */
  it('reads a future `sms.delivered` if the server sends one, and says nothing unless it is above zero', () => {
    expect(textsDelivered(answer({ sms: { provider_connected: true, attempted: 4, delivered: 3 } }))).toBe(3)
    expect(alertReach(answer({ sms: { provider_connected: true, attempted: 4, delivered: 3 } }), EMERGENCY).detail).toContain('3 of your community’s emergency numbers were also sent a text message.')

    // Attempted is never a delivery, in the new shape any more than in the old one.
    const tried = alertReach(answer({ sms: { provider_connected: true, attempted: 4, delivered: 0 }, sms_recipients: 4 }), EMERGENCY)
    expect(tried.texts).toBe(0)
    expect(tried.detail).not.toMatch(/text/i)

    // Still running in the new shape: nothing claimed.
    expect(textsDelivered(answer({ sms: { provider_connected: true, attempted: 4, delivered: null } }))).toBe(0)
  })

  it('a provider the server says is not connected delivers nothing, whatever the counts say', () => {
    const r = alertReach(answer({ sms: { provider_connected: false, attempted: 4, delivered: 4 }, sms_delivered: 4 }), EMERGENCY)
    expect(r.texts).toBe(0)
    expect(r.detail).not.toMatch(/text/i)
  })

  it('without the new field the answer is exactly what today\'s fields say', () => {
    expect(textsDelivered(answer({ sms_delivered: 2 }))).toBe(2)
    expect(textsDelivered(answer({ sms_delivered: 2, sms: null }))).toBe(2)
    expect(textsDelivered(answer({ sms_delivered: 0 }))).toBe(0)
    expect(textsDelivered(null)).toBe(0)
    expect(textsDelivered(undefined)).toBe(0)
  })

  it('today, no provider, every alert sms_delivered 0, no state of the screen mentions a text', () => {
    for (const handsets of [null, 0, 1, 3]) {
      const r = alertReach(answer({ alerted_handsets: handsets, alerted_via: handsets ? 'on_duty' : 'none', sms_recipients: 4, sms_delivered: 0 }), EMERGENCY)
      expect(`${r.headline} ${r.detail}`).not.toMatch(/\btext|\bSMS\b/i)
    }
  })

  it('"on duty" counts what the server counted: the officers clocked on AND those rostered today', () => {
    const r = alertReach(answer({ alerted_handsets: 3, alerted_via: 'on_duty' }), EMERGENCY)
    expect(r.detail).toContain('3 officer phones at your community were alerted, the officers on duty and those rostered there today')
    expect(r.detail).not.toMatch(/^3 officer phones on duty/)
  })

  it('never lets a nonsense text count say anything at all', () => {
    for (const bad of [-1, Number.NaN, Number.POSITIVE_INFINITY, '4' as unknown as number]) {
      const r = alertReach(answer({ alerted_handsets: 2, sms_delivered: bad }), EMERGENCY)
      expect(r.texts).toBe(0)
      expect(r.detail).not.toMatch(/text/i)
      expect(textsDelivered(answer({ sms: { delivered: bad } }))).toBe(0)
    }
    // An `sms` that is not an object is ignored rather than trusted.
    expect(textsDelivered(answer({ sms: 'yes' as unknown as AlertAnswer['sms'], sms_delivered: 0 }))).toBe(0)
  })

  it('never lets a nonsense count become a promise', () => {
    for (const bad of [-1, Number.NaN, Number.POSITIVE_INFINITY, '3' as unknown as number]) {
      const r = alertReach(answer({ alerted_handsets: bad }), EMERGENCY)
      expect(r.state).toBe('reaching')
      expect(r.mayPromiseHelp).toBe(false)
    }
  })
})

describe('the timeline stops claiming the gatehouse when the gatehouse was never reached', () => {
  it('keeps its own words when a handset was reached', () => {
    expect(alertSteps({ status: 'open', acknowledged_at: null, cancelled_at: null }, '9:41:02 AM', null)[0]).toMatchObject({
      key: 'sent',
      title: 'Alert sent to gatehouse & dispatch',
      done: true,
    })
  })

  it('credits only the control room when none was', () => {
    const first = alertSteps({ status: 'open', acknowledged_at: null, cancelled_at: null }, '9:41:02 AM', null, true)[0]

    expect(first).toMatchObject({ key: 'sent', title: 'The control room has your alert', done: true })
    expect(first?.detail).toContain('no officer’s phone was reached')
    expect(first?.title).not.toContain('gatehouse')
  })
})

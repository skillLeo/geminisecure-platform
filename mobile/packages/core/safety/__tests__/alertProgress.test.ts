/**
 * THE HONEST STATE (M10 §1.3): alerting → acknowledged → dispatched → en route → on scene → resolved.
 *
 * Three rules, each with the failure it exists to stop:
 *   - a step is ticked ONLY by the time the server recorded for it — never by `status`, which is one word
 *     for several facts, and never back-filled;
 *   - a step the server skipped stays unticked and says "Not recorded" — it is neither faked nor hidden;
 *   - "Help is on the way" is said only once `dispatched_at` holds a time.
 */
import { NOT_RECORDED, alertSteps, answerMoved, progressStates, recorded, responseHeadline, type AlertAnswer, type ProgressFields } from '../index'

const T = {
  sent: '2026-09-24T14:41:02Z',
  ack: '2026-09-24T14:41:16Z',
  dispatched: '2026-09-24T14:42:10Z',
  enRoute: '2026-09-24T14:42:30Z',
  onScene: '2026-09-24T14:45:00Z',
  resolved: '2026-09-24T14:58:00Z',
}
const when = (iso: string) => `at ${iso.slice(11, 16)}`
const sentAt = '9:41:02 AM'

const alert = (over: Partial<ProgressFields> = {}): ProgressFields => ({ status: 'open', acknowledged_at: null, cancelled_at: null, dispatched_at: null, en_route_at: null, on_scene_at: null, resolved_at: null, ...over })

const states = (a: ProgressFields) => progressStates(a).map((s) => `${s.key}:${s.state}`)

describe('each step is ticked by its own field, and by nothing else', () => {
  it('just raised: the control room is next, everything after it is still to come', () => {
    expect(states(alert())).toEqual(['acknowledged:current', 'dispatched:pending', 'en_route:pending', 'on_scene:pending', 'resolved:pending'])
  })

  it('step by step, as the server records them', () => {
    expect(states(alert({ status: 'acknowledged', acknowledged_at: T.ack }))).toEqual(['acknowledged:done', 'dispatched:current', 'en_route:pending', 'on_scene:pending', 'resolved:pending'])
    expect(states(alert({ status: 'responding', acknowledged_at: T.ack, dispatched_at: T.dispatched }))).toEqual(['acknowledged:done', 'dispatched:done', 'en_route:current', 'on_scene:pending', 'resolved:pending'])
    expect(states(alert({ status: 'responding', acknowledged_at: T.ack, dispatched_at: T.dispatched, en_route_at: T.enRoute, on_scene_at: T.onScene }))).toEqual(['acknowledged:done', 'dispatched:done', 'en_route:done', 'on_scene:done', 'resolved:current'])
  })

  it('never from the status: "responding" with no times recorded ticks nothing past what is recorded', () => {
    expect(states(alert({ status: 'responding', acknowledged_at: T.ack }))).toEqual(['acknowledged:done', 'dispatched:current', 'en_route:pending', 'on_scene:pending', 'resolved:pending'])
    // An acknowledged STATUS is not an acknowledged_at.
    expect(states(alert({ status: 'acknowledged' }))[0]).toBe('acknowledged:current')
  })

  it('a time that is not a time records nothing', () => {
    for (const bad of ['', '   ', 'soon', 42, true] as unknown as string[]) {
      expect(recorded(bad)).toBeNull()
      expect(states(alert({ dispatched_at: bad }))[1]).toBe('dispatched:pending')
    }
  })
})

describe('a skipped step is "Not recorded", never drawn as done, never dropped', () => {
  it('an officer who ran straight there: on scene, and no en-route time back-filled', () => {
    const a = alert({ status: 'responding', acknowledged_at: T.ack, dispatched_at: T.dispatched, on_scene_at: T.onScene })
    expect(states(a)).toEqual(['acknowledged:done', 'dispatched:done', 'en_route:not_recorded', 'on_scene:done', 'resolved:current'])
    const steps = alertSteps(a, sentAt, 14, false, when)
    expect(steps.find((s) => s.key === 'en_route')).toMatchObject({ title: 'Officer on the way', detail: NOT_RECORDED, done: false })
  })

  it('a guard on shift who took it without being sent: dispatched is not recorded, and neither is an acknowledgement nobody made', () => {
    const a = alert({ status: 'responding', en_route_at: T.enRoute })
    expect(states(a)).toEqual(['acknowledged:not_recorded', 'dispatched:not_recorded', 'en_route:done', 'on_scene:current', 'resolved:pending'])
    const steps = alertSteps(a, sentAt, null, false, when)
    expect(steps.find((s) => s.key === 'acknowledged')).toMatchObject({ title: 'Acknowledged by dispatch', detail: NOT_RECORDED, done: false })
    expect(steps.find((s) => s.key === 'dispatched')).toMatchObject({ detail: NOT_RECORDED, done: false })
  })

  it('a closed alert leaves nothing pending: every step before the close happened, or was not recorded', () => {
    const a = alert({ status: 'resolved', acknowledged_at: T.ack, resolved_at: T.resolved })
    expect(states(a)).toEqual(['acknowledged:done', 'dispatched:not_recorded', 'en_route:not_recorded', 'on_scene:not_recorded', 'resolved:done'])
    const steps = alertSteps(a, sentAt, 14, false, when)
    // Closing an alert proves nothing about anybody having come.
    expect(steps.filter((s) => s.done).map((s) => s.key)).toEqual(['sent', 'acknowledged', 'resolved'])
    // The consoles' word for the close (M98): Resolved.
    expect(steps.find((s) => s.key === 'resolved')).toMatchObject({ title: 'Resolved', detail: 'at 14:58' })
    expect(steps.every((s) => !s.current)).toBe(true)
  })

  it('the first version\'s placeholder is gone: no step says "Not reported yet", whatever the server has recorded', () => {
    const fields = ['acknowledged_at', 'dispatched_at', 'en_route_at', 'on_scene_at'] as const
    const times = [T.ack, T.dispatched, T.enRoute, T.onScene]
    for (let mask = 0; mask < 16; mask++) {
      const over: Partial<ProgressFields> = { status: mask ? 'responding' : 'open' }
      fields.forEach((f, i) => { if (mask & (1 << i)) over[f] = times[i] })
      expect(JSON.stringify(alertSteps(alert(over), sentAt, 3, false, when))).not.toMatch(/not reported yet/i)
    }
  })
})

describe('the resident\'s words', () => {
  it('just sent: sent to the gatehouse and dispatch, waiting for dispatch', () => {
    const steps = alertSteps(alert(), sentAt, null, false, when)
    expect(steps.map((s) => [s.key, s.done, s.current])).toEqual([['sent', true, false], ['acknowledged', false, true], ['dispatched', false, false], ['en_route', false, false], ['on_scene', false, false], ['resolved', false, false]])
    expect(steps[0]).toMatchObject({ title: 'Alert sent to gatehouse & dispatch', detail: sentAt })
    expect(steps[1]).toMatchObject({ title: 'Waiting for dispatch to acknowledge', detail: 'They have it on their screen now' })
    // A step still to come carries its name and no detail at all.
    expect(steps[2]).toMatchObject({ title: 'Officer dispatched', detail: null, done: false })
  })

  it('each step, ticked, says when, and never an ETA or a name the server does not give the raiser', () => {
    const steps = alertSteps(alert({ status: 'responding', acknowledged_at: T.ack, dispatched_at: T.dispatched, en_route_at: T.enRoute }), sentAt, 14, false, when)
    expect(steps[1]).toMatchObject({ title: 'Dispatch acknowledged your alert', detail: '14 seconds later', done: true })
    expect(steps[2]).toMatchObject({ title: 'Officer dispatched', detail: 'at 14:42', done: true })
    expect(steps[3]).toMatchObject({ title: 'Officer on the way', detail: 'at 14:42', done: true })
    // "On scene", the consoles' Emergency list word (M98), never "at the scene".
    expect(steps[4]).toMatchObject({ title: 'Officer on scene', done: false, current: true })
    expect(JSON.stringify(steps)).not.toMatch(/ETA|minute|Marcus|Guard |at the scene/)
  })

  it('an acknowledgement is not a promise: nothing in the timeline says anybody is coming until it is recorded', () => {
    const steps = alertSteps(alert({ status: 'acknowledged', acknowledged_at: T.ack }), sentAt, 14, false, when)
    expect(JSON.stringify(steps)).not.toMatch(/sending someone|is coming|help is on the way|A responder is on the way/i)
    expect(steps.filter((s) => s.done).map((s) => s.key)).toEqual(['sent', 'acknowledged'])
  })

  it('a cancelled alert says it was cancelled, and is not left "waiting for dispatch"', () => {
    const cancelled = alertSteps(alert({ status: 'false_alarm', cancelled_at: '2026-09-24T14:41:07Z', resolved_at: '2026-09-24T14:41:07Z' }), sentAt, null, false, when)
    expect(cancelled.map((s) => [s.key, s.done])).toEqual([['sent', true], ['resolved', true]])
    expect(cancelled[1]?.title).toBe('You cancelled this alert')
    expect(JSON.stringify(cancelled)).not.toMatch(/Waiting for dispatch|on the way|Not recorded/i)
  })

  it('a false alarm closed by dispatch keeps the acknowledgement that happened, and says what was not recorded', () => {
    const closed = alertSteps(alert({ status: 'false_alarm', acknowledged_at: T.ack, resolved_at: T.resolved }), sentAt, 9, false, when)
    expect(closed.map((s) => s.key)).toEqual(['sent', 'acknowledged', 'dispatched', 'en_route', 'on_scene', 'resolved'])
    expect(closed[5]).toMatchObject({ title: 'False alarm', done: true })
    expect(closed.slice(2, 5).every((s) => s.detail === NOT_RECORDED && !s.done)).toBe(true)
  })

  it('an alert saved on the phone before the new fields existed is read as it always was', () => {
    // No dispatched_at / en_route_at / on_scene_at / resolved_at keys at all.
    const old = { status: 'resolved', acknowledged_at: T.ack, cancelled_at: null }
    const steps = alertSteps(old, sentAt, 14, false, when)
    expect(steps.find((s) => s.key === 'resolved')?.done).toBe(true)
    expect(steps.find((s) => s.key === 'dispatched')?.done).toBe(false)
    // But a close the server sent as null is not ticked on the status' word.
    expect(progressStates({ ...old, resolved_at: null }).find((s) => s.key === 'resolved')?.state).toBe('not_recorded')
  })
})

describe('"Help is on the way": only once an officer was dispatched', () => {
  it('before that, the control room has the alert, and that is all', () => {
    expect(responseHeadline(alert())).toEqual({ headline: 'The control room has your alert', detail: null, officer: false })
    expect(responseHeadline(alert({ status: 'acknowledged', acknowledged_at: T.ack }))).toEqual({ headline: 'The control room has your alert', detail: 'Dispatch has seen it.', officer: false })
  })

  it('dispatched: help is on the way', () => {
    expect(responseHeadline(alert({ status: 'responding', acknowledged_at: T.ack, dispatched_at: T.dispatched }))).toMatchObject({ headline: 'Help is on the way', officer: true })
    expect(responseHeadline(alert({ status: 'responding', acknowledged_at: T.ack, dispatched_at: T.dispatched, en_route_at: T.enRoute }))?.detail).toMatch(/recorded as on the way/)
    expect(responseHeadline(alert({ status: 'responding', dispatched_at: T.dispatched, on_scene_at: T.onScene }))).toMatchObject({ headline: 'An officer has arrived', detail: 'An officer is recorded on scene.', officer: true })
  })

  it('NEVER without dispatched_at: in any combination of the other steps and any status', () => {
    const fields = ['acknowledged_at', 'en_route_at', 'on_scene_at'] as const
    const times = [T.ack, T.enRoute, T.onScene]
    for (const status of ['open', 'acknowledged', 'responding']) {
      for (let mask = 0; mask < 8; mask++) {
        const over: Partial<ProgressFields> = { status }
        fields.forEach((f, i) => { if (mask & (1 << i)) over[f] = times[i] })
        const h = responseHeadline(alert(over))
        expect(`${h?.headline} ${h?.detail}`).not.toMatch(/help is on the way|has sent an officer/i)
      }
    }
  })

  it('an officer who said "on my way" without being sent is said as exactly that', () => {
    expect(responseHeadline(alert({ status: 'responding', en_route_at: T.enRoute }))).toEqual({ headline: 'An officer is on the way', detail: 'An officer is recorded as heading to you.', officer: true })
  })

  it('a closed alert has no headline, the closed card speaks, and nothing is claimed for a console that was not told', () => {
    expect(responseHeadline(alert({ status: 'resolved', dispatched_at: T.dispatched, resolved_at: T.resolved }))).toBeNull()
    expect(responseHeadline(alert({ status: 'false_alarm', cancelled_at: T.ack }))).toBeNull()
    expect(responseHeadline({ ...alert(), console_alerted: false })).toBeNull()
  })
})

describe('the poll notices every step', () => {
  const base: AlertAnswer = { id: 41, status: 'open', cancellable_until: T.sent, acknowledged_at: null, cancelled_at: null, server_time: T.sent, alerted_handsets: 2 }

  it('nothing moved, or only a key the old copy lacked and the answer sends as null: no rewrite', () => {
    expect(answerMoved(base, { ...base })).toBe(false)
    expect(answerMoved(base, { ...base, dispatched_at: null, en_route_at: null, on_scene_at: null, resolved_at: null, is_drill: false })).toBe(false)
    // How many numbers were merely TRIED is not news: only a delivery is.
    expect(answerMoved({ ...base, sms: { provider_connected: false, attempted: 0, delivered: 0 } }, { ...base, sms: { provider_connected: false, attempted: 4, delivered: 0 } })).toBe(false)
  })

  it('every field the screen draws from is noticed', () => {
    for (const change of [
      { status: 'responding' },
      { acknowledged_at: T.ack },
      { dispatched_at: T.dispatched },
      { en_route_at: T.enRoute },
      { on_scene_at: T.onScene },
      { resolved_at: T.resolved },
      { cancelled_at: T.ack },
      { alerted_handsets: 0 },
      { alerted_via: 'rostered' },
      { escalated_contacts: 1 },
      { sms_delivered: 2 },
      { sms: { provider_connected: true, delivered: 1 } },
      // The household's texting starting, and finishing.
      { sms: { provider_connected: true, household: { attempted: 1, delivered: 0, finished: false } } },
      { is_drill: true },
    ] as Partial<AlertAnswer>[]) {
      expect([change, answerMoved(base, { ...base, ...change })]).toEqual([change, true])
    }
    const texting = { ...base, sms: { provider_connected: true, household: { attempted: 2, delivered: 1, finished: false } } }
    expect(answerMoved(texting, { ...base, sms: { provider_connected: true, household: { attempted: 2, delivered: 2, finished: true } } })).toBe(true)
    // "So far" moving while it is still running changes nothing the screen says.
    expect(answerMoved(texting, { ...base, sms: { provider_connected: true, household: { attempted: 3, delivered: 2, finished: false } } })).toBe(false)
  })

  it('a first answer is news; no answer is not', () => {
    expect(answerMoved(null, base)).toBe(true)
    expect(answerMoved(base, undefined)).toBe(false)
  })
})

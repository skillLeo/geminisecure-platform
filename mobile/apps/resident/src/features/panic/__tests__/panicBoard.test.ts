/**
 * THE REBUILT PANIC SCREEN'S WORDS (resident-app-06, M42 §5 #5) — `panicBoard.ts`, tested with the world switched off.
 *
 *   - the three steps are ticked ONLY by the times the server recorded; a step that has not happened is pending, and
 *     one a later step skipped says "Not recorded" — never done;
 *   - the board's words where they are true, the true part where they are not: no guard's name, no ETA, no "live";
 *   - dispatch's message is the furthest thing dispatch recorded, in the app's existing sentences, and nothing before
 *     that; the phone's own sentences never say "Dispatch:";
 *   - none of what the client struck from the screen comes back through the words.
 */

import { time12, time12s } from '@gs/core/lib'
import { alertReach, type AlertAnswer } from '@gs/core/safety'
import { boardBubble, boardSteps, drillModeLine, holdNote, later, panicBoard, WORDS, type BoardInput, type PanicBoard } from '../panicBoard'

const SENT = '2026-09-04T14:41:02Z' // 9:41:02 AM in Kingston
const answer = (over: Partial<AlertAnswer> = {}): AlertAnswer => ({
  id: 7,
  kind: 'panic',
  status: 'open',
  cancellable_until: '2026-09-04T14:41:12Z',
  acknowledged_at: null,
  cancelled_at: null,
  dispatched_at: null,
  en_route_at: null,
  on_scene_at: null,
  resolved_at: null,
  server_time: SENT,
  console_alerted: true,
  alerted_handsets: 3,
  alerted_via: 'on_duty',
  ...over,
})
const reached = alertReach(answer(), '119')
const nobody = alertReach(answer({ alerted_handsets: 0, alerted_via: 'none', on_duty_officers: 0 }), '119')
const steps = (a: AlertAnswer | null, withPosition = true, reachedNobody = false) => boardSteps(a, { reachedNobody, withPosition, atSeconds: (iso) => time12s(iso) })

const input = (over: Partial<BoardInput> = {}): BoardInput => ({
  phase: 'active',
  label: 'General emergency',
  alert: answer(),
  pressedAt: '2026-09-04T14:41:00Z',
  withPosition: true,
  reach: reached,
  held: 'Your phone has no signal, so your alert is saved on this phone and will be sent the moment any signal returns. The control room does not have it yet. Call 119 now.',
  noSignal: true,
  failure: null,
  demoRefusal: null,
  drillMode: null,
  holdMs: 1500,
  emergencyNumber: '119',
  at: (iso) => time12(iso),
  atSeconds: (iso) => time12s(iso),
  ...over,
})

describe('drill mode (M53 §3A.1): said before the press, and after it what did not happen', () => {
  const on = { until: '2026-09-04T21:30:00Z', until_local: '4:30 PM', reason: 'Testing the panic button' }
  const before = Date.parse('2026-09-04T20:00:00Z')

  it('says it on the gold line before the press while the end is still ahead, and nothing once it is past or off', () => {
    expect(drillModeLine(on, before)).toBe('Drill mode until 4:30 PM · a press alerts nobody')
    expect(drillModeLine(on, Date.parse('2026-09-04T21:30:00Z'))).toBeNull()
    expect(drillModeLine(null, before)).toBeNull()
    expect(drillModeLine(undefined, before)).toBeNull()

    expect(panicBoard(input({ phase: 'arm', alert: null, drillMode: drillModeLine(on, before) })).meta).toBe('Drill mode until 4:30 PM · a press alerts nobody')
    // A demonstration session's refusal still comes first: nothing at all is sent from it.
    expect(panicBoard(input({ phase: 'arm', alert: null, demoRefusal: 'Demo', drillMode: drillModeLine(on, before) })).meta).toBe('Demo')
  })

  it('after a drill-mode press: "Drill sent", DRILL first, no officer’s phone rang, and 119 offered in case it is real', () => {
    const drill = answer({ is_drill: true, drill_mode: true, alerted_handsets: null, alerted_via: null })
    const board = panicBoard(input({ alert: drill, reach: alertReach(drill, '119') }))

    expect(board.title).toBe('Drill sent')
    expect(board.meta).toBe('DRILL · General emergency · Sent 9:41 AM')
    expect(board.steps[0]).toEqual({ title: 'Drill sent to dispatch', detail: '9:41:02 AM · no officer’s phone rang', done: true })
    expect(board.bubble).toEqual({ from: null, text: 'Drill mode was on, so no officer’s phone rang and nobody was emailed or texted. If this is a real emergency, call 119 now.' })
  })

  it('a drill the control room has answered says what dispatch did, as any alert does', () => {
    const drill = answer({ is_drill: true, drill_mode: true, alerted_handsets: null, acknowledged_at: '2026-09-04T14:41:16Z', status: 'acknowledged' })
    expect(boardBubble(drill, alertReach(drill, '119'), '119')?.from).toBe(WORDS.dispatch)
  })
})

describe('the three steps: ticked only by the server’s own times', () => {
  it('before the server has the alert, all three are pending: it exists only on this phone', () => {
    expect(steps(null)).toEqual([
      { title: 'Alert sent to gatehouse & dispatch', detail: null, done: false },
      { title: 'Dispatch acknowledged', detail: null, done: false },
      { title: 'On the way', detail: null, done: false },
    ])
  })

  it('sent: the first ticked with the board’s "9:41:02 AM"; the others pending, the position said only when it went', () => {
    expect(steps(answer())).toEqual([
      { title: 'Alert sent to gatehouse & dispatch', detail: '9:41:02 AM', done: true },
      { title: 'Dispatch acknowledged', detail: null, done: false },
      { title: 'On the way', detail: 'Location shared', done: false },
    ])
    expect(steps(answer(), false)[2]).toEqual({ title: 'On the way', detail: null, done: false })
  })

  it('acknowledged: "14 seconds later", the board’s own format', () => {
    expect(steps(answer({ acknowledged_at: '2026-09-04T14:41:16Z', status: 'acknowledged' }))[1]).toEqual({ title: 'Dispatch acknowledged', detail: '14 seconds later', done: true })
  })

  it('on the way only once an officer is RECORDED on the way: dispatched alone is not', () => {
    const sentOnly = steps(answer({ acknowledged_at: '2026-09-04T14:41:16Z', dispatched_at: '2026-09-04T14:42:00Z', status: 'responding' }))
    expect(sentOnly[2]!.done).toBe(false)
    const onTheWay = steps(answer({ acknowledged_at: '2026-09-04T14:41:16Z', dispatched_at: '2026-09-04T14:42:00Z', en_route_at: '2026-09-04T14:42:30Z', status: 'responding' }))
    expect(onTheWay[2]).toEqual({ title: 'On the way', detail: 'Location shared', done: true })
  })

  it('a step a later one skipped is "Not recorded", never ticked, never dropped', () => {
    // An officer who ran straight there: on scene, never recorded as on the way.
    const ran = steps(answer({ on_scene_at: '2026-09-04T14:44:00Z', status: 'responding' }))
    expect(ran[2]).toEqual({ title: 'On the way', detail: 'Not recorded', done: false })
  })

  it('says an officer set off before the control room took the alert as just that, never as a skipped step (M57 §5.2)', () => {
    const going = steps(answer({ en_route_at: '2026-09-04T14:42:30Z', status: 'responding' }))
    expect(going[1]).toEqual({ title: 'Dispatch acknowledged', detail: 'Not yet · an officer is already on the way', done: false })
    expect(going[2]).toEqual({ title: 'On the way', detail: 'Location shared', done: true })

    // On scene first, likewise; and once the alert closes with no acknowledgement, that is "Not recorded".
    expect(steps(answer({ on_scene_at: '2026-09-04T14:44:00Z', status: 'responding' }))[1]!.detail).toBe('Not yet · an officer is already on the way')
    expect(steps(answer({ en_route_at: '2026-09-04T14:42:30Z', status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' }))[1]!.detail).toBe('Not recorded')
  })

  it('a closed alert leaves nothing pending: what did not happen says so', () => {
    const closed = steps(answer({ acknowledged_at: '2026-09-04T14:41:16Z', status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' }))
    expect(closed.map((s) => s.done)).toEqual([true, true, false])
    expect(closed[2]!.detail).toBe('Not recorded')
  })

  it('no officer’s phone reached: only the true half of the board’s first step, and it says so', () => {
    expect(steps(answer({ alerted_handsets: 0 }), true, true)[0]).toEqual({ title: 'Alert sent to dispatch', detail: '9:41:02 AM · no officer’s phone was reached', done: true })
  })

  it('names no guard and gives no estimate: the server sends neither', () => {
    const all = steps(answer({ acknowledged_at: '2026-09-04T14:41:16Z', en_route_at: '2026-09-04T14:42:30Z', status: 'responding' }))
    const words = all.map((s) => `${s.title} ${s.detail ?? ''}`).join(' ')
    expect(words).not.toMatch(/Guard|Marcus|Whyte|ETA|\bmin\b|Live location/)
  })
})

describe('"14 seconds later"', () => {
  it('in seconds under a minute, and in minutes after', () => {
    expect([0, 1, 14, 59.4, 60, 754, -3].map(later)).toEqual(['0 seconds later', '1 second later', '14 seconds later', '59 seconds later', '1 minute later', '13 minutes later', '0 seconds later'])
  })
})

describe('the message in the live card: what dispatch recorded, and nothing before it', () => {
  it('nothing yet while dispatch has recorded nothing', () => {
    expect(boardBubble(answer(), reached, '119')).toBeNull()
    expect(boardBubble(null, reached, '119')).toBeNull()
  })

  it('each step dispatch records, in the app’s existing sentences', () => {
    const at = { acknowledged_at: '2026-09-04T14:41:16Z' }
    expect(boardBubble(answer(at), reached, '119')).toEqual({ from: 'Dispatch:', text: 'The control room has acknowledged your alert.' })
    expect(boardBubble(answer({ ...at, dispatched_at: '2026-09-04T14:42:00Z' }), reached, '119')).toEqual({ from: 'Dispatch:', text: 'An officer has been assigned and is being sent to you.' })
    expect(boardBubble(answer({ ...at, en_route_at: '2026-09-04T14:42:30Z' }), reached, '119')).toEqual({ from: 'Dispatch:', text: 'Your officer is on the way.' })
    expect(boardBubble(answer({ ...at, on_scene_at: '2026-09-04T14:44:00Z' }), reached, '119')).toEqual({ from: 'Dispatch:', text: 'Your officer has arrived at your lot.' })
  })

  it('the close, as the server recorded it', () => {
    // The close in the consoles' words (M98): Resolved, or False alarm.
    expect(boardBubble(answer({ status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' }), reached, '119')).toEqual({ from: 'Dispatch:', text: 'Resolved' })
    expect(boardBubble(answer({ status: 'false_alarm', resolved_at: '2026-09-04T14:50:00Z' }), reached, '119')).toEqual({ from: 'Dispatch:', text: 'False alarm' })
    // Cancelled from this phone: the resident's own act, never put in dispatch's mouth.
    expect(boardBubble(answer({ status: 'false_alarm', cancelled_at: '2026-09-04T14:41:08Z', resolved_at: '2026-09-04T14:41:08Z' }), reached, '119')).toEqual({ from: null, text: 'You cancelled this alert' })
  })

  it('no officer’s phone reached: the phone says so itself, with 119, and never as "Dispatch:"', () => {
    expect(boardBubble(answer({ alerted_handsets: 0 }), nobody, '119')).toEqual({ from: null, text: 'No officer’s phone was reached. Do not wait for this. Call 119 now.' })
  })
})

describe('each state, slot by slot', () => {
  it('the board’s own moment: "Alert sent" and "General emergency · Sent 9:41 AM"; the audio switch off', () => {
    const b = panicBoard(input())
    expect(b.title).toBe('Alert sent')
    expect(b.meta).toBe('General emergency · Sent 9:41 AM')
    expect(b.steps).toHaveLength(3)
    expect(b.audioOn).toBe(false)
  })

  it('before the press: how to send, and never anything about an alert', () => {
    const b = panicBoard(input({ phase: 'arm', alert: null }))
    expect(b).toEqual({ title: 'Hold to send an alert', meta: 'Keep holding for 1.5 seconds · release to stop', steps: [], bubble: null, audioOn: false })
    expect(holdNote(1500)).toBe(b.meta)
  })

  it('a demonstration session is told before it holds the button', () => {
    expect(panicBoard(input({ phase: 'arm', alert: null, demoRefusal: 'You are exploring a demonstration community.' })).meta).toBe('You are exploring a demonstration community.')
  })

  it('sending: "Sending…", "Do not close the app"', () => {
    expect(panicBoard(input({ phase: 'sending', alert: null }))).toMatchObject({ title: 'Sending…', meta: 'Do not close the app', steps: [] })
  })

  it('held with no signal: NOT "Alert sent": every step pending, and the phone’s own sentence in the card', () => {
    const b = panicBoard(input({ phase: 'queued', alert: null }))
    expect(b.title).toBe('Not sent yet')
    expect(b.meta).toBe('General emergency · Pressed 9:41 AM')
    expect(b.steps.every((s) => !s.done)).toBe(true)
    expect(b.bubble).toEqual({ from: null, text: input().held })
    // Slow or a bad answer, online: the control room may already have it.
    expect(panicBoard(input({ phase: 'queued', alert: null, noSignal: false })).title).toBe('Not confirmed yet')
  })

  it('never to be sent: the way to send a new one, and why, in the gold line', () => {
    const b = panicBoard(input({ phase: 'failed', alert: null, failure: 'The server did not accept this alert. Send a new one, or call 119 now.' }))
    expect(b).toEqual({ title: 'Hold to send an alert', meta: 'The server did not accept this alert. Send a new one, or call 119 now.', steps: [], bubble: null, audioOn: false })
  })

  it('closed: "Alert closed", what was recorded, and the close in the card', () => {
    const b = panicBoard(input({ phase: 'closed', alert: answer({ acknowledged_at: '2026-09-04T14:41:16Z', status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' }) }))
    expect(b.title).toBe('Alert closed')
    expect(b.meta).toBe('General emergency · Sent 9:41 AM')
    expect(b.bubble).toEqual({ from: 'Dispatch:', text: 'Resolved' })
  })
})

describe('what the client struck from the screen does not come back through the words (M42 §5 #5)', () => {
  const STRUCK = /Help requested|Call 119 as well|This alert has been closed by the control room|Hold to send a new alert|Can't hold it\?|\bDone\b|Lot 47|your location was sent|alerted|have been notified/i

  it.each([
    ['arm', input({ phase: 'arm', alert: null })],
    ['sending', input({ phase: 'sending', alert: null })],
    ['queued', input({ phase: 'queued', alert: null })],
    ['active', input()],
    ['active, acknowledged', input({ alert: answer({ acknowledged_at: '2026-09-04T14:41:16Z' }) })],
    ['active, on the way', input({ alert: answer({ acknowledged_at: '2026-09-04T14:41:16Z', en_route_at: '2026-09-04T14:42:30Z' }) })],
    ['active, nobody reached', input({ alert: answer({ alerted_handsets: 0 }), reach: nobody })],
    ['closed', input({ phase: 'closed', alert: answer({ status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' }) })],
    ['failed', input({ phase: 'failed', alert: null, failure: 'The server did not accept this alert. Send a new one, or call 119 now.' })],
  ] as const)('%s', (_, given) => {
    const b: PanicBoard = panicBoard(given)
    const words = [b.title, b.meta, ...b.steps.flatMap((s) => [s.title, s.detail ?? '']), b.bubble?.from ?? '', b.bubble?.text ?? ''].join(' | ')
    expect(words).not.toMatch(STRUCK)
    expect(b.audioOn).toBe(false)
  })

  it('the board’s own words, copied, not retyped', () => {
    expect(WORDS).toMatchObject({ sent: 'Alert sent', stepSent: 'Alert sent to gatehouse & dispatch', stepOnTheWay: 'On the way', dispatch: 'Dispatch:' })
  })
})

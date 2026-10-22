/**
 * A HELD PANIC SAYS WHY IT IS HELD, AND ONE THAT WILL NEVER BE SENT NEVER LOCKS THE SCREEN.
 *
 *   B117  a panic whose queued copy was refused, or has left the queue, or was never kept at all, left the screen on
 *         "Alert saved — not yet received … no connection" for good: fire() returned early on ANY saved record, so a
 *         new emergency could not be sent from the app, and the card said things that were not true.
 *   B122  the held card said "no connection … nobody has this alert" when the phone was online and the send was
 *         only slow or got a server error — while the server may already have fanned it out.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'

// The package root loads the UI kit (and Reanimated with it); these rules need only its clock and storage.
jest.mock('@gs/core', () => ({ ...jest.requireActual('@gs/core/lib'), ...jest.requireActual('@gs/core/fidelity') }))
jest.mock('@gs/core/api', () => ({ newStamp: jest.fn() }))
jest.mock('@gs/core/api/resident', () => ({ residentApi: {} }))
jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Warning: 'warning' } }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => ({ granted: false })), Accuracy: { Balanced: 3 } }))
jest.mock('@/offline/runtime', () => ({ outbox: { all: () => [] }, offer: jest.fn() }))
jest.mock('@/store', () => ({ useAppSelector: jest.fn() }))
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => null }))

import { failureOf, heldLine, isLive, NOT_KEPT, NOT_KNOWN } from '../usePanic'

const OPEN = { id: 7, status: 'open', cancellable_until: '2026-09-20T12:00:10Z', acknowledged_at: null, cancelled_at: null, server_time: '2026-09-20T12:00:00Z' }
const held = (extra: Record<string, unknown> = {}) => ({ alert: null, opId: 'op-1', pressedAt: '2026-09-20T12:00:00Z', withPosition: false, ...extra })
const op = (state: string, message?: string) => ({ id: 'op-1', state, error: message ? { message } : null })

describe('B117: a panic that will never be sent is said to be so, and does not stop the next one', () => {
  it('refused by the server: the server’s own sentence', () => {
    expect(failureOf(held(), [op('refused', 'Your account is suspended.')])).toBe('Your account is suspended.')
    expect(isLive(held(), [op('refused', 'Your account is suspended.')])).toBe(false)
  })

  it('refused on the direct send: said at once, before the queue tries its copy', () => {
    expect(failureOf(held({ why: 'refused', message: 'Not accepted.' }), [op('pending')])).toBe('Not accepted.')
  })

  it('no longer in the queue (a confirmed act leaves after a day): not known whether it got through', () => {
    expect(failureOf(held(), [])).toBe(NOT_KNOWN)
    expect(isLive(held(), [])).toBe(false)
  })

  it('never kept on the phone at all: said so, not "saved"', () => {
    expect(failureOf(held({ opId: null }), [])).toBe(NOT_KEPT)
  })

  it('still waiting in the queue: live, so a second press does not make a second alert', () => {
    expect(failureOf(held(), [op('pending')])).toBeNull()
    expect(isLive(held(), [op('pending')])).toBe(true)
  })

  it('delivered by the queue: not a failure: it becomes the alert', () => {
    expect(failureOf(held(), [op('confirmed')])).toBeNull()
  })

  it('an open alert is live; a closed one is not', () => {
    expect(isLive({ ...held(), alert: OPEN }, [])).toBe(true)
    expect(isLive({ ...held(), alert: { ...OPEN, status: 'resolved' } }, [])).toBe(false)
    expect(isLive(null, [])).toBe(false)
  })

  it('only a LIVE alert stops a press, and the screen can send a new one after a closed or failed one', () => {
    const hook = readFileSync(path.join(__dirname, '..', 'usePanic.ts'), 'utf8')
    const screen = readFileSync(path.join(__dirname, '..', 'screens', 'PanicScreen.tsx'), 'utf8')
    expect(hook).toMatch(/if \(isFidelity \|\| firing\.current \|\| isLive\(saved, outbox\.all\(\)\)\) return/)
    expect(hook).toMatch(/sending \? 'sending' : !saved \? 'arm' : closedAs \? 'closed' : a \? 'active' : failure \? 'failed' : 'queued'/)
    // A failed alert: the circle is the button again, right there, under the reason it failed.
    expect(screen).toMatch(/const canRaise = phase === 'arm' \|\| phase === 'failed'/)
    // M42 §5 #5 took away "Done" and "Hold to send a new alert". So an alert already over when the screen opens is not
    // shown again (the screen opens ready to send), and leaving the screen puts a closed or failed one away.
    expect(hook).toMatch(/return belongsToSomeoneElse\(kept, principal\) \|\| isOver\(kept\) \? null : kept/)
    // Over for certain only — never "not known", which a queue not yet read back from disk also looks like.
    expect(hook).toMatch(/const over = phase === 'closed' \|\| \(phase === 'failed' && failure !== NOT_KNOWN\)/)
    expect(hook).toMatch(/if \(!isFidelity && putAway\.current\) box\(\)\.remove\(\)/)
    // …and never a live one: leaving does not cancel anything.
    expect(screen).not.toMatch(/m\.cancel\(|m\.finish\(/)
  })
})

describe('B122: "no connection" only when there is none', () => {
  it('slow, or a server error, with the phone online: not "no connection", and not "nobody has it"', () => {
    for (const why of ['slow', 'server'] as const) {
      const line = heldLine(why, true)
      expect(line).not.toMatch(/no connection|nobody has/i)
      expect(line).toMatch(/call 119/)
    }
    expect(heldLine('slow', true)).toMatch(/may already have it/)
  })

  it('no signal, or a record an older build saved, which has no reason, is "no connection"', () => {
    expect(heldLine('no_signal', true)).toMatch(/no connection/)
    expect(heldLine(undefined, true)).toMatch(/no connection/)
    expect(heldLine('slow', false)).toMatch(/no connection/)
  })
})

/**
 * Where the server may reach this phone when the app is CLOSED — the two pure pieces:
 * `createPushRegistration` (registration.ts) and `createDedupe` (dedupe.ts).
 *
 * Everything the registration touches is handed in, so this runs with no phone, no network and no
 * expo-notifications: exactly the point of splitting the logic out of runtime.ts.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { canQueue } from '../../offline/outbox'
import { createDedupe, dedupeKey } from '../dedupe'
import { createPushRegistration, nudgePushRegistration, onPushRegistrationNudge, type RegisterBody, type RegistrationDeps } from '../registration'
import type { PushToken } from '../runtime'

const TOKEN: PushToken = { ok: true, token: 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]', platform: 'android' }
const OTHER: PushToken = { ok: true, token: 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]', platform: 'android' }

type Harness = {
  deps: RegistrationDeps
  sent: RegisterBody[]
  calls: { register: number; unregister: number; gaveUp: number }
  answer: PushToken
  accept: boolean
  unregisterAnswer: () => Promise<boolean>
  stored: { owner: string } | null
}

function harness(): Harness {
  const h: Harness = {
    sent: [],
    calls: { register: 0, unregister: 0, gaveUp: 0 },
    answer: TOKEN,
    accept: true,
    unregisterAnswer: async () => true,
    stored: null,
    deps: undefined as unknown as RegistrationDeps,
  }
  h.deps = {
    pushToken: async () => h.answer,
    register: async (body) => {
      h.calls.register++
      h.sent.push(body)
      return h.accept
    },
    unregister: async () => {
      h.calls.unregister++
      return h.unregisterAnswer()
    },
    giveUpDeviceToken: async () => {
      h.calls.gaveUp++
    },
    marker: {
      get: () => h.stored,
      set: (value) => {
        h.stored = value
      },
      remove: () => {
        h.stored = null
      },
    },
  }
  return h
}

describe('quiet hours ride on the registration (M50 §4.2 #5)', () => {
  const NIGHT = { on: true, from: '22:00', to: '07:00' }

  it('sends them, registers again when they are switched off, and not when they are the same', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)

    expect(await push.sync('resident:7', [], NIGHT)).toBe('registered')
    expect(h.sent[0]?.quiet).toEqual(NIGHT)
    expect(await push.sync('resident:7', [], { ...NIGHT })).toBe('unchanged')

    expect(await push.sync('resident:7', [], { ...NIGHT, on: false })).toBe('registered')
    expect(h.sent[1]?.quiet).toEqual({ ...NIGHT, on: false })
  })

  it('leaves them out for an app that has none (the Guard App)', async () => {
    const h = harness()
    await createPushRegistration(h.deps).sync('guard:41', [])
    expect(h.sent[0]).not.toHaveProperty('quiet')
  })
})

describe('telling the server where this phone is', () => {
  it('registers once for an owner, token and set of switches, and says so', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)

    await expect(push.sync('guard:41', ['messages'])).resolves.toBe('registered')
    await expect(push.sync('guard:41', ['messages'])).resolves.toBe('unchanged')

    expect(h.calls.register).toBe(1)
    expect(h.sent[0]).toEqual({ token: TOKEN.token, platform: 'android', muted: ['messages'] })
    expect(h.stored).toEqual({ owner: 'guard:41' })
  })

  it('registers again when a switch changes, when the token rotates, and for a different owner', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)

    await push.sync('resident:7', [])
    await expect(push.sync('resident:7', ['notices', 'bookings'])).resolves.toBe('registered')
    // Sorted, so the same set in another order is not a second registration.
    await expect(push.sync('resident:7', ['bookings', 'notices'])).resolves.toBe('unchanged')

    h.answer = OTHER
    await expect(push.sync('resident:7', ['bookings', 'notices'])).resolves.toBe('registered')

    await expect(push.sync('resident:9', ['bookings', 'notices'])).resolves.toBe('registered')

    expect(h.sent.map((body) => body.muted)).toEqual([[], ['bookings', 'notices'], ['bookings', 'notices'], ['bookings', 'notices']])
    expect(h.sent[3]!.token).toBe(OTHER.token)
  })

  it('answers why there is no token, and never asks the server for one it has not got', async () => {
    for (const reason of ['web', 'expo_go', 'not_permitted', 'no_project'] as const) {
      const h = harness()
      h.answer = { ok: false, reason }
      await expect(createPushRegistration(h.deps).sync('guard:1', [])).resolves.toBe(reason)
      expect(h.calls.register).toBe(0)
      expect(h.stored).toBeNull()
    }
  })

  it('never throws, and never records a registration the server refused', async () => {
    const h = harness()
    h.accept = false
    const push = createPushRegistration(h.deps)

    await expect(push.sync('guard:41', [])).resolves.toBe('failed')
    expect(h.stored).toBeNull()

    // A phone that cannot even be asked for a token is a "failed", not a crash.
    h.deps.pushToken = async () => {
      throw new Error('no phone here')
    }
    await expect(push.sync('guard:41', [])).resolves.toBe('failed')
  })

  it('does one thing at a time: a permission grant, a rotation and a switch in the same second are one registration', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)

    const outcomes = await Promise.all([push.sync('guard:41', []), push.sync('guard:41', []), push.sync('guard:41', [])])

    expect(h.calls.register).toBe(1)
    expect(outcomes).toEqual(['registered', 'unchanged', 'unchanged'])
  })
})

describe('the Guard App\'s call-style readiness rides along (GUARD_RING_DESIGN §8.3)', () => {
  const READY = { v: 1, configured: true, notifications: 'on', ring_channel: 'on', full_screen: 'on', dnd_bypass: 'off', battery: 'optimised', background: 'allowed', alarm_volume: 'ok', alarm_tone: 'set', screen_lock: 'on', sdk: 35, maker: 'samsung' }

  it('sends the report with the token when the app supplies one', async () => {
    const h = harness()
    h.deps.ringReport = async () => ({ ...READY })
    await expect(createPushRegistration(h.deps).sync('guard:41', [])).resolves.toBe('registered')
    expect(h.sent[0]).toEqual({ token: TOKEN.token, platform: 'android', muted: [], ring: READY })
  })

  it('a changed report registers again; the same report does not, whatever order its keys come in', async () => {
    const h = harness()
    let report: Record<string, string | number | boolean> = { ...READY }
    h.deps.ringReport = async () => report
    const push = createPushRegistration(h.deps)
    await push.sync('guard:41', [])
    report = Object.fromEntries(Object.entries(READY).reverse())
    await expect(push.sync('guard:41', [])).resolves.toBe('unchanged')
    report = { ...READY, full_screen: 'off' }
    await expect(push.sync('guard:41', [])).resolves.toBe('registered')
    expect(h.sent).toHaveLength(2)
    expect(h.sent[1]!.ring).toEqual({ ...READY, full_screen: 'off' })
  })

  it('a report that cannot be read is left out, and the token is still registered', async () => {
    const h = harness()
    h.deps.ringReport = async () => {
      throw new Error('no module')
    }
    await expect(createPushRegistration(h.deps).sync('guard:41', [])).resolves.toBe('registered')
    expect(h.sent[0]).toEqual({ token: TOKEN.token, platform: 'android', muted: [] })
    expect('ring' in h.sent[0]!).toBe(false)
  })

  it('the Resident App supplies none, and its body is exactly what it always was', async () => {
    const h = harness()
    await createPushRegistration(h.deps).sync('resident:7', ['notices'])
    expect(h.sent[0]).toEqual({ token: TOKEN.token, platform: 'android', muted: ['notices'] })
  })
})

describe('sign-out: BEFORE the API token is dropped', () => {
  it('tells the server, and keeps the device token because the server was told', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)
    await push.sync('guard:41', [])

    await push.signOut()

    expect(h.calls.unregister).toBe(1)
    expect(h.calls.gaveUp).toBe(0)
    expect(h.stored).toBeNull()
  })

  it('gives up the device token instead when the server cannot be told', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)
    await push.sync('resident:7', [])

    h.unregisterAnswer = async () => false
    await push.signOut()

    expect(h.calls.gaveUp).toBe(1)
    expect(h.stored).toBeNull()
  })

  it('is never held up by a dead connection', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps, 20)
    await push.sync('guard:41', [])

    h.unregisterAnswer = () => new Promise<boolean>(() => undefined) // never answers
    const at = Date.now()
    await push.signOut()

    expect(Date.now() - at).toBeLessThan(1000)
    expect(h.calls.gaveUp).toBe(1)
    expect(h.stored).toBeNull()
  })

  it('says nothing at all when this phone was never registered', async () => {
    const h = harness()
    h.answer = { ok: false, reason: 'expo_go' }
    const push = createPushRegistration(h.deps)
    await push.sync('guard:41', [])

    await push.signOut()

    expect(h.calls.unregister).toBe(0)
    expect(h.calls.gaveUp).toBe(0)
  })

  it('a marker left by an earlier run of the app is enough to de-register', async () => {
    const h = harness()
    h.stored = { owner: 'guard:41' }
    await createPushRegistration(h.deps).signOut()

    expect(h.calls.unregister).toBe(1)
    expect(h.stored).toBeNull()
  })

  it('a revoked session tells nobody, there is nobody to tell, and gives the device token up', async () => {
    const h = harness()
    const push = createPushRegistration(h.deps)
    await push.sync('guard:41', [])

    await push.revoked()

    expect(h.calls.unregister).toBe(0)
    expect(h.calls.gaveUp).toBe(1)
    expect(h.stored).toBeNull()
  })

  it('a registration still in flight cannot leave this phone registered for somebody who has left it', async () => {
    const h = harness()
    let land: (ok: boolean) => void = () => undefined
    const asked = new Promise<void>((ready) => {
      h.deps.register = (body) => {
        h.calls.register++
        h.sent.push(body)
        ready()
        return new Promise<boolean>((resolve) => {
          land = resolve
        })
      }
    })
    const push = createPushRegistration(h.deps, 50)

    const flight = push.sync('guard:41', [])
    await asked
    const out = push.signOut()
    land(true) // the server accepted it AFTER the guard handed the phone back

    await expect(flight).resolves.toBe('failed')
    await out

    expect(h.stored).toBeNull()
    expect(h.calls.gaveUp).toBeGreaterThan(0)
  })
})

describe('a nudge', () => {
  it('reaches every listener and survives one that throws', () => {
    const seen: string[] = []
    const stopBad = onPushRegistrationNudge(() => {
      throw new Error('a settings screen is never broken by this')
    })
    const stopGood = onPushRegistrationNudge(() => seen.push('looked'))

    nudgePushRegistration()
    expect(seen).toEqual(['looked'])

    stopGood()
    stopBad()
    nudgePushRegistration()
    expect(seen).toEqual(['looked'])
  })
})

describe('one banner for one thing', () => {
  it('drops the local banner when the server\'s push for the same (type, id) got here first', () => {
    const dedupe = createDedupe()
    expect(dedupe.remoteArrived({ type: 'ticket_update', id: '41' })).toBe(true)
    expect(dedupe.localAllowed({ type: 'ticket_update', id: 41 })).toBe(false)
  })

  it('drops the push\'s banner when the local notifier announced it first', () => {
    const dedupe = createDedupe()
    expect(dedupe.localAllowed({ type: 'notice', id: 9 })).toBe(true)
    expect(dedupe.remoteArrived({ type: 'notice', id: '9' })).toBe(false)
  })

  it('never drops a SECOND push for the same thing: the server has already decided it is news', () => {
    const dedupe = createDedupe()
    expect(dedupe.remoteArrived({ type: 'site_alert', id: 3 })).toBe(true)
    expect(dedupe.remoteArrived({ type: 'site_alert', id: 3 })).toBe(true)
  })

  it('never confuses two different records, or two types about one record', () => {
    const dedupe = createDedupe()
    dedupe.remoteArrived({ type: 'ticket_update', id: 41 })
    expect(dedupe.localAllowed({ type: 'ticket_update', id: 42 })).toBe(true)
    expect(dedupe.localAllowed({ type: 'notice', id: 41 })).toBe(true)
  })

  it('shows both rather than neither when a payload cannot be told apart', () => {
    const dedupe = createDedupe()
    expect(dedupeKey({ type: 'panic_update' })).toBeNull()
    expect(dedupeKey({ type: 'notice', id: '../../etc' })).toBeNull()
    expect(dedupeKey(null)).toBeNull()
    expect(dedupe.remoteArrived({ type: 'panic_update' })).toBe(true)
    expect(dedupe.localAllowed({ type: 'panic_update' })).toBe(true)
  })

  it('forgets after the window, and at sign-out', () => {
    let at = 1_000
    const dedupe = createDedupe(() => at, 60_000)
    dedupe.remoteArrived({ type: 'notice', id: 5 })
    at += 60_001
    expect(dedupe.localAllowed({ type: 'notice', id: 5 })).toBe(true)

    dedupe.remoteArrived({ type: 'notice', id: 6 })
    dedupe.clear()
    expect(dedupe.localAllowed({ type: 'notice', id: 6 })).toBe(true)
  })
})

describe('the registration keeps the push token to itself', () => {
  it('is never saved for later: neither half may ride in the offline queue', () => {
    // De-registering names the API token that is about to be deleted; a copy kept for later could never be sent.
    expect(canQueue('push.register')).toBe(false)
    expect(canQueue('push.unregister')).toBe(false)
  })

  it('never logs, and never writes the token to the key-value store', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'registration.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    expect(source).not.toMatch(/console\.|Sentry|logger/)
    // The marker holds an owner and nothing else.
    expect(source).toMatch(/marker\.set\(\{ owner \}\)/)
  })
})

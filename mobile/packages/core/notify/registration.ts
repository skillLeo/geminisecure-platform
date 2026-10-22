/**
 * Telling the server where to reach this phone when the app is CLOSED — the logic, with
 * everything it touches handed in, so it can be tested without a phone.
 *
 *   sync(owner, muted)   on an active session, when notification permission is granted later, when the
 *                        push token rotates and when a notification switch changes. Gets the Expo token
 *                        (runtime.pushToken) and registers it — ONCE per (owner, token, switches) for the
 *                        life of the process; a fresh start registers again, which costs one small request
 *                        and heals anything the server revoked meanwhile.
 *   signOut()            BEFORE the API token is dropped: de-register. If the server cannot be told, the
 *                        phone gives up its device token instead, so Expo stops delivering to it.
 *   revoked()            the server already ended the session (401): there is nobody to tell — the phone
 *                        gives up its device token and forgets.
 *
 * WHAT IT NEVER DOES
 *   - block anything: every path is awaited by nobody at boot, and no failure throws;
 *   - log or keep the token: it lives in memory for this process only; the key-value store holds nothing
 *     but "registered for <owner>";
 *   - use the offline outbox: registration is online only — "try again next start";
 *   - pretend: in Expo Go, on the web, without permission or without an EAS project id there is no token,
 *     `sync` answers why, silently, and the local notifier stays the way the app notifies while open.
 */

import type { PushToken } from './runtime'

/**
 * `ring` is the Guard App's call-style readiness report (apps/guard/src/features/ring): what the phone says it can
 * do, read at the moment of registering. Core does not know what is in it — it is sent as it came, and only when an
 * app supplies it. The server decides what it means (`ring_state`), and never refuses the token because of it.
 */
export type RingReport = Record<string, string | number | boolean>

/**
 * Quiet hours on this phone (the Resident App's Notifications, board 20; M50 §4.2 #5): HH:MM on the ESTATE's clock.
 * The server holds every push but the urgent ones inside them. Sent with every registration; one without it has none.
 */
export type QuietReport = { on: boolean; from: string; to: string }

export type RegisterBody = { token: string; platform: 'ios' | 'android'; muted: string[]; ring?: RingReport; quiet?: QuietReport }

export type RegistrationDeps = {
  pushToken: () => Promise<PushToken>
  /**
   * The Guard App only: the call-style readiness report, or undefined when this phone has none (Expo Go, iOS, an
   * older build). A report that changed is a reason to register again; the same report is not. Never throws —
   * a report that cannot be read is simply left out, and the token is registered without it.
   */
  ringReport?: () => Promise<RingReport | undefined>
  /** True when the server accepted it. Never throws. */
  register: (body: RegisterBody) => Promise<boolean>
  /** True when the server answered. Never throws. */
  unregister: () => Promise<boolean>
  /** Best effort: hand the device token back to the OS, so nothing more is delivered to this install. */
  giveUpDeviceToken: () => Promise<void>
  marker: { get: () => { owner: string } | null; set: (value: { owner: string }) => void; remove: () => void }
}

export type SyncOutcome = 'registered' | 'unchanged' | 'failed' | 'web' | 'expo_go' | 'not_permitted' | 'no_project'

export type PushRegistration = {
  sync: (owner: string, muted: readonly string[], quiet?: QuietReport) => Promise<SyncOutcome>
  signOut: () => Promise<void>
  revoked: () => Promise<void>
}

/** Sign-out waits this long for the server and no longer: a guard handing a phone back is not kept on a spinner by a dead connection. */
export const SIGN_OUT_WAIT_MS = 4000

export function createPushRegistration(deps: RegistrationDeps, signOutWaitMs: number = SIGN_OUT_WAIT_MS): PushRegistration {
  let last: { owner: string; token: string; muted: string; ring: string; quiet: string } | null = null

  const readRing = async (): Promise<RingReport | undefined> => {
    if (!deps.ringReport) return undefined
    try {
      const report = await deps.ringReport()
      return report && typeof report === 'object' ? report : undefined
    } catch {
      return undefined
    }
  }
  let queue: Promise<unknown> = Promise.resolve()

  const soon = (work: Promise<boolean>): Promise<boolean> =>
    new Promise<boolean>((resolve) => {
      const timer = setTimeout(() => resolve(false), signOutWaitMs)
      work.then((value) => { clearTimeout(timer); resolve(value) }, () => { clearTimeout(timer); resolve(false) })
    })

  // One at a time: a permission grant, a rotation and a switch can all fire in the same second.
  const inTurn = <T>(work: () => Promise<T>): Promise<T> => {
    const next = queue.then(work, work)
    queue = next.catch(() => undefined)
    return next
  }

  const quietly = async (work: () => Promise<unknown>): Promise<void> => {
    try { await work() } catch { /* best effort */ }
  }

  // Each sign-out or revocation starts a new session. A registration still in flight from the one before must not
  // leave this phone registered for somebody who has just left it.
  let session = 0

  const ended = async (tellServer: boolean): Promise<void> => {
    session++
    // Let a registration in flight finish — but not for long: signing out is never held up by a dead connection.
    await soon(queue.then(() => true, () => true))
    let marked = false
    try { marked = deps.marker.get() !== null } catch { /* an unreadable store is "not marked" */ }
    const registered = last !== null || marked
    last = null
    if (!registered) return // Expo Go, the web, never permitted: nothing was ever registered, so nothing is said
    let told = false
    if (tellServer) await quietly(async () => { told = await soon(deps.unregister()) })
    if (!told) await quietly(deps.giveUpDeviceToken)
    await quietly(async () => deps.marker.remove())
  }

  return {
    sync: (owner, muted, quiet) =>
      inTurn(async (): Promise<SyncOutcome> => {
        const mine = session
        try {
          const answer = await deps.pushToken()
          if (!answer.ok) return answer.reason
          const switches = [...muted].sort().join(',')
          const ring = await readRing()
          const ringKey = ring === undefined ? '' : JSON.stringify(Object.keys(ring).sort().map((k) => [k, ring[k]]))
          // Quiet hours switched on or off are a reason to register again; the same hours are not.
          const quietKey = quiet === undefined ? '' : `${quiet.on ? 1 : 0}:${quiet.from}-${quiet.to}`
          if (last && last.owner === owner && last.token === answer.token && last.muted === switches && last.ring === ringKey && last.quiet === quietKey) return 'unchanged'
          const body: RegisterBody = { token: answer.token, platform: answer.platform, muted: [...muted].sort() }
          if (ring !== undefined) body.ring = ring
          if (quiet !== undefined) body.quiet = quiet
          const accepted = await deps.register(body)
          if (!accepted) return 'failed'
          if (mine !== session) {
            // The owner signed out while this was on its way: the server may now hold an address for nobody.
            await quietly(deps.giveUpDeviceToken)
            return 'failed'
          }
          last = { owner, token: answer.token, muted: switches, ring: ringKey, quiet: quietKey }
          deps.marker.set({ owner })
          return 'registered'
        } catch {
          return 'failed'
        }
      }),

    signOut: () => ended(true),
    revoked: () => ended(false),
  }
}

/* ---- "something that affects registration just changed" ------------------------------------------ */

const listeners = new Set<() => void>()

/** A switch was flipped, or permission was just granted: whoever keeps the registration should look again. */
export function nudgePushRegistration(): void {
  listeners.forEach((fn) => { try { fn() } catch { /* a listener never breaks a settings screen */ } })
}

export function onPushRegistrationNudge(fn: () => void): () => void {
  listeners.add(fn)
  return () => void listeners.delete(fn)
}

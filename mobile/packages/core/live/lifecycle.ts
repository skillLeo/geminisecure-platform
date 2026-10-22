/**
 * WHEN THE SOCKET IS OPEN, AND WHAT THE PHONE MAY SAY ABOUT IT (DESIGN §8.3, §8.5, §5.4, §5.6).
 *
 * CONNECT only while all of these hold: live was started for a session (active, or a resident's pending
 * account, which may follow its own claim), the app is in the foreground, the device is online, and this
 * is not the fidelity harness.
 *
 *   background      disconnect 10 s after the app goes to the background. Push covers a closed app, and
 *                   because nothing is sent while backgrounded the idle window runs exactly as it does today
 *   a drop          the server closed a working socket (a deploy, an eviction): wait 0.5–3 s, to spread the
 *                   reconnect herd, then back off
 *   a failure       1, 2, 4, 8, 15, then 30 s, ±30 % jitter (the resident app in data-light mode, with no
 *                   safety screen open, up to 120 s — fallback.ts)
 *   online / resume the backoff resets: catch-up for every channel starts AT ONCE, in parallel with a forced
 *                   reconnect — the phone does not wait out a zombie socket's 30 s + 10 s ping (§5.4)
 *
 * While the socket is DOWN, catch-up is the poll (fallback.ts cadence). While it is UP, a catch-up still runs
 * every 5 minutes: a message the network lost silently cannot hide for longer than that.
 *
 * THE STATUS — the same conditions on both apps and on the web (rule 7, §8.5). "Live" only when the socket
 * is up, every channel is subscribed, catch-up is done, the relay says it is keeping up (`relay.ok`), and its
 * pulse arrived within the last 25 s. Anything else is said, after 3 s (so a resume that reconnects within a
 * second never flashes a line):
 *
 *   paused    the socket is down or connecting, a channel is still catching up, or catch-up is failing
 *   delayed   the relay is behind, or its pulse has stopped
 *   refused   a channel this person was given is refused to them
 *
 * Offline, the app's own offline line says it, so this one says nothing. In fidelity mode nothing runs.
 */

import { useSyncExternalStore } from 'react'
import { AppState } from 'react-native'
import type { LiveApp } from './configure'
import { backoffMs, downCadenceMs, serverCloseDelayMs } from './fallback'
import type { SyncState } from './catchUp'

export const BACKGROUND_GRACE_MS = 10_000
/** An attempt that has not opened by then has failed. */
export const ATTEMPT_TIMEOUT_MS = 15_000
export const SAFETY_CATCH_UP_MS = 5 * 60_000
export const PULSE_STALE_MS = 25_000
export const BANNER_GRACE_MS = 3_000

/* ── the environment: AppState, replaceable for tests and the headless harness ─────────────────────── */

export type LifecycleEnvironment = {
  appState(): string
  onAppStateChange(fn: (state: string) => void): () => void
  /** 0 ≤ r < 1, for the jitter. */
  random?(): number
}

export const reactNativeEnvironment: LifecycleEnvironment = {
  appState: () => AppState.currentState ?? 'active',
  onAppStateChange(fn) {
    const sub = AppState.addEventListener('change', fn)
    return () => sub.remove()
  },
}

/* ── the controller ────────────────────────────────────────────────────────────────────────────── */

export type SocketPhase = 'closed' | 'connecting' | 'open'

export type LifecycleFacts = {
  app: LiveApp
  fidelity: boolean
  sessionLive(): boolean
  online(): boolean
}

export type LifecycleDriver = {
  /** Open the socket. It reports back through `opened()` or `closed()`. */
  open(): void
  /** Close the socket. Nothing reports back. */
  close(): void
  /** The online edge, a resume or a Refresh: catch every channel up now. */
  catchUpAll(reason: 'resume' | 'online' | 'refresh'): void
  /** The fallback poll while down, and the 5-minute safety catch-up while up. */
  poll(): void
  /** Anything the status depends on changed. */
  changed(): void
}

export class Lifecycle {
  socket: SocketPhase = 'closed'
  foreground = true
  private started = false
  private attempts = 0
  private lastOnline = true
  private retryTimer: ReturnType<typeof setTimeout> | null = null
  private attemptTimer: ReturnType<typeof setTimeout> | null = null
  private backgroundTimer: ReturnType<typeof setTimeout> | null = null
  private pollTimer: ReturnType<typeof setTimeout> | null = null
  private pollEvery = 0
  private offAppState: (() => void) | null = null

  constructor(
    private readonly facts: LifecycleFacts,
    private readonly env: LifecycleEnvironment,
    private readonly driver: LifecycleDriver,
  ) {}

  private random(): number {
    return this.env.random ? this.env.random() : Math.random()
  }

  /** Whether the socket should be open now. */
  get wanted(): boolean {
    return this.started && !this.facts.fidelity && this.facts.sessionLive() && this.facts.online() && this.foreground
  }

  get isStarted(): boolean {
    return this.started
  }

  start(): void {
    if (this.started) return
    this.started = true
    this.foreground = this.env.appState() !== 'background'
    this.lastOnline = this.facts.online()
    this.offAppState = this.env.onAppStateChange((state) => this.appStateChanged(state))
    this.evaluate()
  }

  stop(): void {
    this.started = false
    this.offAppState?.()
    this.offAppState = null
    for (const t of [this.retryTimer, this.attemptTimer, this.backgroundTimer, this.pollTimer]) if (t) clearTimeout(t)
    this.retryTimer = this.attemptTimer = this.backgroundTimer = this.pollTimer = null
    this.pollEvery = 0
    if (this.socket !== 'closed') this.driver.close()
    this.socket = 'closed'
    this.attempts = 0
    this.driver.changed()
  }

  /** The store changed: the session, or the network. */
  factsChanged(): void {
    if (!this.started) return
    const online = this.facts.online()
    const edge = online && !this.lastOnline
    const lost = !online && this.lastOnline
    this.lastOnline = online
    if (edge) return this.resume('online')
    if (lost) this.closeNow()
    this.evaluate()
  }

  /**
   * A safety screen opened or closed, or data-light changed: the cadence MAY have changed. When it did, the poll
   * starts again on the new one from now; when it did not (a second safety screen, data-light on the guard app),
   * the poll keeps its time rather than being put off.
   */
  cadenceChanged(): void {
    if (this.started) this.schedulePoll(false)
  }

  private closeNow(): void {
    this.cancel('retry')
    this.cancel('attempt')
    if (this.socket !== 'closed') this.driver.close()
    this.socket = 'closed'
  }

  private evaluate(): void {
    if (!this.wanted) {
      this.closeNow()
    } else if (this.socket === 'closed' && !this.retryTimer) {
      this.attempt()
    }
    this.schedulePoll(false)
    this.driver.changed()
  }

  private attempt(): void {
    this.cancel('retry')
    this.cancel('attempt')
    this.socket = 'connecting'
    this.attemptTimer = setTimeout(() => {
      this.attemptTimer = null
      if (this.socket === 'connecting') this.failed()
    }, ATTEMPT_TIMEOUT_MS)
    this.driver.open()
    this.driver.changed()
  }

  /** The socket opened. */
  opened(): void {
    if (!this.started) return
    this.cancel('attempt')
    this.socket = 'open'
    this.attempts = 0
    this.schedulePoll(true)
    this.driver.changed()
  }

  /** The socket closed without being asked to, or an attempt failed to open. */
  closed(): void {
    if (!this.started || this.socket === 'closed') return
    const wasOpen = this.socket === 'open'
    this.cancel('attempt')
    this.driver.close() // the library's own retry loop stops: this controller owns every reconnect
    this.socket = 'closed'
    if (!this.wanted) return this.evaluate()
    if (wasOpen) this.retryIn(serverCloseDelayMs(this.random()))
    else this.retryIn(backoffMs(++this.attempts, this.facts.app, this.random()))
    this.schedulePoll(false)
    this.driver.changed()
  }

  /*
   * NOT `schedulePoll(true)` on a failed attempt: a network that refuses WebSockets at once fails the attempts at
   * 1, 3, 7, 15 s, and restarting the poll's timer at each one held a safety screen's first 5 s poll back to 12 s.
   * The poll keeps its own time while the socket stays down; only a change of cadence (the drop of an open socket,
   * a safety screen, data-light) starts it again.
   */
  private failed(): void {
    this.driver.close()
    this.socket = 'closed'
    if (!this.wanted) return this.evaluate()
    this.retryIn(backoffMs(++this.attempts, this.facts.app, this.random()))
    this.schedulePoll(false)
    this.driver.changed()
  }

  private retryIn(ms: number): void {
    this.cancel('retry')
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null
      if (this.wanted && this.socket === 'closed') this.attempt()
    }, ms)
  }

  appStateChanged(state: string): void {
    if (!this.started) return
    if (state === 'background') {
      if (this.foreground && !this.backgroundTimer) {
        this.backgroundTimer = setTimeout(() => {
          this.backgroundTimer = null
          this.foreground = false
          this.evaluate()
        }, BACKGROUND_GRACE_MS)
      }
      return
    }
    if (state !== 'active') return // iOS's 'inactive' is a pull-down shade, not a departure
    const away = !this.foreground || this.backgroundTimer !== null
    this.cancel('background')
    this.foreground = true
    if (away) this.resume('resume')
    else this.evaluate()
  }

  /** Resume, the online edge: catch everything up now, and reconnect now — in parallel, never one after the other. */
  private resume(reason: 'resume' | 'online'): void {
    this.attempts = 0
    if (!this.wanted) return this.evaluate()
    this.driver.catchUpAll(reason)
    this.closeNow()
    this.attempt()
    this.schedulePoll(true)
  }

  /** The person pressed Refresh: catch up now, and if the socket is down, try it again now. */
  refresh(): void {
    if (!this.started) return
    this.attempts = 0
    if (!this.wanted) return
    this.driver.catchUpAll('refresh')
    if (this.socket === 'closed') this.attempt()
  }

  private schedulePoll(force: boolean): void {
    const every = this.wanted ? (this.socket === 'open' ? SAFETY_CATCH_UP_MS : downCadenceMs(this.facts.app)) : 0
    if (!force && every === this.pollEvery && (this.pollTimer || every === 0)) return
    this.cancel('poll')
    this.pollEvery = every
    if (every === 0) return
    this.pollTimer = setTimeout(() => {
      this.pollTimer = null
      this.pollEvery = 0
      if (!this.wanted) return
      this.driver.poll()
      this.schedulePoll(true)
    }, every)
  }

  private cancel(which: 'retry' | 'attempt' | 'background' | 'poll'): void {
    const key = `${which}Timer` as 'retryTimer' | 'attemptTimer' | 'backgroundTimer' | 'pollTimer'
    const t = this[key]
    if (t) clearTimeout(t)
    this[key] = null
    if (which === 'poll') this.pollEvery = 0
  }
}

/* ── the status ────────────────────────────────────────────────────────────────────────────────── */

export type LiveState = 'off' | 'offline' | 'connecting' | 'down' | 'catching-up' | 'failing' | 'delayed' | 'refused' | 'live'
export type LiveBanner = 'none' | 'paused' | 'delayed' | 'refused'

export type LiveStatus = {
  state: LiveState
  /** What LiveStatusBanner draws. */
  banner: LiveBanner
  /** The last moment the phone knew it was up to date (epoch ms). `null` while live, or before it ever was. */
  updatedAt: number | null
}

export type StatusFacts = {
  started: boolean
  fidelity: boolean
  sessionLive: boolean
  online: boolean
  foreground: boolean
  socket: SocketPhase
  channels: SyncState[]
  catchUpFailing: boolean
  relayOk: boolean | null
  lastPulseAt: number | null
  openedAt: number | null
}

export function deriveState(f: StatusFacts, now: number): LiveState {
  if (!f.started || f.fidelity || !f.sessionLive || !f.foreground) return 'off'
  if (!f.online) return 'offline'
  if (f.socket !== 'open') return f.socket === 'connecting' ? 'connecting' : 'down'
  if (f.catchUpFailing) return 'failing'
  if (f.channels.some((s) => s === 'idle' || s === 'catching')) return 'catching-up'
  // The first pulse is due within 10 s of opening: until 25 s after opening, no pulse is not yet a dead relay.
  const pulseRef = Math.max(f.lastPulseAt ?? 0, f.openedAt ?? 0)
  if (f.relayOk === false || now - pulseRef > PULSE_STALE_MS) return 'delayed'
  if (f.channels.some((s) => s === 'refused')) return 'refused'
  return 'live'
}

/** The states that draw no line: live, or somewhere another line (the offline one) or nothing at all is right. */
const isQuiet = (state: LiveState): boolean => state === 'off' || state === 'offline' || state === 'live'

/** `since`: when the status last LEFT the quiet states — not when it last changed (see StatusStore.recompute). */
export function bannerFor(state: LiveState, since: number, now: number): LiveBanner {
  if (isQuiet(state)) return 'none'
  if (now - since < BANNER_GRACE_MS) return 'none'
  if (state === 'delayed') return 'delayed'
  if (state === 'refused') return 'refused'
  return 'paused'
}

const OFF: LiveStatus = { state: 'off', banner: 'none', updatedAt: null }

class StatusStore {
  private snapshot: LiveStatus = OFF
  private since = 0
  private lastCurrentAt: number | null = null
  private readonly listeners = new Set<() => void>()
  private timer: ReturnType<typeof setTimeout> | null = null
  private facts: (() => StatusFacts) | null = null

  get = (): LiveStatus => this.snapshot

  subscribe = (fn: () => void): (() => void) => {
    this.listeners.add(fn)
    return () => this.listeners.delete(fn)
  }

  /** Where the facts come from (the client). */
  attach(facts: (() => StatusFacts) | null): void {
    this.facts = facts
    this.recompute()
  }

  /** A catch-up just brought everything up to date. */
  markCurrent(at: number = Date.now()): void {
    this.lastCurrentAt = at
    this.recompute()
  }

  recompute(now: number = Date.now()): void {
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    const facts = this.facts?.()
    const state: LiveState = facts ? deriveState(facts, now) : 'off'
    const previous = this.snapshot.state
    if (state !== previous) {
      /*
       * The 3 s grace runs from the moment the status stopped being quiet, NOT from its last change. A reconnect
       * loop goes down → connecting → down every few seconds; restarting the grace at each step kept the line
       * away for as long as the loop lasted — on a network that refuses the socket at once, for ever.
       */
      if (isQuiet(previous) || isQuiet(state)) this.since = now
      if (previous === 'live') this.lastCurrentAt = now
    }
    if (state === 'live') this.lastCurrentAt = now
    const next: LiveStatus = { state, banner: bannerFor(state, this.since, now), updatedAt: state === 'live' ? null : this.lastCurrentAt }
    if (state === 'off') this.lastCurrentAt = facts?.started && facts.sessionLive ? this.lastCurrentAt : null

    // Look again when the grace runs out, or when the pulse would go stale.
    let wake = Infinity
    if (next.banner === 'none' && !isQuiet(state)) wake = this.since + BANNER_GRACE_MS
    if (facts && facts.socket === 'open' && (state === 'live' || state === 'refused')) wake = Math.min(wake, Math.max(facts.lastPulseAt ?? 0, facts.openedAt ?? 0) + PULSE_STALE_MS + 1)
    if (wake !== Infinity) this.timer = setTimeout(() => this.recompute(), Math.max(0, wake - now))

    const s = this.snapshot
    if (s.state === next.state && s.banner === next.banner && s.updatedAt === next.updatedAt) return
    this.snapshot = next
    this.listeners.forEach((fn) => fn())
  }

  /** For tests, and when live stops. */
  reset(): void {
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    this.facts = null
    this.lastCurrentAt = null
    this.since = 0
    const was = this.snapshot
    this.snapshot = OFF
    if (was !== OFF) this.listeners.forEach((fn) => fn())
  }
}

export const liveStatus = new StatusStore()

/** The phone's live status, for a screen or the banner. */
export const useLiveStatus = (): LiveStatus => useSyncExternalStore(liveStatus.subscribe, liveStatus.get, liveStatus.get)

export const getLiveStatus = (): LiveStatus => liveStatus.get()

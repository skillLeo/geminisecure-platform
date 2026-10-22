/**
 * The guard's duress alert — board guard-app-13, and the path the board cannot draw.
 *
 * TWO WAYS TO RAISE IT, ONE ALERT AT DISPATCH.
 *
 *   AUDIBLE   the guard is free to act: the Duress screen, a deliberate HOLD, a haptic, the board's
 *             "Alert sent" with the server's own timeline.
 *
 *   SILENT    the guard is being watched or coerced. It must not look like it fired. So it is raised
 *             by resting a thumb on the Home screen's shield for two seconds — and NOTHING happens
 *             that a person standing beside the guard could see or hear: no navigation, no sound, no
 *             vibration, no toast, no badge, no row in the sync queue, no "queued" count. Dispatch
 *             sees it at once. The only trace on the phone is one the guard was trained to read — the
 *             two faint rings around the shield are gone — and the full status is on the Duress
 *             screen whenever the guard judges it safe to open it.
 *
 * Both go through the shared engine (packages/core/safety): on disk first, sent directly with the
 * same key and body, under three seconds, waiting for no GPS beyond 400 ms and never for media.
 * No signal → it is held and sent the moment any signal returns; silently, if it was silent.
 *
 * WHAT IS NOT CLAIMED. The board draws "live location broadcasting every 5s" and a named coordinator.
 * The server keeps no guard position at all and names nobody, so the live screen says neither: it
 * says the position went WITH the alert (when there was one), and shows the timeline the server reports.
 */

import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { Platform } from 'react-native'
import * as Haptics from 'expo-haptics'
import * as Location from 'expo-location'
// From the lib and fidelity entries, not the package root: the silent path is unit-tested without the UI kit loaded.
import { now } from '@gs/core/fidelity'
import { getStorage, jsonStore, time12, time12s, useOfflineCache } from '@gs/core/lib'
import type { ApiError } from '@gs/core/api'
import { newStamp } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { SILENT_KIND } from '@gs/core/offline'
import { alertSteps, answerMoved, isDrill, keepOffering, raiseAlert, responseHeadline, type AlertAnswer, type AlertStep, type Headline } from '@gs/core/safety'
import { recordMoment } from '@/features/tracking/dutyTracker'
import { offer, outbox } from '@/offline/runtime'
import { store } from '@/store'

export const HOLD_MS = 1500
export const SILENT_HOLD_MS = 2000
const POLL_MS = 5000

export const CANCEL_REASONS = ['Pressed by accident', 'I was testing it', 'The situation is over'] as const

/** Why a held alert is held: `raiseAlert`'s own reasons. `refused` is not held at all — the server said no. */
export type HeldWhy = 'no_signal' | 'slow' | 'server' | 'refused'

export type ActiveDuress = {
  mode: 'silent' | 'audible'
  alert: AlertAnswer | null
  opId: string | null
  pressedAt: string
  withPosition: boolean
  /** Why it is held, when it is (B126). Optional: a record an older build saved has none, and reads as no signal. */
  why?: HeldWhy | null
  /** The server's own sentence when it refused the alert (B126). */
  message?: string | null
  /** The server's clock minus this phone's when the answer came in, in ms (B132). Unknown for an alert the queue delivered. */
  offsetMs?: number | null
}

/* ---- the one active alert, shared by Home's shield and the Duress screen ------------------------ */

const KEY = 'duress.active'
const VERSION = 'v1'
const box = () => jsonStore<ActiveDuress>(getStorage(), KEY, VERSION)
/*
 * READ FROM THE STORE EVERY TIME, NOT FROM A COPY IN MEMORY (B124). The copy outlived what it copied: sign-out
 * and a revoked session clear the store, and the copy went on "blocking" the next guard's duress on the same
 * phone until the app was restarted. The parsed value is kept only while the stored text is unchanged — which is
 * all useSyncExternalStore asks of a snapshot.
 */
let seenRaw: string | null | undefined
let seen: ActiveDuress | null = null
const listeners = new Set<() => void>()
const read = (): ActiveDuress | null => {
  const raw = getStorage().getString(`${VERSION}:${KEY}`)
  if (raw !== seenRaw) {
    seenRaw = raw
    seen = box().get()
  }
  return seen
}
function write(next: ActiveDuress | null): void {
  if (next) void box().setDurable(next)
  else box().remove()
  listeners.forEach((fn) => fn())
}
const subscribe = (fn: () => void) => {
  listeners.add(fn)
  return () => void listeners.delete(fn)
}
export const useActiveDuress = (): ActiveDuress | null => useSyncExternalStore(subscribe, read, read)

/* ---- what the record means --------------------------------------------------------------------- */

/** A minute without word from the server, and an open alert may have been closed long ago (B124). */
export const HEARD_WITHIN_MS = 60_000

/** When this phone last heard the server's word on the alert (phone clock). Memory only: after a restart, nothing yet. */
let heardAt = 0
const heard = (at: number) => {
  heardAt = Math.max(heardAt, at)
}

export const NOT_KEPT = 'This phone could not keep this alert to send it again, and never heard whether it got through. If you still need help, raise it again, or use your radio.'
export const NOT_KNOWN = 'This phone no longer knows whether this alert got through. If you still need help, raise it again, or use your radio.'
const REFUSED = 'The server did not accept this alert. Raise it again, or use your radio.'

const isOpen = (a: AlertAnswer | null | undefined): boolean => !!a && !['resolved', 'false_alarm'].includes(a.status)
const opOf = (id: string | null) => (id === null ? undefined : outbox.all().find((o) => o.id === id))

/**
 * WHY A HELD ALERT WILL NEVER BE SENT, or null while it still can be (B126). The server refused it (the direct send,
 * or the queue's copy later — the same key is answered the same way for ever); it was never kept on the phone to
 * send again; or the queue no longer holds it. Each is said as what it is, never as "no connection".
 *
 * Read only once the queue is loaded from disk — every caller is behind the session gate, or waits for it.
 */
export function failureOf(d: ActiveDuress | null): string | null {
  if (!d || d.alert) return null
  const op = opOf(d.opId)
  if (op?.state === 'refused') return op.error?.message || d.message || REFUSED
  if (op?.state === 'confirmed') return null // delivered: it becomes the alert on the next look
  if (d.why === 'refused') return d.message || REFUSED
  if (d.opId === null) return NOT_KEPT
  if (op === undefined) return NOT_KNOWN
  return null
}

/**
 * WHETHER THE ALERT IS LIVE: open at dispatch, or held on this phone and still to be sent. A closed alert, a refused
 * one and a lost one are not (B124) — and everything that reads the one alert asks THIS, never "is there a record":
 * the alertness checks, Home's rings, sign-out and a new press.
 */
export function isLive(d: ActiveDuress | null): boolean {
  if (!d) return false
  return d.alert ? isOpen(d.alert) : failureOf(d) === null
}

/**
 * Whether a new press repeats the alert already live, and so does nothing: only an alert the server has said is
 * open within the last minute, or one still held here waiting to be sent. An open alert not heard of for a minute
 * may have been closed long ago, and a duress swallowed because of a stale record is the one failure this cannot
 * have (B124). A second alert costs dispatch a glance; a lost one can cost the guard.
 */
function repeats(d: ActiveDuress | null): boolean {
  if (!d) return false
  if (d.alert) return isOpen(d.alert) && Date.now() - heardAt < HEARD_WITHIN_MS // phone-clock: how long since this phone heard, on its own clock
  return failureOf(d) === null
}

/** The server's clock minus the phone's, from an answer that has just arrived (B132). */
const offsetFrom = (serverIso: string, receivedAt: number): number | null => {
  const server = Date.parse(serverIso)
  return Number.isFinite(server) ? server - receivedAt : null
}

/** A held alert, said by WHY it is held (B126): "no connection" only when that is the reason. */
function heldLineFor(d: ActiveDuress, online: boolean): string {
  const why = d.why ?? 'no_signal'
  if (!online || why === 'no_signal') return 'This phone has no connection, so dispatch does not have this alert yet. It is held here and sent by itself the moment any signal returns. Use your radio if you can.'
  if (why === 'slow') return 'Not confirmed yet: dispatch may already have it. The server was slow to answer, and this phone keeps sending it until it does. Use your radio if you can.'
  return 'Not confirmed yet: the server did not answer properly. This phone keeps sending it until it does. Use your radio if you can.'
}

const lastKnownPosition = async (): Promise<{ latitude: number; longitude: number } | null> => {
  if (Platform.OS === 'web') return null
  const permission = await Location.getForegroundPermissionsAsync()
  if (!permission.granted) return null // never ASKED for here: a permission dialog is a delay, and a tell
  const fix = (await Location.getLastKnownPositionAsync({ maxAge: 120_000 })) ?? (await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }))
  return fix ? { latitude: fix.coords.latitude, longitude: fix.coords.longitude } : null
}

let raising = false

/** Raise it. Safe to call from anywhere; a second call while one is live (see `repeats`) or in flight does nothing. */
export async function raiseDuress(mode: 'silent' | 'audible'): Promise<void> {
  if (raising || repeats(read())) return
  raising = true
  const stamp = newStamp() // the moment of the press
  const pressedAt = now().toISOString()
  // Audible only: a vibration is exactly the kind of thing a coercer notices.
  if (mode === 'audible') void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => undefined)
  // On-duty sharing takes a fix for the moment too, on its own and never in the alert's way (nothing shows it).
  void recordMoment('duress')
  try {
    const state = store.getState()
    const outcome = await raiseAlert(
      {
        stamp,
        online: state.network.online,
        clock: () => Date.now(),
        position: lastKnownPosition,
        capture: (body, s) =>
          outbox.enqueue({
            endpoint: 'duress.store',
            body,
            stamp: s,
            label: 'Duress alert',
            // A silent alert leaves no row in the sync queue and no "queued" count for anyone to read.
            kind: mode === 'silent' ? SILENT_KIND : 'alert',
            owner: state.session.principal?.reference ?? 'unknown',
          }),
        send: async (body, s) => {
          const answer = await store.dispatch(guardApi.endpoints.duressStore.initiate({ ...body, __stamp: s }, { track: false }))
          return 'error' in answer && answer.error ? { error: answer.error as ApiError } : { data: answer.data as AlertAnswer }
        },
        settle: (opId, answer) => outbox.settle(opId, answer),
      },
      { mode },
    )
    const answeredAt = Date.now() // phone-clock: when the answer arrived, to measure the server's clock against (B132)
    if (outcome.state === 'sent') heard(answeredAt)
    write(
      outcome.state === 'sent'
        ? { mode, alert: outcome.alert, opId: outcome.opId, pressedAt, withPosition: outcome.withPosition, why: null, message: null, offsetMs: offsetFrom(outcome.alert.server_time, answeredAt) }
        : { mode, alert: null, opId: outcome.opId, pressedAt, withPosition: outcome.withPosition, why: outcome.why, message: outcome.message, offsetMs: null },
    )
    // Held on the phone: offered again every few seconds, from here rather than a screen — a silent alert has none open.
    // Not a refused one: the same key is answered the same way for ever (B126).
    const held = outcome.state === 'queued' && outcome.why !== 'refused' ? outcome.opId : null
    if (held) keepOffering(held, { state: (id) => outbox.all().find((o) => o.id === id)?.state, resend: () => offer(held) })
  } finally {
    raising = false
  }
}

/* ---- keeping the record true — from the root watcher, and from the Duress screen ------------------ */

/**
 * THE ALERT'S FATE, KEPT ON ITS RECORD (B124, B126). A held alert the queue has since delivered becomes the alert;
 * one the queue was refused for says so; a sent one takes every step the server records, closed included. This
 * ran only on the Duress screen, and a silent alert's guard never opens it — so dispatch closing one changed nothing
 * on the phone, and the stale "active" swallowed the next duress. `DuressWatcher` runs it from the root as well.
 */
export function useFollowDuress(active: ActiveDuress | null, off: boolean): void {
  useEffect(() => {
    if (off || !active || active.alert || !active.opId) return
    const held = active.opId
    // The runtime already offers it from launch (offerHeldAlerts, B128). One timer per alert, however often this runs.
    if (active.why !== 'refused') keepOffering(held, { state: (id) => outbox.all().find((o) => o.id === id)?.state, resend: () => offer(held) })
    const check = () => {
      const op = opOf(held)
      if (op?.state === 'confirmed' && op.result && typeof op.result === 'object') write({ ...active, alert: op.result as AlertAnswer, why: null, message: null })
      else if (op?.state === 'refused' && active.why !== 'refused') write({ ...active, why: 'refused', message: op.error?.message || REFUSED })
    }
    check()
    const timer = setInterval(check, 2000)
    return () => clearInterval(timer)
  }, [active, off])

  const alertId = active?.alert?.id
  const open = isOpen(active?.alert)
  const live = guardApi.useDuressShowQuery({ alert: alertId ?? 0 }, { skip: off || alertId === undefined || !open, pollingInterval: open ? POLL_MS : 0 })
  useEffect(() => {
    if (live.fulfilledTimeStamp) heard(live.fulfilledTimeStamp)
    const a = active?.alert
    const latest = live.data as AlertAnswer | undefined
    // Only THIS alert's answer: while a new id is fetched the hook still hands back the last alert's.
    if (!active || !a || !latest || String(latest.id) !== String(a.id)) return
    // Every step the server records (dispatched, en route, on scene, closed), not only the status: `answerMoved`.
    if (answerMoved(a, latest)) write({ ...active, alert: { ...a, ...latest } })
  }, [live.data, live.fulfilledTimeStamp, active])
}

/* ---- the Duress screen ---------------------------------------------------------------------------- */

export type DuressModel = {
  phase: 'arm' | 'sending' | 'active' | 'queued' | 'failed' | 'closed'
  /** Why a held alert is held, in words that say so — "no connection" only when that is the reason (B126). */
  heldLine: string
  /** Why an alert will never be sent — the server's own sentence where it gave one. Phase `failed` (B126). */
  failure: string | null
  chip: string
  sentLine: string
  /** Where the response stands, in the server's own push words — "Help is on the way" only once an officer was SENT. */
  headline: Headline | null
  /** A DRILL raised to test the fan-out. Never a handset's own press — but if the server ever says so, it is said first. */
  drill: boolean
  steps: AlertStep[]
  withPosition: boolean
  canCancel: boolean
  cancelSecondsLeft: number
  cancelBusy: boolean
  cancelError: string | null
  closedAs: 'resolved' | 'false_alarm' | null
  online: boolean
  fire: () => void
  cancel: (reason: string) => void
  finish: () => void
}

export function useDuress(fixtureActive: boolean, onDone: () => void): DuressModel {
  const active = useActiveDuress()
  const online = store.getState().network.online
  const shift = useOfflineCache<{ shift: { post: { name: string } } | null }>('shift.current', undefined)
  const postName = shift?.shift?.post.name ?? ''
  const [sending, setSending] = useState(false)
  const [cancelBusy, setCancelBusy] = useState(false)
  const [cancelError, setCancelError] = useState<string | null>(null)
  const firing = useRef(false)

  const fire = useCallback(async () => {
    // Whether this repeats a live alert is `raiseDuress`'s to decide; the screen offers the hold only when none is.
    if (fixtureActive || firing.current) return
    firing.current = true
    setSending(true)
    try {
      await raiseDuress('audible')
    } finally {
      setSending(false)
      firing.current = false
    }
  }, [fixtureActive])

  // Held → delivered or refused, and every step the server records: the same follower the root watcher runs.
  useFollowDuress(active, fixtureActive)

  const open = isOpen(active?.alert)
  const [tick, setTick] = useState(0)
  useEffect(() => {
    if (!open) return
    const timer = setInterval(() => setTick((n) => n + 1), 500)
    return () => clearInterval(timer)
  }, [open])
  const cancelSecondsLeft = useMemo(() => {
    const a = active?.alert
    if (!a || a.acknowledged_at || a.status !== 'open') return 0
    /*
     * ON THE SERVER'S CLOCK (B132). `cancellable_until` is the server's time, and a phone fifteen seconds fast never
     * showed the cancel at all, while one a minute slow offered it for a minute of 409s. The phone's clock is moved
     * by the offset measured when the answer arrived; with none measured (the queue delivered it), the phone's own.
     */
    const serverNow = Date.now() + (active?.offsetMs ?? 0) // phone-clock: moved onto the server's clock by the offset measured on arrival
    return Math.max(0, Math.ceil((Date.parse(a.cancellable_until) - serverNow) / 1000))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, tick])

  const [cancelAlert] = guardApi.useDuressCancelMutation()
  const cancel = useCallback(
    async (reason: string) => {
      const a = active?.alert
      if (!a || !active || cancelBusy || reason.trim().length === 0) return
      setCancelBusy(true)
      setCancelError(null)
      try {
        const answer = await cancelAlert({ alert: a.id, reason: reason.trim().slice(0, 160) })
        if ('error' in answer && answer.error) {
          const e = answer.error as ApiError
          setCancelError(e.status === 'network' || e.status === 'timeout' ? 'No connection: the alert stays active. Radio or call dispatch to stand it down.' : e.message)
        } else if (answer.data) {
          heard(Date.now()) // phone-clock: when this phone last heard the server on this alert
          write({ ...active, alert: { ...a, ...(answer.data as AlertAnswer) } })
        }
      } finally {
        setCancelBusy(false)
      }
    },
    [active, cancelBusy, cancelAlert],
  )

  const a = active?.alert ?? null
  const closedAs = a?.status === 'resolved' ? 'resolved' : a?.status === 'false_alarm' ? 'false_alarm' : null
  const secondsToAck = a?.acknowledged_at ? Math.max(0, Math.round((new Date(a.acknowledged_at).getTime() - new Date(a.server_time).getTime()) / 1000)) : null
  /*
   * The shared six steps (alertProgress.ts), in the guard's words: the alert went to DISPATCH, the officer
   * sent is the board's "response unit", and only dispatch closes it. A cancelled duress keeps the shared
   * step's own words ("You cancelled this alert"): dispatch did not close it.
   */
  const steps = a
    ? alertSteps(a, time12s(a.server_time), secondsToAck, false, (iso) => time12s(iso)).map((s) =>
        s.key === 'sent'
          ? { ...s, title: 'Alert sent to dispatch' }
          : s.key === 'dispatched'
            ? { ...s, title: 'Response unit dispatched' }
            : s.key === 'resolved' && a.status !== 'false_alarm' && a.cancelled_at === null
              ? { ...s, title: 'Closed by dispatch', detail: s.done || s.state === 'not_recorded' ? s.detail : 'Only dispatch can close this alert' }
              : s,
      )
    : []

  const failure = failureOf(active)

  return {
    phase: sending ? 'sending' : !active ? 'arm' : closedAs ? 'closed' : a ? 'active' : failure ? 'failed' : 'queued',
    heldLine: active && !a ? heldLineFor(active, online) : '',
    failure,
    chip: `${active?.mode === 'silent' ? 'SILENT' : 'AUDIBLE'} · GENERAL DURESS`,
    sentLine: active ? `${postName ? `${postName} · ` : ''}${a ? 'Sent' : 'Pressed'} ${time12(active.pressedAt)}` : '',
    headline: a ? responseHeadline(a) : null,
    drill: isDrill(a?.is_drill),
    steps,
    withPosition: active?.withPosition ?? false,
    canCancel: cancelSecondsLeft > 0,
    cancelSecondsLeft,
    cancelBusy,
    cancelError,
    closedAs,
    online,
    fire: () => void fire(),
    cancel: (reason) => void cancel(reason),
    finish: () => {
      write(null)
      onDone()
    },
  }
}

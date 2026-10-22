/**
 * "SHARE LIVE AUDIO" — WHAT THIS PHONE HEARS, TO THE CONTROL ROOM, WHILE A PANIC IS OPEN (board resident-app-06; M50
 * §4.2 #1). The decision and the state; the recording itself is LiveAudioHost's, mounted once for the whole app.
 *
 * ONE RECORDER FOR THE APP, NOT FOR A SCREEN. The resident may leave the panic screen, lock the phone or put the app
 * in the background, and the audio goes on — Android keeps expo-audio's microphone foreground service (with its
 * notification) running, iOS its `audio` background mode — until the switch is turned off or the alert closes.
 *
 * A FEW SECONDS AT A TIME. The host records PIECE_MS, stops, hands the piece to the queue (`duress.audio.store` with
 * its `seq`) and starts the next at once: the control room hears it a few seconds late, never all at the end, and with
 * no signal the pieces wait on the phone and go in order once there is one. The count is kept per alert ON THE PHONE,
 * so a restart never sends a new piece under an old number (the server keeps the first of each number).
 *
 * ON AS THE BOARD DRAWS IT — WHEN IT TRULY CAN BE. With the microphone already allowed, it starts with the alert.
 * Without, it is off and says so: the panic screen never pops a permission prompt by itself (a prompt in the middle
 * of a panic is a delay, as with location). The switch asks. Turned off, it stays off for that alert.
 */

import { useSyncExternalStore } from 'react'
import { getStorage, jsonStore } from '@gs/core/lib'

export const PIECE_MS = 5000

/** Which alert the audio is for: the queue's act for it, which names it held or sent; and its id once it has one. */
export type AudioTarget = { alertOp: string; alertId: number | null }

/**
 * off          not recording
 * starting     asked to record; the phone has not started yet
 * on           recording now
 * denied       the microphone is not allowed for this app
 * unavailable  this build cannot record (the web, the design build)
 * failed       the phone could not record (another app holds the microphone, say)
 */
export type LiveAudioState = 'off' | 'starting' | 'on' | 'denied' | 'unavailable' | 'failed'

let wanted: AudioTarget | null = null
let state: LiveAudioState = 'off'
const listeners = new Set<() => void>()
const tell = (): void => listeners.forEach((fn) => fn())
const watch = (fn: () => void): (() => void) => {
  listeners.add(fn)
  return () => void listeners.delete(fn)
}

/** What the host should be recording for — null to stop. Never throws, never waits. */
export function wantLiveAudio(target: AudioTarget | null): void {
  const same = wanted === target || (!!wanted && !!target && wanted.alertOp === target.alertOp && wanted.alertId === target.alertId)
  if (same) return
  wanted = target
  if (!target && (state === 'starting' || state === 'on')) state = 'off'
  else if (target && state !== 'on') state = 'starting'
  tell()
}

export const wantedAudio = (): AudioTarget | null => wanted

/** The host's own report of what is true. */
export function setLiveAudioState(next: LiveAudioState): void {
  if (state === next) return
  state = next
  tell()
}

export const liveAudioState = (): LiveAudioState => state
export const useLiveAudioState = (): LiveAudioState => useSyncExternalStore(watch, liveAudioState, liveAudioState)
export const useWantedAudio = (): AudioTarget | null => useSyncExternalStore(watch, wantedAudio, wantedAudio)

/* ---- kept on the phone, per alert: the next piece's number, and whether the resident switched it off ---- */

type Kept = Record<string, { next: number; off: boolean }>
const box = () => jsonStore<Kept>(getStorage(), 'panic.audio', 'v1')
const read = (): Kept => {
  try {
    return box().get() ?? {}
  } catch {
    return {}
  }
}
const write = (kept: Kept): void => {
  try {
    // Only the last few alerts: a phone does not need the count of an alert from last year.
    const keys = Object.keys(kept).slice(-10)
    box().set(Object.fromEntries(keys.map((key) => [key, kept[key]!])))
  } catch {
    /* an unwritable store costs only a repeated number, which the server answers with the first piece */
  }
}

/** The next piece's number for this alert, counted on — 0 for the first. */
export function takeSeq(alertOp: string): number {
  const kept = read()
  const entry = kept[alertOp] ?? { next: 0, off: false }
  kept[alertOp] = { ...entry, next: entry.next + 1 }
  write(kept)
  return entry.next
}

/** The resident turned it off (or on) for this alert: it is not started again by itself. */
export function markSwitchedOff(alertOp: string, off: boolean): void {
  const kept = read()
  kept[alertOp] = { next: kept[alertOp]?.next ?? 0, off }
  write(kept)
}

export const switchedOff = (alertOp: string): boolean => read()[alertOp]?.off === true

/** The words under nothing — said in the screen's own sheet when the switch cannot be on. */
export const MICROPHONE_OFF = {
  title: 'The microphone is off for GeminiSecure',
  line: 'To share live audio with the control room, allow the microphone for GeminiSecure in your phone\'s Settings.',
}
export const CANNOT_RECORD = {
  title: 'Live audio could not start',
  line: 'Another app may be using the microphone. Your alert is not affected: try again, or call 119.',
}

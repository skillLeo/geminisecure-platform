/**
 * The Call-style alerts screen's state: what the phone says it can do, read again every time the guard comes back
 * from the phone's Settings, and the one real proof there is — a test ring sent by the server as a push.
 *
 * Everything the screen draws comes from readiness.ts, so the words are tested there. Everything it touches is
 * handed in with a default, so a test can drive it without a phone.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { AppState } from 'react-native'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { flags } from '@gs/core/lib'
import { nudgePushRegistration } from '@gs/core/notify'
import { store } from '@/store'
import { GuardRing, availability as availabilityOf, type Availability, type GuardRingNative, type NativeReadiness, type SettingName } from './native'
import { NOTES, makerAdvice, rowsOf, serverReport, summaryOf, type ReadinessRow, type Summary } from './readiness'

export type TestAnswer = { ok: true; ringState: string | null } | { ok: false; message: string }

export const TEST_SENT = 'Test ring sent. It should ring within a few seconds, over the lock screen when the phone is locked, and stop by itself after 15 seconds. If it did not ring, a panic would not ring this phone either: check the rows above.'
export const TEST_FAILED = 'The test ring could not be sent. Check your signal and try again.'
export const NO_SETTINGS_SCREEN = 'This phone would not open that settings screen. Open Settings → Apps → GeminiSecure Guard and look for it there.'

const STATE_WORDS: Record<string, string> = { ready: 'ready to ring like a call', partial: 'partly ready', not_ready: 'not ready' }

export function testSentence(answer: TestAnswer): string {
  if (!answer.ok) return answer.message
  const state = answer.ringState ? STATE_WORDS[answer.ringState] : null
  return state ? `${TEST_SENT} The office's console has this phone as ${state}.` : TEST_SENT
}

/** The server's test ring, to THIS sign-in's phone only (`ring.test`). Never throws. */
export async function sendTestRing(): Promise<TestAnswer> {
  try {
    const answer = await store.dispatch(guardApi.endpoints.ringTest.initiate(undefined, { track: false }))
    if ('error' in answer && answer.error) return { ok: false, message: isApiError(answer.error) ? answer.error.message : TEST_FAILED }
    const data = 'data' in answer ? answer.data : undefined
    return { ok: true, ringState: data?.ring_state ?? null }
  } catch {
    return { ok: false, message: TEST_FAILED }
  }
}

export type CallStyle = {
  availability: Availability
  native: NativeReadiness | null
  rows: ReadinessRow[]
  summary: Summary | null
  /** Instead of rows, on a phone with no call-style ring (Expo Go, iPhone, an older build). */
  note: string | null
  /** The manufacturer's own battery manager, which no app can read. */
  advice: string | null
  /** A sentence after a settings screen would not open, or after a test ring. */
  message: string | null
  testing: boolean
  open: (which: SettingName) => Promise<void>
  sendTest: () => Promise<void>
  refresh: () => Promise<void>
}

export type CallStyleDeps = { module?: GuardRingNative | null; availability?: Availability; askForDnd?: boolean; sendTest?: () => Promise<TestAnswer> }

export function useCallStyle(deps: CallStyleDeps = {}): CallStyle {
  const module = deps.module === undefined ? GuardRing : deps.module
  const where = deps.availability ?? availabilityOf(module)
  const askForDnd = deps.askForDnd ?? flags.askForDndAccess
  const send = deps.sendTest ?? sendTestRing
  const [native, setNative] = useState<NativeReadiness | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [testing, setTesting] = useState(false)
  const last = useRef<string | null>(null)

  const refresh = useCallback(async () => {
    if (where !== 'android' || !module) return
    try {
      const read = await module.readiness()
      setNative(read)
      // A guard who changed a setting must not stay what they were on the console: register again when it changed.
      const report = JSON.stringify(serverReport(read) ?? null)
      if (last.current !== null && last.current !== report) nudgePushRegistration()
      last.current = report
    } catch {
      setNative(null)
    }
  }, [module, where])

  useEffect(() => { void refresh() }, [refresh])

  // Every fix is in the phone's own Settings, which means leaving the app: read again on the way back.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => { if (state === 'active') void refresh() })
    return () => sub.remove()
  }, [refresh])

  const open = useCallback(async (which: SettingName) => {
    if (!module) return
    setMessage(null)
    const opened = await module.openSetting(which).catch(() => false)
    if (!opened) setMessage(NO_SETTINGS_SCREEN)
    if (which === 'alarm_volume') setTimeout(() => void refresh(), 1_500)
  }, [module, refresh])

  const sendTest = useCallback(async () => {
    if (testing) return
    setTesting(true)
    setMessage(null)
    try {
      setMessage(testSentence(await send()))
    } finally {
      setTesting(false)
    }
  }, [send, testing])

  return {
    availability: where,
    native,
    rows: native ? rowsOf(native, { askForDnd }) : [],
    summary: native ? summaryOf(native) : null,
    note: where === 'android' ? null : NOTES[where],
    advice: native ? makerAdvice(native.maker) : null,
    message,
    testing,
    open,
    sendTest,
    refresh,
  }
}

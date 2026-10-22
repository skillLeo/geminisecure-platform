/**
 * SPEAK INSTEAD OF TYPING — ON THE PHONE, WITH NO CONNECTION (M12 §2.9).
 *
 * One implementation for every microphone in both apps: the resident's maintenance request and security
 * report, and the guard's incident report. Each screen passes its own sentences (`DictationWords`).
 *
 *   TAP, SPEAK, SEE IT. The microphone toggles listening. What the phone is hearing shows as it is heard
 *            (`hearing`, the recogniser's interim words); once it settles, those words are appended to the
 *            text box, where they are edited like anything typed. Words still being heard when listening stops
 *            are kept, never dropped.
 *   ON THE PHONE ONLY. Recognition is always `requiresOnDeviceRecognition`: what a resident says about their
 *            home, or a guard about an incident, is never sent to a speech service — and so it works in a
 *            basement with no signal. A phone that cannot recognise speech on the device says so, and the
 *            microphone is off; nothing falls back to a network service.
 *   THE ONE-TIME DOWNLOAD. Android keeps its offline English model as a separate download. Where it is missing
 *            the screen offers it (`model.download`), says what happened in the phone's own terms (downloaded,
 *            waiting for Wi-Fi, follow the prompt), and never pretends it is ready.
 *   HONEST WHERE IT CANNOT. Expo Go, the web, or a build without the native module: `available: false` with
 *            the screen's reason. Nothing here is ever a reason a report cannot be sent.
 *
 * The `require` sits in a try/catch so Metro treats the module as optional and a build without it bundles.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Platform } from 'react-native'
import { isExpoGo } from '../lib/env'

/** The sentences a screen says in its own words ("your report", "the details"). */
export type DictationWords = {
  /** No speech on this build or this phone: type, or use the keyboard's microphone. */
  unavailable: string
  /** Listening stopped by itself. */
  stopped: string
  /** Listening could not start. */
  couldNotStart: string
  /** The microphone is refused for this app. */
  micOff: string
}

export type Dictation = {
  available: boolean
  /** Why the microphone is off — shown on the screen, and read out as the control's hint. */
  reason: string | null
  listening: boolean
  /** What is being heard right now, before the phone settles on it; '' when nothing is. */
  hearing: string
  /** Listening stopped, was refused, or needs the model: said plainly, and the text is typed instead. */
  trouble: string | null
  /** What the phone said about the model download when it is not a fault (downloaded, waiting for Wi-Fi, a prompt). */
  note: string | null
  /** The phone's offline speech model, when it is missing (Android): offered, never assumed. */
  model: { needed: boolean; downloading: boolean; download: () => void } | null
  toggle: () => void
}

/** The fixture harness's stand-in: drawn as the board draws it, doing nothing. */
export const STILL_DICTATION: Dictation = { available: true, reason: null, listening: false, hearing: '', trouble: null, note: null, model: null, toggle: () => undefined }

export const NO_ON_DEVICE =
  "This phone can't recognise speech on the phone itself, and nothing you say is sent away to be recognised, so speaking is off. Type instead, or use the microphone on your keyboard."
export const MODEL_NEEDED =
  'To speak with no connection, this phone needs its English speech model: a one-time download from Google. Download it, then tap the microphone again. Or type instead.'
export const MODEL_READY = 'The English speech model is on this phone now. Tap the microphone to speak: it works with no connection.'
export const MODEL_SCHEDULED = 'The phone will download its English speech model when it can (it may wait for Wi-Fi). Until then, type, or use the microphone on your keyboard.'
export const MODEL_DIALOG = "Follow the phone's prompt to download its English speech model, then tap the microphone again."
export const MODEL_FAILED = 'The speech model could not be downloaded just now. It needs a connection once: try again when you are connected, or type instead.'

type Subscription = { remove: () => void }
type ResultEvent = { isFinal?: boolean; results?: { transcript?: string }[] }
type ErrorEvent = { error?: string; message?: string }
type ModelStatus = 'download_success' | 'opened_dialog' | 'download_scheduled'

/** The part of expo-speech-recognition's module this file uses. */
export type SpeechModule = {
  isRecognitionAvailable?: () => boolean
  supportsOnDeviceRecognition?: () => boolean
  requestMicrophonePermissionsAsync?: () => Promise<{ granted: boolean }>
  requestPermissionsAsync: () => Promise<{ granted: boolean }>
  getSupportedLocales?: (options: Record<string, never>) => Promise<{ locales: string[]; installedLocales: string[] }>
  androidTriggerOfflineModelDownload?: (options: { locale: string }) => Promise<{ status: ModelStatus; message?: string }>
  start: (options: { lang: string; interimResults: boolean; continuous: boolean; requiresOnDeviceRecognition: boolean; addsPunctuation: boolean }) => void
  stop: () => void
  addListener(event: 'result', listener: (event: ResultEvent) => void): Subscription
  addListener(event: 'error', listener: (event: ErrorEvent) => void): Subscription
  addListener(event: 'end', listener: () => void): Subscription
}

/** en-US first: the on-device model phones actually ship. An installed en-GB or en-JM is used where en-US is not. */
export const PREFERRED_LOCALE = 'en-US'

/**
 * Which English the phone can recognise offline: the preferred one if it is installed, else any installed English,
 * else null. `undefined` when the phone cannot say (Android 12 and below answers with nothing at all) — then the
 * recogniser is simply tried, on the device, and refuses in its own way if it cannot.
 */
export function offlineLocale(answer: { locales: string[]; installedLocales: string[] } | null): string | null | undefined {
  if (answer === null || (answer.locales.length === 0 && answer.installedLocales.length === 0)) return undefined
  const installed = answer.installedLocales.map((l) => l.replace('_', '-'))
  if (installed.some((l) => l.toLowerCase() === PREFERRED_LOCALE.toLowerCase())) return PREFERRED_LOCALE
  return installed.find((l) => l.toLowerCase().startsWith('en')) ?? null
}

/** What a recogniser's error means to the person holding the phone; null for silence, which is not a fault. */
export function troubleOf(error: string | undefined, words: DictationWords): string | null {
  switch (error) {
    case 'no-speech':
    case 'speech-timeout':
    case 'aborted':
    case 'nomatch':
      return null
    case 'language-not-supported':
      return MODEL_NEEDED
    case 'not-allowed':
      return words.micOff
    case 'service-not-allowed':
      return NO_ON_DEVICE
    default:
      return words.stopped
  }
}

/** What the phone said about the model download, in a sentence — a note, or (when it failed) trouble. */
export function modelSentence(status: ModelStatus | null): { note: string | null; trouble: string | null } {
  if (status === 'download_success') return { note: MODEL_READY, trouble: null }
  if (status === 'download_scheduled') return { note: MODEL_SCHEDULED, trouble: null }
  if (status === 'opened_dialog') return { note: MODEL_DIALOG, trouble: null }
  return { note: null, trouble: MODEL_FAILED }
}

let loaded: SpeechModule | null | undefined

/** For the tests: the module this file would use. */
export function setSpeechModuleForTests(module: SpeechModule | null | undefined): void {
  loaded = module
}

function speechModule(): SpeechModule | null {
  if (loaded !== undefined) return loaded
  loaded = null
  if (isExpoGo || Platform.OS === 'web') return loaded
  try {
    const mod = require('expo-speech-recognition') as { ExpoSpeechRecognitionModule?: SpeechModule } | undefined
    const speech = mod?.ExpoSpeechRecognitionModule
    if (speech && typeof speech.start === 'function' && speech.isRecognitionAvailable?.() !== false) loaded = speech
  } catch {
    loaded = null
  }
  return loaded
}

const joined = (words: string): string => words.replace(/\s+/g, ' ').trim()

export function useDictation(onHeard: (words: string) => void, words: DictationWords): Dictation {
  const speech = speechModule()
  const onDevice = !!speech && speech.supportsOnDeviceRecognition?.() !== false
  const [listening, setListening] = useState(false)
  const [hearing, setHearing] = useState('')
  const [trouble, setTrouble] = useState<string | null>(null)
  const [note, setNote] = useState<string | null>(null)
  const [modelNeeded, setModelNeeded] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const heard = useRef(onHeard)
  heard.current = onHeard
  // The interim words not yet settled: committed when listening ends, so nothing heard is lost.
  const pending = useRef('')
  const say = useRef(words)
  say.current = words

  const settle = useCallback(() => {
    const words = joined(pending.current)
    pending.current = ''
    setHearing('')
    if (words) heard.current(words)
  }, [])

  useEffect(() => {
    if (!speech || !onDevice) return
    const subscriptions = [
      speech.addListener('result', (event) => {
        const words = event.results?.[0]?.transcript ?? ''
        if (event.isFinal === false) {
          pending.current = words
          setHearing(joined(words))
          return
        }
        pending.current = words
        settle()
      }),
      speech.addListener('error', (event) => {
        setListening(false)
        settle()
        if (event.error === 'language-not-supported') setModelNeeded(true)
        setTrouble(troubleOf(event.error, say.current))
      }),
      speech.addListener('end', () => {
        setListening(false)
        settle()
      }),
    ]
    return () => {
      for (const subscription of subscriptions) subscription.remove()
      try {
        speech.stop()
      } catch {
        // already stopped
      }
    }
  }, [speech, onDevice, settle])

  const toggle = useCallback(async () => {
    if (!speech || !onDevice) return
    try {
      if (listening) return speech.stop()
      setTrouble(null)
      setNote(null)
      // Recognised on the device, so only the microphone is asked for (never iOS's speech-service permission).
      // Written as two plain calls so gate:store sees each request site and holds it to its iOS purpose string.
      const permission = speech.requestMicrophonePermissionsAsync ? await speech.requestMicrophonePermissionsAsync() : await speech.requestPermissionsAsync()
      if (!permission.granted) return setTrouble(say.current.micOff)
      let lang: string = PREFERRED_LOCALE
      if (Platform.OS === 'android' && speech.getSupportedLocales) {
        const answer = await speech.getSupportedLocales({}).catch(() => null)
        const locale = offlineLocale(answer)
        if (locale === null) {
          setModelNeeded(true)
          return setTrouble(MODEL_NEEDED)
        }
        if (locale) lang = locale
      }
      setModelNeeded(false)
      speech.start({ lang, interimResults: true, continuous: true, requiresOnDeviceRecognition: true, addsPunctuation: true })
      setListening(true)
    } catch {
      setListening(false)
      setTrouble(say.current.couldNotStart)
    }
  }, [speech, onDevice, listening])

  const download = useCallback(async () => {
    if (!speech?.androidTriggerOfflineModelDownload || downloading) return
    setDownloading(true)
    try {
      const answer = await speech.androidTriggerOfflineModelDownload({ locale: PREFERRED_LOCALE })
      const said = modelSentence(answer.status)
      setTrouble(said.trouble)
      setNote(said.note)
      if (answer.status === 'download_success') setModelNeeded(false)
    } catch {
      setNote(null)
      setTrouble(MODEL_FAILED)
    } finally {
      setDownloading(false)
    }
  }, [speech, downloading])

  const available = !!speech && onDevice
  return {
    available,
    reason: !speech ? words.unavailable : !onDevice ? NO_ON_DEVICE : null,
    listening,
    hearing,
    trouble,
    note,
    model: available && modelNeeded && Platform.OS === 'android' && speech?.androidTriggerOfflineModelDownload ? { needed: true, downloading, download: () => void download() } : null,
    toggle: () => void toggle(),
  }
}

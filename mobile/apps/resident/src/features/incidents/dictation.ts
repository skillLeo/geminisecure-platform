/**
 * Speech-to-text for a security report — board resident-app-14's microphone, its "Listening…" and its waveform.
 *
 * A SCREEN-LOCAL COPY OF THE ONE RECOGNISER (@gs/core/speech, M12 §2.9), AND WHY. The design adds a live
 * waveform (M42 §5 #15), and it must not be fake (the user, 7 October): it is drawn from the microphone level the
 * recogniser reports — expo-speech-recognition's `volumechange` — and from nothing else, never a loop. The
 * recogniser sends that level only when `start()` asks for it, and @gs/core/speech (shared with the Guard App) does
 * not ask. So this file is that hook again with one addition: `start()` asks for the level, and the level is kept in
 * a small store of its own (`levels`) that only the waveform reads — ten readings a second must not redraw the whole
 * form. Everything else is the core's: its rules (`offlineLocale`, `troubleOf`, `modelSentence`) and sentences are
 * imported, not copied. New maintenance request listens through this file too, in its own words (M42 §5 #10).
 *
 *   TAP, SPEAK, SEE IT. The microphone toggles listening. What the phone is hearing shows as it is heard
 *            (`hearing`); once it settles, it is appended to the transcript, where it is edited like typing.
 *            Words still being heard when listening stops are kept, never dropped.
 *   ON THE PHONE ONLY. `requiresOnDeviceRecognition` always: what a resident says about what they saw is never
 *            sent to a speech service, and so it works with no signal. A phone that cannot recognise on the device
 *            says so, and the microphone is off.
 *   THE LEVEL. -2 to 10 from the recogniser ("below 0 is inaudible"), kept as the last WAVE_BARS readings, each
 *            0 to 1, newest on the right. Before the first reading, and whenever listening stops, every bar is at
 *            rest (0). A phone whose recogniser sends no level leaves the bars at rest while it listens.
 *   HONEST WHERE IT CANNOT. Expo Go, the web, or a build without the native module: `available: false` with the
 *            reason. Nothing here is ever a reason a report cannot be sent.
 *   THE PLAIN WAY, WHEN THE CONTINUOUS ONE FAILS (M47 §13.4). On Android 13 and later a continuous session has the app
 *            record the microphone itself and stream it to the recogniser (expo-speech-recognition: EXTRA_AUDIO_SOURCE
 *            in a segmented session) — a path Google's own on-device recogniser takes and not every maker's does. On a
 *            real Samsung, listening ended at once with an error that is none of the named ones, so every resident there
 *            saw "Listening stopped". When a continuous session fails like that before a word is heard, the recogniser
 *            is started once more the plain way — it opens the microphone itself, and stops at a pause — and this phone
 *            listens that way for as long as the app runs. The recogniser's own reason is in the phone's log
 *            (`adb logcat -s ExpoSpeechService`: "onError() - client: … - code: 5").
 *
 * The `require` sits in a try/catch so Metro treats the module as optional and a build without it bundles.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Platform } from 'react-native'
// From the lib entry, not the package root: like the core's own recogniser, this file loads without the UI kit.
import { isExpoGo } from '@gs/core/lib'
import { MODEL_FAILED, MODEL_NEEDED, NO_ON_DEVICE, PREFERRED_LOCALE, modelSentence, offlineLocale, troubleOf, type Dictation, type DictationWords, type SpeechModule } from '@gs/core/speech'

export const DICTATION_UNAVAILABLE = "Speaking your report isn't available in this version of the app: type it instead, or use the microphone on your keyboard."

const WORDS: DictationWords = {
  unavailable: DICTATION_UNAVAILABLE,
  stopped: 'Listening stopped on this phone. Type the details instead: nothing you wrote is lost.',
  couldNotStart: 'Listening could not start on this phone. Type the details instead.',
  micOff: 'The microphone is switched off for this app. Type the details, or allow the microphone in your phone settings to speak them.',
}

/** The board's waveform: seven bars. */
export const WAVE_BARS = 7

/** How often the recogniser is asked for the level, in milliseconds. */
export const LEVEL_EVERY_MS = 100

/** The microphone level, as the waveform reads it: the last WAVE_BARS readings, 0 to 1, newest last. */
export type Levels = { get: () => readonly number[]; subscribe: (listener: () => void) => () => void }

export type ReportDictation = Dictation & { levels: Levels }

const AT_REST: readonly number[] = Object.freeze(Array.from({ length: WAVE_BARS }, () => 0))

/** One reading of the recogniser's scale (-2…10, below 0 inaudible) as a bar's share of its height. */
export const levelOf = (value: number | undefined): number => (typeof value === 'number' && Number.isFinite(value) ? Math.min(10, Math.max(0, value)) / 10 : 0)

/** A level store that never changes — the fixture's, and a phone with no recogniser's. */
export function stillLevels(levels: readonly number[] = AT_REST): Levels {
  return { get: () => levels, subscribe: () => () => undefined }
}

type Subscription = { remove: () => void }
type StartOptions = Parameters<SpeechModule['start']>[0] & { volumeChangeEventOptions: { enabled: boolean; intervalMillis: number } }

/** The recogniser as this screen uses it: the core's shape, plus the level. */
type ReportSpeech = Omit<SpeechModule, 'start' | 'addListener'> & {
  start: (options: StartOptions) => void
  addListener(event: 'result', listener: (event: { isFinal?: boolean; results?: { transcript?: string }[] }) => void): Subscription
  addListener(event: 'error', listener: (event: { error?: string; message?: string }) => void): Subscription
  addListener(event: 'end', listener: () => void): Subscription
  addListener(event: 'volumechange', listener: (event: { value?: number }) => void): Subscription
}

let loaded: ReportSpeech | null | undefined

/** This phone's recogniser failed a continuous session before a word: it listens the plain way from now on. */
let plain = false

/** For the tests: the module this file would use — and a phone that has not failed a continuous session yet. */
export function setReportSpeechForTests(module: ReportSpeech | null | undefined): void {
  loaded = module
  plain = false
}

function speechModule(): ReportSpeech | null {
  if (loaded !== undefined) return loaded
  loaded = null
  if (isExpoGo || Platform.OS === 'web') return loaded
  try {
    const mod = require('expo-speech-recognition') as { ExpoSpeechRecognitionModule?: ReportSpeech } | undefined
    const speech = mod?.ExpoSpeechRecognitionModule
    if (speech && typeof speech.start === 'function' && speech.isRecognitionAvailable?.() !== false) loaded = speech
  } catch {
    loaded = null
  }
  return loaded
}

const joined = (words: string): string => words.replace(/\s+/g, ' ').trim()

/** The level store a listening session writes and the waveform reads. */
function useLevelStore(): Levels & { push: (value: number | undefined) => void; rest: () => void } {
  const current = useRef<readonly number[]>(AT_REST)
  const listeners = useRef(new Set<() => void>())
  return useMemo(() => {
    const tell = () => {
      for (const listener of listeners.current) listener()
    }
    return {
      get: () => current.current,
      subscribe: (listener: () => void) => {
        listeners.current.add(listener)
        return () => void listeners.current.delete(listener)
      },
      push: (value: number | undefined) => {
        current.current = [...current.current.slice(1), levelOf(value)]
        tell()
      },
      rest: () => {
        if (current.current === AT_REST) return
        current.current = AT_REST
        tell()
      },
    }
  }, [])
}

/**
 * `phrasing`: what the microphone's refusals say on the screen using it — a report's by default; New maintenance request
 * passes its own ("Speaking your request…"), so both screens get the live level without a second copy of this file.
 */
export function useReportDictation(onHeard: (words: string) => void, phrasing: DictationWords = WORDS): ReportDictation {
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
  const levels = useLevelStore()
  // This session's language; whether it has heard anything; and the language to start again in, the plain way, once
  // the failed continuous session has ended.
  const session = useRef<string | null>(null)
  const heardAny = useRef(false)
  const again = useRef<string | null>(null)

  const settle = useCallback(() => {
    const words = joined(pending.current)
    pending.current = ''
    setHearing('')
    if (words) heard.current(words)
  }, [])

  const begin = useCallback(
    (lang: string) => {
      if (!speech) return
      levels.rest()
      session.current = lang
      heardAny.current = false
      const level = { enabled: true, intervalMillis: LEVEL_EVERY_MS }
      speech.start(
        plain
          ? { lang, interimResults: true, continuous: false, requiresOnDeviceRecognition: true, addsPunctuation: false, volumeChangeEventOptions: level }
          : { lang, interimResults: true, continuous: true, requiresOnDeviceRecognition: true, addsPunctuation: true, volumeChangeEventOptions: level },
      )
    },
    [speech, levels],
  )

  useEffect(() => {
    if (!speech || !onDevice) return
    const subscriptions = [
      speech.addListener('result', (event) => {
        const words = event.results?.[0]?.transcript ?? ''
        if (words) heardAny.current = true
        if (event.isFinal === false) {
          pending.current = words
          setHearing(joined(words))
          return
        }
        pending.current = words
        settle()
      }),
      speech.addListener('error', (event) => {
        levels.rest()
        const said = troubleOf(event.error, phrasing)
        // A continuous session that failed before a word, with an error that names nothing: once, the plain way,
        // when this one has ended (the recogniser sends "end" after its "error").
        if (Platform.OS === 'android' && !plain && !heardAny.current && said === phrasing.stopped && session.current) {
          plain = true
          again.current = session.current
          return
        }
        setListening(false)
        settle()
        if (event.error === 'language-not-supported') setModelNeeded(true)
        setTrouble(said)
      }),
      speech.addListener('end', () => {
        const lang = again.current
        if (lang) {
          again.current = null
          try {
            begin(lang)
            return
          } catch {
            setTrouble(phrasing.couldNotStart)
          }
        }
        setListening(false)
        levels.rest()
        settle()
      }),
      // What the microphone hears, as the recogniser measures it — the waveform's only source.
      speech.addListener('volumechange', (event) => levels.push(event.value)),
    ]
    return () => {
      for (const subscription of subscriptions) subscription.remove()
      again.current = null
      try {
        speech.stop()
      } catch {
        // already stopped
      }
    }
  }, [speech, onDevice, settle, levels, begin])

  const toggle = useCallback(async () => {
    if (!speech || !onDevice) return
    try {
      if (listening) return speech.stop()
      setTrouble(null)
      setNote(null)
      // Recognised on the device, so only the microphone is asked for (never iOS's speech-service permission).
      // Written as two plain calls so gate:store sees each request site and holds it to its iOS purpose string.
      const permission = speech.requestMicrophonePermissionsAsync ? await speech.requestMicrophonePermissionsAsync() : await speech.requestPermissionsAsync()
      if (!permission.granted) return setTrouble(phrasing.micOff)
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
      begin(lang)
      setListening(true)
    } catch {
      setListening(false)
      levels.rest()
      setTrouble(phrasing.couldNotStart)
    }
  }, [speech, onDevice, listening, levels, begin])

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
    reason: !speech ? phrasing.unavailable : !onDevice ? NO_ON_DEVICE : null,
    listening,
    hearing,
    trouble,
    note,
    model: available && modelNeeded && Platform.OS === 'android' && speech?.androidTriggerOfflineModelDownload ? { needed: true, downloading, download: () => void download() } : null,
    toggle: () => void toggle(),
    levels,
  }
}

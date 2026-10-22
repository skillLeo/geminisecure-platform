/**
 * SPEAK INSTEAD OF TYPING (M12 §2.9) — tap, speak, see the words as they are heard, edit them after; on the phone
 * only, so it works with no connection; the one-time model download offered, never assumed; and every refusal in a
 * sentence the person can act on. A fake recogniser stands in for expo-speech-recognition.
 */

import { act, renderHook } from '@testing-library/react-native'
import { Platform } from 'react-native'
import {
  MODEL_DIALOG,
  MODEL_FAILED,
  MODEL_NEEDED,
  MODEL_READY,
  MODEL_SCHEDULED,
  NO_ON_DEVICE,
  PREFERRED_LOCALE,
  modelSentence,
  offlineLocale,
  setSpeechModuleForTests,
  troubleOf,
  useDictation,
  type DictationWords,
  type SpeechModule,
} from '../dictation'

const WORDS: DictationWords = { unavailable: 'Not in this version.', stopped: 'Listening stopped.', couldNotStart: 'Could not start.', micOff: 'The microphone is off.' }

type Listener = (event: never) => void

function fakeSpeech(over: Partial<SpeechModule> = {}) {
  const listeners: Record<string, Listener[]> = {}
  const started: unknown[] = []
  const module: SpeechModule = {
    isRecognitionAvailable: () => true,
    supportsOnDeviceRecognition: () => true,
    requestMicrophonePermissionsAsync: jest.fn(async () => ({ granted: true })),
    requestPermissionsAsync: jest.fn(async () => ({ granted: true })),
    getSupportedLocales: jest.fn(async () => ({ locales: ['en-US', 'en-GB'], installedLocales: ['en-US'] })),
    androidTriggerOfflineModelDownload: jest.fn(async () => ({ status: 'download_success' as const })),
    start: jest.fn((options) => void started.push(options)),
    stop: jest.fn(),
    addListener: ((event: string, listener: Listener) => {
      ;(listeners[event] ??= []).push(listener)
      return { remove: () => undefined }
    }) as SpeechModule['addListener'],
    ...over,
  }
  const emit = async (event: string, payload?: unknown) => act(async () => { for (const l of listeners[event] ?? []) l(payload as never) })
  return { module, emit, started }
}

afterEach(() => setSpeechModuleForTests(undefined))

describe('the pure rules', () => {
  it('uses the preferred English when it is installed, any installed English otherwise, and says when there is none', () => {
    expect(offlineLocale({ locales: ['en-US'], installedLocales: ['en-US', 'fr-FR'] })).toBe(PREFERRED_LOCALE)
    expect(offlineLocale({ locales: ['en-US'], installedLocales: ['en_GB'] })).toBe('en-GB')
    expect(offlineLocale({ locales: ['en-US', 'fr-FR'], installedLocales: ['fr-FR'] })).toBeNull()
    // Android 12 and below answers with nothing at all: the recogniser is simply tried, on the device.
    expect(offlineLocale({ locales: [], installedLocales: [] })).toBeUndefined()
    expect(offlineLocale(null)).toBeUndefined()
  })

  it('silence is not a fault; a missing model, a refused microphone and a phone that cannot do it are each said', () => {
    for (const quiet of ['no-speech', 'speech-timeout', 'aborted', 'nomatch']) expect(troubleOf(quiet, WORDS)).toBeNull()
    expect(troubleOf('language-not-supported', WORDS)).toBe(MODEL_NEEDED)
    expect(troubleOf('not-allowed', WORDS)).toBe(WORDS.micOff)
    expect(troubleOf('service-not-allowed', WORDS)).toBe(NO_ON_DEVICE)
    expect(troubleOf('audio-capture', WORDS)).toBe(WORDS.stopped)
  })

  it('the model download is reported in the phone\'s own terms, and only a failure is trouble', () => {
    expect(modelSentence('download_success')).toEqual({ note: MODEL_READY, trouble: null })
    expect(modelSentence('download_scheduled')).toEqual({ note: MODEL_SCHEDULED, trouble: null })
    expect(modelSentence('opened_dialog')).toEqual({ note: MODEL_DIALOG, trouble: null })
    expect(modelSentence(null)).toEqual({ note: null, trouble: MODEL_FAILED })
  })
})

describe('the microphone', () => {
  it('with no module (Expo Go, the web, an old build) it is off, with the screen\'s own reason', async () => {
    setSpeechModuleForTests(null)
    const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
    expect(result.current).toMatchObject({ available: false, reason: WORDS.unavailable, listening: false, model: null })
  })

  it('a phone that cannot recognise on the device is off too: nothing falls back to a speech service', async () => {
    const { module } = fakeSpeech({ supportsOnDeviceRecognition: () => false })
    setSpeechModuleForTests(module)
    const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
    expect(result.current).toMatchObject({ available: false, reason: NO_ON_DEVICE })
  })

  it('tap, speak, the words shown as they are heard, then appended once they settle: always on the device', async () => {
    const fake = fakeSpeech()
    setSpeechModuleForTests(fake.module)
    const onHeard = jest.fn()
    const { result } = await renderHook(() => useDictation(onHeard, WORDS))

    await act(async () => result.current.toggle())
    expect(result.current.listening).toBe(true)
    expect(fake.started[0]).toMatchObject({ requiresOnDeviceRecognition: true, interimResults: true, continuous: true })
    // Only the microphone is asked for: recognised on the device, iOS's speech-service permission is never needed.
    expect(fake.module.requestMicrophonePermissionsAsync).toHaveBeenCalled()
    expect(fake.module.requestPermissionsAsync).not.toHaveBeenCalled()

    await fake.emit('result', { isFinal: false, results: [{ transcript: 'the street  light' }] })
    expect(result.current.hearing).toBe('the street light')
    expect(onHeard).not.toHaveBeenCalled()

    await fake.emit('result', { isFinal: true, results: [{ transcript: 'The street light by lot 47 is out.' }] })
    expect(onHeard).toHaveBeenCalledWith('The street light by lot 47 is out.')
    expect(result.current.hearing).toBe('')
  })

  it('words still being heard when listening stops are kept, never dropped', async () => {
    const fake = fakeSpeech()
    setSpeechModuleForTests(fake.module)
    const onHeard = jest.fn()
    const { result } = await renderHook(() => useDictation(onHeard, WORDS))
    await act(async () => result.current.toggle())
    await fake.emit('result', { isFinal: false, results: [{ transcript: 'water leaking under the sink' }] })
    await fake.emit('end')
    expect(onHeard).toHaveBeenCalledWith('water leaking under the sink')
    expect(result.current).toMatchObject({ listening: false, hearing: '' })
  })

  it('a refused microphone is said, and nothing starts', async () => {
    const fake = fakeSpeech({ requestMicrophonePermissionsAsync: jest.fn(async () => ({ granted: false })) })
    setSpeechModuleForTests(fake.module)
    const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
    await act(async () => result.current.toggle())
    expect(result.current).toMatchObject({ listening: false, trouble: WORDS.micOff })
    expect(fake.module.start).not.toHaveBeenCalled()
  })

  describe('on Android, the offline model', () => {
    beforeEach(() => jest.replaceProperty(Platform, 'OS', 'android'))
    afterEach(() => jest.restoreAllMocks())

    it('missing: offered, the reason said, and listening not started', async () => {
      const fake = fakeSpeech({ getSupportedLocales: jest.fn(async () => ({ locales: ['en-US'], installedLocales: [] })) })
      setSpeechModuleForTests(fake.module)
      const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
      await act(async () => result.current.toggle())
      expect(fake.module.start).not.toHaveBeenCalled()
      expect(result.current.trouble).toBe(MODEL_NEEDED)
      expect(result.current.model).toMatchObject({ needed: true, downloading: false })

      await act(async () => result.current.model?.download())
      expect(fake.module.androidTriggerOfflineModelDownload).toHaveBeenCalledWith({ locale: PREFERRED_LOCALE })
      expect(result.current).toMatchObject({ note: MODEL_READY, trouble: null, model: null })
    })

    it('a download that fails is trouble, and the offer stays', async () => {
      const fake = fakeSpeech({
        getSupportedLocales: jest.fn(async () => ({ locales: ['en-US'], installedLocales: [] })),
        androidTriggerOfflineModelDownload: jest.fn(async () => { throw new Error('offline') }),
      })
      setSpeechModuleForTests(fake.module)
      const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
      await act(async () => result.current.toggle())
      await act(async () => result.current.model?.download())
      expect(result.current).toMatchObject({ trouble: MODEL_FAILED, note: null })
      expect(result.current.model?.needed).toBe(true)
    })

    it('an installed English other than en-US is the one used', async () => {
      const fake = fakeSpeech({ getSupportedLocales: jest.fn(async () => ({ locales: ['en-US', 'en-GB'], installedLocales: ['en-GB'] })) })
      setSpeechModuleForTests(fake.module)
      const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
      await act(async () => result.current.toggle())
      expect(fake.started[0]).toMatchObject({ lang: 'en-GB', requiresOnDeviceRecognition: true })
    })

    it('the recogniser saying the language is not there mid-way offers the model too', async () => {
      const fake = fakeSpeech()
      setSpeechModuleForTests(fake.module)
      const { result } = await renderHook(() => useDictation(jest.fn(), WORDS))
      await act(async () => result.current.toggle())
      await fake.emit('error', { error: 'language-not-supported' })
      expect(result.current).toMatchObject({ listening: false, trouble: MODEL_NEEDED })
      expect(result.current.model?.needed).toBe(true)
    })
  })
})

/**
 * The report's microphone (board resident-app-14): the core recogniser's behaviour, plus the one thing this
 * screen-local copy adds — the waveform's level, taken ONLY from what the recogniser reports (`volumechange`),
 * never from a loop, and at rest when it reports nothing. A fake recogniser stands in for expo-speech-recognition.
 */

import { act, renderHook } from '@testing-library/react-native'
import { Platform } from 'react-native'
import { DICTATION_UNAVAILABLE, LEVEL_EVERY_MS, WAVE_BARS, levelOf, setReportSpeechForTests, stillLevels, useReportDictation } from '../dictation'

type Listener = (event: never) => void

function fakeSpeech(over: Record<string, unknown> = {}) {
  const listeners: Record<string, Listener[]> = {}
  const started: Record<string, unknown>[] = []
  const module = {
    isRecognitionAvailable: () => true,
    supportsOnDeviceRecognition: () => true,
    requestMicrophonePermissionsAsync: jest.fn(async () => ({ granted: true })),
    requestPermissionsAsync: jest.fn(async () => ({ granted: true })),
    getSupportedLocales: jest.fn(async () => ({ locales: ['en-US'], installedLocales: ['en-US'] })),
    start: jest.fn((options: Record<string, unknown>) => void started.push(options)),
    stop: jest.fn(),
    addListener: (event: string, listener: Listener) => {
      ;(listeners[event] ??= []).push(listener)
      return { remove: () => undefined }
    },
    ...over,
  }
  const emit = async (event: string, payload?: unknown) => act(async () => { for (const l of listeners[event] ?? []) l(payload as never) })
  return { module, emit, started }
}

const install = (fake: ReturnType<typeof fakeSpeech>) => setReportSpeechForTests(fake.module as unknown as Parameters<typeof setReportSpeechForTests>[0])
const atRest = Array.from({ length: WAVE_BARS }, () => 0)

afterEach(() => setReportSpeechForTests(undefined))

describe('the level, as a bar reads it', () => {
  it('is the recogniser\'s -2…10 scale as a share of the bar: below 0 is silence, 10 is full', () => {
    expect(levelOf(-2)).toBe(0)
    expect(levelOf(0)).toBe(0)
    expect(levelOf(5)).toBe(0.5)
    expect(levelOf(10)).toBe(1)
    expect(levelOf(14)).toBe(1)
    expect(levelOf(undefined)).toBe(0)
    expect(levelOf(Number.NaN)).toBe(0)
  })

  it('a still store never changes and never calls anybody', () => {
    const levels = stillLevels([0.2, 0.4])
    const listener = jest.fn()
    levels.subscribe(listener)()
    expect(levels.get()).toEqual([0.2, 0.4])
    expect(listener).not.toHaveBeenCalled()
    expect(stillLevels().get()).toEqual(atRest)
  })
})

describe('the microphone', () => {
  it('with no module (Expo Go, the web, an old build) it is off, with its reason, and the bars at rest', async () => {
    setReportSpeechForTests(null)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    expect(result.current).toMatchObject({ available: false, reason: DICTATION_UNAVAILABLE, listening: false })
    expect(result.current.levels.get()).toEqual(atRest)
  })

  it('asks the recogniser for its level: on the device only, as ever', async () => {
    const fake = fakeSpeech()
    install(fake)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    await act(async () => result.current.toggle())
    expect(result.current.listening).toBe(true)
    expect(fake.started[0]).toMatchObject({ requiresOnDeviceRecognition: true, interimResults: true, continuous: true, volumeChangeEventOptions: { enabled: true, intervalMillis: LEVEL_EVERY_MS } })
  })

  it('the bars follow what the microphone hears, newest on the right, and rest when listening stops', async () => {
    const fake = fakeSpeech()
    install(fake)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    const listener = jest.fn()
    result.current.levels.subscribe(listener)

    await act(async () => result.current.toggle())
    expect(result.current.levels.get()).toEqual(atRest)

    await fake.emit('volumechange', { value: 10 })
    await fake.emit('volumechange', { value: 5 })
    await fake.emit('volumechange', { value: -1 })
    expect(result.current.levels.get()).toEqual([0, 0, 0, 0, 1, 0.5, 0])
    expect(listener).toHaveBeenCalledTimes(3)

    await fake.emit('end')
    expect(result.current.listening).toBe(false)
    expect(result.current.levels.get()).toEqual(atRest)
  })

  it('a recogniser that reports no level leaves the bars still while it listens: nothing is made up', async () => {
    const fake = fakeSpeech()
    install(fake)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    await act(async () => result.current.toggle())
    await fake.emit('result', { isFinal: false, results: [{ transcript: 'a grey pickup' }] })
    expect(result.current.listening).toBe(true)
    expect(result.current.levels.get()).toEqual(atRest)
  })

  it('what is heard shows as it is heard, and is handed over once it settles', async () => {
    const fake = fakeSpeech()
    install(fake)
    const onHeard = jest.fn()
    const { result } = await renderHook(() => useReportDictation(onHeard))
    await act(async () => result.current.toggle())
    await fake.emit('result', { isFinal: false, results: [{ transcript: 'a grey  pickup' }] })
    expect(result.current.hearing).toBe('a grey pickup')
    await fake.emit('result', { isFinal: true, results: [{ transcript: 'A grey pickup with no plates.' }] })
    expect(onHeard).toHaveBeenCalledWith('A grey pickup with no plates.')
    expect(result.current.hearing).toBe('')
  })

  it('a refused microphone is said, and nothing starts', async () => {
    const fake = fakeSpeech({ requestMicrophonePermissionsAsync: jest.fn(async () => ({ granted: false })) })
    install(fake)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    await act(async () => result.current.toggle())
    expect(result.current.listening).toBe(false)
    expect(result.current.trouble).toMatch(/microphone is switched off/)
    expect(fake.module.start).not.toHaveBeenCalled()
  })
})

describe('the plain way, when a continuous session fails (M47 §13.4: a real Samsung)', () => {
  let os: jest.ReplaceProperty<typeof Platform.OS>
  beforeEach(() => {
    os = jest.replaceProperty(Platform, 'OS', 'android')
  })
  afterEach(() => os.restore())

  const STOPPED = /Listening stopped on this phone/

  it('a continuous session that fails before a word, with an error that names nothing, starts once more the plain way, and listening goes on', async () => {
    const fake = fakeSpeech()
    install(fake)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    await act(async () => result.current.toggle())
    expect(fake.started[0]).toMatchObject({ continuous: true })

    await fake.emit('error', { error: 'client' })
    expect(result.current.trouble).toBeNull()
    await fake.emit('end')
    expect(fake.started).toHaveLength(2)
    // The plainest session there is: no stream of the app's own, no formatting asked for — still on the device only.
    expect(fake.started[1]).toMatchObject({ lang: 'en-US', continuous: false, addsPunctuation: false, requiresOnDeviceRecognition: true, interimResults: true, volumeChangeEventOptions: { enabled: true, intervalMillis: LEVEL_EVERY_MS } })
    expect(result.current.listening).toBe(true)
    expect(result.current.trouble).toBeNull()

    // The plain session ends at a pause; the next tap listens the plain way straight away.
    await fake.emit('end')
    expect(result.current.listening).toBe(false)
    await act(async () => result.current.toggle())
    expect(fake.started[2]).toMatchObject({ continuous: false })
  })

  it('the plain way failing too is said, in the screen’s words, and nothing more is tried', async () => {
    const fake = fakeSpeech()
    install(fake)
    const { result } = await renderHook(() => useReportDictation(jest.fn()))
    await act(async () => result.current.toggle())
    await fake.emit('error', { error: 'client' })
    await fake.emit('end')
    await fake.emit('error', { error: 'network' })
    await fake.emit('end')
    expect(fake.started).toHaveLength(2)
    expect(result.current.listening).toBe(false)
    expect(result.current.trouble).toMatch(STOPPED)
  })

  it('a session that had heard words, or an error that names its cause, is never tried again', async () => {
    const fake = fakeSpeech()
    install(fake)
    const onHeard = jest.fn()
    const { result } = await renderHook(() => useReportDictation(onHeard))
    await act(async () => result.current.toggle())
    await fake.emit('result', { isFinal: false, results: [{ transcript: 'a grey pickup' }] })
    await fake.emit('error', { error: 'client' })
    await fake.emit('end')
    expect(fake.started).toHaveLength(1)
    expect(result.current.trouble).toMatch(STOPPED)
    // What was heard is kept, never dropped.
    expect(onHeard).toHaveBeenCalledWith('a grey pickup')

    const named = fakeSpeech()
    install(named)
    const second = await renderHook(() => useReportDictation(jest.fn()))
    await act(async () => second.result.current.toggle())
    await named.emit('error', { error: 'not-allowed' })
    await named.emit('end')
    expect(named.started).toHaveLength(1)
    expect(second.result.current.trouble).toMatch(/microphone is switched off/)
  })
})

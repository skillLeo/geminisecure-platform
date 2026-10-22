/* eslint-env jest */

// The tests' fixture estates are Jamaican, so the tests' build setting names Jamaica (M19A §9): the app's own code
// names no zone, and a build left unset reads UTC until the server names the estate's.
process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE = 'America/Jamaica'

// Jest hoists mock factories above everything else, so they may only close over
// variables whose names start with `mock`.
let mockUuidCounter = 0
jest.mock('expo-crypto', () => ({
  randomUUID: () => {
    mockUuidCounter += 1
    return `00000000-0000-4000-8000-${String(mockUuidCounter).padStart(12, '0')}`
  },
}))

jest.mock('expo-secure-store', () => {
  const mockVault = new Map()
  return {
    WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'WHEN_UNLOCKED_THIS_DEVICE_ONLY',
    getItemAsync: jest.fn(async (k) => (mockVault.has(k) ? mockVault.get(k) : null)),
    setItemAsync: jest.fn(async (k, v) => void mockVault.set(k, v)),
    deleteItemAsync: jest.fn(async (k) => void mockVault.delete(k)),
  }
})

jest.mock('@react-native-async-storage/async-storage', () =>
  require('@react-native-async-storage/async-storage/jest/async-storage-mock'),
)

// "Share live audio" (M50 §4.2 #1): a native recorder no test can open. The microphone reads as not allowed, so
// nothing records unless a test says otherwise.
jest.mock('expo-audio', () => ({
  AudioQuality: { MIN: 0, LOW: 32, MEDIUM: 64, HIGH: 96, MAX: 127 },
  IOSOutputFormat: { MPEG4AAC: 'aac ' },
  getRecordingPermissionsAsync: jest.fn(async () => ({ granted: false, status: 'denied', canAskAgain: true, expires: 'never' })),
  requestRecordingPermissionsAsync: jest.fn(async () => ({ granted: false, status: 'denied', canAskAgain: true, expires: 'never' })),
  setAudioModeAsync: jest.fn(async () => undefined),
  useAudioRecorder: () => ({ prepareToRecordAsync: jest.fn(async () => undefined), record: jest.fn(), stop: jest.fn(async () => undefined), uri: null, isRecording: false }),
}))

/**
 * "BEFORE WE GET STARTED" IS EXPLAINED ONCE PER PHONE (M47 §3).
 *
 * It came back after every sign-in: its "explained" flag sat in the key-value store every sign-out wipes. It is done
 * once THIS phone has answered each permission it asks — which the phone keeps through every sign-out — or once
 * "Continue" was pressed here.
 */

import { getStorage } from '@gs/core/lib'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), replace: jest.fn() }, usePathname: () => '/', useFocusEffect: jest.fn() }))

type Answer = { granted: boolean; canAskAgain: boolean; status: string }
const ASKED: Answer = { granted: true, canAskAgain: true, status: 'granted' }
const REFUSED: Answer = { granted: false, canAskAgain: true, status: 'denied' }
const NOT_YET: Answer = { granted: false, canAskAgain: true, status: 'undetermined' }

const mockAnswers: { notifications: Answer; location: Answer; camera: Answer } = { notifications: ASKED, location: ASKED, camera: ASKED }
jest.mock('@gs/core/notify', () => ({ notificationPermission: jest.fn(async () => mockAnswers.notifications) }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => mockAnswers.location), requestForegroundPermissionsAsync: jest.fn() }))
jest.mock('expo-camera', () => ({ Camera: { getCameraPermissionsAsync: jest.fn(async () => mockAnswers.camera), requestCameraPermissionsAsync: jest.fn() } }))

/** A fresh launch of the app's code: nothing in memory, the phone's answers and the key-value store as they are. */
function launch(): typeof import('../usePermissions').permissionsAsked {
  let fresh: typeof import('../usePermissions').permissionsAsked | undefined
  jest.isolateModules(() => {
    fresh = (require('../usePermissions') as typeof import('../usePermissions')).permissionsAsked
  })
  return fresh!
}

beforeEach(async () => {
  await getStorage().clearAll()
  Object.assign(mockAnswers, { notifications: ASKED, location: ASKED, camera: ASKED })
})

it('is done once the phone has answered every permission, allowed or not, with nothing on the key-value store', async () => {
  mockAnswers.location = REFUSED
  const asked = launch()
  expect(asked.known()).toBe(false)

  await asked.hydrate()

  expect(asked.known()).toBe(true)
  expect(asked.get()).toBe(true)
})

it('is explained while the phone still has a permission it has never been asked', async () => {
  mockAnswers.camera = NOT_YET
  const asked = launch()
  await asked.hydrate()
  expect(asked.get()).toBe(false)
})

it('counts "Continue" pressed here too, whatever the phone could answer', async () => {
  mockAnswers.notifications = NOT_YET
  const asked = launch()
  asked.mark()
  await asked.hydrate()
  expect(asked.get()).toBe(true)
})

it('survives a sign-out: the store is wiped, the phone still remembers what it answered', async () => {
  const before = launch()
  before.mark()
  await getStorage().clearAll()

  const after = launch()
  await after.hydrate()
  expect(after.get()).toBe(true)
})

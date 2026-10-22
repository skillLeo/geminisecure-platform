/**
 * refetchOnReconnect / refetchOnFocus ON A PHONE (M11 Part C). RTK's own listeners are the browser's `online` and
 * `visibilitychange`, which React Native has neither of, so until the native handler neither ever fired on either
 * app. It is driven here with NetInfo's and AppState's events, and watched through the actions RTK is sent.
 */

import { AppState, NativeModules } from 'react-native'
import { setupListeners } from '@reduxjs/toolkit/query'
import { nativeListeners } from '../store'

let mockNet: ((s: { isConnected: boolean | null; isInternetReachable?: boolean | null }) => void) | null = null
jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {
    addEventListener: (fn: typeof mockNet) => {
      mockNet = fn
      return () => {
        mockNet = null
      }
    },
  },
}))

let appStateFn: ((s: string) => void) | null = null

const FOCUSED = '__rtkq/focused'
const UNFOCUSED = '__rtkq/unfocused'
const ONLINE = '__rtkq/online'
const OFFLINE = '__rtkq/offline'

/** The handler as RTK runs it: through setupListeners, with RTK's own four actions. */
function start() {
  const sent: string[] = []
  const dispatch = ((action: { type: string }) => void sent.push(action.type)) as never
  const off = setupListeners(dispatch, nativeListeners)
  return { sent, off }
}

beforeEach(() => {
  mockNet = null
  appStateFn = null
  NativeModules.RNCNetInfo = {}
  jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, fn: (s: string) => void) => {
    appStateFn = fn
    return { remove: () => (appStateFn = null) }
  }) as never)
})

afterEach(() => {
  delete NativeModules.RNCNetInfo
  jest.restoreAllMocks()
})

describe('NetInfo', () => {
  it('its first answer at start-up is not a reconnect: nothing is refetched for it', () => {
    const { sent } = start()
    mockNet?.({ isConnected: true, isInternetReachable: true })
    expect(sent).toEqual([])
  })

  it('offline → online is: onOffline, then onOnline once: the same test the offline line uses', () => {
    const { sent } = start()
    mockNet?.({ isConnected: false })
    mockNet?.({ isConnected: false })
    mockNet?.({ isConnected: true, isInternetReachable: null }) // not known yet: counted as reachable
    mockNet?.({ isConnected: true, isInternetReachable: true })
    expect(sent).toEqual([OFFLINE, ONLINE])
  })

  it('connected to a network that reaches nothing is offline', () => {
    const { sent } = start()
    mockNet?.({ isConnected: true, isInternetReachable: false })
    expect(sent).toEqual([OFFLINE])
  })

  it('where NetInfo’s native half is missing, it is not used at all (its first read would fail out of reach)', () => {
    delete NativeModules.RNCNetInfo
    start()
    expect(mockNet).toBeNull()
  })
})

describe('AppState', () => {
  it('background → active is a focus; iOS’s inactive (the pull-down shade) is neither', () => {
    const { sent } = start()
    appStateFn?.('inactive')
    appStateFn?.('active')
    expect(sent).toEqual([])
    appStateFn?.('background')
    appStateFn?.('background')
    appStateFn?.('active')
    expect(sent).toEqual([UNFOCUSED, FOCUSED])
  })

  it('the returned function stops both', () => {
    const { off } = start()
    off()
    expect(mockNet).toBeNull()
    expect(appStateFn).toBeNull()
  })
})

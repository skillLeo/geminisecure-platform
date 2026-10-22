/**
 * THE CONSTRUCTOR, WHATEVER SHAPE PUSHER-JS EXPORTS IT IN (found on production, 26 Sep, M13).
 *
 * pusher-js's React Native build exports `{ Pusher }`. The adapter read only `default`, handed the module object to
 * `new`, and the throw was swallowed by `openSocket`'s catch: every phone polled and no phone ever opened a socket.
 * These tests hold the unwrapping to every shape, and — the one that matters — to the package as installed.
 */

import { pusherConstructorOf } from '../socket'

jest.mock('@react-native-community/netinfo', () => {
  const api = { fetch: () => Promise.resolve({ type: 'wifi', isConnected: true }), addEventListener: () => () => undefined }
  return { __esModule: true, default: api, ...api }
})

class FakePusher {}

describe('pusherConstructorOf', () => {
  it('takes the class itself (the Node build)', () => {
    expect(pusherConstructorOf(FakePusher)).toBe(FakePusher)
  })

  it('takes a named `Pusher` export (the React Native build)', () => {
    expect(pusherConstructorOf({ Pusher: FakePusher })).toBe(FakePusher)
  })

  it('takes a default export (a bundler’s ESM interop)', () => {
    expect(pusherConstructorOf({ default: FakePusher })).toBe(FakePusher)
    expect(pusherConstructorOf({ default: { Pusher: FakePusher } })).toBe(FakePusher)
  })

  it('refuses, by name, a module with no constructor in it', () => {
    expect(() => pusherConstructorOf({})).toThrow('pusher-js exported no Pusher constructor')
    expect(() => pusherConstructorOf(null)).toThrow('pusher-js exported no Pusher constructor')
  })

  it('finds a constructor in pusher-js/react-native AS INSTALLED: the shape a phone actually loads', () => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const installed = require('pusher-js/react-native')
    const Pusher = pusherConstructorOf(installed)

    expect(typeof Pusher).toBe('function')
    expect(typeof (Pusher as unknown as { prototype: { subscribe?: unknown } }).prototype.subscribe).toBe('function')
  })
})

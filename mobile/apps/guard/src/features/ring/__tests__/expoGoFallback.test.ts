/**
 * Requirement 5 (GUARD_RING_DESIGN §11): without the native module — Expo Go, an iPhone, an older APK — the Guard App
 * does exactly what it did before the ring existed: today's MAX `safety` channel notification, while the app is open.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

type NativeModule = typeof import('../native')

function loadNative(os: 'android' | 'ios' | 'web', expoGo: boolean, present: boolean): { mod: NativeModule; asked: jest.Mock } {
  const asked = jest.fn(() => (present ? { configure: jest.fn() } : null))
  let mod: NativeModule | undefined
  jest.isolateModules(() => {
    // native.ts reads only Platform.OS from react-native.
    jest.doMock('react-native', () => ({ Platform: { OS: os } }))
    jest.doMock('expo', () => ({ requireOptionalNativeModule: asked }))
    jest.doMock('@gs/core/lib', () => ({ isExpoGo: expoGo }))
    mod = require('../native') as NativeModule
  })
  // A mock made with doMock outlives isolateModules: take them back, or the rest of the run meets a one-line react-native.
  jest.dontMock('react-native')
  jest.dontMock('expo')
  jest.dontMock('@gs/core/lib')
  return { mod: mod!, asked }
}

describe('the native module is optional, and absent means today', () => {
  it('in Expo Go the module is null and never even asked for', () => {
    const { mod, asked } = loadNative('android', true, true)
    expect(mod.GuardRing).toBeNull()
    expect(asked).not.toHaveBeenCalled()
    expect(mod.availability()).toBe('expo_go')
  })

  it('on an iPhone the module is null', () => {
    const { mod, asked } = loadNative('ios', false, true)
    expect(mod.GuardRing).toBeNull()
    expect(asked).not.toHaveBeenCalled()
    expect(mod.availability()).toBe('ios')
  })

  it('an older APK without the module answers null, not a crash', () => {
    const { mod, asked } = loadNative('android', false, false)
    expect(asked).toHaveBeenCalledWith('GuardRing')
    expect(mod.GuardRing).toBeNull()
    expect(mod.availability()).toBe('no_module')
  })

  it('a build with the module has it', () => {
    const { mod } = loadNative('android', false, true)
    expect(mod.GuardRing).not.toBeNull()
    expect(mod.availability()).toBe('android')
  })

  it('it is loaded optionally, and only there: nothing else in the app opens it', () => {
    const native = readFileSync(path.resolve(__dirname, '..', 'native.ts'), 'utf8')
    expect(native).toMatch(/requireOptionalNativeModule<GuardRingNative>\('GuardRing'\)/)
    expect(native.replace(/\/\*[\s\S]*?\*\//g, '')).not.toMatch(/\brequireNativeModule\(/)
  })
})

describe('without the module, nothing the phone sends or shows changes', () => {
  it('the registration carries no readiness report', () => {
    const { serverReport } = jest.requireActual('../readiness') as typeof import('../readiness')
    expect(serverReport(null)).toBeUndefined()
    const wiring = readFileSync(path.resolve(__dirname, '..', '..', 'notify', 'pushRegistration.ts'), 'utf8')
    expect(wiring).toMatch(/ringReport: async \(\) => \(GuardRing \? serverReport\(await GuardRing\.readiness\(\)\) : undefined\)/)
  })

  it('the Call-style screen says the build note instead of rows', () => {
    const { NOTES } = jest.requireActual('../readiness') as typeof import('../readiness')
    expect(NOTES.expo_go).toMatch(/Guard App build from the office/)
    const hook = readFileSync(path.resolve(__dirname, '..', 'useCallStyle.ts'), 'utf8')
    expect(hook).toMatch(/note: where === 'android' \? null : NOTES\[where\]/)
    expect(hook).toMatch(/if \(where !== 'android' \|\| !module\) return/)
  })

  it('today\'s local banners still go on the MAX safety channel', () => {
    const runtime = readFileSync(path.resolve(__dirname, '..', '..', '..', '..', '..', '..', 'packages', 'core', 'notify', 'runtime.ts'), 'utf8')
    expect(runtime).toMatch(/channelId: input\.urgent \? SAFETY_CHANNEL : channelFor\(input\.data\.type\)/)
    expect(runtime).toMatch(/importance: AndroidImportance\.MAX/)
  })

  it('an expired session puts the ring back only when there is a ring to put back', () => {
    const store = readFileSync(path.resolve(__dirname, '..', '..', '..', 'store', 'index.ts'), 'utf8')
    expect(store).toMatch(/if \(reason === 'expired'\) await GuardRing\?\.restore\(\)/)
  })
})

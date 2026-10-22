/**
 * NO MATERIAL SYMBOLS FONT ON A PHONE — AND NOTHING THAT WOULD DRAW ONE (M11 A4).
 *
 * expo-router's native tabs import expo-symbols, which imports a 966,544-byte Material Symbols font, so
 * Metro packed it into every APK although neither app draws a Material Symbol. Both apps' Metro configs
 * now resolve that import to an empty module for a phone's release bundle (tools/metro/
 * withoutMaterialSymbols.cjs). That is only safe while nothing in either app can draw one, so this test
 * holds both halves:
 *   - the resolver: empty for an Android or iOS release bundle; exactly as before for development and
 *     web; every other module to the resolver already in the config;
 *   - the apps: both configs use it, and no source imports expo-symbols, a SymbolView, the native tabs,
 *     the material-symbol image helper or the font itself. The day one does, this fails, and the font
 *     must be let back in.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

type Resolution = { type: string; filePath?: string }
type Resolve = (context: { dev: boolean; resolveRequest: Resolve }, moduleName: string, platform: string | null) => Resolution
type Config = { resolver: { resolveRequest?: Resolve } }

const repo = path.resolve(__dirname, '..', '..', '..', '..')
// eslint-disable-next-line @typescript-eslint/no-require-imports
const { withoutMaterialSymbols, MATERIAL_SYMBOLS } = require(path.join(repo, 'tools', 'metro', 'withoutMaterialSymbols.cjs')) as { withoutMaterialSymbols: (config: Config) => Config; MATERIAL_SYMBOLS: RegExp }

const FONT = '@expo-google-fonts/material-symbols/400Regular'

describe('the resolver', () => {
  const upstream = jest.fn<Resolution, Parameters<Resolve>>((_context, moduleName) => ({ type: 'sourceFile', filePath: `/upstream/${moduleName}` }))
  const metro = jest.fn<Resolution, Parameters<Resolve>>((_context, moduleName) => ({ type: 'sourceFile', filePath: `/metro/${moduleName}` }))
  const resolve = (config: Config, moduleName: string, platform: string | null, dev: boolean) => {
    const rr = config.resolver.resolveRequest
    if (!rr) throw new Error('no resolveRequest')
    return rr({ dev, resolveRequest: metro }, moduleName, platform)
  }
  beforeEach(() => {
    upstream.mockClear()
    metro.mockClear()
  })

  it.each(['android', 'ios'])('a %s release bundle gets an empty module for the font, and never asks further', (platform) => {
    const config = withoutMaterialSymbols({ resolver: { resolveRequest: upstream } })
    expect(resolve(config, FONT, platform, false)).toEqual({ type: 'empty' })
    expect(upstream).not.toHaveBeenCalled()
  })

  it.each([
    ['android', true],
    ['ios', true],
    ['web', false],
    ['web', true],
  ] as const)('%s with dev=%s resolves the font exactly as before', (platform, dev) => {
    const config = withoutMaterialSymbols({ resolver: { resolveRequest: upstream } })
    expect(resolve(config, FONT, platform, dev)).toEqual({ type: 'sourceFile', filePath: `/upstream/${FONT}` })
  })

  it('every other module goes to the resolver already in the config, and with none, to Metro’s own', () => {
    const chained = withoutMaterialSymbols({ resolver: { resolveRequest: upstream } })
    expect(resolve(chained, 'expo-router', 'android', false)).toEqual({ type: 'sourceFile', filePath: '/upstream/expo-router' })
    const bare = withoutMaterialSymbols({ resolver: {} })
    expect(resolve(bare, 'expo-router', 'android', false)).toEqual({ type: 'sourceFile', filePath: '/metro/expo-router' })
    expect(resolve(bare, FONT, 'android', false)).toEqual({ type: 'empty' })
  })

  it('it is that font package and its weights, nothing else, not the app’s own fonts, not expo-symbols', () => {
    for (const name of [FONT, '@expo-google-fonts/material-symbols', '@expo-google-fonts/material-symbols/700Bold']) expect(MATERIAL_SYMBOLS.test(name)).toBe(true)
    for (const name of ['@expo-google-fonts/inter', '@expo-google-fonts/poppins/600SemiBold', '@expo-google-fonts/material-symbols-rounded', '@expo/material-symbols/star.xml', 'expo-symbols', '../assets/fonts/Inter-Bold.ttf']) expect(MATERIAL_SYMBOLS.test(name)).toBe(false)
  })
})

/** Every source file of the two apps and the shared package: routes, features, the kit. Tests excluded. */
function sources(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name === '__tests__' || entry.name === 'android' || entry.name === 'ios' || entry.name.startsWith('.')) continue
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) out.push(...sources(full))
    else if (/\.(ts|tsx|js|jsx|cjs|mjs)$/.test(entry.name)) out.push(full)
  }
  return out
}

describe('both apps use it, and neither can draw a Material Symbol', () => {
  it.each(['guard', 'resident'])('the %s app’s metro.config.js wraps its whole config in it', (app) => {
    const source = readFileSync(path.join(repo, 'apps', app, 'metro.config.js'), 'utf8')
    expect(source).toContain("require('../../tools/metro/withoutMaterialSymbols.cjs')")
    expect(source).toMatch(/module\.exports = withoutMaterialSymbols\(\s*withNativeWind\(/)
  })

  it('no source imports expo-symbols, a SymbolView, the native tabs, the image helper or the font', () => {
    const files = [...sources(path.join(repo, 'apps', 'guard')), ...sources(path.join(repo, 'apps', 'resident')), ...sources(path.join(repo, 'packages', 'core'))]
    expect(files.length).toBeGreaterThan(300)
    const DRAWS = /['"]expo-symbols['"]|\bSymbolView\b|unstable-native-tabs|\bNativeTabs\b|unstable_getMaterialSymbolSourceAsync|@expo-google-fonts\/material-symbols/
    const offenders = files.filter((f) => DRAWS.test(readFileSync(f, 'utf8'))).map((f) => path.relative(repo, f))
    expect(offenders).toEqual([])
  })
})

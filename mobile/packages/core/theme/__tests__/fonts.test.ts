/**
 * EVERY FACE IS IN THE APP ONCE — AND NEVER NOT AT ALL (M11 A4).
 *
 * A release build for a phone gets its fonts from the expo-font config plugin (app.config.ts →
 * FONT_FILES → Android `assets/fonts/`, iOS `UIAppFonts`); `fontAssets` is `{}` there, so Metro no
 * longer packs a second copy of each file. That is only safe while the plugin embeds EVERY face the
 * app draws — a face missing from FONT_FILES would fall back to the system font on a phone and be
 * invisible here and on web, where the runtime copy still loads. So this test holds all three lists
 * to one another: the faces the type layer names, the runtime map, and both apps' FONT_FILES.
 */

import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { fontAssets } from '../fonts'
import { fontFamily } from '../typography'

const faces = Object.values(fontFamily).sort()
const repo = path.resolve(__dirname, '..', '..', '..', '..')

function fontFiles(app: 'guard' | 'resident'): { dir: string; names: string[] } {
  const source = readFileSync(path.join(repo, 'apps', app, 'app.config.ts'), 'utf8')
  const dir = /const FONT_DIR = '([^']+)'/.exec(source)?.[1] ?? ''
  const list = /const FONT_FILES = \[([\s\S]*?)\]\.map\(\(f\) => `\$\{FONT_DIR\}\/\$\{f\}\.ttf`\)/.exec(source)?.[1] ?? ''
  return { dir, names: [...list.matchAll(/'([^']+)'/g)].map((m) => m[1] ?? '').sort() }
}

describe('the nine faces, once each', () => {
  it('in development (Expo Go, web) every face the type layer names is loaded at runtime', () => {
    expect(__DEV__).toBe(true)
    expect(Object.keys(fontAssets).sort()).toEqual(faces)
    for (const face of faces) expect(typeof fontAssets[face]).toBe('number')
  })

  it.each(['guard', 'resident'] as const)('the %s app embeds exactly those faces, and every file exists', (app) => {
    const { dir, names } = fontFiles(app)
    expect(names).toEqual(faces)
    for (const name of names) expect(existsSync(path.join(repo, 'apps', app, dir, `${name}.ttf`))).toBe(true)
  })

  it('the runtime copies are required only behind the development-or-web switch', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'fonts.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    const branch = source.indexOf('__DEV__ || Platform.OS === \'web\'')
    expect(branch).toBeGreaterThan(-1)
    const requires = [...source.matchAll(/require\('\.\.\/assets\/fonts\//g)].map((m) => m.index ?? -1)
    expect(requires).toHaveLength(faces.length)
    for (const at of requires) expect(at).toBeGreaterThan(branch)
    // …and the other arm is empty: a phone's build asks Metro for no font file at all.
    expect(source.trimEnd()).toMatch(/:\s*\{\}\s*$/)
  })
})

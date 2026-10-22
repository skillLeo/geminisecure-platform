/**
 * THE RESIDENT APP NEVER IMPORTS THE CRYPTO INDEX (M11 A4).
 *
 * `@gs/core/crypto` is the Guard App's: its index loads the offline pass verifier and tweetnacl
 * (≈ 60 KB of JavaScript in the release bundle, and a random-number generator set up at launch). The
 * Resident App needs one thing from it — this install's id — and takes it from
 * `@gs/core/crypto/deviceUid`. One stray import of the index would put all of it back, silently.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(__dirname, '..', '..', '..', '..')

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = path.join(dir, name)
    if (statSync(full).isDirectory()) return name === 'node_modules' || name === '__tests__' ? [] : sources(full)
    return /\.(ts|tsx)$/.test(name) ? [full] : []
  })
}

it('no resident source imports the crypto index, deviceKey or the pass verifier', () => {
  const files = [...sources(path.join(root, 'src')), ...sources(path.join(root, 'app'))]
  expect(files.length).toBeGreaterThan(100)
  const offenders = files.filter((file) => /from '@gs\/core\/crypto'|@gs\/core\/crypto\/(deviceKey|passVerifier)|from 'tweetnacl'/.test(readFileSync(file, 'utf8')))
  expect(offenders).toEqual([])
})

it('the one thing it needs comes from its own module', () => {
  expect(readFileSync(path.join(root, 'src', 'features', 'auth', 'useSignIn.ts'), 'utf8')).toMatch(/from '@gs\/core\/crypto\/deviceUid'/)
})

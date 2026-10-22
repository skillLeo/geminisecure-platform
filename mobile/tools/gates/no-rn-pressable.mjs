/**
 * GATE — no React Native `Pressable` outside packages/core/ui/Pressable.tsx.
 *
 * NativeWind's native wrapper drops a Pressable's style FUNCTION (`style={({ pressed }) => …}`):
 * on a phone the control loses its box, height and colour, while web — where the fidelity
 * harness runs — draws it perfectly. Found on a real Android phone. The core
 * wrapper resolves the function itself; everything imports `Pressable` from `@gs/core`.
 * A control that draws correctly on a computer can draw as bare text on a handset.
 *
 *   node tools/gates/no-rn-pressable.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const ALLOWED = 'packages/core/ui/Pressable.tsx'
// Generated from the boards and never shipped as screens: they hold no Pressable at all, but are skipped for speed.
const SKIP = new Set(['node_modules', 'lifted', '.expo', 'dist', 'android', 'ios'])

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.(tsx?|jsx?)$/.test(name) ? [full] : []
  })

const IMPORT = /import\s*\{([^}]*)\}\s*from\s*['"]react-native['"]/g
const offenders = []

for (const file of [...walk(path.join(ROOT, 'apps')), ...walk(path.join(ROOT, 'packages'))]) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  if (rel === ALLOWED) continue
  const source = readFileSync(file, 'utf8')
  for (const match of source.matchAll(IMPORT)) {
    const names = match[1].split(',').map((s) => s.trim().split(/\s+as\s+/)[0])
    if (names.includes('Pressable')) offenders.push(`${rel}:${source.slice(0, match.index).split('\n').length}`)
  }
}

if (offenders.length > 0) {
  console.log("GATE pressable · FAIL — import Pressable from '@gs/core', not from 'react-native':")
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log("GATE pressable · every Pressable comes from '@gs/core'")

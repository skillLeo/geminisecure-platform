/**
 * Which NATIVE libraries does each app depend on but never import?
 *
 * A native library the app never calls still merges its permissions and services into the manifest,
 * and still has to be explained in the stores' privacy declarations. This lists, per app, every
 * dependency with an android/ or ios/ folder and whether any shipped source file — the app's own
 * or packages/core's — imports it (statically, lazily with require(), or as a config plugin).
 *
 *   node tools/store/unused-native.mjs          (exit 1 if a native dependency is imported by nothing)
 *   node tools/store/unused-native.mjs --json   the same answer as data, and ALWAYS exit 0
 *
 * `--json` is data, not a verdict, so it does not fail: it exists so a gate can consume this set
 * rather than work it out again. `unusedSourceProblems` in tools/gates/permission-rules.mjs is the
 * one that turns it into a verdict, by joining it to the merged manifest — a permission whose only
 * source is a library nothing imports.
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'lifted'])

// Needed by the platform or the build even though no source file names them.
const ALWAYS = new Set(['expo', 'react', 'react-dom', 'react-native', 'react-native-web', 'expo-build-properties', 'expo-splash-screen', 'expo-system-ui', 'expo-status-bar', 'expo-font', 'expo-router', 'expo-linking', 'expo-constants', 'react-native-screens', 'react-native-safe-area-context', 'react-native-gesture-handler', 'react-native-reanimated', 'react-native-worklets', 'nativewind', 'react-native-nitro-modules'])

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.(ts|tsx|js|cjs|mjs)$/.test(name) ? [full] : []
  })

const core = walk(path.join(ROOT, 'packages', 'core')).map((f) => readFileSync(f, 'utf8')).join('\n')
const JSON_OUT = process.argv.includes('--json')
const report = []
let unused = 0

for (const app of ['guard', 'resident']) {
  const dir = path.join(ROOT, 'apps', app)
  const pkg = JSON.parse(readFileSync(path.join(dir, 'package.json'), 'utf8'))
  const source = [...walk(path.join(dir, 'src')), ...walk(path.join(dir, 'app')), path.join(dir, 'app.config.ts')].map((f) => readFileSync(f, 'utf8')).join('\n') + core
  const row = { app, used: [], unused: [] }
  report.push(row)
  if (!JSON_OUT) console.log(`\n${app}`)
  for (const dep of Object.keys(pkg.dependencies ?? {}).sort()) {
    const at = [path.join(dir, 'node_modules', dep), path.join(ROOT, 'node_modules', dep)].find(existsSync)
    if (!at || !(existsSync(path.join(at, 'android')) || existsSync(path.join(at, 'ios')))) continue
    if (ALWAYS.has(dep)) continue
    const used = new RegExp(`['"\`]${dep.replace(/[/\\^$*+?.()|[\]{}-]/g, '\\$&')}(/[^'"\`]*)?['"\`]`).test(source)
    ;(used ? row.used : row.unused).push(dep)
    if (!used) unused++
    if (!JSON_OUT) console.log(`  ${used ? 'used  ' : 'UNUSED'}  ${dep}`)
  }
}

// Data, not a verdict: a gate reads this to build its own answer, so it must not exit non-zero.
if (JSON_OUT) {
  console.log(JSON.stringify(report))
  process.exit(0)
}

if (unused > 0) {
  console.log(`\n${unused} native dependenc${unused === 1 ? 'y is' : 'ies are'} imported by nothing — remove them, or they ship permissions and code nobody can explain.`)
  process.exit(1)
}
console.log('\nevery native dependency is imported by something that ships')

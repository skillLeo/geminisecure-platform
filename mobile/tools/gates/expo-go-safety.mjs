/**
 * GATE — nothing the apps import may THROW AT IMPORT inside Expo Go.
 *
 * The browser harness and Jest never see this class of bug. On 2026-09-20 the client's phone stopped at
 * the first screen of both apps: `import … from 'expo-notifications'` runs the package's push
 * auto-registration side effect (`DevicePushTokenAutoRegistration.fx`), and since SDK 53 that throws
 * inside Expo Go on Android. One import, in a file every screen depends on, and the app is dead.
 *
 * So:
 *   1  the package's INDEX is never imported or required, anywhere        ('expo-notifications')
 *   2  a deep import ('expo-notifications/build/X') is fine only if X's own static import graph never
 *      reaches a side-effect module (Expo names them `*.fx.js`) — checked by walking the real files
 *   3  a lazy `require('expo-notifications/build/…')` of a module that DOES reach one is allowed in exactly
 *      one place, packages/core/notify/runtime.ts, where it sits behind `isExpoGo`
 *   4  a native module is only ever loaded OPTIONALLY: `requireNativeModule(` THROWS when the module is not
 *      in the binary — Expo Go, or an older APK than the JS — so it is refused everywhere; use
 *      `requireOptionalNativeModule`, which answers null (the call-style ring's features/ring/native.ts)
 *   5  the ring's own module (`guard-ring`) is never imported by name: it has no JS, and Expo Go has no such module
 *
 * The in-app modules under apps/<app>/modules are scanned too, so a TypeScript file added there later is held
 * to the same rules.
 *
 *   node tools/gates/expo-go-safety.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const PKG = 'expo-notifications'
const BUILD = path.join(ROOT, 'node_modules', PKG, 'build')
const LAZY_OK = 'packages/core/notify/runtime.ts'
// Installed for the development / production build; their native halves do not exist inside Expo Go.
const NOT_IN_EXPO_GO = ['react-native-mmkv', 'react-native-nitro-modules', 'jail-monkey', 'react-native-ssl-public-key-pinning', 'guard-ring']
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'generated', 'lifted'])

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.(ts|tsx)$/.test(name) ? [full] : []
  })

/** Every file a module under build/ statically imports, platform variants included. Returns the first side-effect module reached, or null. */
const reached = new Map()
function reachesSideEffect(spec, seen = new Set()) {
  if (reached.has(spec)) return reached.get(spec)
  const base = path.join(BUILD, spec)
  const files = ['.js', '.android.js', '.ios.js', '.native.js'].map((ext) => base + ext).filter(existsSync)
  let hit = null
  for (const file of files) {
    if (seen.has(file)) continue
    seen.add(file)
    if (/\.fx\.(android\.|ios\.|native\.)?js$/.test(file)) { hit = path.basename(file); break }
    const source = readFileSync(file, 'utf8')
    for (const m of source.matchAll(/^\s*(?:import|export)\s[^'"]*?from\s*['"](\.[^'"]+)['"]|^\s*import\s*['"](\.[^'"]+)['"]/gm)) {
      const next = path.relative(BUILD, path.resolve(path.dirname(file), m[1] ?? m[2])).replace(/\\/g, '/')
      hit = reachesSideEffect(next, seen)
      if (hit) break
    }
    if (hit) break
  }
  reached.set(spec, hit)
  return hit
}

const offenders = []
let scanned = 0
const roots = [path.join(ROOT, 'packages', 'core'), ...['guard', 'resident'].flatMap((app) => [path.join(ROOT, 'apps', app, 'src'), path.join(ROOT, 'apps', app, 'app'), path.join(ROOT, 'apps', app, 'modules')])]

for (const file of roots.flatMap(walk)) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  scanned++
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
    const at = `${rel}:${i + 1}`
    if (new RegExp(`(from|require\\(|import\\()\\s*['"]${PKG}['"]`).test(line)) {
      offenders.push(`${at}  imports the ${PKG} index — it throws at import inside Expo Go on Android; use @gs/core/notify`)
      return
    }
    if (/\brequireNativeModule\s*[<(]/.test(line.replace(/\/\/.*$/, ''))) {
      offenders.push(`${at}  requireNativeModule() throws when the module is not in the binary (Expo Go, an older APK) — use requireOptionalNativeModule() and handle null`)
      return
    }
    if (/(from\s*|require\(\s*|import\(\s*|^\s*import\s*)['"]guard-ring['"]/.test(line)) {
      offenders.push(`${at}  imports the guard-ring module by name — it has no JS; reach it only through apps/guard/src/features/ring/native.ts (requireOptionalNativeModule('GuardRing'))`)
      return
    }
    const absent = NOT_IN_EXPO_GO.find((name) => new RegExp(`from\\s*['"]${name}['"]|^\\s*import\\s*['"]${name}['"]`).test(line))
    if (absent) {
      offenders.push(`${at}  ${absent} is not part of Expo Go — a static import stops the app there; require() it lazily behind isExpoGo (packages/core/lib/storage.ts shows how)`)
      return
    }
    const deep = new RegExp(`(from|require\\(|import\\()\\s*['"]${PKG}/build/([^'"]+)['"]`).exec(line)
    if (!deep) return
    const lazy = deep[1] !== 'from'
    const hit = reachesSideEffect(deep[2])
    if (!existsSync(path.join(BUILD, deep[2] + '.js')) && !existsSync(path.join(BUILD, deep[2] + '.android.js'))) offenders.push(`${at}  ${PKG}/build/${deep[2]} does not exist in the installed package`)
    else if (hit && !(lazy && rel === LAZY_OK)) offenders.push(`${at}  ${PKG}/build/${deep[2]} pulls in ${hit}, which throws at import inside Expo Go`)
    else if (rel !== LAZY_OK) offenders.push(`${at}  only ${LAZY_OK} may touch ${PKG}; screens use @gs/core/notify`)
  })
}

if (offenders.length > 0) {
  console.log(`GATE expo-go-safety · FAIL — ${offenders.length} import(s) that stop the app inside Expo Go:`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE expo-go-safety · ${scanned} files: nothing imports a module that throws at import inside Expo Go`)

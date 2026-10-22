/**
 * What permissions will each app's MERGED Android manifest hold?
 *
 * "Check the merged manifest, not just yours — libraries add permissions you did not ask for."
 * A Gradle build is what really merges them, and this machine has no Android SDK. But the merge is
 * mechanical, so it is modelled exactly:
 *
 *     merged  =  ( app.config.ts `android.permissions`
 *                  ∪ every <uses-permission> in the AndroidManifest.xml of every native library
 *                    the app depends on — found by walking the real node_modules, transitively )
 *                −  app.config.ts `android.blockedPermissions`        (Expo writes tools:node="remove")
 *
 * It also lists every foreground-service type a library declares, and each library a permission
 * came from — so PERMISSIONS.md can say WHY it is there, and gate:store can refuse one nobody explained.
 *
 *   node tools/store/manifest-audit.mjs            prints both apps and writes each app's snapshot: store/manifests/
 *                                                  for the Resident App, distribution/guard/ for the Guard App, which
 *                                                  goes to no store (M25; snapshot-path.mjs)
 *   node tools/store/manifest-audit.mjs --json     machine-readable, for the gate
 *
 * When a real build exists, compare: `aapt dump permissions <apk>` must equal the `merged` list here.
 */

import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { permissionSnapshot } from './snapshot-path.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const APPS = ['guard', 'resident']

/** app.config.ts, resolved by Expo itself — never parsed by hand. */
function expoConfig(app) {
  // The CLI is run with node directly — no shell, no npx.
  // `introspect` EVALUATES every config plugin (the way prebuild would) without writing a native project: what comes back
  // is the app-level AndroidManifest and Info.plist as the plugins leave them — including what a PLUGIN adds that no
  // library manifest mentions (expo-brightness's WRITE_SETTINGS, expo-location's default "Always" strings…).
  const cli = path.join(resolvePkg('expo', path.join(ROOT, 'apps', app)), 'bin', 'cli')
  const out = execFileSync(process.execPath, [cli, 'config', '--type', 'introspect', '--json'], { cwd: path.join(ROOT, 'apps', app), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: { ...process.env, EXPO_NO_TELEMETRY: '1' } })
  return JSON.parse(out.slice(out.indexOf('{')))
}

const resolvePkg = (name, from) => {
  for (let dir = from; ; dir = path.dirname(dir)) {
    const candidate = path.join(dir, 'node_modules', name, 'package.json')
    if (existsSync(candidate)) return path.dirname(candidate)
    if (path.dirname(dir) === dir) return null
  }
}

/** Every AndroidManifest.xml under a package's android/ folder (main source set only — never debug or test). */
function manifestsOf(pkgDir) {
  const found = []
  const walk = (dir, depth) => {
    if (depth > 6 || !existsSync(dir)) return
    for (const name of readdirSync(dir)) {
      if (['node_modules', 'build', 'debug', 'androidTest', 'test', 'release'].includes(name)) continue
      const full = path.join(dir, name)
      if (statSync(full).isDirectory()) walk(full, depth + 1)
      else if (name === 'AndroidManifest.xml') found.push(full)
    }
  }
  walk(path.join(pkgDir, 'android'), 0)
  return found
}

/** A library's own package, for a manifest entry written relative to it (".service.Foo"): its Gradle namespace, else the manifest's package. */
function namespaceOf(pkgDir, xml) {
  for (const file of ['build.gradle', 'build.gradle.kts']) {
    const gradle = path.join(pkgDir, 'android', file)
    if (!existsSync(gradle)) continue
    const m = /\bnamespace\s*=?\s*["']([\w.]+)["']/.exec(readFileSync(gradle, 'utf8'))
    if (m) return m[1]
  }
  return /<manifest\b[^>]*\bpackage="([\w.]+)"/.exec(xml)?.[1] ?? null
}

const fullName = (name, ns) => (name && name.startsWith('.') && ns ? ns + name : name)

function audit(app) {
  const config = expoConfig(app)
  const evaluated = config._internal?.modResults ?? {}
  const appManifest = evaluated.android?.manifest?.manifest ?? {}
  const entries = (appManifest['uses-permission'] ?? []).map((e) => ({ name: e.$?.['android:name'], remove: e.$?.['tools:node'] === 'remove' }))
  const declared = entries.filter((e) => e.name && !e.remove).map((e) => e.name)
  const blocked = new Set(entries.filter((e) => e.name && e.remove).map((e) => e.name))
  const plist = evaluated.ios?.infoPlist ?? {}

  const sources = new Map(declared.map((p) => [p, new Set(['app config + plugins'])]))
  const services = []
  /*
   * EVERY SERVICE, not only the foreground ones (GUARD_RING_DESIGN §16). A service decides where the phone's pushes
   * go — the call-style ring swaps expo-notifications' messaging service for its own — so a new, removed or changed
   * service changes the snapshot below, and gate:store refuses the build until somebody has looked at it.
   */
  const allServices = []
  const seen = new Set()
  const queue = [[path.join(ROOT, 'apps', app), null]]
  while (queue.length) {
    const [dir] = queue.shift()
    const pkgFile = path.join(dir, 'package.json')
    if (!existsSync(pkgFile)) continue
    const pkg = JSON.parse(readFileSync(pkgFile, 'utf8'))
    for (const dep of Object.keys({ ...pkg.dependencies, ...pkg.peerDependencies })) {
      if (seen.has(dep)) continue
      const depDir = resolvePkg(dep, dir)
      if (!depDir) continue
      seen.add(dep)
      queue.push([depDir, dep])
      for (const manifest of manifestsOf(depDir)) {
        const xml = readFileSync(manifest, 'utf8').replace(/<!--[\s\S]*?-->/g, '')
        for (const m of xml.matchAll(/<uses-permission(?:-sdk-\d+)?\b[^>]*android:name="([^"]+)"[^>]*>/g)) {
          if (/tools:node="remove"/.test(m[0])) continue
          if (!sources.has(m[1])) sources.set(m[1], new Set())
          sources.get(m[1]).add(dep)
        }
        const ns = namespaceOf(depDir, xml)
        for (const m of xml.matchAll(/<service\b[^>]*>/g)) {
          const tag = m[0]
          const name = fullName(/android:name="([^"]+)"/.exec(tag)?.[1], ns)
          if (!name || /tools:node="remove"/.test(tag)) continue
          const type = /android:foregroundServiceType="([^"]+)"/.exec(tag)?.[1] ?? null
          allServices.push({ name, from: dep, exported: /android:exported="([^"]+)"/.exec(tag)?.[1] ?? null, type })
          if (type) services.push({ library: dep, type, name })
        }
      }
    }
  }

  // Every tools:node="remove" service in the app manifest — expo-location's (withoutLocationService.cjs) and
  // expo-notifications' messaging service, which the call-style ring replaces with its own (modules/guard-ring).
  const appServices = appManifest.application?.[0]?.service ?? []
  const strippedServices = new Set(appServices.filter((s) => s.$?.['tools:node'] === 'remove').map((s) => s.$?.['android:name']))
  for (const s of appServices) {
    if (s.$?.['tools:node'] === 'remove' || !s.$?.['android:name']) continue
    allServices.push({ name: s.$['android:name'], from: 'app config + plugins', exported: s.$['android:exported'] ?? null, type: s.$['android:foregroundServiceType'] ?? null })
    if (s.$['android:foregroundServiceType']) services.push({ library: 'app config + plugins', type: s.$['android:foregroundServiceType'], name: s.$['android:name'] })
  }
  const removedServices = services.filter((s) => strippedServices.has(s.name))
  const keptServices = services.filter((s) => !removedServices.includes(s))
  const byName = (a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : String(a.from).localeCompare(String(b.from)))

  const all = [...sources.keys()].sort()
  return {
    app,
    package: config.android?.package,
    merged: all.filter((p) => !blocked.has(p)).map((p) => ({ permission: p, from: [...sources.get(p)].sort() })),
    removed: all.filter((p) => blocked.has(p)).map((p) => ({ permission: p, from: [...sources.get(p)].sort() })),
    blockedButNeverDeclared: [...blocked].filter((p) => !sources.has(p)).sort(),
    foregroundServices: keptServices.map(({ library, type }) => ({ library, type })),
    removedForegroundServices: removedServices.map(({ library, type }) => ({ library, type })),
    services: allServices.filter((s) => !strippedServices.has(s.name)).sort(byName),
    removedServices: allServices.filter((s) => strippedServices.has(s.name)).sort(byName),
    ios: {
      usageDescriptions: Object.fromEntries(Object.entries(plist).filter(([k]) => /UsageDescription$/.test(k))),
      usesNonExemptEncryption: plist.ITSAppUsesNonExemptEncryption ?? null,
      backgroundModes: plist.UIBackgroundModes ?? [],
      // What a SIGNED build is allowed to do, beyond what the purpose strings ask the person for. The
      // entitlement that matters here is Time Sensitive notifications: without it a Focus mode holds a
      // panic back and NOTHING reports that — see PERMISSIONS.md, "iOS entitlements".
      entitlements: evaluated.ios?.entitlements ?? {},
    },
  }
}

const results = APPS.map(audit)
if (process.argv.includes('--json')) {
  console.log(JSON.stringify(results))
} else {
  for (const r of results) {
    const file = permissionSnapshot(ROOT, r.app)
    mkdirSync(path.dirname(file), { recursive: true })
    writeFileSync(file, JSON.stringify(r, null, 2) + '\n')
    console.log(`\n${r.app} (${r.package}) — ${r.merged.length} permission(s) in the merged manifest, ${r.removed.length} removed, ${r.foregroundServices.length} foreground service type(s)`)
    for (const p of r.merged) console.log(`  ${p.permission.padEnd(58)} ← ${p.from.join(', ')}`)
    for (const p of r.removed) console.log(`  (removed) ${p.permission.padEnd(48)} ← ${p.from.join(', ')}`)
    for (const s of r.foregroundServices) console.log(`  FOREGROUND SERVICE ${s.type} ← ${s.library}`)
    for (const s of r.removedForegroundServices) console.log(`  (removed) foreground service ${s.type} ← ${s.library}`)
  }
}

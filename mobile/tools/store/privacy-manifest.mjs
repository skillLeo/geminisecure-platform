/**
 * Apple privacy manifests: which "required-reason" APIs do the native libraries in each app use,
 * and does each app's own PrivacyInfo.xcprivacy (app.config.ts → ios.privacyManifests) declare them all?
 *
 * Every SDK must ship its own PrivacyInfo.xcprivacy. For statically-linked pods Apple's tooling does not
 * reliably merge them into the app's, so the app declares the UNION itself. This tool:
 *
 *   1  walks the real node_modules of each app's native dependencies (transitively) and reads every
 *      PrivacyInfo.xcprivacy it finds — the accessed-API categories and their reason codes;
 *   2  lists every native dependency with an ios/ folder that ships NO privacy manifest (each one has to be
 *      looked at: does it touch a required-reason API at all?);
 *   3  compares the union with what the app declares, and checks NSPrivacyTracking is false with no tracking
 *      domains — neither app tracks anybody.
 *
 *   node tools/store/privacy-manifest.mjs            report; exit 1 if an app's declaration is missing a reason
 *   node tools/store/privacy-manifest.mjs --json
 */

import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')

const resolvePkg = (name, from) => {
  for (let dir = from; ; dir = path.dirname(dir)) {
    const candidate = path.join(dir, 'node_modules', name, 'package.json')
    if (existsSync(candidate)) return path.dirname(candidate)
    if (path.dirname(dir) === dir) return null
  }
}

function find(dir, name, depth = 0, out = []) {
  if (depth > 7 || !existsSync(dir)) return out
  for (const entry of readdirSync(dir)) {
    if (entry === 'node_modules' || entry === 'android' || entry === '.git') continue
    const full = path.join(dir, entry)
    const s = statSync(full)
    if (s.isDirectory()) find(full, name, depth + 1, out)
    else if (entry === name) out.push(full)
  }
  return out
}

/** { category: Set(reasons) } from one PrivacyInfo.xcprivacy (a plist — read with a regex, its shape is fixed). */
function reasonsIn(file) {
  const xml = readFileSync(file, 'utf8')
  const out = {}
  for (const block of xml.matchAll(/<dict>\s*(?:(?!<\/dict>)[\s\S])*?<\/dict>/g)) {
    const type = /<key>NSPrivacyAccessedAPIType<\/key>\s*<string>([^<]+)<\/string>/.exec(block[0])?.[1]
    if (!type) continue
    const reasons = /<key>NSPrivacyAccessedAPITypeReasons<\/key>\s*<array>([\s\S]*?)<\/array>/.exec(block[0])?.[1] ?? ''
    out[type] ??= new Set()
    for (const r of reasons.matchAll(/<string>([^<]+)<\/string>/g)) out[type].add(r[1])
  }
  return out
}

function appConfig(app) {
  const cli = path.join(resolvePkg('expo', path.join(ROOT, 'apps', app)), 'bin', 'cli')
  const out = execFileSync(process.execPath, [cli, 'config', '--json'], { cwd: path.join(ROOT, 'apps', app), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: { ...process.env, EXPO_NO_TELEMETRY: '1' } })
  return JSON.parse(out.slice(out.indexOf('{')))
}

/**
 * Every native dependency of an app, transitively, split by whether it ships a manifest.
 *
 * Split out of `audit` and EXPORTED so `privacy-declarations.mjs` can hold its one-verdict-per-library
 * table to the real node_modules: a native dependency added later with no manifest and no verdict
 * fails `gate:store` instead of quietly joining a count nobody reads. It does not run the Expo CLI,
 * so importing it costs nothing.
 *
 * @returns {{ without: string[], withManifest: string[], union: Record<string, Set<string>> }}
 */
export function nativeDependencies(app) {
  const union = {}
  const without = []
  const withManifest = []
  const seen = new Set()
  const queue = [path.join(ROOT, 'apps', app)]
  while (queue.length) {
    const dir = queue.shift()
    const pkgFile = path.join(dir, 'package.json')
    if (!existsSync(pkgFile)) continue
    const pkg = JSON.parse(readFileSync(pkgFile, 'utf8'))
    for (const dep of Object.keys({ ...pkg.dependencies, ...pkg.peerDependencies })) {
      if (seen.has(dep)) continue
      const depDir = resolvePkg(dep, dir)
      if (!depDir) continue
      seen.add(dep)
      queue.push(depDir)
      const hasIos = existsSync(path.join(depDir, 'ios')) || readdirSync(depDir).some((f) => f.endsWith('.podspec'))
      if (!hasIos) continue
      const manifests = find(depDir, 'PrivacyInfo.xcprivacy')
      if (manifests.length === 0) without.push(dep)
      else withManifest.push(dep)
      for (const file of manifests) for (const [type, reasons] of Object.entries(reasonsIn(file))) { union[type] ??= new Set(); for (const r of reasons) union[type].add(r) }
    }
  }

  return { without: without.sort(), withManifest: withManifest.sort(), union }
}

function audit(app) {
  const { without, union } = nativeDependencies(app)

  const declared = appConfig(app).ios?.privacyManifests ?? null
  const declaredTypes = Object.fromEntries((declared?.NSPrivacyAccessedAPITypes ?? []).map((t) => [t.NSPrivacyAccessedAPIType, new Set(t.NSPrivacyAccessedAPITypeReasons ?? [])]))
  const missing = []
  for (const [type, reasons] of Object.entries(union)) for (const r of reasons) if (!declaredTypes[type]?.has(r)) missing.push(`${type} · ${r}`)

  const problems = []
  if (!declared) problems.push('app.config.ts has no ios.privacyManifests')
  if (declared && declared.NSPrivacyTracking !== false) problems.push('NSPrivacyTracking must be false')
  if (declared && (declared.NSPrivacyTrackingDomains ?? []).length > 0) problems.push('NSPrivacyTrackingDomains must be empty')
  if (declared && !Array.isArray(declared.NSPrivacyCollectedDataTypes)) problems.push('NSPrivacyCollectedDataTypes is missing')
  for (const m of missing) problems.push(`required-reason API used by a library and not declared: ${m}`)

  return { app, union: Object.fromEntries(Object.entries(union).map(([k, v]) => [k, [...v].sort()])), librariesWithoutManifest: without.sort(), declared, problems }
}

/*
 * Run as a command, this reports and exits. IMPORTED — which `privacy-declarations.mjs` now does, to
 * hold its verdict table to the real node_modules — it must do neither, so the report is behind a
 * check that this file is the one node was asked to run.
 */
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const results = ['guard', 'resident'].map(audit)
  if (process.argv.includes('--json')) console.log(JSON.stringify(results))
  else {
    for (const r of results) {
      console.log(`\n${r.app}`)
      for (const [type, reasons] of Object.entries(r.union)) console.log(`  ${type.replace('NSPrivacyAccessedAPICategory', '').padEnd(22)} ${reasons.join(', ')}`)
      console.log(`  native libraries with an iOS half and NO privacy manifest (${r.librariesWithoutManifest.length}): ${r.librariesWithoutManifest.join(', ') || '—'}`)
      console.log(`  every one of those ${r.librariesWithoutManifest.length} has a one-line verdict in PRIVACY_DECLARATIONS.md`)
      for (const p of r.problems) console.log(`  PROBLEM  ${p}`)
    }
  }
  process.exit(results.some((r) => r.problems.length > 0) ? 1 : 0)
}

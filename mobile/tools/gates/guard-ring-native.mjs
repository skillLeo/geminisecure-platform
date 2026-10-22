/**
 * GATE — the call-style ring's native half still fits the libraries it stands on (GUARD_RING_DESIGN §16).
 *
 *   1  expo-notifications' ExpoFirebaseMessagingService is still an `open class` with an overridable onMessageReceived
 *   2  expo-notifications' manifest still declares it with MESSAGING_EVENT at priority -1
 *   3  the INTROSPECTED Guard App manifest removes it and declares GuardRingMessagingService in its slot, not
 *      exported; RingActivity over the lock screen; RingActionReceiver; both ring permissions — and the Resident
 *      App's manifest has none of it and keeps expo-notifications' service
 *   4  spec/decisions.json is well formed, and no message the server builds carries a title, message or body in its
 *      data (the received fixture is checked for a leaked token or ticket instead)
 *   5  the module's own library manifest declares nothing
 *
 * An Expo upgrade that broke the service swap would silence every Guard App push without an error; this makes it red.
 * The rules are in guard-ring-rules.mjs, and tools/gates/selftest.mjs proves each one.
 *
 *   node tools/gates/guard-ring-native.mjs
 */

import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { appManifestProblems, contractProblems, libraryManifestProblems, upstreamProblems } from './guard-ring-rules.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const NM = path.join(ROOT, 'node_modules', 'expo-notifications', 'android', 'src', 'main')
const MODULE = path.join(ROOT, 'apps', 'guard', 'modules', 'guard-ring')
const problems = []

const read = (file) => (existsSync(file) ? readFileSync(file, 'utf8') : '')

problems.push(...upstreamProblems({
  serviceKt: read(path.join(NM, 'java', 'expo', 'modules', 'notifications', 'service', 'ExpoFirebaseMessagingService.kt')),
  libraryManifest: read(path.join(NM, 'AndroidManifest.xml')),
}))

function introspect(app) {
  const dir = path.join(ROOT, 'apps', app)
  const cli = [path.join(dir, 'node_modules', 'expo', 'bin', 'cli'), path.join(ROOT, 'node_modules', 'expo', 'bin', 'cli')].find(existsSync)
  const out = execFileSync(process.execPath, [cli, 'config', '--type', 'introspect', '--json'], { cwd: dir, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: { ...process.env, EXPO_NO_TELEMETRY: '1' } })
  return JSON.parse(out.slice(out.indexOf('{')))._internal?.modResults?.android?.manifest?.manifest ?? {}
}

for (const app of ['guard', 'resident']) {
  try {
    problems.push(...appManifestProblems(introspect(app), { app }))
  } catch (e) {
    problems.push(`${app}: the app manifest could not be introspected: ${String(e.message).split('\n')[0]}`)
  }
}

problems.push(...libraryManifestProblems(read(path.join(MODULE, 'android', 'src', 'main', 'AndroidManifest.xml'))))

let table = null
try {
  table = JSON.parse(read(path.join(MODULE, 'spec', 'decisions.json')))
} catch (e) {
  problems.push(`spec/decisions.json is not JSON: ${e.message}`)
}
const contractDir = path.join(MODULE, 'spec', 'contract')
const fixtures = existsSync(contractDir) ? readdirSync(contractDir).filter((f) => f.endsWith('.json')).map((name) => ({ name, json: JSON.parse(read(path.join(contractDir, name))) })) : []
if (fixtures.length === 0) problems.push('spec/contract/ has no fixtures')
problems.push(...contractProblems({ table, fixtures }))

if (problems.length > 0) {
  console.log(`GATE guard-ring-native · FAIL — ${problems.length} problem(s) with the call-style ring's native half:`)
  for (const p of problems) console.log(`  ${p}`)
  process.exit(1)
}
console.log(`GATE guard-ring-native · the ring still takes expo-notifications' own messaging slot (open class, priority -1), the Guard App manifest declares its service, screen and receiver where the gates see them, the Resident App has none of it, and ${fixtures.length} contract fixture(s) carry no presentational words`)

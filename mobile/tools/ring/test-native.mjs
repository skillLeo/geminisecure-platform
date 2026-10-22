/**
 * The call-style ring's NATIVE tests, and a check of the manifest Gradle really merges (GUARD_RING_DESIGN §15.3, G21).
 *
 *   npm run test:ring-native
 *
 * In apps/guard/android (the project `expo prebuild` generates; it is git-ignored and regenerated for every build):
 *
 *   1  gradlew :guard-ring:testReleaseUnitTest — compiles the module's Kotlin and runs its JUnit tests, which are
 *      driven by apps/guard/modules/guard-ring/spec/decisions.json (the same table jest checks);
 *   2  gradlew :app:processReleaseManifest — and then reads the MERGED release manifest: exactly one messaging
 *      service of ours at priority -1, Firebase's own at -500, no ExpoFirebaseMessagingService left, the ring screen
 *      shown over the lock screen, both ring permissions, and no foreground service.
 *
 * It prints SKIPPED only when there is no apps/guard/android at all (run `npx expo prebuild --platform android
 * --no-install --clean` in apps/guard first). When that directory exists, ANY failure is a failure — never a skip.
 * Nothing is assembled and nothing is uploaded.
 */

import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const ANDROID = path.join(ROOT, 'apps', 'guard', 'android')

if (!existsSync(ANDROID)) {
  console.log('test:ring-native · SKIPPED (no apps/guard/android — run prebuild): npx expo prebuild --platform android --no-install --clean, in apps/guard')
  process.exit(0)
}

const env = { ...process.env }
if (!env.JAVA_HOME && process.env.LOCALAPPDATA) {
  const jdk = path.join(process.env.LOCALAPPDATA, 'Android', 'Sdk', 'jdk')
  if (existsSync(jdk)) env.JAVA_HOME = jdk
}
const gradlew = path.join(ANDROID, process.platform === 'win32' ? 'gradlew.bat' : 'gradlew')

function gradle(...tasks) {
  console.log(`== gradlew ${tasks.join(' ')}`)
  const run = spawnSync(gradlew, [...tasks, '--console=plain'], { cwd: ANDROID, env, stdio: 'inherit', shell: process.platform === 'win32' })
  if (run.status !== 0) {
    console.log(`test:ring-native · FAIL — gradlew ${tasks.join(' ')} exited ${run.status}`)
    process.exit(1)
  }
}

gradle(':guard-ring:testReleaseUnitTest')
gradle(':app:processReleaseManifest')

const find = (dir, depth = 0) => {
  if (depth > 8 || !existsSync(dir)) return []
  return readdirSync(dir).flatMap((name) => {
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? find(full, depth + 1) : name === 'AndroidManifest.xml' ? [full] : []
  })
}
const candidates = find(path.join(ANDROID, 'app', 'build', 'intermediates')).filter((f) => /merged_manifests?[\\/]release[\\/]/.test(f))
const merged = candidates.find((f) => /processReleaseManifest/.test(f)) ?? candidates[0]
if (!merged) {
  console.log('test:ring-native · FAIL — no merged release manifest was written')
  process.exit(1)
}
const xml = readFileSync(merged, 'utf8').replace(/<!--[\s\S]*?-->/g, '')
const problems = []
const services = [...xml.matchAll(/<service\b([^>]*)>([\s\S]*?)<\/service>/g)].map((m) => ({ attrs: m[1], body: m[2], name: /android:name="([^"]+)"/.exec(m[1])?.[1] }))
const messaging = services.filter((s) => /com\.google\.firebase\.MESSAGING_EVENT/.test(s.body))
const ours = messaging.filter((s) => s.name === 'jm.geminisecure.guardring.GuardRingMessagingService')
if (ours.length !== 1) problems.push(`expected exactly one GuardRingMessagingService listening for MESSAGING_EVENT, found ${ours.length}`)
else if (!/android:priority="-1"/.test(ours[0].body)) problems.push('GuardRingMessagingService is not at priority -1')
if (services.some((s) => s.name === 'expo.modules.notifications.service.ExpoFirebaseMessagingService')) problems.push('ExpoFirebaseMessagingService is still in the merged manifest — two messaging services would compete')
const firebase = messaging.filter((s) => s.name === 'com.google.firebase.messaging.FirebaseMessagingService')
if (firebase.length !== 1 || !/android:priority="-500"/.test(firebase[0].body)) problems.push("Firebase's own FirebaseMessagingService is not at priority -500 as before")
const others = messaging.filter((s) => s.name !== 'jm.geminisecure.guardring.GuardRingMessagingService' && s.name !== 'com.google.firebase.messaging.FirebaseMessagingService')
if (others.length) problems.push(`another MESSAGING_EVENT service is merged: ${others.map((s) => s.name).join(', ')}`)
const activity = /<activity\b[^>]*android:name="jm\.geminisecure\.guardring\.RingActivity"[^>]*>/.exec(xml)?.[0]
if (!activity) problems.push('RingActivity is not in the merged manifest')
else {
  if (!/android:showWhenLocked="true"/.test(activity)) problems.push('RingActivity is not shown over the lock screen')
  if (!/android:exported="false"/.test(activity)) problems.push('RingActivity is exported')
}
for (const p of ['USE_FULL_SCREEN_INTENT', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS']) if (!new RegExp(`<uses-permission\\b[^>]*android\\.permission\\.${p}"`).test(xml)) problems.push(`${p} is not in the merged manifest`)
if (/<uses-permission\b[^>]*android\.permission\.FOREGROUND_SERVICE"/.test(xml)) problems.push('FOREGROUND_SERVICE is in the merged manifest')
if (/android:foregroundServiceType=/.test(xml)) problems.push('a foreground service is declared in the merged manifest')

if (problems.length) {
  console.log(`test:ring-native · FAIL — the merged release manifest (${path.relative(ROOT, merged)}):`)
  for (const p of problems) console.log(`  ${p}`)
  process.exit(1)
}
console.log(`test:ring-native · the module's JUnit tests pass, and the merged release manifest has one messaging service of ours at -1, Firebase's at -500, no ExpoFirebaseMessagingService, the ring screen over the lock screen and both permissions (${path.relative(ROOT, merged)})`)

/**
 * Proves each gate on known inputs, so a change to a pattern cannot quietly stop it
 * biting. Writes probe files, runs the gate, removes them.
 */
import { writeFileSync, readFileSync, rmSync, mkdirSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { ASSERTED_NOT_NEEDED, BACKGROUND_NEGATION, NEGATION_WINDOW, OUR_OWN_CONFIG, REQUIRED_ENTITLEMENTS, backgroundDisclosureProblems, claimEchoProblems, disclosureProblems, trackerProblems, documentProblems, entitlementProblems, purposeStringProblems, snapshotProblems, staleAllowances, stringsIn, unusedSourceProblems } from './permission-rules.mjs'
import { annotationProblems, configProblems, discoveredConfig, gatesChainProblems, overrideProblems, runtimeGuardProblems, seedReporterProblems } from './shuffle-rules.mjs'
import { contactProblems } from './contact-rules.mjs'
import { appManifestProblems, contractProblems, libraryManifestProblems, upstreamProblems } from './guard-ring-rules.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const dir = path.join(ROOT, 'packages', 'core', 'ui', '__gateprobe__')
const gate = (name) => () => spawnSync(process.execPath, [path.join(ROOT, 'tools', 'gates', name)], { encoding: 'utf8' })
const colour = gate('no-hex-literals.mjs')
const pressable = gate('no-rn-pressable.mjs')
const nativeStyle = gate('native-style-safety.mjs')
const deadControls = gate('no-dead-controls.mjs')
const a11y = gate('a11y-labels.mjs')
const expoGo = gate('expo-go-safety.mjs')
const oneClock = gate('one-clock.mjs')
const location = gate('location-discipline.mjs')
const shuffledOrder = gate('shuffled-order.mjs')
const contactDetails = gate('contact-details.mjs')

let failed = 0
const check = (name, ok) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`); if (!ok) failed++ }
const probe = (file, source) => writeFileSync(path.join(dir, file), source)
const clear = () => { rmSync(dir, { recursive: true, force: true }); mkdirSync(dir, { recursive: true }) }

mkdirSync(dir, { recursive: true })
try {
  /* ---- colour literals ---- */
  probe('copy.tsx', `export const t = <Text>{"Payment received — receipt #4471"}</Text>\n/* a colour in a comment: #123456 */\n`)
  check('colour · a receipt number in copy and a hex in a comment are not colours', colour().status === 0)
  probe('bad.tsx', `export const s = { color: '#123456' }\n`)
  check('colour · a quoted hex value is caught', colour().status === 1)
  probe('bad.tsx', 'export const s = { backgroundColor: "rgba(0,0,0,.5)" }\n')
  check('colour · an rgba() literal is caught', colour().status === 1)

  /* ---- Pressable must come from @gs/core ---- */
  clear()
  probe('ok.tsx', `import { View } from 'react-native'\nimport { Pressable } from '@gs/core'\nexport const x = <Pressable><View /></Pressable>\n`)
  check("pressable · importing it from '@gs/core' passes", pressable().status === 0)
  probe('bad.tsx', `import { Pressable, View } from 'react-native'\nexport const x = <Pressable><View /></Pressable>\n`)
  check("pressable · importing it from 'react-native' is caught", pressable().status === 1)

  /* ---- styles a phone silently drops (the amber-box class of bug) ---- */
  clear()
  probe('ok.tsx', `import { View } from 'react-native'\nimport { Pressable } from '@gs/core'\nexport const x = (\n  <Pressable style={({ pressed }) => [{ height: 52, opacity: pressed ? 0.8 : 1 }]}>\n    <View style={{ position: 'absolute', width: '100%', overflow: 'hidden' }} />\n  </Pressable>\n)\nexport const options = { animation: 'fade', content: 1 }\n`)
  check('native-style · a style function on the core Pressable, % widths and navigator options pass', nativeStyle().status === 0)
  probe('bad.tsx', `import { TouchableOpacity } from 'react-native'\nexport const x = <TouchableOpacity style={({ pressed }) => ({ height: 52 })} />\n`)
  check('native-style · a style function on anything else is caught', nativeStyle().status === 1)
  for (const [what, line] of [
    ['cursor', `{ cursor: 'pointer' }`],
    ['position fixed', `{ position: 'fixed' }`],
    ['display grid', `{ display: 'grid' }`],
    ['overflow auto', `{ overflow: 'auto' }`],
    ['a px string', `{ height: '52px' }`],
    ['a calc() string', `{ width: 'calc(100% - 20px)' }`],
    ['fontWeight', `{ fontWeight: '700' }`],
    ['backgroundImage', `{ backgroundImage: 'linear-gradient(red, blue)' }`],
    ['transitionDuration', `{ transitionDuration: '200ms' }`],
    ['a font face that is not loaded', `{ fontFamily: 'Inter-Black' }`],
    ['an iOS-only shadow', `{ shadowOpacity: 0.2 }`],
    ['the background shorthand', `{ background: 'red' }`],
    ["a multi-value margin ('0 auto')", `{ margin: '0 auto' }`],
    ['a unitless lineHeight', `{ fontSize: 14, lineHeight: 1.4 }`],
    ['a % fontSize', `{ fontSize: '90%' }`],
    ['an iOS-only decoration colour', `{ textDecorationColor: 'red' }`],
    ['a dashed border with per-side widths', `{ borderStyle: 'dashed', borderTopWidth: 1 }`],
    ['mixBlendMode without its reason', `{ mixBlendMode: 'multiply' }`],
    ['className on a third-party component', `<LinearGradient className="p-4" />`],
  ]) {
    probe('bad.tsx', `export const s = ${line}\n`)
    check(`native-style · ${what} is caught`, nativeStyle().status === 1)
  }
  probe('bad.tsx', `export const s = { cursor: 'pointer' } // native-ok: web-only branch behind Platform.OS\n`)
  check('native-style · a line marked native-ok with its reason passes', nativeStyle().status === 0)
  probe('bad.tsx', `export const s = [type('Inter-Regular', 12.5, { lineHeight: 1.5 }), { fontFamily: 'Inter-Bold', borderStyle: 'dashed', borderWidth: 1 }]\n`)
  check('native-style · a multiplier inside type(), a loaded face and a dashed border of one width pass', nativeStyle().status === 0)

  /* ---- imports that stop the app inside Expo Go (the client's phone, 2026-09-20) ---- */
  clear()
  probe('ok.tsx', `import { notificationPermission } from '@gs/core/notify'\nexport const x = notificationPermission\n`)
  check('expo-go · asking through @gs/core/notify passes', expoGo().status === 0)
  probe('bad.tsx', `import * as Notifications from 'expo-notifications'\nexport const x = Notifications\n`)
  check('expo-go · importing the expo-notifications index is caught', expoGo().status === 1)
  probe('bad.tsx', `export const x = () => require('expo-notifications')\n`)
  check('expo-go · a lazy require of the index is caught too', expoGo().status === 1)
  probe('bad.tsx', `import { getExpoPushTokenAsync } from 'expo-notifications/build/getExpoPushTokenAsync'\nexport const x = getExpoPushTokenAsync\n`)
  check('expo-go · a deep import that reaches the push side-effect module is caught', expoGo().status === 1)
  probe('bad.tsx', `import { createMMKV } from 'react-native-mmkv'\nexport const x = createMMKV\n`)
  check('expo-go · a static import of a package Expo Go does not carry is caught', expoGo().status === 1)
  clear()
  probe('ok.tsx', `import { requireOptionalNativeModule } from 'expo'\nexport const x = requireOptionalNativeModule('GuardRing')\n`)
  check('expo-go · a native module loaded OPTIONALLY passes (the call-style ring)', expoGo().status === 0)
  probe('bad.tsx', `import { requireNativeModule } from 'expo'\nexport const x = requireNativeModule('GuardRing')\n`)
  check('expo-go · requireNativeModule, which throws when the module is not in the binary, is caught', expoGo().status === 1)
  probe('bad.tsx', `export const x = () => require('guard-ring')\n`)
  check('expo-go · importing the ring module by name is caught', expoGo().status === 1)

  /* ---- the call-style ring's native half still fits expo-notifications (gate:ring-native) ---- */
  {
    const KT = 'package expo.modules.notifications.service\nopen class ExpoFirebaseMessagingService : FirebaseMessagingService() {\n  override fun onMessageReceived(remoteMessage: RemoteMessage) = Unit\n}\n'
    const LIB = '<manifest><application><service android:name=".service.ExpoFirebaseMessagingService" android:exported="false"><intent-filter android:priority="-1"><action android:name="com.google.firebase.MESSAGING_EVENT" /></intent-filter></service></application></manifest>'
    check('ring-native · expo-notifications as shipped today passes', upstreamProblems({ serviceKt: KT, libraryManifest: LIB }).length === 0)
    check('ring-native · a messaging service that is no longer open is caught', upstreamProblems({ serviceKt: KT.replace('open class', 'class'), libraryManifest: LIB }).length === 1)
    check('ring-native · an onMessageReceived made final is caught', upstreamProblems({ serviceKt: KT.replace('override fun', 'final override fun'), libraryManifest: LIB }).length === 1)
    check('ring-native · the service moved off priority -1 is caught', upstreamProblems({ serviceKt: KT, libraryManifest: LIB.replace('"-1"', '"-10"') }).length === 1)
    const svc = (name, extra = {}) => ({ $: { 'android:name': name, ...extra } })
    const ringSvc = (name = 'jm.geminisecure.guardring.GuardRingMessagingService') => ({ $: { 'android:name': name, 'android:exported': 'false' }, 'intent-filter': [{ $: { 'android:priority': '-1' }, action: [{ $: { 'android:name': 'com.google.firebase.MESSAGING_EVENT' } }] }] })
    const good = () => ({
      'uses-permission': [svc('android.permission.USE_FULL_SCREEN_INTENT'), svc('android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS')],
      application: [{
        service: [svc('expo.modules.notifications.service.ExpoFirebaseMessagingService', { 'tools:node': 'remove' }), ringSvc()],
        activity: [svc('jm.geminisecure.guardring.RingActivity', { 'android:exported': 'false', 'android:showWhenLocked': 'true' })],
        receiver: [svc('jm.geminisecure.guardring.RingActionReceiver', { 'android:exported': 'false' })],
      }],
    })
    check('ring-native · the Guard App manifest the plugin writes passes', appManifestProblems(good()).length === 0)
    const without = (mutate) => { const m = good(); mutate(m); return appManifestProblems(m).length }
    check('ring-native · expo-notifications\' service left in place is caught', without((m) => { m.application[0].service.shift() }) >= 1)
    check('ring-native · the ring service at another priority is caught', without((m) => { m.application[0].service[1]['intent-filter'][0].$['android:priority'] = '-2' }) === 1)
    check('ring-native · an exported ring service is caught', without((m) => { m.application[0].service[1].$['android:exported'] = 'true' }) === 1)
    check('ring-native · a second messaging service is caught', without((m) => { m.application[0].service.push(ringSvc('com.other.Service')) }) === 1)
    check('ring-native · a ring screen that is not shown over the lock screen is caught', without((m) => { m.application[0].activity[0].$['android:showWhenLocked'] = 'false' }) === 1)
    check('ring-native · a missing ring permission is caught', without((m) => { m['uses-permission'].pop() }) === 1)
    check('ring-native · the ring in the Resident App is caught', appManifestProblems(good(), { app: 'resident' }).length >= 1)
    check('ring-native · a library manifest that declares a service is caught', libraryManifestProblems('<manifest><application><service android:name=".X" /></application></manifest>').length === 1)
    check('ring-native · an empty library manifest passes', libraryManifestProblems('<!-- empty -->\n<manifest />').length === 0)
    const table = { decider: [{ id: 'D1', decision: 'RING' }], launch: [{ id: 'D22', decision: 'SHOW' }], delete: [{ id: 'D24', decision: 'RECORD_DISMISSED' }], pending: [{ id: 'D26', decision: 'DROP_STALE_ANSWER' }], send: [{ id: 'D27', decision: 'DELETE_PENDING' }], restore: [{ id: 'D28', decision: 'RESTORE' }], actions: [{ id: 'A1', screen: 'SENDING' }] }
    const ring = { name: 'site_ring.json', json: { priority: 'high', data: { type: 'site_ring', v: 1, id: '1' } } }
    check('ring-native · a well-formed table and a clean ring fixture pass', contractProblems({ table, fixtures: [ring] }).length === 0)
    check('ring-native · a data-only ring carrying a title in its data is caught', contractProblems({ table, fixtures: [{ name: 'x.json', json: { priority: 'high', data: { title: 'x' } } }] }).length === 1)
    check('ring-native · a data-only ring at normal priority is caught', contractProblems({ table, fixtures: [{ name: 'x.json', json: { data: { type: 'site_ring' } } }] }).length === 1)
    check('ring-native · a table missing a section is caught', contractProblems({ table: { ...table, actions: [] }, fixtures: [ring] }).length === 1)
    check('ring-native · a received fixture that still holds a push token is caught', contractProblems({ table, fixtures: [{ name: 'fcm-site_ring.received.json', json: { to: 'ExponentPushToken[abc]', body: '{}' } }] }).length === 1)
  }

  /* ---- location is read at named moments only (the biggest Play Store claim) ---- */
  clear()
  probe('bad.tsx', `import * as Location from 'expo-location'\nexport const x = () => Location.watchPositionAsync({}, () => undefined)\n`)
  check('location · a continuous position watcher is caught', location().status === 1)
  probe('bad.tsx', `import * as Location from 'expo-location'\nexport const x = () => Location.startLocationUpdatesAsync('task', {})\n`)
  check('location · background location updates are caught', location().status === 1)
  probe('bad.tsx', `import * as Location from 'expo-location'\nexport const x = () => Location.requestBackgroundPermissionsAsync()\n`)
  check('location · asking for the BACKGROUND permission is caught', location().status === 1)
  probe('bad.tsx', `import * as TaskManager from 'expo-task-manager'\nexport const x = TaskManager\n`)
  check('location · a background task is caught', location().status === 1)
  probe('bad.tsx', `import * as Location from 'expo-location'\nexport const x = () => Location.getCurrentPositionAsync({})\n`)
  check('location · a file not on the allowed list may not read a position at all', location().status === 1)

  /* ---- the permission RULES, on made-up facts: no file is written into either app ----
   * These four are why the patrol checkpoint claim could live for months with every gate green.
   * Each is a pure function, so it can be put in the exact state that went unnoticed.
   */
  const reads = (...rel) => ({ exists: new Set(rel), readsPosition: new Set(rel) })
  const entry = (extra = {}) => ({ 'a.ts': { moment: 'the moment', ...extra } })

  /* rule 1 — a stale allowance is an offender, not a footnote */
  check('permission-rules · an allowed file that reads a position passes', staleAllowances(entry(), reads('a.ts')).length === 0)
  check('permission-rules · an allowed file that READS NOTHING is caught (the patrol case)', staleAllowances(entry(), { exists: new Set(['a.ts']), readsPosition: new Set() }).length === 1)
  check('permission-rules · …unless it is marked stateOnly', staleAllowances(entry({ stateOnly: true }), { exists: new Set(['a.ts']), readsPosition: new Set() }).length === 0)
  check('permission-rules · a stateOnly file that DOES read a position is caught', staleAllowances(entry({ stateOnly: true }), reads('a.ts')).length === 1)
  check('permission-rules · an allowed file that no longer exists is caught', staleAllowances(entry(), { exists: new Set(), readsPosition: new Set() }).length === 1)

  /* rule 2 — whatever raises the phone's dialog says why first, in words a person is shown */
  const GOOD = `export const D = 'We read your location once, when you tap. It is never collected in the background.'`
  const facts = (source, extra = {}) => ({ asks: new Set(['a.ts']), onMount: new Set(['a.ts']), sourceOf: () => source, ...extra })
  check('permission-rules · an ask whose disclosure names location and denies background passes', disclosureProblems(entry({ disclosure: 'd.ts' }), facts(GOOD)).length === 0)
  check('permission-rules · an ask that names NO disclosure is caught', disclosureProblems(entry(), facts(GOOD)).length === 1)
  check('permission-rules · an ask whose disclosure file is missing is caught', disclosureProblems(entry({ disclosure: 'gone.ts' }), facts(null)).length === 1)
  check('permission-rules · a disclosure that never denies background collection is caught', disclosureProblems(entry({ disclosure: 'd.ts' }), facts(`export const D = 'We read your location when you tap.'`)).length === 1)
  check('permission-rules · a promise written only in a COMMENT is not a disclosure', disclosureProblems(entry({ disclosure: 'd.ts' }), facts(`// location is never collected in the background\nexport const D = 'Tap to attach.'`)).length === 1)
  check('permission-rules · a file that never asks needs no disclosure', disclosureProblems(entry(), facts(GOOD, { asks: new Set() })).length === 0)
  for (const phrasing of ['It is never collected in the background.', 'The app never follows you.', 'Only while you use the app · sent with an alert, never otherwise', 'never tracked in the background.'])
    check(`permission-rules · "${phrasing.slice(0, 34)}…" counts as denying background collection`, BACKGROUND_NEGATION.test(phrasing))

  /* rule 2b — the one background reader's disclosure says it IS in the background, who sees it, and when it stops */
  const BG = `export const P = { lead: 'We share your location with the control room while you are on duty.', body: 'It carries on in the background and while the app is closed. Sharing starts when you clock in and stops when you clock out.', refuse: 'No — do not share my location' }`
  const bgFacts = (source, extra = {}) => ({ asks: new Set(['t.ts']), onMount: new Set(), sourceOf: () => source, ...extra })
  const bgEntry = { 't.ts': { moment: 'on duty', disclosure: 'p.ts', background: true } }
  check('permission-rules · a background disclosure naming location, background, who, when it stops and a no passes', backgroundDisclosureProblems(bgEntry, bgFacts(BG)).length === 0)
  check('permission-rules · a background disclosure that never says it runs in the background is caught', backgroundDisclosureProblems(bgEntry, bgFacts(BG.replace('It carries on in the background and while the app is closed. ', ''))).some((p) => /background, or while/.test(p)))
  check('permission-rules · …one that never says it stops at clock-out is caught', backgroundDisclosureProblems(bgEntry, bgFacts(BG.replace('Sharing starts when you clock in and stops when you clock out.', ''))).some((p) => /stops at clock-out/.test(p)))
  check('permission-rules · …one that never offers a no is caught', backgroundDisclosureProblems(bgEntry, bgFacts(BG.replace("refuse: 'No — do not share my location'", "refuse: 'Later'"))).some((p) => /say no/.test(p)))
  check('permission-rules · …and a background ask raised ON MOUNT is caught', backgroundDisclosureProblems(bgEntry, bgFacts(BG, { onMount: new Set(['t.ts']) })).some((p) => /ON MOUNT/.test(p)))

  /* rule 2c — the background reader stops by construction */
  const TRACKER = [
    'TaskManager.defineTask<{ locations?: X[] }>(DUTY_TASK, async ({ data, error }) => {',
    '      if (error) return',
    '      await onPositions(data?.locations ?? [])',
    '    })',
    'export async function onPositions(locations: readonly X[]): Promise<void> {',
    '  await getStorage().hydrate()',
    '  const duty = readDuty()',
    '  if (!duty) return stopUpdates()',
    '}',
    'async function stopUpdates() { await Location.stopLocationUpdatesAsync(DUTY_TASK) }',
  ].join('\n')
  const callers = (clockOut, signOut) => [
    { file: 'useClockOut.ts', source: clockOut, call: /\bendDuty\(\)/, why: 'clock-out ends the duty' },
    { file: 'useSignOut.ts', source: signOut, call: /\bstopDuty\(\)/, why: 'sign-out stops it' },
  ]
  check('permission-rules · a tracker that reads the duty first, can stop, and is ended by clock-out and sign-out passes', trackerProblems({ tracker: 't.ts', source: TRACKER, callers: callers('void endDuty()', 'await stopDuty()') }).length === 0)
  check('permission-rules · a tracker whose task does not check the duty first is caught', trackerProblems({ tracker: 't.ts', source: TRACKER.replace('  if (!duty) return stopUpdates()', '  void duty'), callers: callers('endDuty()', 'stopDuty()') }).some((p) => /duty FIRST/.test(p)))
  check('permission-rules · …one that cannot stop the service is caught', trackerProblems({ tracker: 't.ts', source: TRACKER.replace('Location.stopLocationUpdatesAsync(DUTY_TASK)', 'undefined'), callers: callers('endDuty()', 'stopDuty()') }).some((p) => /stopLocationUpdatesAsync/.test(p)))
  check('permission-rules · …and a clock-out that no longer ends the duty is caught', trackerProblems({ tracker: 't.ts', source: TRACKER, callers: callers('onDone()', 'stopDuty()') }).some((p) => /clock-out ends the duty/.test(p)))

  /* the extractor those rules stand on: an apostrophe in a comment must not eat the next string */
  check("permission-rules · an apostrophe in a comment does not swallow the copy after it", stringsIn(`/* the Guard App's own form */\nexport const D = 'never in the background'\n`).includes('never in the background'))

  /* rule 3 — no document may claim a read the gate never saw */
  const CLAIM = [{ app: 'guard', phrase: 'scan a checkpoint', says: /checkpoint/i, needs: 'patrol.ts' }]
  const SAYS = [{ app: 'guard', key: 'NSLocationWhenInUseUsageDescription', text: 'GeminiSecure Guard reads your location when you scan a checkpoint.' }]
  check('permission-rules · a declared code path that reads a position passes', documentProblems({ readsPosition: new Set(['a.ts']), dataMapPaths: [{ app: 'guard', path: 'a.ts' }] }).length === 0)
  check('permission-rules · a declared code path that reads NOTHING is caught', documentProblems({ readsPosition: new Set(), dataMapPaths: [{ app: 'guard', path: 'a.ts' }] }).length === 1)
  check('permission-rules · a purpose string whose moment still has a reader passes', documentProblems({ readsPosition: new Set(['patrol.ts']), iosStrings: SAYS, claims: CLAIM }).length === 0)
  check('permission-rules · a purpose string claiming a moment nothing reads is caught (the Info.plist case)', documentProblems({ readsPosition: new Set(), iosStrings: SAYS, claims: CLAIM }).length === 1)

  /* rule 4 — a permission request needs its iOS purpose string, even behind an optional require */
  const site = [{ file: 'dictation.ts', line: 99, module: 'expo-speech-recognition', call: 'requestPermissionsAsync' }]
  const ask = (declared, installed) => purposeStringProblems({ app: 'guard', sites: site, declared: new Set(declared), installed: new Set(installed) })
  check('permission-rules · the module installed WITH its purpose strings passes', ask(['NSMicrophoneUsageDescription', 'NSSpeechRecognitionUsageDescription'], ['expo-speech-recognition']).fails.length === 0)
  check('permission-rules · the module installed WITHOUT its purpose string fails (iOS ends the app)', ask([], ['expo-speech-recognition']).fails.length === 2)
  check('permission-rules · the module absent is ARMED, never a silent pass', ask([], []).armed.length === 2 && ask([], []).fails.length === 0)
  check('permission-rules · the module absent but the strings declared is neither', ask(['NSMicrophoneUsageDescription', 'NSSpeechRecognitionUsageDescription'], []).armed.length === 0)

  /* rule 5 — a permission nobody uses may not ship. NOT wired into any gate yet: it goes on with the
   * dependency pass (see the comment at check 1 of store.mjs). Proved here first, so the day it is
   * switched on it is already known to bite in the right places and nowhere else. */
  const merged = (permission, ...from) => [{ permission, from }]
  const lib = (m, unused, asserted = {}) => unusedSourceProblems({ app: 'guard', merged: m, unused: new Set(unused), assertedNotNeeded: asserted })
  check('permission-rules · a permission whose only source library is imported by nothing is caught', lib(merged('android.permission.DETECT_SCREEN_CAPTURE', 'expo-screen-capture'), ['expo-screen-capture']).length === 1)
  check('permission-rules · …but not if one of its sources is still imported', lib(merged('android.permission.ACCESS_NETWORK_STATE', 'expo-network', '@react-native-community/netinfo'), ['expo-network']).length === 0)
  check('permission-rules · a permission the app asked for ITSELF is never flagged here (check 1 owns it)', lib(merged('android.permission.CAMERA', OUR_OWN_CONFIG), ['expo-camera']).length === 0)
  check('permission-rules · a permission the project asserts nothing needs is caught while it still ships', lib(merged('android.permission.ACCESS_WIFI_STATE', '@react-native-community/netinfo'), [], { 'android.permission.ACCESS_WIFI_STATE': { why: 'nothing reads an SSID', proof: 'packages/core/offline/network.ts' } }).length === 1)
  check('permission-rules · …and says nothing once it is no longer merged', lib([], [], { 'android.permission.ACCESS_WIFI_STATE': { why: 'nothing reads an SSID', proof: 'packages/core/offline/network.ts' } }).length === 0)
  check('permission-rules · the assertion names the file that proves it', /network\.ts/.test(lib(merged('android.permission.ACCESS_WIFI_STATE', 'netinfo'), [], ASSERTED_NOT_NEEDED)[0] ?? ''))

  /* rule 6 — the written-down snapshot must match the config it was generated from.
   * This is the hole the real "scan a checkpoint" string sat in: struck from app.config.ts, every gate
   * green, and store/manifests/guard.permissions.json still telling a reader the old thing. */
  const LIVE = { app: 'guard', merged: [{ permission: 'CAMERA', from: ['expo-camera'] }], ios: { usageDescriptions: { NSLocationWhenInUseUsageDescription: 'reads a position when you start a shift' }, entitlements: { 'aps-environment': 'development' } } }
  const copy = () => JSON.parse(JSON.stringify(LIVE))
  const snap = (mutate) => { const s = copy(); mutate(s); return snapshotProblems({ app: 'guard', live: LIVE, snapshot: s, ignore: ['ios.entitlements.aps-environment'] }) }
  check('permission-rules · a snapshot identical to the config passes', snap(() => {}).length === 0)
  check('permission-rules · a snapshot missing altogether is caught', snapshotProblems({ app: 'guard', live: LIVE, snapshot: null }).length === 1)
  const drift = snap((s) => { s.ios.usageDescriptions.NSLocationWhenInUseUsageDescription = 'reads a position when you scan a checkpoint' })
  check('permission-rules · a stale purpose string is caught (the real case)', drift.length === 1)
  check('permission-rules · …and the failure NAMES the key that drifted', /ios\.usageDescriptions\.NSLocationWhenInUseUsageDescription/.test(drift[0] ?? ''))
  check('permission-rules · a permission that drifted out of the snapshot is caught', snap((s) => { s.merged = [] }).length > 0)
  check('permission-rules · a library that drifted in the source list is caught', snap((s) => { s.merged[0].from = ['expo-camera', 'expo-image-picker'] }).length > 0)
  check('permission-rules · a key that only the snapshot has is caught', snap((s) => { s.ios.usageDescriptions.NSContactsUsageDescription = 'we read your address book' }).length === 1)
  check('permission-rules · aps-environment differs by build profile and is ignored', snap((s) => { s.ios.entitlements['aps-environment'] = 'production' }).length === 0)
  check('permission-rules · …but another entitlement drifting is NOT ignored', snap((s) => { s.ios.entitlements['com.apple.developer.carplay-messaging'] = true }).length === 1)

  /* rule 7 — the Time Sensitive entitlement: required, and every entitlement held is written down */
  const ent = (entitlements, documented = () => true) => entitlementProblems({ app: 'guard', entitlements, required: REQUIRED_ENTITLEMENTS, documented })
  const TS = 'com.apple.developer.usernotifications.time-sensitive'
  check('permission-rules · the Time Sensitive entitlement declared and documented passes', ent({ [TS]: true }).length === 0)
  check('permission-rules · a config MISSING the Time Sensitive entitlement is caught', ent({}).length === 1)
  check('permission-rules · …and declaring it FALSE is caught too, not read as present', ent({ [TS]: false }).length === 1)
  check('permission-rules · the failure says the loss is SILENT, which is the whole point', /never surfaces|nothing logs/.test(ent({})[0] ?? ''))
  check('permission-rules · an entitlement with no row in PERMISSIONS.md is caught', ent({ [TS]: true, 'aps-environment': 'development' }, (k) => k === TS).length === 1)

  /* rule 8 — no artefact may repeat a claim nothing backs.
   * THE NEGATION CASES COME FIRST, because they are the hard part: "no position is read when a
   * checkpoint is scanned" is the correct sentence and must pass, while the same words without the
   * denial must fail. Every awkward case below is taken from the real corpus, not invented. */
  const CHECKPOINT = [{ app: 'guard', phrase: 'scan a checkpoint', says: /scan(?:s|ning)?\s+(?:a|the)?\s*checkpoint|checkpoint\s+code/i, needs: 'patrol.ts' }]
  const BACKED = [{ app: 'guard', phrase: 'report an incident', says: /report(?:s|ing)?\s+an incident/i, needs: 'incidents.ts' }]
  const echo = (text, claims = CHECKPOINT) => claimEchoProblems({ artefacts: [{ file: 'a.md', text }], claims, readsPosition: new Set(['incidents.ts']) })

  // — denials that MUST pass —
  check('rule8 · a plain denial passes', echo('No position is read when you scan a checkpoint.').length === 0)
  check('rule8 · a denial AFTER the phrase passes', echo('A checkpoint scan carries NO position.').length === 0)
  check('rule8 · a denial split across a clause passes (real: store/listing/guard.md)', echo("Scanning a checkpoint reads the tag's own code and never asks where you are.").length === 0)
  check('rule8 · a denial in markdown emphasis passes (real: PERMISSIONS.md)', echo('**Not** a checkpoint scan — no position is read there').length === 0)
  check('rule8 · the phrase inside a QUOTED example of what not to say passes (real: PERMISSIONS.md)', echo('The words "scan a checkpoint" were struck from this string because patrol reads no position.').length === 0)
  check('rule8 · a denial wrapped across two source lines passes (real: STORE_COMPLIANCE.md)', echo('**Not a checkpoint scan:** patrol\nstopped reading a position, and the purpose string said otherwise for months.').length === 0)
  check('rule8 · a denial in a table cell passes', echo('| Location | **Not** a checkpoint scan — no position is read there | Optional |').length === 0)
  check('rule8 · a sentence naming the moment with NO location word is not a claim', echo('The camera is used to scan a pass or a checkpoint and to photograph an incident.').length === 0)
  check('rule8 · a following sentence that mentions the moment without asserting location passes', echo('No position is read on patrol. The checkpoint code itself says which point was reached.').length === 0)

  // — assertions that MUST fail —
  check('rule8 · a bare assertion is caught', echo('Location is checked when you scan a checkpoint.').length === 1)
  check('rule8 · a denial in the PREVIOUS sentence does not license the next one', echo('No position is read on patrol. Location is checked when you scan a checkpoint.').length === 1)
  check('rule8 · a denial about something ELSE, far from the phrase, does not license it', echo('GeminiSecure Guard checks your location when you start a shift, scan a checkpoint, report an incident or raise an alert, and it is never collected in the background.').length === 1)
  check('rule8 · the failure quotes the sentence, so a reader can find it', /The sentence:/.test(echo('Location is checked when you scan a checkpoint.')[0] ?? ''))
  check('rule8 · a claim whose reader STILL reads is never flagged, even bare', echo('Location is checked when you report an incident.', BACKED).length === 0)

  // — the window, probed at both edges —
  check(`rule8 · a denial ${NEGATION_WINDOW - 21} characters from the phrase still denies it`, echo(`Scanning a checkpoint reads the tag's own code and never asks where you are.`).length === 0)
  check('rule8 · a denial far beyond the window does not', echo('Location is read when you scan a checkpoint to confirm the officer walked the whole round on time, and it is never used for anything else.').length === 1)

  // — two REAL bugs this rule had, found by running it on the real corpus rather than on probes —
  check('rule8 · a full stop INSIDE a quotation does not split the sentence and unbalance the quotes (real bug)', echo('- **Location** — "Guard checks your location when you start a shift. It is never collected in the background."\n  *(The words "scan a checkpoint" were struck from this string on 2026-09-20.)*').length === 0)
  check('rule8 · the moment in one clause and the location word in another is not a claim (real bug)', echo('The checkpoint code itself says which point was reached; the phone is never asked where it is to record one.').length === 0)
  check('rule8 · …but the moment and the location word in the SAME clause still is', echo('The checkpoint code is read; a position is recorded when you scan a checkpoint.').length === 1)

  // — the KNOWN LIMIT, pinned so it is visible rather than silent —
  check('rule8 · KNOWN LIMIT: a denial in a list HEADING does not reach its bullets (flags; put the denial in the bullet)', echo('The app does NOT:\n- read a position when you scan a checkpoint\n').length === 1)

  /* ---- no dead controls ---- */
  clear()
  probe('ok.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable onPress={() => go('/x')} style={({ pressed }) => ({ opacity: pressed ? 0.8 : 1 })} />\nexport const b = (props) => <Pressable {...props} />\nexport const c = <ResidentButton label="Face ID" onPress={() => undefined} disabled disabledReason="Log in once first." />\n`)
  check('dead-controls · a real handler, a prop spread and an always-disabled button WITH its reason pass', deadControls().status === 0)
  probe('bad.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable style={{ height: 48 }}><Text>{'Download PDF'}</Text></Pressable>\n`)
  check('dead-controls · a Pressable with no handler is caught', deadControls().status === 1)
  probe('bad.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable onPress={() => undefined} />\n`)
  check('dead-controls · a handler that does nothing is caught', deadControls().status === 1)
  probe('bad.tsx', `export const a = <ResidentButton label="Pay" onPress={pay} disabled={!online} />\n`)
  check('dead-controls · a button that can be disabled with no reason is caught', deadControls().status === 1)

  /* ---- accessibility: a role on every control, a label on every icon-only one ---- */
  clear()
  probe('ok.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable onPress={go} role="button"><Text>{'Send'}</Text></Pressable>\nexport const b = <Pressable onPress={go} role="button" aria-label="Back"><Icon name="chevron-left" /></Pressable>\nexport const c = <Pressable onPress={focus} accessible={false}><TextInput /></Pressable>\nexport const d = <Pressable onPress={go} role="button" aria-label="Duress alert" accessibilityHint="Opens the duress alert"><Icon name="shield" /></Pressable>\n`)
  check('a11y · text inside, an aria-label on an icon, a focus helper and a hint all pass', a11y().status === 0)
  probe('bad.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable onPress={go} role="button"><Icon name="chevron-left" /></Pressable>\n`)
  check('a11y · an icon-only control with no label is caught', a11y().status === 1)
  probe('bad.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable onPress={go}><Text>{'Send'}</Text></Pressable>\n`)
  check('a11y · a control with no role is caught', a11y().status === 1)
  probe('bad.tsx', `import { Pressable } from '@gs/core'\nexport const a = <Pressable onPress={go} accessibilityRole="button" accessibilityLabel="Back"><Icon name="chevron-left" /></Pressable>\n`)
  check('a11y · the old accessibilityRole / accessibilityLabel spellings are caught', a11y().status === 1)

  /* ---- one clock: every time is rendered in the SITE's zone ---- */
  clear()
  probe('ok.ts', `import { time12, atSiteZone, siteParts, siteDayIso } from '@gs/core/lib'\nexport const a = time12(shift.rostered_start)\nexport const b = atSiteZone('2026-09-21', 840)\nexport const c = new Intl.DateTimeFormat('en-CA', { timeZone: zone }).format(at)\nexport const d = Date.now() - new Date(started).getTime() // phone-clock: a countdown, elapsed time has no zone\nexport const e = new Date(wall).getUTCDay()\nexport const f = m.setDay(day.iso)\nexport const g = siteDayIso(iso)\nexport const h = new Date().toISOString()\nexport const i = new Date(Date.parse(\`\${ymd}T12:00:00Z\`) + days * DAY_MS).toISOString().slice(0, 10)\n`)
  check('one-clock · the app’s own helpers, an explicit timeZone and a marked phone-clock line pass', oneClock().status === 0)
  for (const [what, line] of [
    ['toLocaleTimeString with no zone', `export const a = at.toLocaleTimeString('en-US')`],
    ['toLocaleDateString with no zone', `export const a = at.toLocaleDateString()`],
    ['an Intl.DateTimeFormat with no zone', `export const a = new Intl.DateTimeFormat('en-US', { hour: '2-digit' }).format(at)`],
    ['an Intl.DateTimeFormat called WITHOUT new', `export const a = Intl.DateTimeFormat('en-US', { hour: '2-digit' }).format(at)`],
    ["asking the handset which zone it is in", `export const a = Intl.DateTimeFormat().resolvedOptions().timeZone`],
    ["a local-time getter on a Date", `export const a = new Date(shift.rostered_start).getHours()`],
    ["a local-time setter on a Date", `export const a = at.setMonth(at.getMonth ? 1 : 1)`],
    ['a fixed -05:00 inside a time', `export const a = \`\${day}T12:00:00-05:00\``],
    ['UTC-5 written out', `export const a = 'UTC-5'`],
    ['five hours multiplied out', `export const a = Date.now() - 5 * 3_600_000`],
    ["the handset's offset", `export const a = -at.getTimezoneOffset()`],
    ['a hard-coded zone name', `export const a = 'America/Jamaica'`],
    ['a local-time Date constructor', `export const a = new Date(2026, 8, 21, 7, 0)`],
    ['toDateString, which is the phone’s zone under another name', `export const a = at.toDateString()`],
    ['toTimeString, the same', `export const a = at.toTimeString()`],
    // The day a UTC readout names is not the day the estate is on: 9 PM in Kingston is already tomorrow there.
    ['an instant re-serialised on the UTC clock', `export const a = dayOfYear(new Date(iso).toISOString())`],
    ['the same through a field', `export const a = new Date(notice.published_at).toISOString().slice(0, 10)`],
  ]) {
    probe('bad.ts', `${line}\n`)
    check(`one-clock · ${what} is caught`, oneClock().status === 1)
  }

  /* ---- shuffled test ordering, on made-up facts: nothing is written into the Laravel project ----
   * The PHP suite shuffles its test order on purpose — tests that pass alone and fail together were
   * hiding real defects — and every probe below is one of the ways that has been, or could be,
   * switched off again. They are pure functions precisely because the alternative, writing a probe
   * configuration into the server project, would break the suite for everyone running against the
   * one shared test database. See tools/gates/shuffled-order.mjs.
   */
  const CONFIG_OK = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<!-- executionOrder is explained here, in a comment. A comment is not a setting. -->',
    '<phpunit bootstrap="vendor/autoload.php" executionOrder="random" resolveDependencies="true">',
    '  <extensions><bootstrap class="Tests\\Support\\RandomOrderSeed"/></extensions>',
    '</phpunit>',
  ].join('\n')
  const cfg = (xml) => configProblems({ file: 'phpunit.xml', xml })
  const swap = (from, to) => cfg(CONFIG_OK.replace(from, to))

  check('shuffled-order · the real configuration shape passes, and the word in its COMMENT is not what passed it', cfg(CONFIG_OK).length === 0)
  check('shuffled-order · executionOrder REMOVED is caught', swap(' executionOrder="random"', '').length === 1)
  check('shuffled-order · …and the failure says what to put back', /executionOrder="random"/.test(swap(' executionOrder="random"', '')[0] ?? ''))
  check('shuffled-order · executionOrder changed to default is caught', swap('executionOrder="random"', 'executionOrder="default"').length === 1)
  check('shuffled-order · executionOrder changed to duration is caught', swap('executionOrder="random"', 'executionOrder="duration"').length === 1)
  check('shuffled-order · random WEAKENED by a second mode is caught', swap('executionOrder="random"', 'executionOrder="defects,random"').length === 1)
  check('shuffled-order · …and the failure names the mode that weakened it', /defects/.test(swap('executionOrder="random"', 'executionOrder="defects,random"')[0] ?? ''))
  check('shuffled-order · a mode PHPUnit does not know (a typo) is caught, because it silently means no shuffle', swap('executionOrder="random"', 'executionOrder="randomised"').length === 2)
  check('shuffled-order · resolveDependencies REMOVED is caught', swap(' resolveDependencies="true"', '').length === 1)
  check('shuffled-order · resolveDependencies="false" is caught', swap('resolveDependencies="true"', 'resolveDependencies="false"').length === 1)
  check('shuffled-order · the seed reporter no longer registered is caught', swap('<bootstrap class="Tests\\Support\\RandomOrderSeed"/>', '').length === 1)
  check('shuffled-order · a settings-looking COMMENT with no real attributes is caught, not read as the settings', cfg('<!-- executionOrder="random" resolveDependencies="true" -->\n<phpunit bootstrap="x"><extensions><bootstrap class="Tests\\Support\\RandomOrderSeed"/></extensions></phpunit>').length === 2)
  check('shuffled-order · a file with no <phpunit> element at all is caught', cfg('<project><name>not phpunit</name></project>').length === 1)
  check("shuffled-order · attributes written in single quotes are read too", cfg("<phpunit executionOrder='random' resolveDependencies='true'><extensions><bootstrap class='Tests\\Support\\RandomOrderSeed'/></extensions></phpunit>").length === 0)

  /* case 4 — phpunit.xml is not magic, it is merely FIRST */
  check('shuffled-order · phpunit.xml is the one PHPUnit discovers when several are present', discoveredConfig(['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']) === 'phpunit.xml')
  check('shuffled-order · rename it and phpunit.dist.xml silently becomes the one that runs', discoveredConfig(['phpunit.dist.xml', 'phpunit.xml.dist']) === 'phpunit.dist.xml')
  check('shuffled-order · …then phpunit.xml.dist', discoveredConfig(['phpunit.xml.dist', 'composer.json']) === 'phpunit.xml.dist')
  check('shuffled-order · a differently-named configuration is discovered by NOTHING', discoveredConfig(['phpunit.ci.xml', 'composer.json']) === null)

  /* an annotation pins an order one file at a time, where no single gate is looking */
  const ann = (source, ownsOrdering = false) => annotationProblems({ file: 'tests/Feature/AThingTest.php', source, ownsOrdering })
  check('shuffled-order · an ordinary test file passes', ann('public function test_a_gate_event_is_appended(): void\n{\n    $this->assertTrue(true);\n}\n').length === 0)
  for (const [what, line] of [
    ['a @depends annotation', '     * @depends test_the_shift_started'],
    ['a @dependsExternal annotation', '     * @dependsExternal OtherTest::test_x'],
    ["a #[Depends] attribute", "    #[Depends('test_the_shift_started')]"],
    ['a fully qualified #[\\PHPUnit\\Framework\\Attributes\\Depends]', "    #[\\PHPUnit\\Framework\\Attributes\\Depends('test_x')]"],
    ['a #[DependsOnClass] attribute', '    #[DependsOnClass(ShiftTest::class)]'],
    ['a Pest ->depends() chain', "    ->depends('the shift started')"],
    ['an #[Order] from an ordering plugin', '    #[Order(3)]'],
    ['a @phpunit-order annotation', '     * @phpunit-order 2'],
    ["a test reaching for PHPUnit's own sorter", '    $sorter->setOrder(TestSuiteSorter::ORDER_DEFAULT);'],
    ['a test setting executionOrder itself', '    $config->executionOrder = 0;'],
  ]) check(`shuffled-order · ${what} in a test file is caught`, ann(`${line}\n`).length === 1)
  check('shuffled-order · the seed reporter MAY name the sorter, because that is its whole job', ann('if ($configuration->executionOrder() !== TestSuiteSorter::ORDER_RANDOMIZED) {\n', true).length === 0)
  check('shuffled-order · …but it still may not pin an order with an annotation', ann("    #[Depends('test_x')]\n", true).length === 1)
  check('shuffled-order · a line that says why in the open passes: order-ok', ann('     * @depends nothing — quoted here to explain why it is banned. order-ok: prose, not an annotation\n').length === 0)

  /* the command line beats the file, so a wrapper script can unshuffle every run while phpunit.xml looks perfect */
  const runner = (text) => overrideProblems({ file: 'run-tests.ps1', text, configs: ['phpunit.xml'] })
  check('shuffled-order · a plain runner passes', runner('php artisan test --filter=GuardApiContract\n').length === 0)
  check('shuffled-order · a runner that names the discovered configuration passes', runner('php artisan test --configuration=phpunit.xml\n').length === 0)
  check('shuffled-order · …and one that asks for random explicitly passes', runner('php artisan test --order-by=random\n').length === 0)
  check('shuffled-order · --order-by=default in a runner is caught', runner('php artisan test --order-by=default\n').length === 1)
  check('shuffled-order · --order-by written with a space is caught too', runner('php artisan test --order-by reverse\n').length === 1)
  check('shuffled-order · --order-by=defects,random is caught, because it pins part of the order back', runner('php artisan test --order-by=defects,random\n').length === 1)
  check('shuffled-order · a PINNED --random-order-seed is caught: it shuffles once and runs that order for ever', runner('php artisan test --order-by=random --random-order-seed=1234\n').length === 1)
  check('shuffled-order · --no-configuration is caught', runner('vendor/bin/phpunit --no-configuration tests\n').length === 1)
  check('shuffled-order · a -c pointing at a configuration this gate never checked is caught', runner('vendor/bin/phpunit -c phpunit.ci.xml\n').length === 1)
  check('shuffled-order · …and so is the long spelling of it', runner('vendor/bin/phpunit --configuration=build/phpunit.fast.xml\n').length === 1)
  check('shuffled-order · a documented example that says why passes: order-ok', runner('# to reproduce one failure: --order-by=random --random-order-seed=1234   order-ok: written down for a human, not run\n').length === 0)

  /* the two files the whole arrangement rests on */
  check('shuffled-order · the seed reporter, intact, passes', seedReporterProblems('if ($c->executionOrder() !== TestSuiteSorter::ORDER_RANDOMIZED) return; $s = $c->randomOrderSeed();').length === 0)
  check('shuffled-order · the seed reporter DELETED is caught', seedReporterProblems(null).length === 1)
  check('shuffled-order · a seed reporter that stopped checking the run was randomised is caught', seedReporterProblems('$s = $c->randomOrderSeed();').length === 1)
  check('shuffled-order · a seed reporter that stopped reading the seed is caught', seedReporterProblems('if ($c->executionOrder() !== TestSuiteSorter::ORDER_RANDOMIZED) return;').length === 1)
  const guard = (source) => runtimeGuardProblems(source, 'tests/Unit/TestOrderIsRandomisedTest.php')
  check("shuffled-order · the server's own runtime guard, intact, passes", guard('assertSame(TestSuiteSorter::ORDER_RANDOMIZED, $o); assertTrue($c->resolveDependencies());').length === 0)
  check("shuffled-order · the server's own runtime guard DELETED is caught", guard(null).length === 1)
  check('shuffled-order · …and the failure says what only it can see', /--order-by typed by hand/.test(guard(null)[0] ?? ''))
  check('shuffled-order · a runtime guard hollowed out to assert nothing is caught', guard('$this->assertTrue(true);').length === 2)

  /* and the gate itself: a gate nobody runs is a comment */
  check('shuffled-order · a gates chain that runs the gate passes', gatesChainProblems('npm run gate:location && npm run gate:shuffled-order && npm run gate:store', 'gate:shuffled-order').length === 0)
  check('shuffled-order · a gates chain with the gate quietly dropped out is caught', gatesChainProblems('npm run gate:location && npm run gate:store', 'gate:shuffled-order').length === 1)

  /* ---- the company's contact details: real, and only one of them (Q-M33) ---- */
  const REAL = { company: 'Gemini Security', email: 'gemisecja@gmail.com', addressLines: ['Shop #20P', 'Portmore Town Centre', 'Portmore, Saint Catherine', 'Jamaica, West Indies'] }
  REAL.address = REAL.addressLines.join(', ')
  const OWNS = 'packages/core/lib/legal.ts'
  const carries = `email ${REAL.email} and post ${REAL.address}`
  const contactRun = (over = {}, rest = {}) => contactProblems({ contact: { ...REAL, ...over }, owner: OWNS, documents: [{ path: 'store/listing/guard.md', text: carries }], ...rest })

  check('contact · the real details, carried everywhere they must be, pass', contactRun().length === 0)
  for (const [what, email] of [
    ['example.com', 'support@example.com'],
    ['an unfilled token', '{{SUPPORT_EMAIL}}'],
    ['an angle-bracket stand-in', '<support email>'],
    ['a fill-this-in marker', 'TBD'],
    ['nothing at all', ''],
    ['not an address', 'support at geminisl dot com'],
  ]) check(`contact · a support email that is ${what} is caught`, contactRun({ email }).length > 0)
  check('contact · "your company" as the company name is caught', contactRun({ company: 'Your Company Ltd' }).length > 0)
  // M33 §3: the old support address was a real-looking mailbox on a domain with no mail server.
  check('contact · a support email on geminisl.com, which takes no mail, is caught', contactRun({ email: 'support@geminisl.com' }, { documents: [{ path: 'store/listing/resident.md', text: `email support@geminisl.com and post ${REAL.address}` }] }).length > 0)
  check('contact · a listing that offers a geminisl.com mailbox beside the real one is caught', contactProblems({ contact: REAL, owner: OWNS, documents: [{ path: 'store/listing/resident.md', text: `${carries} — or office@geminisl.com` }] }).length === 1)
  check('contact · the demonstration committee\'s sign-in domain is not a contact, and passes', contactProblems({ contact: REAL, owner: OWNS, documents: [{ path: 'store/MANIFEST.md', text: `${carries} — dahlia@phoenixpark.demo.geminisl.com` }] }).length === 0)
  check('contact · a postal address too short to post a letter to is caught', contactRun({ addressLines: ['Portmore'], address: 'Portmore' }).length > 0)

  /* a document that lost the details, or grew a SECOND support address beside them */
  check('contact · a listing that no longer carries the support email is caught', contactProblems({ contact: REAL, owner: OWNS, documents: [{ path: 'store/listing/guard.md', text: `post ${REAL.address}` }] }).length === 1)
  check('contact · a listing that no longer carries the postal address is caught', contactProblems({ contact: REAL, owner: OWNS, documents: [{ path: 'store/listing/guard.md', text: `email ${REAL.email}` }] }).length === 1)
  check('contact · a SECOND support address appearing beside the real one is caught', contactProblems({ contact: REAL, owner: OWNS, documents: [{ path: 'store/MANIFEST.md', text: `${carries} — or write to help@example.com` }] }).length === 1)
  check('contact · a reviewer or test mailbox in a document is NOT a company contact and passes', contactProblems({ contact: REAL, owner: OWNS, documents: [{ path: 'store/MANIFEST.md', text: `${carries} — reviewer@demo.geminisecure.app` }] }).length === 0)

  /* a shipped source that wrote its own copy, and a form hint that is not a contact detail */
  const src = (text) => contactProblems({ contact: REAL, owner: OWNS, sources: [{ path: 'apps/guard/src/features/more/screens/HelpScreen.tsx', text }] })
  check('contact · a screen that hard-codes a company mailbox is caught', src(`<Text>{'support@gemini-security.example'}</Text>`).length >= 1)
  check('contact · …and the failure says to import SUPPORT_CONTACT instead', /SUPPORT_CONTACT/.test(src(`<Text>{'info@gemini-security.example'}</Text>`)[0] ?? ''))
  check('contact · a screen that hard-codes the old geminisl.com mailbox is caught', src(`<Text>{'support@geminisl.com'}</Text>`).length === 1)
  check('contact · a screen that hard-codes some other geminisl.com mailbox is caught', src(`<Text>{'office@geminisl.com'}</Text>`).length === 1)
  check('contact · a screen that hard-codes even the client\'s own address is caught: it comes from SUPPORT_CONTACT', src(`<Text>{'gemisecja@gmail.com'}</Text>`).length === 1)
  check('contact · a screen that imports SUPPORT_CONTACT passes', src(`import { SUPPORT_CONTACT } from '@gs/core'`).length === 0)
  check('contact · a form hint showing the SHAPE of an address is left alone', src(`<TextInput placeholder="you@email.com" />`).length === 0)
  check('contact · the module that owns the details is allowed to hold them', contactProblems({ contact: REAL, owner: OWNS, sources: [{ path: OWNS, text: `email: 'gemisecja@gmail.com'` }] }).length === 0)

  /* the server saying something different from the apps */
  const srv = (text) => contactProblems({ contact: REAL, owner: OWNS, server: [{ path: '(server) config/legal.php', text, needs: [REAL.email, REAL.address] }] })
  check('contact · a server config with the same pair passes', srv(`'support_email' => env('LEGAL_SUPPORT_EMAIL', 'gemisecja@gmail.com'), 'address' => env('LEGAL_COMPANY_ADDRESS', '${REAL.address}')`).length === 0)
  check('contact · a server still on the old geminisl.com mailbox is caught', srv(`'support_email' => env('LEGAL_SUPPORT_EMAIL', 'support@geminisl.com'), 'address' => env('LEGAL_COMPANY_ADDRESS', '${REAL.address}')`).length === 1)
  check('contact · a server that lost the details back to a blank env is caught', srv(`'support_email' => env('LEGAL_SUPPORT_EMAIL'), 'address' => env('LEGAL_COMPANY_ADDRESS')`).length === 2)
} finally {
  rmSync(dir, { recursive: true, force: true })
}

check('colour · the real tree is clean', colour().status === 0)
check('pressable · the real tree is clean', pressable().status === 0)
check('native-style · the real tree is clean', nativeStyle().status === 0)
check('dead-controls · the real tree is clean', deadControls().status === 0)
check('a11y · the real tree is clean', a11y().status === 0)
check('one-clock · the real tree is clean', oneClock().status === 0)
check('shuffled-order · the real tree is clean', shuffledOrder().status === 0)
// A gate dropped out of `npm run gates` never runs again to complain about it, so the self-test — which IS in
// the chain — is the only place that can notice. See tools/gates/shuffled-order.mjs, check 6 of the header.
check('shuffled-order · the gate is still in the npm run gates chain', gatesChainProblems(JSON.parse(readFileSync(path.join(ROOT, 'package.json'), 'utf8')).scripts.gates, 'gate:shuffled-order').length === 0)
check('contact · the real tree is clean', contactDetails().status === 0)
check('contact · the gate is still in the npm run gates chain', gatesChainProblems(JSON.parse(readFileSync(path.join(ROOT, 'package.json'), 'utf8')).scripts.gates, 'gate:contact').length === 0)
check('ring-native · the gate is still in the npm run gates chain', gatesChainProblems(JSON.parse(readFileSync(path.join(ROOT, 'package.json'), 'utf8')).scripts.gates, 'gate:ring-native').length === 0)
check('expo-go · the real tree is clean, the ring module included', expoGo().status === 0)
process.exit(failed ? 1 : 0)

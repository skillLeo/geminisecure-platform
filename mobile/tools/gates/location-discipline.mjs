/**
 * GATE — location is read at NAMED MOMENTS ONLY, never continuously, never in the background,
 * and never after a guard's shift ends (Google Play's most scrutinised permission) — WITH ONE
 * EXCEPTION, NAMED BELOW AND HELD TIGHTER THAN EVERYTHING ELSE (M11 §3, D-174).
 *
 * THE ONE EXCEPTION: ON-DUTY SHARING IN THE GUARD APP. The client asked for the control room to see
 * its officers on a live map, also while the app is closed. That is a background location service,
 * and it is allowed in exactly ONE file, `apps/guard/src/features/tracking/dutyTracker.ts`
 * (BACKGROUND_TRACKER). What this gate proves about it:
 *   B1  no other file in either app touches a watcher, background updates, the background permission
 *       or a background task — the Resident App touches none of them at all;
 *   B2  the tracker's background task reads the DUTY first and stops the service when there is none;
 *   B3  clock-out ends the duty (useClockOut calls endDuty) and sign-out stops it (useSignOut calls
 *       stopDuty) — so "clock out and it stops" is true by construction, and the server refuses any
 *       position taken outside the shift whatever a phone does;
 *   B4  the tracker asks for the permissions only from a tap, and names a disclosure whose WORDS say
 *       it is location, that it carries on in the background / while the app is closed, who sees it,
 *       and that it stops at clock-out (rule 2b, permission-rules.mjs);
 *   B5  a claim a document makes about the named moments it covers (a checkpoint scan) is backed only
 *       while the screen for that moment actually calls it (`via`, in the ledger).
 *
 * The claim both stores are asked to believe is: "a security officer's employer verifies they are
 * physically at the assigned post during a paid shift, and nothing is collected off-shift." A
 * comment cannot prove that. What proves it is that the only way to read a position in either app
 * is a one-off call, from a file on this list, inside a function a person's tap reached.
 *
 * So:
 *   1  no watcher, no background task, no geofencing service, in either app —
 *      watchPositionAsync · startLocationUpdatesAsync · startGeofencingAsync · hasStartedLocationUpdatesAsync
 *      · expo-task-manager · TaskManager.defineTask · requestBackgroundPermissionsAsync
 *   2  every one-off read (getCurrentPositionAsync / getLastKnownPositionAsync) is in a file this
 *      gate names, with the moment it belongs to written here — so adding a new read is a decision,
 *      not an accident;
 *   3  nothing reads a position from a timer, an interval or a poller: no location call may sit in
 *      a setInterval / setTimeout body or in a polling hook;
 *   4  the Guard App's clock-OUT file reads a position (the last one of the shift) and nothing after
 *      it: no file outside this list may import expo-location at all;
 *   5  an entry on this list whose file READS NOTHING is an offender, not a footnote (rule 1 of
 *      tools/gates/permission-rules.mjs) — unless it is marked `stateOnly`, meaning it asks the phone
 *      what the permission is and never takes a fix;
 *   6  a file that ASKS for the permission names the screen that explains it first, and this gate
 *      holds that screen's words to what Google requires (rule 2);
 *   7  no document may claim a read this gate never saw — not a code path in tools/store/data-map.cjs,
 *      and not a moment named in an NSLocation… purpose string (rule 3).
 *
 * Rules 5–7 exist because of one incident. A patrol checkpoint scan used to read a position; it
 * stopped; nothing else changed. The entry stayed on this list, the data map went on naming
 * `features/patrol` as a location code path, and both apps' Info.plist went on telling Apple the app
 * reads a position when a guard scans a checkpoint. Every gate was green the whole time. A claim no
 * code supports is what a store removes an app for after approving it, so a stale allowance is now a
 * failure and the documents are joined to the same source of truth.
 *
 * With no watcher and no background permission, "location stops when the shift ends" is true by
 * construction: after the clock-out read there is no code left that could take another.
 *
 *   node tools/gates/location-discipline.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { backgroundDisclosureProblems, claimEchoProblems, disclosureProblems, documentProblems, staleAllowances, trackerProblems } from './permission-rules.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const require = createRequire(import.meta.url)
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'lifted'])

/**
 * Every file allowed to read a position, the moment it reads it at, and — where the file raises the
 * phone's own dialog — the screen that explains it BEFORE that dialog appears.
 *
 *   stateOnly    asks the phone what the permission is; never takes a fix. Reading one here is an offender.
 *   disclosure   the file whose words are shown first. Required of any file that ASKS for the permission.
 *
 * A file that reaches a position through another file on this list does NOT get an entry of its own:
 * name the file with the call in it. An entry for a file that reads nothing re-permits a read nobody
 * decided on, which is exactly how the checkpoint claim survived for months.
 */
const ALLOWED = {
  /*
   * The three Guard App files that raise the phone's own dialog all point at ONE disclosure: setup
   * step 4, "Before you start work". It is the screen every guard passes once,
   * before they ever reach clock-in, and none of these three may ask until it has been passed —
   * `onboarding.locationDisclosed()` gates each request, and until it is true they only LOOK at the
   * permission the phone already holds, so no dialog can appear behind the guard's back.
   */
  'apps/guard/src/features/shift/useGeofence.ts': {
    moment: 'the guard taps Clock in — is the officer at the post? (also the supervisor-override screen)',
    disclosure: 'apps/guard/src/features/enrolment/setupPermissions.ts',
  },
  'apps/guard/src/features/clockout/useClockOut.ts': {
    moment: 'the guard taps Clock out — the LAST read of the shift',
    disclosure: 'apps/guard/src/features/enrolment/setupPermissions.ts',
  },
  'apps/guard/src/features/incidents/useIncidentReport.ts': {
    moment: 'the guard files an incident — where did it happen?',
    disclosure: 'apps/guard/src/features/enrolment/setupPermissions.ts',
  },
  'apps/guard/src/features/duress/useDuress.ts': { moment: 'the guard raises a duress alert — where is the officer?' },
  /*
   * THE ONE BACKGROUND READER (M11 §3, D-174): from clock-in to clock-out, with the officer's consent, for the
   * control room's live map — and one fix at each named moment of the shift (a checkpoint or gate scan, a duress,
   * clock-in and clock-out). Its disclosure is the consent screen's words — kept in trackingPolicy.ts (POLICY), which
   * TrackingConsentScreen draws in full before its Agree button, the only caller of the ask — held to rule 2b.
   */
  'apps/guard/src/features/tracking/dutyTracker.ts': {
    moment: 'ON DUTY, with consent — clock-in to clock-out, in the background: the control room\'s live map, and a fix at each named moment of the shift',
    disclosure: 'apps/guard/src/features/tracking/trackingPolicy.ts',
    background: true,
  },
  'apps/resident/src/features/panic/usePanic.ts': { moment: 'the resident sends an alert — where are they?' },
  /*
   * M42 §5 #15 (the client's design; the user, 7 October): Report incident pins where the phone is by itself —
   * "Pinned from current location" — so the screen's own consent card is gone. One reading when the screen
   * opens, sent only with the report filed from it. The phone's dialog may appear there only once the resident
   * has passed the Permissions screen, the app's location disclosure (`permissionsAsked`); before that the
   * permission the phone already holds is only looked at — the Guard App's rule for its own setup step.
   */
  'apps/resident/src/features/incidents/location.ts': {
    moment: 'the resident opens Report incident — one reading pinned to that report, the dialog only after the Permissions screen, nothing kept',
    disclosure: 'apps/resident/src/features/auth/screens/PermissionsScreen.tsx',
  },
  // These two only ASK THE PHONE what the permission is; they never read a position.
  'apps/resident/src/features/auth/usePermissions.ts': {
    moment: 'the permissions screen — permission state only',
    stateOnly: true,
    disclosure: 'apps/resident/src/features/auth/screens/PermissionsScreen.tsx',
  },
  'apps/resident/src/features/settings/useSettings.ts': {
    moment: 'Settings → Privacy — permission state only',
    stateOnly: true,
    disclosure: 'apps/resident/src/features/settings/useSettings.ts',
  },
}

/**
 * THE CLAIMS LEDGER — every moment this product is allowed to tell a store it reads a position at,
 * and the file that must still be reading for that sentence to be true. Add a claim here only with
 * the reader that backs it.
 *
 * This table is the single OWNER of those claims. Rule 3 holds the two app configs
 * to it; rule 8 holds every other artefact that repeats them — the store listings, the data map, the
 * permissions document, the compliance document and the config-plugin comments. The gate prints the
 * whole ledger on success, so a green run publishes the list a reader can check a document against.
 */
const BACKGROUND_TRACKER = 'apps/guard/src/features/tracking/dutyTracker.ts'

const CLAIMS = [
  { app: 'guard', phrase: 'start a shift', says: /start(?:ing)?\s+(?:or\s+end\w*\s+)?a shift|clock[- ]?in\b|\bclock\s+on\b/i, needs: 'apps/guard/src/features/shift/useGeofence.ts' },
  { app: 'guard', phrase: 'end a shift', says: /end(?:ing)?\s+(?:a|your|the)\s+shift|clock[- ]?out\b|\bclock\s+(?:on or )?off\b/i, needs: 'apps/guard/src/features/clockout/useClockOut.ts' },
  /*
   * A checkpoint scan reads NO position of its own (the tag's code says which point was reached). An officer who
   * shares on duty has a fix taken at that moment by the tracker — so the claim is backed by the tracker, and ONLY
   * while the patrol screen still asks it to (`via`). Stop calling it and the claim is unbacked again, everywhere.
   */
  { app: 'guard', phrase: 'scan a checkpoint', says: /scan(?:s|ning)?\s+(?:a|the)?\s*checkpoint|checkpoint\s+code/i, needs: BACKGROUND_TRACKER, via: { file: 'apps/guard/src/features/patrol/usePatrol.ts', call: /\brecordMoment\(\s*'checkpoint'\s*\)/ } },
  { app: 'guard', phrase: 'share it on duty, in the background', says: /on duty|in the background|app is closed|while the app is closed/i, needs: BACKGROUND_TRACKER },
  { app: 'guard', phrase: 'report an incident', says: /report(?:s|ing)?\s+an incident/i, needs: 'apps/guard/src/features/incidents/useIncidentReport.ts' },
  { app: 'guard', phrase: 'raise an alert', says: /rais(?:e|es|ing)\s+an alert|duress/i, needs: 'apps/guard/src/features/duress/useDuress.ts' },
  { app: 'resident', phrase: 'send an alert', says: /send(?:s|ing)?\s+an alert/i, needs: 'apps/resident/src/features/panic/usePanic.ts' },
  // Report incident pins the phone's position by itself (M42 §5 #15): the documents say "report an incident", never
  // "choose to attach it to a report" (the user, 7 October).
  { app: 'resident', phrase: 'report an incident', says: /report(?:s|ing)?\s+an incident|incident reports?\b/i, needs: 'apps/resident/src/features/incidents/location.ts' },
]

const READS_A_POSITION = /\bLocation\.(getCurrentPositionAsync|getLastKnownPositionAsync)\b/
const ASKS_FOR_PERMISSION = /\brequestForegroundPermissionsAsync\b|\brequestBackgroundPermissionsAsync\b/
/** `tracker: true` — allowed in BACKGROUND_TRACKER and nowhere else (B1). The rest are allowed nowhere. */
const FORBIDDEN = [
  [/\bwatchPositionAsync\b/, 'a continuous position watcher'],
  [/\bstartLocationUpdatesAsync\b|\bhasStartedLocationUpdatesAsync\b|\bstopLocationUpdatesAsync\b/, 'background location updates', true],
  [/\bstartGeofencingAsync\b|\bhasStartedGeofencingAsync\b/, "the platform geofencing service (this app's geofence is one check at clock-in, not a watcher)"],
  [/\brequestBackgroundPermissionsAsync\b|\bgetBackgroundPermissionsAsync\b/, 'the BACKGROUND location permission', true],
  [/from\s*'expo-task-manager'|TaskManager\.defineTask/, 'a background task', true],
]

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.(ts|tsx)$/.test(name) ? [full] : []
  })

/** The `{ … }` block that starts at or after `from`, as a character span — enough to say what is inside it. */
function blockAt(source, from) {
  const open = source.indexOf('{', from)
  if (open < 0) return null
  let depth = 0
  for (let i = open; i < source.length; i++) {
    if (source[i] === '{') depth++
    else if (source[i] === '}' && --depth === 0) return [open, i]
  }
  return null
}

/**
 * Does the phone's dialog appear with NO tap before it? True when the request sits inside a
 * `useEffect`, or inside a function an effect calls — one level, which is every shape these files
 * actually use (`useEffect(() => { void check() })`, where `check` holds the request).
 */
function asksOnMount(source) {
  const asks = [...source.matchAll(/\brequestForegroundPermissionsAsync\b/g)].map((m) => m.index)
  if (asks.length === 0) return false
  const effects = [...source.matchAll(/\buseEffect\s*\(/g)].map((m) => blockAt(source, m.index)).filter(Boolean)
  const inside = (i, [a, b]) => i > a && i < b
  if (asks.some((i) => effects.some((e) => inside(i, e)))) return true
  const NOT_A_HELPER = new Set(['if', 'for', 'while', 'switch', 'return', 'void', 'catch', 'then', 'Promise', 'useEffect', 'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval', 'require'])
  for (const effect of effects) {
    for (const call of source.slice(effect[0], effect[1]).matchAll(/\b([A-Za-z_$][\w$]*)\s*\(/g)) {
      if (NOT_A_HELPER.has(call[1])) continue
      const definition = new RegExp(`\\b(?:const|let|var|function)\\s+${call[1]}\\b`).exec(source)
      if (!definition) continue
      const span = blockAt(source, definition.index)
      if (span && asks.some((i) => inside(i, span))) return true
    }
  }
  return false
}

const offenders = []
const seen = new Set()
const readsPosition = new Set()
const asks = new Set()
const onMount = new Set()
let scanned = 0

const files = [
  ...walk(path.join(ROOT, 'packages', 'core')),
  ...['guard', 'resident'].flatMap((app) => [...walk(path.join(ROOT, 'apps', app, 'src')), ...walk(path.join(ROOT, 'apps', app, 'app'))]),
]

for (const file of files) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  scanned++
  const source = readFileSync(file, 'utf8')
  const lines = source.split('\n')

  for (const [pattern, what, tracker] of FORBIDDEN) {
    // B1 — the background tracker may use these three, and only the tracker. Never a watcher, never geofencing.
    if (tracker && rel === BACKGROUND_TRACKER) continue
    lines.forEach((line, i) => {
      if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
      if (pattern.test(line.replace(/\/\/.*$/, ''))) offenders.push(`${rel}:${i + 1}  uses ${what} — ${tracker ? `only ${BACKGROUND_TRACKER} may (on duty, with consent), and the Resident App never` : 'neither app may'}`)
    })
  }

  if (!/from\s*'expo-location'/.test(source)) continue
  if (!(rel in ALLOWED)) { offenders.push(`${rel}  imports expo-location, and is not one of the files allowed to (add it to tools/gates/location-discipline.mjs with the moment it reads at, or do not read a position here)`); continue }
  seen.add(rel)
  if (READS_A_POSITION.test(source)) readsPosition.add(rel)
  if (ASKS_FOR_PERMISSION.test(source)) {
    asks.add(rel)
    if (asksOnMount(source)) onMount.add(rel)
  }

  /*
   * A read must not be SCHEDULED by a repeating timer — that is a watcher by another name. A one-shot
   * `setTimeout` RACED AGAINST a read (Promise.race, so the app gives up waiting rather than keeps asking)
   * is the opposite and is allowed: what matters is whether the timer causes the read or abandons it.
   */
  lines.forEach((line, i) => {
    if (!READS_A_POSITION.test(line)) return
    const here = lines.slice(Math.max(0, i - 12), i + 1).join('\n')
    if (/\bsetInterval\s*\(/.test(here)) offenders.push(`${rel}:${i + 1}  a position is read from a repeating timer — that is a watcher by another name`)
    else if (/\bsetTimeout\s*\([^)]*$/m.test(lines[i]) || /\bsetTimeout\s*\(\s*(async\s*)?\(\)\s*=>\s*\{[^}]*$/.test(here.split('\n').slice(-3).join('\n'))) offenders.push(`${rel}:${i + 1}  a position is read from inside a timer's callback`)
  })
}

/* 5 ── every allowance is still earned: the file exists, and it reads (or is marked state-only) ── */
offenders.push(...staleAllowances(ALLOWED, { exists: new Set(Object.keys(ALLOWED).filter((rel) => existsSync(path.join(ROOT, rel)))), readsPosition }))

/* 6 ── whatever raises the phone's dialog says why first, in words that satisfy the policy ─────── */
const sourceOf = (rel) => (existsSync(path.join(ROOT, rel)) ? readFileSync(path.join(ROOT, rel), 'utf8') : null)
// A foreground asker's words say it is NOT in the background (rule 2); the background tracker's say it IS, and when it stops (rule 2b).
const foregroundAllowed = Object.fromEntries(Object.entries(ALLOWED).filter(([, entry]) => !entry.background))
const backgroundAllowed = Object.fromEntries(Object.entries(ALLOWED).filter(([, entry]) => entry.background))
offenders.push(...disclosureProblems(foregroundAllowed, { asks, onMount, sourceOf }))
offenders.push(...backgroundDisclosureProblems(backgroundAllowed, { asks, onMount, sourceOf }))

/* B2, B3 ── "clock out and it stops", by construction ───────────────────────────────────────────── */
const trackerSource = sourceOf(BACKGROUND_TRACKER)
if (trackerSource !== null) {
  offenders.push(...trackerProblems({
    tracker: BACKGROUND_TRACKER,
    source: trackerSource,
    callers: [
      { file: 'apps/guard/src/features/clockout/useClockOut.ts', source: sourceOf('apps/guard/src/features/clockout/useClockOut.ts'), call: /\bendDuty\(\)/, why: 'clock-out ends the duty' },
      { file: 'apps/guard/src/features/more/useSignOut.ts', source: sourceOf('apps/guard/src/features/more/useSignOut.ts'), call: /\bstopDuty\(\)/, why: 'sign-out stops the service and keeps nothing' },
    ],
  }))
}

/* B5 ── a claim carried `via` a screen is backed only while that screen still calls the reader ──── */
const viaBroken = new Set(CLAIMS.filter((c) => c.via && !(sourceOf(c.via.file) ?? '').match(c.via.call)).map((c) => c.phrase))
const claimsAsTrue = CLAIMS.map((c) => (viaBroken.has(c.phrase) ? { ...c, needs: c.via.file } : c))

/* 7 ── the documents may not claim a read this gate never saw ──────────────────────────────────── */
const { RESIDENT, GUARD, RESIDENT_NOT_COLLECTED, GUARD_NOT_COLLECTED } = require('../store/data-map.cjs')
const dataMapPaths = [
  ...RESIDENT.filter((r) => r.apple === 'PreciseLocation').flatMap((r) => r.paths.map((p) => ({ app: 'resident', path: p }))),
  ...GUARD.filter((r) => r.apple === 'PreciseLocation').flatMap((r) => r.paths.map((p) => ({ app: 'guard', path: p }))),
]
const iosStrings = []
for (const app of ['guard', 'resident']) {
  const config = path.join(ROOT, 'apps', app, 'app.config.ts')
  if (!existsSync(config)) continue
  const said = new Set()
  for (const m of readFileSync(config, 'utf8').matchAll(/(NSLocation\w*UsageDescription|location(?:WhenInUse|Always\w*)Permission)\s*:\s*'((?:[^'\\]|\\.)*)'/g)) {
    if (said.has(m[2])) continue
    said.add(m[2])
    iosStrings.push({ app, key: m[1], text: m[2] })
  }
}
offenders.push(...documentProblems({ readsPosition, dataMapPaths, iosStrings, claims: claimsAsTrue }))

/* 8 ── …and neither may any OTHER artefact that repeats one ─────────────────────────────────────
 * The false checkpoint clause reached six places, and it was FIRST WRITTEN DOWN in a comment in
 * tools/expo-plugins/withoutLocationService.cjs — from there into a purpose string, a data map, two
 * documents and a store listing. Rule 3 covers the configs; this covers everywhere else the sentence
 * can be repeated, against the same ledger.
 */
const readable = (rel) => (existsSync(path.join(ROOT, rel)) ? { file: rel, text: readFileSync(path.join(ROOT, rel), 'utf8') } : null)
const pluginDir = path.join(ROOT, 'tools', 'expo-plugins')
const artefacts = [
  // The Guard App goes to no store (M25): its documents live in distribution/guard/, and are held to the same ledger.
  readable('distribution/guard/README.md'),
  readable('distribution/guard/PRIVACY_DATA.md'),
  readable('distribution/guard/PERMISSIONS.md'),
  readable('store/listing/resident.md'),
  readable('PERMISSIONS.md'),
  readable('STORE_COMPLIANCE.md'),
  ...(existsSync(pluginDir) ? readdirSync(pluginDir).filter((f) => /\.(cjs|mjs|js)$/.test(f)).map((f) => readable(`tools/expo-plugins/${f}`)) : []),
  // The data map's own prose, read from the module rather than scraped: what each row says it collects,
  // why, and every "not collected" line — which is where the denials live, so they are checked too.
  {
    file: 'tools/store/data-map.cjs',
    text: [...RESIDENT, ...GUARD].flatMap((r) => [r.what, r.why, r.kept]).concat(RESIDENT_NOT_COLLECTED, GUARD_NOT_COLLECTED).join('\n\n'),
  },
].filter(Boolean)
offenders.push(...claimEchoProblems({ artefacts, claims: claimsAsTrue, readsPosition }))

// The claim that depends on all of this.
const clockOut = path.join(ROOT, 'apps/guard/src/features/clockout/useClockOut.ts')
if (existsSync(clockOut) && !READS_A_POSITION.test(readFileSync(clockOut, 'utf8'))) offenders.push('clock-out no longer takes the final position — the shift record would not say where it ended')

if (offenders.length > 0) {
  console.log(`GATE location-discipline · FAIL — ${offenders.length}:`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(
  `GATE location-discipline · ${scanned} files: ${seen.size} touch location (${readsPosition.size} read a position, ${seen.size - readsPosition.size} permission state only), each at one named moment; ` +
    `every ask names its disclosure; ${dataMapPaths.length} declared code path(s), ${iosStrings.length} purpose string(s) and ${artefacts.length} other artefact(s) match what reads; ` +
    `no watcher and no geofencing anywhere; background location in ONE file (${BACKGROUND_TRACKER}), whose task stops with no duty, whose duty clock-out ends and sign-out stops — and none in the Resident App`,
)

/*
 * THE CLAIMS LEDGER, PRINTED. A count tells a reader nothing they can act on. This is the complete
 * list of moments this product may tell a store it reads a position at, each with the file that backs
 * it — so anybody can hold a document up against a green run and see, by eye, whether a sentence in it
 * is on the list. A moment with NO reader is printed too, and loudly: that is the shape the false
 * checkpoint claim had for months.
 */
console.log('\n  the claims ledger — every moment the stores are told about, and what backs it:')
for (const claim of claimsAsTrue) {
  const backed = readsPosition.has(claim.needs)
  console.log(`    ${backed ? ' ' : '!'} ${claim.app.padEnd(8)} "${claim.phrase}"`.padEnd(46) + (backed ? `← ${claim.needs}` : `← NOTHING READS — no artefact may say this (${claim.needs} takes no position)`))
}
console.log(`  checked against: ${artefacts.map((a) => a.file).join(', ')}\n`)

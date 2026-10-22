/**
 * GATE â€” everything that gets an app REJECTED, checked before a release build is ever made.
 *
 *   node tools/gates/store.mjs              the checks that need nothing but this repo
 *   node tools/gates/store.mjs --release    â€¦and the ones that only matter for a real submission:
 *                                           the legal pages must LOAD, and no draft text may ship
 *
 * Each check names what a store would say if it failed. Anything this cannot know yet (a real
 * device, a real account) is listed as "for a human", never silently passed.
 */

import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { refusesProductionWithoutHttps } from './api-url-rules.mjs'
import { ASSERTED_NOT_NEEDED, PERMISSION_CALL, PURPOSE_STRINGS, REQUIRED_ENTITLEMENTS, entitlementProblems, purposeStringProblems, snapshotProblems, unusedSourceProblems } from './permission-rules.mjs'
import { STORE_APPS, permissionSnapshot } from '../store/snapshot-path.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const RELEASE = process.argv.includes('--release')
/*
 * BOTH APPS are held to everything that protects a build wherever it goes — permissions, purpose strings, account
 * deletion, no development flag, no placeholder. Only STORE_APPS go to a store (M25: the client keeps the Guard App
 * private to his own officers), so only they need a listing, and nothing of the other may be in the store pack.
 */
const APPS = ['guard', 'resident']
const fails = []
const notes = []
const ok = []

/*
 * WHAT MAY RUN IN THE BACKGROUND, AND WHY (M11 §3, D-174). Nothing else may. Each entry names what PERMISSIONS.md
 * must say for it, so a mode or a service cannot be added without somebody writing down what it is for.
 */
const ALLOWED_FOREGROUND_SERVICES = [
  { app: 'guard', type: 'location', library: 'expo-location', documentedAs: 'FOREGROUND_SERVICE_LOCATION', why: "on-duty location sharing, clock-in to clock-out, with the officer's consent (D-174)" },
  // "Share live audio" during an open emergency alert, which must go on with the app in the background (M50 §4.2 #1).
  { app: 'resident', type: 'microphone', library: 'app config + plugins', documentedAs: 'FOREGROUND_SERVICE_MICROPHONE', why: 'live audio to the control room while an emergency alert is open and its switch is on (M50 §4.2 #1)' },
]
const ALLOWED_BACKGROUND_MODES = {
  guard: {
    location: "on-duty location sharing, clock-in to clock-out, with the officer's consent (D-174)",
    fetch: 'added by expo-task-manager, the runtime the on-duty location task runs in',
    processing: 'expo-background-task: what was saved at a post with no signal is sent once a connection is back, even with the app closed (M11 §1.3)',
  },
  resident: {
    fetch: 'added by expo-task-manager, the runtime the background send runs in (M11 §1.3)',
    processing: 'expo-background-task: what was saved with no signal is sent once a connection is back, even with the app closed (M11 §1.3)',
    audio: 'expo-audio: live audio to the control room goes on with the app in the background, only while an emergency alert is open and "Share live audio" is on (M50 §4.2 #1)',
  },
}

const run = (args, cwd = ROOT) => execFileSync(process.execPath, args, { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: { ...process.env, EXPO_NO_TELEMETRY: '1' } })
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'lifted', 'generated'])
const walk = (dir, exts) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full, exts) : exts.test(name) ? [full] : []
  })

/* 1 â”€â”€ every permission in the merged manifest is explained in PERMISSIONS.md â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
/*
 * PERMISSIONS.md and the other submission documents are working notes and are not published with the
 * source. Where they are present â€” on the machine a submission is prepared from â€” every check below
 * runs. Where they are absent, the checks that read them are SKIPPED rather than failed, so a fresh
 * checkout is green, and the skip is printed rather than silent so nobody mistakes it for a pass.
 */
const permissionsDoc = existsSync(path.join(ROOT, 'PERMISSIONS.md')) ? readFileSync(path.join(ROOT, 'PERMISSIONS.md'), 'utf8') : ''
const HAS_DOCS = permissionsDoc !== ''
/*
 * M25: the root PERMISSIONS.md is the store app's. An app that goes to no store keeps its own record beside its private
 * distribution (distribution/<app>/PERMISSIONS.md), so the store pack never describes it; that app is held to both files.
 */
const ownPermissionsDoc = (app) => {
  const own = path.join(ROOT, 'distribution', app, 'PERMISSIONS.md')
  return STORE_APPS.includes(app) || !existsSync(own) ? '' : readFileSync(own, 'utf8')
}
const docFor = (app) => `${permissionsDoc}\n${ownPermissionsDoc(app)}`
if (!HAS_DOCS) notes.push('PERMISSIONS.md is not in this checkout, so the permission-explanation, entitlement and store-listing checks are SKIPPED. They must pass on the machine a submission is prepared from.')
let manifests = []
try {
  manifests = JSON.parse(run([path.join(ROOT, 'tools', 'store', 'manifest-audit.mjs'), '--json']))
} catch (e) {
  fails.push(`the merged-manifest audit could not run: ${String(e.message).split('\n')[0]}`)
}
for (const m of manifests) {
  if (HAS_DOCS) {
    for (const { permission } of m.merged) {
      const short = permission.replace('android.permission.', '')
      if (!new RegExp(`\`?${short}\`?`).test(docFor(m.app))) fails.push(`${m.app}: the merged manifest holds ${permission}, which PERMISSIONS.md does not explain`)
    }
  }
  /*
   * 1b â”€â”€ A PERMISSION NOTHING USES.
   *
   * Rule 1 above proves a permission is EXPLAINED. It does not prove anything USES it, and those are
   * different questions. Before this rule existed, `DETECT_SCREEN_CAPTURE` reached the Guard App from
   * expo-screen-capture, which nothing in that app imported, and `ACCESS_WIFI_STATE` reached BOTH apps
   * from netinfo â€” imported constantly, but never for anything to do with Wi-Fi. Each had a row in
   * PERMISSIONS.md and each would have been a question at review with no good answer.
   *
   * Both halves run: a permission whose every source library is imported by nothing, and a permission
   * the project has asserted no code needs (`ASSERTED_NOT_NEEDED`) that is still merged. The second
   * half exists because the first cannot catch Wi-Fi state â€” netinfo IS used, just not for that â€” so
   * "nothing we wrote needs this" has to be written down and defended rather than inferred.
   */
  const natives = JSON.parse(run([path.join(ROOT, 'tools', 'store', 'unused-native.mjs'), '--json']))
  const unused = new Set(natives.find((r) => r.app === m.app)?.unused ?? [])
  fails.push(...unusedSourceProblems({ app: m.app, merged: m.merged, unused, assertedNotNeeded: ASSERTED_NOT_NEEDED }))
  /* 2 â”€â”€ no foreground service at all: geofencing may not be one, and neither app needs one */
  // ONE is allowed in the whole product: the Guard App's on-duty location sharing (M11 §3, D-174), declared by
  // expo-location and explained in PERMISSIONS.md. The Resident App has none, and any other that appears fails.
  for (const s of m.foregroundServices) {
    const allowed = ALLOWED_FOREGROUND_SERVICES.find((a) => a.app === m.app && a.type === s.type && a.library === s.library)
    if (!allowed) fails.push(`${m.app}: a foreground service of type "${s.type}" is declared (by ${s.library}) - Play reviews every one, and the only one this product has a use for is the Guard App's on-duty location sharing`)
    else if (HAS_DOCS && !docFor(m.app).includes(allowed.documentedAs)) fails.push(`${m.app}: the ${s.type} foreground service is declared, and PERMISSIONS.md does not explain ${allowed.documentedAs}`)
    else ok.push(`${m.app}: the ${s.type} foreground service - ${allowed.why}`)
  }
  /* 3 â”€â”€ every iOS purpose string is specific, names the app, and is not a library's default */
  const strings = Object.entries(m.ios.usageDescriptions)
  if (strings.length === 0) fails.push(`${m.app}: no iOS usage descriptions at all`)
  for (const [key, text] of strings) {
    if (/\$\(PRODUCT_NAME\)|^Allow .* to access|needs? access to/i.test(text)) fails.push(`${m.app}: ${key} is a generic default ("${text.slice(0, 60)}â€¦") â€” App Review rejects these`)
    else if (String(text).length < 40) fails.push(`${m.app}: ${key} is too short to say why ("${text}")`)
    else if (!/GeminiSecure/.test(text)) fails.push(`${m.app}: ${key} does not name the app`)
    else ok.push(`${m.app} ${key}`)
  }
  if (m.ios.usesNonExemptEncryption === null) fails.push(`${m.app}: ITSAppUsesNonExemptEncryption is not set â€” every upload will stop and ask`)
  // Each iOS background mode must be one this app has a stated use for, written down in PERMISSIONS.md.
  for (const mode of m.ios.backgroundModes ?? []) {
    const why = ALLOWED_BACKGROUND_MODES[m.app]?.[mode]
    if (!why) fails.push(`${m.app}: the iOS background mode "${mode}" is declared, and this app has no stated use for it`)
    else if (HAS_DOCS && !docFor(m.app).includes(`UIBackgroundModes: ${mode}`)) fails.push(`${m.app}: the iOS background mode "${mode}" is declared, and PERMISSIONS.md has no "UIBackgroundModes: ${mode}" row saying why`)
    else ok.push(`${m.app}: iOS background mode ${mode} - ${why}`)
  }

  /* 3a â”€â”€ the entitlements: required where needed, written down wherever held â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const entitlements = m.ios.entitlements ?? {}
  const documented = (key) => !HAS_DOCS || docFor(m.app).includes(key)
  fails.push(...entitlementProblems({ app: m.app, entitlements, required: REQUIRED_ENTITLEMENTS, documented }))
  if (Object.keys(entitlements).length > 0) ok.push(`${m.app}: ${Object.keys(entitlements).length} iOS entitlement(s), each with a row in PERMISSIONS.md`)

  /* 3b â”€â”€ the written-down snapshot still matches the config it was generated from â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const snapshotFile = permissionSnapshot(ROOT, m.app)
  const snapshotRel = path.relative(ROOT, snapshotFile).replace(/\\/g, '/')
  if (process.env.APP_VARIANT === 'development') {
    // The dev variant deliberately IS a different app (its own bundle id and name), so the snapshot â€”
    // which documents the store build â€” is expected to differ and comparing them says nothing.
    notes.push(`${m.app}: the permission snapshot (${snapshotRel}) is not compared while APP_VARIANT=development (the dev variant is a different app id by design)`)
  } else {
    const snapshot = existsSync(snapshotFile) ? JSON.parse(readFileSync(snapshotFile, 'utf8')) : null
    // aps-environment is written by expo-notifications from the BUILD PROFILE, not from our config, so
    // it legitimately differs between a local run and a production build. Everything else must match.
    const stale = snapshotProblems({ app: m.app, live: m, snapshot, ignore: ['ios.entitlements.aps-environment'], file: snapshotRel })
    fails.push(...stale)
    if (stale.length === 0) ok.push(`${m.app}: the permission snapshot (${snapshotRel}) matches app.config.ts`)
  }
}

/* 4 â”€â”€ privacy manifests, and the declarations matching the code â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
for (const tool of [['tools', 'store', 'privacy-manifest.mjs'], ['tools', 'store', 'privacy-declarations.mjs']]) {
  try {
    run([path.join(ROOT, ...tool), '--check'])
    ok.push(tool[2])
  } catch (e) {
    fails.push(`${tool[2]}: ${String(e.stdout || e.message).trim().split('\n').slice(0, 6).join(' Â· ')}`)
  }
}

/* 5 â”€â”€ the alert button is never described as an emergency service (Apple 5.1.5) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
try {
  run([path.join(ROOT, 'tools', 'store', 'panic-framing.mjs')])
  ok.push('panic-framing')
} catch (e) {
  fails.push(`panic framing: ${String(e.stdout || e.message).trim().split('\n').slice(1, 6).join(' Â· ')}`)
}

/* 6 â”€â”€ deleting the account is reachable IN the app, within two taps of the main navigation â”€â”€â”€â”€ */
const DELETE_ROUTES = { resident: 'apps/resident/app/settings/delete-account.tsx', guard: 'apps/guard/app/delete-account.tsx' }
for (const app of APPS) {
  const route = path.join(ROOT, DELETE_ROUTES[app])
  if (!existsSync(route)) { fails.push(`${app}: no in-app "Delete my account" screen (${DELETE_ROUTES[app]}) â€” both stores require one`); continue }
  // â€¦and something on a TAB screen must lead to it, so it is two taps: tab â†’ row â†’ (the screen).
  const tabs = walk(path.join(ROOT, 'apps', app, 'src', 'features'), /Screen\.tsx$/).filter((f) => /Profile|More|Settings/.test(path.basename(f)))
  const target = path.basename(DELETE_ROUTES[app], '.tsx')
  const leadsThere = (f) => new RegExp(`delete-account|${target}`).test(readFileSync(f, 'utf8'))
  // Or the design's own place for it (resident-app-33, the M42 rebuild): Settings, "Privacy & data", "Delete my account".
  // Counted only when that Privacy screen leads to it AND a Profile/More/Settings screen leads to that Privacy screen.
  const privacy = walk(path.join(ROOT, 'apps', app, 'src', 'features'), /Screen\.tsx$/).filter((f) => /Privacy/.test(path.basename(f)) && leadsThere(f))
  const viaPrivacy = privacy.length > 0 && tabs.some((f) => /settings\/privacy/.test(readFileSync(f, 'utf8')))
  const linked = tabs.some(leadsThere) || viaPrivacy
  if (!linked) fails.push(`${app}: the delete-account screen exists but no Profile/More/Settings screen links to it â€” "buried" is a rejection`)
  else ok.push(`${app} account deletion reachable`)
}

/* 7 â”€â”€ no placeholder text anywhere a person can read â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
const PLACEHOLDER = /\b(lorem ipsum|TODO|FIXME|XXX+|TBD|coming soon|placeholder text|your text here|example\.com)\b/i
for (const app of APPS) {
  for (const file of [...walk(path.join(ROOT, 'apps', app, 'src'), /\.tsx$/), ...walk(path.join(ROOT, 'apps', app, 'app'), /\.tsx$/)]) {
    readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
      if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
      const copy = [...line.matchAll(/'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\]|\\.)*)`/g)].map((m) => m[1] ?? m[2] ?? m[3] ?? '')
      for (const text of copy) if (PLACEHOLDER.test(text) && /\s/.test(text)) fails.push(`${path.relative(ROOT, file).replace(/\\/g, '/')}:${i + 1} placeholder copy: "${text.slice(0, 60)}"`)
    })
  }
}

/* 8 â”€â”€ no development flag can reach a release build â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
for (const app of APPS) {
  const config = readFileSync(path.join(ROOT, 'apps', app, 'app.config.ts'), 'utf8')
  if (!refusesProductionWithoutHttps(config)) fails.push(`${app}: app.config.ts does not refuse a production build without an https server address`)
  if (/usesCleartextTraffic:\s*true\b/.test(config)) fails.push(`${app}: cleartext HTTP is allowed unconditionally`)
  const eas = JSON.parse(readFileSync(path.join(ROOT, 'apps', app, 'eas.json'), 'utf8'))
  for (const [name, profile] of Object.entries(eas.build ?? {})) {
    const env = profile.env ?? {}
    if (name === 'production' || name === 'preview') {
      if (env.EXPO_PUBLIC_FIDELITY) fails.push(`${app}: eas.json profile "${name}" sets EXPO_PUBLIC_FIDELITY â€” the board fixtures would ship`)
      if (env.APP_VARIANT === 'development') fails.push(`${app}: eas.json profile "${name}" builds the development variant`)
      if (profile.developmentClient) fails.push(`${app}: eas.json profile "${name}" is a development client`)
    }
    if (typeof env.EXPO_PUBLIC_API_URL === 'string' && /\b(\d{1,3}\.){3}\d{1,3}\b|localhost/.test(env.EXPO_PUBLIC_API_URL)) fails.push(`${app}: eas.json profile "${name}" pins a laptop address`)
  }
  if (eas.build?.production?.android?.buildType && eas.build.production.android.buildType !== 'app-bundle') fails.push(`${app}: the production Android build is not an app bundle (Play requires one)`)
}

/* 9 â”€â”€ the privacy invariants that have tests: those tests must exist and pass â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
const INVARIANT_TESTS = [
  ['no camera frame is ever kept', ['tools', 'gates', 'camera-frames.mjs']],
  ['no household money on a guard screen', ['tools', 'gates', 'guard-no-money.mjs']],
  ['nothing imports a module that stops Expo Go', ['tools', 'gates', 'expo-go-safety.mjs']],
  ['the server address is one configuration value', ['tools', 'gates', 'one-api-url.mjs']],
]
for (const [what, tool] of INVARIANT_TESTS) {
  try { run([path.join(ROOT, ...tool)]); ok.push(what) } catch { fails.push(`a privacy/safety invariant fails: ${what} (${tool.join('/')})`) }
}

/* 10 â”€â”€ the legal pages must be reachable, and not drafts, before a real submission â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
// M24 (D-223): the Resident App's three pages. The Guard App's policy and terms are on the phone, never on the web,
// and the two pages that described the product must stay gone.
const LEGAL = ['/legal/privacy', '/legal/terms', '/account/delete']
const GONE = ['/apps', '/support']
if (RELEASE) {
  const base = process.env.GS_PUBLIC_URL ?? ''
  // The Guard App goes to no store (M25), so its own texts do not hold a store submission up; the lawyer's sign-off
  // is still owed to the officers who read them, and is said here so it is not forgotten.
  const guardLegal = readFileSync(path.join(ROOT, 'apps', 'guard', 'src', 'features', 'legal', 'guardLegal.ts'), 'utf8')
  if (/export const DRAFT = true/.test(guardLegal)) notes.push('the Guard App\'s own privacy policy and terms are still marked DRAFT (apps/guard/src/features/legal/guardLegal.ts): not a store matter, the app is private, but the lawyer\'s sign-off is still owed')
  if (!/^https:\/\//.test(base)) fails.push('GS_PUBLIC_URL is not an https address â€” set it to the live site so the legal pages can be checked')
  else {
    for (const page of GONE) {
      try {
        const res = await fetch(base.replace(/\/$/, '') + page, { redirect: 'manual' })
        if (res.status === 410 || res.status === 404) ok.push(`${page} is gone (${res.status})`)
        else fails.push(`${page} answered ${res.status}: the page that described the product must stay gone, with no redirect`)
      } catch (e) {
        fails.push(`${page} could not be checked: ${String(e.message).split('\n')[0]}`)
      }
    }
    for (const page of LEGAL) {
      try {
        const res = await fetch(base.replace(/\/$/, '') + page, { redirect: 'follow' })
        const body = await res.text()
        if (!res.ok) fails.push(`${page} answered ${res.status} â€” Google Play loads the deletion URL itself`)
        else if (/\bDraft\.\b/.test(body)) fails.push(`${page} still carries the DRAFT banner â€” the company's final text has not been pasted in`)
        else ok.push(`${page} loads`)
      } catch (e) {
        fails.push(`${page} could not be loaded: ${String(e.message).split('\n')[0]}`)
      }
    }
  }
} else {
  notes.push(`the legal pages are only checked with --release (and GS_PUBLIC_URL set): ${LEGAL.join(' ')}; gone: ${GONE.join(' ')}`)
}

/* 11 â”€â”€ the listing itself: text, icons, feature graphic and screenshots all present â”€â”€ */
const ASSETS = [
  ['listing text', (app) => [`store/listing/${app}.md`]],
  ['App Store marketing icon', (app) => [`store/icons/${app}/ios/icon-1024.png`]],
  ['Play icon and feature graphic', (app) => [`store/icons/${app}/android/play-icon-512.png`, `store/feature-graphic/${app}-1024x500.png`]],
  ['screenshots', (app) => ['ios-6.7', 'ios-6.5', 'android-phone'].map((d) => `store/screenshots/${app}/${d}`)],
]
for (const app of STORE_APPS) {
  for (const [what, files] of ASSETS) {
    for (const rel of files(app)) {
      const full = path.join(ROOT, rel)
      const there = existsSync(full) && (statSync(full).isDirectory() ? readdirSync(full).filter((f) => f.endsWith('.png')).length >= 2 : statSync(full).size > 0)
      if (!there) fails.push(`${app}: ${what} missing or empty (${rel}) â€” a listing cannot be published without it; run npm run store:assets`)
      else ok.push(`${app} ${what}`)
    }
  }
}
if (!existsSync(path.join(ROOT, 'store', 'MANIFEST.md'))) fails.push('store/MANIFEST.md is missing â€” it is the list of what the client still has to supply')

/* 11b ── the store pack carries the store app and nothing else (M25) ──────────────────────────────
 * The client keeps the Guard App private to his own officers: no listing, and nothing in the store pack that
 * describes it or hints that it exists. The pack is what a person copies into a console or hands a reviewer:
 * everything under store/, and the four submission documents. A file whose path names another app, or a line
 * that names the Guard App, fails here; so does a store submit profile for an app that goes to no store.
 */
const OTHER_APPS = APPS.filter((a) => !STORE_APPS.includes(a))
const PACK_DOCS = ['STORE_SUBMISSION.md', 'STORE_REVIEW_NOTES.md', 'STORE_COMPLIANCE.md', 'PRIVACY_DECLARATIONS.md']
const NAMES_OTHER = /\bGuard App\b|\bGeminiSecure Guard\b|jm\.geminisecure\.guard|\bguards?\b/i
// Every file, platform folders included: `walk` skips android/ and ios/, which in store/icons are icon sizes, not builds.
const walkAll = (dir) => (!existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
  const full = path.join(dir, name)
  return statSync(full).isDirectory() ? walkAll(full) : [full]
}))
const packFiles = [...walkAll(path.join(ROOT, 'store')), ...PACK_DOCS.map((f) => path.join(ROOT, f)).filter((f) => existsSync(f))]
let packClean = true
for (const file of packFiles) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  if (OTHER_APPS.some((a) => rel.split('/').some((part) => part === a || part.startsWith(`${a}-`) || part.startsWith(`${a}.`)))) {
    fails.push(`${rel}: the store pack holds a file of an app that goes to no store (M25) — move it to distribution/ or remove it`)
    packClean = false
    continue
  }
  if (!/\.(md|json|txt)$/.test(rel)) continue
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (NAMES_OTHER.test(line)) {
      fails.push(`${rel}:${i + 1} names the Guard App, which goes to no store and is mentioned nowhere in the store pack (M25): "${line.trim().slice(0, 80)}"`)
      packClean = false
    }
  })
}
for (const app of OTHER_APPS) {
  const eas = JSON.parse(readFileSync(path.join(ROOT, 'apps', app, 'eas.json'), 'utf8'))
  if (eas.submit) { fails.push(`apps/${app}/eas.json has a "submit" profile, and ${app} goes to no store (M25) — remove it, so it cannot be submitted by accident`); packClean = false }
}
if (packClean) ok.push(`the store pack is ${STORE_APPS.join(', ')} alone, and no other app has a submit profile`)

/* 12 â”€â”€ the listing may not advertise a feature that ships switched OFF (Guideline 2.3.3) â”€â”€â”€â”€â”€â”€â”€â”€â”€
 * A flag in packages/core/lib/flags.ts removes a feature's every entry point. A screenshot or a
 * sentence about it would then promise a screen the person cannot reach, which is a rejection â€” and
 * the day the flag goes on, this check is what says the listing may mention it again.
 */
const FLAGGED_COPY = [
  { flag: 'amenityBookings', app: 'resident', words: /\b(amenit(?:y|ies)|book(?:ing)? (?:the )?(?:pool|gazebo|court|hall)|amenity booking)\b/i, screens: /resident-app-(?:21|22|31|37)\b/ },
]
const flagsSrc = existsSync(path.join(ROOT, 'packages', 'core', 'lib', 'flags.ts')) ? readFileSync(path.join(ROOT, 'packages', 'core', 'lib', 'flags.ts'), 'utf8') : ''
for (const { flag: name, app, words, screens } of FLAGGED_COPY) {
  const on = new RegExp(`${name}:\\s*true`).test(flagsSrc)
  if (on) { ok.push(`${name} is on â€” the listing may show it`); continue }
  const listing = path.join(ROOT, 'store', 'listing', `${app}.md`)
  if (!existsSync(listing)) continue
  readFileSync(listing, 'utf8').split('\n').forEach((line, i) => {
    // The note explaining WHY it is left out is the one place the words are allowed â€” including the
    // sentence that says the listing does NOT advertise it (that sentence is the opposite of a claim).
    if (/flags\.ts|amenityBookings|2\.3\.3|gate:store/.test(line)) return
    if (/\b(nothing|not|never|no)\b[^.]*\badvertis|switched off|does not (show|advertise|mention)/i.test(line)) return
    if (words.test(line)) fails.push(`store/listing/${app}.md:${i + 1} advertises a feature that ships switched off (flags.${name}): "${line.trim().slice(0, 80)}"`)
  })
  const shots = path.join(ROOT, 'store', 'screenshots', 'manifest.json')
  if (existsSync(shots) && screens.test(readFileSync(shots, 'utf8'))) fails.push(`store/screenshots: a screenshot of a feature that ships switched off (flags.${name}) â€” retake without it (npm run store:screenshots)`)
  ok.push(`${app} listing does not promise ${name}`)
}

/* 13 â”€â”€ â€¦nor a feature whose OPTIONAL NATIVE MODULE is not installed â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 * Speaking a maintenance request needs expo-speech-recognition. It is not installed, so the shipped
 * screen draws that microphone disabled with the sentence saying so, and neither app asks for the
 * microphone at all. Board resident-app-11's fixture state draws it mid-dictation â€” a store picture of
 * it would promise a feature that does not exist and contradict PRIVACY_DECLARATIONS.md at the same
 * time. Install the module and this check lets the picture back in.
 */
const residentPkg = JSON.parse(readFileSync(path.join(ROOT, 'apps', 'resident', 'package.json'), 'utf8'))
const shotsFile = path.join(ROOT, 'store', 'screenshots', 'manifest.json')
if (!('expo-speech-recognition' in { ...residentPkg.dependencies, ...residentPkg.devDependencies })) {
  if (existsSync(shotsFile) && /resident-app-11\b/.test(readFileSync(shotsFile, 'utf8'))) fails.push('store/screenshots: board resident-app-11 draws a LISTENING microphone, and expo-speech-recognition is not installed â€” that picture promises a feature the build does not have (and no app asks for the microphone)')
  else ok.push('no store picture promises speech recognition')
}

/* 14 â”€â”€ a permission request needs its iOS purpose string, EVEN BEHIND AN OPTIONAL `require` â”€â”€â”€â”€â”€â”€
 * iOS does not refuse a permission request that has no purpose string; it ends the app, on the
 * device, the instant the dialog would appear. A module loaded in a try/catch hides that â€” the call
 * sits unreachable and green until somebody installs the package to finish the feature.
 * So the trigger is the PACKAGE: absent, the site is reported as ARMED on every run and never
 * silently passed; present, it is a failure, which catches it before a build rather than after a
 * crash report. (`apps/guard/src/features/incidents/dictation.ts` is the live example.)
 */
const MODULE_REF = (mod) => new RegExp(`from\\s*'${mod}'|require\\(\\s*'${mod}'\\s*\\)`)
for (const m of manifests) {
  const app = m.app
  const declared = new Set(Object.keys(m.ios.usageDescriptions))
  const pkg = JSON.parse(readFileSync(path.join(ROOT, 'apps', app, 'package.json'), 'utf8'))
  const installed = new Set(Object.keys({ ...pkg.dependencies, ...pkg.devDependencies }))
  const sites = []
  const sources = [
    ...walk(path.join(ROOT, 'apps', app, 'src'), /\.tsx?$/),
    ...walk(path.join(ROOT, 'apps', app, 'app'), /\.tsx?$/),
    ...walk(path.join(ROOT, 'packages', 'core'), /\.tsx?$/),
  ]
  for (const file of sources) {
    const source = readFileSync(file, 'utf8')
    const lines = source.split('\n')
    const at = lines.findIndex((l) => !/^\s*(\*|\/\/|\/\*)/.test(l) && PERMISSION_CALL.test(l))
    if (at < 0) continue
    const call = (lines[at].match(PERMISSION_CALL) ?? ['a permission request'])[0].replace(/\s*\($/, '')
    for (const mod of Object.keys(PURPOSE_STRINGS)) {
      if (MODULE_REF(mod).test(source)) sites.push({ file: path.relative(ROOT, file).replace(/\\/g, '/'), line: at + 1, module: mod, call })
    }
  }
  const { fails: crashes, armed } = purposeStringProblems({ app, sites, declared, installed })
  fails.push(...crashes)
  for (const a of armed) notes.push(`ARMED â€” ${a}`)
  if (crashes.length === 0) ok.push(`${app}: every permission request has its iOS purpose string`)
}

/* 15 â”€â”€ the listing pictures must come from the DEMONSTRATION ESTATE, not the board fixtures â”€â”€â”€â”€â”€â”€
 * A listing that shows one product and a reviewer who finds another is itself a rejection (M6 Â§3.2),
 * and it is exactly the mistake nobody catches by looking: the pictures are the real app at the real
 * device sizes either way, and only the data behind them differs. `tools/store/screenshots.mjs` writes
 * down which source it used, so this reads that back rather than guessing from the images.
 */
const shotManifest = existsSync(shotsFile) ? JSON.parse(readFileSync(shotsFile, 'utf8')) : null
if (shotManifest === null) {
  fails.push('store/screenshots/manifest.json is missing â€” nothing says where the listing pictures came from (npm run store:screenshots)')
} else if (/fixture/i.test(String(shotManifest.source ?? ''))) {
  fails.push("store/screenshots: the pictures came from the design boards' FIXTURES, so they show names and figures no reviewer can reach â€” seed the demonstration estate (php artisan demo:seed) and retake them (npm run store:screenshots)")
} else {
  ok.push(`store screenshots: ${shotManifest.source}${shotManifest.date ? ` Â· ${shotManifest.date}` : ''}`)
  if (/browser capture/i.test(String(shotManifest.method ?? ''))) {
    notes.push('for a human: the store screenshots are BROWSER captures of the demonstration estate â€” retake them on a handset the moment one is reachable (M6 Â§3.2, Â§4)')
  }
}

/* â”€â”€ what only a person can do â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
notes.push('for a human: Play Console â€” content rating, target audience and the Data Safety form, filled from PRIVACY_DECLARATIONS.md')
notes.push('for a human: App Store Connect â€” the privacy labels, from the same document, and the review notes from STORE_REVIEW_NOTES.md')

console.log(`GATE store Â· ${ok.length} check(s) passed`)
for (const n of notes) console.log(`  note  ${n}`)
if (fails.length > 0) {
  console.log(`GATE store Â· FAIL â€” ${fails.length} thing(s) a reviewer would reject:`)
  for (const f of fails) console.log(`  ${f}`)
  process.exit(1)
}
console.log('GATE store Â· nothing here would be rejected for a reason this repo can see')

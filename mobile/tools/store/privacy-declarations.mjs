/**
 * Writes PRIVACY_DECLARATIONS.md from tools/store/data-map.cjs — and checks it against the code.
 *
 *   node tools/store/privacy-declarations.mjs           write the documents
 *   node tools/store/privacy-declarations.mjs --check   exit 1 if a document is stale, a code path named in the
 *                                                       data map no longer exists, or a tracking / analytics /
 *                                                       advertising / crash-reporting SDK has appeared in an app
 *
 * TWO DOCUMENTS (M25). PRIVACY_DECLARATIONS.md is the store pack's: the Resident App alone, the one app that goes to
 * the stores. The Guard App is distributed privately and listed in no store, so its rows are written to
 * distribution/guard/PRIVACY_DATA.md, outside the store pack, where its own policy and a private store would read
 * them. Both are held to the code, and no tracking SDK may reach either app.
 */

import { createRequire } from 'node:module'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { nativeDependencies } from './privacy-manifest.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const require = createRequire(import.meta.url)
const { RESIDENT, GUARD, RESIDENT_NOT_COLLECTED, GUARD_NOT_COLLECTED, IOS_SDKS } = require('./data-map.cjs')
const OUT = path.join(ROOT, 'PRIVACY_DECLARATIONS.md')
const GUARD_OUT = path.join(ROOT, 'distribution', 'guard', 'PRIVACY_DATA.md')

// Anything that phones home about the user. None may be a dependency of either app: a crash reporter that
// screenshots a screen is a data breach; an analytics SDK makes every declaration below untrue.
const FORBIDDEN_SDKS = /^(@sentry\/|sentry-expo|@bugsnag\/|bugsnag|@datadog\/|@segment\/|analytics-react-native|@amplitude\/|amplitude|mixpanel|@react-native-firebase\/(analytics|crashlytics|perf)|expo-firebase-analytics|react-native-fbsdk|@logrocket\/|logrocket|instabug|@instabug\/|appcenter|@microsoft\/appcenter|newrelic|@newrelic\/|smartlook|uxcam|react-native-appsflyer|@adjust\/|react-native-adjust|expo-ads|react-native-google-mobile-ads|expo-tracking-transparency|posthog)/

const problems = []
const table = (rows) => [
  '| Data | Apple type | Google Play category → type | Linked to identity | Tracking | Optional | Purpose | Who sees it | Kept | Code path |',
  '|---|---|---|---|---|---|---|---|---|---|',
  ...rows.map((r) => {
    for (const p of r.paths) if (!existsSync(path.join(ROOT, p))) problems.push(`data-map names a code path that does not exist: ${p}`)
    return `| ${r.what} | ${r.apple} | ${r.google} | ${r.linked ? 'Yes' : 'No'} | No | ${r.optional ? 'Yes' : 'No — needed to use the app'} | App functionality — ${r.why} | ${r.who} | ${r.kept} | ${r.paths.map((p) => `\`${p}\``).join('<br>') || 'the public web form (server)'} |`
  }),
].join('\n')

for (const app of ['guard', 'resident']) {
  const deps = Object.keys(JSON.parse(readFileSync(path.join(ROOT, 'apps', app, 'package.json'), 'utf8')).dependencies ?? {})
  for (const dep of deps) if (FORBIDDEN_SDKS.test(dep)) problems.push(`apps/${app} depends on ${dep} — a tracking / analytics / crash-reporting SDK; every declaration here would be untrue`)
}

/*
 * THE VERDICT TABLE IS HELD TO THE REAL node_modules, in both directions.
 *
 * "41 libraries ship no privacy manifest" is a number that goes stale the moment anybody adds a
 * native dependency, and a stale number in a store submission is worse than none. So the set of
 * names in `IOS_SDKS` must be EXACTLY the set `privacy-manifest.mjs` finds with an iOS half and no
 * manifest — a new one with no verdict fails the gate, and a verdict for a library that has since
 * gained a manifest or been removed fails it too.
 */
const withoutManifest = new Set()
const manifestCounts = {}
const withoutByApp = {}
for (const app of ['guard', 'resident']) {
  const { without, withManifest } = nativeDependencies(app)
  manifestCounts[app] = { without: without.length, with: withManifest.length, sources: withManifest }
  withoutByApp[app] = new Set(without)
  for (const dep of without) withoutManifest.add(dep)
}

const judged = new Set(IOS_SDKS.map((row) => row.name))
for (const dep of withoutManifest) if (!judged.has(dep)) problems.push(`${dep} is a native dependency with an iOS half and no privacy manifest, and no verdict for it in data-map.cjs IOS_SDKS — read its iOS source and add one`)
for (const dep of judged) if (!withoutManifest.has(dep)) problems.push(`IOS_SDKS judges ${dep}, which is no longer a native dependency without a manifest — remove the row`)

// The store pack's table names the Resident App's own libraries; every verdict is still held above, for both apps.
const sdkTable = [
  '| Library | On Apple\'s "must ship a manifest" list? | Required-reason API in its own iOS source | What it causes to be collected | Verdict |',
  '|---|---|---|---|---|',
  ...[...IOS_SDKS].filter((r) => withoutByApp.resident.has(r.name)).sort((a, b) => a.name.localeCompare(b.name)).map((r) => `| \`${r.name}\` | ${r.apple === false ? 'No' : `**${r.apple}**`} | ${r.api ?? 'None'} | ${r.gets} | ${r.verdict} |`),
].join('\n')

const R = manifestCounts.resident

const doc = `# PRIVACY DECLARATIONS — what the app collects, generated from one description

GENERATED by \`node tools/store/privacy-declarations.mjs\` from \`tools/store/data-map.cjs\` — edit the data map, never this file.
The Apple privacy manifest of the app (\`ios.privacyManifests\`, from \`tools/store/privacy-manifests.cjs\`) is derived from the
same description, so the manifest, the **App Store privacy labels** and **Google Play's Data Safety form** are filled from one
place and cannot disagree with each other; \`--check\` (part of \`gate:store\`) holds the description to the code.

## The answers both stores ask first

| Question | Answer |
|---|---|
| Does the app track users (Apple's definition), or share data with data brokers? | **No.** \`NSPrivacyTracking\` is false; no tracking domains; no advertising id is read |
| Advertising or marketing use of any data? | **No** |
| Analytics, crash-reporting or session-replay SDKs in the app? | **None** — checked against the app's dependency list on every gate run |
| Is data encrypted in transit? | **Yes** — a production build refuses to be made without an \`https://\` server address (\`app.config.ts\`) |
| Can users request deletion? | **Yes** — in the app (Profile → Delete my account) **and** on the public page \`/account/delete\` (no sign-in, no app needed). What must be kept by law or as the community's record is itemised before the person confirms |
| Is any data collection optional? | Yes — marked per row below |
| Is the app directed at children? | No |
| Every purpose | **App functionality** only |

## GeminiSecure (Resident App) — \`jm.geminisecure.resident\`

${table(RESIDENT)}

**Not collected** (say so where a form asks):
${RESIDENT_NOT_COLLECTED.map((line) => `- ${line}`).join('\n')}

## The iOS libraries that ship no privacy manifest of their own — a verdict each

\`node tools/store/privacy-manifest.mjs\` walks the real \`node_modules\`. The app has **${R.with + R.without} native
dependencies** with an iOS half; **${R.with}** ship a \`PrivacyInfo.xcprivacy\` and **${R.without}** do not.
The declared reasons above are the union of what those ${R.with} say: ${R.sources.map((s) => `\`${s}\``).join(', ')}.

A missing manifest is only a problem if the library is one Apple REQUIRES to ship one, or if it touches a **required-reason API**
(file timestamps · system boot time · disk space · active keyboards · user defaults) that the app then fails to declare. Apple's
required list names frameworks and pods — Alamofire, FirebaseCore, hermes, SDWebImage, GoogleUtilities, OneSignal, RxSwift and so on
— not npm packages, and **none of the ${R.without} npm names is on it**. Each one below was answered by reading its own iOS sources
(\`.m\`, \`.mm\`, \`.swift\`, \`.h\`, \`.c\`, \`.cpp\`) for every symbol on Apple's four lists.

**The short answer: none of the ${R.without} needs a manifest entry this project does not already have.** One of them, \`expo-sharing\`,
touches a required-reason API (user defaults) and \`CA92.1\` already covers it. Three raise a question about a CocoaPods dependency
rather than about themselves, and that question cannot be answered from this repository — see "What is left undetermined" below.

${sdkTable}

### Two things found while checking, that are not manifest matters

- **\`@react-native-community/netinfo\` makes a request to a Google endpoint on iOS.** Its iOS native module returns only
  \`type\`, \`isConnected\` and \`details\` — never \`isInternetReachable\` — so its JavaScript half falls back to a \`HEAD\` request to
  \`https://clients3.google.com/generate_204\` whenever the phone becomes connected, and \`packages/core/offline/network.ts\` reads
  \`isInternetReachable\`, so the fallback is live in the app. Nothing about the person is sent — it is a bare HEAD to a 204
  endpoint — but the device's IP address reaches Google, so "the app contacts no third-party server" would not be a true sentence.
  It is not tracking (no identifier, no profile, nothing stored) and needs no privacy-label row. To stop it, call
  \`NetInfo.configure({ reachabilityUrl: '<this platform's own health URL>' })\` once at start-up.
- **The certificate pinner reports nothing to anybody.** \`react-native-ssl-public-key-pinning\` sets
  \`kTSKDisableDefaultReportUri = YES\` on every pinned domain, so a pin failure is never posted to TrustKit's default reporting
  service. Checked in \`node_modules/react-native-ssl-public-key-pinning/ios/SslPublicKeyPinning.mm\`.

### What is left undetermined, and what would settle it

The app has no \`ios/\` folder — the native project is generated at build time — so the CocoaPods layer does not exist in this
repository and cannot be read from it. Three pods are pulled in by libraries above and their own manifests are therefore
**undetermined here**: \`SDWebImage ~> 5.21.0\` (via \`expo-image\`; it has shipped a manifest since 5.18.0), \`hermes-engine\`
(via \`expo-modules-core\`, \`react-native-reanimated\` and \`react-native-worklets\`; React Native's prebuilt artifacts carry one), and
\`MMKVCore 2.4.0\` (via \`react-native-mmkv\`; not on Apple's required list). SDWebImage and hermes ARE on Apple's required list, so
they must each ship a manifest AND a signature — that obligation is the pod author's, not this project's, but the build has to
carry it.

All three are settled by one command and three files, once, on a machine with CocoaPods:

    npx expo prebuild --platform ios --no-install && cd ios && pod install

then read \`ios/Pods/SDWebImage/**/PrivacyInfo.xcprivacy\`, \`ios/Pods/MMKVCore/**/PrivacyInfo.xcprivacy\` and the hermes framework's
own. Until somebody has, the honest answer is "expected to be there, not verified here" — which is what a store submission should
say rather than a guess.

## Filling the two forms

- **App Store Connect → App Privacy**: for each row above choose the *Apple type*; "Used to track you" = **No**; "Linked to you" as the
  row says; purpose = **App Functionality**. Nothing else is collected.
- **Play Console → Data safety**: "Does your app collect or share any of the required user data types?" **Yes**; "Is all of the user data
  collected by your app encrypted in transit?" **Yes**; "Do you provide a way for users to request that their data is deleted?" **Yes** —
  the URL is \`https://<your domain>/account/delete\`. For each row choose the *Google category → type*; **Collected**, not *Shared*
  (the push-delivery services act as processors); "processed ephemerally" **No**; required or optional as the row says; purpose
  **App functionality** (and **Account management** for name, email and ids).
`

const guardDoc = `# The Guard App — what it collects, generated from one description

GENERATED by \`node tools/store/privacy-declarations.mjs\` from \`tools/store/data-map.cjs\` — edit the data map, never this file.

**The Guard App is distributed privately, to Gemini's own officers, and is listed in no store** (the client's decision, M25).
This is not part of the store pack. It is the record of what the app collects, which the app's own privacy policy
(\`apps/guard/src/features/legal/guardLegal.ts\`) must agree with, and which a private store would ask for if the client ever
chooses one (\`distribution/guard/README.md\`). The app's Apple privacy manifest is derived from the same description.

## GeminiSecure Guard — \`jm.geminisecure.guard\`

${table(GUARD)}

**Not collected**:
${GUARD_NOT_COLLECTED.map((line) => `- ${line}`).join('\n')}
`

if (process.argv.includes('--check')) {
  if (!existsSync(OUT) || readFileSync(OUT, 'utf8').replace(/\r\n/g, '\n') !== doc) problems.push('PRIVACY_DECLARATIONS.md is stale — run `node tools/store/privacy-declarations.mjs`')
  if (!existsSync(GUARD_OUT) || readFileSync(GUARD_OUT, 'utf8').replace(/\r\n/g, '\n') !== guardDoc) problems.push('distribution/guard/PRIVACY_DATA.md is stale — run `node tools/store/privacy-declarations.mjs`')
  if (problems.length > 0) {
    console.log(`privacy-declarations · FAIL — ${problems.length}:`)
    for (const p of problems) console.log(`  ${p}`)
    process.exit(1)
  }
  console.log(`privacy-declarations · the description matches the code (${RESIDENT.length} resident + ${GUARD.length} guard data rows; ${IOS_SDKS.length} iOS libraries without a manifest, each with a verdict; no tracking or analytics SDK in either app)`)
} else {
  writeFileSync(OUT, doc)
  mkdirSync(path.dirname(GUARD_OUT), { recursive: true })
  writeFileSync(GUARD_OUT, guardDoc)
  console.log(`wrote PRIVACY_DECLARATIONS.md (${RESIDENT.length} resident rows, ${IOS_SDKS.length} library verdicts) and distribution/guard/PRIVACY_DATA.md (${GUARD.length} guard rows)${problems.length ? ` · ${problems.length} PROBLEM(S): ${problems.join('; ')}` : ''}`)
  if (problems.length > 0) process.exit(1)
}

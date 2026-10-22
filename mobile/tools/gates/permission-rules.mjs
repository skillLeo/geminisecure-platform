/**
 * THE PERMISSION RULES, AS PURE FUNCTIONS.
 *
 * Every rule here takes facts and returns sentences. Nothing in this file reads a file, walks a
 * tree or exits a process — `tools/gates/location-discipline.mjs` and `tools/gates/store.mjs`
 * gather the facts and print what comes back, and `tools/gates/selftest.mjs` proves each rule on
 * made-up facts without writing a single file into either app.
 *
 * WHY THESE FOUR EXIST. A patrol checkpoint scan used to read a position. It stopped. Nothing else
 * changed: the allow-list kept its entry, the data map kept its code path, and an iOS purpose string
 * went on telling Apple the app reads a position when a guard scans a checkpoint — for months, with
 * every gate green. A claim no code supports is the kind of thing a store removes an app for AFTER
 * approving it, so the rules below make each of those four states impossible to sit in quietly.
 */

/**
 * The sentence Google's "prominent disclosure" turns on: the person is told the position is NOT
 * taken when they are not looking. Written wide, because the honest phrasings differ — "never
 * tracked in the background", "the app never follows you", "only while you use the app" — and a
 * gate that demanded one wording would be answered by pasting that wording.
 */
export const BACKGROUND_NEGATION =
  /never[^.!?]{0,70}(?:background|follows? you|track)|(?:not|isn't|is not|never)[^.!?]{0,50}in the background|only[^.!?]{0,40}while[^.!?]{0,50}(?:open|use the app|using the app|in front of you)|nothing[^.!?]{0,50}after[^.!?]{0,30}(?:shift|clock)/i

/**
 * Every quoted string in a source file, so a rule can judge the COPY rather than the code — and
 * NOTHING from a comment, because a promise written in a comment is not shown to anybody.
 *
 * This walks the source rather than matching it. A regex cannot do this job: `the Guard App's incident
 * form` inside a comment opens a quote that swallows the next real string, which is precisely how the
 * first draft of this rule managed to fail the one disclosure in the codebase that is correct.
 */
export function stringsIn(source) {
  const s = String(source)
  const out = []
  for (let i = 0; i < s.length; ) {
    if (s[i] === '/' && s[i + 1] === '/') { while (i < s.length && s[i] !== '\n') i++; continue }
    if (s[i] === '/' && s[i + 1] === '*') { i += 2; while (i < s.length && !(s[i] === '*' && s[i + 1] === '/')) i++; i += 2; continue }
    if (s[i] === "'" || s[i] === '"' || s[i] === '`') {
      const quote = s[i++]
      let text = ''
      while (i < s.length && s[i] !== quote) {
        if (s[i] === '\\') { text += s[i + 1] ?? ''; i += 2; continue }
        text += s[i++]
      }
      i++
      out.push(text)
      continue
    }
    i++
  }
  return out
}

/**
 * RULE 1 — A FILE ON THE ALLOW-LIST THAT READS NOTHING IS A STALE ALLOWANCE, NOT A FOOTNOTE.
 *
 * The allow-list is the only door to a position in either app, so an entry is a standing permission
 * for whatever file has that name. An entry whose file stopped reading is therefore worse than
 * useless: it silently re-permits the read if anyone puts one back, and — as patrol proved — it goes
 * on being cited by the documents generated beside it. The only entry allowed to read nothing is one
 * explicitly marked `stateOnly`, which asks the phone what the permission IS and never takes a fix.
 *
 *   allowed  { [rel]: { moment, stateOnly?, disclosure? } }
 *   facts    { exists: Set<rel>, readsPosition: Set<rel> }
 */
export function staleAllowances(allowed, facts) {
  const problems = []
  for (const [rel, entry] of Object.entries(allowed)) {
    if (!facts.exists.has(rel)) {
      problems.push(`${rel} is on the allowed list but does not exist — remove it, or a new file of that name would be allowed to read a position without anybody deciding so`)
      continue
    }
    if (entry.stateOnly) {
      if (facts.readsPosition.has(rel)) problems.push(`${rel} is marked "permission state only" on the allowed list, and it READS A POSITION — the marking is no longer true. Either the read does not belong there, or the entry must stop claiming it only asks the phone about the permission`)
      continue
    }
    if (!facts.readsPosition.has(rel)) problems.push(`${rel} is on the allowed list ("${entry.moment}") and reads no position — a STALE ALLOWANCE. It re-permits a read nobody decided on, and the documents generated from this list go on claiming one. Delete the entry, or mark it \`stateOnly: true\` if the file only asks the phone what the permission is`)
  }
  return problems
}

/**
 * RULE 2 — A FILE THAT ASKS FOR THE LOCATION PERMISSION MUST NAME ITS DISCLOSURE.
 *
 * Google's requirement is not "explain it somewhere": the sentence must be on screen BEFORE the
 * operating system's own dialog, with an explicit action to continue. A dialog raised from a
 * `useEffect` has no tap between the person and it, so there is nowhere for the sentence to have
 * been — which is why `onMount` is reported, though the requirement holds either way.
 *
 * The entry names the file that carries the sentence; the rule then reads that file's COPY and holds
 * it to the two things the policy asks for — that it is about location, and that it says the position
 * is not taken in the background.
 *
 *   facts  { asks: Set<rel>, onMount: Set<rel>, sourceOf: (path) => string | null }
 */
export function disclosureProblems(allowed, facts) {
  const problems = []
  for (const [rel, entry] of Object.entries(allowed)) {
    if (!facts.asks.has(rel)) continue
    const how = facts.onMount.has(rel)
      ? "ON MOUNT — the phone's dialog appears with no tap between the person and it"
      : 'and names no sentence shown before the phone asks'
    if (!entry.disclosure) {
      problems.push(`${rel} asks for the location permission ${how}, and its allow-list entry names no disclosure. Google requires the explanation BEFORE the dialog: build the screen, then add \`disclosure: '<file>'\` to the entry so this gate can hold the words to it`)
      continue
    }
    const source = facts.sourceOf(entry.disclosure)
    if (source === null) {
      problems.push(`${rel} names a disclosure that does not exist (${entry.disclosure})`)
      continue
    }
    const copy = stringsIn(source)
    if (!copy.some((text) => /location|where you are|where the phone is|your position|spot your phone/i.test(text))) {
      problems.push(`${rel} names ${entry.disclosure} as its disclosure, and no words SHOWN there are about location (a comment is not a disclosure)`)
      continue
    }
    if (!copy.some((text) => BACKGROUND_NEGATION.test(text))) {
      problems.push(`${rel} names ${entry.disclosure} as its disclosure, and no sentence there tells the person the position is NOT taken in the background — that sentence is the policy requirement, not a nicety`)
    }
  }
  return problems
}

/**
 * RULE 2b — THE ONE BACKGROUND READER'S DISCLOSURE SAYS WHAT A BACKGROUND READER MUST (M11 §3, D-174).
 *
 * Google's prominent disclosure for background location is not "this app uses your location": it must be on
 * screen BEFORE the dialog, reached by a tap, and say that the location is collected when the app is closed or not
 * in use, and what for. This holds the named file's SHOWN words (never a comment) to five things, each wide enough
 * for honest phrasings and none satisfied by a sentence about something else:
 *   location    it is about location;
 *   background  it carries on in the background / while the app is closed or not in use;
 *   who         who sees it (the control room, dispatch, supervisors);
 *   when        it is only on duty, and stops at clock-out;
 *   refusal     saying no is possible, and is an answer.
 * And the ask must never be raised on mount — the same test rule 2 applies.
 *
 *   facts  { asks: Set<rel>, onMount: Set<rel>, sourceOf: (path) => string | null }
 */
export const BACKGROUND_DISCLOSURE = {
  location: /location|where you are|your position/i,
  background: /in the background|when the app is closed|while the app is closed|app is (?:closed|not in use)/i,
  who: /control room|dispatch|supervisor/i,
  when: /(?:clock(?:-| )?in|on duty)[^.]{0,80}(?:clock(?:-| )?out|stops?)|stops? when you clock out|until you clock out/i,
  refusal: /\b(?:say(?:ing)? no|refus\w*|do not share|don['’]t share)\b/i,
}

export function backgroundDisclosureProblems(allowed, facts) {
  const problems = []
  for (const [rel, entry] of Object.entries(allowed)) {
    if (!facts.asks.has(rel)) continue
    if (facts.onMount.has(rel)) problems.push(`${rel} asks for the BACKGROUND location permission ON MOUNT — the phone's dialog appears with no tap between the person and it. Only a tap after the disclosure may raise it`)
    if (!entry.disclosure) {
      problems.push(`${rel} asks for the background location permission and its allow-list entry names no disclosure — Google requires the prominent disclosure BEFORE the dialog`)
      continue
    }
    const source = facts.sourceOf(entry.disclosure)
    if (source === null) {
      problems.push(`${rel} names a disclosure that does not exist (${entry.disclosure})`)
      continue
    }
    const copy = stringsIn(source).join('\n')
    for (const [what, pattern] of Object.entries(BACKGROUND_DISCLOSURE)) {
      if (!pattern.test(copy)) problems.push(`${rel} names ${entry.disclosure} as its disclosure, and nothing SHOWN there says ${what === 'location' ? 'it is about location' : what === 'background' ? 'that it carries on in the background, or while the app is closed' : what === 'who' ? 'who sees it (the control room)' : what === 'when' ? 'that it is only on duty and stops at clock-out' : 'that the person may say no'} — the background disclosure must`)
    }
  }
  return problems
}

/**
 * RULE 2c — THE BACKGROUND READER STOPS BY CONSTRUCTION (M11 §3, D-174).
 *
 * "Clock out and it stops" is a promise to a store and to an officer. A comment cannot keep it; three facts can:
 *   - the tracker's background task reads the DUTY before it does anything, and stops the service with none —
 *     so however the duty ends (clock-out, sign-out, a revoked session clearing storage), the next position the
 *     platform hands over stops it;
 *   - the tracker can stop the service at all (`stopLocationUpdatesAsync`);
 *   - each caller that must end it calls it (clock-out → endDuty, sign-out → stopDuty).
 *
 *   input  { tracker, source, callers: [{ file, source, call: RegExp, why }] }
 */
export function trackerProblems({ tracker, source, callers = [] }) {
  const problems = []
  const code = String(source)
  if (!/\bstopLocationUpdatesAsync\b/.test(code)) problems.push(`${tracker} never calls stopLocationUpdatesAsync — nothing could stop the background service`)
  // The task's own body: from the definition to the end of its callback — a few lines, read as written.
  const at = code.indexOf('TaskManager.defineTask')
  const task = at < 0 ? null : code.slice(at, code.indexOf('\n    })', at) + 1 || at + 400)
  if (task === null) problems.push(`${tracker} defines no background task — the gate cannot see what it does with a position`)
  const onPositions = /export async function onPositions\([\s\S]*?\n\}/.exec(code)
  const firstThing = onPositions ? onPositions[0].split('\n').slice(1, 6).join('\n') : ''
  if (task !== null && !/\bonPositions\(/.test(task)) problems.push(`${tracker}: the background task does not hand its positions to onPositions, so the duty check below does not cover it`)
  if (!onPositions || !/const duty = readDuty\(\)\s*\n\s*if \(!duty\) return stopUpdates\(\)/.test(firstThing)) problems.push(`${tracker}: onPositions must read the duty FIRST and stop the service when there is none ("const duty = readDuty()" then "if (!duty) return stopUpdates()") — that is what makes clock-out, sign-out and a revoked session stop it`)
  for (const caller of callers) {
    if (caller.source === null) problems.push(`${caller.file} does not exist — ${caller.why}, and nothing else does`)
    else if (!caller.call.test(String(caller.source))) problems.push(`${caller.file} no longer calls ${caller.call.source.replace(/\\b|\\/g, '')} — ${caller.why}, so the background service would outlive it`)
  }
  return problems
}

/**
 * RULE 3 — THE DOCUMENTS MAY NOT CLAIM A READ THE GATE NEVER SAW.
 *
 * `tools/store/data-map.cjs` fills PRIVACY_DECLARATIONS.md, the Apple privacy manifest, the App Store
 * labels and Google's Data Safety form. `app.config.ts` fills the Info.plist a reviewer reads on the
 * phone. Both are prose, and prose drifts. This is the join: a `PreciseLocation` code path must be a
 * file that ACTUALLY CALLS for a position, and a moment named in an NSLocation… string must be one
 * the allow-list still has a reader for.
 *
 *   input  { readsPosition, dataMapPaths: [{ app, path }], iosStrings: [{ app, key, text }], claims }
 *   claims [{ app, phrase, says: RegExp, needs: rel }] — the moments a purpose string may name
 */
export function documentProblems({ readsPosition, dataMapPaths = [], iosStrings = [], claims = [] }) {
  const problems = []
  for (const { app, path: rel } of dataMapPaths) {
    if (!readsPosition.has(rel)) problems.push(`tools/store/data-map.cjs: the ${app} PreciseLocation row names \`${rel}\` as a code path, and nothing there reads a position — the Data Safety form and the privacy label would declare a collection this app does not make`)
  }
  for (const { app, key, text } of iosStrings) {
    for (const claim of claims) {
      if (claim.app !== app || !claim.says.test(text)) continue
      if (!readsPosition.has(claim.needs)) problems.push(`apps/${app}/app.config.ts: ${key} tells Apple the app reads a position when the person will "${claim.phrase}", and ${claim.needs} reads no position — a purpose string may not describe something the app does not do`)
    }
  }
  return problems
}

/**
 * RULE 4 — A PERMISSION REQUEST NEEDS ITS iOS PURPOSE STRING, EVEN BEHIND AN OPTIONAL `require`.
 *
 * iOS does not refuse a permission request that has no purpose string; it KILLS THE APP, instantly,
 * on the device. A module loaded in a try/catch hides that: the call sits there unreachable, passing
 * every check, until somebody installs the package to finish the feature and every iPhone starts
 * dying on the screen that uses it.
 *
 * So the trigger is the package, not the call. While the module is absent the call cannot run and
 * this is reported as ARMED — loudly, every run, never silently passed. The moment the module becomes
 * a dependency the same site becomes a FAILURE, which is the point: it is caught before a build, not
 * after a crash report.
 *
 *   input  { app, sites: [{ file, line, module, call }], declared: Set<NSKey>, installed: Set<module> }
 */
export const PURPOSE_STRINGS = {
  'expo-speech-recognition': ['NSMicrophoneUsageDescription', 'NSSpeechRecognitionUsageDescription'],
  'expo-av': ['NSMicrophoneUsageDescription'],
  'expo-audio': ['NSMicrophoneUsageDescription'],
  'expo-camera': ['NSCameraUsageDescription'],
  'expo-image-picker': ['NSPhotoLibraryUsageDescription'],
  'expo-media-library': ['NSPhotoLibraryUsageDescription'],
  'expo-location': ['NSLocationWhenInUseUsageDescription'],
  'expo-local-authentication': ['NSFaceIDUsageDescription'],
  'expo-contacts': ['NSContactsUsageDescription'],
  'expo-calendar': ['NSCalendarsUsageDescription'],
  'expo-sensors': ['NSMotionUsageDescription'],
  'expo-tracking-transparency': ['NSUserTrackingUsageDescription'],
}

/** The calls that make a phone put a permission dialog on the screen. */
export const PERMISSION_CALL = /\b(?:request(?:Foreground|Camera|Microphone|MediaLibrary|Tracking|Calendar|Contacts)?Permissions?Async|requestPermissionToUseAsync|requestTrackingPermissionsAsync)\s*\(/

export function purposeStringProblems({ app, sites = [], declared, installed }) {
  const fails = []
  const armed = []
  for (const { file, line, module, call } of sites) {
    for (const key of PURPOSE_STRINGS[module] ?? []) {
      if (declared.has(key)) continue
      if (installed.has(module)) fails.push(`apps/${app}: ${file}:${line} calls ${call} on ${module}, and ${key} is not declared — iOS ENDS the app the moment that dialog would appear`)
      else armed.push(`apps/${app}: ${file}:${line} calls ${call} on ${module}, and ${key} is not declared. ${module} is NOT installed, so the call cannot run today — the day somebody installs it, every iPhone dies on that screen. Add ${key} to apps/${app}/app.config.ts BEFORE the package`)
    }
  }
  return { fails, armed }
}

/* ══ RULE 5 — NOT WIRED IN YET. Switched on as part of the dependency pass; see below. ═══════════ */

/**
 * RULE 5 — A PERMISSION NOBODY USES MAY NOT SHIP.
 *
 * "Why does a gate app want to know my Wi-Fi state?" is a question asked at review time, and the only
 * honest answer — "a library we stopped using brings it in" — is not one anybody wants to give. Two
 * different things put a permission in the manifest with nothing behind it, so this rule has two halves.
 *
 *   A  EVERY SOURCE LIBRARY IS DEAD. The permission arrives only from dependencies no source file
 *      imports (tools/store/unused-native.mjs --json). Remove the dependency and the permission goes.
 *
 *   B  THE PROJECT ASSERTS NOTHING NEEDS IT. A heuristic cannot catch `ACCESS_WIFI_STATE`: it comes
 *      from @react-native-community/netinfo, which IS imported — just never for anything to do with
 *      Wi-Fi. So that claim has to be WRITTEN DOWN, with the file that proves it, and defended. An
 *      asserted permission still in the merged manifest is a failure: either the dependency goes, or
 *      the assertion does and PERMISSIONS.md gains a row that justifies keeping it.
 *
 * A permission this project asked for ITSELF (`android.permissions` in app.config.ts) is never flagged
 * here — holding those to PERMISSIONS.md is check 1's job in tools/gates/store.mjs.
 *
 *   input  { app, merged: [{ permission, from }], unused: Set<library>, assertedNotNeeded }
 */
export const OUR_OWN_CONFIG = 'app config + plugins'

/**
 * Permissions this project states no code of its own needs, each with the file that proves it.
 * Adding a line here is a claim somebody has to stand behind at review time — not a heuristic's guess.
 */
export const ASSERTED_NOT_NEEDED = {
  'android.permission.ACCESS_WIFI_STATE': {
    why: 'nothing asks the phone anything about Wi-Fi. Whether the app is online is read from NetInfo\'s isConnected and isInternetReachable, and nothing reads an SSID, a BSSID or a signal strength',
    proof: 'packages/core/offline/network.ts',
  },
}

export function unusedSourceProblems({ app, merged = [], unused = new Set(), assertedNotNeeded = {} }) {
  const problems = []
  for (const { permission, from = [] } of merged) {
    const libraries = from.filter((source) => source !== OUR_OWN_CONFIG)
    const asserted = assertedNotNeeded[permission]
    if (asserted) {
      problems.push(`${app}: ${permission} is still in the merged manifest, and this project asserts nothing it wrote needs it — ${asserted.why} (${asserted.proof}). It arrives from ${libraries.join(', ') || OUR_OWN_CONFIG}: drop that dependency, or withdraw the assertion and give PERMISSIONS.md a row that defends keeping it`)
      continue
    }
    if (libraries.length === 0) continue
    if (libraries.every((library) => unused.has(library))) problems.push(`${app}: ${permission} is in the merged manifest and its only source is ${libraries.join(', ')}, which nothing in this app imports — remove the dependency, or a reviewer's question about this permission has no good answer`)
  }
  return problems
}

/**
 * RULE 6 — THE WRITTEN-DOWN MANIFEST MUST MATCH THE CONFIG IT CAME FROM.
 *
 * `store/manifests/<app>.permissions.json` is a SNAPSHOT: it is what a person opens to find out what
 * the app declares, and what the submission package is filled from. The gate, meanwhile, recomputes
 * everything live from `app.config.ts` on each run — so a correction to the config makes the gate
 * green and leaves the snapshot saying the old thing, with nothing anywhere comparing the two.
 *
 * That is not hypothetical: the false "scan a checkpoint" clause was struck from `app.config.ts`,
 * every gate went green, and `store/manifests/guard.permissions.json` went on telling any reader that
 * the Guard App reads a position when an officer scans a checkpoint. A stale snapshot is worse than no
 * snapshot, because it carries the authority of having been generated.
 *
 * The divergence is named by PATH, so the message says which key drifted rather than "something did".
 *
 *   input  { app, live, snapshot, ignore: [dotted paths that legitimately differ per environment], file? }
 *
 * `file` names where the snapshot lives: `store/manifests/` for an app that goes to the stores, `distribution/<app>/`
 * for one that does not (M25, tools/store/snapshot-path.mjs). Without it, the store pack's own place is assumed.
 */
export function snapshotProblems({ app, live, snapshot, ignore = [], file = `store/manifests/${app}.permissions.json` }) {
  if (snapshot === null || snapshot === undefined) return [`${app}: ${file} is missing — regenerate it with \`node tools/store/manifest-audit.mjs\``]
  const skip = new Set(ignore)
  const problems = []
  const show = (v) => (v === undefined ? '(absent)' : JSON.stringify(v).slice(0, 90))
  const walk = (a, b, at) => {
    if (skip.has(at) || problems.length >= 10) return
    const flat = (x) => typeof x !== 'object' || x === null
    if (flat(a) || flat(b) || Array.isArray(a) !== Array.isArray(b)) {
      if (JSON.stringify(a ?? null) !== JSON.stringify(b ?? null)) problems.push(`${app}: ${file} is STALE at \`${at}\` — it says ${show(b)}, and apps/${app}/app.config.ts now produces ${show(a)}. Regenerate it: \`node tools/store/manifest-audit.mjs\``)
      return
    }
    for (const key of [...new Set([...Object.keys(a), ...Object.keys(b)])]) walk(a[key], b[key], at ? `${at}.${key}` : key)
  }
  walk(live, snapshot, '')
  return problems
}

/**
 * RULE 7 — EVERY iOS ENTITLEMENT IS REQUIRED WHERE IT IS NEEDED, AND WRITTEN DOWN WHEREVER IT IS HELD.
 *
 * An entitlement is not a permission: nobody is asked, nothing appears on screen, and a build missing
 * one does not fail — it just quietly stops doing something. Time Sensitive is the case that matters
 * here, and "quietly" is the whole problem.
 *
 * So both halves. A required entitlement MUST be declared. And any entitlement that IS declared must
 * have a row in PERMISSIONS.md, so one cannot be added — by us or by a library's config plugin —
 * without somebody writing down what it lets a signed build do.
 *
 *   input  { app, entitlements, required, documented: (key) => boolean }
 */
export const REQUIRED_ENTITLEMENTS = {
  'com.apple.developer.usernotifications.time-sensitive':
    "the server sends `interruptionLevel: 'time-sensitive'` on every urgent push (panic, duress, alertness, gate approval). iOS honours that level ONLY for a build carrying this entitlement — without it a Focus mode holds every one of them back, and nothing fails and nothing logs: the alert simply never surfaces",
}

/**
 * RULE 8 — NO ARTEFACT MAY REPEAT A CLAIM NOTHING BACKS.
 *
 * Rule 3 joins the two app configs to what the code reads. This joins EVERYTHING ELSE, because the
 * false "scan a checkpoint" clause reached six artefacts before anybody noticed, and the place it was
 * first written down was a COMMENT in a config plugin. A sentence copied from a comment into a purpose
 * string into a data map into two documents and a store listing is one unverified claim wearing six
 * costumes; this reads them all against the same table.
 *
 * USE vs MENTION, and ASSERTION vs DENIAL — the hard part, and the reason this is its own rule.
 * "no position is read when a checkpoint is scanned" is the CORRECT sentence and must pass. The same
 * words without the negation must fail. Three guards, in order:
 *
 *   1  A sentence is only a claim if it also carries a LOCATION WORD. "The camera is used to scan a
 *      pass or a checkpoint" names the moment and asserts nothing about position, so it is not a claim.
 *      This alone removes most of the corpus.
 *   2  A phrase inside QUOTATION MARKS is a mention, not a use — `The words "scan a checkpoint" were
 *      struck from this string` is talking ABOUT the sentence, not making it.
 *   3  A NEGATION within NEGATION_WINDOW characters of the phrase denies it. The window matters: a
 *      sentence can assert one thing and deny another, and "…scan a checkpoint… and it is never
 *      collected in the background" denies the background, not the checkpoint. 60 characters accepts
 *      every real denial in this repo (the furthest is 39) and rejects a denial about something else.
 *      It is a tuned number, probed at both edges; when it is wrong it FLAGS a sentence, which a person
 *      sees, rather than excusing one, which nobody would.
 *
 * KNOWN LIMIT, stated rather than guessed at: a claim asserted in a list item whose only negation lives
 * in the list's HEADING ("The app does NOT:" / "- read a position when you scan a checkpoint") is
 * flagged. Letting a heading license its bullets would make the rule silently stop reading them, and an
 * invisible false negative is the exact failure this whole rule exists to prevent. Put the denial in the
 * bullet — every "not collected" line in this repo already does.
 */
export const NEGATION_WINDOW = 60
export const NEGATION = /\b(?:never|not|no|none|nothing|nobody|without|neither|nor|cannot)\b|n['’]t\b/gi
export const LOCATION_WORD = /\b(?:location|position|coordinates|geofence|GPS|where (?:you|they|it) (?:are|is)|a fix)\b/i

/** Markdown and source comments reduced to the blocks a person actually reads as one thought. */
export function blocksOf(text) {
  const out = []
  let paragraph = []
  const flush = () => { if (paragraph.length) out.push(paragraph.join(' ')); paragraph = [] }
  for (const raw of String(text).split('\n')) {
    const line = raw.replace(/^\s*\/\*+|\*+\/\s*$/g, '').replace(/^\s*\/\/\s?/, '').trimEnd()
    if (!line.trim()) { flush(); continue }
    // A table row is a row of independent assertions; a bullet is its own thought; everything else
    // wraps, so it is joined back into the paragraph it was typed as.
    if (/^\s*\|/.test(line)) { flush(); for (const cell of line.split('|')) if (cell.trim()) out.push(cell.trim()); continue }
    if (/^\s*(?:[-*•]|\d+\.)\s/.test(line)) { flush(); paragraph.push(line.replace(/^\s*(?:[-*•]|\d+\.)\s/, '')); continue }
    paragraph.push(line.replace(/^\s*\*\s?/, ''))
  }
  flush()
  return out
}

/** The character ranges inside double or backtick quotes — where a phrase is being named, not used. */
const quotedRanges = (block) => [...block.matchAll(/"[^"]*"|`[^`]*`|“[^”]*”/g)].map((m) => [m.index, m.index + m[0].length])

/**
 * Sentences, with their offset in the block — and NEVER split inside a quotation, because a full stop
 * inside a quoted sentence does not end the sentence containing it. (Splitting there also leaves an
 * unpaired quote, which silently re-pairs the quotes after it and breaks the mention guard: that is a
 * real bug this rule had, caught by the real PERMISSIONS.md.)
 */
function sentencesOf(block, quoted) {
  const inQuote = (i) => quoted.some(([a, b]) => i >= a && i < b)
  const out = []
  let start = 0
  for (const m of block.matchAll(/[.!?]["”]?\s+/g)) {
    const end = m.index + m[0].length
    if (inQuote(m.index)) continue
    out.push({ text: block.slice(start, end), at: start })
    start = end
  }
  if (start < block.length) out.push({ text: block.slice(start), at: start })
  return out
}

/**
 * Clauses within a sentence, split only on STRONG boundaries — a semicolon, a dash, a colon. Never on
 * a comma: the original false purpose string put the location word at the head of a comma-separated
 * list of moments ("checks your location when you start a shift, scan a checkpoint, report an
 * incident"), and splitting commas would let exactly the sentence this rule exists for slip through.
 */
const clausesOf = (sentence) => {
  const out = []
  let at = 0
  for (const m of sentence.matchAll(/\s*[;:—–]\s*/g)) {
    out.push({ text: sentence.slice(at, m.index), at })
    at = m.index + m[0].length
  }
  out.push({ text: sentence.slice(at), at })
  return out
}

export function claimEchoProblems({ artefacts = [], claims = [], readsPosition = new Set() }) {
  const problems = []
  const unbacked = claims.filter((c) => !readsPosition.has(c.needs))
  if (unbacked.length === 0) return problems
  for (const { file, text } of artefacts) {
    for (const block of blocksOf(text)) {
      const quoted = quotedRanges(block)
      for (const sentence of sentencesOf(block, quoted)) {
        if (!LOCATION_WORD.test(sentence.text)) continue
        const negations = [...sentence.text.matchAll(NEGATION)].map((m) => m.index)
        for (const clause of clausesOf(sentence.text)) {
          // The moment and the word "location" must be in the SAME clause. "The checkpoint code says
          // which point was reached; the phone is never asked where it is" names the moment in one
          // clause and talks about position in the other — it asserts nothing about the two together.
          if (!LOCATION_WORD.test(clause.text)) continue
          for (const claim of unbacked) {
            const hit = new RegExp(claim.says.source, claim.says.flags.replace('g', '')).exec(clause.text)
            if (!hit) continue
            const inSentence = clause.at + hit.index
            if (quoted.some(([a, b]) => sentence.at + inSentence >= a && sentence.at + inSentence < b)) continue
            if (negations.some((n) => Math.abs(n - inSentence) <= NEGATION_WINDOW)) continue
            problems.push(`${file}: repeats a claim nothing backs — "${hit[0]}" (${claim.phrase}), asserted about location with no denial near it, and ${claim.needs} reads no position. The sentence: "${sentence.text.trim().slice(0, 130)}"`)
          }
        }
      }
    }
  }
  return problems
}

export function entitlementProblems({ app, entitlements = {}, required = {}, documented = () => true }) {
  const problems = []
  for (const [key, why] of Object.entries(required)) {
    if (entitlements[key] === undefined || entitlements[key] === false) problems.push(`apps/${app}/app.config.ts declares no \`${key}\` under ios.entitlements — ${why}`)
  }
  for (const key of Object.keys(entitlements)) {
    if (!documented(key)) problems.push(`${app}: ios.entitlements holds \`${key}\`, and PERMISSIONS.md has no row for it — an entitlement changes what a signed build is allowed to do, so it is written down and defended exactly like a permission`)
  }
  return problems
}

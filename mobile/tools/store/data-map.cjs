/**
 * WHAT EACH APP COLLECTS — the single description.
 *
 * The Apple privacy manifest (tools/store/privacy-manifests.cjs), PRIVACY_DECLARATIONS.md, the App Store
 * privacy labels and Google Play's Data Safety form are ALL filled from this file, so they cannot disagree
 * with each other. They cannot disagree with the app either: `node tools/store/privacy-declarations.mjs --check`
 * fails if a code path named here no longer exists, or if a data type is collected in the manifest and not here.
 *
 * A `PreciseLocation` row is held to a HIGHER bar, because a location claim is the one both stores read hardest.
 * Every path on such a row must be a file `tools/gates/location-discipline.mjs` watched ACTUALLY READ a
 * position — not merely a file that exists, and not a screen that calls one that does. The checkpoint-scan claim
 * that used to sit on the Guard row is why: patrol stopped reading a position, every document went on saying it
 * did, and a sentence no code supported reached an Info.plist. Name the file with the call in it.
 *
 * Both apps: NO tracking, NO advertising, NO analytics or crash-reporting SDK, NO data sold or shared with a
 * data broker. Everything is collected for APP FUNCTIONALITY, sent over HTTPS, and deletable (in-app and at
 * the web deletion page) except what the law or the community's own records require to be kept — see `kept`.
 *
 *   apple   the NSPrivacyCollectedDataType suffix          google  Data Safety "category → type"
 *   linked  tied to the person's account?                  optional  can the person use the app without giving it?
 */

const RESIDENT = [
  { what: 'Name', apple: 'Name', google: 'Personal info → Name', linked: true, optional: false, why: 'To open the account and confirm with the estate office that the person lives at the unit', who: 'Estate office; the security company', kept: 'Until the account is deleted', paths: ['apps/resident/src/features/auth/useSignIn.ts', 'apps/resident/src/features/auth/useClaimUnit.ts'] },
  { what: 'Email address', apple: 'EmailAddress', google: 'Personal info → Email address', linked: true, optional: false, why: 'Sign-in: a one-time code is emailed; there is no password', who: 'Estate office; the security company', kept: 'Until the account is deleted', paths: ['apps/resident/src/features/auth/useSignIn.ts'] },
  { what: 'Phone number', apple: 'PhoneNumber', google: 'Personal info → Phone number', linked: true, optional: true, why: "So the community's security team can call back after an alert; contact details on the estate register", who: "Estate office; the community's security team", kept: 'Until the account is deleted', paths: ['apps/resident/src/features/profile/useHouseholdForms.ts'] },
  { what: 'Unit / lot in the community', apple: 'PhysicalAddress', google: 'Personal info → Address', linked: true, optional: false, why: 'The account is a home in a gated community: passes, dues and alerts belong to the unit', who: "Estate office; the community's security team at the gate", kept: 'Stays with the HOME (the community register), not the person', paths: ['apps/resident/src/features/auth/useClaimUnit.ts'] },
  { what: 'Household members, vehicles (plates), emergency contacts — typed in by the resident', apple: 'OtherUserContent', google: 'Personal info → Other info', linked: true, optional: true, why: 'So the gate knows who belongs to the home, and who to reach in an emergency. The phone address book is NEVER read', who: "Estate office; the community's security team at the gate (names and plates)", kept: 'Until removed or the account is deleted', paths: ['apps/resident/src/features/profile/useHouseholdForms.ts'] },
  { what: 'Visitors the resident invites: name, purpose, plate, time window', apple: 'OtherUserContent', google: 'App activity → Other user-generated content', linked: true, optional: true, why: 'To let the visitor in at the gate with a signed pass', who: "The community's security team at the gate; estate office", kept: 'Up to 7 years as the community gate record, de-identified when the account is deleted', paths: ['apps/resident/src/features/visitors'] },
  { what: 'Precise location — ONLY when the resident sends an alert or reports an incident, added to that alert or report automatically. Never in the background', apple: 'PreciseLocation', google: 'Location → Precise location', linked: true, optional: true, why: "So the community's security team can find the person who asked for help", who: "The community's security team; estate office", kept: 'With the alert or report it belongs to', paths: ['apps/resident/src/features/panic/usePanic.ts', 'apps/resident/src/features/incidents/location.ts'] },
  { what: 'Audio — ONLY while the resident has an emergency alert open and its "Share live audio" switch is on: what the phone\'s microphone hears, a few seconds at a time. Never at any other moment, and stopped when the switch is turned off or the alert closes', apple: 'AudioData', google: 'Audio → Voice or sound recordings', linked: true, optional: true, why: "So the community's security control room can hear what is happening while it responds to the alert (M50 §4.2 #1)", who: "The security company's control room", kept: 'With the alert it belongs to', paths: ['apps/resident/src/features/panic/LiveAudioHost.native.tsx', 'apps/resident/src/features/panic/liveAudio.ts'] },
  { what: 'Photos the resident chooses to attach (re-encoded on the phone, which strips hidden location data)', apple: 'PhotosorVideos', google: 'Photos and videos → Photos', linked: true, optional: true, why: 'To show a maintenance problem or an incident', who: 'Estate office; assigned staff', kept: 'With the request or report', paths: ['apps/resident/src/features/maintenance/photoPicker.ts', 'apps/resident/src/features/incidents/photoPicker.ts'] },
  { what: 'Maintenance requests, security reports, meeting RSVPs, notice read receipts', apple: 'OtherUserContent', google: 'App activity → Other user-generated content; App interactions', linked: true, optional: true, why: 'They are the features', who: 'Estate office; assigned staff', kept: "The community's records", paths: ['apps/resident/src/features/maintenance/useMaintenanceRequest.ts', 'apps/resident/src/features/incidents/useIncidentReport.ts', 'apps/resident/src/features/notices/useNotices.ts'] },
  { what: 'Messages to support (web form)', apple: 'CustomerSupport', google: 'Messages → Other in-app messages', linked: true, optional: true, why: 'To answer them', who: 'The security company', kept: 'Until dealt with, at most 90 days after', paths: [] },
  { what: 'Account id', apple: 'UserID', google: 'Personal info → User IDs', linked: true, optional: false, why: 'To keep the person signed in', who: 'The security company', kept: 'Until the account is deleted', paths: ['apps/resident/src/features/auth/session.ts'] },
  { what: 'An identifier for THIS INSTALLATION of the app, the phone platform, and a notification (push) token', apple: 'DeviceID', google: 'Device or other IDs', linked: true, optional: false, why: 'One sign-in per phone; delivering notifications. Not an advertising id; never shared', who: 'The security company; Expo / Apple / Google push delivery', kept: 'Until sign-out or account deletion', paths: ['packages/core/crypto/deviceKey.ts', 'packages/core/notify/registration.ts'] },
]

const RESIDENT_NOT_COLLECTED = [
  'A VOTE is stored with nothing that links it to a household (not linked to identity; never on the phone after sending). The community keeps only THAT a home voted, and the day.',
  'Payment card details — the card checkout is a DEMONSTRATION with Stripe\'s test cards only (the server\'s D-179): the number, expiry, security code and name never leave the phone, a real card number is wiped as it is recognised, only which test card it was (brand and last four digits) is sent, and no money moves.',
  'Fingerprint / face — the app lock is the phone\'s own check; nothing reaches the app.',
  'Audio when SPEAKING a request or a report — it is turned into text ON THE PHONE (on-device recognition only): no sound is recorded, kept or sent. The text becomes part of what the person sends, declared above. (Live audio during an open alert, with its switch on, is the one time sound is sent — declared above.)',
  'Contacts from the address book, background location, advertising id, browsing history, health, analytics, crash logs — none.',
]

const GUARD = [
  { what: 'Name, service number and post', apple: 'Name', google: 'Personal info → Name', linked: true, optional: false, why: 'To identify the officer on duty', who: 'Supervisors; control room; the community sees the name of the officer on duty', kept: 'Employment record — as employment law requires', paths: ['apps/guard/src/features/enrolment/useEnrolCode.ts'] },
  { what: 'Precise location ON DUTY, only if the officer agrees to the location policy — from clock-in to clock-out, also in the background and while the app is closed, with a notification on the phone the whole time: about once a minute while moving (less often standing still or on a low battery), the accuracy, the battery level, and a fix at each checkpoint or gate scan, duress alert, clock-in and clock-out. Nothing off duty: it stops at clock-out, and the server refuses a position taken outside the shift', apple: 'PreciseLocation', google: 'Location → Precise location', linked: true, optional: true, why: 'The control room sees where its officers are, to send the nearest one to an alarm and to find a lone officer who needs help (told to the officer on the consent screen before anything is shared; saying no does not stop them working)', who: 'The security company\'s control room and supervisors. The community sees how many officers are on site, not where — unless its contract says otherwise', kept: '90 days, then deleted', paths: ['apps/guard/src/features/tracking/dutyTracker.ts'] },
  { what: 'Precise location — ONLY when starting or ending a shift, reporting an incident or raising a duress alert, while the app is open; plus whether the phone reports a simulated location. Nothing after clock-out, and none of it on a patrol checkpoint scan record', apple: 'PreciseLocation', google: 'Location → Precise location', linked: true, optional: false, why: 'The employer verifies the officer is physically at the assigned post for a paid shift (told to the officer before it starts); to record where an incident happened; to find an officer who needs help', who: 'Supervisors and the control room', kept: 'With the shift, incident or alert record — up to 7 years', paths: ['apps/guard/src/features/shift/useGeofence.ts', 'apps/guard/src/features/clockout/useClockOut.ts', 'apps/guard/src/features/incidents/useIncidentReport.ts', 'apps/guard/src/features/duress/useDuress.ts'] },
  { what: 'Photos the officer attaches to an incident report', apple: 'PhotosorVideos', google: 'Photos and videos → Photos', linked: true, optional: true, why: 'Evidence for the report', who: 'Supervisors; control room; the estate office for its own incidents', kept: 'With the incident record', paths: ['apps/guard/src/features/incidents/photoPicker.ts'] },
  { what: 'Gate records, patrol records, incident reports, messages with the control room, leave and equipment requests', apple: 'OtherUserContent', google: 'App activity → Other user-generated content', linked: true, optional: false, why: 'They are the job', who: 'Supervisors; control room; estate office', kept: "The community's security record, up to 7 years; employment requests as employment law requires", paths: ['apps/guard/src/features/gate', 'apps/guard/src/features/patrol', 'apps/guard/src/features/incidents/useIncidentReport.ts', 'apps/guard/src/features/messages/useMessages.ts', 'apps/guard/src/features/requests/useRequests.ts'] },
  { what: 'Alertness check-ins — whether and when the officer answered a check during a shift. With SEPARATE, revocable consent', apple: 'OtherDataTypes', google: 'App activity → Other actions', linked: true, optional: true, why: 'Officer safety on a night post', who: 'Supervisors', kept: 'With the shift record', paths: ['apps/guard/src/features/alertness/AlertnessWatcher.tsx'] },
  { what: 'Officer id', apple: 'UserID', google: 'Personal info → User IDs', linked: true, optional: false, why: 'To keep the handset bound to its officer', who: 'The security company', kept: 'Until the handset is revoked or the account deleted', paths: ['apps/guard/src/features/enrolment/useEnrolCode.ts'] },
  { what: 'An identifier for THIS INSTALLATION, the phone make and platform, and a notification (push) token', apple: 'DeviceID', google: 'Device or other IDs', linked: true, optional: false, why: 'The app is bound to one officer on one phone so nobody else can act as them; delivering notifications', who: 'The security company; Expo / Apple / Google push delivery', kept: 'Until the handset is revoked', paths: ['packages/core/crypto/deviceKey.ts', 'packages/core/notify/registration.ts'] },
]

const GUARD_NOT_COLLECTED = [
  'Fingerprint / face — the identity check at clock-in is the phone\'s own; NO image, template or score leaves the phone or is stored by the app. Face data is never used for advertising, marketing or data mining — it never exists outside the phone\'s secure hardware.',
  'Camera frames — while scanning a pass or checkpoint code no picture is kept, recorded or sent (gate camera-frames).',
  'Where a patrol checkpoint was scanned, on the SCAN record — a checkpoint scan carries NO position; the checkpoint code itself says which point was reached. (An officer who shares their location on duty has a fix taken at that moment by on-duty sharing, above — its own record, kept 90 days.)',
  "Household money — a guard's phone never receives a household's financial position (allowlist, contract-tested).",
  'Location off duty — none: sharing stops at clock-out, and the server refuses any position taken outside the shift.',
  'Audio — dictating a report is turned into text ON THE PHONE (on-device recognition only): no sound is recorded, kept or sent anywhere. The text becomes part of the report, declared above.',
  'Contacts, advertising id, analytics, crash logs — none.',
]

/**
 * THE iOS LIBRARIES THAT SHIP NO PRIVACY MANIFEST OF THEIR OWN — one verdict each.
 *
 * `node tools/store/privacy-manifest.mjs` walks the real node_modules and reports, for each app,
 * every native dependency with an iOS half and NO `PrivacyInfo.xcprivacy`. It found 41 — the same
 * 41 in both apps — and reporting a number is not the same as knowing whether it matters. Each one
 * is answered here against the three questions Apple actually asks:
 *
 *   1  IS IT ON APPLE'S LIST of SDKs that must ship a privacy manifest AND a signature? That list
 *      names FRAMEWORKS and PODS (Alamofire, FirebaseCore, hermes, SDWebImage, GoogleUtilities,
 *      OneSignal, RxSwift, …), not npm packages. None of the 41 npm names is on it. Two of them
 *      PULL IN a pod that is, and those two rows say so.
 *   2  DOES IT USE A REQUIRED-REASON API — file timestamps, system boot time, disk space, active
 *      keyboards, user defaults? Answered by reading each library's own iOS sources (`.m`, `.mm`,
 *      `.swift`, `.h`, `.c`, `.cpp`) for every symbol on Apple's four lists. A library that uses
 *      one and declares nothing is the one that gets the automated email back after upload.
 *   3  DOES IT COLLECT ANYTHING that would need declaring?
 *
 * WHERE THE DECLARED REASONS COME FROM. Nine of the fifty native dependencies DO ship a manifest —
 * react-native, expo-file-system, expo-constants, expo-device, expo-notifications, expo-application,
 * expo-localization, expo-system-ui and @react-native-async-storage/async-storage — and the union of
 * what THEY declare is what `tools/store/privacy-manifests.cjs` puts in each app's own manifest. The
 * table below is the other 41, and it exists to show that they add nothing to that union.
 *
 * `apple`  false, or a sentence naming the pod that IS on Apple's list.
 * `api`    null when the library's own iOS source touches no required-reason API, else what it uses.
 * `gets`   what the library causes to be collected, in the app's own words. "Nothing" is the answer
 *          for most of them: they are wrappers round a system capability.
 *
 * @type {{ name: string, apple: false | string, api: string | null, gets: string, verdict: string }[]}
 */
const IOS_SDKS = [
  { name: '@expo/dom-webview', apple: false, api: null, gets: 'Nothing', verdict: 'A WKWebView host for Expo DOM components. No required-reason API, nothing collected — no manifest needed.' },
  { name: '@expo/log-box', apple: false, api: null, gets: 'Nothing', verdict: 'The development error overlay. No required-reason API; it never talks to anything — no manifest needed.' },
  { name: '@expo/ui', apple: false, api: null, gets: 'Nothing', verdict: 'SwiftUI views exposed to React. 149 native files, not one required-reason API — no manifest needed.' },
  { name: '@react-native-community/netinfo', apple: false, api: null, gets: 'Nothing about the person', verdict: 'Reads SCNetworkReachability only. BUT its JS half HEAD-requests `clients3.google.com/generate_204` on iOS — see the note under the table. Not a manifest matter; no manifest needed.' },
  { name: '@react-native-masked-view/masked-view', apple: false, api: null, gets: 'Nothing', verdict: 'A CALayer mask. Four native files, no required-reason API — no manifest needed.' },
  { name: 'expo', apple: false, api: null, gets: 'Nothing', verdict: 'The Expo runtime shim round the app delegate. No required-reason API of its own — no manifest needed.' },
  { name: 'expo-asset', apple: false, api: null, gets: 'Nothing', verdict: 'Resolves assets already inside the bundle. No required-reason API — no manifest needed.' },
  { name: 'expo-audio', apple: false, api: null, gets: 'Audio, ONLY while an alert is open and "Share live audio" is on — declared as AudioData', verdict: 'AVAudioRecorder and AVAudioSession (M50 §4.2 #1). Its 17 iOS files searched for every required-reason symbol — UserDefaults, file timestamps, system boot time, disk space, active keyboards — and none is there. It records only while the panic screen\'s switch is on; the microphone purpose string is the declaration it needs — no manifest needed.' },
  { name: 'expo-brightness', apple: false, api: null, gets: 'Nothing', verdict: 'Sets `UIScreen.brightness`. No required-reason API — no manifest needed.' },
  { name: 'expo-camera', apple: false, api: null, gets: 'Camera frames, none kept (gate: camera-frames)', verdict: 'AVFoundation plus a vendored barcode decoder. 720 native files, no required-reason API. The camera is already declared and permissioned — no manifest needed.' },
  { name: 'expo-clipboard', apple: false, api: null, gets: 'Nothing', verdict: 'UIPasteboard. The pasteboard is not a required-reason API (it has its own system banner) — no manifest needed.' },
  { name: 'expo-crypto', apple: false, api: null, gets: 'Nothing', verdict: 'Digests and secure random bytes. No required-reason API — no manifest needed.' },
  /*
   * THE DEVELOPMENT-CLIENT MODULES. `expo-dev-client` pulls in the four below. They exist so a build can
   * be pointed at a developer's machine and reloaded; in a release build the launcher, the menu and the
   * updates interface are inert. None appears on Apple's list of SDKs that are required to ship a privacy
   * manifest, none collects anything from the person, and none is linked to an account. They are declared
   * here rather than left unlisted because an SDK with no verdict is exactly what this table exists to
   * catch — "it is only a development tool" is a judgement somebody should have to write down.
   */
  { name: 'expo-dev-client', apple: false, api: null, gets: 'Nothing', verdict: 'The development client entry point. Inert in a release build; collects nothing. Not on Apple\'s required-manifest list — no manifest needed.' },
  { name: 'expo-dev-launcher', apple: false, api: null, gets: 'Nothing', verdict: 'The screen that picks which development server to load. Reachable only in a development build; collects nothing — no manifest needed.' },
  { name: 'expo-dev-menu', apple: false, api: null, gets: 'Nothing', verdict: 'The shake-to-open developer menu. Reachable only in a development build; collects nothing — no manifest needed.' },
  { name: 'expo-dev-menu-interface', apple: false, api: null, gets: 'Nothing', verdict: 'The protocol the developer menu is registered through. No behaviour of its own and no data — no manifest needed.' },
  { name: 'expo-json-utils', apple: false, api: null, gets: 'Nothing', verdict: 'JSON helpers used by the development client. No required-reason API and no data — no manifest needed.' },
  { name: 'expo-manifests', apple: false, api: null, gets: 'Nothing', verdict: 'Reads and validates the build\'s own manifest so the development client knows what it is running. Reads no personal data — no manifest needed.' },
  { name: 'expo-updates-interface', apple: false, api: null, gets: 'Nothing', verdict: 'The protocol an updates provider registers through. Over-the-air updates are NOT configured for either app, so nothing implements it — no manifest needed.' },
  { name: 'expo-font', apple: false, api: null, gets: 'Nothing', verdict: 'Registers bundled fonts with CoreText. No required-reason API — no manifest needed.' },
  { name: 'expo-glass-effect', apple: false, api: null, gets: 'Nothing', verdict: 'A UIVisualEffectView wrapper. No required-reason API — no manifest needed.' },
  { name: 'expo-haptics', apple: false, api: null, gets: 'Nothing', verdict: 'UIFeedbackGenerator. One native file, no required-reason API — no manifest needed.' },
  { name: 'expo-image', apple: 'Yes, indirectly: its podspec pins `SDWebImage ~> 5.21.0`, and SDWebImage IS on Apple\'s list', api: null, gets: 'Nothing', verdict: 'Its own 301 native files use no required-reason API. SDWebImage has shipped a privacy manifest since 5.18.0, so the pinned 5.21.x carries one — but that pod is not in node_modules, so this repo cannot prove it. Settled by `npx expo prebuild --platform ios` then `pod install`, and reading `ios/Pods/SDWebImage/.../PrivacyInfo.xcprivacy`.' },
  { name: 'expo-image-manipulator', apple: 'Yes, indirectly: it depends on `SDWebImageWebPCoder`, part of the SDWebImage family on Apple\'s list', api: null, gets: 'Nothing', verdict: 'Re-encodes an image the person already chose. No required-reason API of its own; the pod question is the same one expo-image raises and is settled the same way.' },
  { name: 'expo-image-picker', apple: false, api: null, gets: 'The photos the person picks — declared as PhotosorVideos', verdict: 'PHPickerViewController. No required-reason API; the photo library is already declared and permissioned — no manifest needed.' },
  { name: 'expo-keep-awake', apple: false, api: null, gets: 'Nothing', verdict: 'Sets `isIdleTimerDisabled`. One native file — no manifest needed.' },
  { name: 'expo-linear-gradient', apple: false, api: null, gets: 'Nothing', verdict: 'A CAGradientLayer. No required-reason API — no manifest needed.' },
  { name: 'expo-linking', apple: false, api: null, gets: 'Nothing', verdict: 'URL schemes and universal links. No required-reason API — no manifest needed.' },
  { name: 'expo-local-authentication', apple: false, api: null, gets: 'Nothing — no image, template or score ever leaves the phone\'s secure hardware', verdict: 'LocalAuthentication (Face ID / Touch ID). No required-reason API; `NSFaceIDUsageDescription` is the declaration this needs and it is already there — no manifest needed.' },
  { name: 'expo-location', apple: false, api: null, gets: "A position, only when and as the app's own table above says — declared as PreciseLocation", verdict: 'CoreLocation. Location is not a required-reason API (it has purpose strings instead), and the collection is declared above — no manifest needed.' },
  { name: 'unimodules-app-loader', apple: false, api: null, gets: 'Nothing', verdict: 'Brought in by expo-task-manager (which ships its own privacy manifest) so a background task can start the app headless. Its iOS half is two interfaces and one provider class (UMAppLoaderProvider.m) that register and look up an app loader; read in full — no UserDefaults, no file timestamps, no system boot time, no disk space. No required-reason API — no manifest needed.' },
  { name: 'expo-background-task', apple: false, api: null, gets: 'Nothing — it only wakes the app to send what the person already saved', verdict: 'BGTaskScheduler (M11 §1.3). Its eight Swift files read in full: a BGProcessingTaskRequest, its handler and the task records — no UserDefaults, no file timestamps, no system boot time, no disk space. It runs on expo-task-manager, which ships its own privacy manifest for the UserDefaults IT uses. No required-reason API — no manifest needed.' },
  { name: 'expo-battery', apple: false, api: null, gets: 'The battery level and whether it is charging, to sample less often below 20% (the level is sent with each on-duty fix)', verdict: 'UIDevice battery monitoring. Not a required-reason API — no manifest needed.' },
  { name: 'expo-modules-core', apple: 'Yes, indirectly: its podspec depends on `hermes-engine`, and hermes IS on Apple\'s list', api: null, gets: 'Nothing', verdict: 'The module runtime. Its own 316 native files use no required-reason API. hermes ships its own manifest as part of React Native 0.86\'s prebuilt artifacts; not verifiable from node_modules, so it is settled at `pod install` exactly as SDWebImage is.' },
  { name: 'expo-router', apple: false, api: null, gets: 'Nothing', verdict: 'Navigation. Its native half is glue over react-native-screens — no manifest needed.' },
  { name: 'expo-screen-capture', apple: false, api: null, gets: 'Nothing', verdict: 'Reads `UIScreen.isCaptured` and the screenshot notification. Neither is a required-reason API — no manifest needed.' },
  { name: 'expo-secure-store', apple: false, api: null, gets: 'Nothing — it is where the token is kept, not something sent', verdict: 'The Keychain. The Keychain is not a required-reason API (user defaults is; this is not user defaults) — no manifest needed.' },
  { name: 'expo-sharing', apple: false, api: 'UserDefaults — `UserDefaults(suiteName: appGroupId)`, in the share-into extension path', gets: 'Nothing', verdict: 'THE ONLY ONE OF THE 41 THAT TOUCHES A REQUIRED-REASON API. It is covered: `CA92.1` is already declared. The App Group path is not configured in either app — the plugin is listed with no options and neither app holds an application-groups entitlement — so nothing is read or written through a group. IF share-into is ever configured, `1C8F.1` must be added beside CA92.1.' },
  { name: 'expo-speech-recognition', apple: false, api: null, gets: 'Nothing — speech is recognised on the phone and only the text reaches the app', verdict: 'SFSpeechRecognizer and AVAudioEngine (M12 §2.9). Its eight Swift files read in full: no UserDefaults, no file timestamps, no system boot time, no disk space. It writes audio to the caches directory ONLY when `recordingOptions.persist` is set, which neither app ever passes, and the apps always start it with `requiresOnDeviceRecognition`. The microphone and speech-recognition purpose strings are the declarations it needs — no manifest needed.' },
  { name: 'expo-splash-screen', apple: false, api: null, gets: 'Nothing', verdict: 'The launch screen. No required-reason API — no manifest needed.' },
  { name: 'expo-symbols', apple: false, api: null, gets: 'Nothing', verdict: 'SF Symbols. No required-reason API — no manifest needed.' },
  { name: 'jail-monkey', apple: false, api: null, gets: 'Whether the phone is jailbroken or the clock is trusted — a flag, not a person', verdict: 'Checks for known jailbreak paths with `fileExistsAtPath:`, which is NOT a required-reason API (only file TIMESTAMPS are) — no manifest needed.' },
  { name: 'react-native-gesture-handler', apple: false, api: null, gets: 'Nothing', verdict: 'UIGestureRecognizer. 55 native files, no required-reason API — no manifest needed.' },
  { name: 'react-native-keyboard-controller', apple: false, api: null, gets: 'Nothing', verdict: 'Checked hardest of the 41, because "active keyboards" is a required-reason API and this is the library that would use it: `UITextInputMode.activeInputModes` appears nowhere in its 100 native files. It observes the keyboard FRAME, not which keyboards are installed — no manifest needed.' },
  { name: 'react-native-mmkv', apple: false, api: null, gets: 'Nothing', verdict: 'Its own 13 native files use no required-reason API, but it depends on the pod `MMKVCore 2.4.0`, which is not in node_modules. MMKVCore is NOT on Apple\'s required list; whether it ships a manifest is UNDETERMINED here and is settled at `pod install` by reading `ios/Pods/MMKVCore/.../PrivacyInfo.xcprivacy`.' },
  { name: 'react-native-nitro-modules', apple: false, api: null, gets: 'Nothing', verdict: 'JSI bridging. No required-reason API — no manifest needed.' },
  { name: 'react-native-reanimated', apple: 'Yes, indirectly: it depends on `React-hermes`, the same engine on Apple\'s list', api: null, gets: 'Nothing', verdict: 'Animation worklets. 273 native files, no required-reason API. The hermes question is React Native\'s own and is the one expo-modules-core raises.' },
  { name: 'react-native-safe-area-context', apple: false, api: null, gets: 'Nothing', verdict: 'Safe-area insets. No required-reason API — no manifest needed.' },
  { name: 'react-native-screens', apple: false, api: null, gets: 'Nothing', verdict: 'UIViewController-backed screens. 349 native files, no required-reason API — no manifest needed.' },
  { name: 'react-native-share', apple: false, api: null, gets: 'Nothing — it hands the pass picture the resident chose to send to the share sheet, and the resident picks the app', verdict: 'Resident App only (M12 §2.8), used on Android for one call, `Share.open`, so a picture and its caption go together. Its 32 iOS files read for the required-reason symbols: no UserDefaults, no file timestamps, no system boot time, no disk space, no keyboards. The per-app paths (Instagram, Facebook, Discord…) are never called, and it holds no permission — no manifest needed.' },
  { name: 'react-native-ssl-public-key-pinning', apple: false, api: null, gets: 'Nothing', verdict: 'Wraps the `TrustKit` pod (not on Apple\'s list, not in node_modules — its own manifest is UNDETERMINED here). What IS determined and matters more: the wrapper sets `kTSKDisableDefaultReportUri = YES` on every pinned domain, so a pin failure is never reported to anybody\'s server.' },
  { name: 'react-native-svg', apple: false, api: null, gets: 'Nothing', verdict: 'CoreGraphics SVG rendering. 341 native files, no required-reason API — no manifest needed.' },
  { name: 'react-native-worklets', apple: 'Yes, indirectly: it depends on `React-hermes`, the same engine on Apple\'s list', api: null, gets: 'Nothing', verdict: 'The worklets runtime. 91 native files, no required-reason API. Same hermes question as expo-modules-core.' },
]

module.exports = { RESIDENT, GUARD, RESIDENT_NOT_COLLECTED, GUARD_NOT_COLLECTED, IOS_SDKS }

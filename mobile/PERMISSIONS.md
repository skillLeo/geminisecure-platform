# PERMISSIONS — every permission the app holds, and why

Generated facts, human reasons. The list of what is actually IN the app comes from `node tools/store/manifest-audit.mjs`
(it evaluates every Expo config plugin and walks the real library manifests — `store/manifests/resident.permissions.json`).
`gate:store` fails if the merged manifest holds a permission that has no row here, if an iOS purpose string is missing or
generic, or if a foreground service or an iOS background mode is declared that this document does not name and defend.

This is the record for **GeminiSecure** (the Resident App, `jm.geminisecure.resident`), the app that goes to the stores.

**Rules the app follows**
- **Nothing is asked at launch.** The phone's own question appears only after the person has tapped the thing that needs it.
  No screen raises a permission dialog from a `useEffect` on mount.
- **The reason is on screen BEFORE the phone asks** (the "prominent disclosure"): a sentence saying what is used, why, and that
  it is not collected in the background — with an explicit tap to continue: "Before we get started"
  (`apps/resident/src/features/auth/screens/PermissionsScreen.tsx`, route `/permissions`).
- **Refusing never locks anyone out.** A refused permission blocks only the feature that needs it; the screen says so and
  offers the phone's Settings. The alert button works without any of them.
- **No background location. One foreground service, the microphone's — only for live audio during an open alert.** The
  microphone to speak a request or a report, recognised on the phone and never recorded or sent to a speech service (M12
  §2.9); and, only while an emergency alert is open with its "Share live audio" switch on, to send what it hears to the
  estate's security control room, a few seconds at a time, going on with the app in the background (M50 §4.2 #1). No
  contacts. No broad photo or storage access. What is not held is *removed* from the merged manifest
  (`android.blockedPermissions`, and `tools/expo-plugins/withoutLocationService.cjs` strips the location foreground service a
  library declares).

## Resident App — `jm.geminisecure.resident`

| Permission (Android · iOS) | The feature that needs it | Where it is first asked, and what is said first | Required? |
|---|---|---|---|
| `CAMERA` · `NSCameraUsageDescription` | Taking a photo to attach to a maintenance request or a report | The "Before we get started" screen after first sign-in (row "Camera — for photos you choose to add…"; the phone asks only when that row is tapped), or the first tap on "Take photo" | Optional — the request or report goes without a photo |
| *(no Android permission — the system photo picker)* · `NSPhotoLibraryUsageDescription` | Choosing an existing photo to attach | The first tap on "Choose photo". Android uses the system Photo Picker, which needs no permission; iOS shows only the photos the person picks | Optional |
| `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` · `NSLocationWhenInUseUsageDescription` | Adding where you are to an alert, and to an incident report — automatically: Report incident reads the position once when it opens and sends it with that report (M42 §5 #15) | The "Before we get started" screen (row "Location — added automatically when you send an alert or report an incident… never tracked in the background"), or Settings → Privacy & Data | Optional — the alert and the report are sent without a position |
| `POST_NOTIFICATIONS` · *(iOS notification permission)* | Telling you a visitor is at the gate, a notice, a reply to a request | The "Before we get started" screen (row "Notifications"), or Settings → Notifications | Optional — never a condition of using the app |
| `USE_BIOMETRIC`, `USE_FINGERPRINT` · `NSFaceIDUsageDescription` | The app lock, if the resident turns it on | Settings → Security → App lock. The check is the phone's own; nothing reaches the app | Optional |
| `RECORD_AUDIO` · `NSMicrophoneUsageDescription`, `NSSpeechRecognitionUsageDescription` | **Speaking a maintenance request or a security report instead of typing it (M12 §2.9).** Recognised **on the phone** (`requiresOnDeviceRecognition`, always — `packages/core/speech/dictation.ts`): nothing is recorded, and nothing is sent to a speech service, so it works with no connection. On Android the offline English model is a one-time download the screen offers, never assumed | The first tap on the microphone on the request or report screen — never at launch. The screen draws the microphone with "Tap to speak" beside it; the phone's dialog appears only on that tap | Optional — refused, the screen says so and the text is typed |
| `RECORD_AUDIO` (the same permission) · `NSMicrophoneUsageDescription` | **"Share live audio" on the panic screen (M50 §4.2 #1).** Only while an emergency alert is open and its switch is on, the phone records five seconds at a time and sends each piece to the estate's security control room, which listens on the alert's page (`apps/resident/src/features/panic/LiveAudioHost.native.tsx`). It stops when the switch is turned off or the alert closes. It starts with the alert only if the microphone was ALREADY allowed — the panic screen never raises the phone's question by itself | The switch on the panic screen — the phone asks on that tap, never at launch and never by itself during a panic; the "Before we get started" screen's "Camera & microphone" row names it | Optional — refused, the switch stays off, says so, and offers the phone's Settings; the alert goes exactly as before |
| `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_MICROPHONE`, and expo-audio's recording service element | **Live audio goes on with the app in the background** (M50 §4.2 #1: "it survives backgrounding"): Android shows its notification the whole time the microphone is in use. Running only between the switch going on and going off, inside an open alert | Not asked — the microphone permission above is the question | — |
| `MODIFY_AUDIO_SETTINGS` | Added by `expo-audio` to set the phone's audio session for recording live audio | Never asked (not a runtime permission) | — |
| `UIBackgroundModes: audio` | **Live audio goes on with the app in the background** on an iPhone (M50 §4.2 #1) — only while an alert is open and "Share live audio" is on. The app plays nothing in the background | Not a permission — nobody is asked | — |
| `UIBackgroundModes: fetch` | Added by `expo-task-manager`, the runtime the background send below runs in; nothing else is scheduled with it | Not a permission — nobody is asked | — |
| `UIBackgroundModes: processing` · `BGTaskSchedulerPermittedIdentifiers` · Android WorkManager | **Sending what was saved with no signal, with the app closed (M11 §1.3).** `expo-background-task` asks the OS to wake the app — at most every fifteen minutes, and on Android only while the phone is connected — to send the queue: maintenance photos, requests, reports. It reads nothing and collects nothing; it sends only what the person already did. A resident's session still closes after thirty minutes idle, and a send after that waits for them to sign back in (`packages/core/offline/backgroundSync.ts`) | Not a permission — nobody is asked | — |
| `VIBRATE` | A buzz when the alert is sent and on errors | Never asked (not a runtime permission) | — |
| `INTERNET`, `ACCESS_NETWORK_STATE` | Talking to the server; knowing whether the phone is offline so nothing is faked | Never asked (not runtime permissions) | — |
| `RECEIVE_BOOT_COMPLETED` | Added by the notifications library so scheduled local notifications survive a restart | Never asked | — |
| `DETECT_SCREEN_CAPTURE` | Added by the screen-capture library; the app uses that library only to *refuse* a screenshot of a ballot | Never asked (not a runtime permission) | — |

iOS purpose strings (exact text, from `apps/resident/app.config.ts`):
- **Camera** — "GeminiSecure uses the camera when you choose to add a photo to a maintenance request or a report."
- **Photos** — "GeminiSecure attaches the photos you choose to a maintenance request or a report. It cannot see any other photo."
- **Location (when in use)** — "GeminiSecure uses your location when you send an alert or report an incident, and adds it to the alert or the report automatically, so the security officers on duty in your community can find you. It is never collected in the background."
- **Face ID** — "GeminiSecure uses Face ID to unlock the app if you turn the app lock on. The check is done by your iPhone; your face never leaves it."
- **Microphone** — "GeminiSecure uses the microphone when you tap it to speak a maintenance request or a report — your words are turned into text on your phone and are not recorded — and, while you have an emergency alert open with "Share live audio" on, to send what it hears to your estate's security control room."
- **Speech recognition** — "GeminiSecure turns what you say into text on your phone when you choose to speak a maintenance request or a report. It is recognised on the phone itself and never sent to a speech service."

## Deliberately NOT held — and removed if a library tries to add them

| Permission | Why it is not needed |
|---|---|
| `ACCESS_BACKGROUND_LOCATION` · "Always" location on iOS | The app reads location only while it is open, at the moment of an act |
| `FOREGROUND_SERVICE_LOCATION`, `FOREGROUND_SERVICE_MEDIA_PLAYBACK`, the location service element and expo-audio's playback service | No location or playback service: the one foreground service is the microphone's, for live audio during an open alert (above) |
| `USE_EXACT_ALARM` / `SCHEDULE_EXACT_ALARM` | Not needed: nothing is scheduled on the phone |
| `READ_MEDIA_IMAGES` / `READ_MEDIA_VIDEO` / `READ_MEDIA_VISUAL_USER_SELECTED` / `READ_EXTERNAL_STORAGE` / `WRITE_EXTERNAL_STORAGE` / `ACCESS_MEDIA_LOCATION` | Photos come through the **system Photo Picker**; files the app keeps are inside its own storage |
| `READ_CONTACTS` / `WRITE_CONTACTS` | An emergency contact is typed in; the app never reads the address book |
| `WRITE_SETTINGS` | The brightness library adds it for *system* brightness; the pass screen only brightens its own window |
| `SYSTEM_ALERT_WINDOW`, `READ_PHONE_STATE` | Never used (the first is a development-only overlay) |
| `ACCESS_WIFI_STATE` | **Blocked.** The connectivity library declares it and that library IS used — but only for "is there a connection" and "can the internet be reached", both of which come from `ACCESS_NETWORK_STATE`. Nothing reads a network name, a hardware address, a signal strength or a link speed. Removing the unused network package did NOT remove the permission, which is why it is blocked by name. Safe: the library guards every Wi-Fi call with a runtime permission check and simply omits those fields without it |
| `ACCESS_NOTIFICATION_POLICY` | **Not declared, and must never be.** A homeowner silencing their own phone is their own choice; the "Safety alerts" channel is created without a Do Not Disturb bypass |
| `NSLocationAlways…`, `NSLocationAlwaysUsageDescription` and `NSMotionUsageDescription` | The location plugin's generic defaults ("Allow $(PRODUCT_NAME) to access your location") — switched off, so no generic string and no unused purpose reaches App Review |

## iOS entitlements — what the app declares beyond the purpose strings

| Entitlement | Why | Where |
|---|---|---|
| `aps-environment` | **Not ours — written by `expo-notifications`, and listed here because every entitlement a signed build carries is written down.** It is the standard Apple Push Notification entitlement: without it the app cannot be issued a push token at all, and none of the notifications in the table above could be delivered. Its value is the only thing in these declarations that is NOT decided by this repo — Expo sets `development` for a development build and `production` for a store build, from the EAS build profile, which is why `gate:store` compares every other declaration against the snapshot but not this one | written by the `expo-notifications` config plugin into `ios.entitlements`; visible in `store/manifests/resident.permissions.json` |
| `com.apple.developer.usernotifications.time-sensitive` | **A visitor waiting at the gate for the resident's answer is the textbook Time Sensitive case.** The server sends `interruptionLevel: 'time-sensitive'` on every urgent push, the gate approval request among them (`app/Jobs/Push/SendExpoPush.php`, `PushTypes`). iOS honours that level **only** for an app carrying this entitlement; without it a Focus mode — Sleep, Driving, Work, and the bed-time schedule most iPhones turn on by themselves — holds those pushes back with everything else. It grants nothing beyond the right to mark a notification urgent, and the person can still turn Time Sensitive notifications off | `apps/resident/app.config.ts`, `ios.entitlements` |

**What the client must do in their Apple Developer account, once per App ID — this cannot be done from the repo.**
There are two App IDs (`jm.geminisecure.resident`, `jm.geminisecure.resident.dev`):

1. Sign in to the Apple Developer portal → **Certificates, Identifiers & Profiles → Identifiers** → the App ID.
2. Under **Additional Capabilities**, tick **Time Sensitive Notifications**, and save.
3. Regenerate the provisioning profile for that App ID. With EAS this is `eas credentials` (or simply the next `eas build`, which
   syncs capabilities when it is signed in to the Apple account); the profile must be reissued *after* the capability is on.

If the capability is not on the App ID, the signed build is **rejected at upload** with a missing-entitlement error — it does not
fail quietly, so it cannot reach a phone in that state.

## Do Not Disturb on Android — the one thing that is fixed at first install

An Android notification channel's configuration is **fixed the moment a phone creates it**. Importance, lock-screen visibility and
the Do Not Disturb bypass are set when the channel first appears and the app cannot change them afterwards.

| | |
|---|---|
| Safety channel importance | MAX, public on the lock screen, own vibration |
| `bypassDnd` on that channel | **not set** |
| `ACCESS_NOTIFICATION_POLICY` in the manifest | **not declared, and must never be** |

The app never *claims* a bypass: it reads the channel back out of Android and reports what it finds (`safetyChannelDnd`).

The channel **id** (`safety`) is the id the server puts on every urgent push (`App\Services\Push\PushTypes::SAFETY`). Release
APKs are on client phones, so `safety` is now fixed on them exactly as those builds created it, and nothing may count on
changing it.

## Still to do before a store build

- **The libraries that carried a permission nobody needed are gone.** `expo-network` left the app. `gate:store` check 1b fails
  if a permission's every source library is imported by nothing, or if a permission this document asserts nobody needs is still
  merged — so this cannot silently come back.
- **The current merged count is in `store/manifests/resident.permissions.json`** (with `RECORD_AUDIO` for speech recognised on
  the phone and for live audio during an open alert, and since M50 §4.2 #1 the microphone foreground service). The app holds
  no background location, no location or playback service, no contacts, and no broad photo or storage access.
- **The model under-counts, and that is known.** `tools/store/manifest-audit.mjs` reads the npm packages' own manifests; it
  cannot see what Maven artifacts (Firebase, Play services, AndroidX) merge in, such as `WAKE_LOCK`,
  `com.google.android.c2dm.permission.RECEIVE` and the launcher-badge set. Before a store submission those must be written into
  this document from `aapt dump permissions <apk>`.
- Two libraries nothing imports are still installed, deliberately, because **neither contributes a permission**
  (`node tools/store/unused-native.mjs`): jail-monkey and react-native-ssl-public-key-pinning. Certificate pinning and the
  rooted-device check are wired when the production certificate is final, so they wait on the client, not on tidying.
- When the release build exists: `aapt dump permissions <apk>` must equal the merged list in `store/manifests/resident.permissions.json`.

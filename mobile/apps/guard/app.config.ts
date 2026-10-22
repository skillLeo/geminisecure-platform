import type { ExpoConfig } from 'expo/config'
// eslint-disable-next-line @typescript-eslint/no-require-imports
const { privacyManifest } = require('../../tools/store/privacy-manifests.cjs') as { privacyManifest: (app: 'guard' | 'resident') => NonNullable<NonNullable<ExpoConfig['ios']>['privacyManifests']> }

/**
 * GeminiSecure Guard — DARK ONLY. A white screen at 2am destroys night vision and
 * marks the guard's position from the road, so userInterfaceStyle is 'dark' and
 * nothing in this app follows the system theme.
 *
 * APP_VARIANT=development gives the dev build its own id, so it sits beside the
 * production app on one phone.
 */

const IS_DEV = process.env.APP_VARIANT === 'development'
// eslint-disable-next-line @typescript-eslint/no-require-imports
const { HEADLESS_LOADER_KEEP } = require('../../tools/expo-plugins/headlessLoader.cjs') as { HEADLESS_LOADER_KEEP: string }

// The Google Maps SDK key for Android, if this build has one (M11 §6). Not a secret in the APK, but never in the repo.
const MAPS_KEY = (process.env.GOOGLE_MAPS_ANDROID_KEY ?? '').trim()

// A store build pointed at nothing — or at plain http — must fail HERE, on the build machine, not on a guard's phone at 2am.
// Every profile whose name STARTS WITH production, not only `production` itself: `production-apk` is the same
// binary pointed at the same server, handed to people directly instead of through a store, and a check that
// skipped it would let exactly the build a real guard installs be the one nobody verified (M7).
if ((process.env.EAS_BUILD_PROFILE ?? '').startsWith('production') && !/^https:\/\/[^/]+\/api\/v1\/?$/.test(process.env.EXPO_PUBLIC_API_URL ?? '')) {
  throw new Error('Production build: set EXPO_PUBLIC_API_URL to the live API, e.g. https://<host>/api/v1 (eas env:create --environment production).')
}

const FONT_DIR = '../../packages/core/assets/fonts'
const FONT_FILES = [
  'Poppins-Medium',
  'Poppins-SemiBold',
  'Poppins-Bold',
  'Inter-Regular',
  'Inter-Medium',
  'Inter-SemiBold',
  'Inter-Bold',
  'Inter-Italic',
  'IBMPlexMono-Regular',
  'IBMPlexMono-Medium',
].map((f) => `${FONT_DIR}/${f}.ttf`)

// Dictating an incident report (M12 §2.9; Build Spec GD-8): the two purpose strings, one wording for infoPlist and the plugin.
const MICROPHONE_PURPOSE = 'GeminiSecure Guard uses the microphone only after you tap it to dictate an incident report. Your words are turned into text on the phone; nothing is recorded or sent to a speech service.'
const SPEECH_PURPOSE = 'GeminiSecure Guard turns what you say into text on the phone when you choose to dictate an incident report. It is recognised on the phone itself and never sent to a speech service.'

const config: ExpoConfig = {
  name: IS_DEV ? 'GS Guard (dev)' : 'GeminiSecure Guard',
  slug: 'geminisecure-guard',
  // The Expo account the project belongs to (extra.eas.projectId below): the client's team, not a person's.
  owner: 'gemini-secures-team',
  // The store version (M17 §3.3): the first public release is 1.0.0, not the 0.1.0 of the test builds — a listing
  // that reads "0.1.0" reads as a beta. The build number is EAS's own (eas.json appVersionSource remote, autoIncrement).
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/images/icon.png',
  scheme: 'gsguard',
  userInterfaceStyle: 'dark',
  backgroundColor: '#081422',
  ios: {
    bundleIdentifier: IS_DEV ? 'jm.geminisecure.guard.dev' : 'jm.geminisecure.guard',
    supportsTablet: false,
    // Export compliance: the app uses only standard cryptography — HTTPS, and Ed25519 SIGNATURE VERIFICATION of visitor
    // passes (authentication, not encryption of content). Recommended answer: exempt. The determination is the company's legal
    // call; this value matches the recommendation so every upload stops asking.
    config: { usesNonExemptEncryption: false },
    // PrivacyInfo.xcprivacy: no tracking; what is collected and why; the required-reason APIs the libraries use.
    // One description feeds this, the App Store labels and Google's Data Safety form (PRIVACY_DECLARATIONS.md).
    privacyManifests: privacyManifest('guard'),
    /*
     * TIME SENSITIVE NOTIFICATIONS. The server already sends `interruptionLevel: 'time-sensitive'`
     * on a panic, another officer's duress and an alertness check (`app/Jobs/Push/SendExpoPush.php`), and iOS
     * honours that ONLY for an app carrying this entitlement. Without it a Focus mode — Sleep, Driving, Work —
     * holds back the highest-priority push in the product, silently, which is the one a guard cannot miss.
     * A duress alarm at a guarded estate is the case Apple describes the level for.
     *
     * The capability must also be switched on for the App ID in the Apple Developer account before a build can
     * be signed with it: PERMISSIONS.md ("iOS entitlements") and STORE_COMPLIANCE.md row 27 say exactly how.
     */
    entitlements: { 'com.apple.developer.usernotifications.time-sensitive': true },
    // Every purpose string names the app and the exact reason. PERMISSIONS.md lists each one; gate:store holds them to it.
    // The microphone (M12 §2.9): only after the officer taps it to dictate a report, recognised on the phone.
    infoPlist: {
      NSMicrophoneUsageDescription: MICROPHONE_PURPOSE,
      NSSpeechRecognitionUsageDescription: SPEECH_PURPOSE,
      NSCameraUsageDescription: 'GeminiSecure Guard uses the camera to scan visitor passes and patrol checkpoint codes, and to photograph an incident for your report. No picture is kept while scanning.',
      // THE LOCATION STRINGS (M11 §3; D-174): on duty, with consent, the officer's location is also shared in the background
      // — a deliberate change to the profile M6 recorded, made with the client's agreement. Written out here AND in the
      // expo-location plugin below, word for word (a plugin's default would otherwise replace them), because
      // tools/gates/location-discipline.mjs reads each literal against what the code actually reads.
      NSLocationWhenInUseUsageDescription: 'GeminiSecure Guard checks your location when you start or end a shift, report an incident or raise an alert — to confirm you are at your post and so help can find you. While you are on duty, and only if you agree, it also shares your location with the control room until you clock out. Off duty it is never collected.',
      NSLocationAlwaysAndWhenInUseUsageDescription: 'While you are on duty — from clock-in to clock-out, and only if you agree — GeminiSecure Guard shares your location with the control room, also while the app is in the background or closed, so dispatch can send the nearest officer and find you if you need help. It stops when you clock out.',
      NSFaceIDUsageDescription: 'GeminiSecure Guard uses Face ID to confirm it is really you starting the shift. The check is done by your iPhone; your face never leaves it.',
      NSPhotoLibraryUsageDescription: 'GeminiSecure Guard attaches the photos you choose to an incident report. It cannot see any other photo.',
      // Added by the development client so a test build can find a developer's machine on the same
      // Wi-Fi. Nothing in the shipped app browses the local network; the string is written here rather
      // than left as a library default so the prompt, if it ever appears, names the app and says why.
      NSLocalNetworkUsageDescription: 'GeminiSecure Guard uses the local network only to connect a test build to a development machine on the same Wi-Fi. The released app does not use it.',
    },
  },
  android: {
    package: IS_DEV ? 'jm.geminisecure.guard.dev' : 'jm.geminisecure.guard',
    /*
     * FIREBASE, FOR ANDROID PUSH: without it `getExpoPushTokenAsync` fails on Android and no panic
     * ever rings this handset. The resident app's config says why in full; this is the same file
     * (the gemini-a4ded project names both packages), and never for the `.dev` package, which it
     * does not name.
     */
    ...(IS_DEV ? {} : { googleServicesFile: './google-services.json' }),
    /*
     * GOOGLE MAPS ON ANDROID (M11 §6). The Maps SDK draws nothing without a key, so the key is a build-time value
     * (GOOGLE_MAPS_ANDROID_KEY — an environment variable, never this file) and the app is told whether it has one
     * (`extra.maps.android`): a build without one gives every place in words, never a grey square
     * (packages/core/ui/SiteMap.tsx; QUESTIONS MAPS-01 names the key and who makes it). iOS draws Apple Maps and
     * needs no key.
     */
    ...(MAPS_KEY !== '' ? { config: { googleMaps: { apiKey: MAPS_KEY } } } : {}),
    adaptiveIcon: {
      backgroundColor: '#081422',
      foregroundImage: './assets/images/android-icon-foreground.png',
      monochromeImage: './assets/images/android-icon-monochrome.png',
    },
    allowBackup: false,
    predictiveBackGestureEnabled: false,
    /*
     * ACCESS_NOTIFICATION_POLICY — the GUARD APP ONLY. The Resident App
     * does not declare it and must not: a resident silencing their own phone is their own choice.
     *
     * It is what makes the "Safety alerts" channel's Do Not Disturb bypass real. Without it Android
     * drops that flag in silence, and a duress alarm — the officer's own, another officer's, or a
     * resident's panic at the post they are standing on — is held back by a Focus or bed-time mode the
     * handset turned on by itself. This is an EMPLOYER-ISSUED handset carried on duty, and a lone
     * officer under threat is the case the permission exists for. The justification is written out for
     * the reviewer PRE-EMPTIVELY in STORE_REVIEW_NOTES.md and PERMISSIONS.md.
     *
     * Declaring it is not granting it: it is a special access the guard grants in the phone's own
     * Settings, and the app only ever opens that screen — never changes anybody's Do Not Disturb. The
     * ASK sits behind `flags.askForDndAccess` (default on), so if a reviewer objects it stops being
     * asked for with a JS update and no new binary.
     *
     * USE_FULL_SCREEN_INTENT and REQUEST_IGNORE_BATTERY_OPTIMIZATIONS — the CALL-STYLE RING (modules/guard-ring).
     * A panic at the guard's estate rings the phone like a call, over the lock screen, until somebody answers:
     * the full-screen intent is what puts the ring screen up on a locked phone (Android 14+ lets the guard switch
     * it off; the app checks and says so), and the battery exemption is asked for in plain words because battery
     * saving can hold a panic back for minutes while the phone is idle. A safety app is the use Android's own
     * policy names for both. Neither is a foreground service and neither runs anything in the background by
     * itself. The ring's config plugin refuses to build an app that does not list both here.
     *
     * ACCESS_BACKGROUND_LOCATION, FOREGROUND_SERVICE and FOREGROUND_SERVICE_LOCATION — ON-DUTY LOCATION SHARING
     * (M11 §3; D-174). From clock-in to clock-out, and only after the officer agreed to the location policy on the
     * consent screen, a location foreground service runs with a permanent notification ("On duty · location shared
     * with control room") so the control room can dispatch the nearest officer and find a lone officer who needs
     * help. It stops at clock-out, and the server refuses any position taken outside the shift. An EMPLOYER-ISSUED
     * handset carried on duty; STORE_REVIEW_NOTES.md and PERMISSIONS.md carry the reviewer's justification.
     */
    // RECORD_AUDIO: dictating an incident report (M12 §2.9), asked for only when the microphone is tapped.
    permissions: ['CAMERA', 'ACCESS_FINE_LOCATION', 'ACCESS_COARSE_LOCATION', 'ACCESS_BACKGROUND_LOCATION', 'FOREGROUND_SERVICE', 'FOREGROUND_SERVICE_LOCATION', 'USE_BIOMETRIC', 'USE_FINGERPRINT', 'VIBRATE', 'ACCESS_NOTIFICATION_POLICY', 'USE_FULL_SCREEN_INTENT', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS', 'RECORD_AUDIO'],
    // What a library might add and this app never uses is REMOVED from the merged manifest: no
    // broad photo/media or storage access (photos come through the system photo picker), no contacts, no drawing
    // over other apps. PERMISSIONS.md has the reason for each.
    blockedPermissions: [
      'android.permission.READ_EXTERNAL_STORAGE',
      'android.permission.WRITE_EXTERNAL_STORAGE',
      'android.permission.READ_MEDIA_IMAGES',
      'android.permission.READ_MEDIA_VIDEO',
      'android.permission.READ_MEDIA_AUDIO',
      'android.permission.READ_MEDIA_VISUAL_USER_SELECTED',
      'android.permission.ACCESS_MEDIA_LOCATION',
      'android.permission.READ_CONTACTS',
      'android.permission.WRITE_CONTACTS',
      'android.permission.SYSTEM_ALERT_WINDOW',
      // expo-brightness adds it for SYSTEM brightness; the pass screen only brightens its own window, which needs no permission.
      'android.permission.WRITE_SETTINGS',
      'android.permission.READ_PHONE_STATE',
      // Declared by @react-native-community/netinfo, which IS used — but only for `isConnected` and
      // `isInternetReachable`, both of which come from ACCESS_NETWORK_STATE. Nothing in this app reads an
      // SSID, a BSSID, a signal strength or a link speed, so a reviewer asking "why does a gate app want
      // to know my Wi-Fi state?" would have no good answer. Safe to block: netinfo guards every WifiManager
      // call with a runtime permission check and simply omits those fields without it.
      'android.permission.ACCESS_WIFI_STATE',
    ],
  },
  web: { output: 'single', favicon: './assets/images/favicon.png', bundler: 'metro' },
  plugins: [
    'expo-router',
    ['expo-splash-screen', { backgroundColor: '#081422', image: './assets/images/splash-icon.png', imageWidth: 96 }],
    ['expo-font', { fonts: FONT_FILES }],
    'expo-secure-store',
    'expo-localization',
    'expo-sharing',
    'expo-notifications',
    // M11 §1.3: what was saved at a post with no signal is sent once a connection is back, even with the app closed
    // (iOS: the `processing` background mode and its task identifier; Android: a WorkManager job that runs only when connected).
    'expo-background-task',
    // The call-style ring: takes expo-notifications' messaging-service slot with its own subclass, and declares the
    // ring screen and its receiver in THIS manifest, where gate:store sees them (modules/guard-ring/plugin).
    './modules/guard-ring/app.plugin.js',
    // The plugins write the same purpose strings as infoPlist above (a plugin's default would otherwise replace them); `false` = never add the key.
    // The microphone string is the DICTATION one (M12 §2.9), never `false` here: `false` makes these two plugins delete the
    // key after the speech plugin has written it, and iOS ends the app at the first tap on the microphone.
    ['expo-image-picker', { photosPermission: 'GeminiSecure Guard attaches the photos you choose to an incident report. It cannot see any other photo.', cameraPermission: 'GeminiSecure Guard uses the camera to scan visitor passes and patrol checkpoint codes, and to photograph an incident for your report. No picture is kept while scanning.', microphonePermission: MICROPHONE_PURPOSE }],
    ['expo-camera', { cameraPermission: 'GeminiSecure Guard uses the camera to scan visitor passes and patrol checkpoint codes, and to photograph an incident for your report. No picture is kept while scanning.', microphonePermission: MICROPHONE_PURPOSE, recordAudioAndroid: false }],
    // Dictation, on the phone (M12 §2.9). Nothing is sent to either package — `requiresOnDeviceRecognition` always.
    ['expo-speech-recognition', { microphonePermission: MICROPHONE_PURPOSE, speechRecognitionPermission: SPEECH_PURPOSE, androidSpeechServicePackages: ['com.google.android.as', 'com.google.android.tts'] }],
    // On duty, with consent (M11 §3): background location and its foreground service, on iOS the `location` background mode.
    ['expo-location', { locationWhenInUsePermission: 'GeminiSecure Guard checks your location when you start or end a shift, report an incident or raise an alert — to confirm you are at your post and so help can find you. While you are on duty, and only if you agree, it also shares your location with the control room until you clock out. Off duty it is never collected.', locationAlwaysAndWhenInUsePermission: 'While you are on duty — from clock-in to clock-out, and only if you agree — GeminiSecure Guard shares your location with the control room, also while the app is in the background or closed, so dispatch can send the nearest officer and find you if you need help. It stops when you clock out.', locationAlwaysPermission: false, motionUsagePermission: false, isAndroidBackgroundLocationEnabled: true, isAndroidForegroundServiceEnabled: true, isIosBackgroundLocationEnabled: true }],
    // R8 does not fit in the template's 512 MB of Metaspace, and the way it fails is a silent
    // twenty-minute stall rather than an error. See the plugin.
    '../../tools/expo-plugins/withGradleMemory.cjs',
    // A release is signed with the GeminiSecure key `guard`, never the debug one (M33 §2). See the plugin.
    ['../../tools/expo-plugins/withReleaseSigning.cjs', { alias: 'guard' }],
    ['expo-local-authentication', { faceIDPermission: 'GeminiSecure Guard uses Face ID to confirm it is really you starting the shift. The check is done by your iPhone; your face never leaves it.' }],
    [
      'expo-build-properties',
      {
        android: {
          enableProguardInReleaseBuilds: true,
          enableShrinkResourcesInReleaseBuilds: true,
          // A background task's JavaScript is started by the loader the manifest NAMES: without this R8 removes it, and
          // nothing is ever sent with the app closed (tools/expo-plugins/headlessLoader.cjs, D-M163).
          extraProguardRules: HEADLESS_LOADER_KEEP,
          usesCleartextTraffic: IS_DEV,
          /*
           * 64-BIT ARM ONLY. By default an APK carries native code for four processor types
           * (arm64-v8a, armeabi-v7a, x86 and x86_64), and that native code is most of the file.
           * Every Android phone sold in years is arm64-v8a, and Google Play has required 64-bit
           * since 2019, so the other three are dead weight for a real handset. Dropping them is
           * the single biggest size saving there is. A store build (an app bundle) is split per
           * device by Google anyway; this matters most for an APK sent to somebody directly.
           *
           * NOT ON AN x86_64 EMULATOR, and that was found by trying it. The emulator advertises ARM
           * translation, but React Native's native loader looks for the emulator's OWN chip inside the
           * APK and crashes when only arm64 is there - even installed with --abi arm64-v8a. The APK was
           * correct (lib/arm64-v8a/libreactnative.so is in it); the translation layer simply does not
           * reach this app. So a build meant to be RUN on the emulator sets BUILD_ARCHS=x86_64 (the
           * `emulator` profile in eas.json). The JavaScript is byte-for-byte the same either way, so
           * what passes on the emulator is what ships to a phone.
           */
          buildArchs: (process.env.BUILD_ARCHS ?? 'arm64-v8a').split(',').map((s) => s.trim()).filter(Boolean),
        },
      },
    ],
  ],
  experiments: { typedRoutes: true, reactCompiler: true },
  extra: {
    surface: 'guard',
    // Whether this build can draw a map on Android (a key was given at build time; see android.config above).
    maps: { android: MAPS_KEY !== '' },
    /*
     * THE EAS PROJECT AN EXPO PUSH TOKEN IS ISSUED UNDER.
     *
     * `getExpoPushTokenAsync` needs it, and this config is a FUNCTION, so `eas init`
     * cannot write it in the way it writes it into a static app.json — it printed the id
     * and said "Cannot automatically write to dynamic config", so it is written in here
     * by hand. It is NOT a secret: it is the public name of the project on Expo, it
     * appears in every build log, and an environment variable still overrides it so a
     * fork or a second account needs no code change.
     *
     * The client's own team account since 2026-09-25 (it was `hassam571`'s e7e06319… before;
     * no APK could register a push token then, so no token in the field belongs to the old one):
     *   https://expo.dev/accounts/gemini-secures-team/projects/geminisecure-guard
     * The FCM V1 key Android push is sent with is uploaded to THIS project's credentials.
     *
     * Without an id the app asks for no push token and says exactly that ("no_project")
     * rather than pretending; the in-app notifier still works.
     */
    eas: { projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID ?? 'c5d3f789-d605-4b16-bfce-bbba7502fc59' },
  },
}

export default config

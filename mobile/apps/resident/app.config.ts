import type { ExpoConfig } from 'expo/config'
// eslint-disable-next-line @typescript-eslint/no-require-imports
const { privacyManifest } = require('../../tools/store/privacy-manifests.cjs') as { privacyManifest: (app: 'guard' | 'resident') => NonNullable<NonNullable<ExpoConfig['ios']>['privacyManifests']> }

/**
 * GeminiSecure Resident — light by design, and it follows the system into dark
 * mode (a feature beyond the boards; the boards draw light only).
 *
 * The `gs` scheme belongs to this app: gs://pass/{id}, gs://ticket/{id} and
 * gs://election/{id} are all a resident's. The Guard App uses `gsguard`.
 */

const IS_DEV = process.env.APP_VARIANT === 'development'
// eslint-disable-next-line @typescript-eslint/no-require-imports
const { HEADLESS_LOADER_KEEP } = require('../../tools/expo-plugins/headlessLoader.cjs') as { HEADLESS_LOADER_KEEP: string }

// The Google Maps SDK key for Android, if this build has one (M11 §6). Not a secret in the APK, but never in the repo.
const MAPS_KEY = (process.env.GOOGLE_MAPS_ANDROID_KEY ?? '').trim()

// A store build pointed at nothing — or at plain http — must fail HERE, on the build machine, not in a resident's hand.
// Every profile whose name STARTS WITH production, not only `production` itself: `production-apk` is the same
// binary pointed at the same server, handed to people directly instead of through a store, and a check that
// skipped it would let exactly the build a real resident installs be the one nobody verified (M7).
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

// The microphone, one wording for infoPlist and every plugin: speaking a request or a report instead of typing it (M12
// §2.9), turned into text on the phone; and "Share live audio" while an emergency alert is open (M50 §4.2 #1).
const MICROPHONE_PURPOSE = 'GeminiSecure uses the microphone when you tap it to speak a maintenance request or a report — your words are turned into text on your phone and are not recorded — and, while you have an emergency alert open with "Share live audio" on, to send what it hears to your estate\'s security control room.'
const SPEECH_PURPOSE = 'GeminiSecure turns what you say into text on your phone when you choose to speak a maintenance request or a report. It is recognised on the phone itself and never sent to a speech service.'

const config: ExpoConfig = {
  name: IS_DEV ? 'GS Resident (dev)' : 'GeminiSecure',
  slug: 'geminisecure-resident',
  // The Expo account the project belongs to (extra.eas.projectId below): the client's team, not a person's.
  owner: 'gemini-secures-team',
  // The store version (M17 §3.3): the first public release is 1.0.0, not the 0.1.0 of the test builds — a listing
  // that reads "0.1.0" reads as a beta. The build number is EAS's own (eas.json appVersionSource remote, autoIncrement).
  // 1.0.1 (M50 §8, 8 Oct 2026): the build after the 1.0.0 test builds — household items, Live chat, Helper hours.
  version: '1.0.1',
  orientation: 'portrait',
  icon: './assets/images/icon.png',
  scheme: 'gs',
  userInterfaceStyle: 'automatic',
  backgroundColor: '#FFFFFF',
  ios: {
    bundleIdentifier: IS_DEV ? 'jm.geminisecure.resident.dev' : 'jm.geminisecure.resident',
    supportsTablet: false,
    // Export compliance: standard cryptography only — HTTPS, and Ed25519 signatures on visitor passes (authentication,
    // not encryption of content). Recommended answer: exempt; the determination is the company's legal call.
    config: { usesNonExemptEncryption: false },
    // PrivacyInfo.xcprivacy: no tracking; what is collected and why; the required-reason APIs the libraries use.
    // One description feeds this, the App Store labels and Google's Data Safety form (PRIVACY_DECLARATIONS.md).
    privacyManifests: privacyManifest('resident'),
    /*
     * TIME SENSITIVE NOTIFICATIONS. The server already sends `interruptionLevel: 'time-sensitive'`
     * on an update to the resident's own alert and on somebody waiting at the gate for their answer
     * (`app/Jobs/Push/SendExpoPush.php`), and iOS honours that ONLY for an app carrying this entitlement.
     * Without it a Focus mode — Sleep, Driving, Work — holds those back with everything else, and the reply
     * the gate is waiting on is exactly what should cut through.
     *
     * The capability must also be switched on for the App ID in the Apple Developer account before a build can
     * be signed with it: PERMISSIONS.md ("iOS entitlements") and STORE_COMPLIANCE.md row 27 say exactly how.
     */
    entitlements: { 'com.apple.developer.usernotifications.time-sensitive': true },
    // Every purpose string names the app and the exact reason. PERMISSIONS.md lists each one; gate:store holds them to it.
    // The microphone: speaking a request or a report, recognised on the phone and never recorded (M12 §2.9); and live
    // audio to the control room while an alert is open and its switch is on (M50 §4.2 #1).
    infoPlist: {
      NSMicrophoneUsageDescription: MICROPHONE_PURPOSE,
      NSSpeechRecognitionUsageDescription: SPEECH_PURPOSE,
      NSCameraUsageDescription: 'GeminiSecure uses the camera when you choose to add a photo to a maintenance request or a report.',
      NSPhotoLibraryUsageDescription: 'GeminiSecure attaches the photos you choose to a maintenance request or a report. It cannot see any other photo.',
      NSLocationWhenInUseUsageDescription: 'GeminiSecure uses your location when you send an alert or report an incident, and adds it to the alert or the report automatically, so the security officers on duty in your community can find you. It is never collected in the background.',
      NSFaceIDUsageDescription: 'GeminiSecure uses Face ID to unlock the app if you turn the app lock on. The check is done by your iPhone; your face never leaves it.',
      // Added by the development client so a test build can find a developer's machine on the same
      // Wi-Fi. Nothing in the shipped app browses the local network; the string is written here rather
      // than left as a library default so the prompt, if it ever appears, names the app and says why.
      NSLocalNetworkUsageDescription: 'GeminiSecure uses the local network only to connect a test build to a development machine on the same Wi-Fi. The released app does not use it.',
    },
  },
  android: {
    package: IS_DEV ? 'jm.geminisecure.resident.dev' : 'jm.geminisecure.resident',
    // Each build a phone installs over the last one carries a higher code (M50 §8): 1 was every 1.0.0 test build.
    versionCode: 2,
    /*
     * FIREBASE, FOR ANDROID PUSH. Expo delivers an Android notification through Firebase Cloud
     * Messaging, and a phone can only register for it when the app carries its Firebase project's
     * config: without this file `getExpoPushTokenAsync` fails on Android and the handset is never
     * on anybody's push list. The file names the project (gemini-a4ded) and this package; its API
     * key is an app identifier shipped inside every APK, not a credential. The server's SENDING key
     * is the service account uploaded to expo.dev, and is never in this repository.
     *
     * Not for the development variant: its package (`….dev`) is not registered in the project, and
     * Google's plugin stops the build for a package the file does not name.
     *
     * No iOS counterpart: Expo sends to an iPhone through Apple's push service (APNs) with the key
     * uploaded to expo.dev, and nothing in the app uses a Firebase SDK.
     */
    ...(IS_DEV ? {} : { googleServicesFile: './google-services.json' }),
    /*
     * GOOGLE MAPS ON ANDROID (M11 §6): the panic screen's "where your alert was raised". The Maps SDK draws nothing
     * without a key, so the key is a build-time value (GOOGLE_MAPS_ANDROID_KEY, never this file) and the app is told
     * whether it has one (`extra.maps.android`): without one the place is given in words, never a grey square
     * (packages/core/ui/SiteMap.tsx; QUESTIONS MAPS-01). iOS draws Apple Maps and needs no key.
     */
    ...(MAPS_KEY !== '' ? { config: { googleMaps: { apiKey: MAPS_KEY } } } : {}),
    adaptiveIcon: {
      backgroundColor: '#1974D2',
      foregroundImage: './assets/images/android-icon-foreground.png',
      monochromeImage: './assets/images/android-icon-monochrome.png',
    },
    allowBackup: false,
    predictiveBackGestureEnabled: false,
    // RECORD_AUDIO: speaking a request or a report (M12 §2.9), asked for only when the microphone is tapped; and live
    // audio during an open alert (M50 §4.2 #1), asked for only by its switch.
    permissions: ['CAMERA', 'ACCESS_FINE_LOCATION', 'ACCESS_COARSE_LOCATION', 'USE_BIOMETRIC', 'USE_FINGERPRINT', 'VIBRATE', 'RECORD_AUDIO'],
    // What a library might add and this app never uses is REMOVED from the merged manifest: no
    // broad photo/media or storage access (photos come through the system photo picker), no contacts (an emergency contact
    // is typed in), no background location. The ONE foreground service is expo-audio's microphone service, for live
    // audio during an open alert (M50 §4.2 #1: "it survives backgrounding"); no location or playback service.
    // PERMISSIONS.md has the reason for each.
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
      'android.permission.ACCESS_BACKGROUND_LOCATION',
      'android.permission.FOREGROUND_SERVICE_LOCATION',
      'android.permission.FOREGROUND_SERVICE_MEDIA_PLAYBACK',
      'android.permission.SYSTEM_ALERT_WINDOW',
      // expo-brightness adds it for SYSTEM brightness; the pass screen only brightens its own window, which needs no permission.
      'android.permission.WRITE_SETTINGS',
      'android.permission.READ_PHONE_STATE',
      // Declared by @react-native-community/netinfo, which IS used — but only for `isConnected` and
      // `isInternetReachable`, both of which come from ACCESS_NETWORK_STATE. Nothing in this app reads an
      // SSID, a BSSID, a signal strength or a link speed, so a reviewer asking "why does this app want to
      // know my Wi-Fi state?" would have no good answer. Safe to block: netinfo guards every WifiManager
      // call with a runtime permission check and simply omits those fields without it.
      'android.permission.ACCESS_WIFI_STATE',
    ],
  },
  web: { output: 'single', favicon: './assets/images/favicon.png', bundler: 'metro' },
  plugins: [
    'expo-router',
    ['expo-splash-screen', { backgroundColor: '#1974D2', image: './assets/images/splash-icon.png', imageWidth: 104 }],
    ['expo-font', { fonts: FONT_FILES }],
    'expo-secure-store',
    'expo-localization',
    'expo-sharing',
    'expo-notifications',
    // M11 §1.3: what was saved with no signal is sent once a connection is back, even with the app closed (iOS: the
    // `processing` background mode and its task identifier; Android: a WorkManager job that runs only when connected).
    'expo-background-task',
    // The plugins write the same purpose strings as infoPlist above (a plugin's default would otherwise replace them); `false` = never add the key.
    // The microphone string is the SPEECH one (M12 §2.9), never `false` here: `false` makes these two plugins delete the key
    // after the speech plugin has written it, and iOS ends the app at the first tap on the microphone.
    ['expo-image-picker', { photosPermission: 'GeminiSecure attaches the photos you choose to a maintenance request or a report. It cannot see any other photo.', cameraPermission: 'GeminiSecure uses the camera when you choose to add a photo to a maintenance request or a report.', microphonePermission: MICROPHONE_PURPOSE }],
    ['expo-camera', { cameraPermission: 'GeminiSecure uses the camera when you choose to add a photo to a maintenance request or a report.', microphonePermission: MICROPHONE_PURPOSE, recordAudioAndroid: false }],
    // Speech to text, on the phone (M12 §2.9). The on-device recogniser (com.google.android.as) and Google's speech
    // service are the packages it may look for; nothing is sent to either — `requiresOnDeviceRecognition` always.
    ['expo-speech-recognition', { microphonePermission: MICROPHONE_PURPOSE, speechRecognitionPermission: SPEECH_PURPOSE, androidSpeechServicePackages: ['com.google.android.as', 'com.google.android.tts'] }],
    // "Share live audio" during an open alert (M50 §4.2 #1): recording that goes on in the background — Android's
    // microphone foreground service, iOS's `audio` background mode. No playback service: the app plays nothing.
    ['expo-audio', { microphonePermission: MICROPHONE_PURPOSE, recordAudioAndroid: true, enableBackgroundRecording: true, enableBackgroundPlayback: false }],
    ['expo-location', { locationWhenInUsePermission: 'GeminiSecure uses your location when you send an alert or report an incident, and adds it to the alert or the report automatically, so the security officers on duty in your community can find you. It is never collected in the background.', locationAlwaysAndWhenInUsePermission: false, locationAlwaysPermission: false, motionUsagePermission: false, isAndroidBackgroundLocationEnabled: false, isAndroidForegroundServiceEnabled: false, isIosBackgroundLocationEnabled: false }],
    // No background location, so no location foreground service in the manifest either.
    '../../tools/expo-plugins/withoutLocationService.cjs',
    // R8 does not fit in the template's 512 MB of Metaspace, and the way it fails is a silent
    // twenty-minute stall rather than an error. See the plugin.
    '../../tools/expo-plugins/withGradleMemory.cjs',
    // A release is signed with the GeminiSecure key `resident`, never the debug one (M33 §2). See the plugin.
    ['../../tools/expo-plugins/withReleaseSigning.cjs', { alias: 'resident' }],
    ['expo-local-authentication', { faceIDPermission: 'GeminiSecure uses Face ID to unlock the app if you turn the app lock on. The check is done by your iPhone; your face never leaves it.' }],
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
          // 64-bit ARM only - see the note in the Guard App's config, including why a build meant to
          // RUN on an x86_64 emulator sets BUILD_ARCHS=x86_64 instead (the `emulator` eas profile).
          buildArchs: (process.env.BUILD_ARCHS ?? 'arm64-v8a').split(',').map((s) => s.trim()).filter(Boolean),
        },
      },
    ],
  ],
  experiments: { typedRoutes: true, reactCompiler: true },
  extra: {
    surface: 'resident',
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
     * The client's own team account since 2026-09-25 (it was `hassam571`'s e9e8edc4… before;
     * no APK could register a push token then, so no token in the field belongs to the old one):
     *   https://expo.dev/accounts/gemini-secures-team/projects/geminisecure-resident
     * The FCM V1 key Android push is sent with is uploaded to THIS project's credentials.
     *
     * Without an id the app asks for no push token and says exactly that ("no_project")
     * rather than pretending; the in-app notifier still works.
     */
    eas: { projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID ?? '3a5fc13f-ff19-415e-a90e-f791b832732c' },
  },
}

export default config

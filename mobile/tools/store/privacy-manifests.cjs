/**
 * The Apple privacy manifest (PrivacyInfo.xcprivacy) of each app. Imported by both app.config.ts files
 * (`ios.privacyManifests`), and read by PRIVACY_DECLARATIONS.md's generator, so the manifest, the App Store
 * privacy labels and Google's Data Safety form are all filled from ONE description and cannot disagree.
 *
 * NEITHER APP TRACKS ANYBODY: no advertising, no analytics SDK, no data broker, no cross-app identifier.
 * Every data type below is collected for APP FUNCTIONALITY only, and is linked to the person's account
 * (a vote is the one exception: it is stored with nothing that links it to a household — see `notLinked`).
 *
 * `accessedApis` is the UNION of what the native libraries declare in their own PrivacyInfo.xcprivacy files
 * (`node tools/store/privacy-manifest.mjs` walks node_modules and fails if a reason is missing here).
 */

const FUNCTIONALITY = ['NSPrivacyCollectedDataTypePurposeAppFunctionality']

const collected = (type, linked = true) => ({
  NSPrivacyCollectedDataType: `NSPrivacyCollectedDataType${type}`,
  NSPrivacyCollectedDataTypeLinked: linked,
  NSPrivacyCollectedDataTypeTracking: false,
  NSPrivacyCollectedDataTypePurposes: FUNCTIONALITY,
})

const accessedApis = [
  // 1C8F.1 comes with react-native-maps (M11 §6): the Google Maps SDK's own privacy bundle declares it.
  { NSPrivacyAccessedAPIType: 'NSPrivacyAccessedAPICategoryUserDefaults', NSPrivacyAccessedAPITypeReasons: ['CA92.1', '1C8F.1'] },
  { NSPrivacyAccessedAPIType: 'NSPrivacyAccessedAPICategoryFileTimestamp', NSPrivacyAccessedAPITypeReasons: ['0A2A.1', '3B52.1', 'C617.1'] },
  { NSPrivacyAccessedAPIType: 'NSPrivacyAccessedAPICategorySystemBootTime', NSPrivacyAccessedAPITypeReasons: ['35F9.1'] },
  { NSPrivacyAccessedAPIType: 'NSPrivacyAccessedAPICategoryDiskSpace', NSPrivacyAccessedAPITypeReasons: ['85F4.1', 'E174.1'] },
]

/** What each app collects — DERIVED from the one description in data-map.cjs (reasons and code paths are there). */
const { RESIDENT, GUARD } = require('./data-map.cjs')
const typesOf = (rows) => [...new Set(rows.map((row) => row.apple))]
const DATA = { resident: typesOf(RESIDENT), guard: typesOf(GUARD) }

function privacyManifest(app) {
  return {
    NSPrivacyTracking: false,
    NSPrivacyTrackingDomains: [],
    NSPrivacyCollectedDataTypes: DATA[app].map((type) => collected(type)),
    NSPrivacyAccessedAPITypes: accessedApis,
  }
}

module.exports = { privacyManifest, DATA, accessedApis }

/**
 * Expo config plugin — the call-style ring's manifest entries, written into the APP manifest (GUARD_RING_DESIGN §7.1).
 *
 * Everything goes into the app's own AndroidManifest, and the module's library manifest is empty, so that
 * `expo config --type introspect` — what tools/store/manifest-audit.mjs and gate:store read — sees every service,
 * activity and receiver the ring adds. A library manifest would be merged into the APK without the gate ever seeing it.
 *
 *   1  xmlns:tools on <manifest>
 *   2  expo-notifications' ExpoFirebaseMessagingService REMOVED (tools:node="remove"), and
 *      jm.geminisecure.guardring.GuardRingMessagingService — its subclass — declared in its slot: the same
 *      MESSAGING_EVENT filter at the same priority (-1), not exported. Firebase's own service stays at -500, so the
 *      choice between services on the phone is exactly today's.
 *   3  RingActivity: not exported, shown over the lock screen, turns the screen on, a task of its own
 *   4  RingActionReceiver: not exported
 *
 * The two permissions the ring needs (USE_FULL_SCREEN_INTENT, REQUEST_IGNORE_BATTERY_OPTIMIZATIONS) are NOT added here:
 * they are listed in app.config.ts `android.permissions`, where every permission is reviewed, and this plugin REFUSES
 * TO BUILD an app that forgot them — the module must never ship into an app that cannot use it.
 */

const { withAndroidManifest } = require('expo/config-plugins')

const PKG = 'jm.geminisecure.guardring'
const EXPO_SERVICE = 'expo.modules.notifications.service.ExpoFirebaseMessagingService'
const RING_SERVICE = `${PKG}.GuardRingMessagingService`
const RING_ACTIVITY = `${PKG}.RingActivity`
const RING_RECEIVER = `${PKG}.RingActionReceiver`
const MESSAGING_EVENT = 'com.google.firebase.MESSAGING_EVENT'
const REQUIRED_PERMISSIONS = ['USE_FULL_SCREEN_INTENT', 'REQUEST_IGNORE_BATTERY_OPTIMIZATIONS']

const short = (p) => String(p).replace(/^android\.permission\./, '')

function requirePermissions(config) {
  const declared = new Set((config.android?.permissions ?? []).map(short))
  const blocked = new Set((config.android?.blockedPermissions ?? []).map(short))
  const missing = REQUIRED_PERMISSIONS.filter((p) => !declared.has(p) || blocked.has(p))
  if (missing.length > 0) {
    throw new Error(`guard-ring: app.config.ts android.permissions must list ${missing.join(' and ')} (and not block them). The call-style ring cannot work without them; see PERMISSIONS.md.`)
  }
}

function withGuardRing(config) {
  requirePermissions(config)
  return withAndroidManifest(config, (mod) => {
    const manifest = mod.modResults.manifest
    manifest.$ = { ...manifest.$, 'xmlns:tools': 'http://schemas.android.com/tools' }
    const application = manifest.application?.[0]
    if (!application) throw new Error('guard-ring: the app manifest has no <application>')

    const ours = new Set([EXPO_SERVICE, RING_SERVICE])
    const services = (application.service ?? []).filter((s) => !ours.has(s.$?.['android:name']))
    services.push({ $: { 'android:name': EXPO_SERVICE, 'tools:node': 'remove' } })
    services.push({
      $: { 'android:name': RING_SERVICE, 'android:exported': 'false' },
      'intent-filter': [{ $: { 'android:priority': '-1' }, action: [{ $: { 'android:name': MESSAGING_EVENT } }] }],
    })
    application.service = services

    const activities = (application.activity ?? []).filter((a) => a.$?.['android:name'] !== RING_ACTIVITY)
    activities.push({
      $: {
        'android:name': RING_ACTIVITY,
        'android:exported': 'false',
        'android:showWhenLocked': 'true',
        'android:turnScreenOn': 'true',
        'android:launchMode': 'singleInstance',
        'android:excludeFromRecents': 'true',
        'android:taskAffinity': '',
        'android:screenOrientation': 'portrait',
        'android:configChanges': 'orientation|screenSize|screenLayout|smallestScreenSize|keyboardHidden|uiMode|density|fontScale',
        'android:theme': '@style/GuardRingTheme',
      },
    })
    application.activity = activities

    const receivers = (application.receiver ?? []).filter((r) => r.$?.['android:name'] !== RING_RECEIVER)
    receivers.push({ $: { 'android:name': RING_RECEIVER, 'android:exported': 'false' } })
    application.receiver = receivers
    return mod
  })
}

module.exports = withGuardRing
module.exports.EXPO_SERVICE = EXPO_SERVICE
module.exports.RING_SERVICE = RING_SERVICE
module.exports.RING_ACTIVITY = RING_ACTIVITY
module.exports.RING_RECEIVER = RING_RECEIVER
module.exports.REQUIRED_PERMISSIONS = REQUIRED_PERMISSIONS

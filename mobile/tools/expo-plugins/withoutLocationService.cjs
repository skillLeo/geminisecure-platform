/**
 * Expo config plugin — remove expo-location's FOREGROUND SERVICE from the merged Android manifest.
 *
 * THE RESIDENT APP ONLY (since M11 §3). The Resident App collects no location in the background: a position
 * is read only while the app is open, at the moment of an act (send an alert, report an incident). The
 * Guard App no longer uses this plugin — on duty, with the officer's consent, it runs this very service
 * (apps/guard/src/features/tracking/dutyTracker.ts; D-174). An earlier version of this comment named a
 * moment no code read at, which is how a false claim reached an iOS purpose string, the privacy
 * declarations and the store listing before all four were corrected. expo-location's library manifest
 * nevertheless declares
 *
 *     <service android:name="expo.modules.location.services.LocationTaskService"
 *              android:foregroundServiceType="location" />
 *
 * and a manifest that declares a location foreground service is exactly what Google Play's review
 * looks for: since April 2026 a foreground service may not be used to watch a geofence at all.
 * The FOREGROUND_SERVICE permissions are already blocked in the Resident App's app.config.ts, so the
 * service could never start there; this removes the declaration itself, so its shipped manifest says what
 * is true: NO foreground service, NO background location.
 *
 * `tools:node="remove"` is the manifest merger's own instruction; nothing is patched in node_modules.
 */

const { withAndroidManifest } = require('expo/config-plugins')

const SERVICE = 'expo.modules.location.services.LocationTaskService'

module.exports = function withoutLocationService(config) {
  return withAndroidManifest(config, (mod) => {
    const manifest = mod.modResults.manifest
    manifest.$ = { ...manifest.$, 'xmlns:tools': 'http://schemas.android.com/tools' }
    const application = manifest.application?.[0]
    if (!application) return mod
    const services = (application.service ?? []).filter((s) => s.$?.['android:name'] !== SERVICE)
    services.push({ $: { 'android:name': SERVICE, 'tools:node': 'remove' } })
    application.service = services
    return mod
  })
}

module.exports.SERVICE = SERVICE

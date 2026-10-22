/**
 * WHICH HANDSET THIS IS (M46 §1) — what a reinstall keeps, where the install id and the key do not.
 *
 * The install id and the key live in the app's own data, so anything that wipes it — an uninstall, "Clear storage", a
 * build signed with another key — made the same phone a stranger, and the code it had used was refused as spent.
 * Android's per-app device id (`Settings.Secure.ANDROID_ID`, scoped to this app's signing key) survives a reinstall of
 * the same app, so the server keeps it with the binding — hashed, never as sent — and accepts the phone's own spent
 * code again from this handset and no other. Sent at enrolment and with every sign-back-in, so a phone bound before
 * this records it too.
 *
 * Null on iOS and the web: the vendor id there resets when the app is removed, so it would prove nothing.
 */

import { Platform } from 'react-native'
import * as Application from 'expo-application'

export function handsetId(): string | null {
  if (Platform.OS !== 'android') return null
  try {
    const id = Application.getAndroidId()
    return typeof id === 'string' && id.trim() !== '' ? id : null
  } catch {
    return null
  }
}

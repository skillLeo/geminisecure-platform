/**
 * "Require biometrics for payments — Re-confirm identity above $5,000" (board 19; M49 §4.1: in the design, so it WORKS).
 *
 * Every card payment in the app goes through one act (useCardCheckout.pay): dues, an amenity booked and paid for, a
 * booking's fee or deposit. Before it sends anything, it asks here. With the switch on (the board draws it on, and so it
 * starts) and an amount above the line, the PHONE's own prompt confirms it is its owner — a yes or a no, nothing
 * biometric read, kept or sent — and only a yes lets the payment go.
 *
 * A phone with no screen lock has nothing to confirm with: the Security screen says so and the switch cannot be on there,
 * so a payment is not blocked by a question the phone cannot ask. The web build asks nothing (there is no prompt).
 */

import { Platform } from 'react-native'
import * as LocalAuthentication from 'expo-local-authentication'
import { isFidelity } from '@gs/core/fidelity'
import { settingsPrefs } from './prefs'

/** The board's line: a payment above this many dollars is re-confirmed. */
export const PAYMENTS_LOCK_ABOVE = 5000

/** Why the payment did not go, when the phone did not confirm it — said on the payment screen, never in a dialog. */
export const NOT_CONFIRMED = 'The phone did not confirm it was you, so nothing was paid. Try again.'

const CANCELLED: readonly string[] = ['user_cancel', 'system_cancel', 'app_cancel']

/** Whether a payment of `amount` dollars must be confirmed first. An amount not known yet is asked about. */
export const needsConfirming = (amount: number | null, on: boolean = settingsPrefs.get().paymentsLock): boolean =>
  on && (amount === null || !Number.isFinite(amount) || amount > PAYMENTS_LOCK_ABOVE)

/** "8,000.00" / "8000" → 8000; anything unreadable → null (asked about, never waved through). */
export const amountOf = (text: string | null | undefined): number | null => {
  if (typeof text !== 'string') return null
  const n = Number.parseFloat(text.replace(/[^0-9.]/g, ''))
  return Number.isFinite(n) ? n : null
}

/**
 * Null when the payment may go; otherwise the sentence that says why it did not. A cancel is a "no" too — the
 * resident chose not to confirm — but it is said the same plain way, and nothing is charged.
 */
export async function confirmPayment(amount: number | null): Promise<string | null> {
  if (isFidelity || Platform.OS === 'web' || !needsConfirming(amount)) return null
  try {
    const level = await LocalAuthentication.getEnrolledLevelAsync()
    if (level === LocalAuthentication.SecurityLevel.NONE) return null
    const result = await LocalAuthentication.authenticateAsync({ promptMessage: 'Confirm it is you to make this payment', cancelLabel: 'Cancel', disableDeviceFallback: false })
    if (result.success) return null
    return CANCELLED.includes(result.error) ? 'Not confirmed, so nothing was paid.' : NOT_CONFIRMED
  } catch {
    return 'The phone could not ask just now, so nothing was paid. Try again.'
  }
}

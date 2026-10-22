package jm.geminisecure.guardring

import com.google.firebase.messaging.RemoteMessage
import expo.modules.notifications.service.ExpoFirebaseMessagingService

/**
 * THE GUARD APP'S ONE FIREBASE MESSAGING SERVICE (GUARD_RING_DESIGN §2.1). It takes expo-notifications' own slot in
 * the manifest — the config plugin removes `ExpoFirebaseMessagingService` and declares this with the same
 * MESSAGING_EVENT filter at the same priority (-1) — and it IS that service: a subclass, so token refresh and
 * deleted messages are inherited unchanged.
 *
 *   `site_ring`, `ring_stop`, `ring_test`   handled here, natively: no JavaScript runs on the wake path
 *   a visible push for an alert ringing now  dropped (D19): the ring is already sounding
 *   the server's visible `ring_stop`         stops the native ring, and is presented by expo-notifications
 *   EVERY OTHER MESSAGE                      `super`, exactly as today
 *
 * If anything in the ring code throws, a message that is not a ring still reaches `super`, and a ring still
 * reaches the guard as the fallback notification. Pending receipts are sent after the ring is on screen, inside this
 * call's time (FCM gives a high-priority message several seconds), never before it.
 */
class GuardRingMessagingService : ExpoFirebaseMessagingService() {
  override fun onMessageReceived(remoteMessage: RemoteMessage) {
    val outcome = try {
      RingEngine.onMessage(applicationContext, remoteMessage.data)
    } catch (t: Throwable) {
      RingEngine.Outcome.PASS
    }
    if (outcome == RingEngine.Outcome.PASS) super.onMessageReceived(remoteMessage)
    try {
      RingEngine.flush(applicationContext, FLUSH_BUDGET_MS)
    } catch (t: Throwable) {
      // they stay pending, and go with the next message or the next start
    }
  }

  companion object {
    const val FLUSH_BUDGET_MS = 6_000L
  }
}

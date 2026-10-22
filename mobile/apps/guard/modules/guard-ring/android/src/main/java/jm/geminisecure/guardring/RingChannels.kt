package jm.geminisecure.guardring

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.media.AudioAttributes
import android.os.Build
import android.provider.Settings

/**
 * THE TWO CHANNELS THE RING USES — native, and never in the JS `channelsFor` (GUARD_RING_DESIGN §7.6).
 *
 *   safety_ring       "Call-style alerts": high importance, the PHONE'S OWN ALARM TONE on the ALARM stream
 *                     (USAGE_ALARM), a long vibration, the Do Not Disturb bypass asked for, readable on the lock
 *                     screen. No bundled sound, so no licence question: the tone is resolved when it plays.
 *   safety_ring_held  "Unanswered alerts": silent, no vibration, readable on the lock screen. Where a ring goes
 *                     when its 45 s are up, and where a stop is said.
 *
 * A CHANNEL'S SOUND, IMPORTANCE AND AUDIO ATTRIBUTES ARE FIXED ONCE A PHONE HAS CREATED IT. These are new ids, so the
 * `safety` channel already on client phones (whose settings are forever) is untouched, and this file never creates
 * `safety` — it belongs to packages/core/notify/runtime.ts.
 *
 * `ensure()` runs before EVERY notify() (G17): after an update, cleared data or a reinstall a data-only ring can
 * arrive before any JS has loaded the module, and a notification on a channel that does not exist is dropped.
 * Re-stating a channel with its values is a no-op, except that it is how a Do Not Disturb bypass granted later is
 * picked up — so the bypass is always READ BACK, never assumed.
 */
object RingChannels {
  const val RING = "safety_ring"
  const val RING_NAME = "Call-style alerts"
  const val HELD = "safety_ring_held"
  const val HELD_NAME = "Unanswered alerts"
  const val SAFETY = "safety"
  val VIBRATION = longArrayOf(0, 1000, 500, 1000, 500, 1000)

  fun ensure(context: Context): ChannelState {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return ChannelState.OK
    val nm = context.getSystemService(NotificationManager::class.java) ?: return ChannelState.MISSING
    try {
      val ring = NotificationChannel(RING, RING_NAME, NotificationManager.IMPORTANCE_HIGH)
      ring.description = "A panic, a medical emergency or a fire at your estate, ringing like a call until somebody answers."
      ring.setSound(
        Settings.System.DEFAULT_ALARM_ALERT_URI,
        AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build(),
      )
      ring.enableVibration(true)
      ring.vibrationPattern = VIBRATION
      ring.setBypassDnd(true)
      ring.lockscreenVisibility = Notification.VISIBILITY_PUBLIC
      nm.createNotificationChannel(ring)

      val held = NotificationChannel(HELD, HELD_NAME, NotificationManager.IMPORTANCE_DEFAULT)
      held.description = "An alert that rang and is still waiting for an answer, and word that the ringing stopped. Silent."
      held.setSound(null, null)
      held.enableVibration(false)
      held.lockscreenVisibility = Notification.VISIBILITY_PUBLIC
      nm.createNotificationChannel(held)
    } catch (t: Throwable) {
      // read back below: whatever Android has is the answer
    }
    val made = nm.getNotificationChannel(RING) ?: return ChannelState.MISSING
    return if (made.importance == NotificationManager.IMPORTANCE_NONE) ChannelState.BLOCKED else ChannelState.OK
  }

  fun safetyExists(context: Context): Boolean {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return true
    return try {
      context.getSystemService(NotificationManager::class.java)?.getNotificationChannel(SAFETY) != null
    } catch (t: Throwable) {
      false
    }
  }

  /** The ring channel as Android has it now, or null before Android 8 (no channels) or when it cannot be read. */
  fun ringChannel(context: Context): NotificationChannel? {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return null
    return try {
      context.getSystemService(NotificationManager::class.java)?.getNotificationChannel(RING)
    } catch (t: Throwable) {
      null
    }
  }
}

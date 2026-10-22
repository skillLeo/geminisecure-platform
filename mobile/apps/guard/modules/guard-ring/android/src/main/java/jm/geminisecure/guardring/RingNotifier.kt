package jm.geminisecure.guardring

import android.annotation.SuppressLint
import android.app.Notification
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat

/**
 * Puts the ring on the phone, and takes it off (GUARD_RING_DESIGN §7.3, §7.5, §5.4).
 *
 *   ("ring", slot)        the ring: CATEGORY_ALARM, FLAG_INSISTENT (the sound repeats until it is cancelled), a
 *                         full-screen intent to RingActivity, and a SYSTEM timeout at the hold (45 s), so the sound
 *                         stops even if this process has died. Posting it cancels Expo's ("ring-<id>", 0).
 *   ("held", slot)        posted at the SAME moment, silent: "Unanswered · …". It is what stays when the ring times
 *                         out — the persistent notification — and it lives as long as the ticket can answer.
 *   ("ring-<id>", 0)      Expo's own slot for a visible push of this alert. A stop, and the fallback, are said here,
 *                         so a visible re-ring or visible stop from the server REPLACES them: one tray entry, not two.
 *
 * Every PendingIntent carries its own data URI (RingLaunch), so no two are ever the same PendingIntent. The
 * channels are ensured before every notify() (G17).
 */
object RingNotifier {
  const val TAG_RING = "ring"
  const val TAG_HELD = "held"
  const val ACTION_CANNOT = "jm.geminisecure.guardring.CANNOT"
  const val ACTION_DISMISSED = "jm.geminisecure.guardring.DISMISSED"
  const val ACTION_STOP_TEST = "jm.geminisecure.guardring.STOP_TEST"
  const val EXTRA_WHICH = "which"
  /** The Guard App's scheme (apps/guard/app.config.ts `scheme`); a jest test holds the two together. */
  const val APP_SCHEME = "gsguard"
  const val STOP_SHOWN_MS = 10L * 60 * 1000
  private const val RED = 0xFFE5484D.toInt()
  private const val FLAGS = PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT

  private fun nm(context: Context) = NotificationManagerCompat.from(context)

  fun screen(context: Context, id: String, launch: Launch): PendingIntent {
    val intent = Intent(context, RingActivity::class.java)
      .setData(Uri.parse(RingLaunch.uri(id, launch)))
      .putExtra(RingLaunch.EXTRA_ID, id)
      .putExtra(RingLaunch.EXTRA_LAUNCH, launch.wire)
      .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    return PendingIntent.getActivity(context, RingLaunch.slotOf(id), intent, FLAGS)
  }

  private fun broadcast(context: Context, id: String, action: String, uriAction: String, which: String? = null): PendingIntent {
    val intent = Intent(context, RingActionReceiver::class.java)
      .setAction(action)
      .setData(Uri.parse(RingLaunch.actionUri(id, uriAction)))
      .putExtra(RingLaunch.EXTRA_ID, id)
    if (which != null) intent.putExtra(EXTRA_WHICH, which)
    return PendingIntent.getBroadcast(context, RingLaunch.slotOf(id), intent, FLAGS)
  }

  /** The Guard App itself, at the alert: `gsguard://alert/<id>` into its own (not lock-screen) activity. */
  fun appAlertIntent(context: Context, id: String): Intent =
    Intent(Intent.ACTION_VIEW, Uri.parse("$APP_SCHEME://alert/$id")).setPackage(context.packageName).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

  private fun openApp(context: Context, id: String): PendingIntent =
    PendingIntent.getActivity(context, RingLaunch.slotOf(id), appAlertIntent(context, id), FLAGS)

  private fun icon(): Int = R.drawable.guard_ring_ic

  @SuppressLint("MissingPermission")
  private fun post(context: Context, tag: String, id: Int, n: Notification): Boolean = try {
    nm(context).notify(tag, id, n)
    true
  } catch (e: SecurityException) {
    false
  }

  private fun raisedAt(r: LiveRing, nowMs: Long, nowElapsed: Long, boot: Int): Long = nowMs - r.ageNow(nowMs, nowElapsed, boot) * 1000L

  /** The ring. `ringForMs` is the hold left (a fresh ring: the whole hold; a restore: what remains). */
  fun ring(context: Context, r: LiveRing, ringForMs: Long, withHeld: Boolean, nowMs: Long, nowElapsed: Long, boot: Int): Boolean {
    RingChannels.ensure(context)
    val age = r.ageNow(nowMs, nowElapsed, boot)
    val title = if (r.test) RingText.TEST_TITLE else RingText.title(r.kind, r.drill)
    val text = if (r.test) RingText.TEST_TEXT else RingText.text(r.where, r.estate, age, r.at)
    val b = NotificationCompat.Builder(context, RingChannels.RING)
      .setSmallIcon(icon())
      .setColor(RED)
      .setContentTitle(title)
      .setContentText(text)
      .setStyle(NotificationCompat.BigTextStyle().bigText(text))
      .setCategory(NotificationCompat.CATEGORY_ALARM)
      .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
      .setPriority(NotificationCompat.PRIORITY_MAX)
      .setOngoing(true)
      .setAutoCancel(false)
      .setOnlyAlertOnce(false)
      .setWhen(raisedAt(r, nowMs, nowElapsed, boot))
      .setShowWhen(true)
      .setFullScreenIntent(screen(context, r.id, Launch.FSI), true)
      .setContentIntent(screen(context, r.id, Launch.TAP))
      .setDeleteIntent(broadcast(context, r.id, ACTION_DISMISSED, "dismissed-ring", "ring"))
    @Suppress("DEPRECATION")
    b.setSound(Settings.System.DEFAULT_ALARM_ALERT_URI, AudioManager.STREAM_ALARM)
    b.setVibrate(RingChannels.VIBRATION)
    if (r.test) {
      b.addAction(0, "Stop the test", broadcast(context, r.id, ACTION_STOP_TEST, "stop-test"))
    } else if (r.ticket != null) {
      b.addAction(0, "I'm responding", screen(context, r.id, Launch.ACTION))
      b.addAction(0, "Can't respond", broadcast(context, r.id, ACTION_CANNOT, "cannot"))
    } else {
      b.addAction(0, "Open the alert", openApp(context, r.id))
    }
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) b.setTimeoutAfter(ringForMs.coerceAtLeast(1_000L))
    val n = b.build()
    n.flags = n.flags or Notification.FLAG_INSISTENT
    if (withHeld) held(context, r, nowMs, nowElapsed, boot)
    val shown = post(context, TAG_RING, RingLaunch.slotOf(r.id), n)
    // One tray entry for the alert: the visible re-ring Expo may already have put up goes (G9).
    if (shown) cancelExpo(context, r.id)
    return shown
  }

  /** The silent "Unanswered" notification: taps open the ring screen, and it lasts as long as the ticket answers. */
  fun held(context: Context, r: LiveRing, nowMs: Long, nowElapsed: Long, boot: Int): Boolean {
    if (r.test) return false
    RingChannels.ensure(context)
    val b = NotificationCompat.Builder(context, RingChannels.HELD)
      .setSmallIcon(icon())
      .setColor(RED)
      .setContentTitle(RingText.heldTitle(r.kind, r.drill))
      .setContentText(RingText.heldText(r.where, r.estate))
      .setCategory(NotificationCompat.CATEGORY_REMINDER)
      .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
      .setPriority(NotificationCompat.PRIORITY_DEFAULT)
      .setOngoing(true)
      .setAutoCancel(false)
      .setOnlyAlertOnce(true)
      .setSilent(true)
      .setWhen(raisedAt(r, nowMs, nowElapsed, boot))
      .setShowWhen(true)
      .setContentIntent(screen(context, r.id, Launch.TAP))
      .setDeleteIntent(broadcast(context, r.id, ACTION_DISMISSED, "dismissed-held", "held"))
    if (r.ticket != null) {
      b.addAction(0, "I'm responding", screen(context, r.id, Launch.ACTION))
      b.addAction(0, "Can't respond", broadcast(context, r.id, ACTION_CANNOT, "cannot"))
    }
    val left = r.ticketExpiresAtMs() - nowMs
    if (left <= 0) return false
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) b.setTimeoutAfter(left)
    return post(context, TAG_HELD, RingLaunch.slotOf(r.id), b.build())
  }

  /** §5.4: the ringing stopped, said in words that follow why (a stand-down only for a closed or cancelled alert). */
  fun stopped(context: Context, id: String, words: RingText.Words): Boolean {
    RingChannels.ensure(context)
    val b = NotificationCompat.Builder(context, RingChannels.HELD)
      .setSmallIcon(icon())
      .setContentTitle(words.title)
      .setContentText(words.text)
      .setStyle(NotificationCompat.BigTextStyle().bigText(words.text))
      .setCategory(NotificationCompat.CATEGORY_STATUS)
      .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
      .setPriority(NotificationCompat.PRIORITY_DEFAULT)
      .setAutoCancel(true)
      .setSilent(true)
      .setContentIntent(openApp(context, id))
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) b.setTimeoutAfter(STOP_SHOWN_MS)
    return post(context, RingLaunch.expoTag(id), 0, b.build())
  }

  /** D9, D10, D20: the ring channel cannot ring — say it on the loud `safety` channel if the phone has it, else silently. */
  fun fallback(context: Context, p: RingPayload, onSafety: Boolean): Boolean {
    RingChannels.ensure(context)
    val title = if (p.type == RingTypes.TEST) RingText.TEST_TITLE else RingText.title(p.kind, p.drill)
    val text = if (p.type == RingTypes.TEST) RingText.TEST_TEXT else RingText.text(p.where, p.estate, p.ageSeconds, p.at) + ". " + RingText.FALLBACK_TEXT
    val b = NotificationCompat.Builder(context, if (onSafety) RingChannels.SAFETY else RingChannels.HELD)
      .setSmallIcon(icon())
      .setColor(RED)
      .setContentTitle(title)
      .setContentText(text)
      .setStyle(NotificationCompat.BigTextStyle().bigText(text))
      .setCategory(NotificationCompat.CATEGORY_ALARM)
      .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
      .setPriority(NotificationCompat.PRIORITY_MAX)
      .setAutoCancel(true)
      .setContentIntent(openApp(context, p.id))
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) b.setTimeoutAfter(RingStore.TICKET_MS)
    return post(context, RingLaunch.expoTag(p.id), 0, b.build())
  }

  fun cancelRing(context: Context, id: String) = safely { nm(context).cancel(TAG_RING, RingLaunch.slotOf(id)) }

  fun cancelHeld(context: Context, id: String) = safely { nm(context).cancel(TAG_HELD, RingLaunch.slotOf(id)) }

  fun cancelExpo(context: Context, id: String) = safely { nm(context).cancel(RingLaunch.expoTag(id), 0) }

  private fun safely(work: () -> Unit) {
    try {
      work()
    } catch (t: Throwable) {
      // a notification that is already gone
    }
  }
}

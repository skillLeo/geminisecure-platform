package jm.geminisecure.guardring

/**
 * HOW THE RING SCREEN WAS OPENED (G12), and the one place its PendingIntents get their identity.
 *
 * PendingIntents whose Intents differ only in their extras are the SAME PendingIntent, so each one carries its own
 * data URI, `gsring://<alertId>/<launch>`, as well as the `launch` extra. Without that every launch would read the
 * first one's extra, and the console's "full screen" count would include taps.
 *
 *   fsi     the system launched the full-screen intent        → receipt full_screen (D22)
 *   tap     the guard tapped the ring or the held notification → receipt opened (D23)
 *   action  the guard pressed "I'm responding" on the notification
 *   direct  the app had a window and started the screen itself
 */
enum class Launch(val wire: String) {
  FSI("fsi"),
  TAP("tap"),
  ACTION("action"),
  DIRECT("direct");

  companion object {
    fun of(value: String?): Launch = values().firstOrNull { it.wire == value } ?: TAP
  }
}

object RingLaunch {
  const val SCHEME = "gsring"
  const val EXTRA_LAUNCH = "launch"
  const val EXTRA_ID = "id"

  fun uri(alertId: String, launch: Launch): String = "$SCHEME://$alertId/${launch.wire}"

  /** The receiver's own intents: `gsring://<id>/cannot`, `gsring://<id>/dismissed-ring`, `gsring://<id>/dismissed-held`. */
  fun actionUri(alertId: String, action: String): String = "$SCHEME://$alertId/$action"

  fun receiptFor(launch: Launch): Receipt = if (launch == Launch.FSI) Receipt.FULL_SCREEN else Receipt.OPENED

  fun verdictFor(launch: Launch): Verdict = Verdict(Decision.SHOW, receiptFor(launch), launch.wire)

  /**
   * The notification id for an alert. The server's ids are digits; a test ring's is a slug. Positive, and stable
   * for the same id, so a later round replaces the ring of the same alert and never another's.
   */
  fun slotOf(alertId: String): Int {
    val numeric = alertId.toLongOrNull()
    if (numeric != null && numeric in 1..Int.MAX_VALUE.toLong()) return numeric.toInt()
    return (alertId.hashCode() and 0x7fffffff).let { if (it == 0) 1 else it }
  }

  /** Expo presents a visible push for this alert as notify(tag = "ring-<id>", id = 0): the slot the native ring clears. */
  fun expoTag(alertId: String): String = "ring-$alertId"
}

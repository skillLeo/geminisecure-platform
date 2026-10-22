package jm.geminisecure.guardring

/**
 * HONESTY ABOUT WHETHER A RING MADE A SOUND (G11, G15). `notify()` succeeds just as well when the ring channel was
 * set to Silent, when the alarm volume is 0, in "Total silence", or when cooldown damps it — and none of those is a
 * ring. So every `shown` receipt carries the phone's own sound check at that moment, and the readiness bitmask, and
 * the console decides "with the alarm sound on" from them and from nothing else. Pure: the facts are handed in.
 */

/** What the phone says, read at one moment. Everything the `detail` string and the bitmask are made from. */
data class SoundFacts(
  /** The ring channel's importance, 0-5 (NotificationManager.IMPORTANCE_*); -1 when unreadable. */
  val importance: Int,
  val channelHasSound: Boolean,
  val alarmVolume: Int,
  val alarmVolumeMax: Int,
  /** NotificationManager.getCurrentInterruptionFilter(): 1 all, 2 priority, 3 none, 4 alarms, 0 or -1 unknown. */
  val interruptionFilter: Int,
  /** The ring channel's canBypassDnd(), read back. */
  val channelBypass: Boolean,
  /** The Do Not Disturb policy lets alarms through; null when the phone would not say. */
  val policyAllowsAlarms: Boolean?,
  val canFullScreen: Boolean,
  val keyguardLocked: Boolean,
)

/** One bit per readiness row (GUARD_RING_DESIGN §9.1). The wire contract is spec/contract/ready-bits.json. */
data class ReadyFacts(
  val notifications: Boolean,
  val ringChannel: Boolean,
  val fullScreen: Boolean,
  val dndBypass: Boolean,
  val batteryExempt: Boolean,
  val backgroundAllowed: Boolean,
  val alarmVolumeOk: Boolean,
  val alarmTone: Boolean,
  val screenLock: Boolean,
  val alarmVolumeOn: Boolean,
)

object RingSound {
  const val FILTER_ALL = 1
  const val FILTER_PRIORITY = 2
  const val FILTER_NONE = 3
  const val FILTER_ALARMS = 4

  /**
   * Would an alarm-category, alarm-usage notification on the ring channel sound through Do Not Disturb now?
   *   all        pass
   *   alarms     pass ("Alarms only" lets alarms through)
   *   priority   pass when the channel's bypass is on, or the policy lets alarms through
   *   none       block ("Total silence" silences even alarms)
   *   anything else, or a priority mode whose policy cannot be read and whose channel has no bypass: unknown
   */
  fun dnd(filter: Int, channelBypass: Boolean, policyAllowsAlarms: Boolean?): String = when (filter) {
    FILTER_ALL, FILTER_ALARMS -> "pass"
    FILTER_NONE -> "block"
    FILTER_PRIORITY -> when {
      channelBypass || policyAllowsAlarms == true -> "pass"
      policyAllowsAlarms == false -> "block"
      else -> "unknown"
    }
    else -> "unknown"
  }

  /** `imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1` — at most 60 characters, as `ring.receipt` accepts. */
  fun detail(f: SoundFacts): String {
    val imp = if (f.importance in 0..5) f.importance.toString() else "x"
    val out = "imp=$imp;snd=${if (f.channelHasSound) 1 else 0};vol=${f.alarmVolume.coerceAtLeast(0)}/${f.alarmVolumeMax.coerceAtLeast(0)};" +
      "dnd=${dnd(f.interruptionFilter, f.channelBypass, f.policyAllowsAlarms)};fsi=${if (f.canFullScreen) 1 else 0};lk=${if (f.keyguardLocked) 1 else 0}"
    return if (out.length <= 60) out else out.substring(0, 60)
  }

  /** The server's rule for "with the alarm sound on", kept here too so the phone and the console never disagree. */
  fun soundOn(detail: String?): Boolean {
    if (detail == null) return false
    val parts = detail.split(';').mapNotNull { p -> p.split('=', limit = 2).takeIf { it.size == 2 }?.let { it[0] to it[1] } }.toMap()
    val imp = parts["imp"]?.toIntOrNull() ?: return false
    val vol = parts["vol"]?.substringBefore('/')?.toIntOrNull() ?: return false
    return imp >= 4 && parts["snd"] == "1" && vol > 0 && parts["dnd"] == "pass"
  }

  const val BIT_NOTIFICATIONS = 0
  const val BIT_RING_CHANNEL = 1
  const val BIT_FULL_SCREEN = 2
  const val BIT_DND_BYPASS = 3
  const val BIT_BATTERY = 4
  const val BIT_BACKGROUND = 5
  const val BIT_ALARM_VOLUME_OK = 6
  const val BIT_ALARM_TONE = 7
  const val BIT_SCREEN_LOCK = 8
  const val BIT_ALARM_VOLUME_ON = 9

  fun bits(r: ReadyFacts): Int {
    var b = 0
    fun set(on: Boolean, bit: Int) { if (on) b = b or (1 shl bit) }
    set(r.notifications, BIT_NOTIFICATIONS)
    set(r.ringChannel, BIT_RING_CHANNEL)
    set(r.fullScreen, BIT_FULL_SCREEN)
    set(r.dndBypass, BIT_DND_BYPASS)
    set(r.batteryExempt, BIT_BATTERY)
    set(r.backgroundAllowed, BIT_BACKGROUND)
    set(r.alarmVolumeOk, BIT_ALARM_VOLUME_OK)
    set(r.alarmTone, BIT_ALARM_TONE)
    set(r.screenLock, BIT_SCREEN_LOCK)
    set(r.alarmVolumeOn, BIT_ALARM_VOLUME_ON)
    return b
  }

  /** `off` at 0, `low` under 30 % of the stream's maximum, `ok` otherwise. */
  fun volumeBucket(volume: Int, max: Int): String = when {
    volume <= 0 -> "off"
    max > 0 && volume * 100 < max * 30 -> "low"
    else -> "ok"
  }
}

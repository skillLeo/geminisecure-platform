package jm.geminisecure.guardring

import android.app.ActivityManager
import android.app.KeyguardManager
import android.app.NotificationManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.media.AudioManager
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.biometric.BiometricManager
import androidx.core.app.NotificationManagerCompat

/**
 * "CALL-STYLE ALERTS": what this phone can do, read from Android at the moment of asking, never assumed
 * (GUARD_RING_DESIGN §9.1). Each row is ON or OFF and has a fix — the phone's own settings screen for it. The app
 * never changes any of these itself; it opens the screen and reads again when the guard comes back.
 *
 * The manufacturer's own battery manager (Samsung's "Never sleeping apps" and the like) cannot be read by any app:
 * the JS side gives advice for it from `maker`, and only a real test ring proves a handset.
 */
object RingReadiness {
  private fun <T> safely(otherwise: T, read: () -> T): T = try {
    read()
  } catch (t: Throwable) {
    otherwise
  }

  fun notificationsOn(context: Context): Boolean = safely(false) { NotificationManagerCompat.from(context).areNotificationsEnabled() }

  fun canFullScreen(context: Context): Boolean = safely(true) {
    if (Build.VERSION.SDK_INT >= 34) context.getSystemService(NotificationManager::class.java)?.canUseFullScreenIntent() ?: false else true
  }

  fun keyguardLocked(context: Context): Boolean = safely(false) { context.getSystemService(KeyguardManager::class.java)?.isKeyguardLocked ?: false }

  fun deviceSecure(context: Context): Boolean = safely(false) { context.getSystemService(KeyguardManager::class.java)?.isDeviceSecure ?: false }

  fun biometric(context: Context): Boolean = safely(false) {
    BiometricManager.from(context).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_WEAK) == BiometricManager.BIOMETRIC_SUCCESS
  }

  fun sound(context: Context): SoundFacts {
    val channel = RingChannels.ringChannel(context)
    val nm = context.getSystemService(NotificationManager::class.java)
    val audio = context.getSystemService(AudioManager::class.java)
    val preO = Build.VERSION.SDK_INT < Build.VERSION_CODES.O
    return SoundFacts(
      importance = if (preO) (if (notificationsOn(context)) 4 else 0) else channel?.importance ?: -1,
      channelHasSound = if (preO) true else channel?.sound != null,
      alarmVolume = safely(-1) { audio?.getStreamVolume(AudioManager.STREAM_ALARM) ?: -1 },
      alarmVolumeMax = safely(-1) { audio?.getStreamMaxVolume(AudioManager.STREAM_ALARM) ?: -1 },
      interruptionFilter = safely(-1) { nm?.currentInterruptionFilter ?: -1 },
      channelBypass = if (preO) false else channel?.canBypassDnd() ?: false,
      policyAllowsAlarms = safely(null) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
          val policy = nm?.notificationPolicy
          if (policy == null) null else (policy.priorityCategories and NotificationManager.Policy.PRIORITY_CATEGORY_ALARMS) != 0
        } else {
          true // before Android 9, Priority only always let alarms through
        }
      },
      canFullScreen = canFullScreen(context),
      keyguardLocked = keyguardLocked(context),
    )
  }

  private fun batteryExempt(context: Context): Boolean? = safely(null) {
    context.getSystemService(PowerManager::class.java)?.isIgnoringBatteryOptimizations(context.packageName)
  }

  private fun backgroundRestricted(context: Context): Boolean? = safely(null) {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) context.getSystemService(ActivityManager::class.java)?.isBackgroundRestricted else null
  }

  private fun alarmTone(context: Context): Boolean = safely(false) { RingtoneManager.getActualDefaultRingtoneUri(context, RingtoneManager.TYPE_ALARM) != null }

  fun ready(context: Context): ReadyFacts {
    val s = sound(context)
    val notifications = notificationsOn(context)
    val channelOn = Build.VERSION.SDK_INT < Build.VERSION_CODES.O || (s.importance > NotificationManager.IMPORTANCE_NONE && s.channelHasSound)
    return ReadyFacts(
      notifications = notifications,
      ringChannel = channelOn,
      fullScreen = s.canFullScreen,
      dndBypass = s.channelBypass,
      batteryExempt = batteryExempt(context) == true,
      backgroundAllowed = backgroundRestricted(context) == false,
      alarmVolumeOk = RingSound.volumeBucket(s.alarmVolume, s.alarmVolumeMax) == "ok",
      alarmTone = alarmTone(context),
      screenLock = deviceSecure(context),
      alarmVolumeOn = s.alarmVolume > 0,
    )
  }

  fun bits(context: Context): Int = safely(0) { RingSound.bits(ready(context)) }

  /** What JS sees (native.ts `NativeReadiness`). */
  fun read(context: Context, configured: Boolean): Map<String, Any?> {
    val channelState = RingChannels.ensure(context)
    val s = sound(context)
    val nm = context.getSystemService(NotificationManager::class.java)
    val battery = batteryExempt(context)
    val restricted = backgroundRestricted(context)
    val preO = Build.VERSION.SDK_INT < Build.VERSION_CODES.O
    return mapOf(
      "v" to 1,
      "configured" to (configured && channelState == ChannelState.OK),
      "sdk" to Build.VERSION.SDK_INT,
      "maker" to (Build.MANUFACTURER ?: "").lowercase(),
      "notifications" to if (notificationsOn(context)) "on" else "off",
      "ringChannel" to when {
        channelState == ChannelState.MISSING -> "missing"
        channelState == ChannelState.BLOCKED -> "off"
        !preO && s.importance < NotificationManager.IMPORTANCE_HIGH -> "quiet"
        !s.channelHasSound -> "silent"
        else -> "on"
      },
      "fullScreen" to if (Build.VERSION.SDK_INT >= 34) (if (s.canFullScreen) "on" else "off") else "not_needed",
      "dndAccess" to safely(false) { nm?.isNotificationPolicyAccessGranted ?: false },
      "channelBypass" to s.channelBypass,
      "battery" to when (battery) { true -> "exempt"; false -> "optimised"; null -> "unknown" },
      "background" to when (restricted) { true -> "restricted"; false -> "allowed"; null -> "unknown" },
      "alarmVolume" to s.alarmVolume,
      "alarmVolumeMax" to s.alarmVolumeMax,
      "alarmTone" to if (alarmTone(context)) "set" else "none",
      "screenLock" to if (deviceSecure(context)) "on" else "off",
      "biometric" to biometric(context),
      "interruptionFilter" to s.interruptionFilter,
      "soundDetail" to RingSound.detail(s),
    )
  }

  /** Opens the phone's own screen for one row. True when a screen opened. */
  fun open(context: Context, which: String): Boolean {
    val pkg = context.packageName
    fun start(intent: Intent): Boolean = try {
      intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
      context.startActivity(intent)
      true
    } catch (e: ActivityNotFoundException) {
      false
    } catch (e: SecurityException) {
      false
    }
    val details = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$pkg"))
    return when (which) {
      "notifications" -> start(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, pkg)) || start(details)
      "ring_channel" ->
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
          RingChannels.ensure(context)
          start(Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, pkg).putExtra(Settings.EXTRA_CHANNEL_ID, RingChannels.RING)) ||
            start(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, pkg))
        } else {
          start(details)
        }
      "full_screen" ->
        if (Build.VERSION.SDK_INT >= 34) start(Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:$pkg"))) || start(details) else false
      "dnd" -> start(Intent(Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS))
      "battery" -> start(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$pkg"))) ||
        start(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) || start(details)
      "background" -> start(details)
      "alarm_volume" -> safely(false) {
        context.getSystemService(AudioManager::class.java)?.adjustStreamVolume(AudioManager.STREAM_ALARM, AudioManager.ADJUST_SAME, AudioManager.FLAG_SHOW_UI)
        true
      }
      "alarm_tone" -> start(Intent(Settings.ACTION_SOUND_SETTINGS))
      "screen_lock" -> start(Intent(Settings.ACTION_SECURITY_SETTINGS))
      "app" -> start(details)
      else -> false
    }
  }
}

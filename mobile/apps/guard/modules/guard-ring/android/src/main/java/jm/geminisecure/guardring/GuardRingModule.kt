package jm.geminisecure.guardring

import android.content.Context
import expo.modules.kotlin.functions.Queues
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

/**
 * The JS door to the ring (apps/guard/src/features/ring/native.ts is the only file that opens it). Everything the
 * ring does on its own — waking, ringing, answering from the lock screen — needs none of this: JS is not running
 * when a killed phone rings. This is for the app while it is open: where the server is, whether the guard is under
 * their own duress, the readiness screen, and tidying up at sign-out.
 *
 * In Expo Go, on iOS and in an older APK there is no such module, and native.ts answers null (requireOptionalNativeModule).
 */
class GuardRingModule : Module() {
  private val context: Context
    get() = appContext.reactContext?.applicationContext ?: throw IllegalStateException("no React context")

  override fun definition() = ModuleDefinition {
    Name("GuardRing")

    Events("onRingEvent")

    OnCreate {
      appContext.reactContext?.let { RingChannels.ensure(it.applicationContext) }
      RingEvents.bridge = { body -> sendEvent("onRingEvent", body) }
    }

    OnDestroy {
      RingEvents.bridge = null
    }

    /** Where answers and receipts go: the app's one API address (packages/core/lib/env.ts `apiUrl()`). */
    AsyncFunction("configure") { apiBase: String ->
      RingEngine.configure(context, apiBase)
    }

    AsyncFunction("readiness") {
      RingReadiness.read(context, RingEngine.configured(context))
    }

    AsyncFunction("openSetting") { which: String ->
      RingReadiness.open(appContext.currentActivity ?: context, which)
    }.runOnQueue(Queues.MAIN)

    /** The guard's own live duress (R14): `untilMs` in the phone's clock, or null when there is none. */
    AsyncFunction("setQuiet") { untilMs: Double? ->
      RingEngine.setQuiet(context, untilMs?.toLong())
    }

    AsyncFunction("flushPending") {
      RingEngine.flush(context, 8_000L)
    }

    AsyncFunction("consumeLaunch") {
      RingEngine.consumeLaunch(context)
    }

    AsyncFunction("restore") {
      RingEngine.restore(context)
    }

    AsyncFunction("stopAll") {
      RingEngine.stopAll(context)
    }

    AsyncFunction("forget") {
      RingEngine.forget(context)
    }
  }
}

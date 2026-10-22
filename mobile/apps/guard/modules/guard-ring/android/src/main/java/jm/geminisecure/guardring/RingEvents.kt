package jm.geminisecure.guardring

import android.os.Bundle
import java.util.concurrent.CopyOnWriteArraySet

/**
 * Who is told when something happens to a ring: the JS bridge (while the app's JS runs) and any ring screen on
 * show. Nothing waits on a listener, and a listener that throws never breaks a ring.
 *
 *   ringing   a ring was put on screen
 *   opened    the ring screen was opened for an alert
 *   answered  "I'm responding" was recorded by the server
 *   declined  "Can't respond"
 *   stopped   the ringing stopped for an alert (a stop, or the alert answered)
 */
object RingEvents {
  @Volatile
  var bridge: ((Bundle) -> Unit)? = null

  private val screens = CopyOnWriteArraySet<(String, String, String?) -> Unit>()

  fun addScreen(fn: (String, String, String?) -> Unit) {
    screens.add(fn)
  }

  fun removeScreen(fn: (String, String, String?) -> Unit) {
    screens.remove(fn)
  }

  /** `detail` is for a ring screen on show (a stop's own sentence); JS is told only the type and the alert. */
  fun emit(type: String, id: String, detail: String? = null) {
    try {
      bridge?.invoke(Bundle().apply { putString("type", type); putString("id", id) })
    } catch (t: Throwable) {
      // the app's JS is not listening: nothing to do
    }
    for (fn in screens) {
      try {
        fn(type, id, detail)
      } catch (t: Throwable) {
        // a screen going away mid-call
      }
    }
  }
}

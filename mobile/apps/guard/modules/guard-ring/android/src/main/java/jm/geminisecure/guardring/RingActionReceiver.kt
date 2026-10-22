package jm.geminisecure.guardring

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * The notification's own buttons that need no screen (GUARD_RING_DESIGN §7.3, §7.5, §7.7):
 *
 *   "Can't respond"       no unlock needed — from the LOCK SCREEN it declines this round only (R10b)
 *   a swipe (delete)      the ring or the Unanswered notification was removed. Before the hold deadline it was the
 *                         guard (`dismissed`: neither a decline nor an answer — they are rung again next round);
 *                         at the deadline it was the system's timeout (`held`) (D24, D25)
 *   "Stop the test"       ends a test ring
 *
 * Not exported; every intent is explicit. The work runs off the main thread under `goAsync()`, 8 s at most.
 */
class RingActionReceiver : BroadcastReceiver() {
  override fun onReceive(context: Context, intent: Intent) {
    val id = intent.getStringExtra(RingLaunch.EXTRA_ID) ?: return
    if (!RingParser.isSlug(id)) return
    val app = context.applicationContext
    val pending = goAsync()
    Thread({
      try {
        when (intent.action) {
          RingNotifier.ACTION_CANNOT -> cannot(app, id)
          RingNotifier.ACTION_DISMISSED -> dismissed(app, id, intent.getStringExtra(RingNotifier.EXTRA_WHICH) ?: "ring")
          RingNotifier.ACTION_STOP_TEST -> RingEngine.clear(app, id)
        }
      } catch (t: Throwable) {
        // a button that could not finish: the ring times out by itself
      } finally {
        pending.finish()
      }
    }, "guard-ring-action").start()
  }

  private fun cannot(context: Context, id: String) {
    val store = RingEngine.store(context)
    val lock = LockFacts(RingReadiness.keyguardLocked(context), RingReadiness.deviceSecure(context), false)
    val plan = RingActions.before(Action.CANNOT, lock, store.declinedRoundBefore(id, RingEngine.now()))
    val answer = RingEngine.recordAnswer(context, id, "cannot", plan.scope)
    RingEngine.decline(context, id, plan.scope ?: "round")
    if (answer != null) RingEngine.sendAnswer(context, answer)
  }

  private fun dismissed(context: Context, id: String, which: String) {
    val store = RingEngine.store(context)
    val r = store.liveOf(id) ?: return
    if (r.test || r.state == LiveState.STOPPED || r.state == LiveState.ANSWERED) return
    val verdict = RingDecider.onDelete(which, r.postedAtMs, r.holdSeconds, RingEngine.now())
    if (which == "ring" && r.state == LiveState.RINGING) store.setState(id, LiveState.HELD, RingEngine.now())
    val receipt = verdict.receipt ?: return
    RingEngine.receipt(context, r, receipt, verdict.detail, send = false)
    RingEngine.flush(context, 6_000L)
  }
}

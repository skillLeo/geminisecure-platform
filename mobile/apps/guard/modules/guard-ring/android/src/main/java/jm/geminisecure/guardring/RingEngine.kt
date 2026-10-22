package jm.geminisecure.guardring

import android.app.ActivityManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.Settings
import org.json.JSONObject

/**
 * The ring's moving parts, in one place: the service, the ring screen, the receiver and the JS module all come here.
 * It gathers the facts, asks RingDecider (pure, table-tested), and carries the decision out with RingNotifier.
 *
 * THE RULES IT KEEPS
 *   - A message that is not ours is never touched: `PASS`, and the service hands it to expo-notifications as today.
 *   - A ring that goes wrong half way still reaches the guard: whatever throws, the fallback notification is posted.
 *   - A receipt or an answer is written to the store BEFORE it is sent (G22), and a receipt never delays a ring —
 *     the ring is on screen before anything is sent.
 *   - Every store change happens under one lock: the service, the receiver, the ring screen and the module run on
 *     different threads.
 */
object RingEngine {
  enum class Outcome { HANDLED, PASS }

  private val lock = Any()
  @Volatile private var cached: RingStore? = null
  private val main by lazy { Handler(Looper.getMainLooper()) }

  fun store(context: Context): RingStore = cached ?: synchronized(lock) {
    cached ?: RingStore(RingPrefs(context)).also { cached = it }
  }

  fun now(): Long = System.currentTimeMillis()
  fun elapsed(): Long = SystemClock.elapsedRealtime()
  fun boot(context: Context): Int = try {
    Settings.Global.getInt(context.contentResolver, Settings.Global.BOOT_COUNT, -1)
  } catch (t: Throwable) {
    -1
  }

  private fun phone(context: Context): PhoneFacts = PhoneFacts(
    notificationsEnabled = RingReadiness.notificationsOn(context),
    ringChannel = RingChannels.ensure(context),
    safetyChannelExists = RingChannels.safetyExists(context),
  )

  /* ---- a push arrived ------------------------------------------------------------------------------ */

  fun onMessage(context: Context, data: Map<String, String>): Outcome {
    val parsed = try {
      RingParser.parse(data)
    } catch (t: Throwable) {
      return Outcome.PASS
    }
    return when (parsed) {
      is Parsed.NotRing -> try {
        val nowMs = now()
        val verdict = RingDecider.decide(parsed, { store(context).facts(it, nowMs) }, PhoneFacts())
        if (verdict.decision == Decision.DROP_VISIBLE_DUPLICATE) Outcome.HANDLED else Outcome.PASS
      } catch (t: Throwable) {
        Outcome.PASS
      }
      is Parsed.VisibleStop -> {
        try {
          stop(context, parsed.id, null, null, visible = true)
        } catch (t: Throwable) {
          silenceQuietly(context, parsed.id)
        }
        Outcome.PASS // expo-notifications presents the server's own words
      }
      is Parsed.Malformed -> Outcome.HANDLED
      is Parsed.Ring -> {
        val p = parsed.payload
        try {
          when (p.type) {
            RingTypes.STOP -> stop(context, p.id, p.why, p.who, visible = false)
            else -> ring(context, p)
          }
        } catch (t: Throwable) {
          // Whatever went wrong, the guard is still told (the ladder's rung 3).
          try {
            if (p.type == RingTypes.STOP) silenceQuietly(context, p.id) else RingNotifier.fallback(context, p, RingChannels.safetyExists(context))
          } catch (t2: Throwable) {
            // nothing more can be done from here
          }
        }
        Outcome.HANDLED
      }
    }
  }

  private fun ring(context: Context, p: RingPayload) {
    val nowMs = now()
    val el = elapsed()
    val bootNow = boot(context)
    val phone = phone(context)
    val verdict: Verdict
    var live: LiveRing? = null
    synchronized(lock) {
      val s = store(context)
      verdict = RingDecider.decide(Parsed.Ring(p), { s.facts(it, nowMs) }, phone)
      live = when (verdict.decision) {
        Decision.RING, Decision.TEST -> s.recordRing(p, nowMs, el, bootNow, LiveState.RINGING)
        Decision.SUPPRESS_DURESS -> s.recordRing(p, nowMs, el, bootNow, LiveState.SUPPRESSED)
        Decision.FALLBACK_SAFETY, Decision.FALLBACK_HELD -> s.recordRing(p, nowMs, el, bootNow, LiveState.HELD)
        Decision.IGNORE_NOTIFICATIONS_OFF -> s.recordRing(p, nowMs, el, bootNow, LiveState.NONE)
        else -> s.liveOf(p.id)
      }
    }
    val r = live
    when (verdict.decision) {
      Decision.RING, Decision.TEST -> {
        val shown = RingNotifier.ring(context, r!!, p.holdSeconds * 1000L, withHeld = verdict.decision == Decision.RING, nowMs = nowMs, nowElapsed = el, boot = bootNow)
        if (!shown) {
          RingNotifier.fallback(context, p, phone.safetyChannelExists)
          receipt(context, r, Receipt.FALLBACK, "notify_failed", send = false)
          return
        }
        scheduleHold(context, p.id, p.round, p.holdSeconds * 1000L)
        receipt(context, r, Receipt.SHOWN, RingSound.detail(RingReadiness.sound(context)), send = false)
        RingEvents.emit("ringing", p.id)
        if (appInForeground()) openScreen(context, p.id, Launch.DIRECT)
      }
      Decision.SUPPRESS_DURESS -> receipt(context, r, Receipt.SUPPRESSED, null, send = false)
      Decision.FALLBACK_SAFETY, Decision.FALLBACK_HELD -> {
        RingNotifier.fallback(context, p, verdict.decision == Decision.FALLBACK_SAFETY)
        receipt(context, r, Receipt.FALLBACK, verdict.detail, send = false)
      }
      Decision.IGNORE_NOTIFICATIONS_OFF -> receipt(context, r, Receipt.FALLBACK, verdict.detail, send = false)
      Decision.IGNORE_STOPPED -> if (p.ticket != null) receiptFor(context, p, Receipt.STOPPED)
      else -> Unit // a duplicate, a decline, a malformed message: nothing is shown and nothing is said
    }
  }

  /** A stop: data-only (`why` known), or the server's visible one (Expo presents its own words). */
  fun stop(context: Context, id: String, why: String?, who: String?, visible: Boolean) {
    val nowMs = now()
    val verdict: Verdict
    val before: LiveRing?
    synchronized(lock) {
      val s = store(context)
      before = s.liveOf(id)
      val facts = s.facts(id, nowMs)
      verdict = if (visible) RingDecider.decide(Parsed.VisibleStop(id), { facts }, PhoneFacts())
      else RingDecider.decide(Parsed.Ring(RingPayload(RingTypes.STOP, 1, id, "", 0, 0, false, false, "", "", 0, "", 45, null, why, who)), { facts }, PhoneFacts())
      s.markStopped(id, nowMs)
    }
    RingNotifier.cancelRing(context, id)
    RingNotifier.cancelHeld(context, id)
    val words = RingText.stop(why, who, before?.kind, before?.where ?: "", before?.estate ?: "", before?.drill ?: false)
    when (verdict.decision) {
      Decision.STOP, Decision.STOP_QUIET -> RingNotifier.stopped(context, id, words)
      else -> Unit // STOP_SILENT (a suppressed ring stays unseen), STOP_KEEP_SCREEN, STOP_VISIBLE (Expo says it)
    }
    if (verdict.receipt != null && before != null) receipt(context, before, verdict.receipt, why, send = false)
    // A suppressed ring's stop is not announced even to a screen: under the guard's own duress nothing about it shows.
    if (verdict.decision != Decision.STOP_SILENT) RingEvents.emit("stopped", id, words.text)
    resumeOthers(context, id)
  }

  /** G20: when one alert stops or is answered while another still rings, the other is re-posted with the hold it has left. */
  fun resumeOthers(context: Context, except: String) {
    val nowMs = now()
    val el = elapsed()
    val bootNow = boot(context)
    val others = synchronized(lock) { store(context).ringing(nowMs).filter { it.id != except && it.state == LiveState.RINGING } }
    for (r in others) {
      val left = r.holdEndsAtMs() - nowMs
      if (left > 1_000) RingNotifier.ring(context, r, left, withHeld = false, nowMs = nowMs, nowElapsed = el, boot = bootNow)
    }
  }

  private fun silenceQuietly(context: Context, id: String) {
    RingNotifier.cancelRing(context, id)
    RingNotifier.cancelHeld(context, id)
  }

  /** The in-process half of the 45 s switch (the system's `setTimeoutAfter` is the other, and survives our death). */
  private fun scheduleHold(context: Context, id: String, round: Int, afterMs: Long) {
    val app = context.applicationContext
    main.postDelayed({ holdExpired(app, id, round) }, afterMs)
  }

  fun holdExpired(context: Context, id: String, round: Int) {
    val r = synchronized(lock) {
      val s = store(context)
      val r = s.liveOf(id)
      if (r == null || r.state != LiveState.RINGING || r.round != round || now() < r.holdEndsAtMs() - RingDecider.DELETE_SLACK_MS) return
      s.setState(id, LiveState.HELD, now())
      r
    }
    RingNotifier.cancelRing(context, id)
    if (!r.test) receipt(context, r, Receipt.HELD, null, send = true)
    RingEvents.emit("held", id)
  }

  /* ---- the guard's answers --------------------------------------------------------------------------- */

  /** "I'm responding" is being sent: the insistent sound stops, the silent "Unanswered" notification stays. */
  fun silence(context: Context, id: String) {
    synchronized(lock) {
      val s = store(context)
      if (s.liveOf(id)?.state == LiveState.RINGING) s.setState(id, LiveState.HELD, now())
    }
    RingNotifier.cancelRing(context, id)
  }

  /** This alert needs nothing more from this phone: the ring, the Unanswered notification and Expo's entry go. */
  fun clear(context: Context, id: String) {
    RingNotifier.cancelRing(context, id)
    RingNotifier.cancelHeld(context, id)
    RingNotifier.cancelExpo(context, id)
  }

  fun decline(context: Context, id: String, scope: String) {
    synchronized(lock) { store(context).decline(id, scope, now()) }
    clear(context, id)
    RingEvents.emit("declined", id)
    resumeOthers(context, id)
  }

  /** Writes the answer to pending (G22) and returns it; null when the ring has no ticket to answer with. */
  fun recordAnswer(context: Context, id: String, answer: String, scope: String?): Pending? {
    val nowMs = now()
    return synchronized(lock) {
      val s = store(context)
      val r = s.liveOf(id) ?: return null
      val ticket = r.ticket ?: return null
      val p = Pending(
        key = "$id:answer:$answer", kind = Pending.ANSWER, id = id, ticket = ticket, event = answer, detail = null,
        ready = null, scope = scope, createdAtMs = nowMs, tapElapsed = elapsed(), bootCount = boot(context), expiresAtMs = r.ticketExpiresAtMs(),
      )
      s.addPending(p)
      p
    }
  }

  /** Sends one pending answer now (never on the main thread). A stale "responding" is dropped, never sent (D26). */
  fun sendAnswer(context: Context, p: Pending): AnswerResult {
    val el = elapsed()
    val bootNow = boot(context)
    val base = synchronized(lock) {
      val s = store(context)
      if (s.isStale(p, el, bootNow)) {
        s.removePending(p.key)
        return AnswerResult(STALE)
      }
      s.apiBase
    }
    val body = JSONObject().put("rt", p.ticket).put("answer", p.event).put("device_time", RingHttp.deviceTime())
    if (p.event == "responding") body.put("age_ms", (el - p.tapElapsed).coerceAtLeast(0L))
    if (p.scope != null && p.event == "cannot") body.put("scope", p.scope)
    val res = RingHttp.post(context, base, "ring/answer", body)
    val session = res.json?.optBoolean("session_open", false) ?: false
    val result = AnswerResult(res.status, res.code, res.message, session)
    synchronized(lock) {
      val s = store(context)
      if (RingPendingRule.afterSend(res.status).decision == Decision.DELETE_PENDING) s.removePending(p.key)
      if (res.status in 200..299 && p.event == "responding") s.markAnswered(p.id, now())
    }
    if (res.status in 200..299 && p.event == "responding") {
      clear(context, p.id)
      RingEvents.emit("answered", p.id)
    }
    return result
  }

  /** Status of an answer that was too old to send (D26). */
  const val STALE = -2

  /* ---- receipts ----------------------------------------------------------------------------------------- */

  private fun receiptFor(context: Context, p: RingPayload, event: Receipt) {
    val r = synchronized(lock) { store(context).liveOf(p.id) } ?: LiveRing.of(p, now(), elapsed(), boot(context), LiveState.NONE)
    receipt(context, r, event, null, send = false)
  }

  /**
   * Writes a receipt to pending, then (when `send`) sends what is pending on a background thread. The service
   * passes `send = false` and flushes itself once the ring is up, so a receipt never delays a ring.
   */
  fun receipt(context: Context, r: LiveRing?, event: Receipt, detail: String?, send: Boolean) {
    val ring = r ?: return
    val ticket = ring.ticket ?: return
    val nowMs = now()
    val ready = RingReadiness.bits(context)
    synchronized(lock) {
      store(context).addPending(
        Pending(
          key = "${ring.id}:${event.wire}:${ring.round}", kind = Pending.RECEIPT, id = ring.id, ticket = ticket, event = event.wire,
          detail = detail?.take(60), ready = ready, scope = null, createdAtMs = nowMs, tapElapsed = elapsed(),
          bootCount = boot(context), expiresAtMs = ring.ticketExpiresAtMs(),
        ),
      )
    }
    if (send) flushInBackground(context)
  }

  fun flushInBackground(context: Context) {
    val app = context.applicationContext
    Thread({ try { flush(app, 8_000L) } catch (t: Throwable) { } }, "guard-ring-flush").start()
  }

  /** Sends what is pending, oldest first, until `budgetMs` is spent. Returns how many the server took. */
  fun flush(context: Context, budgetMs: Long): Int {
    val until = elapsed() + budgetMs
    val nowMs = now()
    val list = synchronized(lock) { store(context).purgePending(nowMs, elapsed(), boot(context)) }
    var sent = 0
    for (p in list) {
      val left = until - elapsed()
      if (left < 1_500) break
      if (p.isAnswer) {
        if (sendAnswer(context, p).status in 200..299) sent++
        continue
      }
      val base = synchronized(lock) { store(context).apiBase }
      val body = JSONObject().put("rt", p.ticket).put("event", p.event).put("device_time", RingHttp.deviceTime())
      if (p.detail != null) body.put("detail", p.detail)
      if (p.ready != null) body.put("ready", p.ready)
      val res = RingHttp.post(context, base, "ring/receipt", body, timeoutMs = minOf(5_000L, left).toInt())
      if (RingPendingRule.afterSend(res.status).decision == Decision.DELETE_PENDING) {
        synchronized(lock) { store(context).removePending(p.key) }
        if (res.status in 200..299) sent++
      }
      if (res.status < 0) break // no signal: the rest wait for the next chance
    }
    return sent
  }

  /* ---- the ring screen ---------------------------------------------------------------------------------- */

  fun openScreen(context: Context, id: String, launch: Launch) {
    try {
      context.startActivity(
        Intent(context, RingActivity::class.java)
          .setData(Uri.parse(RingLaunch.uri(id, launch)))
          .putExtra(RingLaunch.EXTRA_ID, id)
          .putExtra(RingLaunch.EXTRA_LAUNCH, launch.wire)
          .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
      )
    } catch (t: Throwable) {
      // the notification's own full-screen intent and heads-up still stand
    }
  }

  fun opened(context: Context, id: String, launch: Launch) {
    val r = synchronized(lock) {
      val s = store(context)
      s.setLaunch(id)
      s.liveOf(id)
    }
    if (r != null && !r.test) receipt(context, r, RingLaunch.receiptFor(launch), launch.wire, send = true)
    RingEvents.emit("opened", id)
  }

  private fun appInForeground(): Boolean = try {
    val info = ActivityManager.RunningAppProcessInfo()
    ActivityManager.getMyMemoryState(info)
    info.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
  } catch (t: Throwable) {
    false
  }

  /* ---- what the JS module asks for --------------------------------------------------------------------- */

  fun configure(context: Context, apiBase: String?): Boolean {
    val ok = apiBase != null && (apiBase.startsWith("https://") || apiBase.startsWith("http://"))
    synchronized(lock) { if (ok) store(context).apiBase = apiBase!!.trimEnd('/') }
    RingChannels.ensure(context)
    return ok
  }

  fun configured(context: Context): Boolean = synchronized(lock) { store(context).apiBase != null }

  /** R14: the guard's own duress. While quiet nothing rings, and a ring sounding now is silenced at once. */
  fun setQuiet(context: Context, untilMs: Long?) {
    val nowMs = now()
    val silenced = synchronized(lock) {
      val s = store(context)
      s.setQuiet(untilMs)
      if (untilMs == null || untilMs <= nowMs) emptyList() else s.ringing(nowMs).also { list -> for (r in list) s.setState(r.id, LiveState.SUPPRESSED, nowMs) }
    }
    for (r in silenced) silenceQuietly(context, r.id)
  }

  fun consumeLaunch(context: Context): String? = synchronized(lock) { store(context).consumeLaunch() }

  /** D28: after the tray was cleared (an expired session), every ring still inside its hold comes back, and every held one silently. */
  fun restore(context: Context): Int {
    val nowMs = now()
    val el = elapsed()
    val bootNow = boot(context)
    val plan = synchronized(lock) { if (store(context).isQuiet(nowMs)) emptyList() else store(context).restorePlan(nowMs) }
    var n = 0
    for (item in plan) {
      val ringFor = item.ringForMs
      val ok = if (ringFor != null) RingNotifier.ring(context, item.ring, ringFor, item.held, nowMs, el, bootNow)
      else RingNotifier.held(context, item.ring, nowMs, el, bootNow)
      if (ok && ringFor != null) scheduleHold(context, item.ring.id, item.ring.round, ringFor)
      if (ok) n++
    }
    return n
  }

  /** Sign-out, or the office took the handset back: nothing of this guard's rings stays on the phone. */
  fun stopAll(context: Context) {
    val all = synchronized(lock) { store(context).live().keys.toList() }
    for (id in all) clear(context, id)
  }

  fun forget(context: Context) {
    synchronized(lock) { store(context).forget() }
  }
}

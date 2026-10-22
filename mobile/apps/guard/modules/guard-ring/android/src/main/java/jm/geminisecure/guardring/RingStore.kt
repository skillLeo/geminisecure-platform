package jm.geminisecure.guardring

import org.json.JSONArray
import org.json.JSONObject

/**
 * What the phone remembers about rings, behind a key-value interface: SharedPreferences in the app
 * (`RingPrefs`), a map in the tests. Pure logic — every time is handed in, so a test can move the clock.
 *
 *   apiBase      where answers and receipts go (set by the app's JS at start; kept across sign-out)
 *   quietUntil   the guard's own duress: no ring may sound before this (R14). Kept across sign-out on purpose
 *   stopped      alerts the server said have stopped ringing (24 h, at most 200) — a later ring for one never sounds
 *   declined     "Can't respond" after unlock, scope `alert` (24 h, at most 200)
 *   roundDecl    a lock-screen decline, scope `round`: the guard is rung again next round (G29)
 *   lastRound    the highest round seen per alert (24 h, at most 200): a repeat of an older round never re-rings
 *   live         each alert ringing, held or suppressed now, with what `restore()` needs to put it back (G4, G20)
 *   answered     "I'm responding" accepted by the server
 *   pending      answers and receipts written BEFORE they are sent, deleted on a 2xx (G22), at most 50
 *   launch       the last alert whose ring screen was opened, for the app to show when it comes to the front
 */
interface KeyValue {
  fun get(key: String): String?
  fun put(key: String, value: String?)
}

class MemoryKeyValue : KeyValue {
  val map = LinkedHashMap<String, String>()
  override fun get(key: String): String? = map[key]
  override fun put(key: String, value: String?) {
    if (value == null) map.remove(key) else map[key] = value
  }
}

data class LiveRing(
  val id: String,
  val kind: String,
  val drill: Boolean,
  val estate: String,
  val where: String,
  val at: String,
  val round: Int,
  val lastRound: Int,
  val escalation: Boolean,
  val test: Boolean,
  val ticket: String?,
  val ageAtReceipt: Long,
  val receivedAtMs: Long,
  val receivedElapsed: Long,
  val bootCount: Int,
  val holdSeconds: Int,
  val postedAtMs: Long,
  val state: LiveState,
) {
  /** The ticket answers for 30 minutes from when the server built it; the phone counts from receipt. */
  fun ticketExpiresAtMs(): Long = receivedAtMs + RingStore.TICKET_MS

  fun holdEndsAtMs(): Long = postedAtMs + holdSeconds * 1000L

  /**
   * What the ring IS now, whatever was last written: a ring past its hold has gone quiet even if nothing on the
   * phone lived to record it (the system's own timeout took it). Without this, a ring whose process died would be
   * "ringing" for ever, and a visible re-ring for it would be dropped as a duplicate.
   */
  fun stateAt(nowMs: Long): LiveState = if (state == LiveState.RINGING && nowMs >= holdEndsAtMs()) LiveState.HELD else state

  /** Seconds since the raise: by the monotonic clock while the phone has not restarted, else by its wall clock. */
  fun ageNow(nowMs: Long, nowElapsed: Long, bootNow: Int): Long =
    ageAtReceipt + (if (bootNow == bootCount && nowElapsed >= receivedElapsed) (nowElapsed - receivedElapsed) else (nowMs - receivedAtMs).coerceAtLeast(0L)) / 1000L

  fun toJson(): JSONObject = JSONObject()
    .put("id", id).put("kind", kind).put("drill", drill).put("estate", estate).put("where", where).put("at", at)
    .put("r", round).put("of", lastRound).put("esc", escalation).put("test", test).put("rt", ticket ?: JSONObject.NULL)
    .put("age", ageAtReceipt).put("recv", receivedAtMs).put("recvEl", receivedElapsed).put("boot", bootCount)
    .put("hold", holdSeconds).put("posted", postedAtMs).put("state", state.name)

  companion object {
    fun of(p: RingPayload, nowMs: Long, elapsed: Long, boot: Int, state: LiveState): LiveRing = LiveRing(
      id = p.id, kind = p.kind, drill = p.drill, estate = p.estate, where = p.where, at = p.at, round = p.round,
      lastRound = p.lastRound, escalation = p.escalation, test = p.type == RingTypes.TEST, ticket = p.ticket,
      ageAtReceipt = p.ageSeconds, receivedAtMs = nowMs, receivedElapsed = elapsed, bootCount = boot,
      holdSeconds = p.holdSeconds, postedAtMs = nowMs, state = state,
    )

    fun from(o: JSONObject): LiveRing? = try {
      LiveRing(
        id = o.getString("id"), kind = o.optString("kind"), drill = o.optBoolean("drill"), estate = o.optString("estate"),
        where = o.optString("where"), at = o.optString("at"), round = o.optInt("r"), lastRound = o.optInt("of"),
        escalation = o.optBoolean("esc"), test = o.optBoolean("test"), ticket = if (o.isNull("rt")) null else o.optString("rt"),
        ageAtReceipt = o.optLong("age"), receivedAtMs = o.optLong("recv"), receivedElapsed = o.optLong("recvEl"),
        bootCount = o.optInt("boot", -1), holdSeconds = o.optInt("hold", RingParser.DEFAULT_HOLD), postedAtMs = o.optLong("posted"),
        state = LiveState.valueOf(o.optString("state", LiveState.NONE.name)),
      )
    } catch (e: Exception) {
      null
    }
  }
}

/** An answer or a receipt, kept until the server has it. */
data class Pending(
  val key: String,
  val kind: String,
  val id: String,
  val ticket: String,
  val event: String,
  val detail: String?,
  val ready: Int?,
  val scope: String?,
  val createdAtMs: Long,
  val tapElapsed: Long,
  val bootCount: Int,
  val expiresAtMs: Long,
) {
  val isAnswer: Boolean get() = kind == ANSWER

  fun toJson(): JSONObject = JSONObject()
    .put("key", key).put("kind", kind).put("id", id).put("rt", ticket).put("event", event)
    .put("detail", detail ?: JSONObject.NULL).put("ready", ready ?: JSONObject.NULL).put("scope", scope ?: JSONObject.NULL)
    .put("created", createdAtMs).put("tapEl", tapElapsed).put("boot", bootCount).put("expires", expiresAtMs)

  companion object {
    const val ANSWER = "answer"
    const val RECEIPT = "receipt"

    fun from(o: JSONObject): Pending? = try {
      Pending(
        key = o.getString("key"), kind = o.getString("kind"), id = o.getString("id"), ticket = o.getString("rt"),
        event = o.getString("event"), detail = if (o.isNull("detail")) null else o.optString("detail"),
        ready = if (o.isNull("ready")) null else o.optInt("ready"), scope = if (o.isNull("scope")) null else o.optString("scope"),
        createdAtMs = o.optLong("created"), tapElapsed = o.optLong("tapEl"), bootCount = o.optInt("boot", -1), expiresAtMs = o.optLong("expires"),
      )
    } catch (e: Exception) {
      null
    }
  }
}

/** What `restore()` puts back after the tray was cleared: the ring with the hold it has left, or only the silent notification. */
data class RestoreItem(val ring: LiveRing, val ringForMs: Long?, val held: Boolean)

class RingStore(private val kv: KeyValue) {
  companion object {
    const val DAY_MS = 24L * 60 * 60 * 1000
    const val TICKET_MS = 30L * 60 * 1000
    const val MAX_IDS = 200
    const val MAX_PENDING = 50
    const val MAX_LIVE = 50
    /** A "responding" that has not reached the server 120 s after the tap is never sent (G10, D26). */
    const val ANSWER_STALE_MS = 120_000L
  }

  /* ---- plain values ---------------------------------------------------------------------------- */

  var apiBase: String?
    get() = kv.get("apiBase")
    set(value) = kv.put("apiBase", value)

  fun quietUntil(): Long = kv.get("quietUntil")?.toLongOrNull() ?: 0L

  fun setQuiet(untilMs: Long?) = kv.put("quietUntil", untilMs?.toString())

  fun isQuiet(nowMs: Long): Boolean = quietUntil() > nowMs

  fun setLaunch(id: String?) = kv.put("launch", id)

  fun consumeLaunch(): String? {
    val id = kv.get("launch")
    kv.put("launch", null)
    return id
  }

  /* ---- id → time maps, capped and aged ----------------------------------------------------------- */

  private fun times(key: String): LinkedHashMap<String, Long> {
    val out = LinkedHashMap<String, Long>()
    val raw = kv.get(key) ?: return out
    try {
      val o = JSONObject(raw)
      for (k in o.keys()) out[k] = o.optLong(k)
    } catch (e: Exception) {
      // an unreadable store is an empty one
    }
    return out
  }

  private fun putTimes(key: String, map: Map<String, Long>, nowMs: Long) {
    val kept = map.entries.filter { nowMs - it.value < DAY_MS }.sortedBy { it.value }.takeLast(MAX_IDS)
    val o = JSONObject()
    for ((k, v) in kept) o.put(k, v)
    kv.put(key, o.toString())
  }

  private fun mark(key: String, id: String, nowMs: Long) {
    val m = times(key)
    m.remove(id)
    m[id] = nowMs
    putTimes(key, m, nowMs)
  }

  private fun has(key: String, id: String, nowMs: Long): Boolean = times(key)[id]?.let { nowMs - it < DAY_MS } ?: false

  fun isStopped(id: String, nowMs: Long) = has("stopped", id, nowMs)
  fun isDeclined(id: String, nowMs: Long) = has("declined", id, nowMs)
  fun declinedRoundBefore(id: String, nowMs: Long) = has("roundDecl", id, nowMs)
  fun isAnswered(id: String, nowMs: Long) = has("answered", id, nowMs)
  fun stoppedCount(nowMs: Long) = times("stopped").count { nowMs - it.value < DAY_MS }

  fun lastRound(id: String, nowMs: Long): Int? {
    val raw = kv.get("lastRound") ?: return null
    return try {
      val o = JSONObject(raw).optJSONObject(id) ?: return null
      if (nowMs - o.optLong("at") >= DAY_MS) null else o.optInt("r")
    } catch (e: Exception) {
      null
    }
  }

  private fun setLastRound(id: String, round: Int, nowMs: Long) {
    val o = try { JSONObject(kv.get("lastRound") ?: "{}") } catch (e: Exception) { JSONObject() }
    o.put(id, JSONObject().put("r", round).put("at", nowMs))
    val entries = o.keys().asSequence().toList().map { it to o.getJSONObject(it) }
      .filter { nowMs - it.second.optLong("at") < DAY_MS }.sortedBy { it.second.optLong("at") }.takeLast(MAX_IDS)
    val out = JSONObject()
    for ((k, v) in entries) out.put(k, v)
    kv.put("lastRound", out.toString())
  }

  /* ---- live rings ----------------------------------------------------------------------------------- */

  fun live(): LinkedHashMap<String, LiveRing> {
    val out = LinkedHashMap<String, LiveRing>()
    val raw = kv.get("live") ?: return out
    try {
      val o = JSONObject(raw)
      for (k in o.keys()) LiveRing.from(o.getJSONObject(k))?.let { out[k] = it }
    } catch (e: Exception) {
      // unreadable: nothing live
    }
    return out
  }

  private fun putLive(map: Map<String, LiveRing>, nowMs: Long) {
    val kept = map.values.filter { nowMs < it.ticketExpiresAtMs() }.sortedBy { it.receivedAtMs }.takeLast(MAX_LIVE)
    val o = JSONObject()
    for (r in kept) o.put(r.id, r.toJson())
    kv.put("live", o.toString())
  }

  fun liveOf(id: String): LiveRing? = live()[id]

  fun liveState(id: String, nowMs: Long): LiveState {
    val r = liveOf(id) ?: return LiveState.NONE
    if (nowMs >= r.ticketExpiresAtMs()) return LiveState.NONE
    return r.stateAt(nowMs)
  }

  /** The facts the decider needs for one alert. */
  fun facts(id: String, nowMs: Long): StoreFacts = StoreFacts(
    stopped = isStopped(id, nowMs),
    declinedForAlert = isDeclined(id, nowMs),
    lastRound = lastRound(id, nowMs),
    live = liveState(id, nowMs),
    quiet = isQuiet(nowMs),
    answeredResponding = isAnswered(id, nowMs) || liveState(id, nowMs) == LiveState.ANSWERED,
  )

  /** A ring (or a test) was put on screen, or suppressed. The round is remembered either way. */
  fun recordRing(p: RingPayload, nowMs: Long, elapsed: Long, boot: Int, state: LiveState): LiveRing {
    val ring = LiveRing.of(p, nowMs, elapsed, boot, state)
    val m = live()
    m.remove(p.id)
    m[p.id] = ring
    putLive(m, nowMs)
    if (p.type == RingTypes.RING) setLastRound(p.id, p.round, nowMs)
    return ring
  }

  fun setState(id: String, state: LiveState, nowMs: Long) {
    val m = live()
    val r = m[id] ?: return
    m[id] = r.copy(state = state)
    putLive(m, nowMs)
  }

  fun markStopped(id: String, nowMs: Long) {
    mark("stopped", id, nowMs)
    setState(id, LiveState.STOPPED, nowMs)
  }

  fun markAnswered(id: String, nowMs: Long) {
    mark("answered", id, nowMs)
    setState(id, LiveState.ANSWERED, nowMs)
  }

  fun decline(id: String, scope: String, nowMs: Long) {
    if (scope == "alert") mark("declined", id, nowMs) else mark("roundDecl", id, nowMs)
    setState(id, LiveState.STOPPED, nowMs)
  }

  /** The alerts ringing or held now, newest first: what the ring screen lists (G20). */
  fun ringing(nowMs: Long): List<LiveRing> = live().values
    .filter { (it.state == LiveState.RINGING || it.state == LiveState.HELD) && nowMs < it.ticketExpiresAtMs() && !(it.test && nowMs >= it.holdEndsAtMs()) }
    .map { it.copy(state = it.stateAt(nowMs)) }
    .sortedByDescending { it.receivedAtMs }

  /** D28: after the tray was cleared, what goes back — each ring still inside its hold, each held one silently. */
  fun restorePlan(nowMs: Long): List<RestoreItem> = ringing(nowMs).mapNotNull { r ->
    val left = r.holdEndsAtMs() - nowMs
    when {
      r.state == LiveState.RINGING && left > 0 -> RestoreItem(r, left, held = !r.test)
      r.test -> null
      else -> RestoreItem(r, null, held = true)
    }
  }

  /* ---- pending answers and receipts --------------------------------------------------------------- */

  fun pending(): List<Pending> {
    val raw = kv.get("pending") ?: return emptyList()
    return try {
      val a = JSONArray(raw)
      (0 until a.length()).mapNotNull { Pending.from(a.getJSONObject(it)) }
    } catch (e: Exception) {
      emptyList()
    }
  }

  private fun putPending(list: List<Pending>) {
    val a = JSONArray()
    for (p in list) a.put(p.toJson())
    kv.put("pending", a.toString())
  }

  /** Written BEFORE it is sent (G22). The same key replaces the older copy. Over the cap, the oldest receipt goes first. */
  fun addPending(p: Pending) {
    val list = pending().filter { it.key != p.key }.toMutableList()
    list.add(p)
    while (list.size > MAX_PENDING) {
      val drop = list.indexOfFirst { !it.isAnswer }.let { if (it >= 0) it else 0 }
      list.removeAt(drop)
    }
    putPending(list)
  }

  fun removePending(key: String) = putPending(pending().filter { it.key != key })

  /** D26: a "responding" older than 120 s by the monotonic clock, or from before a restart, is never sent. */
  fun isStale(p: Pending, nowElapsed: Long, bootNow: Int): Boolean =
    p.isAnswer && p.event == "responding" && (p.bootCount != bootNow || nowElapsed - p.tapElapsed > ANSWER_STALE_MS || nowElapsed < p.tapElapsed)

  /** Drops what can never be sent: stale "responding" answers and anything past its ticket. Returns what is left. */
  fun purgePending(nowMs: Long, nowElapsed: Long, bootNow: Int): List<Pending> {
    val keep = pending().filter { nowMs < it.expiresAtMs && !isStale(it, nowElapsed, bootNow) }
    putPending(keep)
    return keep
  }

  /* ---- sign-out ---------------------------------------------------------------------------------- */

  /** Everything about the last guard's rings goes. The API address stays, and so does quiet (it ends by itself). */
  fun forget() {
    for (key in listOf("stopped", "declined", "roundDecl", "lastRound", "live", "answered", "pending", "launch")) kv.put(key, null)
  }
}

/** D27: what a send's answer means for the copy kept in pending. */
object RingPendingRule {
  fun afterSend(status: Int): Verdict = when {
    status in 200..299 -> Verdict(Decision.DELETE_PENDING)
    // Refused for good (a bad or expired ticket, a validation error, a refusal): sending it again changes nothing.
    status in 400..499 && status != 429 && status != 408 -> Verdict(Decision.DELETE_PENDING)
    else -> Verdict(Decision.KEEP_PENDING)
  }

  fun staleAnswer(): Verdict = Verdict(Decision.DROP_STALE_ANSWER)
}

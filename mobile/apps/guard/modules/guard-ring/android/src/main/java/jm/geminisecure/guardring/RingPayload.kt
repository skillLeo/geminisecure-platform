package jm.geminisecure.guardring

import org.json.JSONObject

/**
 * What a push carries, read the way expo-notifications delivers it: the app's own `data` object arrives as a JSON
 * STRING in FCM's `data["body"]` (expo-notifications NotificationData.kt), next to Expo's own flat keys (`title`,
 * `message`, `tag`, ...). Pure: it takes the FCM data map and knows nothing about Android.
 *
 *   NotRing      not one of ours: expo-notifications presents it exactly as today (super.onMessageReceived)
 *   VisibleStop  the server's VISIBLE `ring_stop` (no `v`): Expo presents it; the native ring of that alert stops too
 *   Malformed    one of our types, but not a message this build can trust (D7, D8): nothing is shown for it
 *   Ring         a `site_ring`, a data-only `ring_stop` or a `ring_test` this build understands
 *
 * RULES (GUARD_RING_DESIGN §6.2, §6.4, D7, D8)
 *   - `body` is read first. Only when there is no `body` at all are the FLAT keys read, and then only ours.
 *   - A `body` object that carries `title` or `message` is refused (D8): expo-notifications would present such a
 *     message itself, so a server that sends one has broken the contract. Flat keys BESIDE `body` are ignored.
 *   - Numbers may come as numbers or as numeric strings.
 *   - `id` and `kind` are the server's slugs (PushMessage::SLUG). Words shown on a lock screen are clipped and
 *     stripped of control characters; the resident's name is never carried, so it can never be shown.
 */
object RingTypes {
  const val RING = "site_ring"
  const val STOP = "ring_stop"
  const val TEST = "ring_test"
  val ALL: Set<String> = setOf(RING, STOP, TEST)
  const val VERSION = 1
  val WHY: Set<String> = setOf("responding", "acknowledged", "closed", "cancelled")
}

data class RingPayload(
  val type: String,
  val v: Int,
  val id: String,
  val kind: String,
  /** This round, 0-5 (`r`). */
  val round: Int,
  /** The last round there will be (`of`). */
  val lastRound: Int,
  /** This round includes every active officer of the estate (`esc`). */
  val escalation: Boolean,
  val drill: Boolean,
  val estate: String,
  val where: String,
  /** Whole seconds from the raise to when the server built the message (`age`). */
  val ageSeconds: Long,
  /** The raise, in the site's zone, as the server wrote it (`at`, "2:11 AM"). */
  val at: String,
  /** Seconds before the ring becomes the silent "Unanswered" notification, clamped to 15-120. */
  val holdSeconds: Int,
  /** The ring ticket (`rt`), or null when absent: the ring still rings, it just cannot be answered from here. */
  val ticket: String?,
  /** `ring_stop` only. */
  val why: String?,
  /** `ring_stop` only, and only for `why = responding`: the responding officer's name. */
  val who: String?,
)

sealed class Parsed {
  data class NotRing(val tag: String?, val bodyType: String?) : Parsed()
  data class VisibleStop(val id: String) : Parsed()
  data class Malformed(val type: String, val reason: String) : Parsed()
  data class Ring(val payload: RingPayload) : Parsed()
}

object RingParser {
  private val SLUG = Regex("^[A-Za-z0-9_-]{1,40}$")
  private val FLAT_KEYS = listOf("type", "v", "id", "kind", "r", "of", "esc", "drill", "estate", "where", "age", "at", "hold", "rt", "why", "who")
  const val MAX_TICKET = 1024
  const val DEFAULT_HOLD = 45
  const val DEFAULT_TEST_HOLD = 15
  const val MIN_HOLD = 15
  const val MAX_HOLD = 120

  fun isSlug(value: String?): Boolean = value != null && SLUG.matches(value)

  /** `ring-4812` (Expo's tray tag for a visible re-ring or stop) → `4812`, else null. */
  fun ringTagId(tag: String?): String? {
    if (tag == null || !tag.startsWith("ring-")) return null
    val id = tag.removePrefix("ring-")
    return if (isSlug(id)) id else null
  }

  fun parse(data: Map<String, String>): Parsed {
    val tag = data["tag"]
    val rawBody = data["body"]
    val fromBody = rawBody != null
    val obj: JSONObject = if (fromBody) {
      try {
        JSONObject(rawBody)
      } catch (e: Exception) {
        return Parsed.NotRing(tag, null)
      }
    } else {
      val flat = JSONObject()
      for (key in FLAT_KEYS) data[key]?.let { flat.put(key, it) }
      flat
    }
    val type = obj.opt("type") as? String
    if (type == null || type !in RingTypes.ALL) return Parsed.NotRing(tag, type)
    // D8: the app's own data object may never carry presentational words (expo-notifications would show them).
    if (fromBody && (obj.has("title") || obj.has("message"))) return Parsed.Malformed(type, "title_or_message_in_body")

    val id = obj.opt("id")?.let { stringOf(it) }
    val v = intOf(obj.opt("v"))
    if (v == null) {
      // The server's VISIBLE stop carries only {type, id}: it is a stop all the same.
      if (type == RingTypes.STOP && isSlug(id)) return Parsed.VisibleStop(id!!)
      return Parsed.Malformed(type, "no_version")
    }
    if (v != RingTypes.VERSION) return Parsed.Malformed(type, "unknown_version")
    if (!isSlug(id)) return Parsed.Malformed(type, "bad_id")

    val ticket = (obj.opt("rt") as? String)?.takeIf { it.isNotBlank() && it.length <= MAX_TICKET }
    return when (type) {
      RingTypes.RING -> {
        val kind = obj.opt("kind") as? String
        if (!isSlug(kind)) return Parsed.Malformed(type, "bad_kind")
        val round = intOf(obj.opt("r")) ?: return Parsed.Malformed(type, "no_round")
        if (round < 0 || round > 99) return Parsed.Malformed(type, "bad_round")
        val last = (intOf(obj.opt("of")) ?: round).coerceAtLeast(round)
        Parsed.Ring(
          RingPayload(
            type = type, v = v, id = id!!, kind = kind!!, round = round, lastRound = last,
            escalation = boolOf(obj.opt("esc")), drill = boolOf(obj.opt("drill")),
            estate = words(obj.opt("estate"), 60), where = words(obj.opt("where"), 40),
            ageSeconds = (longOf(obj.opt("age")) ?: 0L).coerceAtLeast(0L), at = words(obj.opt("at"), 12),
            holdSeconds = hold(obj.opt("hold"), DEFAULT_HOLD), ticket = ticket, why = null, who = null,
          ),
        )
      }
      RingTypes.TEST -> Parsed.Ring(
        RingPayload(
          type = type, v = v, id = id!!, kind = "test", round = 0, lastRound = 0,
          escalation = false, drill = obj.opt("drill")?.let { boolOf(it) } ?: true,
          estate = words(obj.opt("estate"), 60), where = "", ageSeconds = 0L, at = "",
          holdSeconds = hold(obj.opt("hold"), DEFAULT_TEST_HOLD), ticket = ticket, why = null, who = null,
        ),
      )
      else -> {
        val why = (obj.opt("why") as? String)?.takeIf { it in RingTypes.WHY }
        Parsed.Ring(
          RingPayload(
            type = type, v = v, id = id!!, kind = "", round = 0, lastRound = 0,
            escalation = false, drill = false, estate = "", where = "", ageSeconds = 0L, at = "",
            holdSeconds = DEFAULT_HOLD, ticket = null, why = why,
            who = if (why == "responding") words(obj.opt("who"), 40).takeIf { it.isNotEmpty() } else null,
          ),
        )
      }
    }
  }

  private fun stringOf(value: Any): String? = when (value) {
    is String -> value
    is Number -> if (value.toDouble() == Math.floor(value.toDouble())) value.toLong().toString() else null
    else -> null
  }

  fun intOf(value: Any?): Int? = longOf(value)?.let { if (it in Int.MIN_VALUE..Int.MAX_VALUE) it.toInt() else null }

  fun longOf(value: Any?): Long? = when (value) {
    is Int -> value.toLong()
    is Long -> value
    is Number -> value.toDouble().let { if (it.isFinite() && it == Math.floor(it)) it.toLong() else null }
    is String -> value.trim().toLongOrNull()
    else -> null
  }

  fun boolOf(value: Any?): Boolean = when (value) {
    is Boolean -> value
    is Number -> value.toInt() != 0
    is String -> value.trim().lowercase() in setOf("1", "true", "yes")
    else -> false
  }

  private fun hold(value: Any?, default: Int): Int = (intOf(value) ?: default).coerceIn(MIN_HOLD, MAX_HOLD)

  /** Text for a lock screen: a string, control characters removed, whitespace collapsed, clipped. */
  fun words(value: Any?, max: Int): String {
    val text = (value as? String) ?: return ""
    val clean = text.replace(Regex("[\\p{Cntrl}\\u2028\\u2029]"), " ").replace(Regex("\\s+"), " ").trim()
    return if (clean.length <= max) clean else clean.substring(0, max).trimEnd()
  }
}

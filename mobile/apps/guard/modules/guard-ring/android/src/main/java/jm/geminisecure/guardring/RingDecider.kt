package jm.geminisecure.guardring

/**
 * WHAT THE PHONE DOES WITH A MESSAGE, AS A PURE DECISION (GUARD_RING_DESIGN §7.9). No Android in here: the service
 * gathers the facts, this answers, and the notifier carries it out. Every row of `spec/decisions.json` is a case of
 * one of the functions below, and JUnit runs the whole table.
 *
 * The order of the checks for a ring matters and is deliberate:
 *   malformed → stopped → quiet (the guard's own duress) → declined → duplicate → notifications off → channel → RING
 * A stop always wins, so a ring that lands after its stop never sounds. Quiet comes before everything that could
 * make a noise. Nothing below ever makes a sound for a message it cannot trust.
 */

enum class Decision {
  RING,
  IGNORE_DUPLICATE,
  IGNORE_STOPPED,
  IGNORE_DECLINED,
  SUPPRESS_DURESS,
  IGNORE_MALFORMED,
  FALLBACK_SAFETY,
  FALLBACK_HELD,
  IGNORE_NOTIFICATIONS_OFF,
  STOP,
  STOP_QUIET,
  STOP_SILENT,
  STOP_KEEP_SCREEN,
  STOP_VISIBLE,
  TEST,
  DROP_VISIBLE_DUPLICATE,
  PASS,
  SHOW,
  RECORD_DISMISSED,
  RECORD_HELD,
  DROP_STALE_ANSWER,
  DELETE_PENDING,
  KEEP_PENDING,
  RESTORE,
}

/** The receipt events the server accepts (`ring.receipt`). */
enum class Receipt(val wire: String) {
  SHOWN("shown"),
  FULL_SCREEN("full_screen"),
  OPENED("opened"),
  HELD("held"),
  DISMISSED("dismissed"),
  STOPPED("stopped"),
  SUPPRESSED("suppressed"),
  FALLBACK("fallback"),
}

/** What this phone is doing about one alert right now. */
enum class LiveState { NONE, RINGING, HELD, SUPPRESSED, ANSWERED, STOPPED }

/** The ring channel as Android has it, read back after `ensure()`. */
enum class ChannelState { OK, BLOCKED, MISSING }

/** What the phone's own store says about the alert a message names. */
data class StoreFacts(
  val stopped: Boolean = false,
  /** Declined with scope `alert` (after unlock). A lock-screen decline (scope `round`) is NOT this (G29). */
  val declinedForAlert: Boolean = false,
  val lastRound: Int? = null,
  val live: LiveState = LiveState.NONE,
  /** The guard is under their own live duress (R14): nothing may make a sound. */
  val quiet: Boolean = false,
  /** This phone recorded "I'm responding" for the alert. */
  val answeredResponding: Boolean = false,
)

/** What the phone itself says, read at the moment the message arrives. */
data class PhoneFacts(
  val notificationsEnabled: Boolean = true,
  val ringChannel: ChannelState = ChannelState.OK,
  val safetyChannelExists: Boolean = true,
)

data class Verdict(val decision: Decision, val receipt: Receipt? = null, val detail: String? = null)

object RingDecider {
  /** A message arrived. `facts` answers for the id it names (or, for a visible push, the id in its tag). */
  fun decide(parsed: Parsed, facts: (String) -> StoreFacts, phone: PhoneFacts): Verdict = when (parsed) {
    is Parsed.NotRing -> visible(parsed, facts)
    is Parsed.Malformed -> Verdict(Decision.IGNORE_MALFORMED)
    is Parsed.VisibleStop -> {
      val live = facts(parsed.id).live
      Verdict(Decision.STOP_VISIBLE, if (live == LiveState.RINGING || live == LiveState.HELD) Receipt.STOPPED else null)
    }
    is Parsed.Ring -> when (parsed.payload.type) {
      RingTypes.RING -> ring(parsed.payload, facts(parsed.payload.id), phone)
      RingTypes.STOP -> stop(facts(parsed.payload.id))
      else -> test(facts(parsed.payload.id), phone)
    }
  }

  /**
   * D19. A VISIBLE Expo push for an alert whose native ring is sounding right now is the same alert twice: drop it
   * before expo-notifications presents it. Only while RINGING: once the ring has gone quiet (held), a visible re-ring
   * may be the only sound of its round if the data message was lost, so it is let through. A visible re-ring for an
   * alert this phone was told has stopped is stale, and is dropped too. Everything else is today's push: PASS.
   */
  private fun visible(parsed: Parsed.NotRing, facts: (String) -> StoreFacts): Verdict {
    val id = RingParser.ringTagId(parsed.tag) ?: return Verdict(Decision.PASS)
    if (parsed.bodyType != null && parsed.bodyType != "site_alert") return Verdict(Decision.PASS)
    val f = facts(id)
    return if (f.live == LiveState.RINGING || f.stopped) Verdict(Decision.DROP_VISIBLE_DUPLICATE) else Verdict(Decision.PASS)
  }

  private fun ring(p: RingPayload, f: StoreFacts, phone: PhoneFacts): Verdict {
    if (f.stopped) return Verdict(Decision.IGNORE_STOPPED, Receipt.STOPPED)
    if (f.quiet) return Verdict(Decision.SUPPRESS_DURESS, Receipt.SUPPRESSED)
    if (f.declinedForAlert) return Verdict(Decision.IGNORE_DECLINED)
    val last = f.lastRound
    if (last != null && p.round <= last) return Verdict(Decision.IGNORE_DUPLICATE)
    return canSound(phone) ?: Verdict(Decision.RING, Receipt.SHOWN)
  }

  private fun test(f: StoreFacts, phone: PhoneFacts): Verdict {
    if (f.quiet) return Verdict(Decision.SUPPRESS_DURESS, Receipt.SUPPRESSED)
    return canSound(phone) ?: Verdict(Decision.TEST, Receipt.SHOWN)
  }

  /** D9, D10, D11, D20: the phone cannot ring the way the server was told it can. Null when it can. */
  private fun canSound(phone: PhoneFacts): Verdict? {
    if (!phone.notificationsEnabled) return Verdict(Decision.IGNORE_NOTIFICATIONS_OFF, Receipt.FALLBACK, "notifications_off")
    val detail = when (phone.ringChannel) {
      ChannelState.OK -> return null
      ChannelState.BLOCKED -> "channel_blocked"
      ChannelState.MISSING -> "channel_missing"
    }
    return Verdict(if (phone.safetyChannelExists) Decision.FALLBACK_SAFETY else Decision.FALLBACK_HELD, Receipt.FALLBACK, detail)
  }

  private fun stop(f: StoreFacts): Verdict = when {
    f.live == LiveState.SUPPRESSED -> Verdict(Decision.STOP_SILENT)
    f.answeredResponding -> Verdict(Decision.STOP_KEEP_SCREEN, Receipt.STOPPED)
    f.live == LiveState.RINGING || f.live == LiveState.HELD -> Verdict(Decision.STOP, Receipt.STOPPED)
    else -> Verdict(Decision.STOP_QUIET)
  }

  /** How long before the hold deadline a delete still counts as the guard's swipe rather than the system's timeout. */
  const val DELETE_SLACK_MS = 2_000L

  /** D24, D25: the ring's (or the held notification's) delete intent fired. Was it the guard, or the 45-second timeout? */
  fun onDelete(which: String, postedAtMs: Long, holdSeconds: Int, nowMs: Long): Verdict {
    if (which == "held") return Verdict(Decision.RECORD_DISMISSED, Receipt.DISMISSED, "held")
    val deadline = postedAtMs + holdSeconds * 1000L - DELETE_SLACK_MS
    return if (nowMs >= deadline) Verdict(Decision.RECORD_HELD, Receipt.HELD) else Verdict(Decision.RECORD_DISMISSED, Receipt.DISMISSED, "ring")
  }
}

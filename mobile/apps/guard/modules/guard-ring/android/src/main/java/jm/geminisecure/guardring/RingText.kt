package jm.geminisecure.guardring

/**
 * Every word the ring puts on a lock screen (GUARD_RING_DESIGN §7.3, §7.4, §5.4). Pure, so a test reads them.
 *
 * The resident's name is never here: a ring carries the estate, the lot and the kind, nothing more. There is no
 * "security" kind on the server — panic, duress and intrusion all read SECURITY, medical and fire read as
 * themselves (the design's §0.3 item 6).
 *
 * THE STOP'S WORDS FOLLOW WHY IT STOPPED, AND ONLY A CLOSED OR CANCELLED ALERT IS A STAND-DOWN (G3). An
 * acknowledgement is a person in the control room pressing a button; nobody has been sent. So "Nothing more for you
 * to do" is said only when the alert is over.
 */
object RingText {
  fun kindLine(kind: String): String = when (kind) {
    "medical" -> "MEDICAL · Medical emergency"
    "fire" -> "FIRE · Fire reported"
    "panic" -> "SECURITY · Panic alert"
    "intrusion" -> "SECURITY · Intrusion reported"
    "duress" -> "SECURITY · Officer duress"
    else -> "ALERT · Emergency alert"
  }

  fun kindName(kind: String?): String = when (kind) {
    "medical" -> "Medical emergency"
    "fire" -> "Fire reported"
    "panic" -> "Panic alert"
    "intrusion" -> "Intrusion reported"
    "duress" -> "Officer duress"
    else -> "Emergency alert"
  }

  const val DRILL = "DRILL · "
  const val DRILL_BAND = "DRILL · practice alert"

  fun title(kind: String, drill: Boolean): String = (if (drill) DRILL else "") + kindLine(kind)

  /** "Lot 47 · Phoenix Park" — whichever of the two the ring carries. */
  fun place(where: String, estate: String): String = listOf(where, estate).filter { it.isNotBlank() }.joinToString(" · ")

  /** "1 min" · "45 s" · "12 min". Never negative. */
  fun ago(seconds: Long): String {
    val s = seconds.coerceAtLeast(0L)
    return if (s < 60) "$s s" else "${s / 60} min"
  }

  /** "1 min 12 s" for the ring screen, which ticks each second. */
  fun agoExact(seconds: Long): String {
    val s = seconds.coerceAtLeast(0L)
    return if (s < 60) "$s s" else "${s / 60} min ${s % 60} s"
  }

  /** The ring notification's text: "Lot 47 · Phoenix Park · raised 1 min ago (2:11 AM)". */
  fun text(where: String, estate: String, ageSeconds: Long, at: String): String {
    val raised = "raised ${ago(ageSeconds)} ago" + if (at.isNotBlank()) " ($at)" else ""
    return listOf(place(where, estate), raised).filter { it.isNotBlank() }.joinToString(" · ")
  }

  /** The ring screen's clock line: "Raised 1 min 12 s ago · 2:11 AM". */
  fun raisedLine(ageSeconds: Long, at: String): String = "Raised ${agoExact(ageSeconds)} ago" + if (at.isNotBlank()) " · $at" else ""

  /** "Ring 2: every officer of Phoenix Park is being rung" — only when the round is the escalation. */
  fun escalationLine(round: Int, estate: String): String =
    "Ring ${round + 1}: every officer of ${estate.ifBlank { "the estate" }} is being rung"

  fun heldTitle(kind: String, drill: Boolean): String = (if (drill) DRILL else "") + "Unanswered · " + kindName(kind)

  fun heldText(where: String, estate: String): String = listOf(place(where, estate), "Tap to respond.").filter { it.isNotBlank() }.joinToString(" — ")

  const val TEST_TITLE = "Test ring · GeminiSecure Guard"
  const val TEST_TEXT = "This is a test ring. If you can hear it, this phone is set up to ring like a call."
  const val TEST_SCREEN = "This is a test ring. Nothing has happened at the estate."

  data class Words(val title: String, val text: String, val standDown: Boolean)

  /** §5.4's table. `kind` and `where` are from the ring this phone had for the alert, when it had one. */
  fun stop(why: String?, who: String?, kind: String?, where: String, estate: String, drill: Boolean): Words {
    val name = if (kind.isNullOrBlank()) null else kindName(kind)
    val at = place(where, "").ifBlank { estate }
    val lead = if (at.isBlank()) "" else "At $at: "
    val prefix = if (drill) DRILL else ""
    fun head(word: String) = prefix + word + (name?.let { " · $it" } ?: "")
    fun sentence(body: String) = if (lead.isEmpty()) body.replaceFirstChar { it.uppercase() } else lead + body
    return when (why) {
      "closed" -> Words(head("Stood down"), sentence("the alert was closed. Nothing more for you to do."), true)
      "cancelled" -> Words(head("Stood down"), sentence("the alert was cancelled. Nothing more for you to do."), true)
      "responding" -> Words(head("Ringing stopped"), sentence("${who ?: "an officer"} is responding. Follow dispatch's instructions."), false)
      "acknowledged" -> Words(head("Ringing stopped"), sentence("the control room has this alert. Follow dispatch's instructions."), false)
      else -> Words(head("Ringing stopped"), sentence("the ringing was stopped. Follow dispatch's instructions."), false)
    }
  }

  const val FALLBACK_TEXT = "Open GeminiSecure Guard to respond."
}

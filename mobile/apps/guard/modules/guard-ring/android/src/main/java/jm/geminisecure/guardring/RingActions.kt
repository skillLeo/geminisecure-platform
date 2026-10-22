package jm.geminisecure.guardring

/**
 * THE TWO BUTTONS, AS PURE DECISIONS (GUARD_RING_DESIGN §7.7, §7.9 "RingActions rows").
 *
 * "I'm responding" tells a resident an officer is on the way and stops every other guard's phone ringing, so it is
 * never accepted from a phone nobody has proved is theirs: a locked phone must be unlocked first, and a phone with no
 * screen lock at all needs the guard's fingerprint or face, or cannot answer (ruling R10a). "Can't respond" needs no
 * proof — the worst a stranger can do is decline, and everyone else keeps being rung — but from the LOCK SCREEN it
 * declines this round only, so a pocket press never silences the only guard on duty for good (ruling R10b).
 *
 * Every sentence the ring screen shows for an answer is here, so a test holds each one to what actually happened.
 */

enum class Action { RESPOND, CANNOT }

enum class Screen {
  REQUIRE_UNLOCK,
  REQUIRE_BIOMETRIC,
  REFUSE_NO_LOCK,
  SENDING,
  OPEN_ALERT,
  RECORDED_NO_SESSION,
  SERVER_SENTENCE,
  CANNOT_ANSWER_HERE,
  NOT_SENT_YET,
  TOO_LATE,
  DECLINED,
  DECLINED_ROUND,
  DECLINE_NOT_SENT,
}

/** The lock screen as the phone has it at the moment of the tap. */
data class LockFacts(val locked: Boolean, val secure: Boolean, val biometric: Boolean)

/** What the server answered, or `status = -1` when nothing came back (no signal, a timeout). */
data class AnswerResult(val status: Int, val code: String? = null, val message: String? = null, val sessionOpen: Boolean = false)

data class Plan(
  val screen: Screen,
  /** Stop the insistent sound: cancel the ring, leaving the silent "Unanswered" notification. */
  val silence: Boolean = false,
  /** Cancel the ring AND the "Unanswered" notification: this alert needs nothing more from this phone. */
  val clear: Boolean = false,
  val storePending: Boolean = false,
  val retrySeconds: Int? = null,
  val markDeclined: Boolean = false,
  val scope: String? = null,
  val message: String? = null,
)

object RingActions {
  const val NOT_SENT = "Not sent yet: no signal. Radio dispatch now."
  const val TOO_LATE = "Not sent: too late to claim by phone. Radio dispatch to say whether you are going."
  const val NO_LOCK = "This phone has no screen lock, so a ring cannot be answered from it. Radio dispatch, and ask the office to set a screen lock."
  const val UNLOCK_FIRST = "Unlock your phone, then tap I'm responding."
  const val RECORDED_NO_SESSION = "Recorded: you're responding. Dispatch and the resident have been told. Your Guard App session timed out, so the alert can't open there — follow it by radio."
  const val OPENING = "Recorded: you're responding. Dispatch and the resident have been told."
  const val CANNOT_HERE = "This ring can no longer be answered here. Open the Guard App or radio dispatch."
  const val DECLINED = "Recorded: you can't respond. The other officers are still being rung."
  const val DECLINED_ROUND = "Declined for this ring. If nobody answers, you will be rung again."
  const val DECLINE_NOT_SENT = "Not sent yet: no signal. It will be sent when this phone is back online."
  const val SENDING = "Sending…"
  /** A server answer that is not the contract's shape is never shown as it came. */
  const val SERVER_UNREADABLE = "The server did not accept this. Radio dispatch."

  /**
   * The tap, before anything is sent. `declinedBefore`: this guard already declined an earlier round of the same
   * alert from the lock screen, so a second lock-screen decline is taken as meant (scope `alert`).
   */
  fun before(action: Action, lock: LockFacts, declinedBefore: Boolean = false): Plan = when (action) {
    Action.RESPOND -> when {
      !lock.secure && lock.biometric -> Plan(Screen.REQUIRE_BIOMETRIC)
      !lock.secure -> Plan(Screen.REFUSE_NO_LOCK, message = NO_LOCK)
      lock.locked -> Plan(Screen.REQUIRE_UNLOCK, message = UNLOCK_FIRST)
      else -> Plan(Screen.SENDING, silence = true, storePending = true, message = SENDING)
    }
    Action.CANNOT ->
      if (lock.locked && !declinedBefore) Plan(Screen.DECLINED_ROUND, clear = true, storePending = true, scope = "round", message = DECLINED_ROUND)
      else Plan(Screen.DECLINED, clear = true, storePending = true, markDeclined = true, scope = "alert", message = DECLINED)
  }

  /** What the server said to "I'm responding". */
  fun afterRespond(result: AnswerResult): Plan {
    val s = result.status
    return when {
      s == 200 && result.sessionOpen -> Plan(Screen.OPEN_ALERT, clear = true, message = OPENING)
      s == 200 -> Plan(Screen.RECORDED_NO_SESSION, clear = true, message = RECORDED_NO_SESSION)
      s == 409 && result.code == "ring_answer_stale" -> Plan(Screen.SERVER_SENTENCE, clear = true, message = sentence(result, TOO_LATE))
      s == 429 -> Plan(Screen.NOT_SENT_YET, silence = true, storePending = true, retrySeconds = 10, message = NOT_SENT)
      s == 403 || s == 409 -> Plan(Screen.SERVER_SENTENCE, clear = true, message = sentence(result, SERVER_UNREADABLE))
      s == 401 || s == 410 -> Plan(Screen.CANNOT_ANSWER_HERE, clear = true, message = CANNOT_HERE)
      s < 0 || s >= 500 -> Plan(Screen.NOT_SENT_YET, silence = true, storePending = true, retrySeconds = 10, message = NOT_SENT)
      else -> Plan(Screen.CANNOT_ANSWER_HERE, clear = true, message = CANNOT_HERE)
    }
  }

  /** What the server said to "Can't respond". The decline is already done on the phone; only a failure changes anything. */
  fun afterCannot(result: AnswerResult, scope: String): Plan {
    val s = result.status
    val done = if (scope == "round") Plan(Screen.DECLINED_ROUND, clear = true, scope = scope, message = DECLINED_ROUND)
    else Plan(Screen.DECLINED, clear = true, markDeclined = true, scope = scope, message = DECLINED)
    return when {
      s in 200..299 -> done
      s == 429 || s < 0 || s >= 500 -> done.copy(screen = Screen.DECLINE_NOT_SENT, storePending = true, retrySeconds = 10, message = DECLINE_NOT_SENT)
      else -> done
    }
  }

  /** D26: a "responding" that never got through expires 120 s after the tap, by the monotonic clock. */
  fun tooLate(): Plan = Plan(Screen.TOO_LATE, clear = true, message = TOO_LATE)

  /** The server's own sentence, when it sent one in the contract's shape; otherwise a plain one. */
  private fun sentence(result: AnswerResult, otherwise: String): String {
    val m = result.message?.trim()
    return if (m.isNullOrEmpty() || m.length > 300) otherwise else m
  }
}

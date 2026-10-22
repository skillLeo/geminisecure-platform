package jm.geminisecure.guardring

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Every "actions" row of spec/decisions.json: the two buttons, before and after the server answers. */
class RingActionsTest {
  private fun result(o: JSONObject): AnswerResult = AnswerResult(
    status = o.getInt("status"),
    code = if (o.has("code")) o.getString("code") else null,
    message = if (o.has("message")) o.getString("message") else null,
    sessionOpen = o.optBoolean("sessionOpen"),
  )

  @Test
  fun everyActionRow() {
    val rows = Spec.rows("actions")
    assertTrue(rows.size >= 20)
    for (row in rows) {
      val id = row.getString("id")
      val plan = when (row.getString("phase")) {
        "before" -> {
          val lock = row.getJSONObject("lock")
          RingActions.before(Action.valueOf(row.getString("action")), LockFacts(lock.getBoolean("locked"), lock.getBoolean("secure"), lock.getBoolean("biometric")), row.optBoolean("declinedBefore"))
        }
        "respond" -> RingActions.afterRespond(result(row.getJSONObject("result")))
        "cannot" -> RingActions.afterCannot(result(row.getJSONObject("result")), row.getString("scope"))
        "tooLate" -> RingActions.tooLate()
        else -> throw AssertionError("$id: unknown phase")
      }
      assertEquals("$id screen", row.getString("screen"), plan.screen.name)
      for (flag in listOf("silence", "clear", "storePending", "markDeclined")) {
        if (!row.has(flag)) continue
        val got = when (flag) {
          "silence" -> plan.silence
          "clear" -> plan.clear
          "storePending" -> plan.storePending
          else -> plan.markDeclined
        }
        assertEquals("$id $flag", row.getBoolean(flag), got)
      }
      if (row.has("scope")) assertEquals("$id scope", row.getString("scope"), plan.scope)
      if (row.has("retrySeconds")) assertEquals("$id retry", row.getInt("retrySeconds"), plan.retrySeconds)
      if (row.has("message")) assertEquals("$id message", row.getString("message"), plan.message)
    }
  }

  @Test
  fun aStrangerCanNeverSayAnOfficerIsComing() {
    // Every lock state that has not proved who is holding the phone stops short of sending "responding".
    for (lock in listOf(LockFacts(true, true, true), LockFacts(true, true, false), LockFacts(false, false, false), LockFacts(true, false, false))) {
      val plan = RingActions.before(Action.RESPOND, lock)
      assertFalse("$lock must not send", plan.storePending)
      assertTrue("$lock is not SENDING", plan.screen != Screen.SENDING)
    }
  }

  @Test
  fun aServerSentenceThatIsNotTheContractIsNeverShownAsItCame() {
    val long = "x".repeat(400)
    assertEquals(RingActions.SERVER_UNREADABLE, RingActions.afterRespond(AnswerResult(403, "another_officer_responding", long)).message)
  }

  @Test
  fun nothingIsLostWhenTheAnswerCannotBeSent() {
    for (status in listOf(-1, 429, 500, 502, 503)) {
      val plan = RingActions.afterRespond(AnswerResult(status))
      assertTrue("$status keeps it", plan.storePending)
      assertTrue("$status silences the ring", plan.silence)
      assertFalse("$status keeps the Unanswered notification", plan.clear)
    }
  }
}

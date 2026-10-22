package jm.geminisecure.guardring

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Every "decider", "launch", "delete" and "restore" row of spec/decisions.json, against the Kotlin. */
class RingDeciderTest {
  private fun storeFacts(o: JSONObject?): StoreFacts {
    if (o == null) return StoreFacts()
    return StoreFacts(
      stopped = o.optBoolean("stopped"),
      declinedForAlert = o.optString("declined") == "alert",
      lastRound = if (o.has("lastRound")) o.getInt("lastRound") else null,
      live = LiveState.valueOf(o.optString("live", "NONE")),
      quiet = o.optBoolean("quiet"),
      answeredResponding = o.optBoolean("answered"),
    )
  }

  private fun phoneFacts(o: JSONObject?): PhoneFacts = PhoneFacts(
    notificationsEnabled = Spec.optBool(o, "notificationsEnabled", true),
    ringChannel = ChannelState.valueOf(o?.optString("ringChannel", "OK") ?: "OK"),
    safetyChannelExists = Spec.optBool(o, "safetyChannelExists", true),
  )

  @Test
  fun everyDeciderRow() {
    val rows = Spec.rows("decider")
    assertTrue("the table has its rows", rows.size >= 28)
    for (row in rows) {
      val id = row.getString("id")
      val parsed = RingParser.parse(Spec.fcmData(row))
      val mine = storeFacts(row.optJSONObject("store"))
      val others = row.optJSONObject("otherLive")
      val facts = { alert: String ->
        if (others != null && others.has(alert)) StoreFacts(live = LiveState.valueOf(others.getString(alert))) else mine
      }
      val verdict = RingDecider.decide(parsed, facts, phoneFacts(row.optJSONObject("phone")))
      assertEquals("$id decision", row.getString("decision"), verdict.decision.name)
      assertEquals("$id receipt", if (row.has("receipt")) row.getString("receipt") else null, verdict.receipt?.wire)
      if (row.has("detail")) assertEquals("$id detail", row.getString("detail"), verdict.detail)

      row.optJSONObject("expect")?.let { expect ->
        val p = (parsed as? Parsed.Ring)?.payload
        assertNotNull("$id parses to a ring", p)
        p!!
        for (key in expect.keys()) {
          val want = expect.get(key)
          val got: Any? = when (key) {
            "round" -> p.round
            "drill" -> p.drill
            "hold" -> p.holdSeconds
            "where" -> p.where
            "estate" -> p.estate
            "kind" -> p.kind
            "id" -> p.id
            "escalation" -> p.escalation
            "age" -> p.ageSeconds.toInt()
            "ticket" -> p.ticket != null
            else -> throw AssertionError("$id: unknown expectation $key")
          }
          assertEquals("$id $key", want, got)
        }
      }
      if (row.has("title")) {
        val p = (parsed as Parsed.Ring).payload
        assertEquals("$id title", row.getString("title"), RingText.title(p.kind, p.drill))
      }
      row.optJSONObject("words")?.let { words ->
        val p = (parsed as Parsed.Ring).payload
        val ring = Spec.decisions.getJSONObject("base").getJSONObject("ring")
        val known = row.has("store") && row.getJSONObject("store").has("live")
        val w = RingText.stop(p.why, p.who, if (known) ring.getString("kind") else null, if (known) ring.getString("where") else "", if (known) ring.getString("estate") else "", false)
        assertEquals("$id stop title", words.getString("title"), w.title)
        assertEquals("$id stop text", words.getString("text"), w.text)
        assertEquals("$id stand-down", words.getBoolean("standDown"), w.standDown)
      }
    }
  }

  @Test
  fun everyLaunchRow() {
    for (row in Spec.rows("launch")) {
      val v = RingLaunch.verdictFor(Launch.of(row.getString("launch")))
      assertEquals("${row.getString("id")} decision", row.getString("decision"), v.decision.name)
      assertEquals("${row.getString("id")} receipt", row.getString("receipt"), v.receipt?.wire)
    }
  }

  @Test
  fun everyDeleteRow() {
    val now = 1_790_000_000_000L
    for (row in Spec.rows("delete")) {
      val v = RingDecider.onDelete(row.getString("which"), now - row.getLong("postedAgoMs"), row.getInt("hold"), now)
      assertEquals("${row.getString("id")} decision", row.getString("decision"), v.decision.name)
      assertEquals("${row.getString("id")} receipt", row.getString("receipt"), v.receipt?.wire)
    }
  }

  @Test
  fun everySendRow() {
    for (row in Spec.rows("send")) {
      assertEquals(row.getString("id"), row.getString("decision"), RingPendingRule.afterSend(row.getInt("status")).decision.name)
    }
  }

  @Test
  fun everyRestoreRow() {
    val now = 1_790_000_000_000L
    for (row in Spec.rows("restore")) {
      val id = row.getString("id")
      val store = RingStore(MemoryKeyValue())
      val base = Spec.decisions.getJSONObject("base").getJSONObject(if (row.optBoolean("test")) "test" else "ring")
      val data = mapOf("body" to JSONObject(base.toString()).put("hold", row.getInt("hold")).toString())
      val p = (RingParser.parse(data) as Parsed.Ring).payload
      val posted = now - row.getLong("postedAgoMs")
      store.recordRing(p, posted, 1_000L, 7, LiveState.valueOf(row.getString("state")))
      val plan = store.restorePlan(now)
      if (row.getString("decision") == "NONE") {
        assertEquals("$id restores nothing", 0, plan.size)
        continue
      }
      assertEquals("$id restores one", 1, plan.size)
      val item = plan[0]
      val want = if (row.isNull("ringForMs")) null else row.getLong("ringForMs")
      assertEquals("$id ring for", want, item.ringForMs)
      assertEquals("$id held", row.getBoolean("held"), item.held)
    }
  }

  @Test
  fun aStopAlwaysBeatsARingThatLandsAfterIt() {
    val data = Spec.fcmData(JSONObject().put("message", JSONObject().put("base", "ring").put("set", JSONObject().put("r", 3))))
    val v = RingDecider.decide(RingParser.parse(data), { StoreFacts(stopped = true, quiet = true, lastRound = 1) }, PhoneFacts())
    assertEquals(Decision.IGNORE_STOPPED, v.decision)
  }
}

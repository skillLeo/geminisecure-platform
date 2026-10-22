package jm.geminisecure.guardring

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class RingStoreTest {
  private val now = 1_790_000_000_000L

  private fun ring(id: String, round: Int = 0, test: Boolean = false): RingPayload {
    val base = JSONObject(Spec.decisions.getJSONObject("base").getJSONObject(if (test) "test" else "ring").toString())
    base.put("id", id)
    if (!test) base.put("r", round)
    return (RingParser.parse(mapOf("body" to base.toString())) as Parsed.Ring).payload
  }

  private fun pending(key: String, event: String = "shown", kind: String = Pending.RECEIPT, created: Long = now, tapEl: Long = 5_000L, boot: Int = 7) =
    Pending(key, kind, "4812", "v1.x.y", event, null, null, null, created, tapEl, boot, created + RingStore.TICKET_MS)

  @Test
  fun stoppedIdsAreCappedAt200AndForgottenAfterADay() {
    val store = RingStore(MemoryKeyValue())
    for (i in 1..250) store.markStopped("a$i", now + i)
    assertEquals(200, store.stoppedCount(now + 300))
    assertFalse("the oldest went first", store.isStopped("a1", now + 300))
    assertTrue(store.isStopped("a250", now + 300))
    assertFalse("a day later it is forgotten", store.isStopped("a250", now + 250 + RingStore.DAY_MS))
  }

  @Test
  fun pendingIsCappedAt50AndAnAnswerOutlivesAReceipt() {
    val store = RingStore(MemoryKeyValue())
    store.addPending(pending("answer", event = "responding", kind = Pending.ANSWER))
    for (i in 1..60) store.addPending(pending("r$i", created = now + i))
    val left = store.pending()
    assertEquals(50, left.size)
    assertTrue("the answer is kept over receipts", left.any { it.key == "answer" })
  }

  @Test
  fun pendingPastItsTicketIsDropped() {
    val store = RingStore(MemoryKeyValue())
    store.addPending(pending("old", created = now - RingStore.TICKET_MS - 1))
    store.addPending(pending("new"))
    assertEquals(listOf("new"), store.purgePending(now, 6_000L, 7).map { it.key })
  }

  @Test
  fun aReceiptIsInPendingBeforeItIsSentAndGoneAfterA2xx() {
    val store = RingStore(MemoryKeyValue())
    val p = pending("4812:shown:1")
    store.addPending(p) // written first (G22)
    assertEquals(1, store.pending().size)
    // A send that never finished leaves it there.
    assertEquals(Decision.KEEP_PENDING, RingPendingRule.afterSend(-1).decision)
    assertEquals(1, store.pending().size)
    // A 2xx deletes it.
    if (RingPendingRule.afterSend(202).decision == Decision.DELETE_PENDING) store.removePending(p.key)
    assertEquals(0, store.pending().size)
  }

  @Test
  fun aPendingRespondingIsDroppedAt120sAndOnANewBootWhileACannotIsKept() {
    val store = RingStore(MemoryKeyValue())
    val tapEl = 10_000L
    store.addPending(pending("resp", event = "responding", kind = Pending.ANSWER, tapEl = tapEl))
    store.addPending(pending("cant", event = "cannot", kind = Pending.ANSWER, tapEl = tapEl))
    assertEquals(setOf("resp", "cant"), store.purgePending(now, tapEl + 119_000L, 7).map { it.key }.toSet())
    assertEquals(listOf("cant"), store.purgePending(now, tapEl + 121_000L, 7).map { it.key })
    store.addPending(pending("resp2", event = "responding", kind = Pending.ANSWER, tapEl = tapEl))
    assertEquals(listOf("cant"), store.purgePending(now, tapEl + 1_000L, 8).map { it.key })
  }

  @Test
  fun theRingingListKeepsTwoAlertsInOrderAndRemovingOneLeavesTheOther() {
    val store = RingStore(MemoryKeyValue())
    store.recordRing(ring("4812"), now, 1_000L, 7, LiveState.RINGING)
    store.recordRing(ring("4815"), now + 5_000, 6_000L, 7, LiveState.RINGING)
    assertEquals(listOf("4815", "4812"), store.ringing(now + 6_000).map { it.id })
    store.markStopped("4815", now + 7_000)
    assertEquals(listOf("4812"), store.ringing(now + 8_000).map { it.id })
  }

  @Test
  fun restoreReturnsOnlyRingsInsideTheirHoldAndHeldOnesInsideTheTicket() {
    val store = RingStore(MemoryKeyValue())
    store.recordRing(ring("1"), now - 10_000, 0L, 7, LiveState.RINGING) // 35 s left
    store.recordRing(ring("2"), now - 60_000, 0L, 7, LiveState.RINGING) // past its hold: silent
    store.recordRing(ring("3"), now - 60_000, 0L, 7, LiveState.HELD)
    store.recordRing(ring("4"), now - RingStore.TICKET_MS - 1, 0L, 7, LiveState.HELD) // ticket gone
    store.recordRing(ring("5"), now - 5_000, 0L, 7, LiveState.SUPPRESSED)
    val plan = store.restorePlan(now).associateBy { it.ring.id }
    assertEquals(setOf("1", "2", "3"), plan.keys)
    assertEquals(35_000L, plan.getValue("1").ringForMs)
    assertNull(plan.getValue("2").ringForMs)
    assertNull(plan.getValue("3").ringForMs)
    assertTrue(plan.values.all { it.held })
  }

  @Test
  fun factsFollowWhatHappened() {
    val store = RingStore(MemoryKeyValue())
    assertEquals(StoreFacts(), store.facts("4812", now))
    store.recordRing(ring("4812", round = 1), now, 1_000L, 7, LiveState.RINGING)
    assertEquals(1, store.facts("4812", now).lastRound)
    assertEquals(LiveState.RINGING, store.facts("4812", now).live)
    store.decline("4812", "round", now)
    assertFalse("a lock-screen decline is not a decline of the alert", store.facts("4812", now).declinedForAlert)
    assertTrue(store.declinedRoundBefore("4812", now))
    store.decline("4812", "alert", now)
    assertTrue(store.facts("4812", now).declinedForAlert)
    store.markAnswered("4812", now)
    assertTrue(store.facts("4812", now).answeredResponding)
  }

  @Test
  fun aRingPastItsHoldIsQuietEvenIfNothingLivedToSaySo() {
    // The process died before its timer fired: the system's timeout took the ring, and nothing wrote HELD.
    val store = RingStore(MemoryKeyValue())
    store.recordRing(ring("4812"), now, 1_000L, 7, LiveState.RINGING)
    assertEquals(LiveState.RINGING, store.facts("4812", now + 44_000).live)
    assertEquals(LiveState.HELD, store.facts("4812", now + 45_000).live)
    assertEquals(LiveState.HELD, store.ringing(now + 46_000).single().state)
    // …so the next round's visible re-ring is let through, not dropped as a duplicate (D19b).
    val visible = Parsed.NotRing("ring-4812", "site_alert")
    assertEquals(Decision.PASS, RingDecider.decide(visible, { store.facts(it, now + 46_000) }, PhoneFacts()).decision)
  }

  @Test
  fun quietEndsByItself() {
    val store = RingStore(MemoryKeyValue())
    store.setQuiet(now + 60_000)
    assertTrue(store.facts("x", now).quiet)
    assertFalse(store.facts("x", now + 60_001).quiet)
    store.setQuiet(null)
    assertFalse(store.isQuiet(now))
  }

  @Test
  fun forgetClearsTheLastGuardsRingsButKeepsTheAddressAndQuiet() {
    val kv = MemoryKeyValue()
    val store = RingStore(kv)
    store.apiBase = "https://example.invalid/api/v1"
    store.setQuiet(now + 60_000)
    store.recordRing(ring("4812"), now, 1_000L, 7, LiveState.RINGING)
    store.markStopped("9", now)
    store.addPending(pending("p"))
    store.setLaunch("4812")
    store.forget()
    assertEquals("https://example.invalid/api/v1", store.apiBase)
    assertTrue(store.isQuiet(now))
    assertTrue(store.ringing(now).isEmpty())
    assertFalse(store.isStopped("9", now))
    assertTrue(store.pending().isEmpty())
    assertNull(store.consumeLaunch())
  }

  @Test
  fun anUnreadableStoreIsAnEmptyOneNeverACrash() {
    val kv = MemoryKeyValue()
    for (key in listOf("stopped", "declined", "lastRound", "live", "pending", "answered", "quietUntil")) kv.put(key, "{not json")
    val store = RingStore(kv)
    assertEquals(StoreFacts(), store.facts("4812", now))
    assertTrue(store.pending().isEmpty())
    assertTrue(store.ringing(now).isEmpty())
  }

  @Test
  fun theLaunchIsConsumedOnce() {
    val store = RingStore(MemoryKeyValue())
    store.setLaunch("4812")
    assertEquals("4812", store.consumeLaunch())
    assertNull(store.consumeLaunch())
  }

  @Test
  fun theAgeTicksByTheMonotonicClockAndFallsBackToTheWallAfterARestart() {
    val r = LiveRing.of(ring("4812"), now, 10_000L, 7, LiveState.RINGING) // age 3 at receipt
    assertEquals(13L, r.ageNow(now + 999_999, 20_000L, 7))
    assertEquals(63L, r.ageNow(now + 60_000, 500L, 8))
  }
}

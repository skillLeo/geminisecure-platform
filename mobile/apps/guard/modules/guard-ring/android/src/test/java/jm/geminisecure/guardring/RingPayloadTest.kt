package jm.geminisecure.guardring

import java.io.File
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test

/** How a push is read, including the server's own contract fixtures (spec/contract/). */
class RingPayloadTest {
  /** A server-built Expo message → the FCM data map the phone receives: `data` as the JSON string `body`. */
  private fun received(message: JSONObject): Map<String, String> = mapOf("body" to message.getJSONObject("data").toString())

  @Test
  fun theServersSiteRingParses() {
    val m = Spec.contract("site_ring.json")
    val p = (RingParser.parse(received(m)) as Parsed.Ring).payload
    assertEquals(RingTypes.RING, p.type)
    assertEquals("4812", p.id)
    assertEquals("medical", p.kind)
    assertEquals(1, p.round)
    assertEquals(5, p.lastRound)
    assertTrue(p.escalation)
    assertFalse(p.drill)
    assertEquals("Phoenix Park", p.estate)
    assertEquals("Lot 47", p.where)
    assertEquals(61L, p.ageSeconds)
    assertEquals("2:11 AM", p.at)
    assertEquals(45, p.holdSeconds)
    // The fixture's ticket is a stand-in (`<ticket>`): what matters here is that it is carried through, untouched.
    assertEquals("<ticket>", p.ticket)
  }

  @Test
  fun theServersDataOnlyStopParses() {
    val p = (RingParser.parse(received(Spec.contract("ring_stop.json"))) as Parsed.Ring).payload
    assertEquals(RingTypes.STOP, p.type)
    assertEquals("responding", p.why)
    assertEquals("Marcus Grant", p.who)
  }

  @Test
  fun theServersTestRingParses() {
    val p = (RingParser.parse(received(Spec.contract("ring_test.json"))) as Parsed.Ring).payload
    assertEquals(RingTypes.TEST, p.type)
    assertEquals(15, p.holdSeconds)
    assertTrue(p.drill)
  }

  @Test
  fun theServersVisibleMessagesAreExpoNotificationsOwn() {
    // A visible re-ring is today's site_alert with a tag: expo-notifications presents it, unless the native ring sounds.
    val rering = Spec.contract("visible_ring.json")
    val data = received(rering) + mapOf("tag" to rering.getString("tag"), "title" to rering.getString("title"))
    assertEquals(Parsed.NotRing("ring-4812", "site_alert"), RingParser.parse(data))
    // A visible stop carries no version: it is a stop for the native ring too.
    val stop = Spec.contract("visible_stop.json")
    assertEquals(Parsed.VisibleStop("4812"), RingParser.parse(received(stop) + mapOf("tag" to stop.getString("tag"))))
  }

  @Test
  fun noServerBuiltDataCarriesPresentationalWords() {
    for (name in listOf("site_ring.json", "ring_stop.json", "ring_test.json")) {
      val data = Spec.contract(name).getJSONObject("data")
      for (key in listOf("title", "message", "body")) assertFalse("$name data.$key", data.has(key))
      val top = Spec.contract(name)
      for (key in listOf("title", "body", "sound", "channelId", "tag")) assertFalse("$name $key", top.has(key))
      assertEquals("$name priority", "high", top.getString("priority"))
    }
  }

  @Test
  fun flatKeysAreReadOnlyWhenThereIsNoBody() {
    val flat = mapOf("type" to "site_ring", "v" to "1", "id" to "4812", "kind" to "fire", "r" to "0", "estate" to "Phoenix Park", "junk" to "x")
    val p = (RingParser.parse(flat) as Parsed.Ring).payload
    assertEquals("fire", p.kind)
    // …and with a body present, the flat ring keys are ignored entirely.
    val both = flat + mapOf("body" to JSONObject().put("type", "payslip_ready").toString())
    assertEquals(Parsed.NotRing(null, "payslip_ready"), RingParser.parse(both))
  }

  @Test
  fun wordsForALockScreenAreCleanAndClipped() {
    val body = JSONObject().put("type", "site_ring").put("v", 1).put("id", "9").put("kind", "panic").put("r", 0)
      .put("estate", "A\u0000very\nlong estate name that keeps going and going past sixty characters for sure")
      .put("where", "  Lot\t47  ")
    val p = (RingParser.parse(mapOf("body" to body.toString())) as Parsed.Ring).payload
    assertEquals("Lot 47", p.where)
    assertTrue(p.estate.length <= 60)
    assertFalse(p.estate.contains('\u0000'))
    assertFalse(p.estate.contains('\n'))
  }

  @Test
  fun anOversizedTicketIsNoTicket() {
    val body = JSONObject().put("type", "site_ring").put("v", 1).put("id", "9").put("kind", "panic").put("r", 0).put("rt", "v1." + "a".repeat(2000))
    assertNull((RingParser.parse(mapOf("body" to body.toString())) as Parsed.Ring).payload.ticket)
  }

  @Test
  fun aStopsWhoIsKeptOnlyForResponding() {
    val body = JSONObject().put("type", "ring_stop").put("v", 1).put("id", "9").put("why", "closed").put("who", "Somebody")
    assertNull((RingParser.parse(mapOf("body" to body.toString())) as Parsed.Ring).payload.who)
  }

  @Test
  fun theRingTagIsOnlyOurs() {
    assertEquals("4812", RingParser.ringTagId("ring-4812"))
    assertNull(RingParser.ringTagId("ring-"))
    assertNull(RingParser.ringTagId("other-4812"))
    assertNull(RingParser.ringTagId("ring-../../x"))
    assertNull(RingParser.ringTagId(null))
  }

  /**
   * G25: the one real RemoteMessage.data from an Expo data-only push, logged on a device once Firebase exists (P-1),
   * with the token and the ticket redacted. Until that file is committed this case cannot run.
   */
  @Ignore("no received payload yet (P-1): needs Firebase and one real data-only push, logged on a device")
  @Test
  fun aRealReceivedDataOnlyPushParsesToARing() {
    val file = File(File(Spec.dir(), "contract"), "fcm-site_ring.received.json")
    val o = JSONObject(file.readText(Charsets.UTF_8))
    val data = LinkedHashMap<String, String>()
    for (k in o.keys()) data[k] = o.get(k).toString()
    assertTrue(RingParser.parse(data) is Parsed.Ring)
  }
}

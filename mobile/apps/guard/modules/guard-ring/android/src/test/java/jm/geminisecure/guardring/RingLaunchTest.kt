package jm.geminisecure.guardring

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** G12: each PendingIntent is its own (a data URI of its own), and only the system's full-screen launch counts as full screen. */
class RingLaunchTest {
  @Test
  fun everyLaunchHasItsOwnUri() {
    val uris = Launch.values().map { RingLaunch.uri("4812", it) }
    assertEquals(uris.size, uris.toSet().size)
    for (u in uris) assertTrue(u, u.startsWith("gsring://4812/"))
    assertNotEquals(RingLaunch.uri("4812", Launch.FSI), RingLaunch.uri("4815", Launch.FSI))
  }

  @Test
  fun onlyTheFullScreenIntentIsFullScreen() {
    assertEquals(Receipt.FULL_SCREEN, RingLaunch.receiptFor(Launch.FSI))
    for (l in listOf(Launch.TAP, Launch.ACTION, Launch.DIRECT)) assertEquals(Receipt.OPENED, RingLaunch.receiptFor(l))
    // An extra nobody recognises is a tap, never a full-screen launch.
    assertEquals(Launch.TAP, Launch.of("something-else"))
    assertEquals(Launch.TAP, Launch.of(null))
  }

  @Test
  fun slotsAreStablePositiveAndPerAlert() {
    assertEquals(4812, RingLaunch.slotOf("4812"))
    assertEquals(RingLaunch.slotOf("test-9f2c41d07a3b88e1"), RingLaunch.slotOf("test-9f2c41d07a3b88e1"))
    assertTrue(RingLaunch.slotOf("test-9f2c41d07a3b88e1") > 0)
    assertTrue(RingLaunch.slotOf("99999999999") > 0)
    assertTrue(RingLaunch.slotOf("0") > 0)
    assertEquals("ring-4812", RingLaunch.expoTag("4812"))
  }
}

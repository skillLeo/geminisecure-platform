package jm.geminisecure.guardring

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class RingTextTest {
  @Test
  fun theKindLines() {
    assertEquals("MEDICAL · Medical emergency", RingText.kindLine("medical"))
    assertEquals("FIRE · Fire reported", RingText.kindLine("fire"))
    for (k in listOf("panic", "intrusion", "duress")) assertTrue(k, RingText.kindLine(k).startsWith("SECURITY · "))
    assertEquals("ALERT · Emergency alert", RingText.kindLine("something-new"))
    assertEquals("DRILL · FIRE · Fire reported", RingText.title("fire", true))
  }

  @Test
  fun theRingsText() {
    assertEquals("Lot 47 · Phoenix Park · raised 1 min ago (2:11 AM)", RingText.text("Lot 47", "Phoenix Park", 61, "2:11 AM"))
    assertEquals("Phoenix Park · raised 45 s ago", RingText.text("", "Phoenix Park", 45, ""))
    assertEquals("Raised 1 min 12 s ago · 2:11 AM", RingText.raisedLine(72, "2:11 AM"))
    assertEquals("Ring 2: every officer of Phoenix Park is being rung", RingText.escalationLine(1, "Phoenix Park"))
    assertEquals("raised 0 s ago", RingText.text("", "", -5, ""))
  }

  @Test
  fun onlyAClosedOrCancelledAlertIsAStandDown() {
    for (why in listOf("acknowledged", "responding", null, "unknown")) {
      val w = RingText.stop(why, "Marcus Grant", "medical", "Lot 47", "Phoenix Park", false)
      assertFalse("$why", w.standDown)
      assertFalse("$why never says nothing more to do", w.text.contains("Nothing more for you to do"))
      assertTrue(w.title.startsWith("Ringing stopped"))
    }
    for (why in listOf("closed", "cancelled")) {
      val w = RingText.stop(why, null, "medical", "Lot 47", "Phoenix Park", false)
      assertTrue(w.standDown)
      assertTrue(w.title.startsWith("Stood down"))
    }
    assertEquals("DRILL · Stood down · Fire reported", RingText.stop("closed", null, "fire", "", "Phoenix Park", true).title)
    assertEquals("At Phoenix Park: the alert was closed. Nothing more for you to do.", RingText.stop("closed", null, "fire", "", "Phoenix Park", false).text)
  }
}

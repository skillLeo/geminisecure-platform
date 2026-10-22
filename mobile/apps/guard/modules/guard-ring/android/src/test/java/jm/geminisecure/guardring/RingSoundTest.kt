package jm.geminisecure.guardring

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** G11, G15: the sound check a `shown` receipt carries, and the readiness bitmask, as the server reads them. */
class RingSoundTest {
  private val loud = SoundFacts(importance = 4, channelHasSound = true, alarmVolume = 5, alarmVolumeMax = 7, interruptionFilter = 1, channelBypass = false, policyAllowsAlarms = null, canFullScreen = true, keyguardLocked = true)

  @Test
  fun theDetailIsTheDesignsExampleAndFits() {
    assertEquals("imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1", RingSound.detail(loud))
    assertTrue(RingSound.detail(loud.copy(importance = 99, alarmVolume = 1000, alarmVolumeMax = 1000)).length <= 60)
  }

  @Test
  fun doNotDisturbIsDecidedHonestly() {
    assertEquals("pass", RingSound.dnd(RingSound.FILTER_ALL, false, null))
    assertEquals("pass", RingSound.dnd(RingSound.FILTER_ALARMS, false, false))
    assertEquals("block", RingSound.dnd(RingSound.FILTER_NONE, true, true))
    assertEquals("pass", RingSound.dnd(RingSound.FILTER_PRIORITY, true, null))
    assertEquals("pass", RingSound.dnd(RingSound.FILTER_PRIORITY, false, true))
    assertEquals("block", RingSound.dnd(RingSound.FILTER_PRIORITY, false, false))
    assertEquals("unknown", RingSound.dnd(RingSound.FILTER_PRIORITY, false, null))
    assertEquals("unknown", RingSound.dnd(0, true, true))
  }

  @Test
  fun withTheAlarmSoundOnOnlyWhenEveryPartSaysSo() {
    assertTrue(RingSound.soundOn(RingSound.detail(loud)))
    assertFalse("a silent channel", RingSound.soundOn(RingSound.detail(loud.copy(importance = 2))))
    assertFalse("no sound on the channel", RingSound.soundOn(RingSound.detail(loud.copy(channelHasSound = false))))
    assertFalse("alarm volume 0", RingSound.soundOn(RingSound.detail(loud.copy(alarmVolume = 0))))
    assertFalse("total silence", RingSound.soundOn(RingSound.detail(loud.copy(interruptionFilter = RingSound.FILTER_NONE))))
    assertFalse("dnd unknown", RingSound.soundOn(RingSound.detail(loud.copy(interruptionFilter = 0))))
    assertFalse("unparseable", RingSound.soundOn("garbage"))
    assertFalse(RingSound.soundOn(null))
  }

  @Test
  fun theBitsAreTheContractsBits() {
    val all = ReadyFacts(true, true, true, true, true, true, true, true, true, true)
    assertEquals(1023, RingSound.bits(all))
    assertEquals(1 shl 3, RingSound.bits(ReadyFacts(false, false, false, true, false, false, false, false, false, false)))
    assertEquals(1 shl 8, RingSound.bits(ReadyFacts(false, false, false, false, false, false, false, false, true, false)))
    val bits = Spec.contract("ready-bits.json").getJSONObject("bits")
    assertEquals(10, bits.length())
    assertTrue(bits.getString("3").startsWith("dnd_bypass"))
    assertTrue(bits.getString("8").startsWith("screen_lock"))
  }

  @Test
  fun theAlarmVolumeIsBucketed() {
    assertEquals("off", RingSound.volumeBucket(0, 7))
    assertEquals("low", RingSound.volumeBucket(2, 7))
    assertEquals("ok", RingSound.volumeBucket(3, 7))
    assertEquals("ok", RingSound.volumeBucket(7, 7))
  }
}

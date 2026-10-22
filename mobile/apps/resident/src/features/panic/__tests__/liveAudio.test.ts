/**
 * "Share live audio" (M50 §4.2 #1): what the app decides and keeps — which alert the one recorder is for, what is true,
 * each piece's number (never reused, through a restart), and a resident's "off" that holds for that alert.
 */

import { getStorage } from '@gs/core/lib'
import { liveAudioState, markSwitchedOff, setLiveAudioState, switchedOff, takeSeq, wantLiveAudio, wantedAudio } from '../liveAudio'

beforeEach(async () => {
  await getStorage().clearAll()
  wantLiveAudio(null)
  setLiveAudioState('off')
})

describe('which alert the recorder is for', () => {
  it('starts when wanted, and says so until the recorder reports', () => {
    wantLiveAudio({ alertOp: 'op-1', alertId: 7 })
    expect(wantedAudio()).toEqual({ alertOp: 'op-1', alertId: 7 })
    expect(liveAudioState()).toBe('starting')
    setLiveAudioState('on')
    expect(liveAudioState()).toBe('on')
  })

  it('stops when nothing is wanted, and the switch reads off at once', () => {
    wantLiveAudio({ alertOp: 'op-1', alertId: 7 })
    setLiveAudioState('on')
    wantLiveAudio(null)
    expect(wantedAudio()).toBeNull()
    expect(liveAudioState()).toBe('off')
  })

  it('asking again for the same alert changes nothing', () => {
    wantLiveAudio({ alertOp: 'op-1', alertId: 7 })
    setLiveAudioState('on')
    wantLiveAudio({ alertOp: 'op-1', alertId: 7 })
    expect(liveAudioState()).toBe('on')
  })
})

describe('kept on the phone, per alert', () => {
  it('numbers the pieces from 0, and never gives a number twice', () => {
    expect([takeSeq('op-1'), takeSeq('op-1'), takeSeq('op-1')]).toEqual([0, 1, 2])
    expect(takeSeq('op-2')).toBe(0)
    expect(takeSeq('op-1')).toBe(3)
  })

  it('remembers that the resident switched it off for that alert, and only that one', () => {
    markSwitchedOff('op-1', true)
    expect(switchedOff('op-1')).toBe(true)
    expect(switchedOff('op-2')).toBe(false)
    expect(takeSeq('op-1')).toBe(0)
    markSwitchedOff('op-1', false)
    expect(switchedOff('op-1')).toBe(false)
  })
})

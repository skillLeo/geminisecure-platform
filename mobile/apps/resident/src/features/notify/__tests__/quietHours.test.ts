/**
 * Quiet hours on this phone (board 20; M50 §4.2 #5): the board's 10:00 PM – 7:00 AM on the ESTATE's clock, on unless
 * switched off, and "Urgent alerts still come through" exactly — someone at the gate and a panic update are shown
 * inside them; nothing else is.
 */

import { getStorage, setSiteZone } from '@gs/core/lib'
import { QUIET_FROM, QUIET_TO, allows, quietHours, quietNow, quietReport } from '../prefs'

/** 2026-10-08 at HH:MM in Jamaica (UTC-5, no daylight saving). */
const jamaica = (hhmm: string): Date => new Date(`2026-10-08T${hhmm}:00-05:00`)

beforeEach(async () => {
  await getStorage().clearAll()
  setSiteZone('America/Jamaica')
})

describe('quiet hours', () => {
  it('are the board\'s hours, on until switched off, and go with the push registration', () => {
    expect([QUIET_FROM, QUIET_TO]).toEqual(['22:00', '07:00'])
    expect(quietHours.get()).toBe(true)
    expect(quietReport()).toEqual({ on: true, from: '22:00', to: '07:00' })
    quietHours.set(false)
    expect(quietReport()).toEqual({ on: false, from: '22:00', to: '07:00' })
  })

  it('run on the estate\'s clock: from 10 PM, up to but not at 7 AM, over midnight', () => {
    expect(quietNow(jamaica('22:00'), true)).toBe(true)
    expect(quietNow(jamaica('03:30'), true)).toBe(true)
    expect(quietNow(jamaica('06:59'), true)).toBe(true)
    expect(quietNow(jamaica('07:00'), true)).toBe(false)
    expect(quietNow(jamaica('21:59'), true)).toBe(false)
    expect(quietNow(jamaica('23:00'), false)).toBe(false)
  })

  it('hold back everything but the urgent alerts while they last', () => {
    jest.useFakeTimers().setSystemTime(jamaica('23:30'))
    try {
      expect(allows('notices')).toBe(false)
      expect(allows('visitors')).toBe(false)
      expect(allows('gate')).toBe(true)
      expect(allows('panic')).toBe(true)

      quietHours.set(false)
      expect(allows('notices')).toBe(true)
    } finally {
      jest.useRealTimers()
    }
  })
})

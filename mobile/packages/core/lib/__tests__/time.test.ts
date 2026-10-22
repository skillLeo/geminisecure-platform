import { deviceTimeIso } from '../time'

describe('deviceTimeIso: X-Device-Time', () => {
  it('is ISO 8601 with an explicit offset, to the second', () => {
    const iso = deviceTimeIso(new Date(2026, 8, 17, 9, 41, 2))
    expect(iso).toMatch(/^2026-09-17T09:41:02[+-]\d{2}:\d{2}$/)
  })

  it('names the same instant it was given: the handset clock is sent, never converted', () => {
    const at = new Date(2026, 8, 17, 23, 59, 58)
    expect(new Date(deviceTimeIso(at)).getTime()).toBe(at.getTime())
  })

  it('writes the offset the way the server reads it: east of UTC is +, west is -', () => {
    const at = new Date(2026, 0, 5, 12, 0, 0)
    const minutesEast = -at.getTimezoneOffset()
    const sign = minutesEast >= 0 ? '+' : '-'
    const hh = String(Math.trunc(Math.abs(minutesEast) / 60)).padStart(2, '0')
    const mm = String(Math.abs(minutesEast) % 60).padStart(2, '0')
    expect(deviceTimeIso(at).endsWith(`${sign}${hh}:${mm}`)).toBe(true)
  })

  it('pads single digits', () => {
    expect(deviceTimeIso(new Date(2026, 0, 5, 3, 4, 5))).toMatch(/^2026-01-05T03:04:05/)
  })
})

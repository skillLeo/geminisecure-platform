/**
 * "Re-test the 'Pass not yet valid' refusal" (M12 §3.1) — the phone's half.
 *
 * The server was right at every hour of the estate's day (PassForTheNextHalfHourTest). The invite form was not: it
 * opened on 2:00 – 4:00 PM whatever the time, with only half-hour slots, so a resident at 10:12 who wanted a guest in
 * now picked 10:30 and the gate said "not yet valid" for eighteen minutes. Now the first chip on today is NOW, and
 * the form opens on it.
 */

import { defaultWindow, fromChips, onDay, siteMinute, toRequest, untilChips, windowLabel, type InviteInput } from '../passes'

// 10:12 on Saturday 26 September at the estate (Kingston, UTC−5).
const at = new Date('2026-09-26T15:12:40Z')
const invite = (over: Partial<InviteInput>): InviteInput => ({ category: 'single', name: 'Kemar Brown', phone: '876 555 0123', purpose: 'Family visit', arrival: 'walk', plate: '', fromDay: '2026-09-26', toDay: '2026-09-26', ...defaultWindow(at), ...over })

it('opens on now, for two hours to the half hour', () => {
  expect(siteMinute(at)).toBe(10 * 60 + 12)
  expect(defaultWindow(at)).toEqual({ fromMinutes: 10 * 60 + 12, toMinutes: 12 * 60 + 30 })
  expect(windowLabel(invite({}), at)).toBe('10:12 AM – 12:30 PM')
})

it('sends a start the gate admits at once: this minute on the estate clock, not the next slot', () => {
  const sent = toRequest(invite({}))
  expect(sent.valid_from).toBe('2026-09-26T10:12:00-05:00')
  expect(Date.parse(sent.valid_from)).toBeLessThanOrEqual(at.getTime())
  expect(sent.valid_to).toBe('2026-09-26T12:30:00-05:00')
})

it('offers this minute first on today, by its time and never as "Now", then only the slots still to come: at 2 AM too', () => {
  const today = fromChips('2026-09-26', at)
  expect(today[0]).toEqual({ value: 612, label: '10:12 AM' })
  expect(today.slice(1, 3).map((c) => c.label)).toEqual(['10:30 AM', '11:00 AM'])
  expect(today.some((c) => c.value < 612)).toBe(false)

  const lateNight = new Date('2026-09-26T07:05:00Z') // 2:05 AM at the estate
  expect(fromChips('2026-09-26', lateNight).slice(0, 2).map((c) => c.label)).toEqual(['2:05 AM', '2:30 AM'])
})

it('offers the day\'s slots, and no Now, on another day', () => {
  const tomorrow = fromChips('2026-09-27', at)
  expect(tomorrow[0]).toEqual({ value: 6 * 60, label: '6:00 AM' })
  expect(tomorrow.some((c) => c.label === 'Now')).toBe(false)
})

it('gives a late start an end the same day', () => {
  const late = new Date('2026-09-27T04:40:00Z') // 11:40 PM at the estate
  expect(defaultWindow(late)).toEqual({ fromMinutes: 23 * 60 + 40, toMinutes: 23 * 60 + 59 })
  expect(untilChips(23 * 60 + 40, false).map((c) => c.label)).toEqual(['11:59 PM'])
})

it('keeps the start sensible when the day changes', () => {
  // "Now" (10:12) moved to tomorrow becomes the next slot; the end follows only if it has to.
  expect(onDay(invite({ fromDay: '2026-09-27', toDay: '2026-09-27' }), at)).toMatchObject({ fromMinutes: 10 * 60 + 30, toMinutes: 12 * 60 + 30 })
  // A start already gone on today becomes now.
  expect(onDay(invite({ fromMinutes: 8 * 60, toMinutes: 11 * 60 }), at)).toMatchObject({ fromMinutes: 10 * 60 + 12, toMinutes: 11 * 60 })
  expect(onDay(invite({ fromMinutes: 8 * 60, toMinutes: 9 * 60 }), at)).toMatchObject({ fromMinutes: 10 * 60 + 12, toMinutes: 12 * 60 + 30 })
})

it('names a start that is not now by its time, in the board’s own form ("2:00–4:00 PM")', () => {
  expect(windowLabel(invite({ fromMinutes: 14 * 60, toMinutes: 16 * 60 }), at)).toBe('2:00 PM – 4:00 PM')
  expect(windowLabel(invite({ fromDay: '2026-09-27', fromMinutes: 9 * 60, toMinutes: 11 * 60 }), at)).toBe('9:00 AM – 11:00 AM')
  expect(windowLabel(invite({ fromDay: '2026-09-27', fromMinutes: 11 * 60, toMinutes: 13 * 60 }), at)).toBe('11:00 AM – 1:00 PM')
})

/**
 * Visitor passes — windows are the ESTATE's, a pass lasts 31 days at most, the e-pass is not a
 * visitor, and what a resident is told is only what the server tells a resident.
 */
import { forgetSiteZone, setSiteZone } from '@gs/core/lib'
import { inviteFixture } from '@/fidelity/visitors'
import { ARRIVALS, CATEGORIES, arrivalWords, atSite, clockLabel, dayLabel, epassNow, footerLine, groupPasses, siteDay, passInvitation, passOutcome, shareText, toRequest, unitAndPhase, validateInvite, windowLabel, windowText, type InviteInput, type Pass } from '../passes'
import { VISIT_PURPOSES } from '../visitPurposes'

const at = new Date('2026-09-04T09:41:00-05:00')
const pass = (over: Partial<Pass>): Pass => ({
  id: 1, pass_id: 'u', category: 'single', visitor_name: 'Marcia James', visitor_phone: null, purpose: 'Family visit', vehicle_plate: null, no_vehicle: false,
  valid_from: '2026-09-04T14:00:00-05:00', valid_to: '2026-09-04T16:00:00-05:00', single_use: true, status: 'active', code: 'PPV2-4471', token: 'a.b', share_count: 0, used_at: null, cancelled_at: null, last_admitted_at: null,
  last_admitted_post: null, last_admitted_by: null, host: 'Andrea Fletcher', ...over,
})
// A purpose by default: required since M12 §2.13 (its own case is below). Walking in by default: no plate needed.
const invite = (over: Partial<InviteInput>): InviteInput => ({ category: 'single', name: 'Marcia James', phone: '876 555 0123', purpose: 'Family visit', arrival: 'walk', plate: '', fromDay: '2026-09-04', toDay: '2026-09-04', fromMinutes: 14 * 60, toMinutes: 16 * 60, ...over })

describe('the window is the SITE’s, whatever zone the phone is set to', () => {
  afterEach(() => forgetSiteZone())

  it('builds the instant the resident meant, with the site’s own offset', () => {
    expect(atSite('2026-09-04', 14 * 60)).toBe('2026-09-04T14:00:00-05:00')
    expect(atSite('2026-09-04', 9 * 60 + 5)).toBe('2026-09-04T09:05:00-05:00')
    expect(clockLabel(14 * 60)).toBe('2:00 PM')
  })

  /**
   * THE ONE CLOCK — 2 PM means 2 PM AT THE ESTATE. The suite is also run with TZ=Asia/Tokyo, so a window built
   * from the handset's own zone would send a pass eleven hours out and the guest would be turned away.
   */
  it('sends 2 PM at the site as the right instant, and would send a different one for a site that is not Kingston', () => {
    expect(new Date(toRequest(invite({})).valid_from).toISOString()).toBe('2026-09-04T19:00:00.000Z')

    setSiteZone('America/New_York') // September: daylight saving, −04:00
    const sent = toRequest(invite({}))
    expect(sent.valid_from).toBe('2026-09-04T14:00:00-04:00')
    expect(new Date(sent.valid_from).toISOString()).toBe('2026-09-04T18:00:00.000Z')
    expect(clockLabel(14 * 60)).toBe('2:00 PM')
  })

  it('"today" is Kingston’s today even when UTC has already moved on', () => {
    const lateEvening = new Date('2026-09-05T03:30:00Z') // 10:30 PM on the 4th in Kingston
    // Named by the date, never "Today" or "Tomorrow" (M47 §7.2).
    expect(siteDay(lateEvening)).toEqual({ iso: '2026-09-04', label: '4 Sep 2026' })
    expect(siteDay(lateEvening, 1)).toEqual({ iso: '2026-09-05', label: '5 Sep 2026' })
    expect(siteDay(lateEvening, 2).label).toBe('6 Sep 2026')
  })

  it('reads a window in the one form, a date, a comma, the times, no tag (M98)', () => {
    expect(windowText('2026-09-04T14:00:00-05:00', '2026-09-04T16:00:00-05:00', at)).toBe('4 Sep 2026, 2:00 – 4:00 PM')
    expect(windowText('2026-09-05T11:00:00-05:00', '2026-09-05T13:00:00-05:00', at)).toBe('5 Sep 2026, 11:00 AM – 1:00 PM')
    expect(windowText('2026-09-04T08:00:00-05:00', '2026-09-30T18:00:00-05:00', at)).toBe('4 Sep 2026 – 30 Sep 2026')
  })
})

/*
 * M19A §6 and §10.2 — A PASS MADE FROM PAKISTAN FOR A JAMAICAN ESTATE. The picker is the estate's. (The choice restated
 * under it in both clocks was the "dual-timezone summary block" M42 §5 #6 removed, and its tests with it.)
 */
describe('from a phone on another clock', () => {
  const phoneIn = (tz: string) => {
    const real = Intl.DateTimeFormat.prototype.resolvedOptions
    return jest.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(function (this: Intl.DateTimeFormat) {
      return { ...real.call(this), timeZone: tz }
    })
  }
  afterEach(() => jest.restoreAllMocks())

  it('refuses a window already over AT THE ESTATE, and says the estate’s time now', () => {
    phoneIn('Asia/Karachi')
    expect(validateInvite(invite({ fromMinutes: 6 * 60, toMinutes: 8 * 60 }), at).window).toBe('That window has already passed at the estate: it is 9:41 AM there now. Choose a later time.')
  })
})

describe('an invitation is checked before it is sent', () => {
  it('needs a name, a sane phone, and a window that ends after it starts and has not passed', () => {
    expect(validateInvite(invite({}), at)).toEqual({})
    expect(validateInvite(invite({ name: ' ' }), at).name).toBeDefined()
    expect(validateInvite(invite({ phone: 'call me' }), at).phone).toBeDefined()
    expect(validateInvite(invite({ toMinutes: 13 * 60 }), at).window).toMatch(/end after it starts/)
    expect(validateInvite(invite({ fromMinutes: 6 * 60, toMinutes: 8 * 60 }), at).window).toMatch(/already passed/)
  })

  it('needs to say what the visit is for (M12 §2.13): chosen from the list now', () => {
    expect(validateInvite(invite({ purpose: '' }), at).purpose).toBe('Choose what the visit is for.')
    expect(validateInvite(invite({ purpose: ' x ' }), at).purpose).toBeDefined()
    expect(toRequest(invite({ purpose: '  Contractor or tradesperson ' })).purpose).toBe('Contractor or tradesperson')
    for (const purpose of VISIT_PURPOSES) expect(validateInvite(invite({ purpose }), at)).toEqual({})
  })

  it('refuses more than 31 days: the server would', () => {
    expect(validateInvite(invite({ category: 'contractor', toDay: '2026-10-20' }), at).window).toMatch(/31 days/)
    expect(validateInvite(invite({ category: 'contractor', toDay: '2026-10-04' }), at)).toEqual({})
  })

  it('sends only what was filled in, the plate upper-cased and the number in its one written form', () => {
    expect(toRequest(invite({ plate: ' pc 8821 ', phone: '876 555 0148', arrival: 'drive' }))).toEqual({
      category: 'single', visitor_name: 'Marcia James', visitor_phone: '+1 876 555 0148', purpose: 'Family visit', vehicle_plate: 'PC 8821', arrival: 'drive',
      valid_from: '2026-09-04T14:00:00-05:00', valid_to: '2026-09-04T16:00:00-05:00',
    })
  })

  it('the pass keeps how they come, and says it in the screen\'s words, never "On foot or by taxi" (M50 §6.3, §6.10)', () => {
    expect(toRequest(invite({ arrival: 'ride', plate: '' }))).toMatchObject({ no_vehicle: true, arrival: 'ride' })
    expect(arrivalWords({ vehicle_plate: 'PC 8821', no_vehicle: false, arrival: 'drive' })).toBe('Drive in · PC 8821')
    expect(arrivalWords({ vehicle_plate: null, no_vehicle: true, arrival: 'walk' })).toBe('Walk in')
    expect(arrivalWords({ vehicle_plate: null, no_vehicle: true, arrival: 'ride' })).toBe('Ride in')
    // A pass issued before the pass kept which: both, honestly.
    expect(arrivalWords({ vehicle_plate: null, no_vehicle: true, arrival: null })).toBe('Walk in or ride in')
    expect(arrivalWords({ vehicle_plate: null, no_vehicle: false })).toBeNull()
  })

  /*
   * M42 §5 #6, as the user answered it on 7 October: Walk in · Drive in · Taxi. The plate is asked for Drive in only — "a
   * taxi has a plate but the resident cannot know it in advance". Still M18C §3.1: a plate, or "no vehicle", never neither.
   */
  it('asks how they arrive, and never assumes it', () => {
    expect(validateInvite(invite({ arrival: null }), at).arrival).toBe('Choose how they arrive.')
    expect(validateInvite(invite({ arrival: null }), at).plate).toBeUndefined()
  })

  it('needs a plate for Drive in only', () => {
    expect(validateInvite(invite({ arrival: 'drive', plate: '' }), at).plate).toBe('Enter the plate, or choose Walk in or Ride in.')
    expect(validateInvite(invite({ arrival: 'drive', plate: 'PC 8821' }), at)).toEqual({})
    expect(validateInvite(invite({ arrival: 'walk', plate: '' }), at)).toEqual({})
    expect(validateInvite(invite({ arrival: 'ride', plate: '' }), at)).toEqual({})
  })

  it('sends Walk in and Ride in as "no vehicle", with no plate: even one typed before the choice changed', () => {
    for (const arrival of ['walk', 'ride'] as const) {
      const sent = toRequest(invite({ arrival, plate: 'PC 1' }))
      expect(sent).toMatchObject({ no_vehicle: true })
      expect(sent).not.toHaveProperty('vehicle_plate')
    }
    expect(toRequest(invite({ arrival: 'drive', plate: 'PC 1' }))).not.toHaveProperty('no_vehicle')
  })
})

describe('the screen’s words, as the board writes them (resident-app-07)', () => {
  it('offers exactly the four kinds, the seven purposes and the three ways of arriving', () => {
    expect(CATEGORIES.map((cat) => cat.label)).toEqual(['Single visit', 'Recurring', 'Contractor', 'Delivery'])
    expect(VISIT_PURPOSES).toEqual(['Family visit', 'Friend', 'Delivery', 'Contractor or tradesperson', 'Ride pickup or drop-off', 'Domestic helper', 'Other'])
    expect(ARRIVALS.map((a) => a.label)).toEqual(['Walk in', 'Drive in', 'Ride in'])
  })

  it('reads the board’s window by its real date and times, never "Today", never "Now" (M47 §7.2)', () => {
    const board = invite({ fromMinutes: inviteFixture.fromMinutes, toMinutes: inviteFixture.toMinutes })
    expect(dayLabel(board, at)).toBe('4 Sep 2026')
    expect(windowLabel(board, at)).toBe('2:00 PM – 4:00 PM')
    // A pass that opens as soon as it is signed shows the minute it is now, not the word.
    expect(windowLabel(invite({ fromMinutes: 9 * 60, toMinutes: 11 * 60 }), at)).toBe('9:41 AM – 11:00 AM')
  })

  it('writes a window with both meridiems and a spaced dash, and the day as its date in the one form (M98)', () => {
    expect(windowLabel(invite({ fromMinutes: 11 * 60 + 30, toMinutes: 13 * 60 + 30 }), at)).toBe('11:30 AM – 1:30 PM')
    expect(dayLabel(invite({ fromDay: '2026-09-05', toDay: '2026-09-05' }), at)).toBe('5 Sep 2026')
    expect(dayLabel(invite({ fromDay: '2026-09-08', toDay: '2026-09-08' }), at)).toBe('8 Sep 2026')
  })

  it('reads a pass that spans days by its two dates, compactly, day before month', () => {
    expect(dayLabel(invite({ category: 'recurring', toDay: '2026-09-11' }), at)).toBe('4–11 Sep 2026')
    expect(dayLabel(invite({ category: 'contractor', fromDay: '2026-09-28', toDay: '2026-10-05' }), at)).toBe('28 Sep – 5 Oct 2026')
    expect(dayLabel(invite({ category: 'contractor', fromDay: '2026-12-28', toDay: '2027-01-03' }), at)).toBe('28 Dec 2026 – 3 Jan 2027')
  })

  it('names the visitor typed in the footer, and never guesses a pronoun', () => {
    // M47 §7.5: nothing sends it by itself, so the footer never says it does.
    expect(footerLine('  Kemar   Brown ')).toBe('Kemar gets this pass when you send it on the next screen: they just show it at the gate, no phone call needed.')
    expect(footerLine('')).toBe('Your visitor gets this pass when you send it on the next screen: they just show it at the gate, no phone call needed.')
    expect(footerLine('Kemar')).not.toMatch(/SMS|WhatsApp|automatically/)
    expect(footerLine('Marcia James')).not.toMatch(/\bshe\b/)
  })
})

describe('grouping', () => {
  it('now · upcoming · history, and the resident’s own e-pass is not a visitor', () => {
    const g = groupPasses([
      pass({ id: 1 }), // this afternoon → upcoming
      pass({ id: 2, valid_from: '2026-09-04T08:00:00-05:00', valid_to: '2026-09-04T18:00:00-05:00' }), // now
      pass({ id: 3, status: 'used', used_at: '2026-09-03T10:02:00-05:00' }),
      pass({ id: 4, status: 'cancelled', cancelled_at: '2026-09-02T10:00:00-05:00' }),
      pass({ id: 5, valid_from: '2026-09-01T08:00:00-05:00', valid_to: '2026-09-01T10:00:00-05:00' }), // active by status, but over
      pass({ id: 6, category: 'resident' }),
    ], at)
    expect(g.now.map((p) => p.id)).toEqual([2])
    expect(g.upcoming.map((p) => p.id)).toEqual([1])
    expect(g.history.map((p) => p.id)).toEqual([3, 4, 5])
  })

  it('says what happened, and never claims to know when a visitor LEFT', () => {
    // A recent moment in words that belong to no clock (M19A §4.1).
    expect(passOutcome(pass({ status: 'used', used_at: '2026-09-04T09:02:00-05:00' }), at)).toEqual({ word: 'Arrived', tone: 'green', detail: 'Admitted at the gate · 39 minutes ago' })
    expect(passOutcome(pass({ status: 'cancelled', cancelled_at: '2026-09-02T10:00:00-05:00' }), at).word).toBe('Cancelled')
    expect(passOutcome(pass({ valid_from: '2026-09-01T08:00:00-05:00', valid_to: '2026-09-01T10:00:00-05:00' }), at).word).toBe('Expired')
    expect(passOutcome(pass({}), at)).toMatchObject({ word: 'Upcoming', detail: '4 Sep 2026, 2:00 – 4:00 PM' })
    const all = [pass({ status: 'used', used_at: '2026-09-04T09:02:00-05:00' }), pass({})].map((p) => JSON.stringify(passOutcome(p, at))).join()
    expect(all).not.toMatch(/left|exited|on site/i)
  })
})

/*
 * M47 §6 — THE E-PASS, HALF AN HOUR AT A TIME (the user, 7 October: "48 slots, a day"). The phone holds a day of half
 * hours and shows the one this moment is in; a screenshot dies with its half hour; a spent day says so.
 */
describe('the e-pass on screen is the half hour this moment is in', () => {
  // A day of half hours from 9:30 AM at the estate, each its own token.
  const START = Date.parse('2026-09-04T09:30:00-05:00')
  const HALF = 30 * 60_000
  const iso = (t: number) => new Date(t).toISOString()
  const slots = Array.from({ length: 48 }, (_, i) => ({ valid_from: iso(START + i * HALF), valid_to: iso(START + (i + 1) * HALF), token: `slot-${i}` }))
  const epass = { ...pass({ category: 'resident', visitor_name: 'Andrea Fletcher', single_use: false, valid_from: iso(START), valid_to: iso(START + HALF), token: 'slot-0' }), slots, refresh_after: iso(START + HALF) }

  it('at 9:41 AM, the 9:30 half hour: its window and its own token', () => {
    const now = epassNow(epass, at)
    expect(now.ranOut).toBe(false)
    expect(now.pass).toMatchObject({ token: 'slot-0', valid_from: iso(START), valid_to: iso(START + HALF) })
    expect(windowText(now.pass!.valid_from, now.pass!.valid_to, at)).toBe('4 Sep 2026, 9:30 – 10:00 AM')
  })

  it('the next half hour as the clock moves: with no connection, from what the phone holds', () => {
    expect(epassNow(epass, new Date(START + HALF))?.pass?.token).toBe('slot-1')
    expect(epassNow(epass, new Date(START + 10 * HALF + 60_000))?.pass?.token).toBe('slot-10')
    expect(epassNow(epass, new Date(START + 48 * HALF - 1))?.pass?.token).toBe('slot-47')
  })

  it('a day later the half hours are spent: nothing is shown, and the screen is told it ran out', () => {
    expect(epassNow(epass, new Date(START + 48 * HALF))).toEqual({ pass: null, ranOut: true })
  })

  it('a phone whose clock is behind the server’s shows the first half hour: the gate’s clock decides', () => {
    expect(epassNow(epass, new Date(START - 60_000))?.pass?.token).toBe('slot-0')
  })

  it('nothing on the phone yet is not "ran out"', () => {
    expect(epassNow(null, at)).toEqual({ pass: null, ranOut: false })
  })

  it('an older server sends one window and no slots: that window, while it lasts', () => {
    const { slots: _, ...older } = epass
    const day = { ...older, valid_to: iso(START + 48 * HALF) }
    expect(epassNow(day, at).pass?.token).toBe('slot-0')
    expect(epassNow(day, new Date(START + 48 * HALF))).toEqual({ pass: null, ranOut: true })
  })
})

/*
 * M18F §4 — THE WORDS THAT GO WITH A PASS, the server's words for when it cannot be asked (tests/Unit/PassInvitationTest.php
 * holds its cases): the client's example, each kind greeted as what it is, and a text message inside two segments. Since
 * M98 (Hassam, 12 Oct 2026) the date is in the one form and no clock is named; the server's words are to follow.
 */
describe('the invitation', () => {
  const place = { estate: 'Phoenix Park Village 1', ...unitAndPhase('Phase 2 · Lot 47'), host: 'Hassam Ahmed' }
  const theirs = (over: Partial<Pass> = {}) => pass({ visitor_name: 'Mmm', code: 'PPV2-2195', valid_from: '2026-09-27T13:58:00Z', valid_to: '2026-09-27T16:00:00Z', ...over })

  it('reads the lot and its phase off the session\'s reference', () => {
    expect(unitAndPhase('Phase 2 · Lot 47')).toEqual({ unit: 'Lot 47', phase: 'Phase 2' })
    expect(unitAndPhase('Lot 47')).toEqual({ unit: 'Lot 47', phase: null })
    expect(unitAndPhase(null)).toEqual({ unit: null, phase: null })
  })

  it('writes the client\'s single-visit caption, its date in the one form and no clock named (M98)', () => {
    expect(passInvitation(theirs(), place).message).toBe(
      "Hi Mmm, you're expected at Phoenix Park Village 1, Lot 47 (Phase 2).\n\n27 Sep 2026, 8:58 – 11:00 AM.\n\nShow this pass at the gate, or give the guard the code *PPV2-2195*.\n\nSent by Hassam Ahmed",
    )
    // Offline, the caption is those same words.
    expect(shareText(theirs(), place)).toBe(passInvitation(theirs(), place).message)
  })

  it('writes the words-only version in plain GSM characters, inside two segments', () => {
    const sms = passInvitation(theirs(), place).sms
    expect(sms).toBe("Hi Mmm - you're expected at Phoenix Park Village 1, Lot 47 on 27 Sep 2026, 8:58-11:00 AM. Give the guard the code PPV2-2195 at the gate. Sent by Hassam Ahmed.")
    expect(/[^\x20-\x7E\n]/.test(sms)).toBe(false)
    expect(sms.length).toBeLessThanOrEqual(306)
  })

  it('greets each kind as what it is, and a recurring pass with both its days', () => {
    expect(passInvitation(theirs({ category: 'contractor' }), place).message).toMatch(/^Hello Mmm, you're booked to work at Phoenix Park Village 1, Lot 47 \(Phase 2\)\.[\s\S]*Please have your ID ready\./)
    expect(passInvitation(theirs({ category: 'delivery' }), place).message).toMatch(/^Hello Mmm, a delivery to Phoenix Park Village 1, Lot 47 \(Phase 2\) is expected\./)
    expect(passInvitation(theirs({ category: 'recurring', valid_from: '2026-09-27T11:00:00Z', valid_to: '2026-10-03T23:00:00Z' }), place).message).toBe(
      "Hi Mmm, here is your pass for Phoenix Park Village 1, Lot 47 (Phase 2).\n\nValid from 27 Sep 2026, 6:00 AM to 3 Oct 2026, 6:00 PM.\n\nShow this pass at the gate each time you come, or give the guard the code *PPV2-2195*.\n\nSent by Hassam Ahmed",
    )
    expect(passInvitation(theirs({ category: 'recurring', valid_from: '2026-09-27T11:00:00Z', valid_to: '2026-10-03T23:00:00Z' }), place).sms).toBe(
      "Hi Mmm - your pass for Phoenix Park Village 1, Lot 47, valid from 27 Sep 2026, 6:00 AM to 3 Oct 2026, 6:00 PM. Give the guard the code PPV2-2195 at the gate. Sent by Hassam Ahmed.",
    )
  })

  it('never says anything an app would, and names no clock (Hassam, M98: everything on a pass is the estate\'s time)', () => {
    for (const category of ['single', 'recurring', 'contractor', 'delivery'] as const) {
      const words = passInvitation(theirs({ category }), place)
      for (const text of [words.message, words.sms]) {
        expect(text).not.toMatch(/e-?pass|\bQR\b|signed/i)
        expect(/Jamaica|[A-Z]\w* time\b|\(.*time.*\)|\bJA\b/.test(text)).toBe(false)
        expect(text).toContain('27 Sep 2026')
      }
      expect(words.sms.length).toBeLessThanOrEqual(306)
    }
  })
})

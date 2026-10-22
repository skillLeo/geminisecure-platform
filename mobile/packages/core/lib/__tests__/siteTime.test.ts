/**
 * THE ONE CLOCK.
 *
 * Every assertion here is written so it can only pass if the zone is read from the IANA name the server
 * sent. Nothing is allowed to be right by accident:
 *
 *   - the same instant reads 7:00 AM in Kingston and 8:00 AM in New York on a September day, which a
 *     hard-coded −5 cannot produce;
 *   - the same New York wall time is built with −05:00 in January and −04:00 in July;
 *   - the whole file passes with the machine's own TZ set to Asia/Tokyo (`npm test` is run that way too),
 *     so nothing here can be reading the phone's clock.
 */

import {
  DEFAULT_SITE_ZONE,
  LEGACY_TIME_VIEW_KEY,
  adoptSiteZone,
  atSiteZone,
  clockNameOf,
  clockTagOf,
  dateLong,
  dateShort,
  dayTimeTag,
  displayZone,
  duration,
  estateClockExplained,
  estateClockLine,
  estateNowLine,
  forgetSiteZone,
  hydrateSiteZone,
  isSameSiteDay,
  isZoneName,
  rangeTag,
  readingAnotherClock,
  setSiteZone,
  shiftName,
  sinceWords,
  siteDayIso,
  siteNoon,
  siteOffsetMinutes,
  siteParts,
  siteZone,
  time12,
  time12s,
  timeCompact,
  timeTag,
  timeWords,
  watchSiteZone,
  withClockTag,
  yourTimeLine,
} from '../siteTime'
import { getStorage } from '../storage'

const KINGSTON = 'America/Jamaica'
const NEW_YORK = 'America/New_York'
const TOKYO = 'Asia/Tokyo'
const KARACHI = 'Asia/Karachi'

/** The instant in the work order: 12:00 UTC on Monday 21 September 2026. */
const NOON_UTC = '2026-09-21T12:00:00Z'

afterEach(() => forgetSiteZone())

describe('the same instant, read at two sites', () => {
  it('reads 7:00 AM in Kingston and 8:00 AM in New York: a fixed offset could not do both', () => {
    expect(time12(NOON_UTC, KINGSTON)).toBe('7:00 AM')
    expect(time12(NOON_UTC, NEW_YORK)).toBe('8:00 AM')
    expect(time12(NOON_UTC, TOKYO)).toBe('9:00 PM')
  })

  it('carries the site through every shape of label', () => {
    expect(timeCompact(NOON_UTC, KINGSTON)).toBe('7:00')
    expect(time12s('2026-09-21T14:41:02Z', KINGSTON)).toBe('9:41:02 AM')
    // The one way a date is written (M98, 12 Oct 2026): day, month, year.
    expect(dateShort(NOON_UTC, KINGSTON)).toBe('21 Sep 2026')
    expect(dateLong(NOON_UTC, KINGSTON)).toBe('21 Sep 2026')
  })

  it('answers the offset by the zone database, never by arithmetic', () => {
    expect(siteOffsetMinutes(NOON_UTC, KINGSTON)).toBe(-300)
    // The same zone, six months apart: −240 in daylight saving, −300 outside it.
    expect(siteOffsetMinutes('2026-07-21T12:00:00Z', NEW_YORK)).toBe(-240)
    expect(siteOffsetMinutes('2026-01-21T12:00:00Z', NEW_YORK)).toBe(-300)
  })
})

describe('the day boundary', () => {
  it('03:30Z is still the evening BEFORE in Kingston', () => {
    const lateEvening = '2026-09-22T03:30:00Z'
    expect(siteDayIso(new Date(lateEvening), 0, KINGSTON)).toBe('2026-09-21')
    expect(time12(lateEvening, KINGSTON)).toBe('10:30 PM')
    expect(dateShort(lateEvening, KINGSTON)).toBe('21 Sep 2026')
    // The same instant in Tokyo is already lunchtime the NEXT day — the zone, not the instant, moves the day.
    expect(siteDayIso(new Date(lateEvening), 0, TOKYO)).toBe('2026-09-22')
  })

  it('knows when two instants fall on the same day at the site', () => {
    expect(isSameSiteDay('2026-09-22T03:30:00Z', '2026-09-21T13:00:00Z', KINGSTON)).toBe(true)
    expect(isSameSiteDay('2026-09-22T03:30:00Z', '2026-09-21T13:00:00Z', TOKYO)).toBe(false)
  })

  it('names a calendar day from noon at the site, so no zone can move it', () => {
    expect(dateShort(siteNoon('2026-09-21', KINGSTON), KINGSTON)).toBe('21 Sep 2026')
    expect(dateShort(siteNoon('2026-01-01', NEW_YORK), NEW_YORK)).toBe('1 Jan 2026')
  })
})

describe('a shift', () => {
  // The work order's own case: the roster holds 07:00–19:00 Kingston, the database holds 12:00–00:00 UTC.
  const start = '2026-09-21T12:00:00Z'
  const end = '2026-09-22T00:00:00Z'

  it('renders "7:00 AM – 7:00 PM" at the site', () => {
    expect(`${time12(start, KINGSTON)} – ${time12(end, KINGSTON)}`).toBe('7:00 AM – 7:00 PM')
  })

  it('is a Day Shift by the site clock, and a Night Shift by Tokyo’s', () => {
    expect(shiftName(start, KINGSTON)).toBe('Day Shift')
    expect(shiftName(start, TOKYO)).toBe('Night Shift')
  })

  it('measures its length without a zone at all', () => {
    expect(duration(new Date(end).getTime() - new Date(start).getTime())).toBe('12h 0m')
  })
})

describe('what the phone SENDS', () => {
  it('builds a 2 PM window in the SITE’s zone, whatever the handset’s zone is', () => {
    const sent = atSiteZone('2026-09-21', 14 * 60, KINGSTON)
    expect(sent).toBe('2026-09-21T14:00:00-05:00')
    expect(new Date(sent).toISOString()).toBe('2026-09-21T19:00:00.000Z')
  })

  it('uses the zone’s own offset for that day: daylight saving included', () => {
    expect(atSiteZone('2026-07-21', 14 * 60, NEW_YORK)).toBe('2026-07-21T14:00:00-04:00')
    expect(atSiteZone('2026-01-21', 14 * 60, NEW_YORK)).toBe('2026-01-21T14:00:00-05:00')
    expect(new Date(atSiteZone('2026-07-21', 14 * 60, NEW_YORK)).toISOString()).toBe('2026-07-21T18:00:00.000Z')
  })

  /*
   * M11 §4.4, on the phone: the same 18:00 visitor window the web's TwoEstatesAcrossDaylightSavingTest proves, on
   * the days either side of New York's changes AND on the change days themselves — where the offset at a naive
   * guess is not the offset at the instant being built. Kingston, which keeps no daylight saving, beside it.
   */
  it.each([
    ['2026-10-30', '-04:00', '22:00'], // the Friday before clocks go back (EDT)
    ['2026-11-01', '-05:00', '23:00'], // the change itself, 1 November: 18:00 is after 02:00, so EST
    ['2026-11-02', '-05:00', '23:00'], // the Monday after (EST)
    ['2026-03-07', '-05:00', '23:00'], // the Saturday before clocks go forward (EST)
    ['2026-03-08', '-04:00', '22:00'], // 8 March, the change: 18:00 is EDT
  ])('an 18:00 window in New York on %s is %s, %s UTC, and reads back as 6:00 PM', (day, offset, utc) => {
    const sent = atSiteZone(day, 18 * 60, NEW_YORK)
    expect(sent).toBe(`${day}T18:00:00${offset}`)
    expect(new Date(sent).toISOString().slice(11, 16)).toBe(utc)
    expect(time12(sent, NEW_YORK)).toBe('6:00 PM')
    // Kingston, the same evening: never moves.
    expect(atSiteZone(day, 18 * 60, KINGSTON)).toBe(`${day}T18:00:00-05:00`)
  })

  it('an hour the change skips (02:30 on 8 March in New York) is taken as the next real one, not an instant that never was', () => {
    const sent = atSiteZone('2026-03-08', 2 * 60 + 30, NEW_YORK)
    // 03:30 EDT — the server's reading of the same typed time (PHP moves a skipped time forward).
    expect(new Date(sent).getTime()).toBe(new Date('2026-03-08T07:30:00.000Z').getTime())
    expect(time12(sent, NEW_YORK)).toBe('3:30 AM')
  })

  it('an hour that happens twice (01:30 on 1 November in New York) is the first of the two, as the server takes it', () => {
    const sent = atSiteZone('2026-11-01', 60 + 30, NEW_YORK)
    expect(new Date(sent).getTime()).toBe(new Date('2026-11-01T05:30:00.000Z').getTime()) // 01:30 EDT
  })

  it('rolls midnight at the end of a day into the next one', () => {
    expect(atSiteZone('2026-09-30', 24 * 60, KINGSTON)).toBe('2026-10-01T00:00:00-05:00')
  })

  it('reads back as the same wall-clock time it was picked at', () => {
    for (const zone of [KINGSTON, NEW_YORK, TOKYO]) {
      for (const day of ['2026-01-21', '2026-07-21']) {
        expect(time12(atSiteZone(day, 14 * 60, zone), zone)).toBe('2:00 PM')
      }
    }
  })

  it('answers an empty string for something that is not a calendar date', () => {
    expect(atSiteZone('not-a-day', 60, KINGSTON)).toBe('')
  })
})

describe('the zone comes from the session, never from the phone', () => {
  it('starts at the one default and formats with it', () => {
    // The tests' build setting is Jamaica (packages/core/jest.setup.js): the fixture estates are Jamaican.
    expect(siteZone()).toBe(DEFAULT_SITE_ZONE)
    expect(time12(NOON_UTC)).toBe('7:00 AM')
  })

  it('names no zone in the code: the default is the build\'s setting, and UTC when there is none (M19A §9)', () => {
    const was = process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE
    const load = (): string => {
      let found = ''
      jest.isolateModules(() => {
        found = (require('../siteTime') as typeof import('../siteTime')).DEFAULT_SITE_ZONE
      })
      return found
    }
    try {
      delete process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE
      expect(load()).toBe('UTC')
      process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE = 'Not/AZone'
      expect(load()).toBe('UTC')
      process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE = NEW_YORK
      expect(load()).toBe(NEW_YORK)
    } finally {
      process.env.EXPO_PUBLIC_PLATFORM_TIMEZONE = was
    }
  })

  it('takes the zone off whichever payload carries it', () => {
    // devices.enrol · guards.me
    expect(adoptSiteZone({ site: { timezone: NEW_YORK } })).toBe(true)
    expect(siteZone()).toBe(NEW_YORK)
    expect(time12(NOON_UTC)).toBe('8:00 AM')

    // shifts.me · sync.pull
    forgetSiteZone()
    expect(adoptSiteZone({ timezone: TOKYO })).toBe(true)
    expect(siteZone()).toBe(TOKYO)

    // shifts.me.current
    forgetSiteZone()
    expect(adoptSiteZone({ shift: { site: { timezone: NEW_YORK } } })).toBe(true)
    expect(siteZone()).toBe(NEW_YORK)

    // auth.otp.verify · me.show · me.update
    forgetSiteZone()
    expect(adoptSiteZone({ estate: { timezone: NEW_YORK } })).toBe(true)
    expect(siteZone()).toBe(NEW_YORK)
  })

  it('ignores an answer that carries no zone, and anything that is not one', () => {
    for (const body of [null, undefined, 'a string', {}, { timezone: null }, { timezone: 'UTC-5' }, { timezone: '-05:00' }, { timezone: 'Not/AZone' }]) {
      expect(adoptSiteZone(body)).toBe(false)
    }
    expect(siteZone()).toBe(DEFAULT_SITE_ZONE)
  })

  it('takes the first READABLE zone in the answer, not merely the first field that is present', () => {
    // '' is not nullish, so a `??` chain stopped here and left the phone on its default.
    expect(adoptSiteZone({ timezone: '', site: { timezone: NEW_YORK } })).toBe(true)
    expect(siteZone()).toBe(NEW_YORK)
    forgetSiteZone()
    expect(adoptSiteZone({ timezone: 'UTC-5', estate: { timezone: TOKYO } })).toBe(true)
    expect(siteZone()).toBe(TOKYO)
  })

  it('reads a zone name it cannot render as the session’s own, never as UTC', () => {
    // A name from a newer database, or a typo: Kingston's evening must not be drawn as the next morning.
    expect(time12(NOON_UTC, 'Not/AZone')).toBe(time12(NOON_UTC, DEFAULT_SITE_ZONE))
    expect(isZoneName('Not/AZone')).toBe(false)
    expect(isZoneName(KINGSTON)).toBe(true)
    expect(isZoneName(42)).toBe(false)
  })

  it('tells a watcher when the server moves it, and forgets it at sign-out', () => {
    const seen: string[] = []
    const stop = watchSiteZone((z) => seen.push(z))
    setSiteZone(NEW_YORK)
    forgetSiteZone()
    stop()
    setSiteZone(TOKYO)
    expect(seen).toEqual([NEW_YORK, DEFAULT_SITE_ZONE])
  })

  it('says which zone a reading was taken in', () => {
    expect(siteParts(NOON_UTC, NEW_YORK)).toMatchObject({ year: 2026, month: 8, day: 21, weekday: 1, hour: 8, minute: 0, zone: NEW_YORK })
  })

  it('answers NaN parts for something that is not an instant, rather than a wrong time', () => {
    expect(Number.isNaN(siteParts('rubbish', KINGSTON).hour)).toBe(true)
    expect(siteDayIso('rubbish', 0, KINGSTON)).toBe('')
  })
})

/**
 * A TIME THAT IS NOT THERE IS NOT A TIME. The API sends `null` for a moment that has not happened
 * (`actual_end`, `used_at`, `cancelled_at`), and `atSiteZone`/`siteNoon` answer '' for a day they cannot
 * read. `new Date(null)` is the EPOCH, not an invalid date, so an absent field used to be drawn as a real
 * time — "Dec 31, 7:00 PM" at the site, 1969 — and `dateShort` of an unreadable one THREW, taking the
 * screen with it. Both are wrong in the same way: the reader is told something nobody recorded.
 */
describe('a time that is not there', () => {
  const nothings = [null, undefined, '', '   ', 'rubbish', NaN]

  it('renders as nothing, never as the epoch and never as a crash', () => {
    for (const nothing of nothings) {
      const absent = nothing as unknown as string
      expect(time12(absent, KINGSTON)).toBe('')
      expect(timeCompact(absent, KINGSTON)).toBe('')
      expect(time12s(absent, KINGSTON)).toBe('')
      expect(dateShort(absent, KINGSTON)).toBe('')
      expect(dateLong(absent, KINGSTON)).toBe('')
    }
  })

  it('does not throw when a calendar day could not be built', () => {
    expect(siteNoon('', KINGSTON)).toBe('')
    expect(() => dateShort(siteNoon('', KINGSTON), KINGSTON)).not.toThrow()
    expect(dateShort(siteNoon('not-a-day', KINGSTON), KINGSTON)).toBe('')
  })

  it('still reads the epoch itself when it is genuinely the instant asked for', () => {
    // 0 is a number, and a number is an instant: only null/undefined/blank mean "no time".
    expect(dateShort(0, KINGSTON)).toBe('31 Dec 1969')
    expect(dateShort(new Date(0), KINGSTON)).toBe('31 Dec 1969')
  })
})

/*
 * M19A §3 — ONE CLOCK. Everything to do with the estate is shown in the estate's time, always, whatever zone the phone
 * is set to; the viewer's own clock only ever appears BESIDE it, secondary. The phone's zone is faked through Intl, so
 * this holds whatever the machine running it is set to.
 */
const phoneIn = (tz: string) => {
  const real = Intl.DateTimeFormat.prototype.resolvedOptions
  return jest.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(function (this: Intl.DateTimeFormat) {
    return { ...real.call(this), timeZone: tz }
  })
}

describe('one clock, the estate\'s', () => {
  afterEach(() => jest.restoreAllMocks())

  it('reads the estate\'s clock on a phone set to Pakistan: 7:38 PM there is 9:38 AM at the gate', () => {
    setSiteZone(KINGSTON)
    phoneIn(KARACHI)
    // The client's own case, 27 Sep: 14:38 UTC.
    expect(time12('2026-09-27T14:38:00Z')).toBe('9:38 AM')
    // No tag and no zone's name on the number (M98): the estate's clock is the only one shown.
    expect(timeTag('2026-09-27T14:38:00Z')).toBe('9:38 AM')
    expect(timeWords('2026-09-27T14:38:00Z')).toBe('9:38 AM')
    expect(displayZone()).toBe(KINGSTON)
  })

  it('never moves what the phone sends', () => {
    setSiteZone(KINGSTON)
    phoneIn(NEW_YORK)
    expect(atSiteZone('2026-09-21', 14 * 60)).toBe('2026-09-21T14:00:00-05:00')
    expect(siteDayIso('2026-09-22T03:30:00Z')).toBe('2026-09-21')
  })

  it('forgets a choice made before M19A, at start-up and at sign-out', () => {
    getStorage().set(LEGACY_TIME_VIEW_KEY, 'phone')
    hydrateSiteZone()
    expect(getStorage().getString(LEGACY_TIME_VIEW_KEY) ?? null).toBeNull()
    getStorage().set(LEGACY_TIME_VIEW_KEY, 'phone')
    forgetSiteZone()
    expect(getStorage().getString(LEGACY_TIME_VIEW_KEY) ?? null).toBeNull()
  })
})

describe('a time a person reads is the digits alone (M98, 12 Oct 2026: "No JA after a time, ever")', () => {
  it('still names a zone by its own place, for the lines that explain the clock: no zone is written into the product', () => {
    expect(clockTagOf('America/Jamaica')).toBe('JA')
    expect(clockTagOf('America/New_York')).toBe('NY')
    expect(clockTagOf('America/Port_of_Spain')).toBe('POS')
    expect(clockTagOf('America/Port-au-Prince')).toBe('PAP')
    expect(clockTagOf('Asia/Karachi')).toBe('KA')
    expect(clockNameOf('Asia/Karachi')).toBe('Karachi time')
  })

  it('reads an estate in New York on its own clock, untagged', () => {
    setSiteZone(NEW_YORK)
    expect(timeTag(NOON_UTC)).toBe('8:00 AM')
    expect(dayTimeTag(NOON_UTC)).toBe('21 Sep 2026, 8:00 AM')
  })

  it('gives a range no tag', () => {
    setSiteZone(KINGSTON)
    expect(rangeTag('2026-09-27T13:58:00Z', '2026-09-27T16:00:00Z')).toBe('8:58 – 11:00 AM')
    expect(rangeTag('2026-09-28T04:00:00Z', '2026-09-28T06:00:00Z')).toBe('11:00 PM – 1:00 AM')
  })

  it('writes a date one way, day month year (M98: the consoles\' "9 Oct 2026")', () => {
    expect(dateShort('2026-09-27T14:38:00Z', KINGSTON)).toBe('27 Sep 2026')
  })

  it('never puts a tag or a zone\'s name after a time, whatever the estate\'s zone', () => {
    for (const zoneName of [KINGSTON, NEW_YORK]) {
      setSiteZone(zoneName)
      const said = [timeTag(NOON_UTC), timeWords(NOON_UTC), dayTimeTag(NOON_UTC), rangeTag(NOON_UTC, '2026-09-21T14:00:00Z'), sinceWords('2026-09-20T12:00:00Z', NOON_UTC), withClockTag('9:38 AM')]
      expect(said.filter((text) => /\b(JA|NY)\b|Jamaica time|New York time/.test(text))).toEqual([])
    }
  })

  it('draws nothing for a time that is not one', () => {
    expect(timeTag(null as never)).toBe('')
    expect(dayTimeTag('')).toBe('')
    expect(sinceWords(null as never, NOON_UTC)).toBe('')
  })
})

describe('a recent event, in words that belong to no clock (M19A §4.1)', () => {
  const AT = '2026-09-27T19:00:00Z' // 2:00 PM in Kingston, Sunday

  beforeEach(() => setSiteZone(KINGSTON))

  it('says how long ago, within the estate\'s day', () => {
    expect(sinceWords('2026-09-27T18:59:40Z', AT)).toBe('just now')
    expect(sinceWords('2026-09-27T18:59:00Z', AT)).toBe('a minute ago')
    expect(sinceWords('2026-09-27T18:58:00Z', AT)).toBe('2 minutes ago')
    expect(sinceWords('2026-09-27T17:40:00Z', AT)).toBe('an hour ago')
    expect(sinceWords('2026-09-27T14:00:00Z', AT)).toBe('5 hours ago')
  })

  it('says yesterday, with the time, once the estate\'s midnight has passed', () => {
    expect(sinceWords('2026-09-26T21:15:00Z', AT)).toBe('yesterday at 4:15 PM')
  })

  it('gives the date and the time in the one form for anything older, or still to come', () => {
    expect(sinceWords('2026-09-24T14:38:00Z', AT)).toBe('24 Sep 2026, 9:38 AM')
    expect(sinceWords('2026-09-28T14:38:00Z', AT)).toBe('28 Sep 2026, 9:38 AM')
  })

  it('does not print a future for a phone clock a minute ahead of the server', () => {
    expect(sinceWords('2026-09-27T19:01:00Z', AT)).toBe('just now')
  })
})

describe('the viewer\'s own clock, secondary, only when it differs (M19A §4.3, §5)', () => {
  afterEach(() => jest.restoreAllMocks())

  it('is nothing at the estate: most people never see it', () => {
    setSiteZone(KINGSTON)
    phoneIn(KINGSTON)
    expect(yourTimeLine('2026-09-27T13:58:00Z', '2026-09-27T16:00:00Z')).toBeNull()
    expect(estateClockLine(NOON_UTC)).toBeNull()
    expect(estateNowLine(NOON_UTC)).toBeNull()
    expect(readingAnotherClock()).toBe(false)
  })

  it('gives Pakistan\'s clock under a Jamaican window, across midnight there', () => {
    setSiteZone(KINGSTON)
    phoneIn(KARACHI)
    // 8:58 – 11:00 AM on Sunday 27 September in Kingston is 6:58 – 9:00 PM in Karachi…
    // Its days in the one form (M98).
    expect(yourTimeLine('2026-09-27T13:58:00Z', '2026-09-27T16:00:00Z')).toBe('6:58 PM – 9:00 PM your time (27 Sep 2026)')
    // …and a window late in Kingston's evening runs over midnight in Karachi: both days are named.
    expect(yourTimeLine('2026-09-28T01:00:00Z', '2026-09-28T03:00:00Z')).toBe('6:00 AM – 8:00 AM your time (28 Sep 2026)')
    expect(yourTimeLine('2026-09-27T18:58:00Z', '2026-09-27T21:00:00Z')).toBe('11:58 PM – 2:00 AM your time (27 Sep 2026 – 28 Sep 2026)')
    expect(yourTimeLine('2026-09-27T14:38:00Z')).toBe('7:38 PM your time (27 Sep 2026)')
  })

  it('names the estate\'s clock only on a phone set to another zone: the header, and beside a time field', () => {
    setSiteZone(KINGSTON)
    phoneIn(KARACHI)
    expect(estateClockLine('2026-09-27T14:38:00Z')).toBe('Estate time · 9:38 AM')
    expect(estateNowLine('2026-09-27T14:38:00Z')).toBe('Jamaica time: it is 9:38 AM there now.')
    // Unused since M46 (no screen draws it), kept for the hub to decide (M98).
    expect(estateClockExplained()).toBe('This estate is on Jamaica time. Times you see and times you enter are the estate’s clock, so your visitors and the guards all agree.')
  })
})

/*
 * M19A §8.6 — DAYLIGHT SAVING, ON AN ESTATE THAT KEEPS IT. New York springs forward on Sunday 8 March 2026 (2:00 AM →
 * 3:00 AM) and falls back on Sunday 1 November 2026 (2:00 AM → 1:00 AM). A shift and a meeting either side of each
 * change read correctly, and what the phone sends carries the offset of its own day.
 */
describe('a United States estate across both daylight-saving changes', () => {
  beforeEach(() => setSiteZone(NEW_YORK))

  it('springs forward: a night shift from 10 PM Saturday to 6 AM Sunday is seven hours long, and reads right both ends', () => {
    const start = atSiteZone('2026-03-07', 22 * 60)
    const end = atSiteZone('2026-03-08', 6 * 60)
    expect(start).toBe('2026-03-07T22:00:00-05:00')
    expect(end).toBe('2026-03-08T06:00:00-04:00')
    expect(timeTag(start)).toBe('10:00 PM')
    expect(timeTag(end)).toBe('6:00 AM')
    expect(duration(new Date(end).getTime() - new Date(start).getTime())).toBe('7h 0m')
    expect(dateShort(end)).toBe('8 Mar 2026')
  })

  it('falls back: the same shift in November is nine hours long', () => {
    const start = atSiteZone('2026-10-31', 22 * 60)
    const end = atSiteZone('2026-11-01', 6 * 60)
    expect(start).toBe('2026-10-31T22:00:00-04:00')
    expect(end).toBe('2026-11-01T06:00:00-05:00')
    expect(timeTag(end)).toBe('6:00 AM')
    expect(duration(new Date(end).getTime() - new Date(start).getTime())).toBe('9h 0m')
  })

  it('keeps a 7 PM meeting at 7 PM on the Friday before and the Monday after each change', () => {
    for (const day of ['2026-03-06', '2026-03-09', '2026-10-30', '2026-11-02']) {
      expect(timeTag(atSiteZone(day, 19 * 60))).toBe('7:00 PM')
    }
  })

  it('a Jamaican estate beside it does not move at all: Jamaica keeps no daylight saving', () => {
    for (const day of ['2026-03-07', '2026-03-09', '2026-10-31', '2026-11-02']) {
      expect(atSiteZone(day, 19 * 60, KINGSTON)).toBe(`${day}T19:00:00-05:00`)
    }
  })
})
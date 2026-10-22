import { readFileSync } from 'node:fs'
import path from 'node:path'
import { HELD, calendarDate, daysLeft, licenceView, licenceWarning, todayAt, type Licence } from '../licence'

const licence = (over: Partial<Licence> = {}): Licence => ({ name: 'PSRA licence', number: 'PSRA-004471', expires_on: '2026-11-30', state: 'valid', days_left: 71, warning_days: 30, ...over })

describe('a calendar date is a calendar date', () => {
  it('prints the day the office typed, never shifted by a timezone', () => {
    expect(calendarDate('2026-11-30')).toBe('Nov 30, 2026')
    expect(calendarDate('2026-01-01')).toBe('Jan 1, 2026')
    expect(calendarDate('2026-11-30T00:00:00+00:00')).toBe('Nov 30, 2026')
  })
  it('is null for anything that is not a date', () => {
    expect(calendarDate(null)).toBeNull()
    expect(calendarDate('')).toBeNull()
    expect(calendarDate('soon')).toBeNull()
    expect(calendarDate('2026-13-40')).toBeNull()
  })
  it('counts whole days: 0 on the day, negative after', () => {
    expect(daysLeft('2026-11-30', '2026-11-30')).toBe(0)
    expect(daysLeft('2026-11-30', '2026-11-18')).toBe(12)
    expect(daysLeft('2026-11-30', '2026-12-03')).toBe(-3)
    expect(daysLeft('2027-03-01', '2026-03-01')).toBe(365)
    expect(daysLeft('nope', '2026-12-03')).toBeNull()
  })
})

describe("today, at the site: by the zone's NAME", () => {
  // 03:00 UTC on the 21st is still the evening of the 20th in Kingston, and already the 21st in London.
  const at = new Date('2026-09-21T03:00:00Z')
  it('uses the IANA zone the server sent', () => {
    expect(todayAt('America/Jamaica', at)).toBe('2026-09-20')
    expect(todayAt('Europe/London', at)).toBe('2026-09-21')
  })
  it('falls back to the app\'s site clock when no zone has reached the phone, or the name is not one', () => {
    expect(todayAt(null, at)).toBe('2026-09-20')
    expect(todayAt('Not/AZone', at)).toBe('2026-09-20')
  })
  it('hard-codes no offset of its own', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'licence.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, '')
    expect(source).not.toMatch(/-\s*5\s*\*|3_600_000|UTC[−-]5/)
  })
})

describe('the warning: in plain words, inside 30 days and after', () => {
  it('says nothing while the licence has more than 30 days', () => {
    expect(licenceWarning(licence(), '2026-10-30')).toBeNull()
    expect(licenceWarning(licence(), '2026-09-20')).toBeNull()
  })
  it('warns from 30 days out, counting down in days', () => {
    expect(licenceWarning(licence(), '2026-10-31')).toMatchObject({ level: 'expiring', title: 'Licence expires in 30 days: Nov 30, 2026' })
    expect(licenceWarning(licence(), '2026-11-18')?.title).toBe('Licence expires in 12 days: Nov 30, 2026')
    expect(licenceWarning(licence(), '2026-11-29')?.title).toBe('Licence expires tomorrow: Nov 30, 2026')
    expect(licenceWarning(licence(), '2026-11-30')?.title).toBe('Licence expires today: Nov 30, 2026')
    expect(licenceWarning(licence(), '2026-11-18')?.body).toMatch(/Renew your PSRA licence/)
  })
  it('says EXPIRED once the day has passed, and what to do', () => {
    const w = licenceWarning(licence(), '2026-12-01')
    expect(w).toMatchObject({ level: 'expired', title: 'Licence expired: Nov 30, 2026' })
    expect(w?.body).toMatch(/ran out yesterday/)
    expect(w?.body).toMatch(/Call your supervisor/)
    expect(licenceWarning(licence(), '2026-12-10')?.body).toMatch(/ran out 10 days ago/)
  })
  it('is worked out from the DATE, not from the state the server sent with it: a saved "valid" goes stale', () => {
    expect(licenceWarning(licence({ state: 'valid', days_left: 71 }), '2026-12-05')?.level).toBe('expired')
    expect(licenceWarning(licence({ state: 'expired', days_left: -4 }), '2026-09-20')).toBeNull()
  })
  it('uses the server\'s window when it sends one', () => {
    expect(licenceWarning(licence({ warning_days: 60 }), '2026-10-05')?.level).toBe('expiring')
    expect(licenceWarning(licence({ warning_days: 0 }), '2026-10-05')).toBeNull() // nonsense falls back to 30
  })
  it('never warns about a date nobody has', () => expect(licenceWarning(licence({ expires_on: null }), '2026-12-05')).toBeNull())
})

describe('the rows My Profile draws', () => {
  it('shows the number and the date the office holds', () => {
    const v = licenceView(licence(), '2026-09-20')!
    expect(v.rows).toEqual([['PSRA licence', 'PSRA-004471'], ['Licence expires', 'Nov 30, 2026']])
    expect(v.number).toBe('PSRA-004471')
    expect(v.warning).toBeNull()
  })
  it('says "Held by the office" where the server has none, never an invented number or date', () => {
    const v = licenceView(licence({ number: null, expires_on: null, state: 'unknown', days_left: null }), '2026-09-20')!
    expect(v.rows).toEqual([['PSRA licence', HELD], ['Licence expires', HELD]])
    expect(v.number).toBeNull()
    expect(v.warning).toBeNull()
    expect(licenceView(licence({ number: '   ' }), '2026-09-20')!.rows[0]).toEqual(['PSRA licence', HELD])
  })
  it('draws nothing at all before anything has reached the phone', () => {
    expect(licenceView(null, '2026-09-20')).toBeNull()
    expect(licenceView(undefined, '2026-09-20')).toBeNull()
  })
})

describe('the rules the screen must keep', () => {
  const screen = readFileSync(path.resolve(__dirname, '..', 'screens', 'ProfileScreen.tsx'), 'utf8')
  it('the warning is an alert with an icon and words: nothing relies on colour alone', () => {
    expect(screen).toMatch(/role="alert"/)
    expect(screen).toMatch(/warning\.title/)
    expect(screen).toMatch(/warning\.body/)
  })
  it('a licence that has RUN OUT reads differently from one running out: in its words, not in red (M12 §4.4)', () => {
    // Red is urgent and life-safety only: both are amber, and the title tells them apart.
    expect(screen).toMatch(/warnTone\(warning\.level\)/)
    expect(screen).not.toMatch(/'red-500'/)
    const expired = licenceWarning(licence({ expires_on: '2026-09-01', state: 'expired', days_left: -20 }), '2026-09-21')
    const expiring = licenceWarning(licence({ expires_on: '2026-10-03', state: 'expiring', days_left: 12 }), '2026-09-21')
    expect(expired?.level).toBe('expired')
    expect(expiring?.level).toBe('expiring')
    expect(expired?.title).not.toBe(expiring?.title)
  })

  it('a guard sees no pay on their profile', () => expect(screen).not.toMatch(/rate|salary|wage|J\$/i))
})

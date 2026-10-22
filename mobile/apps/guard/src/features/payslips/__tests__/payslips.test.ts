import { readFileSync } from 'node:fs'
import path from 'node:path'
import { DEFAULT_SITE_ZONE, forgetSiteZone, setSiteZone } from '@gs/core/lib'
import { dayRows, deduction, deductionLines, deductionRows, earningLines, employerLines, hourFigure, hoursFigure, hoursLabel, siteDate, newestFirst, queryDraft, queryRange, weekLabel, weekOf, weekTotals, yearToDateLines, type Payslip, type ShiftRow } from '../payslips'

// Friday 2026-09-04, 09:41 on the site's clock (Kingston).
const AT = new Date('2026-09-04T14:41:00Z')

/**
 * One payslip as the server now sends it (M8 §5.3): the hours and their rates, the earnings that
 * make up the gross, every deduction with its rate AND the figure it was taken on, the employer's
 * own contributions, and the year to date.
 */
const slip = (over: Partial<Payslip> = {}): Payslip => ({
  id: 41, run_reference: 'PR-2026-08', period_label: 'August 2026', period_start: '2026-08-01', period_end: '2026-08-31', status: 'paid', currency: 'JMD',
  gross: '85721.00', deductions: { nis: '2571.63', nht: '1714.42', education_tax: '1870.86', paye: '0.00', pension: '0.00' }, net: '79564.09', paye_note: null,
  employee: { name: 'Marcus Whyte', employee_number: 'GS-1041', trn: null, nis_number: null },
  employer: { name: 'Gemini Security', address: 'Shop #20P, Portmore Town Centre', email: 'gemisecja@gmail.com' },
  hours: { regular: '176.00', overtime: '12.50', regular_rate: '1050.00', overtime_rate: '1575.00', overtime_note: 'Overtime is paid at time and a half of the standard rate.' },
  earnings: { basic: '184800.00', overtime: '19687.50', allowances: '3500.00', gross: '85721.00', taxable_note: 'Basic pay, overtime and allowances are all taxable earnings.' },
  lines: [
    { key: 'nis', label: 'NIS', amount: '2571.63', rate: '3%', base: '85721.00', note: 'On gross pay, up to the insurable ceiling for the period.' },
    { key: 'nht', label: 'NHT', amount: '1714.42', rate: '2%', base: '85721.00', note: 'On gross pay, with no ceiling.' },
    { key: 'education_tax', label: 'Education Tax', amount: '1870.86', rate: '2.25%', base: '83149.37', note: 'On statutory income: gross less NIS.' },
    { key: 'paye', label: 'PAYE (income tax)', amount: '0.00', rate: '25%', base: '83149.37', note: 'On statutory income above the threshold.' },
  ],
  total_deductions: '6156.91',
  employer_contributions: { nis: '2571.63', nht: '2571.63', education_tax: '2743.93', heart: '2571.63', total: '10458.82', note: 'Paid by Gemini Security on top of your wage. Not taken from it.' },
  year_to_date: { year: '2026', periods: 8, gross: '685768.00', nis: '20573.04', nht: '13715.36', education_tax: '14966.88', paye: '0.00', net: '636512.72' },
  periods_per_year: 12,
  ...over,
})

const shift = (id: number, start: string, end: string, over: Partial<ShiftRow> = {}): ShiftRow => ({
  id, status: 'completed', post: { id: 1, name: 'Main Gate' }, site: { id: 1, name: 'Phoenix Park Village 1', timezone: DEFAULT_SITE_ZONE },
  rostered_start: start, rostered_end: end, actual_start: start, actual_end: end, worked_hours: 12, night_hours: 0, ...over,
})

describe('a payslip shows the server\'s figures and nothing else', () => {
  it('a deduction carries a minus; a zero one does not', () => {
    expect(deduction('2571.63')).toBe('−$2,571.63')
    expect(deduction('0.00')).toBe('$0.00')
    expect(deduction('-12.50')).toBe('−$12.50') // never a double sign
  })

  it('lists the four statutory lines, and a pension only when there is one', () => {
    expect(deductionLines(slip()).map((l) => l.label)).toEqual(['NIS', 'NHT', 'Education Tax', 'PAYE'])
    expect(deductionLines(slip({ deductions: { ...slip().deductions, pension: '1500.00' } })).at(-1)).toMatchObject({ label: 'Pension', amount: '−$1,500.00' })
  })

  it('prints no statutory RATE: a rate is the payroll engine\'s business, not the phone\'s', () => {
    for (const line of deductionLines(slip())) expect(line.label).not.toMatch(/%/)
  })

  it('a huge or many-placed amount is moved as characters, never through a float', () => {
    expect(deduction('90071992547409931234.555')).toBe('−$90,071,992,547,409,931,234.555')
  })

  it('orders newest first whatever order arrives', () => {
    const items = [slip({ id: 1, period_start: '2026-06-01' }), slip({ id: 3, period_start: '2026-08-01' }), slip({ id: 2, period_start: '2026-07-01' })]
    expect(newestFirst(items).map((s) => s.id)).toEqual([3, 2, 1])
  })

  it('a question to the office names the period and the run, never a figure', () => {
    const text = queryDraft(slip())
    expect(text).toBe('About my August 2026 payslip (PR-2026-08): ')
    expect(text).not.toMatch(/\$|\d{2},?\d{3}/)
  })

  it('does no arithmetic on money: no parseFloat, Number( or math operator near an amount, anywhere in the folder', () => {
    const dir = path.resolve(__dirname, '..')
    for (const file of ['payslips.ts', 'usePayslips.ts', 'screens/PayslipScreen.tsx']) {
      const source = readFileSync(path.join(dir, file), 'utf8')
      expect(source).not.toMatch(/parseFloat|parseInt\(|Number\((slip|s|d|item)\b|\.(gross|net|nis|nht|paye|pension|education_tax)\s*[-+*/]/)
    }
  })

  it('never reaches for shared storage, a file, a share sheet or a log', () => {
    const dir = path.resolve(__dirname, '..')
    for (const file of ['usePayslips.ts', 'screens/PayslipScreen.tsx']) {
      const source = readFileSync(path.join(dir, file), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
      expect(source).not.toMatch(/useOfflineCache|AsyncStorage|getStorage|jsonStore|expo-file-system|expo-sharing|expo-print|Share\.|console\./)
    }
  })
})

describe('a week, in Jamaica', () => {
  it('is Monday to Sunday around today', () => {
    expect(weekOf(AT)).toMatchObject({ from: '2026-08-31', to: '2026-09-06' })
    expect(weekOf(AT).days).toHaveLength(7)
    expect(weekOf(AT, -1)).toMatchObject({ from: '2026-08-24', to: '2026-08-30' })
  })

  it('a Sunday belongs to the week that is ending, and 03:00 UTC Monday is still Sunday in Kingston', () => {
    expect(weekOf(new Date('2026-09-06T20:00:00Z')).from).toBe('2026-08-31')
    expect(weekOf(new Date('2026-09-07T03:00:00Z')).from).toBe('2026-08-31')
    expect(weekOf(new Date('2026-09-07T06:00:00Z')).from).toBe('2026-09-07')
  })

  it('asks the server for a day either side, and labels the week itself', () => {
    expect(queryRange(weekOf(AT))).toEqual({ from: '2026-08-30', to: '2026-09-07' })
    expect(weekLabel(weekOf(AT))).toBe('31 Aug 2026 – 6 Sep 2026')
  })

  it('the site’s day of an instant', () => {
    expect(siteDate('2026-09-04T03:00:00Z')).toBe('2026-09-03')
    expect(siteDate('2026-01-01T04:59:00Z')).toBe('2025-12-31')
  })
})

describe('the daily breakdown', () => {
  const week = weekOf(AT)
  const items = [
    shift(1, '2026-08-31T12:02:00Z', '2026-09-01T00:05:00Z', { worked_hours: 12.05 }),
    shift(2, '2026-09-04T12:24:00Z', '2026-09-05T00:00:00Z', { status: 'on_duty', actual_end: null, worked_hours: 4.2 }),
    shift(3, '2026-09-05T12:00:00Z', '2026-09-06T00:00:00Z', { status: 'rostered', actual_start: null, actual_end: null, worked_hours: 0 }),
    shift(4, '2026-09-02T12:00:00Z', '2026-09-03T00:00:00Z', { status: 'missed', actual_start: null, actual_end: null, worked_hours: 0 }),
    // An evening shift the Sunday BEFORE this week: Monday in UTC, Sunday in Kingston — not this week's.
    shift(5, '2026-08-31T01:00:00Z', '2026-08-31T11:00:00Z', { worked_hours: 10, night_hours: 8 }),
    // An open shift is nobody's hours.
    shift(6, '2026-09-03T12:00:00Z', '2026-09-04T00:00:00Z', { status: 'open', actual_start: null, actual_end: null, worked_hours: 0 }),
  ]
  const rows = dayRows(items, week, AT)

  it('has a row for every day, in order, and marks today', () => {
    expect(rows.map((r) => `${r.weekday} ${r.day}`)).toEqual(['MON 31', 'TUE 1', 'WED 2', 'THU 3', 'FRI 4', 'SAT 5', 'SUN 6'])
    expect(rows.filter((r) => r.today).map((r) => r.weekday)).toEqual(['FRI'])
  })

  it('a worked shift shows its real times and the server\'s hours', () => {
    expect(rows[0]).toMatchObject({ title: 'Main Gate', detail: '7:02 AM – 7:05 PM', hours: '12.1h' })
  })

  it('a running shift is "In progress" with the server\'s hours so far', () => {
    expect(rows[4]).toMatchObject({ detail: '7:24 AM – In progress', hours: '4.2h' })
  })

  it('a rostered shift has no hours yet; a missed one says it was not worked', () => {
    expect(rows[5]).toMatchObject({ detail: 'Rostered 7:00 AM – 7:00 PM', hours: '—' })
    expect(rows[2]).toMatchObject({ detail: '7:00 AM – 7:00 PM · not worked', hours: '—' })
  })

  it('an empty day says "No shift": the server does not say why, so neither does the app', () => {
    expect(rows[1]).toMatchObject({ title: 'No shift', hours: '—' })
    expect(rows[3]).toMatchObject({ title: 'No shift' }) // the open shift is not the guard's
  })

  it('totals only what was worked in THIS Jamaica week', () => {
    expect(weekTotals(items, week)).toEqual({ worked: 16.25, night: 0, shifts: 2 })
  })

  it('formats hours', () => {
    expect(hoursLabel(12)).toBe('12.0h')
    expect(hoursLabel(4.24)).toBe('4.2h')
    expect(hoursFigure(40)).toBe('40')
    expect(hoursFigure(7.5)).toBe('7.5')
  })
})

/**
 * THE ONE CLOCK — the roster is read on the SITE's clock, from the IANA name the server sent, and never on
 * the handset's. The whole suite also runs with TZ=Asia/Tokyo, so a wrong answer here cannot hide.
 */
describe('a shift is read on the site\'s clock', () => {
  afterEach(() => forgetSiteZone())

  // What the database holds for a 07:00–19:00 Kingston shift.
  const held = shift(9, '2026-09-21T12:00:00Z', '2026-09-22T00:00:00Z', { status: 'rostered', actual_start: null, actual_end: null, worked_hours: 0 })
  const monday = new Date('2026-09-21T16:00:00Z')

  it('renders 12:00Z as "7:00 AM – 7:00 PM" at a Kingston site', () => {
    const rows = dayRows([held], weekOf(monday), monday)
    expect(rows.find((r) => r.day === '21')).toMatchObject({ detail: 'Rostered 7:00 AM – 7:00 PM' })
    expect(siteDate(held.rostered_start)).toBe('2026-09-21')
  })

  it('renders the SAME instant as "8:00 AM – 8:00 PM" once the server says the site is in New York', () => {
    setSiteZone('America/New_York')
    const rows = dayRows([held], weekOf(monday), monday)
    expect(rows.find((r) => r.day === '21')).toMatchObject({ detail: 'Rostered 8:00 AM – 8:00 PM' })
  })

  it('cuts the week on the site\'s calendar, so a Kingston Sunday evening is not Monday', () => {
    // 01:00Z on Monday 21 September is 8 PM on SUNDAY the 20th in Kingston — the week before.
    expect(siteDate('2026-09-21T01:00:00Z')).toBe('2026-09-20')
    expect(weekOf(monday).days).toContain('2026-09-21')
    expect(weekOf(monday).days).not.toContain('2026-09-20')
  })
})

describe('the payslip a guard can check: M8 §5.3', () => {
  it('shows each deduction with its rate AND the figure it was taken on', () => {
    const rows = deductionRows(slip())

    expect(rows.map((r) => r.key)).toEqual(['nis', 'nht', 'education_tax', 'paye'])
    expect(rows[0]).toMatchObject({ label: 'NIS', rate: '3%', base: '$85,721.00' })
    // Education Tax is taken on statutory income, NOT on gross — which is why the base is shown at all.
    expect(rows[2]!.base).toBe('$83,149.37')
    expect(rows[2]!.base).not.toBe(rows[0]!.base)
    // A deduction still reads as money coming off.
    expect(rows[0]!.amount.startsWith('−')).toBe(true)
  })

  it('splits the earnings that make up the gross, and falls back to the gross alone on an older slip', () => {
    expect(earningLines(slip()).map((l) => l.key)).toEqual(['basic', 'overtime', 'allowances', 'gross'])

    const older = slip({ earnings: undefined as never, hours: null })
    expect(earningLines(older).map((l) => l.key)).toEqual(['gross'])
    expect(earningLines(older)[0]!.amount).toBe('$85,721.00')
  })

  it('keeps the employer’s contributions out of the deductions entirely', () => {
    const employer = employerLines(slip())
    const deductions = deductionRows(slip())

    expect(employer.map((l) => l.key)).toEqual(['nis', 'nht', 'education_tax', 'heart', 'total'])
    // Nothing the employer paid appears as an amount coming off the wage.
    for (const line of employer) expect(line.amount.startsWith('−')).toBe(false)
    expect(deductions.some((d) => d.key === 'heart')).toBe(false)
  })

  it('shows the tax year so far, and says nothing when the server sent none', () => {
    expect(yearToDateLines(slip()).map((l) => l.key)).toEqual(['gross', 'nis', 'nht', 'education_tax', 'paye', 'net'])
    expect(yearToDateLines(slip({ year_to_date: undefined as never }))).toEqual([])
  })

  it('prints hours as the server states them, and a dash where there are none', () => {
    expect(hourFigure('176.00')).toBe('176.00 h')
    expect(hourFigure('12.50')).toBe('12.50 h')
    expect(hourFigure(null)).toBe('—')
    expect(hourFigure(undefined)).toBe('—')
  })
})

/**
 * The guard's OWN pay and hours — the pure logic. The one folder in the Guard App that may format
 * money (tools/gates/guard-no-money.mjs), because it is the guard's wage and nobody's household.
 *
 * NO ARITHMETIC ON MONEY. Every figure is the server's decimal string, moved through money() and
 * nowhere else: nothing here adds, subtracts, derives a rate or checks a total. The server now sends
 * the whole slip — the hours and their rates, basic pay, overtime, allowances, each deduction's rate
 * AND the figure it was taken on, the employer's own contributions and the year to date (M8 §5.3) —
 * so all of it is SHOWN and none of it is worked out here. A total the phone computed could disagree
 * with the wage that was paid, and then neither figure could be trusted.
 * Hours are not money: they are summed here, because the SITE's week is not the server's UTC range.
 */

import { dateShort, siteDayIso, siteNoon, siteParts, money, moneySign, time12 } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

export type Payslip = Api.PayslipsMeResponse['items'][number]
export type ShiftRow = Api.ShiftsMeResponse['items'][number]

/* ── payslip ─────────────────────────────────────────────────────────────── */

/** Every line is white: the board drew deductions red, and red is urgent and life-safety only (M12 §4.4, D-185). */
export type PayLine = { key: string; label: string; amount: string; tone: 'white' }

/** A deduction reads "−$2,571.63"; a zero one reads "$0.00" with no sign, as the board draws PAYE. The sign says it is taken off. */
export const deduction = (amount: string): string => (moneySign(amount) === 0 ? money(amount) : `−${money(amount).replace(/^-/, '')}`)

export function deductionLines(slip: Payslip): PayLine[] {
  const d = slip.deductions
  const lines: PayLine[] = [
    { key: 'nis', label: 'NIS', amount: deduction(d.nis), tone: 'white' },
    { key: 'nht', label: 'NHT', amount: deduction(d.nht), tone: 'white' },
    { key: 'education_tax', label: 'Education Tax', amount: deduction(d.education_tax), tone: 'white' },
    { key: 'paye', label: 'PAYE', amount: deduction(d.paye), tone: 'white' },
  ]
  // "zero unless one is on file" — a pension line appears only when there is one.
  if (moneySign(d.pension) !== 0) lines.push({ key: 'pension', label: 'Pension', amount: deduction(d.pension), tone: 'white' })
  return lines
}

/**
 * The deductions WITH the rate and the figure each was taken on (M8 §5.3).
 *
 * The rate alone is what makes a slip look checkable and be misleading: NIS is a percentage of gross
 * UP TO the period's insurable ceiling, so an officer above it who multiplies their gross by the rate
 * gets a bigger number than the platform did and concludes it is wrong. The base is the answer, and
 * it comes from the server's own calculation — never from a sum here.
 */
export type DeductionRow = { key: string; label: string; amount: string; rate: string | null; base: string | null; note: string }

export function deductionRows(slip: Payslip): DeductionRow[] {
  return (slip.lines ?? []).map((line) => ({
    key: line.key,
    label: line.label,
    amount: deduction(line.amount),
    rate: line.rate ?? null,
    base: line.base === null || line.base === undefined ? null : money(line.base),
    note: line.note,
  }))
}

/** The earnings that make up the gross — drawn only when the server sent them (an older slip has none). */
export function earningLines(slip: Payslip): PayLine[] {
  const e = slip.earnings

  if (!e || e.basic === null || e.basic === undefined) return [{ key: 'gross', label: 'Gross pay', amount: money(slip.gross), tone: 'white' }]

  return [
    { key: 'basic', label: 'Basic pay', amount: money(e.basic), tone: 'white' },
    { key: 'overtime', label: 'Overtime', amount: money(e.overtime ?? '0'), tone: 'white' },
    { key: 'allowances', label: 'Allowances', amount: money(e.allowances ?? '0'), tone: 'white' },
    { key: 'gross', label: 'Gross pay', amount: money(e.gross ?? slip.gross), tone: 'white' },
  ]
}

/** What the employer paid on top. Never a deduction — none of it came out of this wage. */
export function employerLines(slip: Payslip): PayLine[] {
  const c = slip.employer_contributions

  if (!c) return []

  return [
    { key: 'nis', label: 'NIS', amount: money(c.nis), tone: 'white' },
    { key: 'nht', label: 'NHT', amount: money(c.nht), tone: 'white' },
    { key: 'education_tax', label: 'Education Tax', amount: money(c.education_tax), tone: 'white' },
    { key: 'heart', label: 'HEART', amount: money(c.heart), tone: 'white' },
    { key: 'total', label: 'Total', amount: money(c.total), tone: 'white' },
  ]
}

/** The tax year so far, as the server counted it. */
export function yearToDateLines(slip: Payslip): PayLine[] {
  const y = slip.year_to_date

  if (!y) return []

  return [
    { key: 'gross', label: 'Gross', amount: money(y.gross), tone: 'white' },
    { key: 'nis', label: 'NIS', amount: money(y.nis), tone: 'white' },
    { key: 'nht', label: 'NHT', amount: money(y.nht), tone: 'white' },
    { key: 'education_tax', label: 'Education Tax', amount: money(y.education_tax), tone: 'white' },
    { key: 'paye', label: 'PAYE', amount: money(y.paye), tone: 'white' },
    { key: 'net', label: 'Net', amount: money(y.net), tone: 'white' },
  ]
}

/** "176.00 h" · "12.50 h" — hours as the server states them, never rounded into something tidier. */
export const hourFigure = (hours: string | null | undefined): string => (hours === null || hours === undefined ? '—' : `${hours} h`)

export const STATUS_WORD: Record<Payslip['status'], string> = { approved: 'Approved, not paid yet', paid: 'Paid' }

/** Newest first, as the server sends them; defensive about order because "next" and "previous" depend on it. */
export const newestFirst = (items: Payslip[]): Payslip[] => [...items].sort((p, q) => (p.period_start < q.period_start ? 1 : p.period_start > q.period_start ? -1 : 0))

/** A message to the office about a payslip names the period and the run — never a figure. */
export const queryDraft = (slip: Payslip): string => `About my ${slip.period_label} payslip (${slip.run_reference}): `

/* ── hours ───────────────────────────────────────────────────────────────── */

const DAY_MS = 86_400_000
const WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']

/** "2026-09-04" — the calendar day AT THE SITE that an instant falls on. */
export const siteDate = (input: Date | string): string => siteDayIso(input)

const shiftDate = (ymd: string, days: number): string => new Date(Date.parse(`${ymd}T12:00:00Z`) + days * DAY_MS).toISOString().slice(0, 10)

/** The Monday-to-Sunday week AT THE SITE that holds `at`, moved by `offset` whole weeks. */
export function weekOf(at: Date, offset = 0): { from: string; to: string; days: string[] } {
  const today = siteDate(at)
  const weekday = siteParts(at).weekday // 0 Sunday
  const monday = shiftDate(today, -((weekday + 6) % 7) + offset * 7)
  const days = Array.from({ length: 7 }, (_, i) => shiftDate(monday, i))
  return { from: monday, to: days[6]!, days }
}

/** What to ASK the server for: a day either side, because its range is in UTC and an evening at the site is tomorrow there. */
export const queryRange = (week: { from: string; to: string }): { from: string; to: string } => ({ from: shiftDate(week.from, -1), to: shiftDate(week.to, 1) })

/**
 * "1 Sep 2026 – 7 Sep 2026": each date in the one form, which carries its year (M98). The board's own "Sep 1 – Sep 7,
 * 2026" is drawn where `dateShort` still writes the board's form (the fidelity harness): the year once, at the end.
 */
export function weekLabel(week: { from: string; to: string }): string {
  const to = dateShort(siteNoon(week.to))
  return `${dateShort(siteNoon(week.from))} – ${to}${/\d{4}$/.test(to) ? '' : `, ${week.to.slice(0, 4)}`}`
}

/** "12.0h" · "4.2h" — one decimal, as the board draws it. */
export const hoursLabel = (hours: number): string => `${(Math.round(hours * 10) / 10).toFixed(1)}h`
/** "40" · "7.5" — a tile's figure: whole when it is whole. */
export const hoursFigure = (hours: number): string => { const r = Math.round(hours * 10) / 10; return Number.isInteger(r) ? String(r) : r.toFixed(1) }

export type DayRow = { key: string; weekday: string; day: string; title: string; detail: string; hours: string; today: boolean }

export function dayRows(items: ShiftRow[], week: { days: string[] }, at: Date): DayRow[] {
  const today = siteDate(at)
  return week.days.flatMap<DayRow>((ymd) => {
    const base = { weekday: WEEKDAYS[siteParts(siteNoon(ymd)).weekday]!, day: String(Number(ymd.slice(8))), today: ymd === today }
    const shifts = items.filter((s) => s.status !== 'open' && siteDate(s.rostered_start) === ymd).sort((p, q) => (p.rostered_start < q.rostered_start ? -1 : 1))
    if (shifts.length === 0) return [{ ...base, key: ymd, title: 'No shift', detail: '—', hours: '—' }]
    return shifts.map((s) => {
      const started = s.actual_start !== null
      const detail = s.status === 'missed' && !started ? `${time12(s.rostered_start)} – ${time12(s.rostered_end)} · not worked`
        : started ? `${time12(s.actual_start!)} – ${s.actual_end ? time12(s.actual_end) : 'In progress'}`
        : `Rostered ${time12(s.rostered_start)} – ${time12(s.rostered_end)}`
      return { ...base, key: `${ymd}-${s.id}`, title: s.post.name, detail, hours: started ? hoursLabel(s.worked_hours) : '—' }
    })
  })
}

export function weekTotals(items: ShiftRow[], week: { days: string[] }): { worked: number; night: number; shifts: number } {
  const mine = items.filter((s) => s.status !== 'open' && s.actual_start !== null && week.days.includes(siteDate(s.rostered_start)))
  const sum = (pick: (s: ShiftRow) => number) => Math.round(mine.reduce((total, s) => total + pick(s), 0) * 100) / 100
  return { worked: sum((s) => s.worked_hours), night: sum((s) => s.night_hours), shifts: mine.length }
}

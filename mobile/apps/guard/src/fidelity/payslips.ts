/**
 * The pay boards' own data (guard-app-12 the payslip · guard-app-19 my hours), served only in web
 * fidelity mode. Every use sits behind `isFidelity`, a build-time `false` on a phone.
 *
 * What the boards draw that the server does not have lives here and nowhere else:
 *   12  the split of earnings (basic pay, overtime at 1.5×, a laundry allowance), statutory RATES in the
 *       labels ("NIS (3%)"), regular / overtime / night-differential hours, "Download PDF" (a payslip may
 *       never be written to shared storage) and "Dispute a line" (there is no dispute endpoint). The server
 *       sends gross, the five deductions, net and why PAYE is what it is. The live screen shows exactly that.
 *   19  "regular" and "overtime" hours, "Rest day" against "Not scheduled". The server sends hours worked and
 *       night hours per shift; the live tiles are HOURS WORKED · NIGHT HOURS · SHIFTS and an empty day says "No shift".
 */

/** guard-app-12 · Digital Payslip */
export const payslipFixture = {
  period: 'August 2026',
  hours: [
    { figure: '160', label: 'REGULAR HRS' },
    { figure: '14', label: 'OVERTIME' },
    { figure: '32', label: 'NIGHT DIFF.' },
  ],
  earnings: [
    { label: 'Basic pay', amount: '$73,661.00', tone: 'white' },
    { label: 'Overtime (1.5×)', amount: '$9,660.00', tone: 'white' },
    { label: 'Laundry allowance', amount: '+$2,400.00', tone: 'green-400' },
    { label: 'Gross pay', amount: '$85,721.00', tone: 'white' },
  ],
  deductions: [
    { label: 'NIS (3%)', amount: '−$2,571.63' },
    { label: 'NHT (2%)', amount: '−$1,714.42' },
    { label: 'Education Tax (2.25%)', amount: '−$1,870.86' },
    { label: 'PAYE', amount: '$0.00' },
  ],
  net: '$79,564.09',
} as const

/** guard-app-19 · My Hours */
export const hoursFixture = {
  week: 'Sep 1 – Sep 7, 2026',
  tiles: [
    { figure: '40', label: 'REGULAR HRS' },
    { figure: '4', label: 'OVERTIME' },
    { figure: '8', label: 'NIGHT DIFF.' },
  ],
  days: [
    { key: 'mon', weekday: 'MON', day: '1', title: 'Main Gate', detail: '7:02 AM – 7:05 PM', hours: '12.0h' },
    { key: 'tue', weekday: 'TUE', day: '2', title: 'Main Gate', detail: '7:00 AM – 7:00 PM', hours: '12.0h' },
    { key: 'wed', weekday: 'WED', day: '3', title: 'Rest day', detail: '—', hours: '—' },
    { key: 'thu', weekday: 'THU', day: '4', title: 'Main Gate', detail: '7:24 AM – In progress', hours: '4.2h' },
    { key: 'fri', weekday: 'FRI', day: '5', title: 'Not scheduled', detail: '—', hours: '—' },
  ],
  payslipButton: 'View August payslip',
} as const

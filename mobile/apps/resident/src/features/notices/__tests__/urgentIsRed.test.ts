/**
 * "'Urgent' is red, everywhere it appears … Red stays reserved for urgent and life-safety, nothing else." (M12 §4.4)
 *
 * The board drew an urgent notice amber, the colour of a late bill, while an overdue bill, a reversed payment and a
 * declined booking were red. Now an urgent notice is red on the list and on the notice itself, and the standings
 * that only need attention are amber, with words that say which.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { chargeStatus, householdStanding } from '../../dues/duesLogic'
import { balanceView } from '../../home/homeLogic'

const source = (...parts: string[]) => readFileSync(path.join(__dirname, '..', '..', ...parts), 'utf8')

it('draws an urgent notice GOLD on the rebuilt list and the rebuilt notice (M42 §5 #11: red is for life-safety only)', () => {
  const list = source('notices', 'screens', 'NoticesScreen.tsx')
  expect(list).not.toMatch(/red-/)
  expect(list).toMatch(/<Badge key=\{tag\.label\} label=\{tag\.label\} urgent=\{tag\.urgent\} \/>/)
  expect(list).toMatch(/backgroundColor: fill\.urgentTint/)
  expect(source('notices', 'screens', 'NoticeDetailScreen.tsx')).not.toMatch(/red-/)
})

it('never draws a bill in red: overdue and in arrears are amber, and say so', () => {
  const balance = { balance: '6200.00', days_overdue: 34, oldest_unpaid_due_on: '2026-08-01', on_payment_plan: false, as_at: '2026-09-04', currency: 'JMD' } as never
  expect(chargeStatus({ status: 'overdue', due_on: '2026-08-01', outstanding: '6200.00' }, new Date('2026-09-04T12:00:00-05:00')).tone).toBe('amber-700')
  expect(householdStanding(balance, new Date('2026-09-04T12:00:00-05:00')).status?.tone).toBe('amber-700')
  expect(householdStanding({ ...(balance as object), access_restricted: true } as never, new Date('2026-09-04T12:00:00-05:00'))).toMatchObject({ status: { word: 'In arrears: access restricted', tone: 'amber-700' }, warningTone: 'amber' })
  expect(balanceView(balance, true).tone).toBe('amber-700')
})

it('draws a reversed payment and a declined booking amber, not red', () => {
  expect(source('dues', 'screens', 'PaymentsScreen.tsx')).not.toMatch(/red-/)
  expect(source('dues', 'screens', 'ReceiptScreen.tsx')).not.toMatch(/red-/)
  expect(source('amenities', 'screens', 'BookingAnswerScreen.tsx')).not.toMatch(/red-/)
})

/**
 * The dues boards' own data (resident-app-09 statement · -10 payment · -30 payment success ·
 * -35 add a card · -40 payments & AutoPay), served only in web fidelity mode. Every use sits
 * behind `isFidelity`.
 *
 * These boards draw a card gateway the platform does not have (Q-012: dues are paid by hand): a
 * saved Visa, a card form, "AUTOPAY IS ON". The saved Visa and AutoPay's terms exist here and
 * nowhere else; the card form is drawn on a phone too, and keeps and sends nothing (useDues.ts
 * useAddCard). On a phone the same screens show the ledger's figures and the office's receipts,
 * and Pay says the payment gateway is not integrated yet (M42 E6).
 */

import type { BalanceView, StatementRow } from '@/features/dues/statement'

/**
 * resident-app-09 as drawn. On a phone the same card and rows come from the ledger (features/dues/statement.ts) —
 * the four ageing boxes included, added up from the unpaid charges; the card payment on the third line is the design's.
 */
export const statementFixture: { balance: BalanceView; lines: StatementRow[] } = {
  balance: {
    amount: '$12,400.00',
    due: '2 months overdue · next due Sep 28',
    ageing: [
      { label: 'CURRENT', value: '$0' },
      { label: '30 DAYS', value: '$6,200' },
      { label: '60 DAYS', value: '$6,200' },
      { label: '90+ DAYS', value: '$0' },
    ],
    canPay: true,
  },
  lines: [
    { key: 's1', kind: 'charge', title: 'Maintenance fee — September', sub: 'Charged Sep 1 · due Sep 28', amount: '−$6,200', balance: 'Bal. $12,400' },
    { key: 's2', kind: 'charge', title: 'Maintenance fee — August', sub: 'Charged Aug 1 · overdue', amount: '−$6,200', balance: 'Bal. $6,200' },
    { key: 's3', kind: 'payment', title: 'Payment received — receipt #4471', sub: 'Jul 30 · Visa •••• 4417', amount: '+$6,200', balance: 'Bal. $0' },
    { key: 's4', kind: 'charge', title: 'Maintenance fee — July', sub: 'Charged Jul 1', amount: '−$6,200', balance: 'Bal. $6,200' },
  ],
}

/**
 * resident-app-10 as drawn (M42 §5 #9). On a phone the amounts are the ledger's balance and the line under the full
 * balance is worked out from the overdue charges (features/dues/pay.ts); the saved Visa, AutoPay's terms and AutoPay
 * switched ON are the design's alone — no card is stored, and the server refuses AutoPay until a provider exists.
 */
export const paymentFixture = {
  balance: '12400.00',
  clears: 'Clears both overdue months',
  card: { title: 'Visa •••• 4417', sub: 'Saved card · expires 08/28' },
  autopay: { on: true, line: 'Automatically pay up to $15,000 on the 28th each month.' },
} as const

export const successFixture = {
  title: 'Payment successful',
  summary: '$12,400.00 paid to Phoenix Park Village 1 · receipt #4472',
  lines: [
    { label: 'Maintenance fee — September', value: '$6,200.00' },
    { label: 'Maintenance fee — August', value: '$6,200.00' },
    { label: 'Paid with', value: 'Visa •••• 4417' },
    { label: 'Date & time', value: 'Sep 4, 9:41 AM' },
  ],
  total: { label: 'Your balance', value: '$0.00' },
  note: 'Your payment reminders for this balance have been turned off automatically — nothing more to do.',
  secondary: 'Download PDF',
  primary: 'Done',
} as const

/**
 * resident-app-35 as drawn. On a phone the placeholders and the cardholder name are read as the empty boxes' grey
 * examples (AddCardScreen.tsx); the card at the top shows what is typed. No card is ever stored or sent.
 */
export const addCardFixture = {
  brand: 'VISA',
  number: '•••• •••• •••• 0148',
  holderCaps: 'ANDREA FLETCHER',
  numberPlaceholder: '1234 5678 9012 0148',
  expiryPlaceholder: 'MM / YY',
  cvvPlaceholder: 'CVV',
  holder: 'Andrea Fletcher',
  defaultLabel: 'Set as default payment method',
  button: 'Save card',
  note: "Entered directly into our payment gateway's secure form. GeminiSecure never sees or stores your full card number.",
} as const

/**
 * resident-app-40 as drawn. On a phone only the four lines' LABELS are read (useDues.ts usePaymentsAndAutopay): no card
 * is stored and no AutoPay arrangement can exist, so the saved Visa, "AUTOPAY IS ON" and the terms are the design's alone.
 */
export const autopayFixture = {
  method: { title: 'Visa •••• 4417', sub: 'Expires 08/28', chip: 'Default' },
  heading: 'AUTOPAY IS ON',
  lines: [
    { label: 'Charges up to', value: '$15,000' },
    { label: 'On', value: 'The 28th of each month' },
    { label: 'Using', value: 'Visa •••• 4417' },
    { label: 'Next charge', value: 'Sep 28, 2026' },
  ],
  primary: 'Edit limit',
  secondary: 'Turn off',
  history: 'View all receipts',
} as const

/**
 * Dues & payments — board resident-app-09 (M42 §5 #8). The statement screen's pure logic: no React, no UI kit, so it is
 * unit-tested without a phone (__tests__/statement.test.ts).
 *
 * THE ONE SUM THE PHONE DOES. Everywhere else in the dues screens an amount is SHOWN from the ledger's decimal string
 * and never worked out (duesLogic.ts, packages/core/lib/money.ts). The client's design puts the balance in four ageing
 * boxes, and the server sends no ageing for a household — so they are added up here, from the household's own unpaid
 * charges (`invoices.index`: what is still owed on each charge, and the date it falls due):
 *
 *   CURRENT    owed on charges not yet past their due date (due today or later)
 *   30 DAYS    owed on charges 1 to 30 days past their due date
 *   60 DAYS    owed on charges 31 to 60 days past their due date
 *   90+ DAYS   owed on charges more than 60 days past their due date
 *
 * Days are counted on the estate's calendar, as `daysUntil` counts them. THIS RULE IS FOR THE CLIENT TO CONFIRM: it is
 * the reading the M42 order gave. The estate office's own ageing is different (board 6, `Dues::ageingOf` on the
 * server): it puts a unit's WHOLE balance in ONE bucket, by how long its oldest open charge has been due, at 30, 60 and
 * 90 days. This screen ages each charge on its own, as the resident design draws it ($6,200 and $6,200 in two boxes).
 *
 * EXACT, OR NOT AT ALL. Each amount is read as whole cents — an integer, from its characters — and the cents are added
 * as integers, never as floats. The four must add up to the ledger's balance to the cent; if they do not (the balance
 * holds something that is not one of these charges — an opening balance, a journal adjustment, more charges than the
 * list returns — or the two answers were fetched at different moments), no box is filled: the screen leaves the row
 * out rather than show figures that do not add up to the total above them.
 */

import { money, moneySign, siteParts } from '@gs/core/lib'
import { daysUntil, type Balance, type Charge, type StatementLine } from './duesLogic'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const
const DATE = /^(\d{4})-(\d{2})-(\d{2})/

/**
 * "2026-09-28" → "28 Sep 2026": the one way a date is written (M98, 12 Oct 2026, the consoles' form), its year every
 * time, so "1 Sep" twice in a statement that runs back years is never two different days. A DATE has no time zone: it
 * is read as written. `_at` is kept for the callers that still pass today.
 */
export function monthDay(date: string | null | undefined, _at?: Date): string | null {
  const m = DATE.exec(date ?? '')
  const month = m ? MONTHS[Number(m[2]) - 1] : undefined
  if (!m || !month) return null
  return `${Number(m[3])} ${month} ${m[1]}`
}

/* ---- whole cents ------------------------------------------------------------------------------------------------ */

const DECIMAL = /^\s*(-?)(\d+)(?:\.(\d{1,2}))?\s*$/

/**
 * "6200.50" → 620050: an amount as whole cents, read from its characters. Null for anything that is not a plain decimal
 * of at most two places, or too large to be held exactly — a figure that cannot be read exactly is not read at all.
 */
export function toCents(amount: string | null | undefined): number | null {
  const m = DECIMAL.exec(amount ?? '')
  if (!m) return null
  const cents = Number(m[2]) * 100 + Number((m[3] ?? '').padEnd(2, '0'))
  if (!Number.isSafeInteger(cents)) return null
  return m[1] === '-' && cents !== 0 ? -cents : cents
}

/** 620050 → "6200.50": whole cents back to the decimal string `money()` shows. Integer steps only. */
export function fromCents(cents: number): string {
  const whole = Math.abs(cents)
  const rest = whole % 100
  return `${cents < 0 ? '-' : ''}${(whole - rest) / 100}.${String(rest).padStart(2, '0')}`
}

/* ---- the ageing boxes ------------------------------------------------------------------------------------------- */

export const AGEING_LABELS = ['CURRENT', '30 DAYS', '60 DAYS', '90+ DAYS'] as const
export type AgeBox = { label: (typeof AGEING_LABELS)[number]; value: string }

/** The box a charge's unpaid amount sits in, by how many days it is PAST its due date (0 or less: not yet past). */
export function ageBucket(daysPastDue: number): 0 | 1 | 2 | 3 {
  if (daysPastDue <= 0) return 0
  if (daysPastDue <= 30) return 1
  if (daysPastDue <= 60) return 2
  return 3
}

/**
 * The four boxes — "$0", "$6,200", "$6,200", "$0" — or null when they cannot be filled honestly: an amount or a due date
 * that cannot be read, or unpaid amounts that do not add up to the balance to the cent. Nothing owed, or a credit:
 * nothing is outstanding in any box, and each is "$0".
 */
export function ageing(balance: string, charges: readonly Charge[], at: Date): AgeBox[] | null {
  const owed = toCents(balance)
  if (owed === null) return null
  const sums: [number, number, number, number] = [0, 0, 0, 0]
  for (const charge of charges) {
    const left = toCents(charge.outstanding)
    if (left === null || left < 0) return null
    if (left === 0) continue
    const until = daysUntil(charge.due_on, at)
    if (until === null) return null
    sums[ageBucket(-until)] += left
  }
  const total = sums[0] + sums[1] + sums[2] + sums[3]
  if (!Number.isSafeInteger(total) || total !== Math.max(owed, 0)) return null
  return sums.map((cents, i) => ({ label: AGEING_LABELS[i]!, value: money(fromCents(cents), 'compact') }))
}

/* ---- the gold line under the total ------------------------------------------------------------------------------ */

/** Whole calendar months from a date to today at the estate: Aug 1 → Sep 4 is 1, Aug 1 → Oct 1 is 2, Aug 31 → Sep 30 is 0. */
export function monthsSince(date: string, at: Date): number | null {
  const m = DATE.exec(date)
  if (!m) return null
  const today = siteParts(at)
  const months = (today.year - Number(m[1])) * 12 + (today.month + 1 - Number(m[2])) - (today.day < Number(m[3]) ? 1 : 0)
  return Number.isFinite(months) ? months : null
}

const count = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`

/**
 * "2 months overdue · next due 28 Sep 2026" (the board's "Sep 28" in M98's one form). How long the household has been overdue — the server's own `days_overdue`, from
 * its oldest unpaid charge (`oldest_unpaid_due_on`), said in whole months once it is a month or more and in days before
 * that; and the date the next unpaid charge falls due, when one has not fallen due yet. Null when neither is so.
 */
export function dueLine(b: Balance, charges: readonly Charge[] | null, at: Date): string | null {
  const parts: string[] = []
  if (b.days_overdue > 0) {
    const months = b.oldest_unpaid_due_on ? monthsSince(b.oldest_unpaid_due_on, at) : null
    parts.push(months !== null && months >= 1 ? `${count(months, 'month')} overdue` : `${count(b.days_overdue, 'day')} overdue`)
  }
  // ISO dates sort as text.
  const next = (charges ?? [])
    .filter((c) => moneySign(c.outstanding) > 0 && (daysUntil(c.due_on, at) ?? -1) >= 0)
    .map((c) => c.due_on)
    .sort()[0]
  const when = monthDay(next, at)
  if (when) parts.push(`next due ${when}`)
  const line = parts.join(' · ')
  return line === '' ? null : line.charAt(0).toUpperCase() + line.slice(1)
}

/* ---- the dark-blue card ----------------------------------------------------------------------------------------- */

export type BalanceView = {
  /** "$12,400.00" — what is owed. Nothing owed, or a credit, is "$0.00": nothing is outstanding. */
  amount: string
  /** "2 months overdue · next due 28 Sep 2026", or null when nothing is overdue or coming due. */
  due: string | null
  /** The four ageing boxes; null when they cannot be filled honestly (see `ageing`). */
  ageing: AgeBox[] | null
  /** Something is owed, so "Pay now" is drawn. */
  canPay: boolean
}

/** `charges` is null until `invoices.index` has answered: the line then says only what the balance says, and no box is filled. */
export function balanceView(b: Balance, charges: readonly Charge[] | null, at: Date): BalanceView {
  const owes = moneySign(b.balance) > 0
  return {
    amount: moneySign(b.balance) < 0 ? money('0.00') : money(b.balance),
    due: owes ? dueLine(b, charges, at) : null,
    ageing: charges ? ageing(b.balance, charges, at) : null,
    canPay: owes,
  }
}

/* ---- the statement's rows --------------------------------------------------------------------------------------- */

export type StatementRow = { key: string; kind: 'charge' | 'payment'; title: string; sub: string; amount: string; balance: string }

const sameAmount = (a: string | null, b: string | null): boolean => {
  const cents = toCents(a)
  return cents !== null && cents === toCents(b)
}

/** "due Sep 28" while a charge is unpaid and not yet past its date; "overdue" once it is; nothing once it is paid. */
function chargeState(charge: Charge, at: Date): string | null {
  if (moneySign(charge.outstanding) <= 0) return null
  const until = daysUntil(charge.due_on, at)
  if (until === null) return null
  return until < 0 ? 'overdue' : `due ${monthDay(charge.due_on, at) ?? charge.due_on}`
}

/** "Bal. $12,400" — the running balance; below zero it is a credit, "Credit $350.50". */
const runningBalance = (amount: string): string => (moneySign(amount) < 0 ? `Credit ${money(amount.replace('-', ''), 'compact')}` : `Bal. ${money(amount, 'compact')}`)

/**
 * The journal's lines exactly as the server sends them — its order (newest first), its memo, its running balance — in
 * the board's words: "Charged Sep 1 · due Sep 28", "Charged Aug 1 · overdue", "Charged Jul 1" once paid, and a payment's
 * date alone, "Jul 30". A charge is "−$6,200", a payment "+$6,200".
 *
 * A charge line's state is the charge's own (`invoices.index`). The server posts a charge's journal entry ON ITS DUE
 * DATE, with the charge's description as the line's memo (Dues::charge and Dues::chargeRun), so a line and its charge
 * are matched on those three facts — date and due date, memo and description, debit and amount — each charge to one
 * line at most. A line that no charge matches says only when it was charged.
 */
export function statementRows(lines: readonly StatementLine[], charges: readonly Charge[] | null, at: Date): StatementRow[] {
  const open = [...(charges ?? [])]
  return lines.map((line, i) => {
    const isPayment = moneySign(line.payment) > 0
    const date = monthDay(line.date, at) ?? line.date
    let sub = date
    if (!isPayment) {
      const k = open.findIndex((c) => c.due_on.slice(0, 10) === line.date.slice(0, 10) && c.description === line.description && sameAmount(c.amount, line.charge))
      const charge = k >= 0 ? open.splice(k, 1)[0] : undefined
      const state = charge ? chargeState(charge, at) : null
      sub = state ? `Charged ${date} · ${state}` : `Charged ${date}`
    }
    return {
      key: `${line.reference}:${i}`,
      kind: isPayment ? 'payment' : 'charge',
      title: line.description || (isPayment ? 'Payment received' : 'Charge'),
      sub,
      // The board's own signs. A line that carries neither amount shows a dash, not "−—".
      amount: isPayment ? `+${money(line.payment, 'compact')}` : line.charge === null ? '—' : `−${money(line.charge, 'compact')}`,
      balance: runningBalance(line.balance),
    }
  })
}

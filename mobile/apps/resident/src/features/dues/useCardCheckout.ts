/**
 * The card checkout's one act — paying with a TEST card (M12 §2.6, the client's instruction overriding D-077; the
 * server's D-179). Dues (`payments.card`), and an amenity booking's fee or deposit (`bookings.pay_card`).
 *
 *   THE CARD STAYS ON THE PHONE. The screen checks the form against Stripe's test list (testCheckout.ts) and this
 *            sends only WHICH test card it was — its brand and last four digits. A real card never gets this far:
 *            the field is wiped the moment one is recognised.
 *   ONLINE ONLY, SENT ONCE. A payment is never saved to send later (the outbox refuses `payments:` abilities) and a
 *            refusal is never retried: with no connection nothing is sent and the screen says nothing was charged.
 *   THE SERVER'S ANSWER, IN ITS WORDS. A test card Stripe declines comes back as Stripe's own sentence and what to
 *            do next — never a gateway code — and the form stays so another card can be tried. A success is the
 *            estate's real receipt and the balance from the ledger; every read that shows money is asked again.
 */

import { useCallback, useState } from 'react'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { amountOf, confirmPayment } from '@/features/settings/paymentsLock'
import { useAppDispatch, useAppSelector } from '@/store'
import type { Brand } from './testCheckout'

/**
 * `amount` on a fee is the figure the screen showed (the server's `fee_to_clear`): sent with the card, so the server
 * refuses a different one rather than take it. `book` (M47 §10) books an amenity's hours and pays for them in one act —
 * confirmed when it returns — for `total`, the server's own quote the Gazebo screen showed.
 */
export type CheckoutTarget =
  | { kind: 'dues'; amount: string }
  | { kind: 'fee' | 'deposit'; booking: string; amount?: string }
  | { kind: 'book'; amenity: string; startsAt: string; endsAt: string; total: string; rules: boolean }

export type CheckoutCard = { brand: Exclude<Brand, 'unknown'>; last4: string }

export type CheckoutOutcome =
  | { ok: true; receiptId: string | null; receiptNo: string | null; amount: string | null; balance: string | null; reference: string | null; booking?: Record<string, unknown> }
  | { ok: false; kind: 'declined' | 'refused' | 'offline'; message: string }

export const NO_CONNECTION_NOTHING_CHARGED =
  'There is no connection, so the payment was not sent and nothing was charged. A payment is never saved to send later: try again when you are connected.'

const UNREACHABLE =
  'The estate’s server could not be reached, so the payment may not have gone through. Nothing is sent again by itself: open Payments to see whether a receipt was issued before you try again.'

export function useCardCheckout(target: CheckoutTarget | null) {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const dispatch = useAppDispatch()
  const [payDues] = residentApi.usePaymentsCardMutation()
  const [payBooking] = residentApi.useBookingsPayCardMutation()
  const [book] = residentApi.useBookingsBookMutation()
  const [sending, setSending] = useState(false)

  const pay = useCallback(async (card: CheckoutCard): Promise<CheckoutOutcome> => {
    if (!target) return { ok: false, kind: 'refused', message: 'There is nothing to pay here.' }
    if (refusal) return { ok: false, kind: 'refused', message: refusal }
    if (!online) return { ok: false, kind: 'offline', message: NO_CONNECTION_NOTHING_CHARGED }
    // "Require biometrics for payments — above $5,000" (Security, M50 §3.3): the phone confirms its owner before anything
    // is sent. A fee whose amount the screen does not know is asked about too.
    const amount = target.kind === 'dues' ? amountOf(target.amount) : target.kind === 'book' ? amountOf(target.total) : amountOf(target.kind === 'fee' ? target.amount : undefined)
    const notConfirmed = await confirmPayment(amount)
    if (notConfirmed) return { ok: false, kind: 'refused', message: notConfirmed }
    setSending(true)
    try {
      const answer = target.kind === 'dues'
        ? await payDues({ amount: target.amount, brand: card.brand, last4: card.last4 })
        : target.kind === 'book'
          ? await book({ amenity_id: parseInt(target.amenity, 10), starts_at: target.startsAt, ends_at: target.endsAt, brand: card.brand, last4: card.last4, total: target.total, rules_accepted: target.rules })
          : await payBooking({
            booking: target.booking,
            part: target.kind,
            brand: card.brand,
            last4: card.last4,
            ...(target.kind === 'fee' && target.amount ? { amount: target.amount } : {}),
          })

      if ('error' in answer && answer.error) {
        const e = isApiError(answer.error) ? answer.error : null
        if (e?.code === 'card_declined') return { ok: false, kind: 'declined', message: e.message }
        if (!e || e.status === 'network' || e.status === 'timeout') return { ok: false, kind: 'offline', message: UNREACHABLE }
        return { ok: false, kind: 'refused', message: e.message }
      }

      // Every read that shows money, or the booking, is asked again: the balance, the statement, the lists — and, for a
      // booking just made, the household's passes, which its guests' pass has joined.
      dispatch(residentApi.util.invalidateTags(target.kind === 'book' ? ['dues', 'payments', 'bookings', 'passes'] : ['dues', 'payments', 'bookings']))

      const data = answer.data as Record<string, unknown> | undefined
      if (target.kind === 'book') {
        const receipt = (data?.receipt ?? null) as Record<string, unknown> | null
        return {
          ok: true,
          receiptId: receipt?.id === undefined || receipt === null ? null : String(receipt.id),
          receiptNo: typeof receipt?.receipt_no === 'string' ? receipt.receipt_no : null,
          amount: target.total,
          balance: null,
          reference: typeof receipt?.reference === 'string' ? receipt.reference : null,
          booking: data,
        }
      }
      if (target.kind === 'dues') {
        return {
          ok: true,
          receiptId: data?.id === undefined ? null : String(data.id),
          receiptNo: typeof data?.receipt_no === 'string' ? data.receipt_no : null,
          amount: typeof data?.amount === 'string' ? data.amount : null,
          balance: typeof data?.balance === 'string' ? data.balance : null,
          reference: typeof data?.reference === 'string' ? data.reference : null,
        }
      }
      const receipt = (data?.receipt ?? null) as Record<string, unknown> | null
      return {
        ok: true,
        receiptId: receipt?.id === undefined || receipt === null ? null : String(receipt.id),
        receiptNo: typeof receipt?.receipt_no === 'string' ? receipt.receipt_no : null,
        amount: typeof receipt?.amount === 'string' ? receipt.amount : null,
        balance: null,
        reference: typeof receipt?.reference === 'string' ? receipt.reference : null,
      }
    } finally {
      setSending(false)
    }
  }, [target, refusal, online, payDues, payBooking, book, dispatch])

  return { pay, sending, online, blocked: refusal }
}

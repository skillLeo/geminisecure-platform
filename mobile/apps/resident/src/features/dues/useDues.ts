/**
 * Dues — boards resident-app-09 · -10 · -30 · -35 · -40. THE MONEY HARD-STOP LIVES HERE.
 *
 * NO REAL MONEY MOVES IN THIS FILE. Dues are paid outside the app (M42 E6): the client's payment page,
 * once its link exists, and the estate office meanwhile. What the app records is the server's DEMONSTRATION
 * (pay.ts; the user, 7 October): Stripe's TEST cards, real receipts, no money — and only while the server says its
 * demonstration checkout is on, which ONE answer says (`cards.index`'s `available`, M50 §6.6). NOTHING here queues
 * or retries a payment (useCardCheckout.ts). Anything that stops a payment is said in the screen's own sheet, never
 * the phone's grey dialog (M50 §4.3).
 *
 *   STATEMENT (09)  the ledger's balance and the journal's lines, shown as sent; the four ageing boxes
 *                   added up from the unpaid charges, to the cent, and shown only when they come to the
 *                   balance (statement.ts). Everything kept on the phone is kept WITH the moment it was
 *                   fetched.
 *   PAY (10)        the ledger's balance — the Dues screen's own figure — in the full-balance option and
 *                   the button; the overdue charges for the line under it (pay.ts). The methods: the resident's
 *                   saved test cards (or the demonstration card with none saved), Bank transfer, Cash agent. A
 *                   card records the demonstration and opens Payment successful on its receipt; Bank transfer
 *                   and Cash agent show the estate's own instructions and the reference to quote. "Turn on
 *                   AutoPay" turns it on with the default card (or asks for a card to be saved first), and off.
 *   SUCCESS (30)    the estate's receipt (`payments.receipt`), in board 30's shape (duesLogic.ts `successView`):
 *                   the demonstration just made, or a payment the OFFICE recorded, opened from the history.
 *   ADD A CARD (35) the board's form, checked on the phone against Stripe's test list (testCheckout.ts) —
 *                   a real number is never kept — and "Save card" saves the TEST card by what names it
 *                   (`cards.store`: brand, last four, expiry, name; never the number, never the code). What is
 *                   typed is emptied when the screen closes.
 *   AUTOPAY (40)    the household's real arrangement (`autopay.show`): on or off, its limit, its day, its
 *                   card, the next day it pays; "Edit limit" chooses a new one, "Turn off" / "Turn on" do so.
 *                   The saved cards are listed, each opening Make default and Remove. The payment history is
 *                   `payments.index`; each payment opens its receipt (30).
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Keyboard, Platform } from 'react-native'
import { router, useFocusEffect } from 'expo-router'
import { isFidelity, now, useOfflineCache } from '@gs/core'
import { money, moneySign, siteParts } from '@gs/core/lib'
import { isApiError, keepForSharing, safeFileName, type Api, type ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import type { Note } from '@/design/parts'
import { addCardFixture, autopayFixture, paymentFixture, statementFixture, successFixture } from '@/fidelity/dues'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { useAppSelector } from '@/store'
import { cardPreview, fieldText, type CardField, type CardPreview } from './addCard'
import {
  paymentRows,
  successView,
  type PaymentRow,
  type Stamped,
  type SuccessView,
} from './duesLogic'
import {
  BANK_TRANSFER,
  CARDS_OFF,
  CASH_AGENT,
  CHOOSE_AN_AMOUNT,
  DEMO_CARD,
  DEMO_TEST_CARD,
  amountOf,
  autopayLine,
  boardDate,
  cleanAmount,
  clearsLine,
  fullBalanceTitle,
  ordinal,
  payLabel,
  savedCardMethod,
  savedCardOf,
  type MethodKey,
  type PayChoice,
  type PayMethod,
  type SavedCard,
} from './pay'
import { testCardFor, cardErrors, digitsOf } from './testCheckout'
import { receiptPdfBase64 } from './receiptPdf'
import { balanceView, statementRows, type BalanceView, type StatementRow } from './statement'
import { useCardCheckout } from './useCardCheckout'

/* ---- shared ------------------------------------------------------------------------------------ */

type Stampable<T> = { data?: T; fulfilledTimeStamp?: number }

/** The last good answer, kept with WHEN it was fetched — so its age can always be shown. */
function useStamped<T>(key: string, query: Stampable<T>): Stamped<T> | undefined {
  const stamped = useMemo<Stamped<T> | undefined>(
    () => (query.data === undefined ? undefined : { value: query.data, savedAt: new Date(query.fulfilledTimeStamp ?? now().getTime()).toISOString() }),
    [query.data, query.fulfilledTimeStamp],
  )
  return useOfflineCache(key, stamped)
}

/** The clock an age is read against; frozen by the fidelity harness. */
function useClock(): Date {
  const [tick, setTick] = useState(0)
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setTick((n) => n + 1), 60_000)
    return () => clearInterval(timer)
  }, [])
  return useMemo(() => now(), [tick]) // eslint-disable-line react-hooks/exhaustive-deps
}

const apiError = (e: unknown): ApiError | null => (isApiError(e) ? e : null)
const isDenied = (e: unknown) => apiError(e)?.status === 403
const noSignal = (e: ApiError | null) => e?.status === 'network' || e?.status === 'timeout'
const sentence = (e: ApiError | null, what: string) => (noSignal(e) ? `${what} could not be loaded: there is no connection, and nothing is saved on this phone yet.` : (e?.message ?? `${what} could not be loaded.`))

type SectionState = 'loading' | 'ready' | 'error'
const sectionState = (has: boolean, error: unknown): SectionState => (has ? 'ready' : error ? 'error' : 'loading')

function useHouseholdId(): { id: number | string | undefined; error: unknown; refetch: () => Promise<unknown> } {
  const household = residentApi.useMeHouseholdQuery(undefined, { skip: isFidelity })
  const cached = useOfflineCache('me.household', household.data)
  return { id: cached?.household?.id, error: household.error, refetch: household.refetch }
}

/* ---- 09 · the statement ------------------------------------------------------------------------ */

export type DuesModel = {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  /** The dark-blue card: the total, the gold line, the four ageing boxes, and whether there is anything to pay. */
  balance: BalanceView | null
  /** The statement's lines, newest first, each with its running balance. */
  lines: StatementRow[]
  linesState: SectionState
  refreshing: boolean
  refresh: () => void
  /** The same re-read without the pull-to-refresh spinner — run whenever the tab comes back into view. */
  reread: () => void
}

export function useDuesStatement(): DuesModel {
  const at = useClock()
  const home = useHouseholdId()
  const skip = isFidelity || home.id === undefined

  const balanceQ = residentApi.useHouseholdsBalanceQuery({ household: home.id ?? '' }, { skip })
  const balance = useStamped('dues.balance.stamped', balanceQ)
  const invoicesQ = residentApi.useInvoicesIndexQuery(undefined, { skip: isFidelity })
  const invoices = useStamped('dues.invoices', invoicesQ)
  const statementQ = residentApi.useHouseholdsStatementQuery({ household: home.id ?? '' }, { skip })
  const statement = useStamped('dues.statement', statementQ)

  const [refreshing, setRefreshing] = useState(false)
  const refetch = { home: home.refetch, balance: balanceQ.refetch, invoices: invoicesQ.refetch, statement: statementQ.refetch }
  const load = useCallback(
    (pulled: boolean) => {
      if (isFidelity) return
      if (pulled) setRefreshing(true)
      // A query that has not started (no household yet) cannot be refetched.
      void Promise.allSettled([refetch.home(), refetch.invoices(), ...(skip ? [] : [refetch.balance(), refetch.statement()])]).finally(() => setRefreshing(false))
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [skip, refetch.home, refetch.balance, refetch.invoices, refetch.statement],
  )
  const refresh = useCallback(() => load(true), [load])
  const reread = useCallback(() => load(false), [load])

  // The fidelity harness: the design's own content, exactly as the board draws it (fidelity/dues.ts).
  if (isFidelity) return { status: 'ready', errorMessage: null, ...statementFixture, linesState: 'ready', refreshing: false, refresh, reread }

  const denied = [home.error, balanceQ.error, invoicesQ.error].some(isDenied)
  const failure = apiError(balanceQ.error) ?? (home.id === undefined ? apiError(home.error) : null)
  // The charges give the ageing boxes, the next due date and each statement line's state; until they answer, those wait.
  const charges = invoices?.value.items ?? null

  return {
    status: balance ? 'ready' : denied ? 'denied' : failure ? 'error' : 'loading',
    errorMessage: failure ? sentence(failure, 'Your dues') : null,
    balance: balance ? balanceView(balance.value, charges, at) : null,
    lines: statement ? statementRows(statement.value.items, charges, at) : [],
    linesState: sectionState(!!statement, statementQ.error ?? (home.id === undefined ? home.error : undefined)),
    refreshing,
    refresh,
    reread,
  }
}

/* ---- 10 · pay the maintenance fee -------------------------------------------------------------- */

/**
 * THE RESIDENT'S SAVED CARDS AND THE HOUSEHOLD'S AUTOPAY (M50 §4.2 #3) — and, from them, the ONE answer to "can a card
 * be used here?" (M50 §6.6: "fix it in one place"): `available`, the server's `DemoCardPayments::enabled()`. Pay, Add a
 * card and Payments & AutoPay all read it from here, so no button decides it for itself. Kept on the phone with the
 * last answer, so the screens open with no connection.
 */
export type Wallet = {
  available: boolean
  cards: SavedCard[]
  /** The default card that can still pay — or the first that can. */
  payWith: SavedCard | null
  autopay: Api.AutopayShowResponse | undefined
  refetch: () => void
}

export function useWallet(): Wallet {
  const cardsQ = residentApi.useCardsIndexQuery(undefined, { skip: isFidelity })
  const autopayQ = residentApi.useAutopayShowQuery(undefined, { skip: isFidelity })
  const cards = useOfflineCache('dues.cards', cardsQ.data)
  const autopay = useOfflineCache('dues.autopay', autopayQ.data)
  const items = (cards?.items ?? []) as SavedCard[]
  const usable = items.filter((card) => !card.expired)
  const { refetch: refetchCards } = cardsQ
  const { refetch: refetchAutopay } = autopayQ
  return {
    available: (cards?.available ?? autopay?.available) === true,
    cards: items,
    payWith: usable.find((card) => card.default) ?? usable[0] ?? null,
    autopay,
    refetch: useCallback(() => {
      if (isFidelity) return
      void refetchCards()
      void refetchAutopay()
    }, [refetchCards, refetchAutopay]),
  }
}

const ONLINE_ONLY = 'This needs a connection, and nothing about money is ever saved to send later. Try again when you are connected.'

/** The server's sentence for a refusal, or what to say when it could not be asked. */
const refusalOf = (error: unknown, what: string): string => {
  const e = apiError(error)
  return noSignal(e) ? `The server could not be reached, so ${what}. Nothing was sent.` : (e?.message ?? `${what} just now: try again.`)
}

export type PayModel = {
  /** `settled`: nothing is owed (or the household is in credit), so there is nothing to pay. */
  status: 'loading' | 'ready' | 'settled' | 'error' | 'denied'
  errorMessage: string | null
  /** "Pay full balance — $12,400", and the true line under it ("Clears both overdue months"). */
  full: { title: string; sub: string }
  choice: PayChoice
  choose: (choice: PayChoice) => void
  /** The part-payment as typed, kept to digits and one decimal point. */
  part: string
  setPart: (text: string) => void
  /**
   * The rows to choose from: the resident's saved test cards (the default first, and chosen), or — with none saved —
   * the demonstration card (DEMO_CARD, which says what it is); then Bank transfer and Cash agent. Cards only while the
   * server's demonstration checkout is on. The design's own Visa in fidelity mode.
   */
  methods: PayMethod[]
  method: MethodKey
  chooseMethod: (key: MethodKey) => void
  /** "Add new card": the app's own Add a card screen. */
  addCard: () => void
  /** "Turn on AutoPay" as it truly stands (`autopay.show`), and its line: the terms it pays on. */
  autopay: { on: boolean; line: string | null; toggle: () => void; busy: boolean }
  /** "Pay $12,400.00" — or the part-payment as typed. */
  payLabel: string
  /**
   * A card: records the server's demonstration payment and opens Payment successful. Bank transfer or Cash agent: the
   * estate's own instructions and the reference to quote, in the screen's sheet.
   */
  pay: () => void
  /** The demonstration payment is on its way to the server: the button waits, and a second tap sends nothing. */
  paying: boolean
  /** What stopped a payment or a switch, or how to pay by hand — in the screen's own sheet, never a grey dialog. */
  note: Note | null
  clearNote: () => void
  refresh: () => void
}

export function usePay(): PayModel {
  const at = useClock()
  const home = useHouseholdId()
  const skip = isFidelity || home.id === undefined

  // The Dues screen's own figure, from the same cache: the two can never quote the household two balances.
  const balanceQ = residentApi.useHouseholdsBalanceQuery({ household: home.id ?? '' }, { skip })
  const balance = useStamped('dues.balance.stamped', balanceQ)
  const invoicesQ = residentApi.useInvoicesIndexQuery(undefined, { skip: isFidelity })
  const invoices = useStamped('dues.invoices', invoicesQ)
  const wallet = useWallet()
  const switches = useAutopaySwitch(wallet)
  const [note, setNote] = useState<Note | null>(null)

  // How a household pays by hand is the server's to say (`invoices.pay_intent`, which writes nothing): asked when Bank
  // transfer or Cash agent is chosen and Pay is pressed, of a charge still owed.
  const [askIntent] = residentApi.useInvoicesPayIntentMutation()
  const openCharge = (invoices?.value.items ?? []).find((charge) => moneySign(charge.outstanding) > 0)?.id
  const online = useAppSelector((s) => s.network.online)

  // The fidelity harness: the design's own content (fidelity/dues.ts) — the saved Visa first, as drawn. Live: the saved
  // test cards that can still pay, or the demonstration card with none saved — only while cards are on.
  const usable = wallet.cards.filter((card) => !card.expired)
  const methods = useMemo<PayMethod[]>(
    () =>
      isFidelity
        ? [{ key: 'card', ...paymentFixture.card }, BANK_TRANSFER, CASH_AGENT]
        : [...(wallet.available ? (usable.length > 0 ? [...usable].sort((a, b) => Number(b.default) - Number(a.default)).map(savedCardMethod) : [DEMO_CARD]) : []), BANK_TRANSFER, CASH_AGENT],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [wallet.available, JSON.stringify(usable)],
  )
  const [choice, setChoice] = useState<PayChoice>('full')
  const [part, setPart] = useState('')
  // The first row is chosen until the resident chooses — the default card, once the server has said cards are on.
  const [chosen, setMethod] = useState<MethodKey | null>(null)
  const method = chosen !== null && methods.some((m) => m.key === chosen) ? chosen : methods[0]!.key

  const owed = isFidelity ? paymentFixture.balance : (balance?.value.balance ?? null)
  const amount = choice === 'full' ? owed : amountOf(part)
  const checkout = useCardCheckout(amount === null ? null : { kind: 'dues', amount })
  // State lands a render late: two fast taps must not record two payments.
  const inFlight = useRef(false)

  /** Bank transfer or Cash agent: the estate's own words, and the reference that makes the money find this household. */
  const howToPay = useCallback(async (by: 'bank' | 'cash') => {
    const title = by === 'bank' ? 'Pay by bank transfer' : 'Pay a cash agent'
    if (!online) return setNote({ title, lines: ['There is no connection, so the estate\'s payment details could not be fetched. They are also on your statement: quote your lot number.'] })
    if (openCharge === undefined) return setNote({ title, lines: ['Nothing is owed just now, so there is nothing to pay.'] })
    const answer = await askIntent({ invoice: openCharge })
    if ('error' in answer && answer.error) return setNote({ title, lines: [refusalOf(answer.error, 'the payment details could not be fetched')] })
    const intent = answer.data as { instructions?: string; quote_reference?: string; amount_due?: string } | undefined
    setNote({ title, lines: [intent?.instructions ?? 'Pay at the estate office, or by bank transfer to the account on your statement.', ...(intent?.quote_reference ? [`Quote this reference: ${intent.quote_reference}`] : [])] })
  }, [online, openCharge, askIntent])

  const pay = useCallback(() => {
    Keyboard.dismiss()
    if (isFidelity) return
    if (amount === null || moneySign(amount) <= 0) return setNote({ title: 'Pay maintenance fee', lines: [CHOOSE_AN_AMOUNT] })
    if (method === 'bank' || method === 'cash') return void howToPay(method)
    const card = method === 'card' ? DEMO_TEST_CARD : savedCardOf(method, wallet.cards)
    if (!wallet.available || card === null) return setNote({ title: 'Pay by card', lines: [CARDS_OFF] })
    if (inFlight.current) return
    inFlight.current = true
    void checkout
      .pay({ brand: card.brand as 'visa', last4: card.last4 })
      .then((outcome) => {
        if (!outcome.ok) return setNote({ title: outcome.kind === 'declined' ? 'Card declined' : 'Not paid', lines: [outcome.message] })
        // Replaced, not pushed: Back from the receipt is Dues, never a Pay screen asking for what was just paid.
        router.replace((outcome.receiptId ? { pathname: '/dues/success', params: { payment: outcome.receiptId } } : '/dues/success') as never)
      })
      .finally(() => {
        inFlight.current = false
      })
  }, [method, amount, checkout, wallet, howToPay])

  const toggle = useCallback(() => {
    if (isFidelity) return
    void (wallet.autopay?.on ? switches.turnOff() : switches.turnOn()).then((said) => {
      if (said) setNote(said)
    })
  }, [wallet.autopay?.on, switches])

  const refetch = { home: home.refetch, balance: balanceQ.refetch, invoices: invoicesQ.refetch }
  const refresh = useCallback(
    () => {
      if (isFidelity) return
      // A query that has not started (no household yet) cannot be refetched.
      void Promise.allSettled([refetch.home(), refetch.invoices(), ...(skip ? [] : [refetch.balance()])])
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [skip, refetch.home, refetch.balance, refetch.invoices],
  )

  const denied = [home.error, balanceQ.error, invoicesQ.error].some(isDenied)
  const failure = apiError(balanceQ.error) ?? (home.id === undefined ? apiError(home.error) : null)

  return {
    status: owed !== null ? (moneySign(owed) > 0 ? 'ready' : 'settled') : denied ? 'denied' : failure ? 'error' : 'loading',
    errorMessage: failure ? sentence(failure, 'Your balance') : null,
    full: {
      title: owed !== null ? fullBalanceTitle(owed) : '',
      sub: isFidelity ? paymentFixture.clears : clearsLine(invoices?.value.items ?? null, at),
    },
    choice,
    choose: setChoice,
    part,
    setPart: (text) => setPart(cleanAmount(text)),
    methods,
    method,
    chooseMethod: setMethod,
    addCard: () => router.push('/dues/add-card' as never),
    autopay: {
      on: isFidelity ? paymentFixture.autopay.on : wallet.autopay?.on === true,
      line: isFidelity ? paymentFixture.autopay.line : autopayTermsLine(wallet.autopay),
      toggle,
      busy: switches.busy,
    },
    payLabel: payLabel(amount),
    pay,
    paying: checkout.sending,
    note,
    clearNote: () => setNote(null),
    refresh,
  }
}

/** "Automatically pay up to $15,000 on the 28th each month." — the arrangement's own terms, or the ones it would start on. */
const autopayTermsLine = (autopay: Api.AutopayShowResponse | undefined): string | null => {
  if (!autopay) return null
  const limit = autopay.on && autopay.limit ? autopay.limit : autopay.defaults?.limit
  const day = autopay.on && autopay.day ? autopay.day : autopay.defaults?.day
  return limit && day ? autopayLine(limit, day) : null
}

/* ---- 30 · Payment successful: the estate's receipt ------------------------------------------------- */

export type PaymentSuccessModel = {
  status: 'loading' | 'ready' | 'empty' | 'missing' | 'error' | 'denied'
  errorMessage: string | null
  /** The board's title, line, detail lines, "Your balance" and note — the design's own sample in fidelity mode. */
  view: SuccessView | null
  /** The outline button: the board's "Download PDF" — the receipt as a PDF made on the phone, for the share sheet. */
  secondary: { label: string; onPress: () => void }
  /** Set when the PDF could not be made or shared. */
  problem: string | null
  /** "Done": back where the resident came from — Dues after a payment, the history after a receipt. */
  done: () => void
  refresh: () => void
}

const leaveReceipt = () => (router.canGoBack() ? router.back() : router.replace('/(tabs)/dues' as never))

type Sharing = { isAvailableAsync: () => Promise<boolean>; shareAsync: (url: string, options?: { mimeType?: string; dialogTitle?: string; UTI?: string }) => Promise<void> }

/** The phone's share sheet, lazily and never on web: a build without it is "could not be made", not a crash. */
function sharingModule(): Sharing | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-sharing') as Partial<Sharing> | undefined
    return mod && typeof mod.shareAsync === 'function' && typeof mod.isAvailableAsync === 'function' ? (mod as Sharing) : null
  } catch {
    return null
  }
}

/** board 30 as drawn (fidelity/dues.ts). */
const BOARD_SUCCESS: SuccessView = {
  reversed: false,
  title: successFixture.title,
  line: successFixture.summary,
  details: [...successFixture.lines],
  total: successFixture.total,
  note: successFixture.note,
}

export function usePaymentSuccess(wanted: string | undefined): PaymentSuccessModel {
  const paymentsQ = residentApi.usePaymentsIndexQuery(undefined, { skip: isFidelity })
  const payments = useStamped('dues.payments', paymentsQ)
  // "Your balance": the Dues screen's own figure, from the same cache — asked again once a payment is made.
  const home = useHouseholdId()
  const balanceQ = residentApi.useHouseholdsBalanceQuery({ household: home.id ?? '' }, { skip: isFidelity || home.id === undefined })
  const balance = useStamped('dues.balance.stamped', balanceQ)

  // With no payment named, the newest one the estate recorded.
  const row = wanted ? payments?.value.items.find((p) => String(p.id) === wanted) : payments?.value.items[0]
  const id = wanted ?? (row ? String(row.id) : undefined)

  const receiptQ = residentApi.usePaymentsReceiptQuery({ payment: id ?? '' }, { skip: isFidelity || id === undefined })
  const stamped = useStamped(`dues.receipt.${wanted ?? 'latest'}`, { data: receiptQ.currentData, fulfilledTimeStamp: receiptQ.fulfilledTimeStamp })
  const receipt = stamped && String(stamped.value.id) === id ? stamped : undefined
  const [problem, setProblem] = useState<string | null>(null)

  const owed = balance?.value.balance ?? null
  const view = useMemo(
    () => (isFidelity ? BOARD_SUCCESS : receipt ? successView(receipt.value, owed) : row ? successView(row, owed) : null),
    [receipt, row, owed],
  )

  // "Download PDF": the receipt this screen shows, made into a PDF on the phone (receiptPdf.ts) and handed to the share
  // sheet — Save to Files, Drive, a viewer. One at a time; what went wrong is said under the buttons.
  const receiptNo = (receipt?.value ?? row)?.receipt_no ?? null
  const making = useRef(false)
  const downloadPdf = useCallback(async () => {
    if (!view || making.current) return
    making.current = true
    setProblem(null)
    try {
      const uri = await keepForSharing({ folder: 'receipts', name: `receipt-${safeFileName(receiptNo ?? 'payment')}.pdf`, base64: receiptPdfBase64(view) })
      const sheet = sharingModule()
      if (!uri || !sheet || !(await sheet.isAvailableAsync().catch(() => false))) return setProblem('The PDF could not be made on this phone.')
      await sheet.shareAsync(uri, { mimeType: 'application/pdf', dialogTitle: receiptNo ? `Receipt ${receiptNo}` : 'Receipt', UTI: 'com.adobe.pdf' })
    } catch {
      setProblem('The PDF could not be made on this phone.')
    } finally {
      making.current = false
    }
  }, [view, receiptNo])

  const refetchPayments = paymentsQ.refetch
  const refetchReceipt = receiptQ.refetch
  const refresh = useCallback(() => {
    if (isFidelity) return
    // A receipt query that has not started (no payment to name yet) cannot be refetched.
    void Promise.allSettled([refetchPayments(), ...(id !== undefined ? [refetchReceipt()] : [])])
  }, [refetchPayments, refetchReceipt, id])

  const receiptError = apiError(receiptQ.error)
  const listError = apiError(paymentsQ.error)
  const status: PaymentSuccessModel['status'] = view
    ? 'ready'
    : isDenied(paymentsQ.error) || isDenied(receiptQ.error)
      ? 'denied'
      : receiptError?.status === 404
        ? 'missing'
        : receiptError || (listError && !payments)
          ? 'error'
          : payments && wanted === undefined && payments.value.items.length === 0
            ? 'empty'
            : 'loading'

  return {
    status,
    // A refusal says the server's own sentence: this person's access and who can change it, or the account's state.
    errorMessage: status === 'error' ? sentence(receiptError ?? listError, 'This receipt') : status === 'denied' ? ((isDenied(paymentsQ.error) ? listError : receiptError)?.message ?? null) : null,
    view,
    // The board's "Download PDF", true on a phone: it makes the receipt's PDF (receiptPdf.ts). The measuring harness
    // only draws it.
    secondary: { label: successFixture.secondary, onPress: isFidelity ? () => undefined : () => void downloadPdf() },
    problem,
    done: isFidelity ? () => undefined : leaveReceipt,
    refresh,
  }
}

/* ---- 35 · add a card ------------------------------------------------------------------------------ */

export type AddCardModel = {
  /** The card at the top — its brand, "•••• •••• •••• 0148", the holder's name — from what is typed; the board's own in fidelity mode. */
  preview: CardPreview
  /** The four fields as typed, each kept to the board's format (addCard.ts). Held in this screen's memory only. */
  fields: Record<CardField, string>
  set: (field: CardField, text: string) => void
  /** "Set as default payment method": on, as the board draws it — the card saved becomes the one Pay and AutoPay use. */
  asDefault: boolean
  setAsDefault: (on: boolean) => void
  /** The board draws Card number focused — in fidelity mode, where the design's own state is drawn, and nowhere else. */
  numberLit: boolean
  /** "Save card": checks the form against Stripe's test list, then saves the TEST card by what names it (`cards.store`). */
  save: () => void
  saving: boolean
  /** Why it was not saved — in the screen's own sheet. */
  note: Note | null
  clearNote: () => void
}

/** The form as it opens: empty — with the board's own cardholder name in fidelity mode, where the design is drawn. */
const blankCard = (): Record<CardField, string> => ({ number: '', expiry: '', cvv: '', holder: isFidelity ? addCardFixture.holder : '' })

/** Board 35 as drawn (fidelity/dues.ts). */
const BOARD_CARD: CardPreview = { brand: addCardFixture.brand, number: addCardFixture.number, holder: addCardFixture.holderCaps }

/**
 * Add a card — board 35's form, and "Save card" works (M50 §4.2 #3). What is typed stays in this hook's state and is
 * shown on the card at the top as the board shows it. "Save card" checks it on the phone against Stripe's test list
 * (testCheckout.ts — a real number is refused, and wiped as it is recognised) and sends only what NAMES the test card:
 * its brand, last four, expiry and the name on it. Never the number, never the security code. Everything typed is
 * emptied when the screen closes — blurred or gone — so a card number never outlives the screen it was typed on.
 */
export function useAddCard(): AddCardModel {
  const [fields, setFields] = useState(blankCard)
  const [asDefault, setAsDefault] = useState(true)
  const [note, setNote] = useState<Note | null>(null)
  const [store, stored] = residentApi.useCardsStoreMutation()
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()

  useFocusEffect(
    useCallback(
      () => () => {
        setFields(blankCard())
        setAsDefault(true)
      },
      [],
    ),
  )

  const set = useCallback((field: CardField, text: string) => setFields((was) => ({ ...was, [field]: fieldText(field, text) })), [])

  const save = useCallback(async () => {
    Keyboard.dismiss()
    if (isFidelity || stored.isLoading) return
    if (refusal) return setNote({ title: 'Save card', lines: [refusal] })
    if (!online) return setNote({ title: 'Save card', lines: [ONLINE_ONLY] })
    const today = siteParts(now())
    const problems = Object.values(cardErrors({ number: fields.number, expiry: fields.expiry, cvc: fields.cvv, name: fields.holder }, { year: today.year, month: today.month })).filter((line): line is string => !!line)
    if (problems.length > 0) return setNote({ title: 'Check the card', lines: problems })
    const test = testCardFor(fields.number)
    const expiry = digitsOf(fields.expiry)
    if (!test || test.brand === 'unknown') return setNote({ title: 'Check the card', lines: ['That is not one of Stripe\'s test cards.'] })
    const answer = await store({ brand: test.brand, last4: digitsOf(fields.number).slice(-4), exp_month: Number(expiry.slice(0, 2)), exp_year: 2000 + Number(expiry.slice(2, 4)), holder: fields.holder.trim(), default: asDefault })
    if ('error' in answer && answer.error) return setNote({ title: 'Card not saved', lines: [refusalOf(answer.error, 'the card was not saved')] })
    setFields(blankCard())
    if (router.canGoBack()) router.back()
    else router.replace('/dues/autopay' as never)
  }, [fields, asDefault, online, refusal, store, stored.isLoading])

  return {
    preview: isFidelity ? BOARD_CARD : cardPreview(fields.number, fields.holder),
    fields,
    set,
    asDefault,
    setAsDefault,
    numberLit: isFidelity,
    save: () => void save(),
    saving: stored.isLoading,
    note,
    clearNote: () => setNote(null),
  }
}

/* ---- 40 · Payments & AutoPay -------------------------------------------------------------------------- */

/** Board 40's dark-blue AutoPay block, as it is TRUE: the household's own arrangement; the board's sample in fidelity mode. */
export type AutopayView = {
  /** True only for an arrangement that is on. */
  on: boolean
  /** "AUTOPAY IS ON" — or "AUTOPAY IS OFF", or "AUTOPAY IS NOT AVAILABLE" while card payments are off. */
  heading: string
  /** The four lines — "Charges up to", "On", "Using", "Next charge" — and their terms: the arrangement's, or those it would start on. */
  terms: { label: string; value: string | null }[]
  /** What its last run did, in the server's sentence — or null. */
  lastRun: string | null
  /** "Edit limit": the limits to choose from, in the screen's chooser. */
  editLimit: () => void
  /** "Turn off" while it is on, "Turn on" while it is off. */
  second: { label: string; onPress: () => void }
  busy: boolean
}

export type SavedCardRow = { key: string; title: string; sub: string; chip: string | null; onPress: (() => void) | null }

export type PaymentsModel = {
  /** The resident's saved cards, the default first, each opening Make default and Remove; the board's Visa in fidelity mode. */
  methods: SavedCardRow[]
  /** "Add a card": the app's own Add a card screen. */
  addCard: () => void
  autopay: AutopayView
  /** "Edit limit"'s choices, open or not. */
  limits: { open: boolean; choices: { key: string; label: string; on: boolean; pick: () => void }[]; close: () => void }
  /** "View all receipts": opens the payment history in its own card (and asks the server for it again), or closes it. */
  receipts: { open: boolean; toggle: () => void }
  /** Every payment the estate recorded (`payments.index`), newest first. */
  history: PaymentRow[]
  historyState: SectionState | 'denied'
  historyError: string | null
  /** A payment's receipt: Payment successful (30), on that payment. */
  openReceipt: (id: string) => void
  /** A card's options, or what stopped a switch — in the screen's own sheet. */
  note: Note | null
  clearNote: () => void
  refreshing: boolean
  refresh: () => void
}

/** The limits "Edit limit" offers, in dollars — the board's $15,000 among them. */
export const AUTOPAY_LIMITS = ['5000', '10000', '15000', '20000', '25000', '30000', '50000'] as const

/**
 * AUTOPAY, TURNED ON AND OFF (M50 §4.2 #3) — one switch for Pay (10) and Payments & AutoPay (40). ONLINE ONLY: nothing
 * about money is ever queued for later. On needs a saved card that can pay: with none, it says so and offers Add a card.
 * Each answers with what to say (or null when there is nothing to say), and the switch draws only what the server said.
 */
export function useAutopaySwitch(wallet: Wallet): { turnOn: (limit?: string) => Promise<Note | null>; turnOff: () => Promise<Note | null>; setLimit: (limit: string) => Promise<Note | null>; busy: boolean } {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const [store, stored] = residentApi.useAutopayStoreMutation()
  const [destroy, destroyed] = residentApi.useAutopayDestroyMutation()

  const guard = useCallback((): Note | null => {
    if (refusal) return { title: 'AutoPay', lines: [refusal] }
    if (!online) return { title: 'AutoPay', lines: [ONLINE_ONLY] }
    return null
  }, [online, refusal])

  const turnOn = useCallback(async (limit?: string): Promise<Note | null> => {
    const stop = guard()
    if (stop) return stop
    if (!wallet.available) return { title: 'AutoPay', lines: [wallet.autopay?.reason ?? 'AutoPay needs card payments, which are not switched on for this estate.'] }
    if (!wallet.payWith) {
      return { title: 'Save a card first', lines: ['AutoPay pays with one of your saved cards. Save one, then turn AutoPay on.'], actions: [{ label: 'Add a card', onPress: () => router.push('/dues/add-card' as never) }] }
    }
    const answer = await store({ card: wallet.payWith.id, ...(limit ? { limit } : {}) })
    wallet.refetch()
    return 'error' in answer && answer.error ? { title: 'AutoPay', lines: [refusalOf(answer.error, 'AutoPay was not turned on')] } : null
  }, [guard, wallet, store])

  const turnOff = useCallback(async (): Promise<Note | null> => {
    const stop = guard()
    if (stop) return stop
    const id = wallet.autopay?.id
    if (!id) return null
    const answer = await destroy({ autopay: id })
    wallet.refetch()
    return 'error' in answer && answer.error ? { title: 'AutoPay', lines: [refusalOf(answer.error, 'AutoPay was not turned off')] } : null
  }, [guard, wallet, destroy])

  // A new limit on an arrangement that is on changes it at once, its card and day kept.
  const setLimit = useCallback(async (limit: string): Promise<Note | null> => {
    const autopay = wallet.autopay
    if (!autopay?.on) return null
    const stop = guard()
    if (stop) return stop
    const card = autopay.card?.id ?? wallet.payWith?.id
    if (card === undefined || card === null) return { title: 'AutoPay', lines: ['AutoPay has no saved card to pay with. Save one first.'] }
    const answer = await store({ card: Number(card), limit, day: autopay.day ?? undefined })
    wallet.refetch()
    return 'error' in answer && answer.error ? { title: 'AutoPay', lines: [refusalOf(answer.error, 'the limit was not changed')] } : null
  }, [guard, wallet, store])

  return { turnOn, turnOff, setLimit, busy: stored.isLoading || destroyed.isLoading }
}

/**
 * Payments & AutoPay — board 40, as it is true (M50 §4.2 #3).
 *
 *   SAVED PAYMENT METHODS  the resident's saved test cards, the default first ("Default"), each opening Make default
 *                          and Remove card in the screen's sheet; "Add a card" opens board 35.
 *   AUTOPAY                the household's own arrangement: "AUTOPAY IS ON" with its limit, its day, its card and the
 *                          next day it pays — or OFF with the terms it would start on. "Edit limit" opens the limits;
 *                          "Turn off" / "Turn on" do so. What its last run did is said under it.
 *   PAYMENT HISTORY        `payments.index`, read as the screen opens and again whenever "View all receipts" opens it.
 */
export function usePaymentsAndAutopay(): PaymentsModel {
  const at = useClock()
  const paymentsQ = residentApi.usePaymentsIndexQuery(undefined, { skip: isFidelity })
  const payments = useStamped('dues.payments', paymentsQ)
  const wallet = useWallet()
  const switches = useAutopaySwitch(wallet)
  const [makeDefault] = residentApi.useCardsDefaultMutation()
  const [removeCard] = residentApi.useCardsDestroyMutation()
  const online = useAppSelector((s) => s.network.online)
  const [refreshing, setRefreshing] = useState(false)
  const [open, setOpen] = useState(false)
  const [note, setNote] = useState<Note | null>(null)
  const [choosing, setChoosing] = useState(false)
  // A limit chosen while AutoPay is off is the one it starts on.
  const [pendingLimit, setPendingLimit] = useState<string | null>(null)

  const refetch = paymentsQ.refetch
  const refresh = useCallback(() => {
    if (isFidelity) return
    setRefreshing(true)
    wallet.refetch()
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, wallet])

  const toggle = useCallback(() => {
    if (isFidelity) return
    if (!open) void refetch()
    setOpen(!open)
  }, [open, refetch])

  const said = (next: Note | null) => {
    if (next) setNote(next)
  }

  /** A saved card's options: make it the default, or remove it — each online only, each the server's to do. */
  const cardOptions = (card: SavedCard) =>
    setNote({
      title: card.label,
      lines: [card.expired ? `This card expired ${card.expires}. It cannot pay.` : `Saved card · expires ${card.expires}${card.default ? ' · your default' : ''}`],
      actions: [
        ...(card.default || card.expired ? [] : [{ label: 'Make default', onPress: () => void (online ? makeDefault({ card: card.id }).then(wallet.refetch) : setNote({ title: card.label, lines: [ONLINE_ONLY] })) }]),
        { label: 'Remove card', onPress: () => void (online ? removeCard({ card: card.id }).then(wallet.refetch) : setNote({ title: card.label, lines: [ONLINE_ONLY] })) },
      ],
    })

  const autopay = wallet.autopay
  const on = autopay?.on === true
  const limit = (on ? autopay?.limit : null) ?? pendingLimit ?? autopay?.defaults?.limit ?? null
  const day = (on ? autopay?.day : null) ?? autopay?.defaults?.day ?? null
  const using = (on ? autopay?.card?.label : null) ?? wallet.payWith?.label ?? 'No saved card'

  const failure = apiError(paymentsQ.error)
  return {
    // The fidelity harness: the design's own content (fidelity/dues.ts).
    methods: isFidelity
      ? [{ key: 'board', ...autopayFixture.method, onPress: null }]
      : [...wallet.cards].sort((a, b) => Number(b.default) - Number(a.default)).map((card) => ({
          key: String(card.id),
          title: card.label,
          sub: card.expired ? `Expired ${card.expires}` : `Expires ${card.expires}`,
          chip: card.default ? 'Default' : null,
          onPress: () => cardOptions(card),
        })),
    addCard: () => router.push('/dues/add-card' as never),
    autopay: isFidelity
      ? { on: true, heading: autopayFixture.heading, terms: autopayFixture.lines.map((line) => ({ label: line.label, value: line.value })), lastRun: null, editLimit: () => undefined, second: { label: 'Turn off', onPress: () => undefined }, busy: false }
      : {
          on,
          heading: on ? 'AUTOPAY IS ON' : wallet.available ? 'AUTOPAY IS OFF' : 'AUTOPAY IS NOT AVAILABLE',
          terms: [
            { label: 'Charges up to', value: limit ? moneyCompact(limit) : null },
            { label: 'On', value: day ? `The ${ordinal(day)} of each month` : null },
            { label: 'Using', value: using },
            { label: 'Next charge', value: on && autopay?.next_charge_on ? boardDate(autopay.next_charge_on) : '—' },
          ],
          lastRun: autopay?.last_outcome ?? (wallet.available ? null : (autopay?.reason ?? null)),
          editLimit: () => setChoosing(true),
          second: on
            ? { label: 'Turn off', onPress: () => void switches.turnOff().then(said) }
            : { label: 'Turn on', onPress: () => void switches.turnOn(pendingLimit ?? undefined).then(said) },
          busy: switches.busy,
        },
    limits: {
      open: choosing,
      choices: AUTOPAY_LIMITS.map((value) => ({
        key: value,
        label: moneyCompact(value),
        on: limit !== null && moneySign(limit) > 0 && Number(limit) === Number(value),
        pick: () => {
          setChoosing(false)
          if (on) void switches.setLimit(value).then(said)
          else setPendingLimit(value)
        },
      })),
      close: () => setChoosing(false),
    },
    receipts: { open, toggle },
    history: payments ? paymentRows(payments.value.items) : [],
    historyState: payments ? 'ready' : isDenied(paymentsQ.error) ? 'denied' : failure ? 'error' : 'loading',
    historyError: failure ? sentence(failure, 'Your payments') : null,
    openReceipt: (id) => router.push(`/dues/success?payment=${encodeURIComponent(id)}` as never),
    note,
    clearNote: () => setNote(null),
    refreshing,
    refresh,
  }
}

/** "$15,000" — the board's short form: cents only when there are some. */
const moneyCompact = (amount: string): string => money(amount, 'compact')

/**
 * Amenity bookings — boards resident-app-21 · -22 · -31 · -37. A MONEY HARD-STOP AREA.
 *
 *   THE LIST (21)     `amenities.index`, cached ("Serve the cache"). `may_book: false` keeps the list on
 *                     screen, calls no amenity "Available", and a card pressed says in one plain sentence
 *                     that bookings are paused. The app does not say WHY a household is paused — only the
 *                     server may, in its own words, when it refuses. "Available today" is read from the
 *                     diary the server sends (`taken`, M21 §1.5) against the estate's clock.
 *   THE BOOKING (22)  ONLINE ONLY — "a slot is first come"; never `submit()`, never the outbox, never retried. With the
 *                     demonstration card on (M47 §10, "confirmed on payment"): the server quotes the hours chosen
 *                     (`bookings.quote` — "2 hrs × $2,000", "Total due today $9,000"), "Confirm & pay $9,000" opens the
 *                     card checkout, and `bookings.book` books, pays and confirms in one act. With it off, a booking
 *                     is the REQUEST it was (`bookings.store`): "Send booking request", $0 due today, the estate decides.
 *                     A refusal is the estate's sentence, word for word, said under the button — never a system dialog.
 *   THE ANSWER (31)   what the server said. `pending` reads "Requested — waiting for the estate to
 *                     confirm"; the word "confirmed" — and the board's tick, "You're all set" and
 *                     "CONFIRMATION CODE" — is used only once the booking is confirmed. The gate QR is the
 *                     guests' signed pass (`gate_token`), only while the event is still to come.
 *   MY BOOKINGS (37)  `bookings.index`, cached. Cancelling (`bookings.cancel`) is offered only while
 *                     now < `cancellable_until`, asks first, and is sent ONLINE ONLY: a cancellation
 *                     that sat in a queue past its own deadline would be refused hours after the
 *                     resident was told it was done.
 *
 * MONEY: the fee and the deposit are the server's decimal strings, formatted by `money()` and shown
 * side by side. Nothing is added, multiplied or totalled. The one payment is the card checkout's
 * demonstration payment with a test card (M12 §2.6, §2.14; `payParts`), online only, never queued.
 *
 * What the resident asked for travels to the answer screen in memory; the URL carries only the
 * booking's id.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { fidelityScreenId, flags, isFidelity, now, useConfirmFlow, useOfflineCache, type IconName } from '@gs/core'
import { isApiError, type ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { money, siteNoon } from '@gs/core/lib'
import { amenitiesFixture, bookingsFixture, checkoutFixture, confirmedFixture } from '@/fidelity/amenities'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { useAppSelector } from '@/store'
import {
  DEPOSIT_NOTE,
  DUE_TODAY,
  HOW_PAID,
  PAUSED,
  cancelStateOf,
  checkoutRows,
  dayLong,
  dayOptions,
  dayShort,
  depositLineWithDemo as depositLine,
  depositNoteOf,
  describeLine,
  feeLine,
  heroLine,
  payParts,
  type PayPart,
  fieldErrorsOf,
  findById,
  freeOn,
  hoursOf,
  hoursShort,
  iconFor,
  isClosed,
  isUpcoming,
  needsAttention,
  pickFree,
  priceOf,
  quoteRows,
  rulesOf,
  rulesTailOf,
  siteDay,
  slotPassed,
  slotTaken,
  slotsFor,
  splitBookings,
  standingOf,
  termsRows,
  timePlain,
  timeRange,
  toRequest,
  validateRequest,
  whenLine,
  type Amenity,
  type Booking,
  type CancelState,
  type DayOption,
  type PriceRow,
  type Range,
  type Slot,
  type Tone,
} from './amenities'
import type { CalendarEvent } from './calendar'

const DENIED = "Your account is not linked to a home on the estate's register yet. Once the estate approves your claim, you can book its amenities here."
const OFFLINE_REQUEST = 'No connection. A booking can only be asked for while you are connected: a slot is first come, so a request is never saved to send later.'
const OFFLINE_CANCEL = 'No connection. A booking can only be cancelled while you are connected: nothing is saved to send later.'
const NO_HOURS = 'The estate has not set opening hours for this amenity, so it cannot be booked from the app. Speak to the estate office.'
const NO_ANSWER = 'No answer came back, so this phone cannot tell whether the estate received your request. Look in My bookings before you send it again.'

/**
 * How a saved copy is introduced. It is on screen either because the phone is offline or because the
 * estate's server could not be reached just now — "No connection" is said only when that is the reason.
 */
const staleLeadOf = (online: boolean): string => (online ? 'This could not be refreshed just now' : 'No connection')

const apiError = (e: unknown): ApiError | null => (isApiError(e) ? e : null)
const noSignal = (e: ApiError | null) => e?.status === 'network' || e?.status === 'timeout'

/** The clock deadlines are read against; frozen by the fidelity harness. */
function useClock(everyMs = 30_000): Date {
  const [tick, setTick] = useState(0)
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setTick((n) => n + 1), everyMs)
    return () => clearInterval(timer)
  }, [everyMs])
  return useMemo(() => now(), [tick]) // eslint-disable-line react-hooks/exhaustive-deps
}

type Status = 'loading' | 'ready' | 'error' | 'denied'

/* ---- what the phone holds ---------------------------------------------------------------------------- */

function useAmenityIndex(skip: boolean) {
  const online = useAppSelector((s) => s.network.online)
  const query = residentApi.useAmenitiesIndexQuery(undefined, { skip })
  const cached = useOfflineCache('amenities.index', query.data)
  const [refreshing, setRefreshing] = useState(false)

  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, skip])

  const e = apiError(query.error)
  const denied = e?.status === 403
  const status: Status = cached ? 'ready' : denied ? 'denied' : query.error ? 'error' : 'loading'
  return {
    online,
    status,
    errorMessage: denied ? DENIED : noSignal(e) ? 'The amenities could not be loaded: there is no connection, and nothing is saved on this phone yet.' : (e?.message ?? null),
    mayBook: cached?.may_book ?? false,
    // M47 §10: a booking is paid for in the app, and confirmed by it — the server's word and the app's flag both.
    cardPayments: flags.testCardCheckout && (cached as { card_payments?: boolean } | undefined)?.card_payments === true,
    items: (cached?.items ?? []) as readonly Amenity[],
    stale: !!cached && (!online || !!query.error),
    staleLead: staleLeadOf(online),
    refreshing,
    refresh,
  }
}

function useBookingIndex(skip: boolean) {
  const online = useAppSelector((s) => s.network.online)
  const query = residentApi.useBookingsIndexQuery(undefined, { skip })
  const cached = useOfflineCache('bookings.index', query.data)
  const [refreshing, setRefreshing] = useState(false)

  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, skip])

  const e = apiError(query.error)
  const denied = e?.status === 403
  const status: Status = cached ? 'ready' : denied ? 'denied' : query.error ? 'error' : 'loading'
  return {
    online,
    status,
    errorMessage: denied ? DENIED : noSignal(e) ? 'Your bookings could not be loaded: there is no connection, and nothing is saved on this phone yet.' : (e?.message ?? null),
    items: (cached?.items ?? []) as readonly Booking[],
    stale: !!cached && (!online || !!query.error),
    staleLead: staleLeadOf(online),
    refreshing,
    refresh,
  }
}

/**
 * How many of the household's bookings need it (M12 §2.14): the badge on Home's "Book amenity" tile and on
 * "My bookings". From the same cached `bookings.index` as My bookings, so the two never disagree. `skip` where the
 * person cannot book (the tile is not drawn) and under the fidelity harness.
 */
export function useBookingAttention(skip: boolean): number {
  const list = useBookingIndex(skip)
  const at = useClock()
  return useMemo(() => (skip ? 0 : list.items.filter((b) => needsAttention(b, at)).length), [skip, list.items, at])
}

/** "1 booking needs you" — the badge's words for a screen reader. */
export const attentionWords = (n: number): string => `${n} booking${n === 1 ? ' needs' : 's need'} you: something to pay`

/* ---- 21 · the list ----------------------------------------------------------------------------------- */

/** The board's two filter chips: every amenity, or only those with an hour still free today. "My bookings" is a link. */
export type AmenityFilter = 'all' | 'today'

export type AmenityCard = {
  id: string
  icon: IconName
  name: string
  /** "Up to 30 guests · garden setting" — how many it holds, and what it is (M47 §8.2). */
  detail: string
  /** "$2,000/hr · $5,000 deposit" — by the hour, as the estate charges; "$3,000 fee · …" for a place with one flat fee. */
  price: string
  /** The board's "Available": this household may book it, and it has opening hours to book from. */
  available: boolean
  /** "Available today": an hour today that has not begun and is not booked or closed, inside its booking window. */
  today: boolean
  label: string
}

export type AmenityListModel = {
  status: Status
  errorMessage: string | null
  /** False while the estate has paused this household's bookings. The list still shows; a card says so when pressed. */
  mayBook: boolean
  pausedLine: string
  filter: AmenityFilter
  setFilter: (filter: AmenityFilter) => void
  /** The cards the chosen filter keeps. */
  cards: AmenityCard[]
  refreshing: boolean
  refresh: () => void
}

export function useAmenityList(): AmenityListModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-21' ? amenitiesFixture : null
  const index = useAmenityIndex(isFidelity)
  const at = useClock()
  const [filter, setFilter] = useState<AmenityFilter>('all')

  const all = useMemo<AmenityCard[]>(() => {
    // The board's four places, each drawn "Available" — on its "All" chip, as drawn.
    if (fixture) return fixture.cards.map((card) => ({ id: card.id, icon: card.icon, name: card.name, detail: card.detail, price: card.price, available: true, today: true, label: `${card.name}. ${card.detail}. ${card.price}. Available.` }))
    const today = siteDay(at)
    return index.items.map((a) => {
      const detail = describeLine(a)
      const price = priceOf(a)
      const available = index.mayBook && slotsFor(a).length > 0
      const label = [a.name, detail, price, available ? 'Available' : null].filter(Boolean).join('. ')
      return { id: String(a.id), icon: iconFor(a.icon_key), name: a.name, detail, price, available, today: available && freeOn(a, today, at), label: `${label}.` }
    })
  }, [fixture, index.items, index.mayBook, at])

  return {
    status: fixture ? 'ready' : index.status,
    errorMessage: index.errorMessage,
    mayBook: fixture ? true : index.mayBook,
    pausedLine: PAUSED,
    filter,
    setFilter,
    cards: filter === 'today' ? all.filter((card) => card.today) : all,
    refreshing: index.refreshing,
    refresh: index.refresh,
  }
}

/* ---- 22 · the request -------------------------------------------------------------------------------- */

/**
 * The server's answer to the last request, handed to the next screen in memory — WITH the account it was
 * given to. Module memory outlives a sign-out (storage is wiped; this is not), so on a shared phone the
 * next person to sign in must never be shown the last household's booking.
 */
let lastAnswer: { account: number; booking: Booking } | null = null
const answerFor = (account: number | null): Booking | null => (lastAnswer !== null && account !== null && lastAnswer.account === account ? lastAnswer.booking : null)

/** `taken`: booked, or closed by the estate (M21 §1.5) — it cannot be asked for, like an hour that has passed. */
export type SlotView = Slot & { passed: boolean; taken: boolean; picked: boolean }

/** One block of the board's slot grid as drawn: struck out (`off`) once it has begun, or is booked or closed. */
export type SlotCell = { key: string; index: number; label: string; said: string; picked: boolean; off: boolean }

/**
 * Why the button could not go on, said UNDER it in the screen's own words (M47 U7: no system dialog). `openBookings`
 * when no answer came back: the request may have reached the estate, and My bookings is where to look first.
 */
export type NotSent = { text: string; openBookings: boolean }

/** What "Confirm & pay" hands the card checkout: the hours, the server's total, and what it is for, in words. */
export type PayFor = { amenity: string; startsAt: string; endsAt: string; total: string; what: string; rules: boolean }

export type RequestModel = {
  status: Status | 'missing'
  errorMessage: string | null
  name: string
  icon: IconName
  /** The hero's line: "Up to 30 guests · garden setting · Phase 2". */
  meta: string
  days: DayOption[]
  day: string
  /** "Saturday, 20 September" */
  dayLabel: string
  setDay: (iso: string) => void
  /** The day's blocks — two hours each for the board's places — from the opening hours and the diary the server sent. */
  slots: SlotCell[]
  pick: (index: number) => void
  /**
   * The rules row (M47 §9.6) — "I've read and accept the Gazebo house rules, including …" — drawn when the estate has
   * house rules for the place; null when it has none, and then nothing is asked. `lines` are what the gold link opens.
   */
  rules: { title: string; tail: string; lines: string[] } | null
  /** Ticked, as the board draws it (M47 §9.6); the booking waits for it. */
  rulesAccepted: boolean
  toggleRules: () => void
  /** The summary card — the server's quote for the hours chosen, or the rate and the deposit before any are — and its note. */
  rows: PriceRow[]
  total: PriceRow
  note: string
  /** The button's words — what pressing it does: "Confirm & pay $9,000", or "Send booking request". */
  button: string
  busy: boolean
  /** What stopped the last press, said under the button; null when nothing did. */
  problem: NotSent | null
  /** Presses the button: on to the card checkout, or the request sent — or `problem` says why not. */
  send: () => Promise<void>
  refreshing: boolean
  refresh: () => void
}

const OFFLINE_PAY = 'No connection. A booking is paid for while you are connected: a slot is first come, and a payment is never saved to send later.'
const QUOTE_WAIT = 'The total is still being worked out: press again in a moment.'
const QUOTE_FAILED = 'The total could not be worked out just now, so nothing can be paid. Check your connection and choose the time again.'

export function useBookingRequest(id: string | undefined, onSent: (bookingId: string) => void, onPay: (pay: PayFor) => void): RequestModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-22' ? checkoutFixture : null
  const index = useAmenityIndex(isFidelity)
  const at = useClock()
  const account = useAppSelector((s) => s.session.principal?.id ?? null)
  const refusal = useDemoRefusal()
  const [store] = residentApi.useBookingsStoreMutation()

  const amenity = useMemo(() => findById(id, index.items), [id, index.items])
  const days = useMemo(() => dayOptions(at, amenity?.booking_window_days ?? null), [at, amenity?.booking_window_days])

  const [day, setDayState] = useState<string>(() => siteDay(now(), 1))
  const [range, setRange] = useState<Range | null>(null)
  // Ticked, as the board draws it (M47 §9.6) — the resident can untick it, and the booking then waits for it.
  const [rulesAccepted, setRulesAccepted] = useState(true)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<NotSent | null>(null)
  const sending = useRef(false)
  // M53 §2: an amenity whose bookings wait for the office is a REQUEST even with card payments on — the server
  // refuses to take payment for it, and its fee is charged when the office approves. Unsaid (an older server): as before.
  const cardOn = index.cardPayments && amenity?.requires_approval !== true

  // The hours chosen, as the server takes them — and, to pay for them, the server's quote (nothing is added up here).
  const hours = amenity && range ? hoursOf({ day, range }, amenity) : null
  const quote = residentApi.useBookingsQuoteQuery(amenity && hours ? { amenity: amenity.id, ...hours } : { amenity: 0, starts_at: '', ends_at: '' }, { skip: isFidelity || !cardOn || !amenity || !hours })

  // The day on screen is always one the estate takes bookings for: "tomorrow" is the start, but an amenity
  // with a same-day window has no tomorrow, and a form left open past midnight has lost its first day.
  useEffect(() => {
    if (amenity && days.length > 0 && !days.some((d) => d.iso === day)) setDayState(days[Math.min(1, days.length - 1)]!.iso)
  }, [amenity, days, day])

  const slots = useMemo<SlotView[]>(
    () =>
      amenity
        ? slotsFor(amenity).map((slot) => ({
            ...slot,
            passed: slotPassed(day, slot, at),
            taken: slotTaken(day, slot, amenity.taken),
            picked: range !== null && slot.index >= range.start && slot.index <= range.end,
          }))
        : [],
    [amenity, day, at, range],
  )
  // A stretch never includes an hour that cannot be had (M21 §1.5); a change of day can leave one inside it.
  useEffect(() => {
    if (range && slots.slice(range.start, range.end + 1).some((slot) => slot.passed || slot.taken)) setRange(null)
  }, [range, slots])

  const first = range ? slots[range.start] : undefined
  const last = range ? slots[range.end] : undefined
  const rules = amenity && rulesOf(amenity).length > 0 ? { title: `${amenity.name} house rules`, tail: rulesTailOf(amenity), lines: rulesOf(amenity) } : null

  const send = useCallback(async (): Promise<void> => {
    if (isFidelity || sending.current || !amenity) return
    const say = (text: string, openBookings = false) => setProblem({ text, openBookings })
    setProblem(null)
    // No readable opening hours → no blocks to choose → a booking could never pass its own check.
    if (slotsFor(amenity).length === 0) return say(NO_HOURS)
    const input = { day, range }
    const found = validateRequest(input, amenity, now())
    const when = found.day ?? found.time
    if (when) return say(when)
    if (rules && !rulesAccepted) return say(`Tick the box to accept the ${amenity.name} house rules.`)
    // ONLINE ONLY: nothing is captured for later, so there is nothing to send when the connection returns.
    if (!index.online) return say(cardOn ? OFFLINE_PAY : OFFLINE_REQUEST)
    if (!index.mayBook) return say(`${PAUSED}.`)
    // The slot is picked and the form is validated — the feature has been shown — and only the
    // booking itself is refused, in the demonstration's own words rather than the token's.
    if (refusal) return say(refusal)

    // M47 §10: paid for in the app, and confirmed by it — on to the card, with the server's own total.
    if (cardOn) {
      const at = hoursOf(input, amenity)
      if (!at || !quote.data) return say(quote.isError ? QUOTE_FAILED : QUOTE_WAIT)
      onPay({ amenity: String(amenity.id), startsAt: at.starts_at, endsAt: at.ends_at, total: quote.data.total, what: `${amenity.name} · ${dayLong(at.starts_at)} · ${timePlain(at.starts_at, at.ends_at)}`, rules: rules !== null && rulesAccepted })
      return
    }

    const body = toRequest(amenity.id, input, amenity)
    if (!body) return say('This amenity cannot be booked from the app. Speak to the estate office.')
    sending.current = true
    setBusy(true)
    try {
      const answer = await store(body)
      if ('error' in answer && answer.error) {
        const e = apiError(answer.error)
        const fields = e?.status === 422 ? fieldErrorsOf(e.fieldErrors) : {}
        if (fields.time) return say(fields.time)
        // The estate's own sentence — outside hours, over capacity, the slot is taken, bookings closed to the household.
        if (e?.code === 'booking_refused') return say(e.message)
        if (e?.status === 404) return say('The estate no longer offers this amenity for booking. Go back and choose another.')
        if (e?.status === 403) return say(DENIED)
        if (e === null || noSignal(e)) return say(NO_ANSWER, true)
        return say(e.message, typeof e.status === 'number' && e.status >= 500)
      }
      if (!answer.data) return say(NO_ANSWER, true)
      lastAnswer = account === null ? null : { account, booking: answer.data as Booking }
      onSent(String(answer.data.id))
    } finally {
      sending.current = false
      setBusy(false)
    }
  }, [amenity, day, range, rules, rulesAccepted, index.online, index.mayBook, cardOn, quote.data, quote.isError, store, onSent, onPay, account, refusal])

  // The board's six blocks in fidelity; live, the day's own, struck out once begun, booked or closed.
  const cells: SlotCell[] = fixture
    ? fixture.slots.map((slot, i) => ({ key: String(i), index: i, label: slot.label, said: slot.state === 'taken' ? `${slot.label}, already booked` : slot.label, picked: slot.state === 'picked', off: slot.state === 'taken' }))
    : slots.map((slot) => {
        const off = slot.passed || slot.taken
        return { key: `${day}-${slot.index}`, index: slot.index, label: slot.label, said: slot.passed ? `${slot.spoken}, already passed` : slot.taken ? `${slot.spoken}, already booked` : slot.spoken, picked: slot.picked && !off, off }
      })

  // The summary: the server's quote for the hours chosen; before any, the rate and the deposit. A request (card payments
  // off) states the fee and the deposit, and $0 due today — it is paid once the estate approves.
  const summary = fixture
    ? { rows: fixture.rows.map((row) => ({ ...row })), total: { ...fixture.total } }
    : !amenity
      ? { rows: [], total: DUE_TODAY }
      : !cardOn
        ? { rows: checkoutRows(amenity.name, amenity.booking_fee, amenity.deposit, first && last ? hoursShort(first.from, last.to) : null), total: DUE_TODAY }
        : quote.data && hours
          ? quoteRows(amenity.name, quote.data)
          : { ...termsRows(amenity), total: { label: 'Total due today', value: hours && quote.isFetching ? '…' : '—' } }

  return {
    status: fixture ? 'ready' : amenity ? 'ready' : index.status === 'ready' ? 'missing' : index.status,
    errorMessage: index.errorMessage,
    name: fixture ? fixture.name : (amenity?.name ?? 'Book an amenity'),
    icon: fixture ? fixture.icon : iconFor(amenity?.icon_key),
    meta: fixture ? fixture.sub : amenity ? heroLine(amenity) : '',
    days,
    day,
    dayLabel: fixture ? fixture.day : (days.find((d) => d.iso === day)?.long ?? dayLong(siteNoon(day))),
    setDay: (iso) => {
      setProblem(null)
      setDayState(iso)
    },
    slots: cells,
    pick: (slotIndex) => {
      if (fixture) return
      setProblem(null)
      setRange((current) =>
        pickFree(current, slotIndex, (i) => {
          const slot = slots[i]
          return slot !== undefined && (slot.passed || slot.taken)
        }),
      )
    },
    rules: fixture ? { title: fixture.rules.link, tail: fixture.rules.tail, lines: ['Noise cut-off at 9:00 PM.', 'No glassware on the lawn.'] } : rules,
    rulesAccepted,
    toggleRules: () => {
      setProblem(null)
      setRulesAccepted((on) => !on)
    },
    rows: summary.rows,
    total: summary.total,
    note: DEPOSIT_NOTE,
    button: fixture ? fixture.button : !cardOn ? 'Send booking request' : quote.data && hours ? `Confirm & pay ${money(quote.data.total, 'compact')}` : 'Confirm & pay',
    busy,
    problem,
    send,
    refreshing: index.refreshing,
    refresh: index.refresh,
  }
}

/**
 * The booking the card checkout just made (`bookings.book`), handed to "Booking confirmed" in memory until the list
 * reloads — with the account it was made for, as a request's answer is.
 */
export function rememberBooking(account: number | null, booking: Booking): void {
  lastAnswer = account === null ? null : { account, booking }
}

/* ---- 31 · the server's answer ------------------------------------------------------------------------ */

export type AnswerRow = { icon: IconName; text: string }

/** The mark at the top: the board's gold tick only once the estate has said yes. A request is a clock, never a tick. */
export type AnswerMark = 'tick' | 'clock' | 'declined' | 'cancelled'

export type AnswerModel = {
  status: Status | 'missing'
  errorMessage: string | null
  mark: AnswerMark
  /** "Booking confirmed" only for the status `confirmed`; "Booking requested" while the estate decides. */
  title: string
  /** "You're all set for the Gazebo" once confirmed; "Requested — waiting for the estate to confirm" before. */
  line: string
  /** The place, the day, the hours — "Gazebo" · "Saturday, 20 September" · "11:00 AM – 1:00 PM". */
  rows: AnswerRow[]
  /** "CONFIRMATION CODE" once confirmed; before, the reference is called what it is. */
  codeLabel: string
  /** "GZ-2609-A4" for a booking paid for in the app (M47 §10); the server's reference for one the estate approved. */
  code: string
  /** The guests' signed pass for the gate (`gate_token`) while the event is still to come — null for a booking with none. */
  qr: string | null
  /** The board's deposit note — with the card, for a deposit paid by one in the app — or its state once it has moved on. */
  depositNote: string | null
  /** "Add to calendar": the confirmed booking as an event; null for one that is not confirmed, or is over. */
  calendar: CalendarEvent | null
  /** Pull down to ask the estate again: a request is decided by a person, some time after it was sent. */
  refreshing: boolean
  refresh: () => void
}

export function useBookingAnswer(id: string | undefined): AnswerModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-31' ? confirmedFixture : null
  const list = useBookingIndex(isFidelity)
  const amenities = useAmenityIndex(isFidelity)
  const at = useClock()

  // The list is the server's latest word (the estate may have decided since); the answer in memory covers the moment before it reloads.
  const account = useAppSelector((s) => s.session.principal?.id ?? null)
  const held = answerFor(account)
  const wanted = id ?? (held ? String(held.id) : undefined)
  const booking: Booking | null = fixture ? null : (findById(wanted, list.items) ?? (held && String(held.id) === wanted ? held : null))

  const standing = booking ? standingOf(booking, at) : null
  // The estate's yes — confirmed, or confirmed and since taken place — is what earns the tick and the board's words.
  const settled = booking?.status === 'confirmed' || booking?.status === 'completed'
  // An expired request (M53 §2) is over, not waiting: the stopped mark a cancelled booking wears, never the clock.
  const mark: AnswerMark = settled ? 'tick' : booking?.status === 'declined' ? 'declined' : booking?.status === 'cancelled' || booking?.status === 'expired' ? 'cancelled' : 'clock'
  const place = booking ? findById(String(booking.amenity.id), amenities.items) : null
  const icon = booking ? iconFor(place?.icon_key) : 'pm-icon-2'
  const code = booking ? (booking.confirmation_code ?? booking.reference) : ''
  const estate = useAppSelector((s) => s.session.principal?.siteName ?? '')
  const calendar: CalendarEvent | null =
    booking && booking.status === 'confirmed' && isUpcoming(booking, at)
      ? {
          title: `${booking.amenity.name} booking`,
          startsAt: booking.starts_at,
          endsAt: booking.ends_at,
          location: [booking.amenity.name, place?.place, estate].filter(Boolean).join(', '),
          notes: `Confirmation code ${code}.${booking.gate_token ? ' Show the QR in the app at the gate on the day.' : ''}`,
          uid: `${code}@geminisecure`,
        }
      : null

  return {
    status: fixture || booking ? 'ready' : list.status === 'ready' ? 'missing' : list.status,
    errorMessage: list.errorMessage,
    mark: fixture ? 'tick' : mark,
    title: fixture ? fixture.title : (standing?.title ?? ''),
    line: fixture ? fixture.summary : booking?.status === 'confirmed' ? `You're all set for the ${booking.amenity.name}` : (standing?.line ?? ''),
    rows: fixture
      ? fixture.rows.map((row) => ({ ...row }))
      : booking
        ? [
            { icon, text: booking.amenity.name },
            { icon: 'input', text: dayLong(booking.starts_at) },
            { icon: 'recap-item', text: timePlain(booking.starts_at, booking.ends_at) },
          ]
        : [],
    codeLabel: fixture ? fixture.codeLabel : settled ? 'CONFIRMATION CODE' : 'BOOKING REFERENCE',
    code: fixture ? fixture.code : code,
    // Only a pass the gate accepts: a booking's reference in a QR was one no guard's scanner takes.
    qr: fixture ? fixture.code : booking && isUpcoming(booking, at) && booking.gate_token ? booking.gate_token : null,
    depositNote: fixture ? fixture.note : booking ? depositNoteOf(booking) : null,
    // The board's own booking in fidelity mode, so its two buttons are drawn as the board draws them.
    calendar: fixture ? { title: 'Gazebo booking', startsAt: '2026-09-20T16:00:00Z', endsAt: '2026-09-20T18:00:00Z', location: 'Gazebo', notes: `Confirmation code ${fixture.code}.`, uid: `${fixture.code}@geminisecure` } : calendar,
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}

/* ---- 37 · my bookings -------------------------------------------------------------------------------- */

export type BookingRow = {
  id: string
  icon: IconName
  name: string
  sub: string
  /** Lines under the row: the pending sentence, the deposit's state, the estate's reason for declining. */
  extra: string[]
  pill: { word: string; tone: Tone }
  cancel: CancelState | null
  /** The fee or the deposit, payable in the app now with a test card (M12 §2.14) — empty when nothing is. */
  pay: PayPart[]
  label: string
  /**
   * "the Gazebo on Thu, 1 Oct · 11:00 AM – 2:00 PM" — WHICH booking, in full. A household can hold two
   * bookings of the same amenity; the cancel control and its "are you sure?" must never leave it to the
   * row's position to say which one is meant (a screen reader hears the label, not the position).
   */
  which: string
}

export type BookingsTab = 'upcoming' | 'past'

export type BookingsModel = {
  status: Status
  errorMessage: string | null
  online: boolean
  tab: BookingsTab
  setTab: (tab: BookingsTab) => void
  upcoming: BookingRow[]
  past: BookingRow[]
  /** The booking the ConfirmSheet is asking "cancel it?" about, as its row is drawn — or null. */
  asking: BookingRow | null
  askCancel: (id: string) => void
  /** "Keep the booking": the question closes; whatever the estate said stays on that row (`cancelNote`). */
  keep: () => void
  confirmCancel: () => void
  cancelWorking: boolean
  /** The estate's refusal, or why it could not be asked — INSIDE the sheet. */
  cancelProblem: string | null
  /** What became of a cancel whose question was closed without it going through — drawn on its row. */
  cancelNote: { id: string; text: string } | null
  offlineCancelLine: string
  stale: boolean
  /** "No connection" or "This could not be refreshed just now" - why a saved copy is on screen. */
  staleLead: string
  refreshing: boolean
  refresh: () => void
  /** True in fidelity mode only: the board draws both lists at once. */
  board: boolean
}

export function useMyBookings(): BookingsModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-37' ? bookingsFixture : null
  const list = useBookingIndex(isFidelity)
  const amenities = useAmenityIndex(isFidelity)
  const at = useClock()
  const refusal = useDemoRefusal()
  const [cancelBooking] = residentApi.useBookingsCancelMutation()

  const [tab, setTab] = useState<BookingsTab>('upcoming')
  const [cancelNote, setCancelNote] = useState<BookingsModel['cancelNote']>(null)
  // The server's answer to a cancellation, shown at once — the list reloads behind it.
  const [answers, setAnswers] = useState<Record<string, Booking>>({})

  const items = useMemo(() => list.items.map((b) => answers[String(b.id)] ?? b), [list.items, answers])

  const toRow = useCallback(
    (b: Booking, upcoming: boolean): BookingRow => {
      const standing = standingOf(b, at)
      // Once a booking is in the past, a deposit that never arrived is no longer being waited for: "not received yet" would read as a debt.
      const deposit = upcoming || b.deposit_state !== 'awaiting' ? depositLine(b) : null
      const sub = upcoming ? whenLine(b) : [dayShort(b.starts_at), deposit ?? timeRange(b.starts_at, b.ends_at)].join(' · ')
      const fee = feeLine(b)
      const pay = upcoming ? payParts(b, flags.testCardCheckout) : []
      const extra = [
        // A request says it is waiting (or, once its time has passed or it has expired, that it was never decided); a refusal gives the estate's reason.
        b.status === 'pending' || b.status === 'declined' || b.status === 'expired' ? standing.line : null,
        // Whether each part is paid (M12 §2.14), and — while one is still to pay — how.
        upcoming && fee ? `${fee}.` : null,
        upcoming ? (deposit ? `${deposit}.` : null) : null,
        upcoming && (fee || deposit) && pay.length === 0 && !isClosed(b) && b.fee_paid !== true ? `${HOW_PAID}.` : null,
      ].filter((line): line is string => line !== null)
      return {
        id: String(b.id),
        icon: iconFor(findById(String(b.amenity.id), amenities.items)?.icon_key),
        name: b.amenity.name,
        sub,
        extra,
        pill: { word: standing.word, tone: standing.tone },
        cancel: upcoming ? cancelStateOf(b, at) : null,
        pay,
        label: `${b.amenity.name}, ${standing.word}, ${whenLine(b)}`,
        which: `the ${b.amenity.name} on ${whenLine(b)}`,
      }
    },
    [amenities.items, at],
  )

  const groups = useMemo(() => {
    if (fixture) {
      const draw = (row: (typeof fixture.upcoming)[number]): BookingRow => ({ id: row.id, icon: row.icon, name: row.name, sub: row.sub, extra: [], pill: { word: row.word, tone: row.tone }, cancel: null, pay: [], label: row.name, which: row.name })
      return { upcoming: fixture.upcoming.map(draw), past: fixture.past.map(draw) }
    }
    const split = splitBookings(items, at)
    return { upcoming: split.upcoming.map((b) => toRow(b, true)), past: split.past.map((b) => toRow(b, false)) }
  }, [fixture, items, at, toRow])

  // Asked in a ConfirmSheet, sent ONCE, and the estate's refusal stays in the sheet; it closes only once cancelled.
  const cancelling = useConfirmFlow<string>({
    send: async (id) => {
      if (isFidelity) return { ok: false, message: 'This booking can no longer be cancelled in the app.' }
      const booking = findById(id, items)
      if (!booking) return { ok: false, message: 'This booking is no longer on this phone. Pull down to reload your bookings.' }
      // ONLINE ONLY, and only inside the server's own deadline — checked again at the moment of the press.
      if (!list.online) return { ok: false, message: OFFLINE_CANCEL }
      if (refusal) return { ok: false, message: refusal }
      const state = cancelStateOf(booking, now())
      if (!state.can) return { ok: false, message: state.reason ?? 'This booking can no longer be cancelled in the app.' }

      const answer = await cancelBooking({ booking: booking.id })
      if ('error' in answer && answer.error) {
        const e = apiError(answer.error)
        return {
          ok: false,
          message:
            e === null || noSignal(e)
              ? 'No answer came back, so this phone cannot tell whether the booking was cancelled. Pull down to reload your bookings and check.'
              : e.status === 404
                ? 'The estate has no booking of your household’s with that number. Pull down to reload your bookings.'
                : e.message, // 409 `cancellation_refused`: the estate's own sentence
        }
      }
      if (answer.data) {
        const cancelled: Booking = answer.data
        setAnswers((all) => ({ ...all, [id]: cancelled }))
      }
      return { ok: true }
    },
    failed: 'That did not finish, so this phone cannot tell whether the booking was cancelled. Pull down to reload your bookings and check.',
  })
  const askedId = cancelling.open ? cancelling.target : null
  // Looked for in both lists: a booking that crossed into the past while it was asked about keeps its question,
  // and the press is then refused with the reason (`cancelStateOf`).
  const asking = askedId ? ([...groups.upcoming, ...groups.past].find((row) => row.id === askedId) ?? null) : null

  return {
    status: fixture ? 'ready' : list.status,
    errorMessage: list.errorMessage,
    online: list.online,
    tab,
    setTab,
    upcoming: groups.upcoming,
    past: groups.past,
    asking,
    askCancel: (id) => {
      setCancelNote(null)
      cancelling.ask(id)
    },
    keep: () => {
      // Closed without it going through: what the estate said is not lost with the question.
      if (!cancelling.working && cancelling.problem && cancelling.target) setCancelNote({ id: cancelling.target, text: cancelling.problem })
      cancelling.cancel()
    },
    confirmCancel: cancelling.confirm,
    cancelWorking: cancelling.working,
    cancelProblem: cancelling.problem,
    cancelNote,
    offlineCancelLine: OFFLINE_CANCEL,
    stale: !fixture && list.stale,
    staleLead: list.staleLead,
    refreshing: list.refreshing,
    refresh: () => {
      setAnswers({})
      setCancelNote(null)
      list.refresh()
    },
    board: fixture !== null,
  }
}

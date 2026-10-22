/**
 * PAY WORKS, AND IS HONEST (M42 §5 #9 and E6; M50 §4.2 #3, §6.6) — the hook, with the server mocked:
 *   - "Pay full balance — $12,400" and "Pay $12,400.00" are the household's balance, the Dues screen's own figure, and
 *     the line under it counts the overdue months the server's charges show;
 *   - whether a card can be used is ONE answer — `cards.index`'s `available` — never a button's own guess: with cards
 *     on, the resident's saved test cards come first (the default chosen), or the demonstration card with none saved;
 *     with cards off, Bank transfer and Cash agent alone;
 *   - with a card, Pay sends ONE `payments.card` — that test card, the amount shown — and opens Payment successful on the
 *     estate's receipt; refused, it says the server's sentence and opens nothing; two fast taps record one payment; a
 *     read-only demonstration, or no connection, sends nothing;
 *   - Bank transfer and Cash agent show the estate's own instructions and the reference to quote — never a dead end,
 *     and nothing is sent;
 *   - AutoPay turns on with the default card, stays on, and turns off; with no saved card it asks for one first;
 *   - nothing owed is "settled": there is nothing to pay;
 *   - every one of those words is in the screen's own sheet (`note`) — never the phone's grey dialog.
 */

import { act, renderHook } from '@testing-library/react-native'
import { Alert } from 'react-native'
import { router } from 'expo-router'
import type { Api } from '@gs/core/api'
import { usePay } from '../useDues'

jest.mock('expo-router', () => ({ router: { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: () => true }, useFocusEffect: jest.fn() }))
jest.mock('@gs/core', () => {
  const lib = jest.requireActual('@gs/core/lib')
  // No disk cache between tests: what the hook shows is what the mocked server said in THIS test.
  return { getStorage: lib.getStorage, jsonStore: lib.jsonStore, useOfflineCache: (_key: string, data: unknown) => data, isFidelity: false, now: () => new Date('2026-09-04T14:41:00Z') }
})

type Failure = { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null }
type Answer = { data?: unknown; error?: Failure }
const answer = (data: unknown, error?: Failure) => ({ data, error, fulfilledTimeStamp: Date.parse('2026-09-04T14:41:00Z'), refetch: jest.fn(async () => undefined) })

const VISA = { id: 11, brand: 'visa', brand_name: 'Visa', last4: '4242', label: 'Visa •••• 4242', expires: '08/30', holder: 'Andrea Fletcher', default: true, expired: false, demonstration: true }
const MASTERCARD = { ...VISA, id: 12, brand: 'mastercard', brand_name: 'Mastercard', last4: '4444', label: 'Mastercard •••• 4444', default: false }
const OLD = { ...VISA, id: 13, last4: '5556', label: 'Visa •••• 5556', expires: '01/20', default: false, expired: true }
const AUTOPAY_OFF = { available: true, reason: null, on: false, id: null, limit: null, day: null, card: null, next_charge_on: null, last_run_on: null, last_outcome: null, defaults: { limit: '15000.00', day: 28 } }

let mockBalance = answer(undefined)
let mockInvoices = answer(undefined)
let mockCards = answer({ available: false, items: [] })
let mockAutopay = answer({ ...AUTOPAY_OFF, available: false })
const mockHousehold = answer({ household: { id: 7 } })
const mockStore = jest.fn(async (_arg: unknown): Promise<Answer> => ({ data: { ...AUTOPAY_OFF, on: true, id: 5 } }))
const mockDestroy = jest.fn(async (_arg: unknown): Promise<Answer> => ({ data: AUTOPAY_OFF }))
const INTENT = { instructions: 'Pay by bank transfer to NCB account 123-456-789, Phoenix Park Village 1.', quote_reference: 'Lot 47 · DUES-2026-08-L47', amount_due: '6200.00' }
let mockIntent: Answer = { data: INTENT }
const mockAskIntent = jest.fn(async (_arg: unknown): Promise<Answer> => mockIntent)

/** Every way money could move — each call is written down. Pay may call `payments.card` and nothing else. */
const mockMoney: { name: string; arg: unknown }[] = []
let mockCardAnswer: Answer = { data: { id: 501, receipt_no: 'PPV-R-04472', amount: '12400.00', reference: 'Demonstration card payment · Visa •••• 4242', balance: '0.00' } }
const mockPayCard = jest.fn(async (arg: unknown): Promise<Answer> => {
  mockMoney.push({ name: 'payments.card', arg })
  return mockCardAnswer
})
const mockSpend = (name: string) => [jest.fn(async (arg: unknown) => (mockMoney.push({ name, arg }), {}))]
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useMeHouseholdQuery: () => mockHousehold,
    useHouseholdsBalanceQuery: () => mockBalance,
    useInvoicesIndexQuery: () => mockInvoices,
    useCardsIndexQuery: () => mockCards,
    useAutopayShowQuery: () => mockAutopay,
    useAutopayStoreMutation: () => [mockStore, { isLoading: false }],
    useAutopayDestroyMutation: () => [mockDestroy, { isLoading: false }],
    useInvoicesPayIntentMutation: () => [mockAskIntent],
    usePaymentsCardMutation: () => [mockPayCard],
    usePaymentsIntentsStoreMutation: () => mockSpend('payments.intents.store'),
    useBookingsPayCardMutation: () => mockSpend('bookings.pay_card'),
    useBookingsBookMutation: () => mockSpend('bookings.book'),
    util: { invalidateTags: (tags: string[]) => ({ type: 'invalidate', tags }) },
  },
}))
const mockState = { network: { online: true }, session: { demo: false, principal: { id: 41 } } }
const mockDispatch = jest.fn()
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState), useAppDispatch: () => mockDispatch }))
let mockRefusal: string | null = null
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => mockRefusal }))
// The phone's own biometric check before a payment above $5,000 (M50 §3.3) answers yes here: its own tests are elsewhere.
const mockConfirm: string | null = null
jest.mock('@/features/settings/paymentsLock', () => ({ ...jest.requireActual('@/features/settings/paymentsLock'), confirmPayment: async () => mockConfirm }))

const balance = (over: Partial<Api.HouseholdsBalanceResponse> = {}): Api.HouseholdsBalanceResponse => ({
  household_id: 7, unit: 'Lot 47', balance: '12400.00', currency: 'JMD', as_at: '2026-09-04', oldest_unpaid_due_on: '2026-07-01', days_overdue: 65, on_payment_plan: false, plan: null, pay_now: { amount: '12400.00', due_on: '2026-07-01', reason: 'overdue' }, access_restricted: false, ...over,
})
type Charge = Api.InvoicesIndexResponse['items'][number]
const august: Charge = { id: 2, reference: 'DUES-2026-08-L47', type: 'dues', period: '2026-08', description: 'Maintenance fee: August', amount: '6200.00', outstanding: '6200.00', due_on: '2026-08-01', status: 'overdue' }
const july: Charge = { ...august, id: 1, reference: 'DUES-2026-07-L47', period: '2026-07', description: 'Maintenance fee: July', due_on: '2026-07-01' }

const cardsOn = (items: unknown[] = []) => {
  mockCards = answer({ available: true, items })
  mockAutopay = answer(AUTOPAY_OFF)
}

/** Lets the hook's promise chains (the server's answers, then the sheet or the navigation) run out. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))
const rendered = async () => {
  const hook = await renderHook(() => usePay())
  await act(async () => {
    await settle()
  })
  return hook
}
const press = async (fn: () => void) => {
  await act(async () => {
    fn()
    await settle()
  })
}

let alert: jest.SpyInstance
beforeEach(() => {
  alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)
  mockBalance = answer(balance())
  mockInvoices = answer({ currency: 'JMD', items: [august, july] })
  mockCards = answer({ available: false, items: [] })
  mockAutopay = answer({ ...AUTOPAY_OFF, available: false })
  mockIntent = { data: INTENT }
  mockCardAnswer = { data: { id: 501, receipt_no: 'PPV-R-04472', amount: '12400.00', reference: 'Demonstration card payment · Visa •••• 4242', balance: '0.00' } }
  for (const mock of [mockAskIntent, mockPayCard, mockStore, mockDestroy, mockDispatch]) mock.mockClear()
  mockMoney.length = 0
  mockRefusal = null
  mockState.network.online = true
  jest.mocked(router.push).mockClear()
  jest.mocked(router.replace).mockClear()
})
// Never the phone's grey dialog, in any test (M50 §4.3).
afterEach(() => {
  expect(alert).not.toHaveBeenCalled()
  alert.mockRestore()
})

describe("the amounts are the household's balance: the Dues screen's own figure", () => {
  it('"Pay full balance: $12,400", "Clears both overdue months", "Pay $12,400.00"', async () => {
    const { result } = await rendered()
    expect(result.current.status).toBe('ready')
    expect(result.current.full).toEqual({ title: 'Pay full balance: $12,400', sub: 'Clears both overdue months' })
    expect(result.current.payLabel).toBe('Pay $12,400.00')
  })

  it('another balance is another button, and the line says what is true of it', async () => {
    mockBalance = answer(balance({ balance: '6200.50' }))
    mockInvoices = answer({ currency: 'JMD', items: [{ ...august, outstanding: '6200.50' }, { ...july, outstanding: '0.00', status: 'paid' }] })
    const { result } = await rendered()
    expect(result.current.full).toEqual({ title: 'Pay full balance: $6,200.50', sub: 'Clears 1 overdue month' })
    expect(result.current.payLabel).toBe('Pay $6,200.50')
  })

  it('until the charges have answered, the line says only what the full balance always does', async () => {
    mockInvoices = answer(undefined)
    const { result } = await rendered()
    expect(result.current.full.sub).toBe('Clears your balance')
  })

  it('a part-payment keeps an amount, and the button says it', async () => {
    const { result } = await rendered()
    await act(async () => result.current.choose('part'))
    expect(result.current.payLabel).toBe('Pay')
    await act(async () => result.current.setPart('2,500.759'))
    expect(result.current.part).toBe('2500.75')
    expect(result.current.payLabel).toBe('Pay $2,500.75')
    await act(async () => result.current.choose('full'))
    expect(result.current.payLabel).toBe('Pay $12,400.00')
  })

  it('nothing owed, or a credit, is settled: there is nothing to pay', async () => {
    for (const owed of ['0.00', '-350.50']) {
      mockBalance = answer(balance({ balance: owed, days_overdue: 0 }))
      const { result } = await rendered()
      expect(result.current.status).toBe('settled')
    }
  })

  it('a household the server will not show is denied; one it could not load is an error', async () => {
    mockBalance = answer(undefined, { status: 403, code: 'forbidden', message: 'Not your household.', fieldErrors: {}, retryAfter: null })
    expect((await rendered()).result.current.status).toBe('denied')
    mockBalance = answer(undefined, { status: 500, code: 'server_error', message: 'The server could not answer.', fieldErrors: {}, retryAfter: null })
    const { result } = await rendered()
    expect(result.current.status).toBe('error')
    expect(result.current.errorMessage).toBe('The server could not answer.')
  })
})

describe('the methods: one answer decides whether a card can be used', () => {
  it('cards off: Bank transfer and Cash agent alone, Bank transfer chosen', async () => {
    const { result } = await rendered()
    expect(result.current.methods.map((m) => m.title)).toEqual(['Bank transfer', 'Cash agent'])
    expect(result.current.method).toBe('bank')
    await act(async () => result.current.chooseMethod('cash'))
    expect(result.current.method).toBe('cash')
  })

  it('cards on with none saved: the demonstration card first and chosen, and its own line says what it is', async () => {
    cardsOn()
    const { result } = await rendered()
    expect(result.current.methods).toEqual([
      { key: 'card', title: 'Visa •••• 4242', sub: 'Demonstration card · no money moves' },
      { key: 'bank', title: 'Bank transfer', sub: 'NCB, Scotiabank, JMMB & more' },
      { key: 'cash', title: 'Cash agent', sub: 'Pay in person, reference at checkout' },
    ])
    expect(result.current.method).toBe('card')
  })

  it('cards on with saved cards: theirs, the default first and chosen; an expired one is not offered', async () => {
    cardsOn([MASTERCARD, OLD, VISA])
    const { result } = await rendered()
    expect(result.current.methods.map((m) => [m.key, m.title, m.sub])).toEqual([
      ['card:11', 'Visa •••• 4242', 'Saved card · expires 08/30'],
      ['card:12', 'Mastercard •••• 4444', 'Saved card · expires 08/30'],
      ['bank', 'Bank transfer', 'NCB, Scotiabank, JMMB & more'],
      ['cash', 'Cash agent', 'Pay in person, reference at checkout'],
    ])
    expect(result.current.method).toBe('card:11')
  })

  it('"Add new card" opens the app\'s own Add a card screen', async () => {
    const { result } = await rendered()
    await act(async () => result.current.addCard())
    expect(router.push).toHaveBeenCalledWith('/dues/add-card')
  })
})

describe('Pay with a card', () => {
  beforeEach(() => cardsOn([VISA, MASTERCARD]))

  it('records ONE demonstration payment, the card chosen, the amount shown, and opens Payment successful on its receipt', async () => {
    const { result } = await rendered()
    await press(() => result.current.chooseMethod('card:12'))
    await press(() => result.current.pay())
    expect(mockMoney).toEqual([{ name: 'payments.card', arg: { amount: '12400.00', brand: 'mastercard', last4: '4444' } }])
    // Replaced, not pushed: Back from the receipt is Dues.
    expect(router.replace).toHaveBeenCalledWith({ pathname: '/dues/success', params: { payment: '501' } })
    expect(result.current.note).toBeNull()
    // Every read that shows money is asked again.
    expect(mockDispatch).toHaveBeenCalledWith({ type: 'invalidate', tags: ['dues', 'payments', 'bookings'] })
  })

  it('the demonstration card pays with Stripe\'s test Visa', async () => {
    cardsOn()
    const { result } = await rendered()
    await press(() => result.current.pay())
    expect(mockMoney).toEqual([{ name: 'payments.card', arg: { amount: '12400.00', brand: 'visa', last4: '4242' } }])
  })

  it('a part-payment pays the amount typed; none typed is said in the sheet, and nothing is sent', async () => {
    const { result } = await rendered()
    await act(async () => result.current.choose('part'))
    await press(() => result.current.pay())
    expect(result.current.note?.lines).toEqual(['Choose an amount to pay.'])
    expect(mockMoney).toEqual([])
    await act(async () => result.current.clearNote())
    await act(async () => result.current.setPart('5000'))
    await press(() => result.current.pay())
    expect(mockMoney).toEqual([{ name: 'payments.card', arg: { amount: '5000', brand: 'visa', last4: '4242' } }])
  })

  it('two fast taps record one payment', async () => {
    const { result } = await rendered()
    await press(() => {
      result.current.pay()
      result.current.pay()
    })
    expect(mockPayCard).toHaveBeenCalledTimes(1)
  })

  it('refused, it says the server\'s own sentence, and opens nothing', async () => {
    mockCardAnswer = { error: { status: 409, code: 'payment_refused', message: 'That is more than is owed. The most you can pay now is $12,400.00.', fieldErrors: {}, retryAfter: null } }
    const { result } = await rendered()
    await press(() => result.current.pay())
    expect(result.current.note?.lines).toEqual(['That is more than is owed. The most you can pay now is $12,400.00.'])
    expect(router.replace).not.toHaveBeenCalled()
  })

  it('a read-only demonstration, or no connection, sends nothing and says why', async () => {
    mockRefusal = 'You are exploring a demonstration community, so nothing here can be changed.'
    const first = (await rendered()).result
    await press(() => first.current.pay())
    expect(first.current.note?.lines).toEqual([mockRefusal])
    mockRefusal = null
    mockState.network.online = false
    const second = (await rendered()).result
    await press(() => second.current.pay())
    expect(second.current.note?.lines[0]).toMatch(/^There is no connection, so the payment was not sent and nothing was charged/)
    expect(mockMoney).toEqual([])
    expect(router.replace).not.toHaveBeenCalled()
  })
})

describe('Bank transfer and Cash agent: how to pay, never a dead end', () => {
  it('shows the estate\'s own instructions and the reference to quote, and sends no payment', async () => {
    const { result } = await rendered()
    for (const method of ['bank', 'cash'] as const) {
      await act(async () => result.current.chooseMethod(method))
      await press(() => result.current.pay())
      expect(result.current.note).toEqual({ title: method === 'bank' ? 'Pay by bank transfer' : 'Pay a cash agent', lines: [INTENT.instructions, 'Quote this reference: Lot 47 · DUES-2026-08-L47'] })
      await act(async () => result.current.clearNote())
    }
    expect(mockAskIntent).toHaveBeenCalledWith({ invoice: 2 })
    expect(mockMoney).toEqual([])
    expect(router.replace).not.toHaveBeenCalled()
  })

  it('with no connection, says where the details are instead of asking', async () => {
    mockState.network.online = false
    const { result } = await rendered()
    await press(() => result.current.pay())
    expect(mockAskIntent).not.toHaveBeenCalled()
    expect(result.current.note?.lines[0]).toMatch(/no connection/)
  })
})

describe('AutoPay: on, it stays on; off, it goes', () => {
  it('turns on with the default card, from the server\'s own terms', async () => {
    cardsOn([MASTERCARD, VISA])
    const { result } = await rendered()
    expect(result.current.autopay).toMatchObject({ on: false, line: 'Automatically pay up to $15,000 on the 28th each month.' })
    await press(() => result.current.autopay.toggle())
    expect(mockStore).toHaveBeenCalledWith({ card: 11 })
    expect(result.current.note).toBeNull()
    expect(mockMoney).toEqual([])
  })

  it('on, it draws its own terms, and turns off', async () => {
    cardsOn([VISA])
    mockAutopay = answer({ ...AUTOPAY_OFF, on: true, id: 5, limit: '20000.00', day: 5, card: VISA, next_charge_on: '2026-10-05' })
    const { result } = await rendered()
    expect(result.current.autopay).toMatchObject({ on: true, line: 'Automatically pay up to $20,000 on the 5th each month.' })
    await press(() => result.current.autopay.toggle())
    expect(mockDestroy).toHaveBeenCalledWith({ autopay: 5 })
  })

  it('with no saved card, asks for one first, with the way to Add a card, and asks the server nothing', async () => {
    cardsOn()
    const { result } = await rendered()
    await press(() => result.current.autopay.toggle())
    expect(mockStore).not.toHaveBeenCalled()
    expect(result.current.note?.title).toBe('Save a card first')
    result.current.note?.actions?.[0]?.onPress()
    expect(router.push).toHaveBeenCalledWith('/dues/add-card')
  })

  it('with cards off, says why in the server\'s words; with no connection or in a demonstration, nothing is asked', async () => {
    mockAutopay = answer({ ...AUTOPAY_OFF, available: false, reason: 'AutoPay needs card payments, which are not switched on for this estate.' })
    const off = (await rendered()).result
    await press(() => off.current.autopay.toggle())
    expect(off.current.note?.lines).toEqual(['AutoPay needs card payments, which are not switched on for this estate.'])

    cardsOn([VISA])
    mockState.network.online = false
    const offline = (await rendered()).result
    await press(() => offline.current.autopay.toggle())
    expect(offline.current.note?.lines[0]).toMatch(/needs a connection/)

    mockState.network.online = true
    mockRefusal = 'This is a demonstration community, so nothing here can be changed.'
    const demo = (await rendered()).result
    await press(() => demo.current.autopay.toggle())
    expect(demo.current.note?.lines).toEqual([mockRefusal])
    expect(mockStore).not.toHaveBeenCalled()
  })
})

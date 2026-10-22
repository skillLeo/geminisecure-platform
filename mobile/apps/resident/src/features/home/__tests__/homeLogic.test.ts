/**
 * Home's logic, held to the rules that matter on it:
 *   - money is SHOWN from the server's string, never computed, and a figure that is not from
 *     this minute says so;
 *   - "today" is today in JAMAICA, whatever time zone the phone thinks it is in;
 *   - the resident's own e-pass is not a visitor.
 */
import type { Api } from '@gs/core/api'
import { forgetSiteZone, setSiteZone } from '@gs/core/lib'
import { ago, balanceView, dayOfYear, expectedToday, greetingFor, headlineNotice, secondsLeft } from '../homeLogic'

const balance = (over: Partial<Api.HouseholdsBalanceResponse>): Api.HouseholdsBalanceResponse => ({
  household_id: 7, unit: 'Lot 47', balance: '12400.00', currency: 'JMD', as_at: '2026-09-04', oldest_unpaid_due_on: '2026-09-28', days_overdue: 0, on_payment_plan: false, plan: null, pay_now: { amount: '12400.00', due_on: '2026-09-28', reason: 'due' }, access_restricted: false, ...over,
})

describe('the balance card', () => {
  it('draws the board: "$12,400 outstanding · Due 28 Sep 2026 · Pay $12,400"', () => {
    // M18D §1: the button names the act and the sum; M98: "Due 28 Sep 2026" is a DATE, in the one form, and stays.
    // M57 §1.2: owing on time is the card's body ink (readable), never the board's amber-600 (2.2:1 on white).
    expect(balanceView(balance({}), true)).toMatchObject({ state: 'owing', amount: '$12,400 outstanding', detail: 'Due 28 Sep 2026', tone: 'slate-500', action: 'Pay $12,400', opens: 'pay', stale: null })
    // The server's word, never worked out from the days: a restricted household is told HERE, and sent to read why.
    expect(balanceView(balance({ access_restricted: true, days_overdue: 0 }), true)).toMatchObject({ detail: "Guests' passes are on hold: open to see why", tone: 'amber-700', action: 'See my dues', opens: 'dues' })
    expect(balanceView(balance({ access_restricted: false, days_overdue: 400 }), true)).toMatchObject({ action: 'Pay $12,400', opens: 'pay' })
  })

  it('says overdue in the darker amber, never red (M12 §4.4), and a payment plan being met in the card\'s body ink (M57 §1.2)', () => {
    expect(balanceView(balance({ days_overdue: 12 }), true)).toMatchObject({ state: 'overdue', detail: 'Overdue by 12 days', tone: 'amber-700' })
    expect(balanceView(balance({ days_overdue: 1 }), true).detail).toBe('Overdue by 1 day')
    expect(balanceView(balance({ days_overdue: 40, on_payment_plan: true, pay_now: null }), true)).toMatchObject({ detail: 'On a payment plan', tone: 'slate-500' })
  })

  it('on a plan, asks for the next instalment: the dues page\'s own words and button (M18D §5)', () => {
    const onPlan = balance({ balance: '20400.00', days_overdue: 26, on_payment_plan: true, pay_now: { amount: '3400.00', due_on: '2026-10-02', reason: 'instalment' } })
    expect(balanceView(onPlan, true)).toMatchObject({ amount: '$20,400 outstanding', detail: 'On a payment plan · next payment $3,400, due 2 Oct 2026', action: 'Pay $3,400', opens: 'pay' })
  })

  it('nothing owed and a credit are not debts', () => {
    expect(balanceView(balance({ balance: '0.00' }), true)).toMatchObject({ state: 'clear', amount: "You're all paid up", action: 'View' })
    expect(balanceView(balance({ balance: '-350.50' }), true)).toMatchObject({ state: 'credit', amount: '$350.50 in credit', action: 'View' })
  })

  it('a cached figure is never shown as current', () => {
    expect(balanceView(balance({}), false).stale).toBe("As at 4 Sep 2026: connect to see today's figure")
    expect(balanceView(balance({}), true).stale).toBeNull()
  })

  it('keeps cents the ledger sent', () => {
    expect(balanceView(balance({ balance: '6200.75' }), true).amount).toBe('$6,200.75 outstanding')
  })
})

describe('dates without a time zone', () => {
  it('reads a date as written, never through a Date, which would shift it by the phone’s offset, in the one form (M98)', () => {
    expect(dayOfYear('2026-09-28')).toBe('28 Sep 2026')
    expect(dayOfYear('2026-01-01')).toBe('1 Jan 2026')
    expect(dayOfYear(null)).toBeNull()
    expect(dayOfYear('soon')).toBeNull()
  })
})

describe('the greeting follows the clock in Jamaica', () => {
  it('morning / afternoon / evening at UTC−5', () => {
    expect(greetingFor(new Date('2026-09-04T14:41:00Z'), 'Andrea')).toBe('Good morning, Andrea') // 9:41 AM in Kingston
    expect(greetingFor(new Date('2026-09-04T18:00:00Z'), 'Andrea')).toBe('Good afternoon, Andrea') // 1:00 PM
    expect(greetingFor(new Date('2026-09-05T00:30:00Z'), 'Andrea')).toBe('Good evening, Andrea') // 7:30 PM on the 4th
    expect(greetingFor(new Date('2026-09-04T14:41:00Z'), '')).toBe('Good morning')
  })
})

const pass = (over: Partial<Api.VisitorPassesIndexResponse['items'][number]>): Api.VisitorPassesIndexResponse['items'][number] => ({
  id: 1, pass_id: 'uuid', category: 'single', visitor_name: 'Marcia James', visitor_phone: null, purpose: 'Family visit', vehicle_plate: null, no_vehicle: false,
  valid_from: '2026-09-04T14:00:00-05:00', valid_to: '2026-09-04T16:00:00-05:00', single_use: true, status: 'active', code: 'PPV2-4471', token: 'x.y', share_count: 0, used_at: null, cancelled_at: null, last_admitted_at: null,
  last_admitted_post: null, last_admitted_by: null, host: 'Andrea Fletcher', ...over,
})

describe('expected today', () => {
  const at = new Date('2026-09-04T09:41:00-05:00')

  it('draws the board’s rows: a short visit as its window, a long pass as when it starts', () => {
    const rows = expectedToday([
      pass({}),
      pass({ id: 2, category: 'contractor', visitor_name: 'TropiClean Pest Control', purpose: null, valid_from: '2026-09-04T10:00:00-05:00', valid_to: '2026-09-04T18:00:00-05:00' }),
    ], at)
    expect(rows).toEqual([
      { id: '2', initials: 'TP', name: 'TropiClean Pest Control', detail: 'Contractor', when: '10:00 AM' },
      { id: '1', initials: 'MJ', name: 'Marcia James', detail: 'Family visit', when: '2:00 – 4:00 PM' },
    ])
  })

  it('leaves out tomorrow’s visitor, a finished window, a cancelled pass, and the resident’s own e-pass', () => {
    const rows = expectedToday([
      pass({ id: 3, valid_from: '2026-09-05T09:00:00-05:00', valid_to: '2026-09-05T11:00:00-05:00' }),
      pass({ id: 4, valid_from: '2026-09-04T06:00:00-05:00', valid_to: '2026-09-04T08:00:00-05:00' }),
      pass({ id: 5, status: 'cancelled' }),
      pass({ id: 6, category: 'resident', visitor_name: 'Andrea Fletcher' }),
    ], at)
    expect(rows).toEqual([])
  })

  it('"today" is Jamaica’s today: 11 PM in Kingston is already tomorrow in UTC, and the pass still counts', () => {
    const late = new Date('2026-09-05T03:30:00Z') // 10:30 PM on the 4th in Kingston
    const rows = expectedToday([pass({ id: 7, valid_from: '2026-09-04T23:00:00-05:00', valid_to: '2026-09-04T23:45:00-05:00' })], late)
    expect(rows.map((r) => r.when)).toEqual(['11:00 – 11:45 PM'])
  })

  it('a multi-day pass that started earlier reads "All day"', () => {
    const rows = expectedToday([pass({ id: 8, category: 'recurring', purpose: null, valid_from: '2026-09-01T08:00:00-05:00', valid_to: '2026-09-30T18:00:00-05:00' })], at)
    expect(rows[0]).toMatchObject({ detail: 'Regular visitor', when: 'All day' })
  })
})

describe('the notice Home shows', () => {
  const notice = (over: Partial<Api.NoticesIndexResponse['items'][number]>): Api.NoticesIndexResponse['items'][number] => ({
    id: 1, kind: 'general', title: 'A', body: '', author_name: 'Sec', posted_as_role: 'Secretary', audience: 'Whole estate', published_at: '2026-09-04T07:00:00-05:00', read_at: null, seen_households: 0, audience_households: 0, attachments: [], ...over,
  })

  it('an unread URGENT notice beats a newer ordinary one', () => {
    const pick = headlineNotice([notice({ id: 1, published_at: '2026-09-04T09:00:00-05:00' }), notice({ id: 2, kind: 'urgent', published_at: '2026-09-03T09:00:00-05:00' })])
    expect(pick?.id).toBe(2)
  })

  it('then the newest unread, then simply the newest', () => {
    expect(headlineNotice([notice({ id: 1, read_at: '2026-09-04T08:00:00-05:00', published_at: '2026-09-04T09:00:00-05:00' }), notice({ id: 2, published_at: '2026-09-02T09:00:00-05:00' })])?.id).toBe(2)
    expect(headlineNotice([notice({ id: 1, read_at: 'x', published_at: '2026-09-01T09:00:00-05:00' }), notice({ id: 2, read_at: 'x', published_at: '2026-09-02T09:00:00-05:00' })])?.id).toBe(2)
    expect(headlineNotice([])).toBeNull()
  })

  it('says how long ago in words', () => {
    const at = new Date('2026-09-04T09:41:00-05:00')
    expect(ago('2026-09-04T07:41:00-05:00', at)).toBe('Posted 2 hours ago')
    expect(ago('2026-09-04T09:40:30-05:00', at)).toBe('Posted just now')
    expect(ago('2026-09-04T09:11:00-05:00', at)).toBe('Posted 30 minutes ago')
    expect(ago('2026-09-02T09:41:00-05:00', at)).toBe('Posted 2 days ago')
  })

  /**
   * ONCE IT IS OLD ENOUGH TO BE NAMED BY ITS DAY. A notice posted at 9 PM in Kingston is
   * already the NEXT day in UTC, in Tokyo, and on any handset east of Greenwich. The day this line
   * names is the ESTATE's day — it was read off `toISOString()`, which is UTC, and said "September 1"
   * for a notice the estate posted on the evening of August 31.
   */
  it("names the day at the ESTATE, not the day in UTC and not the day on the handset", () => {
    const at = new Date('2026-09-20T14:41:00Z')
    // 2 AM UTC = 9:00 PM the evening BEFORE in Kingston.
    expect(ago('2026-09-01T02:00:00Z', at)).toBe('Posted 31 Aug 2026')
    // Midday at the site is the same day everywhere, so this leg holds whatever is broken.
    expect(ago('2026-09-01T17:00:00Z', at)).toBe('Posted 1 Sep 2026')
  })

  it('follows the zone the SERVER sent, so a second territory reads its own calendar', () => {
    const at = new Date('2026-09-20T14:41:00Z')
    setSiteZone('Asia/Tokyo')
    // The same instant is already 11 AM on September 1 in Tokyo.
    expect(ago('2026-09-01T02:00:00Z', at)).toBe('Posted 1 Sep 2026')
    forgetSiteZone()
    expect(ago('2026-09-01T02:00:00Z', at)).toBe('Posted 31 Aug 2026')
  })
})

describe('a guard waiting at the gate', () => {
  it('counts down to the deadline and never below zero', () => {
    const at = new Date('2026-09-04T09:41:00-05:00')
    expect(secondsLeft('2026-09-04T09:43:30-05:00', at)).toBe(150)
    expect(secondsLeft('2026-09-04T09:40:00-05:00', at)).toBe(0)
  })
})

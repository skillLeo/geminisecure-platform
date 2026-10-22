/**
 * Boards guard-app-11 (Standing Orders) and guard-app-16 (Order History) — the boards'
 * own data, in the SERVER's shape (`orders.current`), so fidelity mode exercises the
 * same parsing and grouping a phone does. Used only behind `isFidelity`, which is a
 * build-time `false` on a phone; no screen ever falls back to it.
 */

import type { Api } from '@gs/core/api'
import { PHOENIX_PARK_ZONE, boardFence } from './fixtures'

type Shift = NonNullable<Api.ShiftsMeCurrentResponse['shift']>

export type OrdersFixture = {
  shift: Shift
  orders: Api.OrdersCurrentResponse
  /** Board 11 draws the acknowledgement live (amber button, empty box): the state once the guard has read to the end. */
  readToEnd: boolean
}

const boardShift: Shift = {
  fence: boardFence,
  supervisor_phone: null,
  id: 812,
  status: 'on_duty',
  post: { id: 1, name: 'Main Gate' },
  site: { id: 'phoenixpark', name: 'Phoenix Park Village 1', timezone: PHOENIX_PARK_ZONE },
  rostered_start: '2026-09-04T12:00:00Z',
  rostered_end: '2026-09-05T00:00:00Z',
  actual_start: '2026-09-04T12:24:00Z',
  actual_end: null,
  on_break: false,
  break_minutes: 0,
  previous_handover_note: null,
  orders_to_acknowledge: 1,
  colleagues: [],
}

/** The board's four headed sections, as one order set's text. */
const mainGateBody = [
  'General orders',
  '- Remain at your post unless relieved or authorised to leave by the Head of Security.',
  '- Full uniform and visible ID at all times while on duty.',
  '- No personal visitors at the gatehouse during your shift.',
  '',
  'Post-specific orders',
  "- Verify every vehicle's plate against the visitor pass before raising the barrier.",
  '- Deliveries after 8:00 PM require a call-through — no exceptions, even for known couriers.',
  '- Log every walk-up visitor, admitted or denied, without exception.',
  '',
  'Emergency procedures',
  '- Fire: sound the gatehouse alarm, contact Fire Brigade at 110, then notify dispatch.',
  '- Main water shut-off valve is behind the generator room, key on the gatehouse board.',
  '',
  'Key contacts',
  '- Head of Security — Owen Grant · (876) 555 0110',
  '- Dispatch — (876) 555 0100, available 24 hours',
].join('\n')

const mainGate = {
  set_id: 2,
  version_id: 31,
  version: '3',
  title: 'Phoenix Park Village 1 — Main Gate',
  effective_on: '2026-09-01',
  body: mainGateBody,
  requires_acknowledgement: true,
}

/**
 * Not a board: the SERVER's own text shapes (numbered lines with wrapped continuations, a
 * capitals-labelled annex, three order sets), not yet read. For flow checks of the
 * scroll-to-end gate — `/orders?fidelity=guard-app-11-unread`.
 */
const unread: OrdersFixture = {
  shift: boardShift,
  readToEnd: false,
  orders: {
    site_id: 'phoenixpark',
    history: [{ set_id: 2, title: 'Phoenix Park Village 1 — Main Gate', version: '2', acknowledged_at: '2026-08-01T11:58:00Z' }],
    items: [
      {
        ...mainGate,
        acknowledged_at: null,
        body: [
          '1. The barrier stays down between vehicles. No exceptions for a following car.',
          '2. Contractors are admitted on a pre-approved pass only, and are logged out.',
          '3. Phase 1 residents use the left lane; visitors use the right.',
          '4. Escalate a duress press immediately - do not attempt to verify it first.',
        ].join('\n'),
      },
      {
        set_id: 1,
        version_id: 12,
        version: '4',
        title: 'Company-wide General Orders',
        effective_on: '2026-01-05',
        requires_acknowledgement: false,
        acknowledged_at: null,
        body: [
          '1. Report for duty in full uniform, with your licence and your bound device.',
          '2. Verify every visitor against a pass or a resident call-through. Where neither',
          '   is available, apply the walk-up procedure and record the outcome.',
          '3. Never admit against a denied verdict without recording an override and the',
          '   reason for it.',
          '4. A resident is never refused entry to their own estate over money owed.',
          '5. Log every equipment fault at the time you find it, not at the end of shift.',
        ].join('\n'),
      },
      {
        set_id: 5,
        version_id: 19,
        version: '2',
        title: 'Emergency Procedures — Company-wide',
        effective_on: '2026-02-01',
        requires_acknowledgement: false,
        acknowledged_at: null,
        body: [
          'FIRE     Raise the alarm, open every barrier, do not attempt to fight it.',
          'MEDICAL  Call the ambulance first, then dispatch. Do not move the casualty.',
          'DURESS   Press and keep working normally. Dispatch will call you back on a',
          '         pretext; answering it clears the alert, silence escalates it.',
        ].join('\n'),
      },
    ],
  },
}

export const ordersFixtures: Record<string, OrdersFixture | undefined> = {
  'guard-app-11-unread': unread,
  /** Version 3 is in force and not yet acknowledged. */
  'guard-app-11': {
    shift: boardShift,
    readToEnd: true,
    orders: { site_id: 'phoenixpark', items: [{ ...mainGate, acknowledged_at: null }], history: [] },
  },
  /** The guard's proof of what they were instructed and when. */
  'guard-app-16': {
    shift: { ...boardShift, orders_to_acknowledge: 0 },
    readToEnd: false,
    orders: {
      site_id: 'phoenixpark',
      items: [{ ...mainGate, acknowledged_at: '2026-09-04T12:26:00Z' }],
      history: [
        { set_id: 2, title: 'Phoenix Park Village 1 — Main Gate', version: '3', acknowledged_at: '2026-09-04T12:26:00Z' },
        { set_id: 3, title: 'Phoenix Park Village 1 — Service Gate', version: '2', acknowledged_at: '2026-08-14T19:10:00Z' },
        { set_id: 2, title: 'Phoenix Park Village 1 — Main Gate', version: '2', acknowledged_at: '2026-08-01T11:58:00Z' },
        { set_id: 2, title: 'Phoenix Park Village 1 — Main Gate', version: '1', acknowledged_at: '2026-07-01T12:02:00Z' },
      ],
    },
  },
}

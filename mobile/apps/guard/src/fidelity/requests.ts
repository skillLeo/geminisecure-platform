/**
 * The request boards' own data (guard-app-09 equipment · 10 leave · 21 open shifts · 22 shift claimed),
 * served only in web fidelity mode. Every use sits behind `isFidelity`, a build-time `false` on a phone.
 *
 * What the boards draw that the server does not have lives here and nowhere else:
 *   10  three separate leave balances (vacation / sick / casual). The server keeps ONE yearly entitlement,
 *       so the live tiles are ENTITLEMENT · APPROVED THIS YEAR · REMAINING, and the leave types are the
 *       server's seven (there is no "casual").
 *   21  "+4h OT · 1.5×" — an overtime rate is pay, and a guard's handset shows hours, never pay. The rest
 *       warning's exact wording ("6 hours after your Sep 5 shift") — the server sends only a yes/no.
 *   22  a named approver and how fast he usually answers. The server names nobody.
 *   09  sizes ("Uniform — Large") and a "Fulfilled" status; the server's statuses are pending / approved /
 *       denied / more info needed.
 */

/** guard-app-10 · Leave & Duty */
export const leaveFixture = {
  balances: [
    { figure: '12', label: 'VACATION DAYS' },
    { figure: '6', label: 'SICK DAYS' },
    { figure: '3', label: 'CASUAL DAYS' },
  ],
  types: ['Vacation', 'Sick', 'Casual'],
  from: 'Sep 15',
  to: 'Sep 17',
  reason: 'Family function out of town',
} as const

/** guard-app-21 · Open Shifts */
export const openShiftsFixture = [
  { id: 'a', title: 'Service Gate — Static Post', site: 'Phoenix Park Village 1', ot: '+4h OT', rate: '1.5×', day: 'Tomorrow, Sep 5', window: '7:00 PM–7:00 AM', warn: null },
  { id: 'b', title: 'Main Gate — Static Post', site: 'Phoenix Park Village 1', ot: '+6h OT', rate: '1.5×', day: 'Sat, Sep 6', window: '1:00 PM–7:00 PM', warn: 'Starts 6 hours after your Sep 5 shift ends — check your rest period before claiming.' },
  { id: 'c', title: 'Patrol — Phase 2–5', site: 'Phoenix Park Village 1', ot: '+8h OT', rate: '1.5×', day: 'Sun, Sep 7', window: '11:00 PM–7:00 AM', warn: null },
] as const

/** guard-app-22 · Shift Claimed */
export const claimedFixture = {
  body: "You've claimed the Service Gate shift. It'll appear on your roster once approved.",
  post: 'Service Gate — Static Post',
  when: 'Tomorrow, Sep 5 · 7:00 PM–7:00 AM',
  note: "Owen Grant usually responds within a few hours. You'll get a message and a roster update either way.",
} as const

/** guard-app-09 · Equipment Request */
export const equipmentFixture = {
  selected: 'Torch',
  quantity: 1,
  reason: 'Current torch battery no longer holds charge for a full night shift',
  recent: [
    { key: 'u', icon: 'eq-item-4', title: 'Uniform — Large, ×2', detail: 'Requested Aug 28', chip: { label: 'Fulfilled', tone: 'navy-500' } },
    { key: 'r', icon: 'rh-icon', title: 'Radio — replacement', detail: 'Requested Sep 1', chip: { label: 'Pending', tone: 'amber-500' } },
  ],
} as const

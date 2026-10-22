/**
 * The amenity boards' own data (resident-app-21 the list · -22 checkout · -31 booking confirmed ·
 * -37 my bookings), served only in web fidelity mode. Every use sits behind `isFidelity`.
 *
 * These are DRAWINGS, in the boards' words. Since M47 §8–§10 the server says all of it for real — the hourly rate,
 * the description and place, two-hour blocks, house rules, a quote ("2 hrs × $2,000", "Total due today $9,000"),
 * "Confirm & pay" that books, pays and confirms at once, a confirmation code, the guests' gate QR and the card the
 * deposit goes back to — and the live screens draw it from the server. The boards' own sample (their day, places,
 * figures, the two selected blocks — which the board's "2 hrs" does not add up to — the code and the Visa) stays
 * here, so the measurement stays honest about the design.
 */

import type { IconName } from '@gs/core'

type BoardCard = { id: string; icon: IconName; name: string; detail: string; price: string; pill: string }

/** resident-app-21 · Book an Amenity */
export const amenitiesFixture = {
  chips: ['All', 'Available today', 'My bookings'],
  cards: [
    { id: '1', icon: 'pm-icon-2', name: 'Community Centre', detail: 'Up to 120 guests · kitchen & AV included', price: '$4,500/hr · $10,000 deposit', pill: 'Available' },
    { id: '2', icon: 'amenity-icon', name: 'Club House', detail: 'Up to 60 guests · lounge & bar area', price: '$3,000/hr · $6,000 deposit', pill: 'Available' },
    { id: '3', icon: 'amenity-icon-2', name: 'Gazebo', detail: 'Up to 30 guests · garden setting', price: '$2,000/hr · $5,000 deposit', pill: 'Available' },
    { id: '4', icon: 'amenity-icon-3', name: 'Pool Deck', detail: 'Up to 40 guests · shaded seating', price: '$2,500/hr · $5,000 deposit', pill: 'Available' },
  ] as BoardCard[],
} as const

type BoardSlot = { label: string; state: 'taken' | 'picked' | 'free' }

/** resident-app-22 · Gazebo Checkout */
export const checkoutFixture = {
  name: 'Gazebo',
  icon: 'amenity-icon-2' as IconName,
  sub: 'Up to 30 guests · garden setting · Phase 2',
  day: 'Saturday, 20 September',
  change: 'Change',
  slotsTitle: 'Available time slots',
  slots: [
    { label: '9–11 AM', state: 'taken' },
    { label: '11–1 PM', state: 'picked' },
    { label: '1–3 PM', state: 'picked' },
    { label: '3–5 PM', state: 'free' },
    { label: '5–7 PM', state: 'free' },
    { label: '7–9 PM', state: 'taken' },
  ] as BoardSlot[],
  rules: { lead: "I've read and accept the ", link: 'Gazebo house rules', tail: ', including noise cut-off at 9:00 PM and no glassware on the lawn.' },
  rows: [
    { label: 'Gazebo rate — 2 hrs × $2,000', value: '$4,000' },
    { label: 'Refundable damage deposit', value: '$5,000' },
  ],
  total: { label: 'Total due today', value: '$9,000' },
  depositNote: 'Deposit is refunded after a condition check within 3 business days of your event.',
  button: 'Confirm & pay $9,000',
} as const

/** resident-app-31 · Booking Confirmed */
export const confirmedFixture = {
  title: 'Booking confirmed',
  summary: "You're all set for the Gazebo",
  rows: [
    { icon: 'amenity-icon-2' as IconName, text: 'Gazebo' },
    { icon: 'input' as IconName, text: 'Saturday, 20 September' },
    { icon: 'recap-item' as IconName, text: '11:00 AM – 1:00 PM' },
  ],
  codeLabel: 'CONFIRMATION CODE',
  code: 'GZ-2609-A4',
  qrCaption: 'Show this at the gate on the day of your event',
  note: 'Your $5,000 deposit is refunded within 3 business days after a condition check, straight back to Visa •••• 4417.',
  secondary: 'Add to calendar',
  primary: 'Done',
} as const

type BoardRow = { id: string; icon: IconName; name: string; sub: string; word: string; tone: 'navy' | 'amber' }

/** resident-app-37 · My Bookings — the board draws BOTH lists under the "Upcoming" tab. */
export const bookingsFixture = {
  upcoming: [{ id: 'b1', icon: 'amenity-icon-2', name: 'Gazebo', sub: 'Sat, 20 Sep · 11:00 AM – 1:00 PM', word: 'Confirmed', tone: 'navy' }] as BoardRow[],
  past: [
    { id: 'b2', icon: 'amenity-icon', name: 'Club House', sub: 'Aug 15 · $6,000 deposit refunded Aug 18', word: 'Completed', tone: 'amber' },
    { id: 'b3', icon: 'pm-icon-2', name: 'Community Centre', sub: 'Jun 2 · $10,000 deposit refunded Jun 6', word: 'Completed', tone: 'amber' },
  ] as BoardRow[],
} as const

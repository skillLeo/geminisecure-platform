/**
 * The visitor boards' own data (resident-app-25 approval · -08 tracking · -07 invite), served
 * only in web fidelity mode. Every use sits behind `isFidelity`.
 *
 * Board 25 draws a vehicle description; board 08 draws who is "on site now" with gate and guard
 * names. The server gives the app a plate only, and does not tell a resident when a visitor LEAVES
 * or which guard admitted them — so those exist here and nowhere else. Board 25's photo panel holds
 * no photo, on the board or on a phone; its caption is the screen's words and `post`.
 */

export const approvalFixture = {
  unit: 'Lot 47',
  estate: 'Phoenix Park',
  // The ring is drawn at 264 − 66 of 264 = 75%; the clock reads 01:24.
  secondsLeft: 84,
  ringFraction: 0.75,
  visitor: 'Kevin Chambers',
  purpose: 'Visiting for a family lunch',
  vehicle: 'white Toyota Axio, PC 8821',
  guard: 'Marcus Whyte',
  post: 'Main Gate',
} as const

export const trackingFixture = {
  onSite: [
    { id: 't1', initials: 'MJ', name: 'Marcia James', detail: 'Arrived 2:14 PM · Guest of Andrea Fletcher', chip: '45 min' },
    { id: 't2', initials: 'TC', name: 'TropiClean Pest Control', detail: 'Arrived 10:02 AM · Contractor pass', chip: '3h 12m' },
  ],
  activity: [
    { id: 'a1', entered: true, name: 'Marcia James', detail: 'Entered · Main Gate · by Guard Whyte', time: '2:14 PM' },
    { id: 'a2', entered: false, name: 'Devon Grant', detail: 'Exited · Main Gate · by Guard Palmer', time: '11:48 AM' },
    { id: 'a3', entered: true, name: 'TropiClean Pest Control', detail: 'Entered · Service Gate · by Guard Whyte', time: '10:02 AM' },
    { id: 'a4', entered: true, name: 'Devon Grant', detail: 'Entered · Main Gate · by Guard Palmer', time: '9:20 AM' },
  ],
} as const

/*
 * Board 07 is drawn mid-form. The hook puts the form in that state — the window 2:00 to 4:00 PM on the board's day — and
 * the screen's own labels then read the board's "Today" and "2:00–4:00 PM" (`day` and `window` are what they must read).
 * `arrival` is not on the board: M42 §5 #6 adds that dropdown, and Walk in keeps the plate field hidden, as the board
 * has none. `hint` is the board's footer word for word — "Marcia", "she" — where a phone names the visitor typed.
 */
export const inviteFixture = {
  category: 'single',
  name: 'Marcia James',
  phone: '876 555 0148',
  purpose: 'Family visit',
  arrival: 'walk',
  fromMinutes: 14 * 60,
  toMinutes: 16 * 60,
  day: 'Today',
  window: '2:00–4:00 PM',
  hint: 'Marcia will get this pass by SMS and WhatsApp automatically — she just shows it at the gate, no phone call needed.',
} as const

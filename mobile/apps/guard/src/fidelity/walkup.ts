/**
 * The walk-up boards' own data (guard-app-31 the form · guard-app-06 waiting on the resident ·
 * guard-app-24 today's activity), served only in web fidelity mode. Every use sits behind
 * `isFidelity`, a build-time `false` on a phone.
 *
 * What the boards draw that the server does not have lives here and nowhere else:
 *   31  "Tap to capture visitor photo" — no photo of a walk-up visitor is stored (the live tile says so).
 *   06  a resident's first name and pronoun, a vehicle make and colour, and a ring (75%) that does not
 *       agree with its own clock (01:24 of three minutes) — reproduced by a 112-second window, 84 left.
 *   24  a WALK-UPS count (the server counts admitted · exited · overridden · denied) and a post-only header.
 */

import type { ActivityRow, CountTile } from '@/features/walkup/walkup'

/** guard-app-31 · Walk-up Visitor Entry */
export const walkUpFormFixture = {
  unit: 'Phase 2 · Lot 47',
  household: 'Fletcher household',
  photoLabel: 'Tap to capture visitor photo',
  /** The board draws this field focused (amber border). */
  focused: 'visitor_name',
  idNumberPlaceholder: 'DL-88213',
  values: { unit: 'Phase 2 · Lot 47', visitor_name: 'Kevin Chambers', id_type: "Driver's licence", id_number: '', purpose: 'Family lunch', vehicle_plate: 'PC 8821' },
} as const

/** guard-app-06 · Waiting on Resident — the board's moment is 09:41:00 at the site (BOARD_NOW_ISO). */
export const waitingFixture = {
  approval: {
    approval_id: 3107,
    status: 'pending' as const,
    unit: 'Lot 47',
    household: 'Andrea Fletcher',
    visitor_name: 'Kevin Chambers',
    requested_at: '2026-09-04T14:40:32Z',
    respond_by: '2026-09-04T14:42:24Z',
    responded_at: null,
    responded_by_name: null,
    guidance: 'Waiting on the household.',
  },
  purpose: 'Family lunch',
  vehicle: 'White Toyota Axio · PC 8821',
  title: 'Waiting for Andrea Fletcher',
  body: "She'll get a push notification with your details.\nAuto-holds at the gate if there's no response.",
  standingNote: "If Andrea doesn't respond, Phoenix Park's standing order for unannounced visitors applies automatically.",
}

const row = (key: string, initials: string, title: string, sub: string, time: string, result: ActivityRow['result'] = 'admit'): ActivityRow => ({
  key, initials, title, sub, time, result, resultLabel: result === 'deny' ? 'Denied' : 'Admitted', note: null, queued: false,
})

/** guard-app-24 · Gate Activity */
export const gateActivityFixture: { countsLabel: string; listLabel: string; tiles: CountTile[]; rows: ActivityRow[] } = {
  countsLabel: 'Main Gate · today',
  listLabel: 'Log',
  tiles: [
    { key: 'admitted', label: 'ADMITTED', value: '14', tone: 'green-400' },
    { key: 'denied', label: 'DENIED', value: '1', tone: 'red-500' },
    { key: 'walkups', label: 'WALK-UPS', value: '3', tone: 'white' },
  ],
  rows: [
    row('1', 'MJ', 'Marcia James', 'QR pass · Lot 47', '2:14 PM'),
    row('2', 'KC', 'Kevin Chambers', 'Walk-up · Lot 47', '1:02 PM'),
    row('3', 'TC', 'TropiClean Pest Control', 'QR pass · Contractor', '10:02 AM'),
    row('4', '??', 'Unidentified visitor', 'Walk-up · no unit confirmed', '9:40 AM', 'deny'),
    row('5', 'DG', 'Devon Grant', 'QR pass · Lot 12', '9:20 AM'),
  ],
}

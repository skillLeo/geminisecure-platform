/**
 * Home's quick actions, by what this person may do — pure, so it is proven without a phone.
 *
 * A Member sees the board's six. A Dependent or a Helper sees their own e-pass FIRST — the one thing of the
 * household's their level always keeps, beside the panic button, which is on Home at every level — and then
 * only what the server would not refuse them (`access.may`). While nothing is known (an older server, no answer
 * yet) everything is drawn, as before levels, and the server decides. Three to a row, as ever (quickActionLayout).
 */

import { mayFrom, type Access, type MayKey } from '../access/access'

export type HomeActionKey = 'epass' | 'invite' | 'incident' | 'maintenance' | 'dues' | 'amenity' | 'meetings' | 'notices'

type Spec = { key: HomeActionKey; may: MayKey | null }

const MEMBER_ORDER = (bookings: boolean): Spec[] => [
  { key: 'invite', may: 'visitor_passes' },
  { key: 'incident', may: 'tickets' },
  { key: 'maintenance', may: 'tickets' },
  { key: 'dues', may: 'dues' },
  // Bookings sit behind a flag. With it off the tile becomes the estate's meetings.
  bookings ? { key: 'amenity', may: 'bookings' } : { key: 'meetings', may: 'meetings' },
  { key: 'notices', may: 'notices' },
]

/** The actions to draw, in order. `bookings`: amenity bookings are switched on (or the fidelity build). */
export function homeActionsFor(access: Access | null | undefined, bookings: boolean): HomeActionKey[] {
  const allowed = MEMBER_ORDER(bookings).filter((spec) => spec.may === null || mayFrom(access, spec.may)).map((spec) => spec.key)
  const member = !access || access.level === 'member'
  return member ? allowed : ['epass', ...allowed]
}

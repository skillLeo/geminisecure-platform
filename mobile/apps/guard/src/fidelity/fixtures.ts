/**
 * The boards' own data, served only in web fidelity mode so a screen can be put in
 * exactly the state its board draws. Every use sits behind `isFidelity`, which is a
 * build-time constant `false` on a phone — none of this ships in a native bundle's
 * live code path, and no screen ever falls back to it.
 */

import type { Geo, Shift } from '@/features/shift/useClockIn'

/** The boards' estate is in Jamaica: fixture DATA, as a seeded estate's zone is — not a default for the app (M19A §9). */
export const PHOENIX_PARK_ZONE = 'America/Jamaica'

/** guard-app-01 · Splash */
export const bootFixture = {
  siteName: 'Phoenix Park Village 1',
  /** `.splash .loader i{width:70%}` */
  loaderProgress: 0.7,
} as const

/** The boards' Main Gate as a fence (M12 §1): the board draws a 5 m boundary; the rule's thresholds are the server's. */
export const boardFence: Shift['fence'] = {
  source: 'post',
  name: 'Main Gate',
  latitude: 18.0179,
  longitude: -76.8099,
  radius_m: 5,
  good_accuracy_m: 50,
  poor_accuracy_m: 200,
  max_margin_m: 50,
  margin_per_accuracy: 2,
}

const boardShift: Shift = {
  fence: boardFence,
  supervisor_phone: null,
  id: 812,
  status: 'rostered',
  post: { id: 1, name: 'Main Gate' },
  site: { id: 'phoenixpark', name: 'Phoenix Park Village 1', timezone: PHOENIX_PARK_ZONE },
  rostered_start: '2026-09-04T12:00:00Z',
  rostered_end: '2026-09-05T00:00:00Z',
  actual_start: null,
  actual_end: null,
  on_break: false,
  break_minutes: 0,
  previous_handover_note: null,
  orders_to_acknowledge: 1,
  colleagues: [
    { name: 'Devon Palmer', post: 'Service Gate' },
    { name: 'Renae Cross', post: 'Patrol · Phase 2–5' },
  ],
}

type ClockInFixture = { who: string; shift: Shift; geo: Geo; mocked: boolean; attempt: number; supervisor: string | null }
const who = 'MARCUS WHYTE · PSRA-004471'

/** guard-app-02 / -37 / -38 / -39 — the clock-in screen in each state its boards draw. */
export const clockInFixtures: Record<string, ClockInFixture | undefined> = {
  'guard-app-02': { who, shift: boardShift, geo: { state: 'inside', boundaryM: 1.8, accuracyM: 3, radiusM: 5 }, mocked: false, attempt: 0, supervisor: null },
  'guard-app-37': { who, shift: boardShift, geo: { state: 'outside', boundaryM: 42, accuracyM: 6, radiusM: 5 }, mocked: false, attempt: 0, supervisor: null },
  'guard-app-38': { who, shift: boardShift, geo: { state: 'inside', boundaryM: 2.1, accuracyM: 4, radiusM: 5 }, mocked: true, attempt: 0, supervisor: 'Owen Grant' },
  'guard-app-39': { who, shift: boardShift, geo: { state: 'inside', boundaryM: 1.8, accuracyM: 3, radiusM: 5 }, mocked: false, attempt: 1, supervisor: null },
}

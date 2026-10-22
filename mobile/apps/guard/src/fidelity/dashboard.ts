/**
 * The dashboard boards' own data — guard-app-03 (on post, synced) and guard-app-33
 * (the same shift with no signal and four actions queued). Served ONLY in web fidelity
 * mode, behind `isFidelity` (a build-time `false` on a phone); no screen falls back to it.
 *
 * The boards' moment is 9:41 on Friday 4 September 2026 (BOARD_NOW_ISO). They draw
 * "4h 12m" remaining, so the board's shift ends at 1:53 PM; clocked in at 7:24; the
 * pending alertness check is due by 12:15.
 */

import type { DashboardShift } from '@/features/dashboard/useDashboard'
import { PHOENIX_PARK_ZONE, boardFence } from './fixtures'

export type DashboardFixture = {
  name: string
  shift: DashboardShift | null
  online: boolean
  waiting: number
  /** `respond_by` of the pending alertness check the board draws as NEXT CHECK. */
  nextCheckAt: string | null
}

const onPost: DashboardShift = {
  fence: boardFence,
  supervisor_phone: null,
  id: 812,
  status: 'on_duty',
  post: { id: 1, name: 'Main Gate' },
  site: { id: 'phoenixpark', name: 'Phoenix Park Village 1', timezone: PHOENIX_PARK_ZONE },
  rostered_start: '2026-09-04T12:00:00Z',
  rostered_end: '2026-09-04T18:53:00Z',
  actual_start: '2026-09-04T12:24:00Z',
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

export const dashboardFixtures: Record<string, DashboardFixture | undefined> = {
  'guard-app-03': { name: 'Marcus Whyte', shift: onPost, online: true, waiting: 0, nextCheckAt: '2026-09-04T17:15:00Z' },
  // Board 33 draws neither the roster card nor the orders banner: nothing left to acknowledge, nobody else listed.
  'guard-app-33': { name: 'Marcus Whyte', shift: { ...onPost, orders_to_acknowledge: 0, colleagues: [] }, online: false, waiting: 4, nextCheckAt: '2026-09-04T17:15:00Z' },
}

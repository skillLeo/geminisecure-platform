/**
 * guard-app-20 · Clock Out — the board's own data, served only in web fidelity mode
 * (behind `isFidelity`, a build-time `false` on a phone). No screen ever falls back to it.
 *
 * The board is frozen at 9:41 and draws "24 minutes over" and "12.4h" on post: a 12-hour
 * shift that started on time, took no break and should have ended at 9:17 (744 min = 12.4h).
 */

import type { ClockOutFixture } from '@/features/clockout/useClockOut'
import { PHOENIX_PARK_ZONE, boardFence } from './fixtures'

export const clockOutFixtures: Record<string, ClockOutFixture | undefined> = {
  'guard-app-20': {
    shift: {
      fence: boardFence,
      supervisor_phone: null,
      id: 812,
      status: 'on_duty',
      post: { id: 1, name: 'Main Gate' },
      site: { id: 'phoenixpark', name: 'Phoenix Park Village 1', timezone: PHOENIX_PARK_ZONE },
      rostered_start: '2026-09-04T02:17:00Z',
      rostered_end: '2026-09-04T14:17:00Z',
      actual_start: '2026-09-04T02:17:00Z',
      actual_end: null,
      on_break: false,
      break_minutes: 0,
      previous_handover_note: null,
      orders_to_acknowledge: 0,
      colleagues: [],
    },
    summary: { shiftId: '812', onPostMinutes: 744, checkpointsScanned: 9, checkpointsTotal: 9, incidentsFiled: 1 },
    geo: { state: 'inside', boundaryM: 1.8 },
    supervisor: 'Owen Grant',
    note: 'Service gate sensor sticking — reported to maintenance. Keys for the pump room are in the top drawer.',
  },
}

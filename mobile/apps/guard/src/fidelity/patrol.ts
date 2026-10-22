/**
 * The patrol board's own data — guard-app-07 (four of nine checkpoints scanned, the fifth next).
 * Served ONLY in web fidelity mode, behind `isFidelity` (a build-time `false` on a phone);
 * no screen falls back to it.
 *
 * `copy` holds the board's words for things the API does not have — the tour's name, the
 * "Randomised" badge, the elapsed clock, the distance to the next checkpoint. On a phone
 * none of these exist: usePatrol() says what the server gives instead.
 */

import { atSiteZone } from '@gs/core/lib'
import type { PatrolFixture } from '@/features/patrol/usePatrol'

/** A wall-clock time on the board's day, at the board's site — held as the UTC instant the server would. */
const at = (time: string) => atSiteZone('2026-09-04', Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5)))

export const patrolFixtures: Record<string, PatrolFixture | undefined> = {
  'guard-app-07': {
    checkpoints: [
      { id: 1, label: 'Main Gate', sequence: 1, lastScannedAt: at('11:21') },
      { id: 2, label: 'Generator Room', sequence: 2, lastScannedAt: at('11:25') },
      { id: 3, label: 'Pool Deck', sequence: 3, lastScannedAt: at('11:29') },
      { id: 4, label: 'Phase 1 Boundary', sequence: 4, lastScannedAt: at('11:32') },
      { id: 5, label: 'Playground — Phase 2', sequence: 5, lastScannedAt: null },
      { id: 6, label: 'Service Gate', sequence: 6, lastScannedAt: null },
      { id: 7, label: 'Phase 3 Boundary', sequence: 7, lastScannedAt: null },
      { id: 8, label: 'Club House', sequence: 8, lastScannedAt: null },
      { id: 9, label: 'Community Centre', sequence: 9, lastScannedAt: null },
    ],
    copy: { title: 'Phase 2 Perimeter', badge: 'Randomised', metaRight: 'Elapsed 11:32', radarLabel: 'Checkpoint 5 · 6m away', nextDetail: 'Next · within 15m to scan' },
  },
}

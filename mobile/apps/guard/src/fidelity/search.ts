/**
 * The search boards' own data (guard-app-05 one result · guard-app-40 no results), served
 * only in web fidelity mode. The household members, vehicle and standing instruction the
 * board draws are NOT in the API (gate.search returns the primary resident, the restriction
 * boolean and today's expected visitors) — they exist here and nowhere else.
 */

import type { UnitResult } from '@/features/gate/useUnitSearch'

type SearchFixture = { query: string; siteName: string; phases: string[]; results: UnitResult[] }

/**
 * The board draws three phase chips. On a phone the chips are the ESTATE'S phases, from
 * `guards.me` — Phoenix Park Village 1 has five — so the three here are the board's drawing
 * being reproduced for the measurement, and are not what an officer sees.
 */
const BOARD_PHASES = ['Phase 1', 'Phase 2', 'Phase 3']

export const searchFixtures: Record<string, SearchFixture | undefined> = {
  'guard-app-05': {
    query: 'Lot 47',
    siteName: 'Phoenix Park Village 1',
    phases: BOARD_PHASES,
    results: [
      {
        key: 'P2-47',
        unit: 'Phase 2 · Lot 47',
        household: 'Fletcher household',
        badge: ['P2', '47'],
        phase: 2,
        people: [
          { name: 'Andrea Fletcher', note: 'Primary' },
          { name: 'Jason Fletcher', note: 'Member' },
          { name: 'Chloe Fletcher', note: 'Dependent' },
        ],
        vehicle: 'Grey Honda CR-V · PC 4471',
        instruction: 'Standing instruction: no unannounced visitors after 8:00 PM',
        restricted: false,
      },
    ],
  },
  'guard-app-40': { query: 'Lot 99', siteName: 'Phoenix Park Village 1', phases: BOARD_PHASES, results: [] },
}

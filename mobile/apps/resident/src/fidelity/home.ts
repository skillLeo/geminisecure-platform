/**
 * The Home board's own data (resident-app-05), served only in web fidelity mode.
 * Every use sits behind `isFidelity`.
 */

import type { HomeModel } from '@/features/home/useHome'

export const homeFixture: Pick<HomeModel, 'greeting' | 'place' | 'today' | 'balance' | 'expected' | 'notice' | 'approvals'> = {
  greeting: 'Good morning, Andrea',
  place: 'Phoenix Park Village 1 · Phase 2 · Lot 47',
  today: 'Friday, September 4',
  balance: { state: 'owing', title: 'Maintenance fee', amount: '$12,400 outstanding', detail: 'Due September 28', tone: 'amber-600', action: 'Pay now', opens: 'pay', stale: null },
  expected: [
    { id: 'p1', initials: 'MJ', name: 'Marcia James', detail: 'Family visit', when: '2:00 – 4:00 PM' },
    { id: 'p2', initials: 'TC', name: 'TropiClean Pest Control', detail: 'Contractor', when: '10:00 AM' },
  ],
  notice: { id: 'n1', title: 'Water interruption — Phase 2, Sat 6 Sep', body: 'NWC maintenance work from 9am–3pm. Store water in advance.', posted: 'Posted 2 hours ago', urgent: false },
  approvals: [],
}

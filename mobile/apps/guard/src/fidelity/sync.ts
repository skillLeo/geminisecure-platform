/**
 * The sync-queue board's own data (guard-app-34), served only in web fidelity mode.
 * Every use sits behind `isFidelity`.
 */

import type { QueueRow } from '@/features/sync/useSyncQueue'

export const syncQueueFixture: { waiting: QueueRow[] } = {
  waiting: [
    { id: 'q1', icon: 'bio-btn', title: 'Clock-in', detail: 'Main Gate · 7:24 AM', state: 'pending', trouble: null },
    { id: 'q2', icon: 'tab-patrol', title: 'Checkpoint scan — Pool Deck', detail: '11:29 AM', state: 'pending', trouble: null },
    { id: 'q3', icon: 'tab-patrol', title: 'Checkpoint scan — Phase 1 Boundary', detail: '11:32 AM', state: 'pending', trouble: null },
    { id: 'q4', icon: 'qa-tile-3', title: 'Incident report + 2 photos', detail: 'Suspicious activity · 9:12 AM', state: 'pending', trouble: null },
  ],
}

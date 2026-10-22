/**
 * The notice boards' own data (resident-app-12 the list · -36 one notice), served only in web fidelity
 * mode. Every use sits behind `isFidelity`.
 *
 * The notices themselves are in the SERVER's shape, so the board's state goes through the same code a
 * real notice does (its date line, its byline, its paragraphs, its unread dot — and
 * its "seen by 212 of 450 households" line and its PDF row, which the server now sends). What only the
 * board has is kept apart under `board`: topic chips and pills ("Security", "Events", "Governance") and a
 * picture per topic. The server sends a notice's kind (general | urgent) and its audience — no topic —
 * so those exist here and nowhere else. (The funnel and the "…" button are drawn live too.)
 */

import type { IconName } from '@gs/core'

type BoardCard = { tags: { label: string; urgent: boolean }[]; icon: IconName }

export const noticesFixture = {
  items: [
    { id: 1, kind: 'urgent', title: 'Gate 2 temporarily closed for repairs', body: 'Use the Main Gate on Waterloo Road until further notice. Guards have been briefed.', author_name: 'Property Manager', posted_as_role: 'Property Manager', audience: 'Whole estate', published_at: '2026-09-04T13:15:00Z', read_at: null },
    { id: 2, kind: 'general', title: 'Water interruption — Phase 2, Sat 6 Sep', body: 'NWC maintenance work from 9am–3pm. Please store water in advance.', author_name: 'Property Manager', posted_as_role: 'Property Manager', audience: 'Phase 2', published_at: '2026-09-03T20:40:00Z', read_at: null },
    { id: 3, kind: 'general', title: 'Annual General Meeting — save the date', body: 'Saturday 27 September, 10:00 AM, hosted in-app. One vote per household.', author_name: 'Delroy Samuels', posted_as_role: null, audience: 'Whole estate', published_at: '2026-09-02T15:00:00Z', read_at: '2026-09-02T23:05:00Z' },
    { id: 4, kind: 'general', title: 'Election nominations now open', body: 'Nominate a candidate for Chairman, Vice Chairman and other executive posts.', author_name: 'Delroy Samuels', posted_as_role: 'Secretary', audience: 'Whole estate', published_at: '2026-09-01T14:30:00Z', read_at: '2026-09-01T17:00:00Z' },
  ],
  unread: '2',
  /** What only the board has. */
  board: {
    chips: ['All', 'Urgent', 'Security', 'Events'],
    cards: {
      '1': { tags: [{ label: 'Urgent', urgent: true }, { label: 'Security', urgent: false }], icon: 'qa-tile-3' },
      '2': { tags: [{ label: 'Maintenance', urgent: false }], icon: 'tab-notices' },
      '3': { tags: [{ label: 'Events', urgent: false }], icon: 'input' },
      '4': { tags: [{ label: 'Governance', urgent: false }], icon: 'invite-note' },
    } as Record<string, BoardCard>,
    filterIcon: 'side-btn-6' as IconName,
  },
} as const

export const noticeFixture = {
  notice: {
    id: 1,
    kind: 'urgent',
    title: 'Gate 2 temporarily closed for repairs',
    body: [
      'Gate 2 (Phase 4 pedestrian entrance) is closed from this morning while our contractor repairs a fault with the barrier arm sensor.',
      'All residents and visitors should use the Main Gate on Waterloo Road until further notice. Guards at both gates have been briefed and will direct traffic accordingly.',
      'We expect the repair to be completed within 48 hours and will post an update here as soon as Gate 2 reopens.',
    ].join('\n\n'),
    author_name: 'Property Manager',
    posted_as_role: 'Property Manager',
    audience: 'Whole estate',
    published_at: '2026-09-04T13:15:00Z',
    read_at: '2026-09-04T14:41:00Z',
    // The board's "seen by 212 of 450 households" and its PDF row are the SERVER's shape, so the board's
    // state is drawn by the same code a real notice is. (The board prints no size under the file's name; see the screen.)
    seen_households: 212,
    audience_households: 450,
    attachments: [{ id: 1, name: 'Gate2_Contractor_Notice.pdf', content_type: 'application/pdf', bytes: 245_760 }],
  },
  /** What only the board has: the topic beside "Urgent" in the one pill. */
  board: {
    tag: 'Urgent · Security',
  },
} as const

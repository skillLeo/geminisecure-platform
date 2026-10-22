/**
 * The incident boards' own data (guard-app-08 the report · guard-app-23 my reports), served only in
 * web fidelity mode. Every use sits behind `isFidelity`, a build-time `false` on a phone.
 *
 * What the boards draw that the server does not have lives here and nowhere else:
 *   08  a report caught mid-dictation ("Listening…" and its wave) with the transcript in quotation marks;
 *       two photo thumbnails with no picture in them; a place named by distance from a patrol checkpoint
 *       ("6m from Checkpoint 5"); and a "Link to current patrol" switch — the server links a report to the
 *       running SHIFT by itself and has no patrol link to switch. The board draws no severity control at all:
 *       the live screen adds one (the server requires it), the fidelity build leaves it out.
 *   23  nothing: kind · status · account · time · id are all the server's. (The board words an open report
 *       "Investigating"; the server knows only `open`, and the app says "Open", as the console does.)
 */

import type { ReportItem } from '@/features/incidents/incidents'

/** guard-app-08 · Incident Report */
export const incidentReportFixture = {
  category: 'suspicious',
  severity: 'med',
  listening: true,
  transcriptLabel: 'Transcript — tap to edit',
  transcript: '"Grey pickup, no plates, parked by the Phase 2 playground over an hour. Two men inside, appear to be watching the units. Requesting a second unit to assist."',
  /** The board's two empty thumbnails. */
  photoThumbs: 2,
  location: { title: 'Phase 2 Playground', detail: 'GPS-pinned · 6m from Checkpoint 5' },
  link: { label: 'Link to current patrol', on: true },
} as const

const item = (id: number, kind: string, status: ReportItem['status'], detail: string, occurredAt: string): ReportItem => ({
  id,
  kind,
  severity: 'low',
  status,
  detail,
  location: null,
  shift_id: null,
  occurred_at: occurredAt,
  resolution: '',
  closed_at: null,
  media_count: 0,
})

/** guard-app-23 · My Reports — the board's moment is Friday 4 September 2026, 9:41 AM Jamaica. */
export const myReportsFixture: { items: ReportItem[] } = {
  items: [
    item(337, 'Suspicious activity', 'open', 'Grey pickup, no plates, parked by the Phase 2 playground over an hour.', '2026-09-04T14:12:00Z'),
    item(331, 'Equipment fault', 'resolved', 'Main Gate barrier arm sensor unresponsive, logged for contractor visit.', '2026-09-02T23:40:00Z'),
    item(298, 'Noise complaint', 'resolved', 'Loud music from Lot 22 after 11pm, resident spoken to and complied.', '2026-08-27T04:40:00Z'),
    item(271, 'Trespass', 'resolved', 'Non-resident found near the pool deck after hours, escorted off site.', '2026-08-20T03:05:00Z'),
  ],
}

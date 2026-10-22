/**
 * The maintenance boards' own data (resident-app-11 new request · -26 status), served only in web
 * fidelity mode. Every use sits behind `isFidelity`.
 *
 * Board 11 draws its moment: Plumbing chosen, the microphone mid-sentence with its waveform, the words
 * heard so far, two photos already attached (drawn as the board draws them, two plain tiles), High, and
 * entry allowed. The waveform's bars are the board's own — live, nothing reports a microphone level, so
 * the bars are still (useMaintenanceRequest). Board 26 draws a category that was changed ("Plumbing → Electrical"),
 * and the server keeps no record of a changed category — so that line exists here and nowhere else. Everything else
 * on the board is drawn from the ticket below by the same code a phone's ticket goes through: the vendor and the
 * number its button rings, who in the office acknowledged it ("Property Manager"), the technician and the window.
 * The number is a fictional 555 one; the board draws the button, never the number. The description is the
 * resident's words as the server keeps them: the screen puts them between the board's quotation marks.
 */

export const requestFixture = {
  kind: 'plumbing',
  area: 'unit',
  listening: true,
  /** The board's seven bars, 6 to 18 points tall (6 · 14 · 9 · 18 · 8 · 15 · 6), as levels from 0 to 1. */
  levels: [0, 8 / 12, 3 / 12, 1, 2 / 12, 9 / 12, 0],
  transcript: '"Di gate light dem nuh work since Tuesday, it dark by the visitor parking at night."',
  photoThumbs: 2,
  priority: 'high',
  allowEntry: true,
} as const

export const statusFixture = {
  /** The server's shape, so the board's state goes through the same code a real ticket does. */
  ticket: {
    id: 1042,
    number: '1042',
    title: 'Gate lighting — Phase 2 visitor parking',
    category: 'electrical',
    location: 'Phase 2 visitor parking',
    description: 'The gate light dem nuh work since Tuesday, it dark by the visitor parking at night.',
    // The board draws neither of the named fields; they are here because the server's shape carries them.
    access_permitted: null,
    preferred_time: null,
    priority: 'high',
    status: 'in_progress',
    reported_at: '2026-09-02T23:40:00Z',
    technician_name: 'Owen Grant',
    eta_starts_at: '2026-09-04T19:00:00Z',
    eta_ends_at: '2026-09-04T21:00:00Z',
    resolution: null,
    closed_at: null,
    media_count: 2,
    vendor: { name: 'Island Electric Services', phone: '876-555-0190' },
    timeline: [
      { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-02T23:40:00Z', by: { kind: 'resident', name: 'Andrea Fletcher', role: null } },
      { event: 'acknowledged', status: 'acknowledged', note: null, occurred_at: '2026-09-03T13:15:00Z', by: { kind: 'staff', name: 'Renae Cross', role: 'Property Manager' } },
      { event: 'assigned', status: 'assigned', note: null, occurred_at: '2026-09-03T16:20:00Z', by: { kind: 'staff', name: 'Renae Cross', role: 'Property Manager' } },
      { event: 'started', status: 'in_progress', note: 'Technician on site', occurred_at: '2026-09-04T14:05:00Z', by: null },
    ],
  },
  /** What only the board has. */
  board: {
    meta: 'Submitted Sep 2 · Plumbing → Electrical',
  },
} as const

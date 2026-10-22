/**
 * The meeting boards' own data (resident-app-13 Meeting Lobby · -23 Live Meeting), served only in web
 * fidelity mode. Every use sits behind `isFidelity`.
 *
 * The meeting itself is in the SERVER's shape, so the board's state goes through the same code a real
 * meeting does (its type line, its Kingston date, its agenda times). What only the board has is kept
 * apart under `board`:
 *
 *   Lobby   the quorum figures ("142 of 450 households · need 25%", the bar 32% full) — the server sends no
 *           quorum figure, and an RSVP is not attendance; "Hosted in-app" — the server's meetings are joined
 *           through the estate's own link, outside the app; the board's own date line, resident and estate.
 *           The lock line ("there's no link to share") is drawn live too, with the estate's own name.
 *   Live    video tiles, a participant count and a running clock, "Now discussing", a LIVE MOTION VOTE
 *           with a tally, and mute / raise-hand / camera / chat controls. The server has no streaming,
 *           chat, hand-raise or live-poll endpoint at all — so every one of those exists here and
 *           nowhere else, drawn as plain views with nothing to press.
 */

const meeting = {
  id: 12,
  type: 'agm',
  title: 'Phoenix Park Village 1 — AGM 2026',
  starts_at: '2026-09-27T15:00:00Z',
  /** The board's words for where it is held; the real code path prints a venue exactly like this. */
  venue: 'Hosted in-app',
  virtual_link: null,
  status: 'scheduled',
  recording_enabled: false,
  agenda: [
    { start_time: '10:00:00', text: 'Welcome & confirmation of quorum' },
    { start_time: '10:15:00', text: "Treasurer's report — FY2025/26" },
    { start_time: '10:45:00', text: 'Security update from Gemini Security Ltd' },
    { start_time: '11:15:00', text: 'Motion: approve 2026/27 budget' },
    { start_time: '11:45:00', text: 'Executive election results' },
  ],
  minutes_available: false,
  my_rsvp: null,
} as const

export const lobbyFixture = {
  meeting,
  /** What only the board has. */
  board: {
    /** The board's own words: 27 September 2026 is a SUNDAY, so the real date code would not print this line. */
    when: 'Saturday 27 September · 10:00 AM · Hosted in-app',
    verified: 'Verified as Lot 47 · Andrea Fletcher',
    quorum: { count: '142', rest: ' of 450 households · need 25%', fill: '32%' },
    lockNote: "This room is locked to verified Phoenix Park residents only — there's no link to share, and no outsider can join.",
  },
} as const

export const liveFixture = {
  meeting,
  /** What only the board has — the server carries no video, people, discussion, motion or controls. */
  board: {
    title: 'AGM 2026',
    sub: '142 households · 34:12',
    tiles: [
      { initials: 'DR', name: 'Dwayne R. · Chair', speaking: true, muted: false },
      { initials: 'AF', name: 'Andrea F. (You)', speaking: false, muted: true },
      { initials: 'SC', name: 'Sonia C.', speaking: false, muted: false },
      { initials: '+139', name: 'Others', speaking: false, muted: false },
    ],
    discussing: 'Now discussing: Motion to approve the 2026/27 budget',
    motion: {
      tag: 'LIVE MOTION VOTE',
      title: 'Approve the 2026/27 budget as presented',
      options: ['Yes', 'No', 'Abstain'],
      tally: ['Yes 96', 'No 12', 'Abstain 8'],
    },
  },
} as const

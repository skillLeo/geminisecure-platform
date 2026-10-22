/**
 * The incident boards' own data (resident-app-14 report · -27 status), served only in web fidelity
 * mode (and the dev gallery). Every use sits behind `isFidelity`, in the screens' hooks.
 *
 * Board 14 draws a microphone mid-sentence, a photo and a VIDEO already attached, a place pinned from the
 * phone and named "Phase 2 playground", and the switch that keeps the reporter's name from other residents
 * on. It is given here as DATA — the same shape a phone's report has — and the screen's one code path turns
 * it into the board's words: the quotation marks round the transcript and "Pinned from current location" are
 * the screen's, not the fixture's. What a phone cannot produce today stays here and nowhere else: a video
 * (the picker takes photos only) and a named place without the resident typing it (the app knows no places).
 * Board 27 draws an "IR-" incident number; a report is a `security` ticket, numbered as the estate office numbers
 * every ticket, so that exists only here. Everything else on the board — "Suspicious activity", the stages with
 * "Dispatcher Renae Cross" (the member of staff who took it in, by role and name) and "Patrol dispatched to the area",
 * the two updates (the office's notes) — is drawn from the ticket below by the same code a phone's report goes through
 * (incidents.ts boardSteps, boardUpdates).
 */

export const reportFixture = {
  /** The board's first chip is the one on. */
  type: 'suspicious',
  /** The board draws the microphone mid-sentence: "Listening…" and the waveform. */
  listening: true,
  /** The board's seven bars, 7 16 10 19 9 14 6 points tall, as shares of the 3–20 point range the screen draws a level in. */
  levels: [4, 13, 7, 16, 6, 11, 3].map((n) => n / 17),
  /** What has been heard so far (the screen draws it between the board's quotation marks). */
  transcript: 'A grey pickup with no plates has been parked by the Phase 2 playground for over an hour, two men sitting inside watching the units.',
  /** A photo and a video already attached, drawn as the board draws them: plain tiles, the video's with its play mark. */
  media: [
    { key: 'board-photo', kind: 'photo', uri: null },
    { key: 'board-video', kind: 'video', uri: null },
  ],
  /** The place, in the resident's words, and the pin the phone gave. */
  place: 'Phase 2 playground',
  fix: { latitude: 18.0179, longitude: -76.8099, accuracyM: 12 },
  keepNamePrivate: true,
} as const

export const statusFixture = {
  /** The server's shape, so the board's state goes through the same code a real report does. */
  ticket: {
    id: 337,
    number: '337',
    title: 'Grey pickup at Phase 2 playground',
    category: 'security',
    location: 'Phase 2 playground',
    description:
      '"A grey pickup with no plates has been parked by the Phase 2 playground for over an hour, two men sitting inside watching the units."\n\nReport details:\n- Type: Suspicious activity\n- When: Happening now\n- Written on this phone: Sep 4, 2026, 9:12 AM (estate time)',
    // The board draws neither of the named fields; they are here because the server's shape carries them.
    access_permitted: null,
    preferred_time: null,
    priority: 'medium',
    status: 'in_progress',
    reported_at: '2026-09-04T14:12:00Z',
    technician_name: null,
    eta_starts_at: null,
    eta_ends_at: null,
    resolution: null,
    closed_at: null,
    media_count: 0,
    timeline: [
      { event: 'reported', status: 'submitted', note: null, occurred_at: '2026-09-04T14:12:00Z', by: { kind: 'resident', name: 'Andrea Fletcher', role: null } },
      { event: 'acknowledged', status: 'acknowledged', note: null, occurred_at: '2026-09-04T14:13:00Z', by: { kind: 'staff', name: 'Renae Cross', role: 'Dispatcher' } },
      { event: 'started', status: 'in_progress', note: 'Patrol dispatched to the area', occurred_at: '2026-09-04T14:14:00Z', by: { kind: 'staff', name: 'Renae Cross', role: 'Dispatcher' } },
      { event: 'note', status: 'in_progress', note: 'Guard patrol dispatched to Phase 2 playground.', occurred_at: '2026-09-04T14:16:00Z', by: { kind: 'staff', name: 'Renae Cross', role: 'Dispatcher' } },
      { event: 'note', status: 'in_progress', note: "Vehicle no longer present on arrival. Area added to tonight's patrol route.", occurred_at: '2026-09-04T14:24:00Z', by: { kind: 'staff', name: 'Renae Cross', role: 'Dispatcher' } },
    ],
  },
  /** What only the board has. */
  board: {
    heading: 'Incident #IR-0337',
  },
} as const

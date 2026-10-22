/**
 * The More boards' own data (guard-app-25 more · 26 my profile · 27 help · 35 change PIN · 36 notifications),
 * served only in web fidelity mode. Every use sits behind `isFidelity`, a build-time `false` on a phone.
 *
 * What the boards draw that the server does not have lives here and nowhere else:
 *   25/26  a PSRA licence number and its expiry, a job title, an employment date — the API serves a guard's
 *          name, service number and estate, nothing from the HR file. The live profile shows those, and
 *          what THIS PHONE knows about itself (its model, when it was set up, how identity is checked).
 *   27     a named ops manager to call — no telephone number is served; the live screen messages the
 *          control room and always offers 119 / 110.
 *   35     a PIN. This platform has none: a guard is identified by the enrolled handset and the phone's own
 *          fingerprint or face check. The number pad is drawn for fidelity only; the live screen says so.
 *   36     "SMS backup" — the platform has no SMS provider.
 */

export const moreFixture = { initials: 'MW', name: 'Marcus Whyte', line: 'PSRA-004471 · Phoenix Park Village 1', version: 'GeminiSecure Guard · v2.4.1' } as const

export const profileFixture = {
  initials: 'MW',
  name: 'Marcus Whyte',
  role: 'Security Officer · Full-time',
  tag: 'PSRA-004471 · Active',
  assignment: [['Site', 'Phoenix Park Village 1'], ['Primary post', 'Main Gate'], ['Employed since', 'Mar 14, 2024'], ['PSRA expires', 'Nov 30, 2026']],
  device: { model: 'iPhone 15 Pro', line: '✓ Biometric enrolled · linked Mar 14, 2024' },
} as const

export const helpFixture = {
  topics: [
    { icon: 'privacy-note', title: 'Clock-in & geofence issues' },
    { icon: 'btn-outline', title: 'Payslip & hours questions' },
    { icon: 'input', title: 'Leave & open shifts' },
    { icon: 'help-icon', title: 'Panic & duress protocol' },
  ],
  contacts: [
    { icon: 'call-btn', title: 'Call Owen Grant · Ops Manager' },
    { icon: 'tab-messages', title: 'Dispatch — 24 hours' },
    { icon: 'qa-tile-3', title: 'Report a technical problem' },
  ],
} as const

export const pinFixture = { prompt: 'Enter your new 6-digit PIN', filled: 4 } as const

export const notificationsFixture = {
  categories: [
    { title: 'Panic & duress alerts', detail: 'Always on for safety', locked: true },
    { title: 'Shift & roster changes', detail: 'Open shift approvals, swaps', locked: false },
    { title: 'Standing order updates', detail: 'New or revised orders for your posts', locked: false },
    { title: 'Messages', detail: 'Direct messages and broadcasts', locked: false },
    { title: 'Payslip ready', detail: 'When a new payslip is posted', locked: false },
  ],
  channels: [
    { title: 'Push notifications', detail: null },
    { title: 'SMS backup', detail: "Used if push can't reach you" },
  ],
} as const

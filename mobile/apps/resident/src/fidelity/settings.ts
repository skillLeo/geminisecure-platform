/**
 * The settings boards' own data (resident-app-18 Settings · -19 Security · -20 Notifications · -33 Privacy &
 * data · -38 Language & accessibility · -39 Help & support), served in web fidelity mode. Every use sits
 * behind `isFidelity`, but for the quiet hours' words (notificationsFixture.quiet).
 *
 * Since the M42 rebuild the live screens draw the boards' own rows and words, and since M50 (§4.1: "if it IS in the
 * design, make it functional") what they draw works — or is not drawn (the PIN, which does not exist; SMS, with no
 * provider). What stays here is the boards' SAMPLE DATA — dates, places, names, a version, a phone number, an
 * address — which a phone never shows as fact (the quiet hours' row is drawn live: see notificationsFixture).
 */

/** resident-app-18 · Settings */
export const settingsFixture = {
  sections: [
    {
      heading: 'Account',
      rows: [
        { icon: 'privacy-note', title: 'Security', detail: 'Biometrics, active sessions' },
        { icon: 'set-icon-2', title: 'Notifications', detail: 'Categories, channels, quiet hours' },
        { icon: 'pm-icon', title: 'Payments & AutoPay', detail: 'Saved cards, AutoPay limit' },
      ],
    },
    { heading: 'Privacy', rows: [{ icon: 'invite-note', title: 'Privacy & data', detail: 'Download my data, consents' }] },
    { heading: 'Preferences', rows: [{ icon: 'set-icon-3', title: 'Language & accessibility', detail: 'Text size, contrast, motion' }] },
    {
      heading: 'Support',
      rows: [
        { icon: 'set-icon', title: 'Help & support', detail: 'Report a problem, contact us' },
        { icon: 'set-icon-4', title: 'About GeminiSecure', detail: 'Version 2.4.1' },
      ],
    },
  ],
} as const

/** resident-app-19 · Security — a PIN, payment biometrics and a second phone: none exist on the server. */
export const securityFixture = {
  pin: { icon: 'set-icon-5', title: 'Change PIN', detail: 'Last changed 3 months ago' },
  biometricHeading: 'Biometric login',
  biometrics: [
    { icon: 'set-icon-6', title: 'Face ID / fingerprint', detail: 'Use biometrics to log in and confirm payments' },
    { icon: 'pm-icon', title: 'Require biometrics for payments', detail: 'Re-confirm identity above $5,000' },
  ],
  sessionsHeading: 'Active sessions',
  sessions: [
    { name: 'iPhone 15 Pro', detail: 'This device · Spanish Town, JM', current: true },
    { name: 'Samsung A54', detail: 'Last active 2 days ago', current: false },
  ],
} as const

/**
 * resident-app-20 · Notifications — the board's categories, channels and quiet hours. The quiet hours' words are
 * drawn live too (useNotificationSettings): the app keeps the board's hours, 10:00 PM – 7:00 AM, on this phone (M50
 * §4.2 #5). The SMS row is the board's and is not drawn: no text-message provider is connected.
 */
export const notificationsFixture = {
  categories: [
    { title: 'Urgent alerts', detail: 'Panic, emergencies — always on for safety', state: 'locked' },
    { title: 'Security & access', detail: 'Visitor entries, gate activity', state: 'on' },
    { title: 'Maintenance', detail: 'Ticket updates, utility notices', state: 'on' },
    { title: 'Events', detail: 'Amenity bookings, community events', state: 'on' },
    { title: 'Governance', detail: 'Meetings, elections, notices', state: 'on' },
  ],
  channels: [
    { title: 'Push notifications', detail: null, state: 'on' },
    { title: 'Email', detail: null, state: 'on' },
    { title: 'SMS', detail: null, state: 'off' },
  ],
  quiet: { title: '10:00 PM – 7:00 AM', detail: 'Urgent alerts still come through', state: 'on' },
} as const

/** resident-app-33 · Privacy & data */
export const privacyFixture = {
  data: [
    { icon: 'side-btn-2', title: 'Download my data', detail: 'A copy of everything GeminiSecure holds about you' },
    { icon: 'invite-note', title: 'Data retention', detail: 'How long we keep gate, financial & report records' },
  ],
  consents: [
    { title: 'Biometric login', detail: 'Granted Jun 12, 2026 · this device only', status: 'Granted', on: true },
    { title: 'Location for panic alerts', detail: 'Granted Jun 12, 2026 · while using app', status: 'Granted', on: true },
    { title: 'Marketing communications', detail: 'Estate newsletters and partner offers', status: 'Off', on: false },
  ],
  danger: { title: 'Delete my account', detail: "Your household's financial records stay with the estate" },
  policy: 'Read our full Privacy Policy',
} as const

/** resident-app-38 · Language & accessibility — the board's thumb sits at 52% of the track. */
export const languageFixture = {
  language: { title: 'English (Jamaica)', detail: 'App language and date formats' },
  thumbLeft: '52%',
  preview: 'This is how your text will look',
  display: [
    { title: 'High contrast', detail: 'Stronger colour separation for low vision' },
    { title: 'Reduce motion', detail: 'Turns off animations and transitions' },
  ],
  banner: 'GeminiSecure is fully compatible with VoiceOver and TalkBack screen readers.',
} as const

/** resident-app-39 · Help & support — a support line, an address and a live chat the estate does not have. */
export const helpFixture = {
  search: 'Search help articles',
  topics: [
    { icon: 'help-icon-2', title: 'Visitors & gate access' },
    { icon: 'tab-card', title: 'Dues & payments' },
    { icon: 'tab-profile', title: 'Elections & voting' },
    { icon: 'help-icon', title: 'Using the panic button' },
  ],
  report: { title: 'Report a problem', detail: "Something not working? Send us the details, with logs attached if you'd like.", button: 'Start a report' },
  contacts: [
    { icon: 'call-btn', title: 'Call Gemini Security · (876) 555 0100' },
    { icon: 'input-5', title: 'support@geminisecure.com' },
    { icon: 'tab-messages', title: 'Live chat · typically replies in 5 min' },
  ],
} as const

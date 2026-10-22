/**
 * Help — what THIS app really does, in a guard's words (board guard-app-27). Static, on the phone, so it
 * is there with no signal — which is when a guard most needs it. Every answer is checked against the code:
 * if the app's behaviour changes, the sentence changes with it.
 */

export type Topic = { key: string; icon: 'privacy-note' | 'btn-outline' | 'input' | 'help-icon' | 'offline-note' | 'tab-messages'; title: string; articles: { q: string; a: string }[] }

export const TOPICS: Topic[] = [
  {
    key: 'clock-in',
    icon: 'privacy-note',
    title: 'Clock-in & geofence issues',
    articles: [
      { q: 'It says I am outside the post', a: 'Clock-in checks that this phone is at your post. Move into the open near the gate, wait a few seconds for the location to settle, and try again. If it still refuses and you ARE on post, call your supervisor: they can start your shift from the console.' },
      { q: 'I have no signal at the start of my shift', a: 'Clock in anyway. The app records the time on this phone and sends it when the signal is back. Home shows "Pending sync" until the server has it, then "Synced". Nothing needs to be redone.' },
      { q: 'My fingerprint or face was not accepted', a: 'Try again, then use the other hand or wipe the sensor. The check is done by the phone itself: no fingerprint or face data ever reaches this app or the office. If the phone cannot check you, the shift still starts and is marked as not confirmed by the handset.' },
      { q: 'It says my location looks simulated', a: 'Turn off any mock-location or GPS-changing app, and Developer options if they are on, then try again. The shift is flagged for your supervisor to review.' },
    ],
  },
  {
    key: 'offline',
    icon: 'offline-note',
    title: 'Working with no signal',
    articles: [
      { q: 'What works offline?', a: 'Clock-in and clock-out, scanning visitor passes (they are checked on this phone), patrol checkpoints, incident reports, leave and equipment requests, and messages to the control room. Each is saved on the phone first and sent in order when the signal returns.' },
      { q: 'What does NOT work offline?', a: 'Claiming an open shift, answering an alertness check-in, and asking a resident to approve a walk-up visitor. These only mean something live, so the app says so instead of saving them for later.' },
      { q: 'How do I know everything was sent?', a: 'Open the sync queue from Home. "Confirmed" means the server has it. "Pending sync" means it is safe on this phone and waiting. "Not accepted" shows the server\'s reason.' },
    ],
  },
  {
    key: 'pay',
    icon: 'btn-outline',
    title: 'Payslip & hours questions',
    articles: [
      /*
       * BOTH OF THESE WERE FALSE BY THE TIME THEY SHIPPED, and they shipped anyway.
       *
       * The first described a payslip that showed only gross, deductions and net; the screen has
       * shown the whole slip since M8 §5.3 — every hour with its rate, each earning that makes up
       * the gross, each deduction with the figure it was taken on, the employer's own
       * contributions and the year to date. The second said a payslip is never saved as a file,
       * which was true until the Download button was built in the same change.
       *
       * Help text is not decoration: a guard who reads "No" here does not press the button that
       * works. Found on 23 September 2026 by reading the strings out of the built APK rather than
       * out of the repo — which is the only way to know what is actually on somebody's phone.
       */
      { q: 'What does my payslip show?', a: 'The whole slip, exactly as the office approved it: the hours you worked with the rate for each, every earning that makes up your gross pay, every deduction with the figure it was taken on, your employer\'s own contributions, and the totals for the year so far. Every figure is the office\'s: nothing is worked out on this phone.' },
      { q: 'Can I download my payslip?', a: 'Yes. "Download PDF" on the payslip fetches it and hands it straight to your phone\'s share sheet, so you can send it to yourself, to WhatsApp or to a printer. It is never written to Downloads or anywhere another app could read it, and the copy it makes is deleted the moment the sharing is done.' },
      { q: 'My hours look wrong', a: 'Hours run from your clock-in to your clock-out, less breaks, as the server recorded them. If a day is wrong, message the control room (Messages → Control room) and name the day.' },
    ],
  },
  {
    key: 'leave',
    icon: 'input',
    title: 'Leave & open shifts',
    articles: [
      { q: 'How do I ask for leave?', a: 'More → Leave & duty. Choose the type and the first and last day. For sick leave you can tick that you have a medical certificate: only the tick is sent; show the certificate itself to your supervisor.' },
      { q: 'I claimed a shift. Is it mine?', a: 'Not until a supervisor approves it. Until then do not report for it. The decision shows under Open shifts and the shift joins your roster if approved.' },
      { q: 'Why can I not see open shifts offline?', a: 'They are first come, so a saved list would be out of date. Connect and pull down to refresh.' },
    ],
  },
  {
    key: 'duress',
    icon: 'help-icon',
    title: 'Panic & duress protocol',
    articles: [
      { q: 'How do I raise a duress alert?', a: 'Tap the shield button on Home, then hold the alert button until it fills, a single tap sends nothing. The alert goes to the control room at once with your post. With no signal it is saved and sent the moment the phone reconnects, the screen tells you which.' },
      { q: 'Is there a silent way?', a: 'Yes. Rest your thumb on the Home shield for two seconds. Nothing on the screen changes, and the control room still gets the alert.' },
      { q: 'I pressed it by mistake', a: 'Open the alert and cancel it with a reason. The control room sees both the alert and the cancellation.' },
      { q: 'In real danger', a: 'Your safety comes first. Call 119 for the police or 110 for fire and ambulance: the app never blocks a call.' },
    ],
  },
]

const fold = (text: string) => text.toLowerCase().normalize('NFKD').replace(/[^a-z0-9 ]+/g, ' ')

/** Topics narrowed to the articles that mention every word typed. An empty search is everything. */
export function searchHelp(query: string, topics: Topic[] = TOPICS): Topic[] {
  const words = fold(query).split(' ').filter(Boolean)
  if (words.length === 0) return topics
  return topics
    .map((t) => ({ ...t, articles: t.articles.filter((art) => words.every((w) => fold(`${t.title} ${art.q} ${art.a}`).includes(w))) }))
    .filter((t) => t.articles.length > 0)
}

/** A problem report names the app and the phone — never the guard's location, a payslip figure or a resident. */
export const problemDraft = (info: { version: string; device: string; os: string }): string => `Technical problem (Guard app ${info.version}, ${info.device}, ${info.os}): `

export const EMERGENCY = [
  { label: 'Police: 119', number: '119' },
  { label: 'Fire & ambulance: 110', number: '110' },
] as const

/**
 * The message boards' own data (guard-app-18 the inbox · guard-app-14 a thread), served only in web
 * fidelity mode. Every use sits behind `isFidelity`, a build-time `false` on a phone.
 *
 * What the boards draw that the server does not have lives here and nowhere else:
 *   18  "Direct messages" between guards (Owen Grant, Renae Cross, Devon Palmer) and a green "online" dot.
 *       The server keeps exactly two conversations for a guard — the estate's broadcasts and the guard's
 *       own line to dispatch — and no presence. The live inbox shows those two and nothing else.
 *   14  a named person with "● Online", a call button (no number is served) and an attach button
 *       (a message is text only). The live thread is "Dispatch" or "Site broadcast", with neither control.
 */

/** guard-app-18 · Messages Inbox */
export const inboxFixture = {
  broadcast: { name: 'Phoenix Park Village 1', when: '8:15 AM', preview: 'Dispatch: New standing orders posted for Main Gate…', unread: 2 },
  direct: [
    { id: 'og', initials: 'OG', online: true, name: 'Owen Grant · Ops Manager', when: '9:24 AM', preview: 'Acknowledged. Noticed the grey pickup I flagged…', unread: 1 },
    { id: 'rc', initials: 'RC', online: false, name: 'Renae Cross', when: 'Yesterday', preview: 'Swapped Phase 4 boundary check to 11pm, fyi', unread: 0 },
    { id: 'dp', initials: 'DP', online: false, name: 'Devon Palmer', when: 'Mon', preview: 'Thanks for covering my break, appreciate it', unread: 0 },
  ],
} as const

/** guard-app-14 · Messages (a thread) */
export const threadFixture = {
  title: 'Owen Grant · Ops Manager',
  presence: '● Online',
  placeholder: 'Message Owen Grant…',
  day: 'Today',
  rows: [
    { id: 'a', mine: false, body: "Morning Marcus — heads up, contractor's coming for the Gate 2 barrier sensor around 10am. Let them through, no pass needed.", time: null, waiting: false },
    { id: 'b', mine: true, body: 'Copy that, will brief the Service Gate too.', time: '9:15 AM', waiting: false },
    { id: 'c', mine: false, body: 'Also — new standing orders posted for Main Gate, please acknowledge before your next round.', time: null, waiting: false },
    { id: 'd', mine: true, body: 'Acknowledged. Noticed the grey pickup I flagged earlier is gone now.', time: 'Waiting to send', waiting: true },
  ],
} as const

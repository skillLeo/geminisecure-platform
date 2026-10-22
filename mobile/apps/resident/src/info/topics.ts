/**
 * EVERY `i` IN THE RESIDENT APP, ONE FILE, ONE KEY PER TOPIC (M32 F12).
 *
 * What each feature is and what it is for, in two or three sentences, and what happens next where that helps — not a
 * manual. A sentence is corrected here and nowhere else; a screen names its topic by key (`<InfoButton topic={INFO.dues}
 * />`). Every key is opened and closed by `info/__tests__/topics.test.tsx`.
 *
 * No panic topic: the rebuilt panic screen draws the client's board, which has no `i` (M42 §5 #5) — and its "you can
 * cancel it" would no longer be true there ("Only a responder can close this alert"). Nor inviteVisitor, dues,
 * household or maintenance: Invite visitor, Dues & payments, Profile and New maintenance request are rebuilt from boards
 * that draw no `i` (M42 §5 #6, #8, #28, #10). Nor visitorPass, amenities or voting: the pass screens, the amenity
 * screens and the ballots were rebuilt with none, and no title carries one (M47 U3). My e-Pass says its one line on the
 * screen itself (the user, 9 Oct 2026: no link, no sheet).
 */

import type { InfoTopic } from '@gs/core'

export const INFO = {
  deleteAccount: {
    title: 'Deleting your account',
    lines: [
      'Deleting your account stops it at once: sign-in, your e-Pass, visitor passes, bookings and notifications.',
      'For a while you can restore it by signing in again: the screen says until when. After that nothing of you is left on it.',
    ],
    next: 'What your home owes, its gate log and its votes stay with the home. They are not deleted with you.',
  },
} satisfies Record<string, InfoTopic>

export type InfoKey = keyof typeof INFO

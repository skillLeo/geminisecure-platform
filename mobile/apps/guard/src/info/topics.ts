/**
 * EVERY `i` IN THE GUARD APP, ONE FILE, ONE KEY PER TOPIC (M32 F12).
 *
 * What each feature is and what it is for, in two or three sentences a guard can read at a gate, and what happens
 * next where that helps. A sentence is corrected here and nowhere else; a screen names its topic by key
 * (`<InfoButton tone="guard" topic={INFO.clockIn} />`). Every key is opened and closed by
 * `info/__tests__/topics.test.tsx`.
 */

import type { InfoTopic } from '@gs/core'

export const INFO = {
  clockIn: {
    title: 'Clocking in, and the circle round your post',
    lines: [
      'Clock in at your post with your fingerprint. The app knows which post and which shift.',
      'Your post has a circle on the map. Inside it you clock in straight away; outside it, a supervisor\'s PIN lets you clock in and the office sees why.',
    ],
  },
  scan: {
    title: 'Scanning a visitor\'s pass',
    lines: [
      'Point the camera at the visitor\'s pass. The screen tells you to let them in or not, and why, and it works with no signal.',
      'Green: let them in. Red: do not: the pass has expired, was cancelled, or is not for this gate.',
    ],
    next: 'Every scan is kept in the gate log. The resident is told when their guest arrives.',
  },
  checkpoints: {
    title: 'Checkpoints',
    lines: ['Scan the tags on your patrol so the office can see you passed them.', 'Each tag has its place on the round; the office sees which were scanned and when.'],
  },
  incident: {
    title: 'Reporting an incident',
    lines: [
      'Speak it, add a photo, send. What you say is typed out for you: read it before you send it.',
      'It saves on the phone even with no signal and goes as soon as you have one.',
    ],
  },
  duress: {
    title: 'The duress alert',
    lines: [
      'Tap the shield to open the duress screen, and hold its button to raise the alert: the control room and the other officers on duty are rung at once.',
      'To raise it silently, rest your thumb on the shield for two seconds instead. Nothing on the screen or from the speaker shows that it went.',
    ],
    next: 'The control room answers it and sends help. With no signal it waits on the phone and goes the moment there is one. A mistake can be cancelled on the duress screen.',
  },
  vitality: {
    title: 'Alertness check-ins',
    lines: [
      'Now and then during a shift the control room asks this phone to check you are alert and on post. You have two minutes to tap "I\'m here".',
      'Only the tap and its time are sent. A check you miss is recorded as missed, and your supervisor sees it.',
    ],
  },
  offline: {
    title: 'No signal, and what is waiting to send',
    lines: [
      'With no signal, what you do is kept on the phone, clock-ins, scans, reports and alerts, and sent as soon as there is one.',
      'The bar at the top says how many are waiting. Nothing waiting is thrown away.',
    ],
  },
  payslip: {
    title: 'Your payslip',
    lines: [
      'What you were paid for each pay period: what you earned, the deductions, NIS, NHT, education tax and PAYE, and what was paid to you.',
      'Questions about a payslip go to the office.',
    ],
  },
} satisfies Record<string, InfoTopic>

export type InfoKey = keyof typeof INFO

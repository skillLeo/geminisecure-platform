/**
 * The duress board's own data (guard-app-13 · "Alert sent"), served only in web fidelity mode.
 *
 * The board draws a named acknowledger, a named coordinator and "live location broadcasting every
 * 5s". The server names nobody and keeps no guard position at all, so none of that exists outside
 * this file: the live screen shows the timeline the server reports and says the position went WITH
 * the alert, once.
 */

export const duressFixture = {
  chip: 'AUDIBLE · GENERAL DURESS',
  sentLine: 'Main Gate · Sent 9:41 AM',
  steps: [
    { title: 'Alert sent to dispatch', detail: '9:41:02 AM', done: true },
    { title: 'Owen Grant acknowledged', detail: '9 seconds later', done: true },
    { title: 'Response unit dispatched', detail: 'Live location broadcasting', done: false },
  ],
  radarLabel: 'Broadcasting every 5s',
  coordinator: { initials: 'OG', title: 'Owen Grant is coordinating', detail: 'Operations Manager · on the line with dispatch' },
} as const

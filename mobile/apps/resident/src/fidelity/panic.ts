/**
 * The panic board's own content (resident-app-06 · "Alert sent"), served by `usePanic` in fidelity mode only — the
 * screen draws whatever board the hook hands it and never asks which build it is in.
 *
 * The board draws things the server does not have — a named guard, an ETA, live location, a message from dispatch,
 * live audio. They exist here and nowhere else: on a phone the same slots carry what the server actually recorded
 * (`panicBoard.ts`), the audio switch is off, and the message box says plainly that it cannot reach dispatch.
 */

import type { PanicBoard } from '@/features/panic/panicBoard'

export const panicFixture: PanicBoard = {
  title: 'Alert sent',
  meta: 'General emergency · Sent 9:41 AM',
  steps: [
    { title: 'Alert sent to gatehouse & dispatch', detail: '9:41:02 AM', done: true },
    { title: 'Guard Marcus Whyte acknowledged', detail: '14 seconds later', done: true },
    { title: 'On the way — ETA 2 min', detail: 'Live location shared', done: false },
  ],
  bubble: { from: 'Dispatch:', text: 'Stay on the line, Guard Whyte is 2 minutes out.' },
  audioOn: true,
}

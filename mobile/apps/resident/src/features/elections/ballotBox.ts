/**
 * THE MARKS ARE THE VOTE — and this is the only place they exist.
 *
 * A module-level variable: memory, and nothing else. It is never written to disk, never put in the
 * redux store, never in a URL or a route param, never in an error report, never printed. It is
 * carried between the three marking screens (page A → page B → review) because a URL or a store
 * would outlive them. It is WIPED:
 *   - the moment the paper is handed to the server (`take()` — whatever the answer turns out to be),
 *   - when the last marking screen leaves the stack (`leave()`),
 *   - when the app goes to the background (the screens call `wipe('background')`),
 *   - when the server's answer stops being "this household may vote now" — not eligible, already
 *     voted, poll closed, no such election (`wipe('gate-shut')`): no paper is drawn then, and no
 *     marks are kept behind the holding screen either,
 *   - when a different election is opened (`open()`).
 *
 * The receipt and results screens do not import this file (a test holds them to it).
 */

import { toggleMark, type Marks, type PaperPosition } from './paper'

export type WipeCause = 'sent' | 'left' | 'background' | 'other-election' | 'gate-shut'

const EMPTY: Marks = Object.freeze({})

let election: string | null = null
let marks: Marks = EMPTY
let lastWipe: WipeCause | null = null
let present = 0
const listeners = new Set<() => void>()

const tell = () => listeners.forEach((listener) => listener())

function wipe(cause: WipeCause): void {
  const had = Object.keys(marks).length > 0
  marks = EMPTY
  // Only a wipe that actually took something away is worth telling the person about.
  lastWipe = had ? cause : lastWipe
  tell()
}

export const ballotBox = {
  /** Start (or continue) marking this election's paper. Another election's marks never carry over. */
  open(electionId: string): void {
    if (election !== null && election !== electionId) wipe('other-election')
    if (election !== electionId) lastWipe = null
    election = electionId
  },

  /** Stable between changes, so React can subscribe to it. */
  marks: (): Marks => marks,
  isEmpty: (): boolean => Object.keys(marks).length === 0,

  /** One tap. Returns 'full' when the position already has as many marks as it has seats. */
  mark(position: Pick<PaperPosition, 'key' | 'seats' | 'options'>, optionId: number): 'full' | null {
    const result = toggleMark(marks, position, optionId)
    if (result.marks !== marks) {
      marks = result.marks
      lastWipe = null
      tell()
    }
    return result.refused
  },

  /** Hand the marks over for the ONE send and forget them in the same breath. */
  take(): Marks {
    const handed = marks
    wipe('sent')
    return handed
  },

  wipe,

  /** Why the box was last emptied while it held marks — so the screen can say so honestly. */
  lastWipe: (): WipeCause | null => lastWipe,

  /** A marking screen is on the stack. */
  enter(): void {
    present += 1
  },
  /** A marking screen left the stack; when none is left, the flow has been left and the marks go. */
  leave(): void {
    present = Math.max(0, present - 1)
    if (present === 0) {
      wipe('left')
      election = null
      lastWipe = null
    }
  },

  subscribe(listener: () => void): () => void {
    listeners.add(listener)
    return () => {
      listeners.delete(listener)
    }
  },
}

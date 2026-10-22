/**
 * HOW FAR BELOW THE PHONE'S OWN STATUS BAR SOMETHING STARTS (M57 §2: "the header sits under the status bar and the
 * camera cutout"). A board measures from its own 54-point status bar, which is taller than a phone's, so a board's
 * "6 below it" left a button 6 points under a real phone's clock and camera. Live, the figure is never less than the
 * spacing scale's safe gap (`layout.safeGap`); the fidelity build keeps the board's own figure, so the design walk still
 * compares like with like. Always added to the device's inset, `insets.top + belowStatusBar(6)`, never to a fixed
 * status-bar height: a notch, a punch-hole and a flat top each report their own.
 */

import { isFidelity } from '../fidelity'
import { layout } from '../theme/spacing'

export const belowStatusBar = (boardGap: number): number => (isFidelity ? boardGap : Math.max(boardGap, layout.safeGap))

/**
 * HOW FAR ABOVE THE PHONE'S GESTURE BAR THE LAST THING ON A PAGE ENDS (M57 §2). `Math.max(20, insets.bottom)` left a
 * page's last button touching the gesture bar on any phone whose bottom inset is over 20. Live: at least the board's own
 * figure, and never closer to the device's bottom inset than the safe gap. The fidelity build keeps the board's
 * `max(gap, inset)`.
 */
export const aboveGestureBar = (boardGap: number, bottomInset: number): number =>
  isFidelity ? Math.max(boardGap, bottomInset) : Math.max(boardGap, bottomInset + layout.safeGap)

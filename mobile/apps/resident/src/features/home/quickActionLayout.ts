/**
 * THREE QUICK ACTIONS TO A ROW, ON EVERY PHONE — NOT ONLY ON THE BOARD'S OWN WIDTH.
 *
 * Board resident-app-05 draws six tiles as 3 × 2, and the grid was written as a percentage of what
 * the board leaves: 31.25% of (397 − 36 side padding − 72 kept clear of the panic button) = 90.31.
 * A percentage looks width-independent and is not, because the GAPS are not a percentage:
 *
 *     three tiles + two 9-point gaps ≤ the room    ⇔    0.9375 × room + 18 ≤ room    ⇔    room ≥ 288
 *
 * The board leaves 289. A 360-point phone leaves 252, so the third tile wrapped and Home drew 2 × 3
 * with a hole down the right-hand side — the same fault the Guard App's Home had, found on the
 * client's own Samsung on 2026-09-22, put beside the board with the question why the two
 * differed.
 *
 * So the tile is MEASURED: the most there is room for, capped, and never below the 48-point icon
 * tile it has to hold.
 *
 * TWO LAYOUTS (M12 §2.3). The panic button has left the grid's corner for the top of the header
 * (§2.2), so the live grid has the whole width: two rows of three, widened and evenly spaced. The
 * BOARD's layout — the right 72 kept clear, the board's own 90.31 tile — is what the fidelity
 * harness draws, so it still measures the board it was built from.
 *
 * Pure, so the arithmetic is tested without a renderer. The Guard App has its own copy with its own
 * numbers (`apps/guard/src/features/dashboard/quickActionLayout.ts`) — the two apps are separate
 * products with separate boards, and sharing one constant would tie their layouts together.
 */

import { BOARD_WIDTH } from '@/board'

export { BOARD_WIDTH }

export type QaLayout = 'board' | 'live'

/** The body's two side paddings and two 9-point gaps — and, on the board, the 72 kept clear of the panic button. */
export const QA_GUTTERS: Record<QaLayout, number> = { board: 36 + 72 + 18, live: 36 + 18 }

/** The widest a tile is drawn: the board's own (31.25% of the 289 it leaves), or, live, wide enough to read without sprawling on a tablet. */
export const QA_MAX: Record<QaLayout, number> = { board: 90.3125, live: 116 }

/** The icon tile inside it. Below this the tile could not hold its own contents. */
export const QA_MIN = 48

export function quickActionWidth(screenWidth: number, layout: QaLayout = 'live'): number {
  if (!Number.isFinite(screenWidth) || screenWidth <= 0) return QA_MAX[layout] // a width nobody measured is not a reason to redraw the grid
  return Math.max(QA_MIN, Math.min(QA_MAX[layout], (screenWidth - QA_GUTTERS[layout]) / 3))
}

/** How many tiles actually fit a row at this width — 3 on every phone this app supports. */
export function quickActionsPerRow(screenWidth: number, layout: QaLayout = 'live'): number {
  const tile = quickActionWidth(screenWidth, layout)
  const room = screenWidth - QA_GUTTERS[layout] + 18 // the gaps are counted per gap below, not inside the gutters
  return Math.max(1, Math.floor((room + 9) / (tile + 9)))
}

/**
 * THREE QUICK ACTIONS TO A ROW, ON EVERY PHONE — NOT ONLY ON THE BOARD'S OWN WIDTH.
 *
 * The boards are drawn 397 points wide, and the tile width lifted from board guard-app-03 (89) fits
 * three across with exactly nothing to spare:
 *
 *     397 − 40 (the body's side padding) − 70 (kept clear of the duress button) − 20 (two gaps) = 267 = 3 × 89
 *
 * So on any phone NARROWER than 397 the third tile wrapped and Home drew a 2 × 3 grid instead of the
 * board's 3 × 2. A great many Android handsets are 360 points wide, and any phone whose owner has
 * turned Display size up is narrower still. Found on the client's own Samsung, 2026-09-22, by putting
 * the phone beside board guard-app-03 — the first thing he asked was why it did not look like the design.
 *
 * So the tile is MEASURED, never assumed: the most there is room for, capped, and never below the 56-point icon tile
 * it has to hold.
 *
 * TWO LAYOUTS, as the Resident App's (the client, 27 Sep: "make these quick buttons on the guard perfectly as the
 * resident — centred"). LIVE: the whole width, rows centred, the tiles widened and evenly spaced — the duress button
 * floats over the scroll's own clear space at the foot instead of eating a column of the grid. BOARD: the right 70
 * kept clear and the board's own 89 tile, which is what the fidelity harness draws, so it still measures the board.
 *
 * Pure, so the arithmetic is tested without a renderer.
 */

export type QaLayout = 'board' | 'live'

/** What the grid cannot use: the body's two side paddings and two gaps — and, on the board, the 70 kept clear of the duress button. */
export const QA_GUTTERS: Record<QaLayout, number> = { board: 40 + 70 + 20, live: 40 + 20 }

/** The widest a tile is drawn: the board's own, or, live, wide enough to read without sprawling on a tablet (the Resident App's). */
export const QA_MAX: Record<QaLayout, number> = { board: 89, live: 116 }

/** The icon tile inside it. Below this the tile could not hold its own contents. */
export const QA_MIN = 56

/** The board's frame, where the board layout returns exactly QA_MAX.board. */
export const BOARD_WIDTH = 397

export function quickActionWidth(screenWidth: number, layout: QaLayout = 'board'): number {
  if (!Number.isFinite(screenWidth) || screenWidth <= 0) return QA_MAX[layout] // a width nobody measured is not a reason to redraw the grid
  return Math.max(QA_MIN, Math.min(QA_MAX[layout], Math.floor((screenWidth - QA_GUTTERS[layout]) / 3)))
}

/** How many tiles actually fit a row at this width — 3 on every phone this app supports. */
export function quickActionsPerRow(screenWidth: number, layout: QaLayout = 'board'): number {
  const tile = quickActionWidth(screenWidth, layout)
  const room = screenWidth - QA_GUTTERS[layout] + 20 // the gaps are counted per gap below, not per tile
  return Math.max(1, Math.floor((room + 10) / (tile + 10)))
}

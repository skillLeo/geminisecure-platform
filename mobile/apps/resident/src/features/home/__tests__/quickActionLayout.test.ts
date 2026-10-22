/**
 * HOME'S QUICK ACTIONS ARE THREE TO A ROW ON EVERY PHONE.
 *
 * Board resident-app-05 draws six tiles as 3 × 2. The grid was a percentage of the room the board
 * leaves, which scales the tiles and not the 9-point gaps, so a 360-point phone drew 2 × 3 with
 * "Notices" pushed a row down and a hole down the right-hand side. Found beside the board on the
 * client's own phone, 2026-09-22.
 *
 * Since M12 §2.2–2.3 the panic button is at the top of the header, and the live grid takes the whole
 * width; the board's layout is kept for the fidelity harness.
 */

import { BOARD_WIDTH, QA_GUTTERS, QA_MAX, QA_MIN, quickActionWidth, quickActionsPerRow, type QaLayout } from '../quickActionLayout'

/** Points wide, as React Native reports them. */
const PHONES: [string, number][] = [
  ['a small Android (display size turned up)', 320],
  ['the commonest Android width', 360],
  ['iPhone 12–15', 390],
  ['the board’s own frame', BOARD_WIDTH],
  ['Pixel 6/7/8', 412],
  ['iPhone Pro Max', 430],
]
const LAYOUTS: QaLayout[] = ['live', 'board']

describe('the quick-action grid keeps three columns on every phone', () => {
  it.each(LAYOUTS.flatMap((layout) => PHONES.map(([name, width]) => [layout, name, width] as const)))('%s · %s (%i pt): three tiles to a row', (layout, _name, width) => {
    expect(quickActionsPerRow(width, layout)).toBe(3)
  })

  it('is EXACTLY the board’s tile at the board’s width in the board layout: the fidelity harness must still see the board', () => {
    expect(quickActionWidth(BOARD_WIDTH, 'board')).toBeCloseTo(QA_MAX.board, 4)
  })

  it('live, with the panic button gone from the corner, the three tiles and two gaps fill the whole row on a phone', () => {
    for (const width of [320, 360, 390, BOARD_WIDTH]) {
      expect(3 * quickActionWidth(width) + 2 * 9).toBeCloseTo(width - 36, 4)
    }
    expect(quickActionWidth(360)).toBeGreaterThan(quickActionWidth(360, 'board'))
  })

  it('never draws a tile wider than its cap, however big the screen', () => {
    for (const layout of LAYOUTS) for (const width of [600, 834, 1024, 1366]) expect(quickActionWidth(width, layout)).toBeCloseTo(QA_MAX[layout], 4)
  })

  it('never shrinks a tile below the icon it has to hold', () => {
    for (const layout of LAYOUTS) for (const width of [200, 240, 280, 300]) expect(quickActionWidth(width, layout)).toBeGreaterThanOrEqual(QA_MIN)
  })

  it('the tiles and their gaps actually fit the room they are given', () => {
    for (const layout of LAYOUTS) {
      for (const [, width] of PHONES) {
        const room = width - QA_GUTTERS[layout] + 18
        expect(3 * quickActionWidth(width, layout) + 2 * 9).toBeLessThanOrEqual(room + 0.001)
      }
    }
  })

  it('a width nobody could measure is not a reason to redraw the grid', () => {
    for (const layout of LAYOUTS) for (const bad of [0, -1, Number.NaN, Number.POSITIVE_INFINITY]) expect(quickActionWidth(bad, layout)).toBe(QA_MAX[layout])
  })
})

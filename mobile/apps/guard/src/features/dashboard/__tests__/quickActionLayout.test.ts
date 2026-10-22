/**
 * THE HOME SCREEN'S QUICK ACTIONS ARE THREE TO A ROW ON EVERY PHONE.
 *
 * Board guard-app-03 draws six tiles as 3 × 2. The tile width was lifted from the board as a fixed
 * 89, which fits three across at the board's own 397 points and NOT on a 360-point handset — so the
 * client's Samsung drew 2 × 3 and he asked, with the board beside it, why his phone looked different.
 * This pins the arithmetic at the widths real phones actually have.
 */

import { BOARD_WIDTH, QA_MAX, QA_MIN, quickActionWidth, quickActionsPerRow } from '../quickActionLayout'

/** Points wide, as React Native reports them. */
const PHONES: [string, number][] = [
  ['a small Android (Galaxy A-series, display size up)', 320],
  ['the commonest Android width (Galaxy S/A, Redmi)', 360],
  ['iPhone 12–15', 390],
  ['the board’s own frame', BOARD_WIDTH],
  ['Pixel 6/7/8', 412],
  ['iPhone Pro Max', 430],
]

describe('the quick-action grid keeps the board’s three columns on every phone', () => {
  it.each(PHONES)('%s (%i pt): three tiles to a row', (_name, width) => {
    expect(quickActionsPerRow(width)).toBe(3)
  })

  it('is EXACTLY the board’s tile at the board’s width: the fidelity harness must still see the board', () => {
    expect(quickActionWidth(BOARD_WIDTH)).toBe(QA_MAX.board)
  })

  it('never draws a tile wider than the board’s, however big the screen', () => {
    for (const width of [430, 600, 834, 1024, 1366]) expect(quickActionWidth(width)).toBe(QA_MAX.board)
  })

  it('never shrinks a tile below the icon it has to hold', () => {
    for (const width of [200, 240, 280, 300]) expect(quickActionWidth(width)).toBeGreaterThanOrEqual(QA_MIN)
  })

  it('the tiles and their gaps actually fit the room they are given', () => {
    for (const [, width] of PHONES) {
      const room = width - 40 - 70 // the body’s side padding, and the space kept clear of the duress button
      expect(3 * quickActionWidth(width) + 2 * 10).toBeLessThanOrEqual(room)
    }
  })

  it('a width nobody could measure is not a reason to redraw the grid', () => {
    for (const bad of [0, -1, Number.NaN, Number.POSITIVE_INFINITY]) expect(quickActionWidth(bad)).toBe(QA_MAX.board)
  })
})

/*
 * LIVE, CENTRED, AS THE RESIDENT APP'S (the client, 27 Sep: "make these quick buttons on the guard perfectly as the
 * resident — centred"). The whole width is used — the duress button floats over the scroll's clear foot instead of
 * eating a column — so the tiles are wider, and three of them with their two gaps are centred with equal room each side.
 */
describe('the live grid is centred and uses the whole width', () => {
  it.each(PHONES)('%s (%i pt): three tiles to a row', (_name, width) => {
    expect(quickActionsPerRow(width, 'live')).toBe(3)
  })

  it('is wider than the board’s tile on every phone the board’s tile fits', () => {
    for (const [, width] of PHONES) expect(quickActionWidth(width, 'live')).toBeGreaterThan(quickActionWidth(width, 'board'))
  })

  it('fits the body with nothing kept clear on the right: equal room each side, so the row is centred', () => {
    for (const [, width] of PHONES) {
      const room = width - 40
      const used = 3 * quickActionWidth(width, 'live') + 2 * 10
      expect(used).toBeLessThanOrEqual(room)
      // Whatever is left over is split evenly by the centred row — never more than a tile's width of slack.
      expect(room - used).toBeLessThan(QA_MAX.live)
    }
  })

  it('stops at the Resident App’s widest tile on a tablet', () => {
    for (const width of [600, 834, 1024]) expect(quickActionWidth(width, 'live')).toBe(QA_MAX.live)
  })
})

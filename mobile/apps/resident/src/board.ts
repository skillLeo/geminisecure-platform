/**
 * THE WIDTH THE BOARDS WERE DRAWN AT.
 *
 * Every Resident App board is 397 points wide, and the fidelity harness renders each screen at
 * exactly that width to compare it with the board. Dimensions lifted from a board are therefore
 * true at 397 and nowhere else by right — a screen that only fits at 397 is a screen that breaks on
 * a 360-point Android, which is the commonest phone this product will run on.
 *
 * So a layout that cannot hold at a narrower width measures itself against this number: identical to
 * the board at the board's width, and rearranged below it. Never used to LOCK a layout to 397.
 */
export const BOARD_WIDTH = 397

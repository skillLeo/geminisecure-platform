/**
 * The marks exist in memory only, and they are wiped: when the paper is handed over, when the last
 * marking screen leaves, when the app goes to the background, when another election is opened.
 */
import { ballotBox } from '../ballotBox'
import { receiptNote } from '../receiptNote'
import type { PaperPosition } from '../paper'

const chairman: PaperPosition = { key: 'p1', name: 'Chairman', seats: 1, options: [{ id: 101, label: 'Dwayne Robinson' }, { id: 102, label: 'Sonia Campbell' }] }
const vice: PaperPosition = { key: 'p2', name: 'Vice Chairman', seats: 2, options: [{ id: 111, label: 'Michelle Palmer' }, { id: 112, label: 'Andre Thompson' }, { id: 113, label: 'Rachel Bennett' }] }

beforeEach(() => {
  ballotBox.wipe('left')
  ballotBox.open('5')
})

describe('the ballot box (memory only)', () => {
  it('holds marks, tells its subscribers, and keeps a stable snapshot between changes', () => {
    const heard = jest.fn()
    const stop = ballotBox.subscribe(heard)
    expect(ballotBox.isEmpty()).toBe(true)
    expect(ballotBox.mark(chairman, 101)).toBeNull()
    expect(ballotBox.marks()).toEqual({ p1: [101] })
    expect(ballotBox.marks()).toBe(ballotBox.marks())
    expect(heard).toHaveBeenCalledTimes(1)
    stop()
    ballotBox.mark(chairman, 102)
    expect(heard).toHaveBeenCalledTimes(1)
  })

  it('refuses a mark past the seats and changes nothing', () => {
    ballotBox.mark(vice, 111)
    ballotBox.mark(vice, 112)
    const before = ballotBox.marks()
    expect(ballotBox.mark(vice, 113)).toBe('full')
    expect(ballotBox.marks()).toBe(before)
  })

  it('take() hands the marks over ONCE and forgets them in the same breath', () => {
    ballotBox.mark(chairman, 101)
    expect(ballotBox.take()).toEqual({ p1: [101] })
    expect(ballotBox.isEmpty()).toBe(true)
    expect(ballotBox.take()).toEqual({})
    expect(ballotBox.lastWipe()).toBe('sent')
  })

  it('is wiped when the app goes to the background, and says why', () => {
    ballotBox.mark(chairman, 101)
    ballotBox.wipe('background')
    expect(ballotBox.marks()).toEqual({})
    expect(ballotBox.lastWipe()).toBe('background')
    // Marking again clears the note.
    ballotBox.mark(chairman, 102)
    expect(ballotBox.lastWipe()).toBeNull()
  })

  it('is wiped when the gate shuts, and a shut gate AFTER a send does not overwrite why the box is empty', () => {
    ballotBox.mark(chairman, 101)
    ballotBox.wipe('gate-shut')
    expect(ballotBox.isEmpty()).toBe(true)
    expect(ballotBox.lastWipe()).toBe('gate-shut')
    // The paper was handed over, the answer was lost, then the server said "poll closed": the box was already
    // empty, so the reason stays "sent" — that is what the review has to explain.
    ballotBox.mark(chairman, 101)
    ballotBox.take()
    ballotBox.wipe('gate-shut')
    expect(ballotBox.lastWipe()).toBe('sent')
  })

  it('is wiped when the LAST marking screen leaves, not before', () => {
    ballotBox.enter() // page A
    ballotBox.enter() // page B
    ballotBox.mark(chairman, 101)
    ballotBox.leave() // back from page B
    expect(ballotBox.marks()).toEqual({ p1: [101] })
    ballotBox.leave() // out of the flow
    expect(ballotBox.isEmpty()).toBe(true)
  })

  it('never carries one election’s marks into another', () => {
    ballotBox.mark(chairman, 101)
    ballotBox.open('5')
    expect(ballotBox.marks()).toEqual({ p1: [101] })
    ballotBox.open('6')
    expect(ballotBox.isEmpty()).toBe(true)
  })
})

describe('the receipt note holds the server’s answer and nothing else', () => {
  it('is read only for the ballot it was written for, and can be forgotten', () => {
    receiptNote.set({ ballot: '5', votedOn: '2026-09-19' })
    expect(receiptNote.for('5')).toEqual({ ballot: '5', votedOn: '2026-09-19' })
    expect(receiptNote.for('6')).toBeNull()
    expect(receiptNote.for(undefined)).toBeNull()
    receiptNote.clear()
    expect(receiptNote.for('5')).toBeNull()
  })

  it('has no field that could carry a choice', () => {
    receiptNote.set({ ballot: '5', votedOn: null })
    expect(Object.keys(receiptNote.for('5') ?? {}).sort()).toEqual(['ballot', 'votedOn'])
    receiptNote.clear()
  })
})

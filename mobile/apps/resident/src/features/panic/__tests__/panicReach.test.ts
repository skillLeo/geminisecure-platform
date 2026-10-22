/**
 * THE PANIC SCREEN NEVER SAYS "HELP IS COMING" WHEN IT IS NOT.
 *
 * `alertReach` (packages/core/safety) decides the words and has its own tests. What is held
 * HERE is the wiring, which is the part that rots: a screen that stops asking, a promise that
 * creeps back into the copy, a 119 control that loses its prominence in a refactor. None of
 * those would fail a test of the pure function, and none would be noticed until it mattered.
 *
 * Source-read, like `demoWrites.test.ts`, and for the same reason: the guarantee is about what
 * the file contains, not about what one render happened to produce.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { alertReach } from '@gs/core/safety'

const read = (file: string) => readFileSync(join(__dirname, '..', file), 'utf8')

const screen = read(join('screens', 'PanicScreen.tsx'))
const hook = read('usePanic.ts')

/*
 * READ OUT OF THE SOURCE, not imported. `usePanic` pulls in the whole app (`@gs/core`'s root,
 * and through it Reanimated), which a Jest suite cannot load; and the point of this file is what
 * the source SAYS in any case.
 */
// Declared once, in panicRecord.ts (the Log In screen names it too since B118), and re-exported by the hook.
const EMERGENCY_NUMBER = read('panicRecord.ts').match(/export const EMERGENCY_NUMBER = '(\d+)'/)?.[1] ?? ''

/** Anything that would tell a frightened person somebody is on their way. */
const PROMISES = /help is coming|help is on the way|someone is on (their|the) way|an officer is coming|is responding now/i

describe('the hook asks the server who it reached, and keeps asking', () => {
  it('puts the server\'s own count into words in one place', () => {
    expect(hook).toContain('alertReach(')
    expect(hook).toContain('reach,')
    // The number the sentence names and the number the button dials are the same constant.
    expect(hook).toContain('alertReach(a, EMERGENCY_NUMBER)')
  })

  it('re-reads the count while the alert is open, so "reaching" can become an answer', () => {
    // The fan-out finishes a moment after the press; the poll is what turns "still reaching"
    // into a number. Left out of the comparison, the screen would sit on "reaching" for ever.
    // `answerMoved` (packages/core/safety) compares it — and every step — and is tested there.
    expect(hook).toMatch(/if \(live\.data && saved\?\.alert && String\(\(live\.data as AlertAnswer\)\.id\) === String\(saved\.alert\.id\) && answerMoved\(saved\.alert, live\.data as AlertAnswer\)\)/)
  })

  it('takes only THIS alert\'s answer, never the last one the query held (M23)', () => {
    // A second press after a closed alert: the query still hands back the closed one while the new id is fetched. Merged,
    // it replaced the live alert on the phone with the closed one, and the screen said "closed by the control room".
    const merge = hook.slice(hook.indexOf('/* ---- read it back while it is open ---- */'), hook.indexOf('/* ---- the cancel window'))
    expect(merge).toContain('String((live.data as AlertAnswer).id) === String(saved.alert.id)')
    expect(merge.indexOf('String((live.data as AlertAnswer).id)')).toBeLessThan(merge.indexOf('remember('))
  })

  it('hands the timeline the truth too, so it stops naming the gatehouse when none was reached', () => {
    // Every time it carries is the estate's, tagged (M19A §4.2).
    expect(hook).toMatch(/alertSteps\(a, withClockTag\(time12s\(a\.server_time\)\), secondsToAck, reach\.reachedNobody, \(iso\) => withClockTag\(time12s\(iso\)\)\)/)
  })

  it('builds the screen after the press from the one set of words, handed the server\'s reach (M18A)', () => {
    expect(hook).toMatch(/help: a && saved\s*\? helpScreen\(a, reach, \{/)
  })

  it('says where the response stands in the server\'s words, and a drill as a drill', () => {
    expect(hook).toMatch(/headline: a \? responseHeadline\(a\) : null/)
    expect(hook).toMatch(/drill: isDrill\(a\?\.is_drill\)/)
  })
})

/*
 * M42 §5 #5. The rebuilt screen draws the client's board, and every word in its slots is the hook's `board`
 * (panicBoard.ts, tested in panicBoard.test.ts): the steps ticked only by the server's times, the first step naming
 * the gatehouse only when an officer's phone was reached, dispatch's message only once dispatch recorded something.
 * What is held here is that the screen draws those words and no others, and keeps 119 one tap away in every state.
 */
describe('the screen says it plainly, and keeps 119 one tap away', () => {
  const board = read('panicBoard.ts')

  it('draws the board the hook built, from the server’s reach, and nothing of its own', () => {
    expect(hook).toMatch(/panicBoard\(\{[\s\S]*?reach,[\s\S]*?\}\)/)
    expect(board).toMatch(/reachedNobody\s*\?\s*\{ title: WORDS\.stepSentDispatchOnly/)
    for (const slot of ['board.title', 'board.meta', 'board.steps.map(', 'board.bubble']) expect(screen).toContain(slot)
    // The audio switch is drawn as it truly stands (M50 §4.2 #1); the board's own value only in the design build.
    expect(screen).toContain('m.audio.on')
    expect(hook).toMatch(/on: isFidelity \? board\.audioOn :/)
  })

  it('says what changes as it changes, to a screen reader too', () => {
    expect(screen.match(/aria-live="polite"/g)?.length).toBeGreaterThanOrEqual(3)
  })

  it('dials the real number from one button in every state: before the press, held, sent and closed (invariant 13)', () => {
    expect(screen.match(/onPress=\{m\.callEmergency\}/g)?.length).toBe(1)
    // Not inside any branch: the call button follows the last state's branch, closed, and is drawn whatever the phase.
    expect(screen).toMatch(/\) : null\}\s*\{\/\* \.call-btn[^\n]*\*\/\}\s*<Pressable onPress=\{m\.callEmergency\}/)
  })

  it('keeps red for the emergency itself: the circle and its rings, nothing else (the client, M42 §5 #5)', () => {
    const reds = screen.match(/foundation\.colour\['red-\d+'\]/g) ?? []
    expect(reds).toEqual(["foundation.colour['red-700']", "foundation.colour['red-500']"])
    expect(screen).toMatch(/circle: \{[^}]*backgroundColor: ALERT_RED/)
    expect(screen.match(/borderColor: RING_RED/g)?.length).toBe(2)
    expect(screen).toMatch(/holdFill: \{[^}]*backgroundColor: RING_RED/)
    expect(screen).not.toMatch(/c\('red-|ALERT_RED[^\n]*(callBtn|card|toggleRow)/)
  })

  it('carries no promise of help anywhere in its own copy, nor in the board’s words', () => {
    // The COPY, not the comments: the docblock above quotes the rule it is obeying, and the
    // sentence a person reads is the only thing this is about.
    const copy = (screen + board).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

    expect(copy).not.toMatch(PROMISES)
  })

  it('never asks which build it is in: the fidelity board is swapped in by the hook, never drawn differently', () => {
    expect(screen).not.toMatch(/\bisFidelity\b/)
    expect(hook).toMatch(/const board: PanicBoard = isFidelity\s*\? panicFixture/)
  })
})

describe('the words the screen will actually show', () => {
  const base = { console_alerted: true, escalated_contacts: 0 }

  it('tell a resident at an estate with nobody on duty exactly where they stand', () => {
    const r = alertReach({ ...base, alerted_handsets: 0, alerted_via: 'none' }, EMERGENCY_NUMBER)

    expect(r.reachedNobody).toBe(true)
    expect(r.mayPromiseHelp).toBe(false)
    expect(r.detail).toContain(`Call ${EMERGENCY_NUMBER} now`)
    expect(`${r.headline} ${r.detail}`).not.toMatch(PROMISES)
  })

  it('never read a missing count as nobody: an alert saved by an older build is "still reaching"', () => {
    expect(alertReach({ ...base, alerted_handsets: null, alerted_via: null }, EMERGENCY_NUMBER).reachedNobody).toBe(false)
  })

  it('name the same emergency number the screen dials', () => {
    expect(EMERGENCY_NUMBER).toBe('119')
    expect(alertReach({ ...base, alerted_handsets: 0, alerted_via: 'none' }, EMERGENCY_NUMBER).detail).toContain('119')
  })
})

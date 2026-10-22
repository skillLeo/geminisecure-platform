/**
 * A DRILL SAYS SO FIRST (M10 §1.3) — and only a drill does.
 */
import { DRILL_LABEL, DRILL_NOTE, drillTitle, isDrill } from '../index'

describe('the drill label', () => {
  it('is DRILL, in front of the title, exactly as the server writes its own push titles', () => {
    expect(DRILL_LABEL).toBe('DRILL')
    expect(drillTitle('Panic alert', true)).toBe('DRILL: Panic alert')
    expect(drillTitle('Panic alert', true).startsWith(DRILL_LABEL)).toBe(true)
  })

  it('only a positive true is a drill: a missing or unknown flag says nothing either way', () => {
    for (const notDrill of [false, null, undefined, 'true', 1, {}]) {
      expect(isDrill(notDrill)).toBe(false)
      expect(drillTitle('Panic alert', notDrill)).toBe('Panic alert')
    }
    expect(isDrill(true)).toBe(true)
  })

  it('says what a drill is, and claims nothing about who is responding to it', () => {
    expect(DRILL_NOTE).toMatch(/drill/i)
    expect(DRILL_NOTE).not.toMatch(/help is|on the way|officer is coming|ignore/i)
  })
})

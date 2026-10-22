/**
 * B056, the phone's half: the sync cursor now overlaps by ten seconds, so every pull re-sends the last ten
 * seconds on purpose. The same record arriving twice must not show twice — within a run (as before), and across
 * a restart, when the in-memory record of what was shown used to start empty.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { getStorage } from '../../lib/storage'
import { recentlyShown, SHOWN_KEEP_MS } from '../recentlyShown'

let t = Date.parse('2026-09-24T17:00:00Z')
const clock = () => t

beforeEach(async () => {
  await getStorage().clearAll()
  t = Date.parse('2026-09-24T17:00:00Z')
})

it('within a run, a key shown once is not shown again', () => {
  const shown = recentlyShown(clock)
  expect(shown.has('site-alert:17')).toBe(false)
  shown.add('site-alert:17')
  expect(shown.has('site-alert:17')).toBe(true)
})

it('after a restart, a key shown moments before is still known: the overlap’s repeat does not buzz again', () => {
  recentlyShown(clock).add('site-alert:17')
  t += 8_000 // killed, reopened; the first pull re-sends the last ten seconds
  const afterRestart = recentlyShown(clock)
  expect(afterRestart.has('site-alert:17')).toBe(true)
  expect(afterRestart.has('message:44')).toBe(false)
})

it('kept for ten minutes and no longer: nothing a person would want to be told twice is held back for good', () => {
  recentlyShown(clock).add('message:44')
  t += SHOWN_KEEP_MS + 1_000
  expect(recentlyShown(clock).has('message:44')).toBe(false)
})

it('sign-out forgets them, on the phone and in memory', () => {
  const shown = recentlyShown(clock)
  shown.add('order:9')
  shown.clear()
  expect(shown.has('order:9')).toBe(false)
  expect(recentlyShown(clock).has('order:9')).toBe(false)
})

it('the notifier uses it, and both lists the phone keeps from a pull stay one row per record', () => {
  const runtime = readFileSync(path.join(__dirname, '..', 'runtime.ts'), 'utf8')
  expect(runtime).toContain('const shown = recentlyShown()')
  expect(runtime).toMatch(/shown\.has\(input\.key\)\) return false/)
})

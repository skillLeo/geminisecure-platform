/**
 * EVERY `i` OPENS ITS SHEET AND CLOSES CLEANLY (M32 F12, §14.3) — the Guard App's topics.
 *
 *   - each topic, through the shared `InfoButton` in the guard's tone, opens a sheet with its own words and closes on
 *     Close and on the backdrop;
 *   - the words are short: a title and two or three sentences;
 *   - every topic stands beside a screen's heading (read from the screens' source);
 *   - nothing stands between a guard and the duress shield or the alertness check: the duress `i` is with the
 *     shield's words, not on it, and the two-minute check itself carries none;
 *   - a fidelity build draws no `i`, and the bar adds it only in the live build — the screenshot checks are unchanged;
 *   - no sheet opens over the duress screen.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { InfoButton } from '@gs/core'
import { INFO, type InfoKey } from '../topics'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium', Heavy: 'heavy' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
let mockPath = '/(tabs)'
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, usePathname: () => mockPath, useFocusEffect: jest.fn() }))

jest.setTimeout(30_000)

const KEYS = Object.keys(INFO) as InfoKey[]
const SRC = path.join(__dirname, '..', '..')
const CORE = path.join(SRC, '..', '..', '..', 'packages', 'core', 'ui')
const read = (...parts: string[]) => readFileSync(path.join(SRC, ...parts), 'utf8')

function screens(dir = path.join(SRC, 'features')): string[] {
  return readdirSync(dir).flatMap((name) => {
    const file = path.join(dir, name)
    if (statSync(file).isDirectory()) return name === '__tests__' ? [] : screens(file)
    return file.endsWith('.tsx') ? [readFileSync(file, 'utf8')] : []
  })
}

beforeEach(() => {
  mockPath = '/(tabs)'
})

describe('each topic opens its sheet and closes cleanly', () => {
  it.each(KEYS)('%s', async (key) => {
    const topic = INFO[key]
    await render(<InfoButton topic={topic} tone="guard" />)

    expect(screen.queryByTestId('info-sheet')).toBeNull()
    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    expect(screen.getByTestId('info-sheet')).toBeTruthy()
    expect(screen.getByText(topic.title)).toBeTruthy()
    for (const line of topic.lines) expect(screen.getByText(line)).toBeTruthy()

    await act(async () => fireEvent.press(screen.getByLabelText('Close')))
    expect(screen.queryByTestId('info-sheet')).toBeNull()
  })

  it('closes on the backdrop too', async () => {
    await render(<InfoButton topic={INFO.scan} tone="guard" />)
    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    await act(async () => fireEvent(screen.getByTestId('info-sheet-backdrop', { includeHiddenElements: true }), 'responderRelease'))
    expect(screen.queryByTestId('info-sheet')).toBeNull()
  })

  it('never opens over the duress screen', async () => {
    mockPath = '/duress'
    await render(<InfoButton topic={INFO.duress} tone="guard" />)
    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    expect(screen.queryByTestId('info-sheet')).toBeNull()
  })
})

describe('the words are short, and true to the app', () => {
  it.each(KEYS)('%s: a title and two or three sentences', (key) => {
    const topic = INFO[key]
    expect(topic.title.length).toBeGreaterThan(0)
    expect(topic.lines.join(' ').split(/(?<=[.!?])\s+/).filter(Boolean).length).toBeLessThanOrEqual(4)
    for (const line of topic.lines) expect(line.length).toBeLessThanOrEqual(240)
  })

  it('says how the duress shield really works: a tap opens it, two seconds raise it silently', () => {
    expect(read('features', 'shift', 'components', 'DuressShield.tsx')).toContain('a thumb rested on it for two seconds raises the SILENT alert')
    expect(INFO.duress.lines.join(' ')).toMatch(/Tap the shield.*rest your thumb on the shield for two seconds/s)
  })

  // M33 §3: the one address a person is offered is the client's; the old mailbox on geminisl.com takes no mail.
  it('names no contact address but the client\'s gemisecja@gmail.com', () => {
    for (const key of KEYS) {
      const words = [INFO[key].title, ...INFO[key].lines, ('next' in INFO[key] ? String(INFO[key].next ?? '') : '')].join(' ')
      expect(words).not.toMatch(/geminisl\.com/i)
      for (const address of words.match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g) ?? []) expect(address.toLowerCase()).toBe('gemisecja@gmail.com')
    }
  })
})

describe('every topic is beside a heading, and nothing stands in front of an act', () => {
  it('places every topic on a screen', () => {
    const code = screens().join('\n')
    for (const key of KEYS) expect(code).toMatch(new RegExp(`INFO\\.${key}\\b`))
  })

  it('keeps the duress `i` with the shield\'s words, never on the shield', () => {
    const shield = read('features', 'shift', 'components', 'DuressShield.tsx')
    const words = shield.slice(shield.indexOf('<View style={s.words}>'), shield.indexOf('<Pressable onPress={open}'))
    expect(words).toContain('INFO.duress')
    expect(shield.slice(shield.indexOf('<Pressable onPress={open}'))).not.toContain('INFO.')
  })

  it('puts no `i` on the two-minute alertness check itself', () => {
    const alertness = read('features', 'alertness', 'screens', 'AlertnessScreen.tsx')
    expect(alertness.slice(alertness.indexOf('/* ── 4 · the check'))).not.toContain('INFO.')
  })

  it('draws nothing in a fidelity build, and the bar adds it only in the live build', () => {
    const button = readFileSync(path.join(CORE, 'InfoButton.tsx'), 'utf8')
    const bar = readFileSync(path.join(CORE, 'guard', 'AppBar.tsx'), 'utf8')
    expect(button).toMatch(/if \(isFidelity\) return null/)
    expect(bar).toContain('info && !isFidelity')
  })
})

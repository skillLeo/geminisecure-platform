/**
 * EVERY `i` OPENS ITS SHEET AND CLOSES CLEANLY (M32 F12, §14.3) — the Resident App's topics.
 *
 *   - each topic, rendered through the shared `InfoButton`, opens a sheet with its own words and closes on Close and
 *     on the backdrop, leaving nothing behind;
 *   - the words are short: a title and two or three sentences, and "what happens next" only where it helps;
 *   - every topic stands beside a screen's heading (read from the screens' source), so none is a key nobody shows;
 *   - no screen's primary action moves for it: the glyph is 16 with its touch area widened by hitSlop, the bars draw it
 *     beside the title only in the live build, and a fidelity build draws no `i` at all — so the screenshot checks are
 *     the boards' unchanged;
 *   - no sheet opens over the panic screen unless the screen opens it itself (`emergencyScreen`). The rebuilt panic
 *     screen draws the client's board, which has no `i` (M42 §5 #5), so it has no topic of its own.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { InfoButton, INFO_GLYPH, INFO_REACH, type InfoTopic } from '@gs/core'
import { INFO, type InfoKey } from '../topics'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
let mockPath = '/(tabs)'
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, usePathname: () => mockPath, useFocusEffect: jest.fn() }))

jest.setTimeout(30_000)

const KEYS = Object.keys(INFO) as InfoKey[]
const SRC = path.join(__dirname, '..', '..')
const CORE = path.join(SRC, '..', '..', '..', 'packages', 'core', 'ui')

/** Every .tsx under src/features, as text: where the topics are placed. */
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
    const topic: InfoTopic = INFO[key]
    await render(<InfoButton topic={topic} />)

    expect(screen.queryByTestId('info-sheet')).toBeNull()
    expect(screen.getByLabelText(`About ${topic.title}`)).toBeTruthy()

    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    expect(screen.getByTestId('info-sheet')).toBeTruthy()
    expect(screen.getByText(topic.title)).toBeTruthy()
    for (const line of topic.lines) expect(screen.getByText(line)).toBeTruthy()
    if (topic.next) expect(screen.getByText(topic.next)).toBeTruthy()

    await act(async () => fireEvent.press(screen.getByLabelText('Close')))
    expect(screen.queryByTestId('info-sheet')).toBeNull()
  })

  it('closes on the backdrop too, and opens again', async () => {
    await render(<InfoButton topic={INFO.deleteAccount} />)

    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    // The backdrop is no control — hidden from screen readers — so it is found as a hidden element.
    await act(async () => fireEvent(screen.getByTestId('info-sheet-backdrop', { includeHiddenElements: true }), 'responderRelease'))
    expect(screen.queryByTestId('info-sheet')).toBeNull()

    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    expect(screen.getByTestId('info-sheet')).toBeTruthy()
  })

  it('never opens over the panic screen', async () => {
    mockPath = '/panic'
    await render(<InfoButton topic={INFO.deleteAccount} />)
    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    expect(screen.queryByTestId('info-sheet')).toBeNull()
  })

  it('except one the emergency screen opens itself', async () => {
    mockPath = '/panic'
    await render(<InfoButton topic={INFO.deleteAccount} emergencyScreen />)
    await act(async () => fireEvent.press(screen.getByTestId('info-button')))
    expect(screen.getByTestId('info-sheet')).toBeTruthy()
  })
})

describe('the words are short, and they are true', () => {
  it.each(KEYS)('%s: a title and two or three sentences', (key) => {
    const topic = INFO[key]
    const sentences = topic.lines.join(' ').split(/(?<=[.!?])\s+/).filter(Boolean)

    expect(topic.title.length).toBeGreaterThan(0)
    expect(topic.lines.length).toBeGreaterThanOrEqual(1)
    expect(sentences.length).toBeLessThanOrEqual(4)
    for (const line of topic.lines) expect(line.length).toBeLessThanOrEqual(240)
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

describe('every topic is beside a heading, and nothing moves for it', () => {
  it('places every topic on a screen', () => {
    const code = screens().join('\n')
    for (const key of KEYS) expect(code).toMatch(new RegExp(`INFO\\.${key}\\b`))
  })

  it('is a 16 glyph with its touch area widened by hitSlop: 44 to a finger, nothing pushed aside', () => {
    expect(INFO_GLYPH).toBe(16)
    expect(INFO_GLYPH + 2 * INFO_REACH).toBe(44)
  })

  it('draws nothing in a fidelity build, and no header puts one beside its title (M47 U3)', () => {
    const button = readFileSync(path.join(CORE, 'InfoButton.tsx'), 'utf8')
    const bar = readFileSync(path.join(CORE, 'resident', 'ResidentAppBar.tsx'), 'utf8')

    expect(button).toMatch(/if \(isFidelity\) return null/)
    expect(button).toMatch(/if \(isFidelity\) return <>\{children\}<\/>/)
    expect(bar).not.toMatch(/<InfoButton/)
  })
})

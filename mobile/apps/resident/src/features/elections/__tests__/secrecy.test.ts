/**
 * THE BALLOT BODY IS THE VOTE. This test reads the feature's own source and proves, file by file,
 * that nothing in it could print, keep, queue or re-send one:
 *
 *   - no `console.` anywhere;
 *   - no storage of any kind (AsyncStorage, MMKV, SecureStore, the core storage engine, the
 *     secure cache, web storage) — the published-results cache in useResults.ts is the ONE
 *     allowed use of the offline cache, and that file cannot see the marks;
 *   - no `submit(` and nothing from the offline queue: a vote is sent once, online, by the
 *     generated mutation, and is never queued;
 *   - no hashing, no error reporting, no analytics;
 *   - the marks never travel in a route param;
 *   - the receipt and results never import the marks.
 *
 * Comments are stripped first, so the rules are held by the code and not by its prose.
 */
import { readdirSync, readFileSync, statSync } from 'fs'
import path from 'path'

const ROOT = path.resolve(__dirname, '..')
const FIXTURE = path.resolve(__dirname, '../../../fidelity/elections.ts')
const ROUTES = path.resolve(__dirname, '../../../../app/elections/[id]')

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = path.join(dir, name)
    if (statSync(full).isDirectory()) return name === '__tests__' ? [] : sourceFiles(full)
    return /\.tsx?$/.test(name) ? [full] : []
  })
}

const code = (file: string): string => readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"`])\/\/.*$/gm, '$1')
const rel = (file: string): string => path.relative(ROOT, file).replace(/\\/g, '/')

const files = sourceFiles(ROOT)
const all = [...files, FIXTURE]

const FORBIDDEN: [name: string, pattern: RegExp][] = [
  ['console.', /\bconsole\s*\./],
  ['a storage import', /async-storage|AsyncStorage|react-native-mmkv|\bMMKV\b|expo-secure-store|SecureStore|secureCache|\bgetStorage\b|\bjsonStore\b|localStorage|sessionStorage|indexedDB|expo-file-system|expo-sqlite|redux-persist/],
  ['submit(', /\bsubmit\s*\(/],
  ['the offline queue', /outbox|offline\/runtime|@gs\/core\/offline/i],
  ['hashing', /expo-crypto|@gs\/core\/crypto|\bdigest\w*\s*\(|\bsha\d*\b|\bmd5\b|createHash/i],
  ['error reporting or analytics', /sentry|crashlytics|analytics|bugsnag|captureException|captureMessage/i],
  ['printing a value', /JSON\.stringify|\balert\s*\(/],
]

describe('the elections feature cannot print, keep, queue or re-send a vote', () => {
  it('finds the files it is meant to be scanning', () => {
    const names = files.map(rel)
    for (const expected of ['ballotBox.ts', 'paper.ts', 'receiptNote.ts', 'useBallotGate.ts', 'useBallotPage.ts', 'useBallotSecrecy.ts', 'useReview.ts', 'useReceipt.ts', 'useResults.ts', 'screens/BallotScreen.tsx', 'screens/ReviewScreen.tsx', 'screens/ReceiptScreen.tsx', 'screens/ResultsScreen.tsx', 'components/BallotParts.tsx', 'components/BallotHolding.tsx']) {
      expect(names).toContain(expected)
    }
  })

  it.each(FORBIDDEN)('no file contains %s', (_name, pattern) => {
    const offenders = all.filter((file) => pattern.test(code(file))).map(rel)
    expect(offenders).toEqual([])
  })

  it('the offline cache is used by the published results and by nothing else', () => {
    const users = files.filter((file) => /useOfflineCache/.test(code(file))).map(rel)
    expect(users).toEqual(['useResults.ts'])
  })

  it('the vote is sent by the generated mutation, from ONE place, and never in a loop or on a timer', () => {
    const senders = files.filter((file) => /useElectionsBallotMutation|electionsBallot\b/.test(code(file))).map(rel)
    expect(senders).toEqual(['useReview.ts'])

    const review = code(path.join(ROOT, 'useReview.ts'))
    expect(review.match(/sendPaper\s*\(/g)).toHaveLength(1)
    expect(review).not.toMatch(/\b(for|while)\s*\(|setTimeout|setInterval|\bretry\w*\s*\(.*sendPaper|__stamp/)
    // The marks leave memory in the same expression that hands them to the request.
    expect(review).toMatch(/sendPaper\(\{[^}]*ballotBox\.take\(\)/)
    // The request's own record of the body (RTK Query keeps `originalArgs`) is dropped by the REQUEST the
    // moment the answer is in. The hook's `reset` captured before the send knows of no request and does
    // nothing, so it must not be what is relied on: the current one is reached through a ref.
    expect(review).toMatch(/await request\s+request\.reset\(\)/)
    expect(review).toMatch(/forget\.current = sending\.reset/)
    expect(review).not.toMatch(/const reset = sending\.reset/)
  })

  it('the app-switcher snapshot is shielded on iOS for as long as any marking screen is up', () => {
    const secrecy = code(path.join(ROOT, 'useBallotSecrecy.ts'))
    expect(secrecy).toMatch(/preventScreenCaptureAsync/)
    expect(secrecy).toMatch(/enableAppSwitcherProtectionAsync/)
    expect(secrecy).toMatch(/disableAppSwitcherProtectionAsync/)
    expect(secrecy).not.toMatch(/ballotBox\.(marks|take|mark)\b/)
  })

  it('no marks are kept behind a holding screen: every marking screen hands its gate to the secrecy hook, which wipes when it shuts', () => {
    expect(code(path.join(ROOT, 'useBallotSecrecy.ts'))).toMatch(/if \(!isFidelity && shut\) ballotBox\.wipe\('gate-shut'\)/)
    expect(code(path.join(ROOT, 'screens/BallotScreen.tsx'))).toMatch(/useBallotSecrecy\([^)]*, gate\.state\)/)
    expect(code(path.join(ROOT, 'screens/ReviewScreen.tsx'))).toMatch(/useBallotSecrecy\('review', gate\.state\)/)
  })

  it('the marks live in a module variable, never in the redux store', () => {
    const box = code(path.join(ROOT, 'ballotBox.ts'))
    expect(box).not.toMatch(/from\s+['"](?!\.\/paper['"])/)
    expect(files.filter((file) => /createSlice|useAppDispatch|dispatch\s*\(/.test(code(file))).map(rel)).toEqual([])
  })

  it('the marks never travel in a route: every navigation carries the election id and nothing else', () => {
    // The argument of every router call, and every `href`.
    const navigations = files.flatMap((file) => code(file).match(/(?:router\.(?:push|replace|dismissTo|navigate)\s*\([^)]*\)|href=\{[^\n]*)/g) ?? [])
    expect(navigations.length).toBeGreaterThan(5)
    for (const call of navigations) {
      // No params object, no query string, nothing that smells of a choice.
      expect(call).not.toMatch(/params\s*:|pathname\s*:|\?[\w$=]|marks|option|ballotBox/i)
    }
    expect(files.filter((file) => /setParams|useGlobalSearchParams/.test(code(file))).map(rel)).toEqual([])
    // Every screen reads exactly one route param: the election id.
    const params = files.flatMap((file) => code(file).match(/useLocalSearchParams<\{[^}]*\}>/g) ?? [])
    expect(params.length).toBeGreaterThanOrEqual(4)
    for (const p of params) expect(p).toBe('useLocalSearchParams<{ id?: string }>')
  })

  it('the receipt and the results cannot see the marks', () => {
    for (const name of ['useReceipt.ts', 'receiptNote.ts', 'useResults.ts', 'screens/ReceiptScreen.tsx', 'screens/ResultsScreen.tsx']) {
      const text = code(path.join(ROOT, name))
      expect({ name, seesMarks: /ballotBox|useBallotPage|useReview|optionIdsOf|markedLabels|toggleMark/.test(text) }).toEqual({ name, seesMarks: false })
    }
    // The receipt takes one route param: the election id.
    expect(code(path.join(ROOT, 'screens/ReceiptScreen.tsx')).match(/useLocalSearchParams<\{([^}]*)\}>/)?.[1]?.trim()).toBe('id?: string')
  })

  it('no ballot is mounted unless the gate is open', () => {
    for (const name of ['screens/BallotScreen.tsx', 'screens/ReviewScreen.tsx']) {
      const text = code(path.join(ROOT, name))
      expect(text).toMatch(/useBallotGate\(id\)/)
      expect(text).toMatch(/gate\.state === 'open' && id \? \(\s*<(Paper|Review)\b/)
    }
    // The holding screen is given the gate and the id — it has nothing to draw a choice from.
    expect(code(path.join(ROOT, 'components/BallotHolding.tsx'))).not.toMatch(/useBallotPage|useReview|ballotBox|PositionCard|useElectionsIndexQuery/)
  })

  it('asking again after a lost answer never reads the answer from before the paper went', () => {
    // A failed refetch still carries the last good `data` ("has_voted: false", from before the send). Read
    // directly, it told a household "your vote was NOT received" when nothing was known.
    const gate = code(path.join(ROOT, 'useBallotGate.ts'))
    expect(gate).toMatch(/const askedAt = Date\.now\(\)\s+return recheckedGate\(await refetch\(\), askedAt, id\)/)
    expect(gate).not.toMatch(/answer\.data|\(await refetch\(\)\)\.data/)
  })

  it('the route files only re-export their screens', () => {
    for (const name of readdirSync(ROUTES)) {
      expect(readFileSync(path.join(ROUTES, name), 'utf8').trim()).toMatch(/^export \{ default \} from '@\/features\/elections\/screens\/\w+Screen'$/)
    }
  })
})

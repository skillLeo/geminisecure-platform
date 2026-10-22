import { readFileSync } from 'node:fs'
import path from 'node:path'
import { EMERGENCY, TOPICS, problemDraft, searchHelp } from '../help'
import { initialsOf } from '../more'
import { CATEGORIES, DEFAULTS, normalise, withChange } from '../prefs'

describe('notification preferences: this phone\'s, and safety is not one of them', () => {
  it('everything is on by default', () => expect(Object.values(DEFAULTS).every(Boolean)).toBe(true))

  it('duress and alertness read as ON whatever was stored', () => {
    const prefs = normalise({ duress: false, alertness: false, messages: false })
    expect(prefs.duress).toBe(true)
    expect(prefs.alertness).toBe(true)
    expect(prefs.messages).toBe(false)
  })

  it('a locked category cannot be switched off; the others can', () => {
    expect(withChange(DEFAULTS, 'duress', false).duress).toBe(true)
    expect(withChange(DEFAULTS, 'alertness', false).alertness).toBe(true)
    // A resident's "happening now" at this site is site safety, not a preference.
    expect(withChange(DEFAULTS, 'reports', false).reports).toBe(true)
    expect(withChange(DEFAULTS, 'payslips', false).payslips).toBe(false)
  })

  it('every category has words for a screen reader, and only the safety ones are locked', () => {
    expect(CATEGORIES.filter((cat) => cat.locked).map((cat) => cat.key)).toEqual(['duress', 'alertness', 'reports'])
    for (const cat of CATEGORIES) { expect(cat.title.length).toBeGreaterThan(3); expect(cat.detail.length).toBeGreaterThan(3) }
  })

  it('no household money category exists on a guard handset', () => {
    expect(JSON.stringify(CATEGORIES)).not.toMatch(/dues|arrears|invoice|balance/i)
  })
})

describe('help that is there with no signal', () => {
  it('an empty search is every topic', () => expect(searchHelp('  ')).toHaveLength(TOPICS.length))

  it('finds articles by every word, whatever the case or punctuation', () => {
    const hits = searchHelp('Offline SCAN')
    expect(hits.length).toBeGreaterThan(0)
    for (const t of hits) for (const art of t.articles) expect(`${t.title} ${art.q} ${art.a}`.toLowerCase()).toMatch(/offline/)
  })

  it('nothing found is an empty list, not a crash', () => expect(searchHelp('zzzqqq')).toEqual([]))

  it('always tells a guard in danger to call, and never to write', () => {
    const duress = TOPICS.find((t) => t.key === 'duress')!
    expect(duress.articles.some((art) => /119/.test(art.a))).toBe(true)
    expect(EMERGENCY.map((e) => e.number)).toEqual(['119', '110'])
  })

  it('names no person, telephone extension or pay rate the server does not serve', () => {
    const all = JSON.stringify(TOPICS)
    expect(all).not.toMatch(/Owen|Grant|PSRA-\d|1\.5×|\$\d/)
  })

  it('a problem report names the app and the phone, and nothing about the guard', () => {
    const text = problemDraft({ version: '1.0.0', device: 'Galaxy A15', os: 'android 14' })
    expect(text).toBe('Technical problem (Guard app 1.0.0, Galaxy A15, android 14): ')
  })
})

describe('initials', () => {
  it('two letters at most, and a dash for no name', () => {
    expect(initialsOf('Marcus Whyte')).toBe('MW')
    expect(initialsOf(' tessa  buildcheck (mobile test)')).toBe('TB')
    expect(initialsOf('')).toBe('—')
  })
})

describe('the rules the code must keep', () => {
  const dir = path.resolve(__dirname, '..')
  const strip = (file: string) => readFileSync(path.join(dir, file), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

  it('there is no PIN: nothing stores, hashes or compares one', () => {
    expect(strip('screens/ChangePinScreen.tsx')).not.toMatch(/SecureStore|setItemAsync|digestString|expo-crypto|useState/)
  })

  it('an emergency call is never disabled', () => {
    const help = strip('screens/HelpScreen.tsx')
    expect(help).toMatch(/tel:/)
    expect(help).not.toMatch(/call\([^)]*\)[^\n]*disabled/)
  })

  it('sign-out is refused during a duress alert and gives what is waiting one last chance to go', () => {
    const out = strip('useSignOut.ts')
    expect(out).toMatch(/useActiveDuress/)
    // drainNow: a sign-out's last chance goes AT ONCE, whatever failed a moment ago.
    expect(out.indexOf('await drainNow()')).toBeGreaterThan(-1)
    expect(out.indexOf('await drainNow()')).toBeLessThan(out.indexOf('clearEverything('))
  })
})

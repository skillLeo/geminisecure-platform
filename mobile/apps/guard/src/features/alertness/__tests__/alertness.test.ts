import { readFileSync } from 'node:fs'
import path from 'node:path'
import { NO_CONSENT, clockOffset, mayPresent, outcomeText, pickCheck, remaining, type PendingCheck } from '../alertness'

const AT = new Date('2026-09-04T14:41:00Z')
const check = (id: number, issued: string, by: string): PendingCheck => ({ check_id: id, issued_at: issued, respond_by: by })
const AGREED = { agreedAt: '2026-09-01T12:00:00Z', declinedAt: null }

describe('the deadline is the server\'s, read on the server\'s clock', () => {
  it('measures the phone against the server from one pull', () => {
    expect(clockOffset('2026-09-04T14:41:30Z', AT)).toBe(30_000)
    expect(clockOffset(null, AT)).toBe(0)
    expect(clockOffset('not a date', AT)).toBe(0)
  })

  it('a phone running three minutes slow does not show a closed check as open', () => {
    const c = check(1, '2026-09-04T14:40:00Z', '2026-09-04T14:42:00Z')
    expect(pickCheck([c], AT, 0)).toBe(c) // by the phone's clock there is a minute left
    expect(pickCheck([c], AT, 180_000)).toBeNull() // by the server's it closed two minutes ago
  })

  it('takes the check closing soonest', () => {
    const a = check(1, '2026-09-04T14:40:30Z', '2026-09-04T14:42:30Z')
    const b = check(2, '2026-09-04T14:40:00Z', '2026-09-04T14:42:00Z')
    expect(pickCheck([a, b], AT, 0)?.check_id).toBe(2)
  })

  it('counts down as mm:ss and as a fraction of the two minutes, never below zero', () => {
    const c = check(1, '2026-09-04T14:40:12Z', '2026-09-04T14:42:12Z')
    expect(remaining(c, AT, 0)).toEqual({ ms: 72_000, label: '01:12', fraction: 0.6 })
    expect(remaining(c, new Date('2026-09-04T14:45:00Z'), 0)).toMatchObject({ ms: 0, label: '00:00', fraction: 0 })
  })
})

describe('consent first', () => {
  const base = { duressActive: false, pathname: '/(tabs)', alreadyOnScreen: false }
  it('a guard who has not been asked is shown the CONSENT, not the check', () => expect(mayPresent({ ...base, consent: NO_CONSENT })).toEqual({ present: true, why: 'no_consent' }))
  it('a guard who said no is not nagged', () => expect(mayPresent({ ...base, consent: { agreedAt: null, declinedAt: '2026-09-01T12:00:00Z' } })).toEqual({ present: false, why: 'declined' }))
  it('a guard who agreed is shown the check', () => expect(mayPresent({ ...base, consent: AGREED })).toEqual({ present: true, why: 'ok' }))
})

describe('never in the way', () => {
  it('never covers an active duress alert: whatever screen the guard is on', () => {
    for (const pathname of ['/(tabs)', '/scan', '/duress', '/messages/dispatch']) expect(mayPresent({ consent: AGREED, duressActive: true, pathname, alreadyOnScreen: false })).toEqual({ present: false, why: 'duress' })
  })
  it('never covers the duress screen or a report being written', () => {
    expect(mayPresent({ consent: AGREED, duressActive: false, pathname: '/duress', alreadyOnScreen: false }).why).toBe('busy')
    expect(mayPresent({ consent: AGREED, duressActive: false, pathname: '/incident/new', alreadyOnScreen: false }).why).toBe('busy')
  })
  it('even the consent screen waits for an emergency to be over', () => {
    expect(mayPresent({ consent: NO_CONSENT, duressActive: true, pathname: '/(tabs)', alreadyOnScreen: false }).present).toBe(false)
  })
  it('is not pushed on top of itself', () => expect(mayPresent({ consent: AGREED, duressActive: false, pathname: '/alertness', alreadyOnScreen: true }).why).toBe('showing'))
})

describe('what the server answered', () => {
  it('passed is thanks; missed is said honestly, with what to do', () => {
    expect(outcomeText('passed').tone).toBe('green-400')
    expect(outcomeText('missed').body).toMatch(/recorded as missed/)
    expect(outcomeText('missed').body).toMatch(/control room/)
    expect(outcomeText(undefined).title).toBe('Not recorded')
  })
})

describe('the rules the code must keep', () => {
  const dir = path.resolve(__dirname, '..')
  const strip = (file: string) => readFileSync(path.join(dir, file), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

  it('an answer is never queued and never retried by the app', () => {
    for (const file of ['screens/AlertnessScreen.tsx', 'AlertnessWatcher.tsx']) expect(strip(file)).not.toMatch(/submit\(|outbox|@\/offline\/runtime/)
  })
  it('no score, photo, camera or biometric module is used: a check-in is a tap', () => {
    for (const file of ['screens/AlertnessScreen.tsx', 'AlertnessWatcher.tsx', 'state.ts', 'alertness.ts']) expect(strip(file)).not.toMatch(/score\s*:|expo-camera|expo-local-authentication|expo-image-picker|expo-location/)
  })
})

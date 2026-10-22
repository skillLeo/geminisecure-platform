/**
 * M10 §1: "Panic type: let the resident pick if they can — medical, fire, security, other — but never
 * require it. An unlabelled panic goes to everyone exactly the same."
 *
 * The words are pure and tested directly. The wiring is read out of the source, as panicReach.test.ts
 * does and for its reason: `usePanic` pulls in the whole app, and what is guaranteed here is what the
 * files CONTAIN — that nothing between the press and the send ever asks for a type.
 *
 * THE REBUILT SCREEN OFFERS NO CHOICE (M42 §5 #5: "build the design exactly" — the board has no chips, and its
 * line reads "General emergency"). The hook still carries a type with the alert exactly as before, so an alert
 * pressed today goes as the general emergency the board names.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { ALERT_TYPES } from '@gs/core/safety'
import { AMBULANCE_FIRE_NUMBER, PANIC_TYPES, alertLabel, needsAmbulanceOrFire, toggleType, typeHint } from '../panicType'

const dir = path.resolve(__dirname, '..')
const strip = (s: string) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
const screen = strip(readFileSync(path.join(dir, 'screens', 'PanicScreen.tsx'), 'utf8'))
const hook = strip(readFileSync(path.join(dir, 'usePanic.ts'), 'utf8'))

describe('the four choices', () => {
  it('are exactly the server\'s four, in the order the client named them', () => {
    expect(PANIC_TYPES.map((t) => t.value)).toEqual([...ALERT_TYPES])
    expect(PANIC_TYPES.map((t) => t.label)).toEqual(['Medical', 'Fire', 'Security', 'Other'])
  })

  it('start with nothing chosen, and a second tap clears one: the choice is never a trap', () => {
    expect(hook).toMatch(/useState<AlertType \| null>\(null\)/)
    expect(toggleType(null, 'medical')).toBe('medical')
    expect(toggleType('medical', 'fire')).toBe('fire')
    expect(toggleType('fire', 'fire')).toBeNull()
  })

  it('say plainly that choosing changes who is told not at all, and never the word "optional" (M18C §1)', () => {
    expect(typeHint(null)).toBe('Choose one if you can. The alert goes to the same people whether you choose or not.')
    for (const t of [null, 'medical', 'fire', 'security', 'other'] as const) expect(typeHint(t)).not.toMatch(/optional/i)
    expect(typeHint('security')).toMatch(/same people either way/)
    expect(typeHint('other')).toMatch(/same people either way/)
  })

  it('never let a label pass for a service: medical and fire say they call nobody, and put 110 beside the button', () => {
    expect(AMBULANCE_FIRE_NUMBER).toBe('110')
    for (const t of ['medical', 'fire'] as const) {
      expect(needsAmbulanceOrFire(t)).toBe(true)
      expect(typeHint(t)).toMatch(/does not call an ambulance or the fire service/)
      expect(typeHint(t)).toContain('call 110')
    }
    for (const t of [null, 'security', 'other'] as const) expect(needsAmbulanceOrFire(t)).toBe(false)
    expect(hook).toMatch(/callAmbulanceFire: \(\) => void Linking\.openURL\(`tel:\$\{AMBULANCE_FIRE_NUMBER\}`\)/)
  })
})

describe('the choice never delays or blocks the alert', () => {
  it('the hold and the confirmation send whatever is chosen: including nothing', () => {
    // The press goes straight to fire(); nothing in the way reads the type.
    expect(screen).toMatch(/if \(finished\) m\.fire\(\)/)
    // The confirmation for someone who cannot hold is the screen's own ConfirmSheet (panicConfirm.test.tsx), and its
    // yes is fire() and nothing else.
    expect(screen).toMatch(/send: \(\) => \{\s*m\.fire\(\)\s*return \{ ok: true \}/)
    expect(screen).toContain('confirmLabel="Send the alert"')
    expect(hook + screen).not.toMatch(/!\s*(m\.)?type\b[^\n]*(return|disabled)/)
    expect(hook + screen).not.toMatch(/(m\.)?type\s*===\s*null[^\n]*(return|disabled)/)
  })

  it('the type is carried with the alert and nothing more: raiseAlert takes it as it is', () => {
    expect(hook).toMatch(/\{ mode: 'audible', type \}/)
  })

  it('a type cannot be changed once the alert has gone', () => {
    expect(hook).toMatch(/if \(!saved && !sending\) setType/)
  })

  it('the rebuilt screen asks for nothing before the press: no chips, no type read anywhere on it (M42 §5 #5)', () => {
    expect(screen).not.toMatch(/chooseType|PANIC_TYPES|radiogroup/)
  })
})

describe('what the hero says the alert is', () => {
  it('before any answer, what was pressed', () => {
    expect(alertLabel(null)).toBe('General emergency')
    expect(alertLabel('medical')).toBe('Medical emergency')
    expect(alertLabel('security')).toBe('Security emergency')
  })

  it('once answered, the server\'s kind for medical and fire: that is what the control room sees', () => {
    expect(alertLabel('medical', 'medical')).toBe('Medical emergency')
    expect(alertLabel('fire', 'fire')).toBe('Fire emergency')
    // A server that predates the field recorded a plain panic, and the screen says what it recorded.
    expect(alertLabel('medical', 'panic')).toBe('General emergency')
    // Security and other ARE a plain panic on the server, by design: the resident's word is kept.
    expect(alertLabel('security', 'panic')).toBe('Security emergency')
    expect(alertLabel('other', 'panic')).toBe('Other emergency')
    expect(alertLabel(null, 'panic')).toBe('General emergency')
  })

  it('an alert saved by an older build, with no type on disk, is a general emergency', () => {
    expect(alertLabel(undefined, undefined)).toBe('General emergency')
    expect(hook).toMatch(/isAlertType\(saved\.type\) \? saved\.type : null/)
  })
})

/**
 * More → Notifications (guard-app-36) tells the truth about this phone — including after a guard
 * said no to alerts in setup, which is the case that used to read as "not allowed yet" for ever.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { footerNote, offBanner, permissionRow, type Permission } from '../notifyPermission'

const ALL: Permission[] = ['granted', 'denied', 'undetermined', 'unsupported']
const screen = readFileSync(path.resolve(__dirname, '..', 'screens', 'NotificationsScreen.tsx'), 'utf8')

describe('the "Notifications from this app" row', () => {
  it('says "you chose not to" only when the app really did ask', () => {
    expect(permissionRow('undetermined', true).detail).toMatch(/you chose not to allow alerts when you set up/i)
    expect(permissionRow('undetermined', false).detail).toMatch(/Not allowed yet/)
    expect(permissionRow('undetermined', true).control).toBe('allow')
    expect(permissionRow('undetermined', false).control).toBe('allow')
  })

  it('offers the phone’s Settings when only Settings can change it', () => {
    expect(permissionRow('denied', true)).toEqual({ detail: "Turned off in the phone's settings: tap to open them", control: 'settings' })
  })

  it('shows a state, and an action or an honest dash, for every answer a phone can give', () => {
    for (const p of ALL) {
      const row = permissionRow(p, true)
      expect(row.detail.length).toBeGreaterThan(8)
      expect(['on', 'allow', 'settings', 'none']).toContain(row.control)
    }
    expect(permissionRow('granted', true).control).toBe('on')
    expect(permissionRow('unsupported', false).control).toBe('none')
  })
})

describe('when this phone is silent, the screen says so', () => {
  it('names what will not reach the guard', () => {
    for (const p of ['denied', 'undetermined'] as const) {
      const banner = offBanner(p)
      expect(banner).toMatch(/Alerts are off on this phone/)
      expect(banner).toMatch(/duress or panic alert/)
      expect(banner).toMatch(/alertness check/)
      expect(banner).toMatch(/gate/)
      expect(banner).toMatch(/control room/)
    }
  })

  it('says nothing when there is nothing to warn about', () => {
    expect(offBanner('granted')).toBeNull()
    expect(offBanner('unsupported')).toBeNull()
  })
})

describe('the paragraph under the switches', () => {
  it('only promises a closed app is reached when the phone actually allows it', () => {
    expect(footerNote('granted', false)).toMatch(/respect them when the app is closed/)
    for (const p of ['denied', 'undetermined'] as const) {
      expect(footerNote(p, false)).toMatch(/only take effect once alerts are allowed/)
      expect(footerNote(p, false)).not.toMatch(/respect them when the app is closed/)
    }
  })

  it('always says which categories cannot be switched off, and owns up inside Expo Go', () => {
    for (const p of ALL) {
      expect(footerNote(p, false)).toMatch(/cannot be switched off/)
      expect(footerNote(p, true)).toMatch(/only notify you while it is open/)
    }
  })
})

describe('the screen uses these words rather than its own', () => {
  it('reads the row, the banner and the footer from this file', () => {
    expect(screen).toMatch(/permissionRow\(/)
    expect(screen).toMatch(/offBanner\(/)
    expect(screen).toMatch(/footerNote\(/)
  })

  it('knows whether setup already asked, so the row can tell the difference', () => {
    expect(screen).toMatch(/notificationsAskedAt/)
  })
})

/**
 * "Require biometrics for payments — Re-confirm identity above $5,000" (board 19; M50 §3.3): which payments the phone's
 * own prompt must confirm. On unless turned off; above the line only; an amount the screen does not know is asked about.
 */

import { PAYMENTS_LOCK_ABOVE, amountOf, needsConfirming } from '../paymentsLock'

jest.mock('expo-local-authentication', () => ({ getEnrolledLevelAsync: jest.fn(), authenticateAsync: jest.fn(), SecurityLevel: { NONE: 0 } }))

describe('which payments the phone confirms first', () => {
  it('reads the amount the screen shows, in any of the ways it is written', () => {
    expect(amountOf('8000.00')).toBe(8000)
    expect(amountOf('J$8,000.00')).toBe(8000)
    expect(amountOf('4500')).toBe(4500)
    expect(amountOf(undefined)).toBeNull()
    expect(amountOf('')).toBeNull()
  })

  it('asks above $5,000, not at or below it, and asks about an amount it does not know', () => {
    expect(PAYMENTS_LOCK_ABOVE).toBe(5000)
    expect(needsConfirming(8000, true)).toBe(true)
    expect(needsConfirming(5000.01, true)).toBe(true)
    expect(needsConfirming(5000, true)).toBe(false)
    expect(needsConfirming(3100, true)).toBe(false)
    expect(needsConfirming(null, true)).toBe(true)
  })

  it('turned off, it asks about nothing', () => {
    expect(needsConfirming(8000, false)).toBe(false)
    expect(needsConfirming(null, false)).toBe(false)
  })
})

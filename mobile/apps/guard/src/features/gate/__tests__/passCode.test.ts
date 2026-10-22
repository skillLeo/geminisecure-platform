/**
 * The typed pass code must reach the server exactly as the server stored it — upper case,
 * hyphen before the last four digits — whatever the guard's thumbs did on the way.
 */
import { backspace, formatPassCode, isCompletePassCode } from '../passCode'

describe('pass code', () => {
  it('puts the hyphen before the last four digits, whatever the phase length', () => {
    expect(formatPassCode('ppv24471')).toBe('PPV2-4471')
    expect(formatPassCode('PPV2-4471')).toBe('PPV2-4471')
    expect(formatPassCode('ppv 2 4471')).toBe('PPV2-4471')
    expect(formatPassCode('OV124471')).toBe('OV12-4471')
    expect(formatPassCode('GS04471')).toBe('GS0-4471')
  })

  it('does not guess the hyphen before it can know', () => {
    expect(formatPassCode('PPV2')).toBe('PPV2')
    expect(formatPassCode('PPV244')).toBe('PPV244')
    expect(formatPassCode('PPV2447')).toBe('PPV2447')
  })

  it('ignores what cannot be part of a code, and stops at its length', () => {
    expect(formatPassCode('ppv2-44-71!!')).toBe('PPV2-4471')
    expect(formatPassCode('ABCDEFG14471')).toBe('ABCD1-4471')
    expect(formatPassCode('PPV2447112345')).toBe('PPV244-7112')
  })

  it('is complete only in the server’s shape', () => {
    expect(isCompletePassCode('PPV2-4471')).toBe(true)
    expect(isCompletePassCode('OV12-4471')).toBe(true)
    expect(isCompletePassCode('PPV2-447')).toBe(false)
    expect(isCompletePassCode('P2-4471')).toBe(false)
    expect(isCompletePassCode('PPV24471')).toBe(false)
    expect(isCompletePassCode('')).toBe(false)
  })

  it('backspace removes what the guard typed, never just the hyphen the app added', () => {
    expect(backspace('PPV2-4471')).toBe('PPV2447')
    expect(backspace('PPV2')).toBe('PPV')
    expect(backspace('')).toBe('')
  })
})

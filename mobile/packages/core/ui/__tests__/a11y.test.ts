import { MAX_TEXT_SCALE, setHighContrast, setTextCap, setTextScale, systemHeadroom } from '../a11y'
import { textColor } from '../Text'
import { palette } from '../../theme/colors'

afterEach(() => {
  setTextCap(MAX_TEXT_SCALE)
  setTextScale(1)
  setHighContrast(false)
})

describe('the app\'s own text size', () => {
  it('by default app × system never passes the 1.3× the boards\' layouts are proven to (the Guard App keeps it)', () => {
    for (const app of [1, 1.12, 1.22, 1.3]) expect(app * systemHeadroom(app)).toBeLessThanOrEqual(MAX_TEXT_SCALE + 0.001)
    expect(systemHeadroom(1)).toBe(1.3)
    expect(systemHeadroom(1.3)).toBe(1)
  })

  it('an app that lifts the cap (the Resident App, M50 §6.5) leaves the phone\'s own setting unlimited', () => {
    expect(systemHeadroom(1.5, null)).toBe(0) // React Native's "no limit"
    expect(systemHeadroom(1, null)).toBe(0)
  })

  it('a nonsense value is ignored rather than drawn', () => {
    expect(() => { setTextScale(Number.NaN); setTextScale(9); setTextScale(0.2); setTextScale(1) }).not.toThrow()
  })
})

describe('high contrast (M50 §6.5)', () => {
  it('draws body and secondary text in black, and leaves meaning colours and the dark screens\' white alone', () => {
    expect(textColor('slate-500', true)).toBe(palette.black)
    expect(textColor('navy-900', true)).toBe(palette.black)
    expect(textColor('slate-500', false)).toBe(palette['text-body'])
    expect(textColor('text-2', true)).toBe(palette['text-on-dark'])
    expect(textColor('red-700', true)).toBe(palette['red-700'])
  })

  it('reaches the rebuilt Resident screens too, which draw in the design\'s exact inks', () => {
    for (const ink of ['rd-navy-900', 'rd-navy-800', 'rd-navy-700', 'rd-slate-600', 'rd-slate-500'] as const) {
      expect(textColor(ink, true)).toBe(palette.black)
      expect(textColor(ink, false)).toBe(palette[ink])
    }
    // White on the navy screens, and the brand blue and gold that carry meaning, are left alone.
    expect(textColor('rd-white', true)).toBe(palette['rd-white'])
    expect(textColor('rd-navy-600', true)).toBe(palette['rd-navy-600'])
    expect(textColor('rd-amber-700', true)).toBe(palette['rd-amber-700'])
  })
})

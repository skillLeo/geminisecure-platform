/**
 * The base Text. Every piece of type in both apps goes through this.
 *
 *   <Text face="Inter-SemiBold" size={12} color="slate-600">Visitor name</Text>
 *
 * - `face` names the weight's own family — never fontWeight on a custom font.
 * - With `face` and `size` both given, lineHeight is always set (the face's `normal`
 *   unless `lh` gives the board's multiplier), so text height is the same on
 *   Android, iOS and web.
 * - NO DEFAULTS. A nested <Text> (a bold word inside a sentence) passes only what
 *   differs and must INHERIT the rest; a default size here once made every nested
 *   run 14px inside a 10.5px line and pushed the screen down. A top-level Text
 *   always passes both `face` and `size`.
 * - includeFontPadding:false — Android pads text and iOS does not.
 * - maxFontSizeMultiplier — text scales with the system setting, capped at 1.3 so the
 *   boards' layouts hold, unless the app lifted the cap (ui/a11y.ts · setTextCap).
 */

import { Platform, Text as RNText, type TextProps as RNTextProps, type TextStyle } from 'react-native'
import { isFidelity } from '../fidelity'
import { palette, type ColorToken } from '../theme/colors'
import { type, type FontFace } from '../theme/typography'
import { systemHeadroom, useHighContrast, useTextCap, useTextScale } from './a11y'

export type TextProps = RNTextProps & {
  face?: FontFace
  size?: number
  /** Board line-height multiplier, e.g. 1.5. Omit for the face's `normal`. */
  lh?: number
  /** Board letter-spacing in px. */
  ls?: number
  color?: ColorToken
  align?: TextStyle['textAlign']
  upper?: boolean
  className?: string
}

/**
 * FOUR TEXT COLOURS, AND NO FIFTH (M18B, the client: "darker, not heavier"; no opacity on text, ever).
 *
 *   text-primary      #0B1220  headings, amounts, names, field labels
 *   text-body         #1E2A3A  body, descriptions, list rows, timestamps, helper lines — the same as everything else
 *   text-placeholder  #46586F  placeholders only (the fields' own `placeholderTextColor`)
 *   text-on-dark      #FFFFFF  on the navy header, coloured buttons and the Guard App's dark screens
 *
 * The screens were drawn with the boards' greys and navies, which read pale in daylight. They are mapped HERE, once,
 * so every piece of type in both apps — whatever token a screen names, in whatever expression — lands on one of the
 * four. The light-screen greys and navies only ever sit on light backgrounds and become darker; the Guard App's pale
 * greys only ever sit on its dark screens and become white. Status colours (amber, red, green) and the brand blue of a
 * link are meaning, not hierarchy, and pass through. The fidelity build keeps the boards' own colours, so the
 * measurement still compares like with like.
 */
export const TEXT_TONE: Readonly<Partial<Record<ColorToken, ColorToken>>> = Object.freeze({
  'navy-900': 'text-primary',
  'g-navy-900': 'text-primary',
  'navy-800': 'text-body',
  'navy-700': 'text-body',
  'slate-600': 'text-body',
  'slate-500': 'text-body',
  'slate-300': 'text-body',
  'text-2': 'text-on-dark',
  'text-3': 'text-on-dark',
})

/**
 * HIGH CONTRAST (M50 §6.5, ui/a11y.ts): the two light-screen text tones go to black, so nothing reads pale. The text on
 * dark screens stays white, the placeholders their own, and status colours and links keep their meaning.
 *
 * The Resident App's rebuilt screens draw in the design's exact inks (rd-*, which TEXT_TONE passes through), so their
 * five light-screen text inks are listed too — heading, body, the text on a tint, label and secondary. Without them the
 * switch darkened only the screens not yet rebuilt.
 */
const HIGH_CONTRAST: Readonly<Partial<Record<ColorToken, ColorToken>>> = Object.freeze({
  'text-primary': 'black',
  'text-body': 'black',
  'rd-navy-900': 'black',
  'rd-navy-800': 'black',
  'rd-navy-700': 'black',
  'rd-slate-600': 'black',
  'rd-slate-500': 'black',
})

/** The colour a piece of text is drawn in: its token through `TEXT_TONE`, and in high contrast darker still (off in the fidelity build). */
export const textColor = (token: ColorToken, highContrast = false): string => {
  if (isFidelity) return palette[token]
  const tone = TEXT_TONE[token] ?? token
  return palette[highContrast ? (HIGH_CONTRAST[tone] ?? tone) : tone]
}

export function Text({ face, size: asked, lh, ls, color, align, upper, style, ...rest }: TextProps) {
  // The app's own text-size choice (ui/a11y.ts). 1 unless an app set it — the fidelity harness never does.
  const scale = useTextScale()
  const cap = useTextCap()
  const highContrast = useHighContrast()
  const size = asked && scale !== 1 ? Math.round(asked * scale * 100) / 100 : asked
  const metrics: TextStyle =
    face && size
      ? // On web, a board's `line-height: normal` is left to the browser — the same engine that laid out the
        // board — so mixed-size lines (a 12.5px span in a 16px box) come out exactly as drawn. Native has no
        // `normal`, so it gets the face's normal as a number, which is what keeps Android and iOS the same.
        Platform.OS === 'web' && lh === undefined
        ? { fontFamily: face, fontSize: size, ...(ls === undefined ? null : { letterSpacing: ls }) }
        : type(face, size, { lineHeight: lh, letterSpacing: ls })
      : {
          ...(face ? { fontFamily: face } : null),
          ...(size ? { fontSize: size } : null),
          ...(size && lh ? { lineHeight: Math.round(size * lh * 1000) / 1000 } : null),
          ...(ls === undefined ? null : { letterSpacing: ls }),
        }

  const base: TextStyle = {
    ...metrics,
    includeFontPadding: false,
    ...(color ? { color: textColor(color, highContrast) } : null),
    ...(align ? { textAlign: align } : null),
    ...(upper ? { textTransform: 'uppercase' } : null),
  }

  // The phone's own font setting on top of the app's choice: up to the cap — or, where the app lifted it, all of it.
  return <RNText maxFontSizeMultiplier={systemHeadroom(scale, cap)} {...rest} style={[base, style]} />
}

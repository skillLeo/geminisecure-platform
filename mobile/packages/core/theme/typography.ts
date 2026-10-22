/**
 * Type — two families plus a mono, each WEIGHT registered as its own family.
 *
 * React Native does not reliably apply a numeric fontWeight to a custom font on
 * Android, so `fontFamily: 'Poppins', fontWeight: '600'` is forbidden; name the
 * face: 'Poppins-SemiBold'. The names below are the files' own PostScript names
 * (read by tools/fonts/metrics.mjs), so one name resolves on iOS, Android and web.
 *
 * LINE HEIGHT. The boards almost never set line-height, so the browser uses
 * `normal` = (ascent + descent + lineGap) / unitsPerEm. RN must be told, or text
 * height differs between platforms. The ratios are read from the font files into
 * font-metrics.json, never remembered:  Inter 1.20996 · Poppins 1.5 · Plex Mono 1.3
 */

import type { TextStyle } from 'react-native'
import metrics from './font-metrics.json'

export const fontFamily = {
  poppinsMedium: 'Poppins-Medium',
  poppinsSemiBold: 'Poppins-SemiBold',
  poppinsBold: 'Poppins-Bold',
  interRegular: 'Inter-Regular',
  interMedium: 'Inter-Medium',
  interSemiBold: 'Inter-SemiBold',
  interBold: 'Inter-Bold',
  // Italic as its own face (M19A §4.3: the viewer's own time, "secondary, italic, smaller") — never fontStyle, which
  // Android fakes on a custom font.
  interItalic: 'Inter-Italic',
  monoRegular: 'IBMPlexMono-Regular',
  monoMedium: 'IBMPlexMono-Medium',
} as const

export type FontFace = (typeof fontFamily)[keyof typeof fontFamily]

const PARTS = metrics as Record<FontFace, { ascent: number; descent: number; lineGap: number }>

/**
 * The browser's `line-height: normal` for this face at this size, in px.
 *
 * Not size × ratio: Chromium rounds the ascent, the descent and the line gap to whole
 * pixels SEPARATELY and adds them (Inter 13.5px → 13 + 3 = 16, not 16.33). Using the
 * unrounded product made every line a third of a pixel too tall, and a screen of
 * stacked text drifted 1–2px down its board — measured on the first lifted screens.
 * Whole pixels are also what a phone's text layout wants.
 */
export function normalLineHeight(face: FontFace, size: number): number {
  const m = PARTS[face]
  return Math.round(m.ascent * size) + Math.round(m.descent * size) + Math.round(m.lineGap * size)
}

/**
 * A text style for one face at one size. `lineHeight` defaults to the face's
 * `normal`; pass a multiplier where a board sets one (`line-height:1.5` → 1.5).
 */
export function type(face: FontFace, size: number, opts: { lineHeight?: number; letterSpacing?: number } = {}): TextStyle {
  return {
    fontFamily: face,
    fontSize: size,
    lineHeight: opts.lineHeight === undefined ? normalLineHeight(face, size) : Math.round(size * opts.lineHeight * 1000) / 1000,
    ...(opts.letterSpacing === undefined ? null : { letterSpacing: opts.letterSpacing }),
  }
}

/** CSS font-weight → face, for translating a board rule mechanically. */
export const inter = { 400: fontFamily.interRegular, 500: fontFamily.interMedium, 600: fontFamily.interSemiBold, 700: fontFamily.interBold } as const
export const poppins = { 500: fontFamily.poppinsMedium, 600: fontFamily.poppinsSemiBold, 700: fontFamily.poppinsBold } as const
export const mono = { 400: fontFamily.monoRegular, 500: fontFamily.monoMedium } as const

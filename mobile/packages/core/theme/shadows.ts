/**
 * Elevation.
 *
 * React Native 0.86 on the New Architecture supports the `boxShadow` style prop
 * with CSS syntax on iOS, Android (API 28+) and web — coloured and multi-layer.
 * So the boards' shadows are written exactly as the boards write them, and the
 * two-layer ones are not averaged into a single guess.
 *
 * `fallback` is the Mobile Spec's single-shadow mapping, kept for Android < 9
 * where boxShadow is ignored: elevation only, neutral colour, as the spec accepts.
 */

import type { ViewStyle } from 'react-native'
import { alpha, type ColorToken } from './colors'

type Layer = { x: number; y: number; blur: number; spread?: number; token: ColorToken; opacity: number }

function css(layers: Layer[]): string {
  return layers.map((l) => `${l.x}px ${l.y}px ${l.blur}px ${l.spread ?? 0}px ${alpha(l.token, l.opacity)}`).join(', ')
}

/** A board's box-shadow as a CSS string built from tokens — used by lifted screens, which carry the board's exact layers. */
export const sh = (layers: Layer[]): string => css(layers)

function shadow(layers: Layer[], elevation: number): ViewStyle {
  return { boxShadow: css(layers), elevation }
}

export const shadows = {
  /** Resident card — 0 8px 20px rgba(15,61,107,.07) */
  residentCard: shadow([{ x: 0, y: 8, blur: 20, token: 'navy-800', opacity: 0.07 }], 3),
  /** Navy mobile primary — 0 10px 20px rgba(25,116,210,.28) */
  navyButton: shadow([{ x: 0, y: 10, blur: 20, token: 'navy-600', opacity: 0.28 }], 6),
  /** Resident auth primary (boards 01) — 0 10px 20px rgba(25,116,210,.32) */
  navyButtonAuth: shadow([{ x: 0, y: 10, blur: 20, token: 'navy-600', opacity: 0.32 }], 6),
  /** Compact primary — 0 6px 14px rgba(25,116,210,.25) */
  navyButtonSm: shadow([{ x: 0, y: 6, blur: 14, token: 'navy-600', opacity: 0.25 }], 4),
  /** Guard amber primary — 0 10px 22px rgba(255,182,39,.28) */
  amberButton: shadow([{ x: 0, y: 10, blur: 22, token: 'amber-500', opacity: 0.28 }], 6),
  /** Guard FAB — 0 10px 22px rgba(255,182,39,.4) (guard board 01) */
  amberFab: shadow([{ x: 0, y: 10, blur: 22, token: 'amber-500', opacity: 0.4 }], 8),
  /** Resident auth badge — 0 10px 22px rgba(15,61,107,.28) */
  authBadge: shadow([{ x: 0, y: 10, blur: 22, token: 'navy-800', opacity: 0.28 }], 6),
  /** Geofence pin halo — 0 0 0 6px rgba(255,182,39,.25) */
  pinHalo: shadow([{ x: 0, y: 0, blur: 0, spread: 6, token: 'amber-500', opacity: 0.25 }], 0),
  /** Scan line glow — 0 0 8px rgba(255,182,39,.7) */
  scanGlow: shadow([{ x: 0, y: 0, blur: 8, token: 'amber-500', opacity: 0.7 }], 0),
  /** Focused input ring — 0 0 0 3px rgba(255,182,39,.18) */
  focusRingAmber: shadow([{ x: 0, y: 0, blur: 0, spread: 3, token: 'amber-500', opacity: 0.18 }], 0),
  none: {} as ViewStyle,
} as const

export type ShadowName = keyof typeof shadows

/**
 * Gradients — the boards' CSS gradients, kept as CSS angles and converted
 * geometrically for expo-linear-gradient.
 *
 * A CSS angle is NOT an RN start/end vector, and the conversion depends on the
 * box's aspect ratio: for a W x H box the gradient line runs through the centre
 * at the angle (0deg = up, clockwise) with half-length (|W sin a| + |H cos a|) / 2.
 * A fixed {x,y} pair is only right for one aspect ratio — the Mobile Spec's
 * {0.19,0}→{0.81,1} for the 155deg splash assumes a near-square box, and on the
 * real 397 x 856 screen the line runs corner to corner. So points are computed
 * from the measured size, never hard-coded.
 */

import { palette, alpha, type ColorToken } from './colors'

export type GradientStop = { token: ColorToken; at: number; opacity?: number }
export type GradientSpec = { angle: number; stops: GradientStop[] }

export const gradients = {
  /** linear-gradient(155deg, navy-600 0%, navy-800 78%) */
  residentSplash: { angle: 155, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-800', at: 0.78 }] },
  /** linear-gradient(135deg, navy-600 0%, navy-700 100%) */
  residentAuthHeader: { angle: 135, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-700', at: 1 }] },
  /** linear-gradient(160deg, navy-800 0%, surface-900 78%) */
  guardSplash: { angle: 160, stops: [{ token: 'navy-800', at: 0 }, { token: 'surface-900', at: 0.78 }] },
  /** linear-gradient(135deg, navy-700, surface-900) */
  guardShiftCard: { angle: 135, stops: [{ token: 'navy-700', at: 0 }, { token: 'surface-900', at: 1 }] },
  /** linear-gradient(180deg, navy-700, navy-900) — resident panic */
  panic: { angle: 180, stops: [{ token: 'navy-700', at: 0 }, { token: 'navy-900', at: 1 }] },
  /** linear-gradient(180deg, rgba(0,0,0,.75), rgba(0,0,0,0)) — scan-screen scrim */
  scanScrim: { angle: 180, stops: [{ token: 'black', at: 0, opacity: 0.75 }, { token: 'black', at: 1, opacity: 0 }] },
} as const satisfies Record<string, GradientSpec>

export type GradientName = keyof typeof gradients

/** expo-linear-gradient props for a CSS angle in a box of this size. */
export function gradientProps(spec: GradientSpec, width: number, height: number) {
  const a = (spec.angle * Math.PI) / 180
  const dx = Math.sin(a)
  const dy = -Math.cos(a)
  const half = (Math.abs(width * dx) + Math.abs(height * dy)) / 2
  const cx = width / 2
  const cy = height / 2
  const safeW = width || 1
  const safeH = height || 1

  return {
    colors: spec.stops.map((s) => (s.opacity === undefined ? palette[s.token] : alpha(s.token, s.opacity))) as [string, string, ...string[]],
    locations: spec.stops.map((s) => s.at) as [number, number, ...number[]],
    start: { x: (cx - dx * half) / safeW, y: (cy - dy * half) / safeH },
    end: { x: (cx + dx * half) / safeW, y: (cy + dy * half) / safeH },
  }
}

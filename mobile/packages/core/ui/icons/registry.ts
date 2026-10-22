/**
 * Every icon is the board's own SVG — path data, stroke width, caps and joins exactly
 * as drawn. The boards use contextual stroke weights (1.6 / 1.7 / 1.8 / 1.9 / 2.1 / 3)
 * and they are kept, never rounded to 2; the same glyph at a different weight is a
 * different entry.
 *
 * icons.json is maintained by tools/lift/lift.mjs, which deduplicates every
 * single-colour SVG it meets on the boards by content. Hand-named entries survive;
 * a lifted icon may be renamed here and in the screens that use it.
 *
 * An element is stroked when it has `sw`, filled when it has `fill`, and may be both.
 */

import data from './icons.json'

export type IconEl =
  | { t: 'path'; d: string; sw?: number; cap?: string; join?: string; fill?: boolean }
  | { t: 'circle'; cx: number; cy: number; r: number; sw?: number; cap?: string; join?: string; fill?: boolean }
  | { t: 'ellipse'; cx: number; cy: number; rx: number; ry: number; sw?: number; cap?: string; join?: string; fill?: boolean }
  | { t: 'rect'; x: number; y: number; w: number; h: number; rx?: number; sw?: number; cap?: string; join?: string; fill?: boolean }
  | { t: 'line'; x1: number; y1: number; x2: number; y2: number; sw?: number; cap?: string; join?: string; fill?: boolean }
  | { t: 'polyline' | 'polygon'; points: string; sw?: number; cap?: string; join?: string; fill?: boolean }

export type IconDef = { viewBox?: string; els: IconEl[] }

export type IconName = keyof typeof data

export const icons = data as unknown as Record<IconName, IconDef>

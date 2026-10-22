/**
 * The brand mark — two overlapping circles, the Gemini twins.
 *
 * Foundation defines it exactly: viewBox 0 0 40 40, r 12.5, centres at cx 15 and
 * cx 25 (cy 20), and "the overlap is produced by mix-blend-mode:multiply on the
 * right circle — not by a third shape". React Native 0.86 on the New Architecture
 * supports `mixBlendMode`, so that is how it is built: two circles, the right one
 * multiplying over whatever is beneath it — the left circle AND the backdrop,
 * which is why the right crescent reads dark green over the navy splash.
 * (The three-shape construction in the original specification predates RN's
 * blend-mode support and is the fallback for Android < 10 only.)
 *
 * Never redraw it. A shield, a monogram or a padlock is a different company's logo.
 */

import { Platform, View, type StyleProp, type ViewStyle } from 'react-native'
import { palette, type ColorToken } from '../theme/colors'

export type BrandMarkVariant = 'splash' | 'authBadge' | 'console'

const VARIANTS: Record<BrandMarkVariant, { left: ColorToken; right: ColorToken; opacity: number }> = {
  /** Resident and guard splash — #FFFFFF + #FFB627, opacity .92 */
  splash: { left: 'white', right: 'amber-500', opacity: 0.92 },
  /** Resident auth badge — #1974D2 + #FFB627, full opacity */
  authBadge: { left: 'navy-600', right: 'amber-500', opacity: 1 },
  /** Console — #3B2166 + #1974D2, full opacity */
  console: { left: 'purple-800', right: 'navy-600', opacity: 1 },
}

/**
 * react-native-web gives every View `z-index: 0`, which makes each one a CSS
 * stacking context — and a blend mode only reaches as far as its stacking
 * context. Left alone, the right circle would multiply with the left circle and
 * never with the gradient behind the mark, and the right crescent would stay
 * amber instead of going dark. On web the container therefore opts out of
 * isolating; native Views do not isolate by default. Do not wrap the mark in
 * another View for spacing — pass `style` instead.
 */
const NO_ISOLATE = Platform.OS === 'web' ? ({ zIndex: 'auto' } as unknown as ViewStyle) : null

type Props = { size: number; variant: BrandMarkVariant; style?: StyleProp<ViewStyle> }

export function BrandMark({ size, variant, style }: Props) {
  const v = VARIANTS[variant]
  const unit = size / 40
  const d = 25 * unit
  const top = 7.5 * unit

  return (
    <View
      style={[{ width: size, height: size }, NO_ISOLATE, style]}
      accessible
      role="img"
      aria-label="GeminiSecure"
    >
      <View
        style={{
          position: 'absolute',
          left: 2.5 * unit,
          top,
          width: d,
          height: d,
          borderRadius: d / 2,
          backgroundColor: palette[v.left],
          opacity: v.opacity,
        }}
      />
      <View
        style={{
          position: 'absolute',
          left: 12.5 * unit,
          top,
          width: d,
          height: d,
          borderRadius: d / 2,
          backgroundColor: palette[v.right],
          opacity: v.opacity,
          mixBlendMode: 'multiply', // native-ok: Android 10+ / iOS only; an older phone shows the two discs overlapping without the darker lens between them
        }}
      />
    </View>
  )
}

/**
 * A board gradient — by `name` (theme/gradients.ts) or by `spec` (a lifted screen
 * carries the board's own angle and stops). The CSS angle is converted to start/end
 * points from the box's MEASURED size (theme/gradients.ts explains why a fixed pair
 * is wrong). Pass `width`/`height` when the size is known up front so the first
 * frame is already correct; otherwise it is measured on layout.
 */

import { useState } from 'react'
import { type LayoutChangeEvent, type StyleProp, type ViewStyle } from 'react-native'
import { LinearGradient } from 'expo-linear-gradient'
import { gradients, gradientProps, type GradientName, type GradientSpec } from '../theme/gradients'

type Props = ({ name: GradientName; spec?: never } | { spec: GradientSpec; name?: never }) & {
  width?: number
  height?: number
  style?: StyleProp<ViewStyle>
  children?: React.ReactNode
  pointerEvents?: 'none' | 'auto' | 'box-none' | 'box-only'
}

export function Gradient({ name, spec, width, height, style, children, pointerEvents }: Props) {
  const [box, setBox] = useState<{ w: number; h: number } | null>(width && height ? { w: width, h: height } : null)

  const onLayout = (e: LayoutChangeEvent) => {
    const { width: w, height: h } = e.nativeEvent.layout
    if (!box || Math.abs(box.w - w) > 0.5 || Math.abs(box.h - h) > 0.5) setBox({ w, h })
  }

  // Before the first measure, assume the design canvas — every full-screen gradient is that shape.
  const props = gradientProps(spec ?? gradients[name], box?.w ?? 397, box?.h ?? 856)

  return (
    <LinearGradient {...props} style={style} onLayout={onLayout} pointerEvents={pointerEvents}>
      {children}
    </LinearGradient>
  )
}

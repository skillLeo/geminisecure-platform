import { Platform, type StyleProp, type ViewStyle } from 'react-native'
import Svg, { Circle, Ellipse, Line, Path, Polygon, Polyline, Rect } from 'react-native-svg'
import { palette, type ColorToken } from '../theme/colors'
import { icons, type IconDef, type IconName } from './icons/registry'

type Props = {
  name: IconName
  size: number
  color: ColorToken
  style?: StyleProp<ViewStyle>
  /** Icon-only controls carry their label on the Pressable; a decorative icon is hidden from screen readers. */
  accessible?: boolean
}

/**
 * A decorative icon is hidden from screen readers — its control carries the label.
 * The props differ by platform on purpose: on web react-native-svg hands unknown
 * props straight to the DOM <svg>, so the native ones (`accessible`,
 * `accessibilityElementsHidden`, `importantForAccessibility`) must never reach it.
 */
function hiddenFromScreenReaders(hidden: boolean): Record<string, unknown> {
  if (Platform.OS === 'web') return hidden ? { 'aria-hidden': true } : {}
  return hidden
    ? { accessible: false, accessibilityElementsHidden: true, importantForAccessibility: 'no-hide-descendants' }
    : { accessible: true }
}

type Cap = 'round' | 'butt' | 'square'
type Join = 'round' | 'miter' | 'bevel'

export function Icon({ name, size, color, style, accessible = false }: Props) {
  const def: IconDef = icons[name]
  const c = palette[color]

  return (
    <Svg width={size} height={size} viewBox={def.viewBox ?? '0 0 24 24'} fill="none" style={style} {...hiddenFromScreenReaders(!accessible)}>
      {def.els.map((el, i) => {
        const paint = {
          fill: el.fill ? c : ('none' as const),
          ...(el.sw !== undefined ? { stroke: c, strokeWidth: el.sw, strokeLinecap: el.cap as Cap | undefined, strokeLinejoin: el.join as Join | undefined } : null),
        }
        switch (el.t) {
          case 'path':
            return <Path key={i} d={el.d} {...paint} />
          case 'circle':
            return <Circle key={i} cx={el.cx} cy={el.cy} r={el.r} {...paint} />
          case 'ellipse':
            return <Ellipse key={i} cx={el.cx} cy={el.cy} rx={el.rx} ry={el.ry} {...paint} />
          case 'rect':
            return <Rect key={i} x={el.x} y={el.y} width={el.w} height={el.h} rx={el.rx} {...paint} />
          case 'line':
            return <Line key={i} x1={el.x1} y1={el.y1} x2={el.x2} y2={el.y2} {...paint} />
          case 'polyline':
            return <Polyline key={i} points={el.points} {...paint} />
          case 'polygon':
            return <Polygon key={i} points={el.points} {...paint} />
        }
      })}
    </Svg>
  )
}

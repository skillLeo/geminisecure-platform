/**
 * Board 31's QR block (`.qr-box`), every value from the design tokens: a 120-point white box with a 1.5 navy-200 edge,
 * radius 14, 4 above and 10 below; in it the code, 88 points across, navy-900.
 *
 * The code is drawn ON THE PHONE from what it carries, with the visitor pass's own QR drawing (visitors/qrPicture.ts):
 * dark on white, and the box's white margin is its quiet zone, so a scanner can read it. What it carries is the
 * booking's own reference — never a code the estate did not issue.
 */

import { useMemo } from 'react'
import { StyleSheet, View } from 'react-native'
import Svg, { Path } from 'react-native-svg'
import { QUIET, qrPathOf } from '@/features/visitors/qrPicture'
import { corner, fill, line, size, space, stroke } from '@/design/tokens'

export function BookingQr({ value, label }: { value: string; label: string }) {
  const { d, count } = useMemo(() => qrPathOf(value), [value])
  // The drawing comes with its own quiet zone; the box's margin is that here, so the view starts at the code itself.
  const across = count - QUIET * 2
  return (
    <View style={s.box} role="img" aria-label={label}>
      <Svg width={size[88]} height={size[88]} viewBox={`${QUIET} ${QUIET} ${across} ${across}`}>
        <Path d={d} fill={fill.night} />
      </Svg>
    </View>
  )
}

const s = StyleSheet.create({
  box: { width: size[120], height: size[120], alignItems: 'center', justifyContent: 'center', backgroundColor: fill.surface, borderRadius: corner[14], borderWidth: stroke['1.5'], borderColor: line.outline, marginTop: space[4], marginBottom: space[10] },
})

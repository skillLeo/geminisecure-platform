/**
 * A pass's QR code, drawn ON THE PHONE from the signed token it already holds — no image is
 * fetched, so it renders with no connection at all (which is exactly when a visitor at a gate
 * with no signal needs it). Dark on white with a quiet zone, always: a guard's scanner reads
 * contrast, not brand colours.
 *
 * In board 31's QR box (Booking confirmed's `.qr-box`): white, a 1.5 navy-200 edge, corners of 14, the code navy-900
 * inside it with its quiet zone as the box's own margin. `size` is the whole box, edge included.
 */

import { useMemo } from 'react'
import { StyleSheet, View } from 'react-native'
import Svg, { Path, Rect } from 'react-native-svg'
import qrcode from 'qrcode-generator'
import { c } from '@gs/core'
import { corner, fill, ink, line, stroke } from '@/design/tokens'

const QUIET = 3 // modules of white around the code

export function PassQr({ token, size: side, label }: { token: string; size: number; label: string }) {
  const { path, count } = useMemo(() => {
    // Medium error correction: survives a cracked screen protector, stays small enough to scan quickly.
    const qr = qrcode(0, 'M')
    qr.addData(token)
    qr.make()
    const n = qr.getModuleCount()
    let d = ''
    for (let row = 0; row < n; row++) {
      let run = 0
      for (let col = 0; col <= n; col++) {
        const dark = col < n && qr.isDark(row, col)
        if (dark) run++
        else if (run > 0) {
          d += `M${col - run + QUIET} ${row + QUIET}h${run}v1h-${run}z`
          run = 0
        }
      }
    }
    return { path: d, count: n + QUIET * 2 }
  }, [token])

  // The code fills the box inside its edge.
  const inner = side - 2 * stroke['1.5']

  return (
    <View role="img" aria-label={label} style={[s.box, { width: side, height: side }]}>
      <Svg width={inner} height={inner} viewBox={`0 0 ${count} ${count}`}>
        <Rect width={count} height={count} fill={fill.surface} />
        <Path d={path} fill={c(ink.heading)} />
      </Svg>
    </View>
  )
}

const s = StyleSheet.create({
  // .qr-box
  box: { alignItems: 'center', justifyContent: 'center', overflow: 'hidden', backgroundColor: fill.surface, borderWidth: stroke['1.5'], borderColor: line.outline, borderRadius: corner[14] },
})

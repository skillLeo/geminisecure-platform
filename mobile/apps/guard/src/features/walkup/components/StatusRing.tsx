/**
 * Board guard-app-06's ring (120 square, r 52, stroke 7, drawn from 12 o'clock). While waiting
 * it is the countdown; once there is an answer it closes in the answer's colour around a glyph.
 */

import type { ReactNode } from 'react'
import { StyleSheet, View } from 'react-native'
import Svg, { Circle } from 'react-native-svg'
import { c, type ColorToken } from '@gs/core'
import { RING_LENGTH } from '../walkup'

export function StatusRing({ tone, offset, children }: { tone: ColorToken; offset: number; children: ReactNode }) {
  return (
    <View style={s.waitRingWrap}>
      <Svg width={120} height={120} viewBox="0 0 120 120" fill="none" style={s.waitRingWrapSvg}>
        <Circle cx="60" cy="60" r="52" fill="none" stroke={c('border-700')} strokeWidth={7} />
        {offset >= RING_LENGTH ? null : (
          <Circle cx="60" cy="60" r="52" strokeDasharray={String(RING_LENGTH)} strokeDashoffset={String(offset)} fill="none" stroke={c(tone)} strokeWidth={7} strokeLinecap="round" />
        )}
      </Svg>
      <View style={s.waitRingTxt}>{children}</View>
    </View>
  )
}

const s = StyleSheet.create({
  waitRingWrapSvg: { overflow: 'hidden', transform: [{ rotate: '-90deg' }] },
  waitRingTxt: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center' },
  waitRingWrap: { width: 120, height: 120, flexDirection: 'row', flexWrap: 'wrap', alignItems: 'flex-start', marginBottom: 20 },
})

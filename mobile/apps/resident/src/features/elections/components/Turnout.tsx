/**
 * Board 28's turnout bars, every value from the design tokens. They draw what they are given and keep nothing.
 *
 *   TurnoutBar  .turnout-bar   7 tall, round ends of 6, white at .15, filled gold to the share; 10 under it
 *   PhaseBars   .phase-bars    a well per phase, 6 apart: 34 tall, white at .12, gold from the bottom to its share
 *                              (rounded at the top), its label 4 under it (8.5 · 700, white at .6)
 */

import { StyleSheet, View } from 'react-native'
import { Text } from '@gs/core'
import { corner, fill, font, inkAlpha, size, space } from '@/design/tokens'

export function TurnoutBar({ percent }: { percent: number }) {
  return (
    <View style={s.bar} role="progressbar" aria-label={`Turnout ${percent} percent`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
      <View style={[s.barFill, { width: `${percent}%` }]} />
    </View>
  )
}

export function PhaseBars({ phases }: { phases: readonly { label: string; percent: number }[] }) {
  return (
    <View style={s.phases}>
      {phases.map((phase) => (
        <View key={phase.label} style={s.phase}>
          <View style={s.well}><View style={[s.wellFill, { height: `${phase.percent}%` }]} /></View>
          <View style={s.label}><Text {...font.inter700_8_5} align="center" style={s.labelText}>{phase.label}</Text></View>
        </View>
      ))}
    </View>
  )
}

const s = StyleSheet.create({
  bar: { height: size[7], borderRadius: corner[6], backgroundColor: fill.onDarkBar, overflow: 'hidden', marginBottom: space[10] },
  barFill: { height: '100%', borderRadius: corner[6], backgroundColor: fill.gold },
  phases: { flexDirection: 'row', gap: space[6] },
  phase: { flex: 1, minWidth: 0 },
  well: { height: size[34], flexDirection: 'row', alignItems: 'flex-end', borderRadius: corner[6], backgroundColor: fill.onDarkTrack, overflow: 'hidden' },
  wellFill: { width: '100%', borderTopLeftRadius: corner[6], borderTopRightRadius: corner[6], backgroundColor: fill.gold },
  label: { marginTop: space[4] },
  labelText: { color: inkAlpha.onDarkLabel },
})

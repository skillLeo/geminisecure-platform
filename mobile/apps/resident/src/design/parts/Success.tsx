/**
 * The Resident boards' SUCCESS PATTERN (Payment successful, Booking confirmed, Vote submitted), for the rebuilt
 * screens: the gold tick in its two faint rings → the title (Poppins 600 20) and its line (12 · slate-500, centred) →
 * the detail card (label · value lines, an optional total under a rule) → the info card (navy-100, a navy-600 note
 * icon, 10.5 · 500 · navy-800) → two buttons side by side.
 */

import type { ReactNode } from 'react'
import { StyleSheet, View } from 'react-native'
import { Icon, Text, type IconName } from '@gs/core'
import { fill, font, icon, ink, line, radius, size, space, stroke } from '../tokens'
import { Card } from './Card'
import { ButtonRow } from './Buttons'

export function GoldTick() {
  return (
    <View style={s.tickWrap}>
      <View style={s.ringOuter} />
      <View style={s.ringInner} />
      <View style={s.tick}><Icon name="check" size={icon[30]} color={ink.heading} /></View>
    </View>
  )
}

export function DetailLines({ lines, total }: { lines: { label: string; value: string }[]; total?: { label: string; value: string } }) {
  return (
    <Card style={s.detail}>
      {lines.map((l) => (
        <View key={l.label} style={s.line}>
          <View style={s.shrink}><Text {...font.inter400_12} color={ink.label}>{l.label}</Text></View>
          <View style={s.shrink}><Text {...font.inter700_12} color={ink.heading}>{l.value}</Text></View>
        </View>
      ))}
      {total ? (
        <View style={s.total}>
          <View style={s.shrink}><Text {...font.inter700_13} color={ink.heading}>{total.label}</Text></View>
          <View style={s.shrink}><Text {...font.inter700_15} color={ink.onTint}>{total.value}</Text></View>
        </View>
      ) : null}
    </Card>
  )
}

export function InfoCard({ text, icon: glyph = 'why-note' }: { text: string; icon?: IconName }) {
  return (
    <View style={s.info}>
      <Icon name={glyph} size={icon[15]} color={ink.action} style={s.infoIcon} />
      <View style={s.shrink}><Text {...font.inter500_10_5_lh1_5} color={ink.body}>{text}</Text></View>
    </View>
  )
}

export function Success({ title, line: sub, details, total, info, buttons }: { title: string; line?: string; details?: { label: string; value: string }[]; total?: { label: string; value: string }; info?: string; buttons: ReactNode }) {
  return (
    <View style={s.wrap}>
      <GoldTick />
      <Text {...font.poppins600_20} color={ink.heading} align="center">{title}</Text>
      {sub ? <View style={s.sub}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{sub}</Text></View> : null}
      {details ? <DetailLines lines={details} total={total} /> : null}
      {info ? <InfoCard text={info} /> : null}
      <ButtonRow style={s.buttons}>{buttons}</ButtonRow>
    </View>
  )
}

const s = StyleSheet.create({
  wrap: { alignItems: 'center', paddingTop: space[30], paddingHorizontal: space[24] },
  tickWrap: { width: size[84], height: size[84], alignItems: 'center', justifyContent: 'center', marginBottom: space[16] },
  ringOuter: { position: 'absolute', width: size[100], height: size[100], borderRadius: radius.avatar(size[100]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.14 },
  ringInner: { position: 'absolute', width: size[84], height: size[84], borderRadius: radius.avatar(size[84]), borderWidth: stroke['1.5'], borderColor: line.focus, opacity: 0.28 },
  tick: { width: size[62], height: size[62], borderRadius: radius.avatar(size[62]), alignItems: 'center', justifyContent: 'center', backgroundColor: fill.gold, zIndex: 2 },
  sub: { marginTop: space[5] },
  detail: { width: '100%', marginTop: space[20], marginBottom: space[12] },
  line: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: space[7] },
  total: { flexDirection: 'row', justifyContent: 'space-between', borderTopWidth: stroke[1], borderTopColor: line.hairline, paddingTop: space[11], paddingBottom: space[7], marginTop: space[5] },
  info: { flexDirection: 'row', gap: space[9], backgroundColor: fill.tint, borderRadius: radius.tile, paddingHorizontal: space[12], paddingVertical: space[11], marginTop: space[2] },
  infoIcon: { marginTop: space[1] },
  shrink: { flexShrink: 1 },
  buttons: { marginTop: space[16] },
})

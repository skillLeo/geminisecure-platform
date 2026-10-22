/**
 * Board 31's booking card (`.booking-card` › `.bk-row` · `.bk-code`), every value from the design tokens: navy-100,
 * radius 16, 16 in, the full width, 18 above and 14 below. Each row: a 16-point navy-600 icon, 11 from its words
 * (12.5 · 600 · navy-900), 6 above and below. Then, under a navy-200 rule 8 below the rows and 12 in, centred: the code's
 * label (9.5 · 700 · slate-500) and the code (16 · 700 · navy-900, 1.5 apart, 4 below).
 */

import { StyleSheet, View } from 'react-native'
import { Icon, Text, type IconName } from '@gs/core'
import { fill, font, icon, ink, line, radius, space, stroke } from '@/design/tokens'

export function BookingCard({ rows, codeLabel, code }: { rows: { icon: IconName; text: string }[]; codeLabel: string; code: string }) {
  return (
    <View style={s.card}>
      {rows.map((row) => (
        <View key={`${row.icon}-${row.text}`} style={s.row}>
          <Icon name={row.icon} size={icon[16]} color={ink.action} />
          <View style={s.words}><Text {...font.inter600_12_5} color={ink.heading}>{row.text}</Text></View>
        </View>
      ))}
      <View style={s.code}>
        <Text {...font.inter700_9_5} color={ink.secondary} align="center">{codeLabel}</Text>
        <View style={s.codeValue}>
          <Text {...font.inter700_16_ls1_5} color={ink.heading} align="center" selectable>{code}</Text>
        </View>
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  card: { width: '100%', backgroundColor: fill.tint, borderRadius: radius.card, padding: space[16], marginTop: space[18], marginBottom: space[14] },
  row: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[6] },
  words: { flex: 1, minWidth: 0 },
  code: { alignItems: 'center', borderTopWidth: stroke[1], borderTopColor: line.outline, paddingTop: space[12], marginTop: space[8] },
  codeValue: { marginTop: space[4] },
})

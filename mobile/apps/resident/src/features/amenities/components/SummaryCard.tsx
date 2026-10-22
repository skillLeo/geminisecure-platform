/**
 * Board 22's summary card (`.card` › `.price-row` · `.price-total` · `.price-note`), in the shared Card and every value
 * from the design tokens: each row's words (12.5 · slate-600) against its amount (12.5 · 700 · navy-900), 7 above and
 * below; the total under a navy-100 rule, 5 below the rows and 11 in (13.5 · 700 · navy-900 against 16 · 700 ·
 * navy-700); and the note under it (10 · slate-500, 4 below).
 */

import { StyleSheet, View } from 'react-native'
import { Text } from '@gs/core'
import { Card } from '@/design/parts'
import { font, ink, line, space, stroke } from '@/design/tokens'

type Line = { label: string; value: string }

export function SummaryCard({ rows, total, note }: { rows: Line[]; total: Line; note: string }) {
  return (
    <Card>
      {rows.map((row) => (
        <View key={row.label} style={s.row}>
          <View style={s.words}><Text {...font.inter400_12_5} color={ink.label}>{row.label}</Text></View>
          <Text {...font.inter700_12_5} color={ink.heading}>{row.value}</Text>
        </View>
      ))}
      <View style={s.total}>
        <View style={s.words}><Text {...font.inter700_13_5} color={ink.heading}>{total.label}</Text></View>
        <Text {...font.inter700_16} color={ink.onTint}>{total.value}</Text>
      </View>
      <View style={s.note}>
        <Text {...font.inter400_10} color={ink.secondary}>{note}</Text>
      </View>
    </Card>
  )
}

const s = StyleSheet.create({
  // .price-row
  row: { flexDirection: 'row', justifyContent: 'space-between', gap: space[12], paddingVertical: space[7] },
  words: { flexShrink: 1 },
  // .price-total
  total: { flexDirection: 'row', justifyContent: 'space-between', gap: space[12], borderTopWidth: stroke[1], borderTopColor: line.hairline, paddingTop: space[11], paddingBottom: space[3], marginTop: space[5] },
  // .price-note
  note: { marginTop: space[4] },
})

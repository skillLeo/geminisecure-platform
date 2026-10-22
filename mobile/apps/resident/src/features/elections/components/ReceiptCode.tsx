/**
 * `.receipt-box` — board 16's receipt card, every value from the design tokens: navy-100, radius 14, 16 inside, centred;
 * its label (10.5 · 700 · slate-600), the code (17 · 700, 1.5 apart, navy-900) and the line under it (10 · slate-500),
 * 8 between each. It draws what it is given and keeps nothing; given no code, it draws the label and the line, 8 apart.
 * The space around it is the screen's.
 */

import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { Text } from '@gs/core'
import { corner, fill, font, ink, space } from '@/design/tokens'

export function ReceiptCode({ label, code, caption, style }: { label: string; code: string | null; caption: string; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[s.box, style]}>
      <Text {...font.inter700_10_5} color={ink.label} align="center">{label}</Text>
      {code ? (
        <View style={s.next}><Text {...font.inter700_17_ls1_5} color={ink.heading} align="center">{code}</Text></View>
      ) : null}
      <View style={s.next}><Text {...font.inter400_10} color={ink.secondary} align="center">{caption}</Text></View>
    </View>
  )
}

const s = StyleSheet.create({
  box: { alignSelf: 'stretch', backgroundColor: fill.tint, borderRadius: corner[14], padding: space[16] },
  next: { marginTop: space[8] },
})

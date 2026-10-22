/**
 * The settings boards' own row pieces that the shared parts (design/parts) do not draw, every value from the tokens:
 *
 *   Divider   .set-div   1 tall, navy-100, starting 46 in from the card's edge — board 33's .row-div starts 48 in
 *   TRow      .trow      a switch row: an optional icon tile (.set-icon), the title (13 · 600 · navy-900) over its
 *                        sub-line (10.5 · slate-500, 2 below, line-height 1.4), the switch on the right; 12 above
 *                        and below (11 on the boards of the "10 Bookings Support and AutoPay" file: `pad={11}`),
 *                        12 between
 *
 * The shared ListRow's `divider` is a rule across the whole row; the boards draw theirs between the rows, indented.
 * ListRow's sub-line is .set-txt's (1 below, no line-height); a .trow's is 2 below at 1.4, so it is drawn here.
 */

import type { ReactNode } from 'react'
import { StyleSheet, View } from 'react-native'
import { Text, type IconName } from '@gs/core'
import { IconTile } from '@/design/parts'
import { font, ink, line, role, size, space } from '@/design/tokens'

export function Divider({ inset = 46 }: { inset?: 46 | 48 }) {
  return <View style={inset === 48 ? s.div48 : s.div46} />
}

export function TRow({ icon, title, line: sub, pad = 12, children }: { icon?: IconName; title: string; line?: string | null; pad?: 11 | 12; children: ReactNode }) {
  return (
    <View style={pad === 11 ? s.row11 : s.row}>
      {icon ? <IconTile name={icon} /> : null}
      <View style={s.text}>
        <Text {...role.rowTitle} color={ink.heading}>{title}</Text>
        {sub ? <View style={s.sub}><Text {...font.inter400_10_5_lh1_4} color={ink.secondary}>{sub}</Text></View> : null}
      </View>
      {children}
    </View>
  )
}

const s = StyleSheet.create({
  div46: { height: size[1], backgroundColor: line.hairline, marginLeft: space[46] },
  div48: { height: size[1], backgroundColor: line.hairline, marginLeft: space[48] },
  row: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[12] },
  row11: { flexDirection: 'row', alignItems: 'center', gap: space[12], paddingVertical: space[11] },
  text: { flex: 1, minWidth: 0 },
  sub: { marginTop: space[2] },
})

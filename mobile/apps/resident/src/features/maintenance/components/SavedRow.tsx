/**
 * A row of All requests that is still ON THIS PHONE — waiting for a connection, or being sent now — drawn from the
 * design tokens (no board draws this list). It keeps the card every row of the list has — white, a 1-point navy-100
 * edge, corners of 16, the soft card shadow, a 40-point navy-100 tile — and its tile holds the same honest mark the
 * request's own screen shows after Submit: the phone, never a tick, as nothing has reached the estate office yet.
 *
 * Its words are requestList.ts's, unchanged: the kind, the title, "Saved on this phone … — not sent yet", and the
 * amber "Pending sync" or "Syncing". The line that says it is not sent is never cut. It opens nothing (the server has
 * given it no id), and a screen reader hears all of it as one status.
 */

import { StyleSheet, View } from 'react-native'
import { Icon, Text } from '@gs/core'
import { fill, font, icon, ink, line, radius, shadow, size, space, stroke } from '@/design/tokens'
import type { RequestRow } from '../requestList'

export function SavedRow({ row }: { row: RequestRow }) {
  return (
    <View style={s.row} role="status" aria-label={row.label}>
      <View style={s.tile}>
        <Icon name="device-row" size={icon[18]} color={ink.action} />
      </View>
      <View style={s.words}>
        <Text {...font.inter700_9_5} color={ink.label} numberOfLines={1}>{row.kindLabel.toUpperCase()}</Text>
        <View style={s.next}><Text {...font.inter700_12_5} color={ink.heading} numberOfLines={2}>{row.title}</Text></View>
        <View style={s.next}><Text {...font.inter400_11_lh1_45} color={ink.label}>{row.detail}</Text></View>
      </View>
      <View style={s.pill}>
        <Text {...font.inter700_10} color={ink.onGoldTint} numberOfLines={1}>{row.chip.label}</Text>
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  row: { minHeight: size[64], flexDirection: 'row', alignItems: 'center', gap: space[10], backgroundColor: fill.surface, borderRadius: radius.card, borderWidth: stroke[1], borderColor: line.hairline, paddingHorizontal: space[12], paddingVertical: space[10], marginTop: space[8], ...shadow.cardSoft },
  tile: { width: size[40], height: size[40], flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.tint, borderRadius: radius.tile },
  words: { flex: 1, minWidth: 0 },
  next: { marginTop: space[2] },
  pill: { flexShrink: 0, backgroundColor: fill.goldTint, borderRadius: radius.chip, paddingHorizontal: space[9], paddingVertical: space[4] },
})

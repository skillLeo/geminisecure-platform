/**
 * The Resident boards' card, row and badge pieces, for the rebuilt screens (M44 §1):
 *
 *   Card      .card        white, a 1-point navy-100 edge, radius 16, the soft card shadow; padding 13 (settings,
 *                          profile) or 14 (dues, notices, status), 10 or 12 under it
 *   IconTile  .set-icon    a 34-point square, radius 10, navy-100, a 16-point navy-600 icon — and its other sizes
 *   ListRow   .set-row     icon tile, a title (13 · 600 · navy-900) over a line (10.5 · slate-500), and on the right
 *                          a badge, a toggle, a chevron or anything else; 12 above and below, 12 between
 *   Badge     .tag         9 · 700, 3 × 8, round ends: navy-100 with navy-700, or `urgent` gold with navy-900
 */

import type { ReactNode } from 'react'
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { Icon, Pressable, Text, type IconName } from '@gs/core'
import { fill, font, icon, ink, line, radius, role, shadow, size, space, stroke } from '../tokens'

/**
 * `pressed` (M50 §6.7a): the whole card darkens slightly, as one piece — its own fill, never a fade. A fade on the card's
 * wrapper let Android draw the card's shadow through the faded card, a pale band across the name, the line and the
 * price. Every tappable card and row in the app is pressed this way (`PRESSED`).
 */
export function Card({ children, pad = 14, style, pressed }: { children: ReactNode; pad?: 13 | 14; style?: StyleProp<ViewStyle>; pressed?: boolean }) {
  return <View style={[pad === 13 ? s.card13 : s.card14, style, pressed ? PRESSED : null]}>{children}</View>
}

/** How a pressed card or row looks (M50 §6.7a, U11): its fill one step darker, as one piece — nothing drawn through it. */
export const PRESSED = { backgroundColor: fill.tint } as const

type TileSize = 34 | 36 | 40 | 44
export function IconTile({ name, tile = 34, glyph = 16, tint = fill.tint, color = ink.action, shape = 'icon' }: { name: IconName; tile?: TileSize; glyph?: keyof typeof icon; tint?: string; color?: (typeof ink)[keyof typeof ink]; shape?: 'icon' | 'tile' }) {
  return (
    <View style={[s.tile, { width: size[tile], height: size[tile], borderRadius: shape === 'tile' ? radius.tile : radius.iconTile, backgroundColor: tint }]}>
      <Icon name={name} size={icon[glyph]} color={color} />
    </View>
  )
}

export function Badge({ label, urgent }: { label: string; urgent?: boolean }) {
  return (
    <View style={urgent ? s.badgeUrgent : s.badge}>
      <Text {...role.tag} color={urgent ? ink.heading : ink.onTint} numberOfLines={1}>{label}</Text>
    </View>
  )
}

export function ListRow({ icon: glyph, title, line: sub, right, chevron, onPress, divider, label }: { icon?: IconName; title: string; line?: string | null; right?: ReactNode; chevron?: boolean; onPress?: () => void; divider?: boolean; label?: string }) {
  const body = (
    <>
      {glyph ? <IconTile name={glyph} /> : null}
      <View style={s.rowText}>
        <Text {...role.rowTitle} color={ink.heading}>{title}</Text>
        {sub ? <View style={s.rowSub}><Text {...font.inter400_10_5} color={ink.secondary}>{sub}</Text></View> : null}
      </View>
      {right}
      {chevron ? <Icon name="chevron-right" size={icon[16]} color={ink.faint} /> : null}
    </>
  )
  const look = [s.row, divider ? s.rowDivider : null]
  return onPress ? (
    <Pressable onPress={onPress} role="button" aria-label={label ?? title} style={({ pressed }) => [look, pressed ? s.pressed : null]}>{body}</Pressable>
  ) : (
    <View style={look}>{body}</View>
  )
}

const card = { backgroundColor: fill.surface, borderRadius: radius.card, borderWidth: stroke[1], borderColor: line.hairline, ...shadow.cardSoft } as const

const s = StyleSheet.create({
  card13: { ...card, padding: space[13], marginBottom: space[10] },
  card14: { ...card, padding: space[14], marginBottom: space[12] },
  tile: { flexShrink: 0, alignItems: 'center', justifyContent: 'center' },
  badge: { alignSelf: 'flex-start', backgroundColor: fill.tint, borderRadius: radius.chip, paddingVertical: space[3], paddingHorizontal: space[8] },
  badgeUrgent: { alignSelf: 'flex-start', backgroundColor: fill.gold, borderRadius: radius.chip, paddingVertical: space[3], paddingHorizontal: space[8] },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: space[12], gap: space[12] },
  rowDivider: { borderTopWidth: stroke[1], borderTopColor: line.hairline },
  rowText: { flex: 1, minWidth: 0 },
  rowSub: { marginTop: space[1] },
  pressed: PRESSED,
})

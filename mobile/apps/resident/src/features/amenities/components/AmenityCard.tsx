/**
 * Board 21's amenity card (`.card` › `.amenity-row`), every value from the design tokens and the shared Card part:
 *
 *   AmenityIcon   .amenity-icon    a 56-point tile, radius 14, the hero gradient, the place's picture 26 in gold
 *   AmenityCard   .amenity-row     the tile, then the name (13.5 · 700 · navy-900), the line under it (10.5 · slate-500,
 *                                  2 below) and the price (11.5 · 700 · navy-600, 3 below), 13 apart, and on the right
 *                                  the gold "Available" pill (.amenity-avail · 9.5 · 700, 4 × 9, round ends)
 *
 * The pill is drawn only for an amenity this household can book; pressed, the card says what it can do.
 */

import { StyleSheet, View } from 'react-native'
import { Gradient, Icon, Pressable, Text, type IconName } from '@gs/core'
import { Card } from '@/design/parts'
import { fill, font, gradient, icon, ink, radius, role, size, space } from '@/design/tokens'
import type { AmenityCard as Model } from '../useAmenities'

/** The board's pill, word for word. */
const AVAILABLE = 'Available'

export function AmenityIcon({ name }: { name: IconName }) {
  return (
    <Gradient spec={gradient.hero} style={s.tile}>
      <Icon name={name} size={icon[26]} color={ink.accent} />
    </Gradient>
  )
}

export function AmenityCard({ card, onPress }: { card: Model; onPress: () => void }) {
  // Pressed, the whole card darkens as one piece (M50 §6.7a) — never a fade of its wrapper, which drew a pale band
  // across the name, the line and the price.
  return (
    <Pressable onPress={onPress} role="button" aria-label={card.label}>
      {({ pressed }) => (
      <Card pressed={pressed}>
        <View style={s.row}>
          <AmenityIcon name={card.icon} />
          <View style={s.info}>
            <Text {...role.cardTitle} color={ink.heading}>{card.name}</Text>
            {card.detail ? <View style={s.detail}><Text {...font.inter400_10_5} color={ink.secondary}>{card.detail}</Text></View> : null}
            <View style={s.price}><Text {...font.inter700_11_5} color={ink.action}>{card.price}</Text></View>
          </View>
          {card.available ? (
            <View style={s.pill}><Text {...role.statusChip} color={ink.onGoldTint}>{AVAILABLE}</Text></View>
          ) : null}
        </View>
      </Card>
      )}
    </Pressable>
  )
}

const s = StyleSheet.create({
  // .amenity-row
  row: { flexDirection: 'row', alignItems: 'center', gap: space[13] },
  // .amenity-icon
  tile: { width: size[56], height: size[56], borderRadius: radius.quickTile, overflow: 'hidden', flexShrink: 0, alignItems: 'center', justifyContent: 'center' },
  // .amenity-info · .ac · .ar
  info: { flex: 1, minWidth: 0 },
  detail: { marginTop: space[2] },
  price: { marginTop: space[3] },
  // .amenity-avail
  pill: { flexShrink: 0, backgroundColor: fill.goldTint, borderRadius: radius.chip, paddingVertical: space[4], paddingHorizontal: space[9] },
})

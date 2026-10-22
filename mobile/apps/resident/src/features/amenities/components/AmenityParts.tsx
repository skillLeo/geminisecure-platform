/**
 * The parts the four amenity screens share. Styles are the boards' own (06 Amenities · 08 · 10).
 *
 * `gated()` is the feature flag: with `flags.amenityBookings` off, EVERY bookings route draws
 * one state — "Bookings are not available at this estate" — and a way back, and none of the screens'
 * hooks ever run (no request is made for a feature the estate has not turned on). `isFidelity` lets
 * the board's drawing be measured whatever the flag says; it is a build-time `false` on a phone.
 */

import type { ComponentType } from 'react'
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native'
import { router } from 'expo-router'
import { EmptyState, Gradient, Icon, ResidentAppBar, Text, c, isFidelity, type ColorToken, type IconName } from '@gs/core'
import { flags } from '@gs/core/lib'
import { NOT_AVAILABLE } from '../amenities'

const bookingsOn: boolean = flags.amenityBookings

export function BookingsUnavailable() {
  return (
    <View style={s.screen}>
      <ResidentAppBar title="Bookings" fallback="/(tabs)" />
      <EmptyState
        tone="light"
        icon="amenity-icon"
        title={NOT_AVAILABLE}
        body="Your estate has not turned on amenity bookings in the app. The estate office can tell you how to reserve a space."
        action={{ label: 'Back to Home', onPress: () => router.replace('/(tabs)' as never) }}
      />
    </View>
  )
}

export function gated(Screen: ComponentType): ComponentType {
  return function GatedBookings() {
    if (!bookingsOn && !isFidelity) return <BookingsUnavailable />
    return <Screen />
  }
}

/** The navy tile with the amenity's picture — 56 on the list, 44 on a booking row. */
export function AmenityTile({ icon, size }: { icon: IconName; size: 56 | 44 }) {
  return (
    <Gradient spec={{ angle: 135, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-800', at: 1 }] }} style={size === 56 ? s.tile56 : s.tile44}>
      <Icon name={icon} size={size === 56 ? 26 : 20} color="amber-500" />
    </Gradient>
  )
}

// No red note (M98, 12 Oct 2026): every problem here is an ordinary one, amber; red is an open panic's colour.
type NoteTone = 'navy' | 'amber'
const NOTE: Record<NoteTone, { box: ViewStyle; ink: ColorToken; icon: ColorToken }> = {
  navy: { box: { backgroundColor: c('navy-100') }, ink: 'navy-800', icon: 'navy-600' },
  amber: { box: { backgroundColor: c('amber-100') }, ink: 'amber-700', icon: 'amber-700' },
}

/** The boards' `.confirm-note`: an icon and a short paragraph on a tinted panel. */
export function Note({ text, title, tone = 'navy', icon = 'confirm-note', size = 11.5, alert, style }: { text: string; title?: string; tone?: NoteTone; icon?: IconName; size?: number; alert?: boolean; style?: StyleProp<ViewStyle> }) {
  const look = NOTE[tone]
  return (
    <View style={[s.note, look.box, style]} role={alert ? 'alert' : undefined}>
      <Icon name={icon} size={15} color={look.icon} style={s.noteIcon} />
      <View style={s.shrink}>
        {title ? <Text face="Inter-Bold" size={size} lh={1.5} color={look.ink}>{title}</Text> : null}
        <Text face="Inter-Medium" size={size} lh={1.5} color={look.ink}>{text}</Text>
      </View>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { backgroundColor: c('white'), overflow: 'hidden', flex: 1 },
  shrink: { flexShrink: 1 },
  tile56: { width: 56, height: 56, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 14 },
  tile44: { width: 44, height: 44, flexGrow: 0, flexShrink: 0, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 12 },
  noteIcon: { width: 15, height: 15, flexGrow: 0, flexShrink: 0, overflow: 'hidden', marginTop: 1 },
  note: { flexDirection: 'row', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 11, gap: 9 },
})

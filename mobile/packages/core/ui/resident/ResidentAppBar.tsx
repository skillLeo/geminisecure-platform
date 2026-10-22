/**
 * The Resident App's in-screen header (.appbar on the resident boards): a navy gradient, title Poppins-SemiBold 16.5
 * white, centred, with round side buttons.
 *
 * THE STATUS BAR IS ALLOWED FOR ONCE, HERE (M18E). The bar used to be `insets.top + 46` tall with its content pinned
 * 16 from the bottom — and a 34-point button in 30 points of room rose 4 points into the status bar, with its touch
 * area 5 more: on a phone with a tall status bar the panic button sat under the clock. Now the bar is the top inset
 * PLUS a 56-point row, everything in that row centred on its middle, so a button is always wholly below the status bar
 * and wholly inside the navy. In landscape the side insets (a notch, a camera cut-out) are added to the side padding.
 * The title is the one thing that gives way: it truncates to one line, and the buttons keep their width.
 *
 * NOTHING IN THE BAR THE DESIGN DOES NOT DRAW (M47 U1–U3), on every screen still drawn with this bar:
 *   - no red panic shield (U2). The Panic control lives on Home, top right, and nowhere else (it replaced M12 §2.2's
 *     button on every bar). `PanicBarButton` stays exported for the one place that still asks for it by name;
 *   - no "Estate time · 9:38 AM" under the title (U1) — not one of the design's screens has it. Home keeps its own
 *     line in the hero (`useEstateClockLine`), which the client froze;
 *   - no `i` beside the title (U3). `panic` and `info` are still accepted, and draw nothing.
 *
 * Under the fidelity harness the board's own geometry is drawn (100 tall including its 54 status bar, content 16 from
 * the bottom, 34-point buttons) with no panic button and no clock: the boards have neither.
 */

import { View } from 'react-native'
import { router } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { isFidelity, now } from '../../fidelity'
import { estateClockLine, useMinute, useSiteZone } from '../../lib/siteTime'
import { alpha, color } from '../../theme/colors'
import { layout } from '../../theme/spacing'
import { Gradient } from '../Gradient'
import { Icon } from '../Icon'
import type { InfoTopic } from '../InfoButton'
import { Pressable } from '../Pressable'
import { Text } from '../Text'
import type { GradientSpec } from '../../theme/gradients'
import type { IconName } from '../icons/registry'

type Side = { icon: IconName; iconSize?: number; label: string; onPress: () => void } | 'back' | null

const NAVY: GradientSpec = { angle: 135, stops: [{ token: 'navy-600', at: 0 }, { token: 'navy-700', at: 1 }] }

/** A side button's width on the board; live, every button is 40 with its touch area padded to 44. */
const BOARD_SIDE = 34
const LIVE_SIDE = 40
/** The row under the status bar, live: a 40-point button with 8 clear above and below. */
export const APP_BAR_ROW = 56

const sideOf = (): number => (isFidelity ? BOARD_SIDE : LIVE_SIDE)

function SideButton({ side, fallback }: { side: Side; fallback: string }) {
  const size = sideOf()
  if (side === null) return <View style={{ width: size }} />
  const s = side === 'back' ? { icon: 'chevron-left' as IconName, iconSize: 18, label: 'Back', onPress: () => (router.canGoBack() ? router.back() : router.replace(fallback as never)) } : side
  return (
    <Pressable
      onPress={s.onPress}
      role="button"
      aria-label={s.label}
      hitSlop={isFidelity ? 5 : 2}
      style={({ pressed }) => ({ width: size, height: size, borderRadius: size / 2, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: alpha('white', pressed ? 0.28 : 0.16) })}
    >
      <Icon name={s.icon} size={s.iconSize ?? 18} color="white" />
    </Pressable>
  )
}

export function PanicBarButton() {
  const size = sideOf()
  return (
    <Pressable
      onPress={() => router.push('/panic' as never)}
      role="button"
      aria-label="Panic alert"
      accessibilityHint="Opens the panic alert. It works with no connection."
      hitSlop={isFidelity ? 5 : 2}
      style={({ pressed }) => ({ width: size, height: size, borderRadius: size / 2, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: color('red-700'), borderWidth: 1.5, borderColor: color('white'), opacity: pressed ? 0.85 : 1 })}
    >
      <Icon name="shield-alert" size={isFidelity ? 17 : 19} color="white" />
    </Pressable>
  )
}

/**
 * "Estate time · 9:38 AM", redrawn as each minute turns — only on a phone set to another zone (M19A §5.1). Null at the
 * estate, which is every resident who lives there.
 */
export function useEstateClockLine(): string | null {
  useMinute()
  useSiteZone()
  return isFidelity ? null : estateClockLine(now())
}

export function ResidentAppBar({ title, left = 'back', right = null, fallback = '/(tabs)' }: { title: string; left?: Side; right?: Side; fallback?: string; /** Retired (M47 U2): no header draws the panic shield. */ panic?: boolean; /** Retired (M47 U3): no title carries an `i`. */ info?: InfoTopic }) {
  const insets = useSafeAreaInsets()
  const side = sideOf()

  const row = (
    <>
      <View style={{ flexDirection: 'row', width: side, flexShrink: 0 }}>
        <SideButton side={left} fallback={fallback} />
      </View>
      <View style={{ flex: 1, minWidth: 0, paddingHorizontal: 6 }}>
        <Text face="Poppins-SemiBold" size={16.5} color="white" align="center" role="heading" numberOfLines={1} ellipsizeMode="tail">{title}</Text>
      </View>
      <View style={{ flexDirection: 'row', justifyContent: 'flex-end', width: side, flexShrink: 0 }}>
        <SideButton side={right} fallback={fallback} />
      </View>
    </>
  )

  if (isFidelity) {
    return (
      <Gradient spec={NAVY} style={{ height: insets.top + layout.headerContent, flexDirection: 'row', alignItems: 'flex-end', paddingBottom: 16, paddingHorizontal: 18 }}>
        <StatusBar style="light" />
        {row}
      </Gradient>
    )
  }

  return (
    <Gradient spec={NAVY} style={{ paddingTop: insets.top, paddingLeft: 14 + insets.left, paddingRight: 14 + insets.right }}>
      <StatusBar style="light" />
      <View style={{ minHeight: APP_BAR_ROW, flexDirection: 'row', alignItems: 'center', paddingVertical: 8 }}>{row}</View>
    </Gradient>
  )
}

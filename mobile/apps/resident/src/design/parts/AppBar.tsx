/**
 * `.appbar` on the Resident boards, for the rebuilt screens (M44 §1): the header gradient, the title centred in
 * Poppins 600 16.5 white, a 34-point round side button at each end.
 *
 * THE BOARD'S GEOMETRY. The board's bar is 100 tall including its 54-point status bar, its content 16 from the bottom.
 * On a phone the status bar is the device's own, so the bar is the top inset plus the board's 46, and everything in it
 * sits exactly where the board puts it. A button's touch area grows downward and sideways to 44 — never up into the
 * status bar.
 *
 * BUT NEVER UNDER THE STATUS BAR (M57 §2). The board's 34-point buttons start 4 points inside its 54-point status bar,
 * which is taller than a phone's: on a phone they started 4 points under the clock and the camera. Live, the bar is the
 * top inset, one spacing step, then its content and the board's 16 below, and it grows with larger text rather than
 * reaching up. The fidelity build keeps the board's geometry.
 *
 * NO PANIC SHIELD IN THE HEADER (M42 §6.1): "not one design screen has it. Profile has a gear, Visitors a '+', Notices
 * a funnel, Notice detail a '…'. The rest have only a back chevron. The Panic control lives on Home, top right (E1)."
 * So the right of the bar is the board's own button (`right`) or the board's spacer, and nothing else.
 */

import { StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Gradient, Icon, Pressable, Text, isFidelity, type IconName } from '@gs/core'
import { fill, gradient, ink, radius, role, size, space } from '../tokens'

/** The board's bar below its status bar: 100 − 54. */
const BAR = size[46]
/** What a 34-point button's touch area grows by, to reach 44 — downward and to the sides only. */
const REACH = { top: 0, bottom: size[10], left: size[5], right: size[5] }

/**
 * `caption` (M50 §6.12): a word under the button for anybody who has never used an app like this — "Settings" under
 * Profile's gear. A deliberate departure from the boards, which draw the bare icon. It sits in the bar's own 16 below
 * the button, so the bar is no taller and nothing in it moves.
 */
type Side = { icon: IconName; label: string; onPress: () => void; caption?: string }

function SideButton({ side }: { side: Side }) {
  return (
    <View style={s.sideBox}>
      <Pressable onPress={side.onPress} role="button" aria-label={side.label} hitSlop={REACH} style={({ pressed }) => [s.side, pressed ? s.sidePressed : null]}>
        <Icon name={side.icon} size={size[34]} color={ink.onDark} />
      </Pressable>
      {side.caption ? (
        <View style={s.caption} aria-hidden>
          <Text {...role.tag} color={ink.onDark} align="center" numberOfLines={1}>{side.caption}</Text>
        </View>
      ) : null}
    </View>
  )
}

export function AppBar({ title, back = true, fallback = '/(tabs)', right }: { title: string; back?: boolean; fallback?: string; right?: Side }) {
  const insets = useSafeAreaInsets()
  const goBack = () => (router.canGoBack() ? router.back() : router.replace(fallback as never))

  return (
    <Gradient spec={gradient.header} style={[s.bar, isFidelity ? { height: insets.top + BAR } : { minHeight: insets.top + BAR, paddingTop: insets.top + space[8] }, { paddingLeft: space[18] + insets.left, paddingRight: space[18] + insets.right }]}>
      <StatusBar style="light" />
      {back ? <SideButton side={{ icon: 'chevron-left', label: 'Back', onPress: goBack }} /> : <View style={s.spacer} />}
      <View style={s.title}>
        <Text {...role.screenTitle} color={ink.onDark} align="center" role="heading" numberOfLines={1}>{title}</Text>
      </View>
      {right ? <SideButton side={right} /> : <View style={s.spacer} />}
    </Gradient>
  )
}

const s = StyleSheet.create({
  bar: { flexDirection: 'row', alignItems: 'flex-end', paddingBottom: space[16] },
  title: { flex: 1, minWidth: 0 },
  spacer: { width: size[34], flexShrink: 0 },
  side: { width: size[34], height: size[34], borderRadius: radius.avatar(size[34]), flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: fill.onDarkButton },
  sidePressed: { backgroundColor: fill.onDarkLoader },
  // The button and its word: the word hangs in the bar's 16 below the button, as wide as it needs, centred under it
  sideBox: { width: size[34], flexShrink: 0, alignItems: 'center' },
  caption: { position: 'absolute', top: size[34] + space[2], width: size[64], alignItems: 'center' },
})

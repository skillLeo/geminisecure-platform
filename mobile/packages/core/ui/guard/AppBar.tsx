/**
 * The Guard App's in-screen header (.appbar on every guard board): surface-800 on a border-700 hairline, the title
 * Poppins-SemiBold white and centred, side buttons as circles at white 8% with the touch area padded out for a gloved
 * thumb.
 *
 * THE STATUS BAR IS ALLOWED FOR ONCE, HERE (M18E). The bar used to be `insets.top + 46` tall with its content pinned 16
 * from the bottom — a 34-point button in 30 points of room, so it rose into the status bar on every phone. Now it is the
 * top inset PLUS a 56-point row with everything centred on its middle, so a button is always wholly below the status
 * bar; in landscape the side insets are added to the side padding; and the title is the one thing that gives way — it
 * truncates to one line, and the buttons keep their width.
 *
 * Under the fidelity harness the board's own geometry is drawn: 100 tall including its 54 status bar, content 16 from
 * the bottom, 34-point buttons.
 */

import { View } from 'react-native'
import { Pressable } from '../Pressable'
import { router } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { isFidelity } from '../../fidelity'
import { alpha, palette } from '../../theme/colors'
import { layout } from '../../theme/spacing'
import { Icon } from '../Icon'
import { InfoButton, type InfoTopic } from '../InfoButton'
import { Text } from '../Text'
import type { IconName } from '../icons/registry'

type Side = { icon: IconName; iconSize?: number; label: string; onPress: () => void } | 'back' | null

type Props = {
  title: string
  /** Board 01 draws 16; every other guard board draws 15.5. */
  titleSize?: number
  left?: Side
  right?: Side
  /** The screen's `i` (M32 F12), drawn beside the title in the live build only. */
  info?: InfoTopic
}

/** The row under the status bar, live: a 40-point button with 8 clear above and below. */
export const GUARD_BAR_ROW = 56

const sideOf = (): number => (isFidelity ? 34 : 40)

function SideButton({ side }: { side: Side }) {
  const size = sideOf()
  if (side === null) return <View style={{ width: size }} />
  const s = side === 'back' ? { icon: 'chevron-left' as IconName, iconSize: 18, label: 'Back', onPress: () => (router.canGoBack() ? router.back() : router.replace('/')) } : side
  return (
    <Pressable
      onPress={s.onPress}
      role="button"
      aria-label={s.label}
      hitSlop={isFidelity ? 7 : 4}
      style={({ pressed }) => ({ width: size, height: size, borderRadius: size / 2, flexShrink: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: alpha('white', pressed ? 0.16 : 0.08) })}
    >
      <Icon name={s.icon} size={s.iconSize ?? 18} color="white" />
    </Pressable>
  )
}

export function AppBar({ title, titleSize = 15.5, left = 'back', right = null, info }: Props) {
  const insets = useSafeAreaInsets()
  const heading = (
    <Text face="Poppins-SemiBold" size={titleSize} color="white" align="center" role="heading" numberOfLines={1} ellipsizeMode="tail" style={info && !isFidelity ? { flexShrink: 1 } : undefined}>
      {title}
    </Text>
  )
  const row = (
    <>
      <SideButton side={left} />
      <View style={{ flex: 1, minWidth: 0, paddingHorizontal: 6 }}>
        {/* The `i` on the title's own line, the title giving way first; the side buttons keep their place. */}
        {info && !isFidelity ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
            {heading}
            <InfoButton topic={info} tone="guard" />
          </View>
        ) : (
          heading
        )}
      </View>
      <SideButton side={right} />
    </>
  )

  if (isFidelity) {
    return (
      <View style={{ height: insets.top + layout.headerContent, flexDirection: 'row', alignItems: 'flex-end', paddingBottom: 16, paddingHorizontal: 18, borderBottomWidth: 1, borderBottomColor: palette['border-700'], backgroundColor: palette['surface-800'] }}>
        {row}
      </View>
    )
  }

  return (
    <View style={{ paddingTop: insets.top, paddingLeft: 14 + insets.left, paddingRight: 14 + insets.right, borderBottomWidth: 1, borderBottomColor: palette['border-700'], backgroundColor: palette['surface-800'] }}>
      <View style={{ minHeight: GUARD_BAR_ROW, flexDirection: 'row', alignItems: 'center', paddingVertical: 8 }}>{row}</View>
    </View>
  )
}

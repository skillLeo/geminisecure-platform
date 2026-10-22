/**
 * The bottom tab bar — five items, icon AND label always visible, never icon-only.
 *
 * Board geometry (guard board 01, resident board 02): 86px tall = 60 of content +
 * the home-indicator strip. Here that is 60 + the real bottom safe-area inset, so
 * it is never a hard-coded 86. padding-top 11, icon-to-label gap 4.
 *
 *              icon  label                       idle        active
 *   guard       21   10   / Inter-SemiBold       text-3      amber-500   on surface-800, hairline border-700
 *   resident    22   10.5 / Inter-SemiBold       slate-500   navy-600    on white,       hairline navy-100
 */

import { View } from 'react-native'
import { Pressable } from './Pressable'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { palette, type ColorToken } from '../theme/colors'
import { layout } from '../theme/spacing'
import { isFidelity } from '../fidelity'
import { Icon } from './Icon'
import { Text } from './Text'
import type { IconName } from './icons/registry'

export type TabSurface = 'guard' | 'resident'

const SKIN: Record<TabSurface, { bg: ColorToken; border: ColorToken; idle: ColorToken; active: ColorToken; icon: number; label: number }> = {
  guard: { bg: 'surface-800', border: 'border-700', idle: 'text-3', active: 'amber-500', icon: 21, label: 10 },
  resident: { bg: 'white', border: 'navy-100', idle: 'slate-500', active: 'navy-600', icon: 22, label: 10.5 },
}

export type TabItem = { key: string; label: string; icon: IconName }

type Props = {
  surface: TabSurface
  items: TabItem[]
  activeKey: string
  onPress: (key: string) => void
}

export function TabBar({ surface, items, activeKey, onPress }: Props) {
  const insets = useSafeAreaInsets()
  const skin = SKIN[surface]

  return (
    <View
      role="tablist"
      style={{
        // ABOVE THE GESTURE BAR ON EVERY PHONE (M57 §2): the board's 60 above the device's own bottom inset, and the labels
        // never closer to it than one spacing step; at a larger text size the bar grows upwards instead of the labels
        // sliding under the gesture bar. The fidelity build keeps the board's fixed 86.
        ...(isFidelity
          ? { height: layout.tabBarContent + insets.bottom }
          : { minHeight: layout.tabBarContent + insets.bottom, paddingBottom: insets.bottom + layout.safeGap }),
        backgroundColor: palette[skin.bg],
        borderTopWidth: 1,
        borderTopColor: palette[skin.border],
        flexDirection: 'row',
        alignItems: 'flex-start',
        paddingTop: 11,
      }}
    >
      {items.map((item) => {
        const active = item.key === activeKey
        const tint = active ? skin.active : skin.idle
        return (
          <Pressable
            key={item.key}
            onPress={() => onPress(item.key)}
            // `role` and `aria-*` rather than accessibilityRole/State: React Native 0.86
            // reads them natively, and react-native-web no longer maps accessibilityState.
            role="tab"
            aria-selected={active}
            aria-label={item.label}
            hitSlop={{ top: 11, bottom: 8 }}
            style={{ flex: 1, alignItems: 'center', gap: 4, minHeight: 44 }}
          >
            <Icon name={item.icon} size={skin.icon} color={tint} />
            <Text face="Inter-SemiBold" size={skin.label} color={tint}>
              {item.label}
            </Text>
          </Pressable>
        )
      })}
    </View>
  )
}

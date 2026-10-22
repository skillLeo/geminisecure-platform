/**
 * P1 navigation shell. Each tab's real screen replaces this in its phase; until
 * then the tab says plainly that it is not built, rather than looking broken.
 */

import { View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Text } from '@gs/core'

export function ShellPlaceholder({ title, phase }: { title: string; phase: string }) {
  const insets = useSafeAreaInsets()
  return (
    <View style={{ flex: 1, paddingTop: insets.top + 24, paddingHorizontal: 20 }}>
      <Text face="Poppins-SemiBold" size={20} color="navy-900" role="heading">
        {title}
      </Text>
      <Text face="Inter-Regular" size={13} lh={1.5} color="slate-600" style={{ marginTop: 8 }}>
        This screen is built in {phase}. The navigation shell, theme and fonts you are looking at are the real ones.
      </Text>
    </View>
  )
}

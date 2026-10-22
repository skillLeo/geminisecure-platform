/**
 * Any address the app has no screen for: a link that is simply wrong (no button in the app leads here). Expo's own
 * "Unmatched Route" page is a developer page; a guard gets a sentence and a way back, and never "not available yet"
 * (M54 sign-off: nothing says it).
 */

import { View } from 'react-native'
import { router, usePathname } from 'expo-router'
import { AppBar, EmptyState, c } from '@gs/core'

export default function NotBuiltYetScreen() {
  const path = usePathname()
  return (
    <View style={{ flex: 1, backgroundColor: c('surface-900') }}>
      <AppBar title="Not found" />
      <EmptyState
        tone="dark"
        icon="empty-icon"
        title="There is no screen here"
        body={`Nothing in the app is at this address${path && path !== '/' ? ` (${path})` : ''}. Everything you have already saved is safe.`}
        action={{ label: 'Back to Home', onPress: () => router.replace('/(tabs)') }}
        style={{ marginTop: 60 }}
      />
    </View>
  )
}

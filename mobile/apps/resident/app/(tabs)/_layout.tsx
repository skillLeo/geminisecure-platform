import { Tabs } from 'expo-router'
import { TabBar, palette, type TabItem } from '@gs/core'

/** Resident board 02 · .tabbar — Home, Visitors, Notices, Dues, Profile. Icon AND label, always. */
const ITEMS: TabItem[] = [
  { key: 'index', label: 'Home', icon: 'tab-home' },
  { key: 'visitors', label: 'Visitors', icon: 'tab-visitors' },
  { key: 'notices', label: 'Notices', icon: 'tab-notices' },
  { key: 'dues', label: 'Dues', icon: 'tab-card' },
  { key: 'profile', label: 'Profile', icon: 'tab-profile' },
]

export default function TabsLayout() {
  return (
    <Tabs
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: palette.white } }}
      tabBar={({ state, navigation }) => (
        <TabBar
          surface="resident"
          items={ITEMS}
          activeKey={state.routes[state.index]?.name ?? 'index'}
          onPress={(key) => navigation.navigate(key)}
        />
      )}
    >
      {ITEMS.map((item) => (
        <Tabs.Screen key={item.key} name={item.key} options={{ title: item.label }} />
      ))}
    </Tabs>
  )
}

import { Tabs } from 'expo-router'
import { TabBar, palette, type TabItem } from '@gs/core'

/** Guard board 01 · .tabbar — Home, Patrol, Messages, Payslips, More. */
const ITEMS: TabItem[] = [
  { key: 'index', label: 'Home', icon: 'tab-home' },
  { key: 'patrol', label: 'Patrol', icon: 'tab-patrol' },
  { key: 'messages', label: 'Messages', icon: 'tab-messages' },
  { key: 'payslips', label: 'Payslips', icon: 'tab-card' },
  { key: 'more', label: 'More', icon: 'tab-more' },
]

export default function TabsLayout() {
  return (
    <Tabs
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: palette['surface-900'] } }}
      tabBar={({ state, navigation }) => (
        <TabBar
          surface="guard"
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

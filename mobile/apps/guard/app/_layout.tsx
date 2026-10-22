import '../global.css'

import { useEffect } from 'react'
import { Stack } from 'expo-router'
import { useFonts } from 'expo-font'
import * as SplashScreen from 'expo-splash-screen'
import { StatusBar } from 'expo-status-bar'
import { GestureHandlerRootView } from 'react-native-gesture-handler'
import { KeyboardProvider } from 'react-native-keyboard-controller'
import { Provider } from 'react-redux'
import { AppRoot, fontAssets, palette } from '@gs/core'
import { LiveStatusBanner } from '@gs/core/live'
import { AlertnessWatcher } from '@/features/alertness/AlertnessWatcher'
import { SessionGate } from '@/features/boot/SessionGate'
import { DuressWatcher } from '@/features/duress/DuressWatcher'
import { GuardNotifier } from '@/features/notify/GuardNotifier'
import { LiveRuntime } from '@/features/live/LiveRuntime'
import { RingBridge } from '@/features/ring/RingBridge'
import { TrackingRuntime } from '@/features/tracking/TrackingRuntime'
import { store } from '@/store'

// Held until the fonts are in: a first frame in a fallback face is a layout shift
// on every screen, and the native splash is the same colour as the app's base.
void SplashScreen.preventAutoHideAsync()

export default function RootLayout() {
  const [loaded, error] = useFonts(fontAssets)

  useEffect(() => {
    if (loaded || error) void SplashScreen.hideAsync()
  }, [loaded, error])

  if (!loaded && !error) return null

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <Provider store={store}>
        <KeyboardProvider>
          <AppRoot background="surface-900">
            {/* Dark always. Light status-bar glyphs on every screen. */}
            <StatusBar style="light" />
            <SessionGate />
            <AlertnessWatcher />
            {/* A duress alert's fate, followed with no screen open: a silent alert's guard never opens one (B124). */}
            <DuressWatcher />
            <GuardNotifier />
            {/* The call-style ring's JS half: where the server is, quiet during the guard's own duress, tidying up (the ring itself is native). */}
            <RingBridge />
            {/* Live updates while signed in (M11 Part C): every step of an alert moves the open screen. */}
            <LiveRuntime />
            {/* On-duty location sharing kept in step with the shift (M11 §3): nothing drawn, nothing asked. */}
            <TrackingRuntime />
            {/* Says so, above everything, when live updates are paused or delayed; nothing while they work. */}
            <LiveStatusBanner tone="dark">
              <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: palette['surface-900'] }, animation: 'fade' }} />
            </LiveStatusBanner>
          </AppRoot>
        </KeyboardProvider>
      </Provider>
    </GestureHandlerRootView>
  )
}

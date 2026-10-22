import '../global.css'

import { useEffect } from 'react'
import { DefaultTheme, Stack, ThemeProvider } from 'expo-router'
import { useFonts } from 'expo-font'
import * as SplashScreen from 'expo-splash-screen'
import { StatusBar } from 'expo-status-bar'
import { GestureHandlerRootView } from 'react-native-gesture-handler'
import { KeyboardProvider } from 'react-native-keyboard-controller'
import { Provider } from 'react-redux'
import { AppRoot, fontAssets, isFidelity, palette, setHighContrast, setReduceMotion, setTextCap, setTextScale } from '@gs/core'
import { LiveStatusBanner } from '@gs/core/live'
import { DemoBanner } from '@/features/auth/components/DemoBanner'
import { SessionGate } from '@/features/boot/SessionGate'
import { LiveRuntime } from '@/features/live/LiveRuntime'
import { ResidentNotifier } from '@/features/notify/ResidentNotifier'
import { LiveAudioHost } from '@/features/panic/LiveAudioHost'
import { AppLock } from '@/features/settings/components/AppLock'
import { EpassKeeper } from '@/features/visitors/EpassKeeper'
import { bindHighContrast, bindTextScale, settingsPrefs, useReduceMotion } from '@/features/settings/prefs'
import { store } from '@/store'

void SplashScreen.preventAutoHideAsync()

/**
 * The navigator's own background is white, as every design screen is. Its default is a light grey (#F2F2F2) that
 * painted the container behind every screen — the stray colour the design checks found on every one (M42 Stage 0).
 */
const THEME = { ...DefaultTheme, colors: { ...DefaultTheme.colors, background: palette.white, card: palette.white } }

export default function RootLayout() {
  const [loaded, error] = useFonts(fontAssets)

  useEffect(() => {
    if (loaded || error) void SplashScreen.hideAsync()
  }, [loaded, error])

  // Settings → Language & accessibility, applied where it counts: the type layer multiplies by the chosen
  // text size, and with "reduce motion" on, screens change without a transition and the kit's pulses stand still.
  // The fidelity harness measures the boards at exactly 1×, moving as drawn.
  const still = useReduceMotion() && !isFidelity
  // No cap on text size to protect a layout (M50 §6.5): the chosen size and the phone's own setting, all of both — the
  // screens scroll above the default instead. The Guard App keeps its cap; this is the Resident App's choice.
  useEffect(() => (isFidelity ? undefined : setTextCap(null)), [])
  useEffect(() => (isFidelity ? undefined : bindTextScale(setTextScale)), [])
  useEffect(() => (isFidelity ? undefined : bindHighContrast(setHighContrast)), [])
  useEffect(() => setReduceMotion(still), [still])
  // The phone's storage is read behind the splash: once the session has booted, look at the saved choices again.
  useEffect(() => {
    if (isFidelity) return
    let booted = store.getState().session.status !== 'booting'
    if (booted) return
    const stop = store.subscribe(() => {
      if (booted || store.getState().session.status === 'booting') return
      booted = true
      settingsPrefs.reset()
      stop()
    })
    return stop
  }, [])

  if (!loaded && !error) return null

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <Provider store={store}>
        <KeyboardProvider>
          <AppRoot background="white">
            {/* Each screen sets its own bar style: light glyphs over the navy headers, dark over white. */}
            <StatusBar style="auto" />
            <SessionGate />
            <ResidentNotifier />
            {/* The resident's own e-Pass, collected afresh an hour before it runs out, with no screen open (B120). */}
            <EpassKeeper />
            {/* "Share live audio" (M50 §4.2 #1): one recorder for the app, so the audio goes on off the panic screen. */}
            <LiveAudioHost />
            {/* Live updates while signed in (M11 Part C): the panic timeline moves at every step. */}
            <LiveRuntime />
            {/* A read-only look round the demonstration community says so on EVERY screen.
                With no demonstration session it returns the navigator untouched. The live line
                says so, above everything, when live updates are paused; nothing while they work. */}
            <DemoBanner>
              <LiveStatusBanner tone="light">
                <ThemeProvider value={THEME}>
                  <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: palette.white }, animation: still ? 'none' : 'fade' }} />
                </ThemeProvider>
              </LiveStatusBanner>
            </DemoBanner>
            {/* AFTER the navigator, so it covers it. Off unless the resident turned it on; never covers /panic. */}
            <AppLock />
          </AppRoot>
        </KeyboardProvider>
      </Provider>
    </GestureHandlerRootView>
  )
}

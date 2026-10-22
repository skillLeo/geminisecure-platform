/**
 * guard-app-01 · Splash — board: Guard App Screens/01 Splash Clock-In and QR Scan
 *
 * "Auto-advances to clock-in once the guard's device session is verified."
 * The loader is a real progress bar in the app; the board draws it at 70%.
 * Positions from the bottom (loader 120, footer 78) are the board's, measured from
 * the physical screen edge — the splash is full-bleed under both OS bars.
 *
 * IT LEAVES THE MOMENT THE SESSION IS KNOWN (M11 A4). It used to wait 1.65 s whatever happened —
 * a timer the board never asked for, spent on every cold start before the guard saw a single real
 * screen. The board's rule is "once the device session is verified", and that is the rule now:
 * the boot reads the disk (a few milliseconds), and the splash hands over as soon as it has.
 */

import { useEffect, useRef } from 'react'
import { View } from 'react-native'
import { router } from 'expo-router'
import Animated, { Easing, useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated'
import { alpha, BrandMark, Gradient, isFidelity, palette, Text, useFidelityReady } from '@gs/core'
import { bootFixture } from '@/fidelity/fixtures'
import { nextRoute } from '../nextRoute'
import { useAppSelector } from '@/store'

const LOADER_WIDTH = 132
/** How long the loader takes to fill if the boot is still running — it is cut short the moment it is not. */
const FILL_MS = 1500

// Where it hands over, including a clock-in still waiting on this phone (B127): ../nextRoute.ts.

export default function SplashScreen() {
  const progress = useSharedValue(isFidelity ? bootFixture.loaderProgress : 0)
  const status = useAppSelector((s) => s.session.status)
  // The site this handset is enrolled to, known from the last session even with no signal.
  const enrolledSite = useAppSelector((s) => s.session.principal?.siteName ?? null)
  const siteName = isFidelity ? bootFixture.siteName : enrolledSite

  useFidelityReady(true)

  useEffect(() => {
    if (isFidelity) return
    progress.value = withTiming(1, { duration: FILL_MS, easing: Easing.out(Easing.cubic) })
  }, [progress])

  // The boot (SessionGate) is the only thing waited for: the screen leaves as soon as it has answered —
  // once. After that the SessionGate guards whatever route the guard is on.
  const left = useRef(false)
  useEffect(() => {
    if (isFidelity || left.current || status === 'booting') return
    left.current = true
    router.replace(nextRoute(status) as never)
  }, [status])

  const fill = useAnimatedStyle(() => ({ width: LOADER_WIDTH * progress.value }))

  return (
    <Gradient name="guardSplash" style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
      <BrandMark size={96} variant="splash" style={{ marginBottom: 20 }} />

      <Text face="Poppins-SemiBold" size={25} color="white" role="heading">
        GeminiSecure
      </Text>

      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: 6,
          marginTop: 10,
          paddingVertical: 5,
          paddingHorizontal: 12,
          borderRadius: 20,
          borderWidth: 1,
          borderColor: alpha('amber-500', 0.3),
          backgroundColor: alpha('amber-500', 0.14),
        }}
      >
        <Text face="Inter-Bold" size={10.5} ls={0.6} color="amber-500">
          GUARD
        </Text>
      </View>

      <Text face="Inter-Regular" size={13} lh={1.5} color="text-2" align="center" style={{ marginTop: 10, paddingHorizontal: 40 }}>
        {siteName ? `Signing in to protect ${siteName}` : 'Signing in…'}
      </Text>

      <View
        role="progressbar"
        style={{
          position: 'absolute',
          bottom: 120,
          alignSelf: 'center',
          width: LOADER_WIDTH,
          height: 4,
          borderRadius: 4,
          overflow: 'hidden',
          backgroundColor: alpha('white', 0.1),
        }}
      >
        <Animated.View style={[{ height: 4, borderRadius: 4, backgroundColor: palette['amber-500'] }, fill]} />
      </View>

      <Text
        face="Inter-Medium"
        size={11}
        color="text-3"
        align="center"
        style={{ position: 'absolute', bottom: 78, left: 0, right: 0 }}
      >
        Powered by Gemini Security Limited
      </Text>
    </Gradient>
  )
}

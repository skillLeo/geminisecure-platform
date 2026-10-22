/**
 * resident-app-01 · Splash — board: Resident App Screens/01 Onboarding and Auth, rebuilt from the tokens (M50 §7).
 *
 * "Auto-advances after ~1.5s, or straight to Log In if a session is already saved." The board's 155° navy-600 →
 * navy-800 in the design's exact blues (the shared palette's darker navy made the top read darker than the board), the
 * mark, "GeminiSecure", "Your community, secured.", the loader (a real progress bar; the board draws it at 62%) and
 * "Powered by Gemini Security Limited" in the board's white at .62 — as every rebuilt navy screen draws its quieter
 * words (inkAlpha). Bottom offsets (loader 120, footer 78) are the board's, from the physical screen edge — the splash
 * is full-bleed under both OS bars. What it does is useSplash()'s.
 */

import { StyleSheet, View } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import Animated from 'react-native-reanimated'
import { BrandMark, Gradient, Text, useFidelityReady } from '@gs/core'
import { corner, fill, font, gradient, ink, inkAlpha, size, space } from '@/design/tokens'
import { useSplash } from '../useSplash'

export { nextRoute, waitsForBrand } from '../useSplash'

export default function SplashScreen() {
  const m = useSplash()
  useFidelityReady(true)

  return (
    <Gradient spec={gradient.splash} style={s.splash}>
      <StatusBar style="light" />

      <BrandMark size={size[104]} variant="splash" style={s.mark} />

      <Text {...font.poppins600_27_ls0_2} color={ink.onDark} role="heading">{'GeminiSecure'}</Text>
      <View style={s.tagline}>
        <Text {...font.inter500_14_ls0_2} color={ink.accent}>{'Your community, secured.'}</Text>
      </View>

      <View role="progressbar" style={s.loader}>
        <Animated.View style={[s.loaderFill, m.fill]} />
      </View>

      <View style={s.footer}>
        <Text {...font.inter500_11_ls0_2} align="center" style={s.footerText}>{'Powered by Gemini Security Limited'}</Text>
      </View>
    </Gradient>
  )
}

const s = StyleSheet.create({
  // .splash
  splash: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  // .mark · .tagline
  mark: { marginBottom: space[22] },
  tagline: { marginTop: space[8] },
  // .loader · .loader i
  loader: { position: 'absolute', bottom: space[120], alignSelf: 'center', width: size[132], height: size[4], borderRadius: corner[4], overflow: 'hidden', backgroundColor: fill.onDarkLoader },
  loaderFill: { height: size[4], borderRadius: corner[4], backgroundColor: fill.gold },
  // .footer-text
  footer: { position: 'absolute', bottom: space[78], left: 0, right: 0 },
  footerText: { color: inkAlpha.onDarkFooter },
})

/**
 * Wraps each app once: safe areas (the board's insets in web fidelity mode, the
 * device's everywhere else, a gallery build's phone included) and, on web, a 397-wide stage so a desktop browser
 * shows the phone layout rather than a stretched one.
 */

import { Platform, View } from 'react-native'
import { SafeAreaFrameContext, SafeAreaInsetsContext, SafeAreaProvider, initialWindowMetrics } from 'react-native-safe-area-context'
import { fidelityMetrics, isFidelity } from '../fidelity'
import { palette, type ColorToken } from '../theme/colors'
import { layout } from '../theme/spacing'

type Props = { background: ColorToken; children: React.ReactNode }

export function AppRoot({ background, children }: Props) {
  const content = <View style={{ flex: 1, backgroundColor: palette[background] }}>{children}</View>

  // Web only: a gallery build on a phone (M45 §3) photographs each screen with the device's REAL insets — that is
  // what the emulator pass is for — and must never swap this tree (and remount the app) when it enters the mode.
  if (isFidelity && Platform.OS === 'web') {
    // The contexts are provided DIRECTLY. SafeAreaProvider's `initialMetrics` is only a first guess on
    // web — it re-measures the browser's real insets (zero) a frame later and the board's 54/26 are gone.
    return (
      <SafeAreaFrameContext.Provider value={fidelityMetrics.frame}>
        <SafeAreaInsetsContext.Provider value={fidelityMetrics.insets}>{content}</SafeAreaInsetsContext.Provider>
      </SafeAreaFrameContext.Provider>
    )
  }

  if (Platform.OS === 'web') {
    return (
      <SafeAreaProvider initialMetrics={initialWindowMetrics}>
        <View style={{ flex: 1, alignItems: 'center', backgroundColor: palette['page-bg'] }}>
          <View style={{ flex: 1, width: '100%', maxWidth: layout.canvasWidth }}>{content}</View>
        </View>
      </SafeAreaProvider>
    )
  }

  return <SafeAreaProvider initialMetrics={initialWindowMetrics}>{content}</SafeAreaProvider>
}

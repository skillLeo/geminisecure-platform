/**
 * The ten faces, keyed by the family name components use (Inter-Italic joined for M19A §4.3).
 *
 * Loaded two ways on purpose:
 *   - `useFonts(fontAssets)` at runtime — the only way in Expo Go and on web.
 *   - the expo-font config plugin (app.config.ts → FONT_FILES) — embeds them in a
 *     development or production build, so they are there before JS runs.
 * Both register the SAME name, because each file's PostScript name already equals
 * its file name (tools/fonts/metrics.mjs prints them).
 *
 * A RELEASE BUILD FOR A PHONE CARRIES THEM ONCE (M11 A4). There the plugin has already put all nine
 * in the app (Android `assets/fonts/`, iOS `UIAppFonts`), and `useFonts` finds them there on its first
 * render (expo-font's `getLoadedFonts()` lists `assets/fonts/`), so the runtime copies were never
 * loaded — but because they were `require`d, Metro packed a SECOND copy of every file into the APK
 * (res/raw, 2,107,452 bytes). So the `require`s are only made where they are used: in development
 * (Expo Go has no embedded fonts) and on web. `__DEV__` and `Platform.OS` are constants in a release
 * bundle, so the branch — and the second copies — are gone from it. `fonts.test.ts` holds FONT_FILES
 * in both app configs to exactly these nine names (typography.ts `fontFamily`), so no face can be left
 * without its embedded copy.
 */

import { Platform } from 'react-native'

/* eslint-disable @typescript-eslint/no-require-imports */
export const fontAssets: Readonly<Record<string, number>> =
  __DEV__ || Platform.OS === 'web'
    ? {
        'Poppins-Medium': require('../assets/fonts/Poppins-Medium.ttf'),
        'Poppins-SemiBold': require('../assets/fonts/Poppins-SemiBold.ttf'),
        'Poppins-Bold': require('../assets/fonts/Poppins-Bold.ttf'),
        'Inter-Regular': require('../assets/fonts/Inter-Regular.ttf'),
        'Inter-Medium': require('../assets/fonts/Inter-Medium.ttf'),
        'Inter-SemiBold': require('../assets/fonts/Inter-SemiBold.ttf'),
        'Inter-Bold': require('../assets/fonts/Inter-Bold.ttf'),
        'Inter-Italic': require('../assets/fonts/Inter-Italic.ttf'),
        'IBMPlexMono-Regular': require('../assets/fonts/IBMPlexMono-Regular.ttf'),
        'IBMPlexMono-Medium': require('../assets/fonts/IBMPlexMono-Medium.ttf'),
      }
    : {}

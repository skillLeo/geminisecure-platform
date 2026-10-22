/**
 * A PHONE'S RELEASE BUNDLE CARRIES NO MATERIAL SYMBOLS FONT (M11 A4).
 *
 * expo-router's layouts import its native tabs (layouts/withLayoutContext → native-tabs), whose Android
 * icon code imports expo-symbols, whose one default weight imports
 * `@expo-google-fonts/material-symbols/400Regular` — a 966,544-byte font file. Metro follows the import,
 * so every APK carried it (res/raw). It is drawn only by a `<SymbolView>` or by a NativeTabs trigger given
 * an `md` icon, and neither app has either: the tabs are expo-router's JavaScript `Tabs`, and the icons
 * are the kit's own SVGs. So on a phone the file was never loaded. `symbols.test.ts` fails the day either
 * app imports expo-symbols or the native tabs, which is the day this must go.
 *
 * For a release bundle for a phone the import resolves to an empty module; development (Expo Go) and web
 * resolve it exactly as before. Every other request goes to the resolver already in the config
 * (NativeWind's), or Metro's own.
 */

/** The font package expo-symbols imports, and any weight of it. */
const MATERIAL_SYMBOLS = /^@expo-google-fonts\/material-symbols(\/|$)/

/** @param {import('metro-config').MetroConfig} config */
function withoutMaterialSymbols(config) {
  const upstream = config.resolver.resolveRequest
  config.resolver.resolveRequest = (context, moduleName, platform) => {
    if (!context.dev && platform !== 'web' && MATERIAL_SYMBOLS.test(moduleName)) return { type: 'empty' }
    return (upstream ?? context.resolveRequest)(context, moduleName, platform)
  }
  return config
}

module.exports = { withoutMaterialSymbols, MATERIAL_SYMBOLS }

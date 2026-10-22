const { getDefaultConfig } = require('expo/metro-config')
const { withNativeWind } = require('nativewind/metro')
const { withoutMaterialSymbols } = require('../../tools/metro/withoutMaterialSymbols.cjs')

// Expo's default config already watches the workspace root, so @gs/core resolves
// from source with no build step.
const config = getDefaultConfig(__dirname)

// EXPO_PUBLIC_* values are inlined into the code at transform time — and Metro's transform cache does not key on them:
// measured, a gallery export (EXPO_PUBLIC_GALLERY=1) made after a release export came out byte for byte the release
// bundle. The reverse order would put the dev-only gallery (M45 §3) into a release, and the same goes for any other
// inlined value (the API address, the platform's zone). Every EXPO_PUBLIC_* value is named in the cache version, so
// each combination gets its own cache.
config.cacheVersion = Object.keys(process.env)
  .filter((key) => key.startsWith('EXPO_PUBLIC_'))
  .sort()
  .map((key) => `${key}=${process.env[key]}`)
  .join('|')

// A phone's release bundle leaves out the Material Symbols font expo-router brings and this app never draws.
module.exports = withoutMaterialSymbols(
  withNativeWind(config, {
    input: './global.css',
    // NativeWind's default rem is 14. The boards are drawn at 16, and leaving 14
    // makes every rem-based size wrong by 12.5%.
    inlineRem: 16,
  }),
)

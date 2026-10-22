const { getDefaultConfig } = require('expo/metro-config')
const { withNativeWind } = require('nativewind/metro')
const { withoutMaterialSymbols } = require('../../tools/metro/withoutMaterialSymbols.cjs')

// Expo's default config already watches the workspace root, so @gs/core resolves
// from source with no build step.
const config = getDefaultConfig(__dirname)

// A phone's release bundle leaves out the Material Symbols font expo-router brings and this app never draws.
module.exports = withoutMaterialSymbols(
  withNativeWind(config, {
    input: './global.css',
    // NativeWind's default rem is 14. The boards are drawn at 16, and leaving 14
    // makes every rem-based size wrong by 12.5%.
    inlineRem: 16,
  }),
)

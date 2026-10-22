const gs = require('@gs/core/theme/tailwind')

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: gs.content(__dirname),
  presets: [require('nativewind/preset'), gs.preset],
  // The Guard App has no light mode, ever. `dark:` variants are not used here.
  darkMode: 'class',
  plugins: [],
}

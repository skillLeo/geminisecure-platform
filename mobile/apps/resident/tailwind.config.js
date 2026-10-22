const gs = require('@gs/core/theme/tailwind')

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: gs.content(__dirname),
  presets: [require('nativewind/preset'), gs.preset],
  // Follows the system. The boards draw light; dark is derived from the guard surfaces.
  darkMode: 'class',
  plugins: [],
}

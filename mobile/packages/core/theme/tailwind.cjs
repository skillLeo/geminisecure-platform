/**
 * The Tailwind preset both apps extend. Built from the same two JSON files the
 * TypeScript theme reads, so a class name and a style object can never disagree.
 *
 *   const gs = require('@gs/core/theme/tailwind')
 *   module.exports = { presets: [require('nativewind/preset'), gs.preset], content: gs.content(__dirname) }
 *
 * Arbitrary px classes (`p-[13px]`, `h-[52px]`) are allowed ONLY for geometry
 * copied from a board — the boards draw 9, 11, 13, 14.5 — never for an invented
 * value. Colour is never arbitrary: `bg-[#123456]` fails gate:tokens.
 */

const path = require('node:path')
const tokens = require('./tokens.json')
const boardOnly = require('./board-only.json')

const colors = {
  transparent: 'transparent',
  current: 'currentColor',
  ...tokens.colors,
  ...tokens.semantic,
  ...Object.fromEntries(Object.entries(boardOnly.colors).map(([name, entry]) => [name, entry.hex])),
}

const preset = {
  theme: {
    // Replace, not extend: a Tailwind default colour (`bg-blue-500`) is not a token.
    colors,
    extend: {
      fontFamily: {
        'poppins-medium': ['Poppins-Medium'],
        'poppins-semibold': ['Poppins-SemiBold'],
        'poppins-bold': ['Poppins-Bold'],
        'inter-regular': ['Inter-Regular'],
        'inter-medium': ['Inter-Medium'],
        'inter-semibold': ['Inter-SemiBold'],
        'inter-bold': ['Inter-Bold'],
        'mono-regular': ['IBMPlexMono-Regular'],
        'mono-medium': ['IBMPlexMono-Medium'],
      },
      borderRadius: {
        control: '10px',
        'pill-btn': '11px',
        input: '12px',
        'm-input': '14px',
        'm-btn': '15px',
        card: '16px',
        modal: '20px',
        sheet: '22px',
        'auth-header': '34px',
        pill: '100px',
      },
    },
  },
}

/** Content globs for an app: its own routes and source, plus the shared package. */
function content(appDir) {
  const core = path.resolve(__dirname, '..')
  const rel = (p) => path.relative(appDir, p).replace(/\\/g, '/')
  return [
    './app/**/*.{ts,tsx}',
    './src/**/*.{ts,tsx}',
    `${rel(core)}/ui/**/*.{ts,tsx}`,
    `${rel(core)}/fidelity/**/*.{ts,tsx}`,
  ]
}

module.exports = { preset, content, colors }

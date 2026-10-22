// A design board as a builder needs it: its markup (every string exactly as the design writes it, icons collapsed to
// their names where they have one) and ONLY the CSS rules its own classes use, one per line.
//
//   node tools/design/board-brief.mjs resident-app-07
//   node tools/design/board-brief.mjs 07 --css       the rules only
//   node tools/design/board-brief.mjs 07 --strings   the visible strings only, in order
//
// The boards are _design/screens/resident-app-*.html (the client's design, rendered one screen per file).

import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const arg = process.argv[2] ?? ''
const id = /^\d+$/.test(arg) ? `resident-app-${arg.padStart(2, '0')}` : arg
const html = readFileSync(join(root, '_design', 'screens', `${id}.html`), 'utf8')

const css = (html.match(/<style>([\s\S]*?)<\/style>/)?.[1] ?? '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ')
const body = html.slice(html.indexOf('<div class="screen'))
const chrome = /\b(statusbar|island|home-ind)\b/

// The markup, with each inline SVG collapsed (they are the design's icons; the app draws them from icons.json).
const markup = body
  .replace(/<svg[\s\S]*?<\/svg>/g, '<svg/>')
  .replace(/<div class="statusbar[\s\S]*?<\/span>\s*<\/div>/, '<statusbar/>')
  .replace(/\n\s*\n/g, '\n')

const classes = new Set([...body.matchAll(/class="([^"]+)"/g)].flatMap((m) => m[1].split(/\s+/)))
const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
  .map((m) => ({ sel: m[1].trim(), decl: m[2].trim() }))
  .filter((r) => !r.sel.startsWith('@') && !chrome.test(r.sel))
  .filter((r) => [...classes].some((c) => new RegExp(`\\.${c.replace(/[-]/g, '\\-')}(?![\\w-])`).test(r.sel)) || /^(\*|html|body|\.screen)\b/.test(r.sel))

const strings = [...body.replace(/<svg[\s\S]*?<\/svg>/g, '').replace(/<style[\s\S]*?<\/style>/g, '').matchAll(/>([^<>]+)</g)]
  .map((m) => m[1].replace(/\s+/g, ' ').trim())
  .filter((s) => s && s !== '9:41')
  .map((s) => s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#39;/g, "'").replace(/&quot;/g, '"'))

const want = process.argv.slice(3)
if (want.includes('--strings') || process.argv.includes('--strings')) {
  console.log(strings.join('\n'))
} else if (want.includes('--css') || process.argv.includes('--css')) {
  console.log(rules.map((r) => `${r.sel} { ${r.decl} }`).join('\n'))
} else {
  console.log(`=== ${id} · MARKUP (icons collapsed)\n${markup.trim()}\n\n=== ${id} · CSS (only the rules this board's classes use)\n${rules.map((r) => `${r.sel} { ${r.decl} }`).join('\n')}`)
}

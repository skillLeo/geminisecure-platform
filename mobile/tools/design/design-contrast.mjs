// D1 (7 October 2026): the Resident App draws the design's exact colours. This lists every text colour the DESIGN
// itself draws that fails WCAG 2.1 AA on the background it is actually drawn on — measured, not changed.
//
// For every text run on the forty Resident screens (the boards, _design/screens/resident-app-*.html) it reads the
// rendered text colour, then photographs the screen with all text made transparent and samples the pixels inside
// that run's own box: whatever is really behind the words — a flat fill, a gradient, the Home hero's drawing, a
// translucent chip over a navy card. The worst pixel counts (the one giving the lowest ratio); translucent text is
// composited over it first. AA is 4.5 : 1, or 3 : 1 for large text (24px, or 18.66px at 700 and heavier).
//
//   node tools/design/design-contrast.mjs        writes _design/RESIDENT_CONTRAST.md
//
// Colours are named from Foundation.dc.html where they match one exactly.

import { chromium } from 'playwright'
import { PNG } from 'pngjs'
import { readFileSync, writeFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const regions = JSON.parse(readFileSync(join(root, '_design', 'SCREEN_REGIONS.json'), 'utf8')).screens.filter((s) => s.surface === 'resident-app')
const palette = JSON.parse(readFileSync(join(root, 'packages', 'core', 'theme', 'resident-design.json'), 'utf8')).colors
// One hex can carry two Foundation names (slate-300 and the Guard App's text-2 are both #A9C0D6): the Resident name wins.
const GUARD_ONLY = /^rd-(text-2|text-3|g-navy-|surface-|border-|red-500)/
const nameOf = new Map()
for (const [n, h] of Object.entries(palette)) if (!nameOf.has(h.toUpperCase()) || !GUARD_ONLY.test(n)) if (!GUARD_ONLY.test(n) || !nameOf.has(h.toUpperCase())) nameOf.set(h.toUpperCase(), n.replace(/^rd-/, ''))
// The client's exceptions that keep a screen out of the rebuild: reported, and marked, but their colours stay as built.
const NOT_REBUILT = { 'resident-app-05': 'Home — frozen (E4)', 'resident-app-23': 'live room — not built (E5)', 'resident-app-37': 'My bookings (E7)', 'resident-app-38': 'Language & accessibility (E8)' }

/** Runs in the page: every text run, its colour, size, weight, rule and box (relative to the screen). */
function readRuns() {
  const screen = document.querySelector('.screen')
  const box = screen.getBoundingClientRect()
  const rgba = (s) => { const m = /rgba?\(([^)]*)\)/.exec(s); const [r, g, b, a = 1] = m[1].split(/[\s,/]+/).filter(Boolean).map(Number); return [r, g, b, a] }
  const out = []
  const walker = document.createTreeWalker(screen, NodeFilter.SHOW_TEXT)
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const text = n.textContent.replace(/\s+/g, ' ').trim()
    const el = n.parentElement
    if (!text || el.closest('.statusbar, .island, .home-ind, style, script')) continue
    const cs = getComputedStyle(el)
    if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) continue
    const range = document.createRange()
    range.selectNodeContents(n)
    const rects = [...range.getClientRects()].filter((r) => r.width > 1 && r.height > 1)
    if (!rects.length) continue
    const rule = [...el.classList].join('.') || el.tagName.toLowerCase()
    const parent = el.parentElement ? [...el.parentElement.classList].join('.') : ''
    out.push({
      text: text.slice(0, 40),
      fg: rgba(cs.color),
      size: Number.parseFloat(cs.fontSize),
      weight: Number(cs.fontWeight),
      rule: `${parent ? `.${parent} ` : ''}.${rule}`,
      rects: rects.map((r) => ({ x: r.left - box.left, y: r.top - box.top, w: r.width, h: r.height })),
    })
  }
  return out
}

const lin = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4 }
const lum = ([r, g, b]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05) }
const over = (top, under) => { const a = top[3]; return [0, 1, 2].map((i) => Math.round(top[i] * a + under[i] * (1 - a))) }
const hex = ([r, g, b]) => '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('').toUpperCase()
const label = (c) => { const h = hex(c); const n = nameOf.get(h); return (n ? `${n} ${h}` : h) + (c[3] !== undefined && c[3] < 1 ? ` @ ${Math.round(c[3] * 100) / 100}` : '') }
// A sampled background is one pixel of a gradient, or a card's faint shadow edge: group it under the nearest named
// design colour (within 40 in RGB), so one problem is one row. The worst pixel itself is kept beside it.
// Only the fills the Resident screens actually draw behind text are candidates (not the consoles' or the Guard App's).
const SURFACES = ['white', 'navy-100', 'navy-200', 'amber-100', 'fdedd3', 'navy-600', 'navy-700', 'navy-800', 'navy-900']
const named = SURFACES.map((n) => [n, [1, 3, 5].map((i) => parseInt(palette[`rd-${n}`].slice(i, i + 2), 16))])
const family = (bg) => {
  let best = null
  let dist = Infinity
  for (const [n, c] of named) {
    const d = Math.hypot(c[0] - bg[0], c[1] - bg[1], c[2] - bg[2])
    if (d < dist) { dist = d; best = n }
  }
  if (dist === 0) return best
  if (dist <= 40) return `≈ ${best}`
  // Between two navies: a pixel of a navy card's gradient (or a translucent chip on one).
  return bg[2] > bg[0] && lum(bg) < 0.3 ? 'the navy gradient' : hex(bg)
}

const browser = await chromium.launch()
const groups = new Map()
let runsMeasured = 0
for (const s of regions) {
  const page = await browser.newPage({ viewport: { width: 1000, height: 1200 }, deviceScaleFactor: 1 })
  await page.goto(pathToFileURL(join(root, s.standalone)).href, { waitUntil: 'load' })
  await page.evaluate(async () => { await Promise.all(['400 12px Inter', '600 12px Inter', '700 12px Inter', '600 12px Poppins'].map((f) => document.fonts.load(f))) })
  const runs = await page.evaluate(readRuns)
  // The same screen with every word (and every currentColor icon) made invisible: what remains is what is behind them.
  await page.addStyleTag({ content: '.screen *{color:transparent !important;-webkit-text-fill-color:transparent !important;text-shadow:none !important}' })
  const shot = PNG.sync.read(await page.locator('.screen').first().screenshot())
  await page.close()

  for (const run of runs) {
    // Sample inside each line box, 1px in from its edges so a neighbour's border does not count.
    const samples = new Map()
    for (const r of run.rects) {
      for (let y = Math.ceil(r.y) + 1; y < Math.floor(r.y + r.h) - 1; y++) {
        for (let x = Math.ceil(r.x) + 1; x < Math.floor(r.x + r.w) - 1; x++) {
          if (x < 0 || y < 0 || x >= shot.width || y >= shot.height) continue
          const o = (y * shot.width + x) * 4
          const key = (shot.data[o] << 16) | (shot.data[o + 1] << 8) | shot.data[o + 2]
          samples.set(key, (samples.get(key) ?? 0) + 1)
        }
      }
    }
    if (!samples.size) continue
    runsMeasured++
    const large = run.size >= 24 || (run.size >= 18.66 && run.weight >= 700)
    const need = large ? 3 : 4.5
    let worst = Infinity
    let worstBg = null
    for (const key of samples.keys()) {
      const bg = [(key >> 16) & 255, (key >> 8) & 255, key & 255]
      const fg = run.fg[3] < 1 ? over(run.fg, bg) : run.fg
      const r = ratio(fg, bg)
      if (r < worst) { worst = r; worstBg = bg }
    }
    const fam = family(worstBg)
    const key = `${label(run.fg)} on ${fam}`
    if (!groups.has(key)) groups.set(key, { fg: label(run.fg), bg: fam, worstPixel: null, worst: Infinity, need, sizes: new Set(), rules: new Set(), screens: new Set(), samples: new Set(), notRebuilt: new Set(), rebuilt: new Set() })
    const g = groups.get(key)
    if (worst < g.worst) { g.worst = worst; g.need = need; g.worstPixel = hex(worstBg) }
    g.sizes.add(`${run.size}/${run.weight}`)
    g.rules.add(run.rule)
    g.screens.add(s.id.replace('resident-app-', ''))
    if (g.samples.size < 3) g.samples.add(run.text)
    if (NOT_REBUILT[s.id]) g.notRebuilt.add(NOT_REBUILT[s.id])
    else g.rebuilt.add(s.id)
  }
}
await browser.close()

const all = [...groups.values()].sort((a, b) => a.worst - b.worst)
const failing = all.filter((g) => g.worst < g.need)
const rebuiltFailing = failing.filter((g) => g.rebuilt.size)
const row = (g) => `| ${g.fg} | ${g.bg}${g.bg.startsWith('≈') ? ` (worst ${g.worstPixel})` : ''} | **${g.worst.toFixed(2)}** : 1 | ${g.need} | ${[...g.sizes].join(', ')} | ${[...g.rules].slice(0, 4).join(', ')}${g.rules.size > 4 ? ` … +${g.rules.size - 4}` : ''} | ${[...g.screens].join(', ')}${g.notRebuilt.size ? ` _(${[...g.notRebuilt].join('; ')})_` : ''} | ${[...g.samples].map((t) => `"${t}"`).join(' · ')} |`
const md = [
  '# The design\'s own text colours against WCAG 2.1 AA',
  '',
  'Generated by `node tools/design/design-contrast.mjs` from the forty Resident boards. D1 (7 October 2026): the app draws the design\'s exact colours; this lists where those colours fail AA on what is really behind them. **Nothing here is changed — it is reported.**',
  '',
  `${runsMeasured} text runs measured. Each row is one text colour on one background, at its worst: the background is sampled from the rendered screen with the words hidden, so gradients, the Home hero's drawing and translucent chips are all measured as drawn; translucent text is composited first. AA needs 4.5 : 1, or 3 : 1 for large text. Screens the rebuild does not touch (Home, the live room, My bookings, Language & accessibility) are marked.`,
  '',
  `## Failing — ${failing.length} pairs (${rebuiltFailing.length} on screens the rebuild draws)`,
  '',
  '| text | on (worst pixel) | worst | AA needs | size/weight | rules | screens | e.g. |',
  '|---|---|---|---|---|---|---|---|',
  ...failing.map(row),
  '',
  `## Passing — ${all.length - failing.length} pairs`,
  '',
  '| text | on (worst pixel) | worst | AA needs | size/weight | rules | screens | e.g. |',
  '|---|---|---|---|---|---|---|---|',
  ...all.filter((g) => g.worst >= g.need).map(row),
  '',
].join('\n')
writeFileSync(join(root, '_design', 'RESIDENT_CONTRAST.md'), md, 'utf8')
console.log(`${runsMeasured} runs · ${all.length} text/background pairs · ${failing.length} fail AA (${rebuiltFailing.length} on rebuilt screens)`)
for (const g of failing) console.log(`  ${g.worst.toFixed(2)} (needs ${g.need})  ${g.fg} on ${g.bg}  · ${[...g.screens].join(',')}${g.notRebuilt.size && !g.rebuilt.size ? ' [not rebuilt]' : ''}  · ${[...g.rules].slice(0, 3).join(', ')}`)

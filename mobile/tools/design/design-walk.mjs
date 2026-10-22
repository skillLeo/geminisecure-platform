// M42 Stage 0: what the DESIGN draws, measured in a browser rather than read by eye.
//
// For each of the forty Resident App screens (the boards cropped to one screen, _design/screens/<id>.html) this
// records, into _design/RESIDENT_DESIGN.json:
//
//   strings   every string the screen shows, in order, as seen (text-transform applied) — the string-match check
//             (tools/design/app-walk.mjs) compares the app against these
//   runs      every text run's face, size, weight, line height, letter spacing, case and colour, with the rule that
//             draws it — the type roles in apps/resident/src/design/tokens.json are named from these
//   fit       how far the screen's own content overflows its scroll area when the phone is 390 x 844 or 360 x 800
//             instead of the board's 397 x 856 (the no-scroll law, M42 §5). The board's 54px status bar and 86px tab
//             bar are kept: a real phone's insets are within a pixel or two of them at 390 x 844 (47 + 60 + 34).
//
//   node tools/design/design-walk.mjs            write the file
//   node tools/design/design-walk.mjs --check    fail if the file is out of date

import { chromium } from 'playwright'
import { readFileSync, writeFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const out = join(root, '_design', 'RESIDENT_DESIGN.json')
const regions = JSON.parse(readFileSync(join(root, '_design', 'SCREEN_REGIONS.json'), 'utf8')).screens.filter((s) => s.surface === 'resident-app')

export const SIZES = [
  { name: 'board', width: 397, height: 856 },
  { name: '390x844', width: 390, height: 844 },
  { name: '360x800', width: 360, height: 800 },
]

// Inside .screen but not the app: the drawn phone and the OS.
const CHROME = '.statusbar, .island, .home-ind'

/** Runs in the page: the screen's strings and text runs. */
function readScreen(chrome) {
  const screen = document.querySelector('.screen')
  const box = screen.getBoundingClientRect()
  const strings = []
  const runs = []
  const walker = document.createTreeWalker(screen, NodeFilter.SHOW_TEXT)
  const path = (el) => {
    const parts = []
    for (let e = el; e && e !== screen && parts.length < 3; e = e.parentElement) {
      const cls = [...e.classList].join('.')
      parts.unshift(cls ? `.${cls}` : e.tagName.toLowerCase())
    }
    return parts.join(' ')
  }
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const raw = node.textContent.replace(/\s+/g, ' ').trim()
    if (!raw) continue
    const el = node.parentElement
    if (el.closest(chrome) || el.closest('style, script')) continue
    const cs = getComputedStyle(el)
    if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) continue
    const range = document.createRange()
    range.selectNodeContents(node)
    const r = range.getBoundingClientRect()
    if (r.width === 0 || r.height === 0) continue
    const text = cs.textTransform === 'uppercase' ? raw.toUpperCase() : cs.textTransform === 'lowercase' ? raw.toLowerCase() : raw
    // Below the board's own fold (clipped by its scroll area) the string is in the design but not on the screen.
    let clipped = false
    for (let e = el.parentElement; e && e !== screen.parentElement; e = e.parentElement) {
      const ecs = getComputedStyle(e)
      if (ecs.overflowY !== 'visible') {
        const eb = e.getBoundingClientRect()
        if (r.top >= eb.bottom - 0.5 || r.bottom <= eb.top + 0.5) clipped = true
      }
    }
    strings.push({ text, top: Math.round(r.top - box.top), left: Math.round(r.left - box.left), clipped })
    runs.push({
      rule: path(el),
      text: text.slice(0, 40),
      family: cs.fontFamily.split(',')[0].replace(/["']/g, '').trim(),
      weight: Number(cs.fontWeight),
      size: Number.parseFloat(cs.fontSize),
      lineHeight: cs.lineHeight === 'normal' ? 'normal' : Number.parseFloat(cs.lineHeight),
      letterSpacing: cs.letterSpacing === 'normal' ? 0 : Number.parseFloat(cs.letterSpacing),
      transform: cs.textTransform === 'none' ? null : cs.textTransform,
      style: cs.fontStyle === 'normal' ? null : cs.fontStyle,
      colour: cs.color,
    })
  }
  return { strings, runs }
}

/** Runs in the page: every scroll area (or the screen itself) whose content does not fit. */
function readFit() {
  const screen = document.querySelector('.screen')
  const over = []
  for (const el of [screen, ...screen.querySelectorAll('*')]) {
    const cs = getComputedStyle(el)
    if (el !== screen && (cs.overflowY === 'visible' || el.clientHeight < 200)) continue
    const dy = el.scrollHeight - el.clientHeight
    const dx = el.scrollWidth - el.clientWidth
    if (dy <= 0.5 && dx <= 0.5) continue
    // What the fold cuts: the innermost elements that end below the area's bottom edge, with how much of each is lost.
    const bottom = el.getBoundingClientRect().bottom
    const cut = [...el.querySelectorAll('*')]
      .filter((c) => c.getBoundingClientRect().bottom > bottom + 0.5 && c.getBoundingClientRect().height > 0)
      .filter((c) => ![...c.children].some((k) => k.getBoundingClientRect().bottom > bottom + 0.5))
      .slice(0, 4)
      .map((c) => ({ rule: `.${[...c.classList].join('.') || c.tagName.toLowerCase()}`, text: c.textContent.replace(/\s+/g, ' ').trim().slice(0, 40), lost: Math.round(c.getBoundingClientRect().bottom - bottom) }))
    over.push({ area: el === screen ? '.screen' : `.${[...el.classList].join('.')}`, height: el.clientHeight, overflowY: dy, overflowX: dx, cut })
  }
  return over
}

const browser = await chromium.launch()
const screens = []
for (const s of regions) {
  const url = pathToFileURL(join(root, s.standalone)).href
  const entry = { id: s.id, title: s.title, source: s.source, fit: {} }
  for (const size of SIZES) {
    const page = await browser.newPage({ viewport: { width: 1000, height: 1200 }, deviceScaleFactor: 1 })
    await page.goto(url, { waitUntil: 'load' })
    if (size.name !== 'board') await page.addStyleTag({ content: `.screen{width:${size.width}px !important;height:${size.height}px !important}` })
    await page.evaluate(() => document.fonts.ready)
    // Measured in a fallback face, every wrap and every width would be wrong — and silently.
    const loaded = await page.evaluate(async () => {
      const faces = ['400 12px Inter', '600 12px Inter', '700 12px Inter', '600 12px Poppins']
      await Promise.all(faces.map((f) => document.fonts.load(f)))
      return faces.every((f) => document.fonts.check(f))
    })
    if (!loaded) throw new Error(`${s.id}: the board's fonts (Google Fonts Inter + Poppins) did not load — is the network up?`)
    if (size.name === 'board') Object.assign(entry, await page.evaluate(readScreen, CHROME))
    entry.fit[size.name] = await page.evaluate(readFit)
    await page.close()
  }
  screens.push(entry)
}
await browser.close()

const result = {
  note: 'Generated by tools/design/design-walk.mjs from _design/screens/resident-app-*.html (the boards, one screen each). Do not edit by hand.',
  sizes: SIZES,
  screens,
}
const text = JSON.stringify(result, null, 2) + '\n'

if (process.argv.includes('--check')) {
  if (readFileSync(out, 'utf8') !== text) {
    console.error('RESIDENT_DESIGN.json is out of date: run node tools/design/design-walk.mjs')
    process.exit(1)
  }
  console.log('RESIDENT_DESIGN.json is current')
} else {
  writeFileSync(out, text, 'utf8')
  console.log(`wrote ${out}: ${screens.length} screens · ${screens.reduce((n, s) => n + s.strings.length, 0)} strings`)
  for (const s of screens) {
    const worst = (name) => Math.max(0, ...s.fit[name].map((o) => o.overflowY))
    const wide = (name) => Math.max(0, ...s.fit[name].map((o) => o.overflowX))
    const line = SIZES.map((z) => `${z.name} ${worst(z.name).toFixed(0)}${wide(z.name) > 0.5 ? `/x${wide(z.name).toFixed(0)}` : ''}`).join(' · ')
    console.log(`${s.id}  ${s.title.padEnd(30).slice(0, 30)}  overflow px: ${line}`)
  }
}

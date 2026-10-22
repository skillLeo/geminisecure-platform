// M42 Stage 0: the three automated checks, run against the APP for every Resident screen — Expo web in fidelity mode,
// which renders the same components the phone renders, put in the design's own state with the design's own content.
//
//   1. no scroll       at 390 x 844 and 360 x 800 (and the board's 397 x 856) nothing scrolls and nothing is cut off.
//                      Ballot A (resident-app-15) is the one screen allowed to scroll (M42 §5). Default text size only
//                      (M43 §8) — the largest-text check is tools/design/text-scale.mjs, on the emulator.
//   2. no stray colour every colour the screen paints (text, background, border, shadow, gradient, SVG fill/stroke) is
//                      one the design draws. A colour the phone ships darker by an accessibility decision is reported
//                      as DRIFT, not stray; anything else is STRAY and fails.
//   3. strings         the screen's words, in order, against the design's (_design/RESIDENT_DESIGN.json, written by
//                      design-walk.mjs). The design's placeholder addresses (@email.com) read @example.com (M42 §6).
//
// And for the side-by-side review (M42 §9.2, M44 §2 gates 6 and 7): design | app at 390 x 844 — the design's own HTML
// rendered at that size, the app photographed at it, same content — and a 50% overlay, with the drawn phone (corners,
// island, status-bar clock and icons, home indicator) painted out of both the same way.
//
// Needs the Resident App on Expo web with EXPO_PUBLIC_FIDELITY=1 and the platform's zone, as every eas.json profile
// sets it — EXPO_PUBLIC_PLATFORM_TIMEZONE=America/Jamaica — or every time on the screens reads five hours out
// (measured: "11:40" for the design's "6:40"):
//   cd apps/resident && EXPO_PUBLIC_FIDELITY=1 EXPO_PUBLIC_PLATFORM_TIMEZONE=America/Jamaica npx expo start --web --port 8082
//
//   node tools/design/app-walk.mjs                       every screen
//   node tools/design/app-walk.mjs --only resident-app-07,resident-app-08
//
// Writes _design/RESIDENT_CHECKS.json and _design/screenshots/compare/<id>/{design,app,side-by-side,overlay}.png.
// Exit 1 if any screen fails a check it is held to (tools/design/check-exceptions.json lists the screens that are not
// rebuilt, and why, so their findings are reported but do not fail).

import { chromium } from 'playwright'
import { PNG } from 'pngjs'
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { W, H } from '../fidelity/lib.mjs'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (p) => JSON.parse(readFileSync(join(root, p), 'utf8'))
const regions = read('_design/SCREEN_REGIONS.json').screens.filter((s) => s.surface === 'resident-app')
const routes = read('_design/SCREEN_ROUTES.json')
const design = Object.fromEntries(read('_design/RESIDENT_DESIGN.json').screens.map((s) => [s.id, s]))
const values = read('_design/RESIDENT_VALUES.json')
const core = read('packages/core/theme/tokens.json')
const boardOnly = read('packages/core/theme/board-only.json')
const exceptions = read('tools/design/check-exceptions.json')

const args = process.argv.slice(2)
const flag = (n) => { const i = args.indexOf(n); return i === -1 ? null : args[i + 1] }
const only = flag('--only')?.split(',').map((s) => s.trim()) ?? null
const base = flag('--base') ?? routes.apps['resident-app']

export const SIZES = [
  { name: 'board', width: W, height: H },
  { name: '390x844', width: 390, height: 844 },
  { name: '360x800', width: 360, height: 800 },
]
const MAY_SCROLL = new Set(['resident-app-15'])

/* ------------------------------------------------------------------ the colours the design draws */

const rgbOf = (hex) => { const h = hex.replace('#', ''); return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) }
const designHex = Object.fromEntries(Object.entries(values.rootVariables).map(([k, v]) => [k.slice(2), v[0].toUpperCase()]))
const shippedHex = { ...core.colors, ...core.semantic, ...Object.fromEntries(Object.entries(boardOnly.colors).map(([k, v]) => [k, v.hex])) }
const colourKey = ([r, g, b], a) => `${r},${g},${b},${Math.round(a * 100) / 100}`
const allowed = new Map() // key -> 'design' | 'drift:<why>'
const allow = (rgb, opacity, why) => { const k = colourKey(rgb, opacity); if (!allowed.has(k) || why === 'design') allowed.set(k, why) }
{
  // Every colour token the design names, at every opacity the design uses anywhere — plus opaque.
  const text = JSON.stringify(values.colour) + readFileSync(join(root, 'tools', 'design', 'resident-jobs.json'), 'utf8')
  const opacities = new Set([1, ...[...text.matchAll(/(?:rgba\([^)]*,\s*|", )(0?\.\d+|1)\b/g)].map((m) => Number(m[1]))])
  const tokens = { ...designHex, black: '#000000', 'b-fdedd3': '#FDEDD3' }
  for (const [token, hex] of Object.entries(tokens)) {
    for (const o of opacities) {
      allow(rgbOf(hex), o, 'design')
      const ship = shippedHex[token]
      if (ship && ship.toUpperCase() !== hex) allow(rgbOf(ship), o, `drift: ${token} ships ${ship.toUpperCase()}, the design draws ${hex}`)
    }
  }
  // The kit's live text inks (M18B §3) — off in the fidelity build, listed so a leak is named rather than called stray.
  for (const t of ['text-primary', 'text-body', 'text-placeholder']) allow(rgbOf(shippedHex[t]), 1, `drift: the kit's ${t} ink (M18B §3)`)
}

/* ------------------------------------------------------------------ in-page readers */

/** Every word the page shows, in document order: text nodes, and inputs' values or placeholders. */
function readStrings() {
  const out = []
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT)
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    if (n.nodeType === 1) {
      if (n.matches('input, textarea')) {
        const cs = getComputedStyle(n)
        if (cs.display !== 'none' && cs.visibility !== 'hidden') out.push((n.value || n.placeholder || '').trim())
      }
      continue
    }
    const el = n.parentElement
    // A textarea's own text node is its value written out again by React DOM (defaultValue): read once, above.
    if (!el || el.closest('style, script, noscript, textarea, [aria-hidden="true"]')) continue
    const text = n.textContent.replace(/\s+/g, ' ').trim()
    if (!text) continue
    const cs = getComputedStyle(el)
    if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) continue
    const r = el.getBoundingClientRect()
    if (r.width === 0 || r.height === 0) continue
    out.push(cs.textTransform === 'uppercase' ? text.toUpperCase() : text)
  }
  return out.filter(Boolean)
}

/** Scroll areas with more content than room, and clipping boxes that cut content off. */
function readFit(maySkip) {
  const over = []
  const doc = document.scrollingElement
  if (doc && doc.scrollHeight - innerHeight > 1) over.push({ area: 'document', kind: 'scroll', by: doc.scrollHeight - innerHeight })
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el)
    if (cs.overflowY === 'visible' || el.clientHeight < 120) continue
    const by = el.scrollHeight - el.clientHeight
    if (by <= 1) continue
    const kind = cs.overflowY === 'auto' || cs.overflowY === 'scroll' ? 'scroll' : 'cut'
    over.push({ area: (el.getAttribute('data-testid') || el.className || el.tagName).toString().slice(0, 60), kind, by })
  }
  return over.filter((o) => !maySkip || o.kind !== 'scroll')
}

/** Every colour painted, by property, with a short description of where. */
function readColours() {
  const found = []
  const describe = (el) => `${el.tagName.toLowerCase()}${el.getAttribute('data-testid') ? `#${el.getAttribute('data-testid')}` : ''} "${(el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 30)}"`
  const add = (value, prop, el) => {
    for (const m of String(value).matchAll(/rgba?\(([^)]*)\)/g)) {
      const [r, g, b, a = '1'] = m[1].split(/[\s,/]+/).filter(Boolean).map(Number)
      if (a === 0) continue
      found.push({ rgb: [r, g, b], a, prop, where: describe(el) })
    }
  }
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el)
    if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) continue
    const r = el.getBoundingClientRect()
    if (r.width === 0 || r.height === 0) continue
    if ([...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()) || el.matches('input, textarea')) add(cs.color, 'color', el)
    add(cs.backgroundColor, 'background', el)
    if (cs.backgroundImage !== 'none') add(cs.backgroundImage, 'gradient', el)
    for (const side of ['Top', 'Right', 'Bottom', 'Left']) if (Number.parseFloat(cs[`border${side}Width`]) > 0 && cs[`border${side}Style`] !== 'none') add(cs[`border${side}Color`], 'border', el)
    if (cs.boxShadow !== 'none') add(cs.boxShadow, 'shadow', el)
    if (el instanceof SVGElement) {
      if (cs.fill && !/none|url/.test(cs.fill)) add(cs.fill, 'svg fill', el)
      if (cs.stroke && !/none|url/.test(cs.stroke)) add(cs.stroke, 'svg stroke', el)
      if (el.tagName.toLowerCase() === 'stop') add(cs.stopColor, 'svg stop', el)
    }
  }
  return found
}

/**
 * Text drawn in no face of ours: the phone would draw it in ITS font — the resident's own choice, a handwritten one
 * on some Samsung phones (M43 §10). On web the fallback shows as the browser's system stack.
 */
function readFaces() {
  const ours = /^["']?(Inter|Poppins|IBMPlexMono)-/
  const out = []
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const el = n.parentElement
    const text = n.textContent.replace(/\s+/g, ' ').trim()
    if (!el || !text || el.closest('style, script, noscript')) continue
    const cs = getComputedStyle(el)
    if (cs.display === 'none' || cs.visibility === 'hidden') continue
    if (!ours.test(cs.fontFamily.trim())) out.push(`${text.slice(0, 30)} — ${cs.fontFamily.split(',')[0]}`)
  }
  for (const el of document.querySelectorAll('input, textarea')) {
    const cs = getComputedStyle(el)
    if (!ours.test(cs.fontFamily.trim())) out.push(`[${el.tagName.toLowerCase()} ${el.placeholder || el.value || ''}] — ${cs.fontFamily.split(',')[0]}`)
  }
  return out
}

/* ------------------------------------------------------------------ strings */

const words = (list) => list.join(' ').replace(/ /g, ' ').split(/\s+/).filter(Boolean)

/*
 * LAW 3 (M53, M54 §2.5): the app writes ": " or ", " where the design writes " — ". The design's dash is read onto the
 * word before it (marked), and that word then matches the app's word with a colon or a comma after it; a colon or comma
 * the app drew as its own word is joined to the word before it first. Nothing else is loosened.
 */
const DASH_MARK = '\u0001'
const law3Design = (list) => list.reduce((out, w) => (w === '—' && out.length ? [...out.slice(0, -1), `${out[out.length - 1]}${DASH_MARK}`] : [...out, w]), [])
const law3App = (list) => list.reduce((out, w) => ((w === ':' || w === ',') && out.length ? [...out.slice(0, -1), `${out[out.length - 1]}${w}`] : [...out, w]), [])
const same = (want, got) => want === got || (want.endsWith(DASH_MARK) && (got === `${want.slice(0, -1)}:` || got === `${want.slice(0, -1)},`))

/** Word-level diff (LCS): the runs only the design has, and the runs only the app has, each with its neighbours. */
function diffWords(want, got) {
  const n = want.length
  const m = got.length
  const L = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1))
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) L[i][j] = same(want[i], got[j]) ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1])
  const ops = []
  let i = 0
  let j = 0
  const push = (kind, word, at) => {
    const last = ops[ops.length - 1]
    if (last && last.kind === kind) last.words.push(word)
    else ops.push({ kind, words: [word], at })
  }
  while (i < n && j < m) {
    if (same(want[i], got[j])) { i++; j++; ops.push(null) }
    else if (L[i + 1][j] >= L[i][j + 1]) push('design only', want[i], want.slice(Math.max(0, i - 3), i).join(' ')), i++
    else push('app only', got[j], want.slice(Math.max(0, i - 3), i).join(' ')), j++
  }
  while (i < n) push('design only', want[i], want.slice(Math.max(0, i - 3), i).join(' ')), i++
  while (j < m) push('app only', got[j], want.slice(Math.max(0, n - 3)).join(' ')), j++
  return { matched: L[0][0], runs: ops.filter(Boolean).map((o) => ({ kind: o.kind, text: o.words.join(' '), after: o.at })) }
}

/* ------------------------------------------------------------------ images (M42 §9.2: both at 390 x 844) */

// The review images are drawn at the phone size the client named, not the board's 397 x 856: the design's own HTML is
// rendered with its .screen at 390 x 844, the app is photographed at a 390 x 844 viewport, same content.
const RW = 390
const RH = 844
const GREY = [158, 158, 158]

/** Runs in the design page: the drawn phone's parts (status-bar clock and icons, island, home indicator), as boxes. */
function readChrome() {
  const screen = document.querySelector('.screen').getBoundingClientRect()
  const boxes = []
  for (const el of document.querySelectorAll('.statusbar > *, .island, .home-ind')) {
    const r = el.getBoundingClientRect()
    if (r.width && r.height) boxes.push({ x: Math.floor(r.left - screen.left) - 2, y: Math.floor(r.top - screen.top) - 2, w: Math.ceil(r.width) + 4, h: Math.ceil(r.height) + 4 })
  }
  return boxes
}

function maskOf(boxes) {
  const mask = new Uint8Array(RW * RH)
  const set = (x, y) => { if (x >= 0 && x < RW && y >= 0 && y < RH) mask[y * RW + x] = 1 }
  // The screen's 48px corners, as lib.mjs masks them at the board size.
  const R = 48
  for (let y = 0; y < R; y++) for (let x = 0; x < R; x++) {
    const dx = R - x - 0.5
    const dy = R - y - 0.5
    if (dx * dx + dy * dy > (R - 1.5) * (R - 1.5)) { set(x, y); set(RW - 1 - x, y); set(x, RH - 1 - y); set(RW - 1 - x, RH - 1 - y) }
  }
  for (const b of boxes) for (let y = b.y; y < b.y + b.h; y++) for (let x = b.x; x < b.x + b.w; x++) set(x, y)
  return mask
}

function masked(buf, mask) {
  const png = PNG.sync.read(buf)
  const out = new PNG({ width: RW, height: RH })
  PNG.bitblt(png, out, 0, 0, Math.min(RW, png.width), Math.min(RH, png.height), 0, 0)
  for (let p = 0; p < mask.length; p++) if (mask[p]) out.data.set([...GREY, 255], p * 4)
  return out
}
function sideBySide(a, b) {
  const gap = 16
  const out = new PNG({ width: RW * 2 + gap, height: RH })
  out.data.fill(255)
  PNG.bitblt(a, out, 0, 0, RW, RH, 0, 0)
  PNG.bitblt(b, out, 0, 0, RW, RH, RW + gap, 0)
  return out
}
function overlay(a, b) {
  const out = new PNG({ width: RW, height: RH })
  for (let p = 0; p < a.data.length; p++) out.data[p] = (a.data[p] + b.data[p]) >> 1
  return out
}

/** The design's own screen, rendered at 390 x 844 (its .screen resized), and where its drawn phone parts sit. */
async function designAt390(screen) {
  const page = await browser.newPage({ viewport: { width: 600, height: 1000 }, deviceScaleFactor: 1 })
  await page.goto(pathToFileURL(join(root, screen.standalone)).href, { waitUntil: 'load' })
  await page.addStyleTag({ content: `.screen{width:${RW}px !important;height:${RH}px !important}` })
  // Ask for the faces outright: a screen that draws no Inter 400 never makes the browser fetch it, and fonts.ready
  // resolves without it — measured on the splash.
  const loaded = await page.evaluate(async () => {
    const faces = ['400 12px Inter', '600 12px Inter', '700 12px Inter', '600 12px Poppins']
    await Promise.all(faces.map((f) => document.fonts.load(f)))
    return faces.every((f) => document.fonts.check(f))
  })
  if (!loaded) throw new Error('the design\'s fonts (Google Fonts) did not load')
  const buf = await page.locator('.screen').first().screenshot()
  const chrome = await page.evaluate(readChrome)
  await page.close()
  return { buf, chrome }
}

/* ------------------------------------------------------------------ run */

const FREEZE = '*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;caret-color:transparent!important}'
const browser = await chromium.launch()
const results = []
const held = (id, check) => !(exceptions.screens[id]?.notHeldTo ?? []).includes(check)

for (const s of regions.filter((r) => !only || only.includes(r.id))) {
  const entry = routes.routes[s.id]
  const result = { id: s.id, title: s.title, route: entry?.route ?? null, fit: {}, strings: null, colours: null, error: null }
  results.push(result)
  if (!entry?.route) { result.error = 'no route'; continue }
  const url = `${base}${entry.route}${entry.route.includes('?') ? '&' : '?'}fidelity=${s.id}`
  for (const size of SIZES) {
    // The design's moment is 9:41 AM in Kingston; a phone there reads its times in Jamaica's zone, not this computer's.
    const context = await browser.newContext({ viewport: { width: size.width, height: size.height }, deviceScaleFactor: 1, timezoneId: 'America/Jamaica', locale: 'en-JM' })
    const page = await context.newPage()
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 240000 })
      await page.waitForSelector('html[data-fidelity-ready]', { timeout: 45000 })
      await page.evaluate(() => document.fonts.ready)
      await page.addStyleTag({ content: FREEZE })
      await page.waitForTimeout(150)
      result.fit[size.name] = await page.evaluate(readFit, MAY_SCROLL.has(s.id))
      if (size.name === 'board') {
        const swaps = exceptions.screens[s.id]?.strings ?? []
        const want = law3Design(words(design[s.id].strings.map((x) => swaps.reduce((t, w) => (t === w.design ? w.app : t), x.text.replace(/@email\.com\b/g, '@example.com')))))
        const got = law3App(words(await page.evaluate(readStrings)))
        const d = diffWords(want, got)
        result.strings = { designWords: want.length, appWords: got.length, matched: d.matched, runs: d.runs }

        const stray = new Map()
        const drift = new Map()
        for (const c of await page.evaluate(readColours)) {
          const k = colourKey(c.rgb, c.a)
          const why = allowed.get(k)
          if (why === 'design') continue
          const bucket = why ? drift : stray
          const label = why ? `${k} — ${why}` : `rgba(${k})`
          if (!bucket.has(label)) bucket.set(label, new Set())
          bucket.get(label).add(`${c.prop}: ${c.where}`)
        }
        const shape = (m) => [...m.entries()].map(([colour, where]) => ({ colour, where: [...where].slice(0, 4), count: where.size }))
        result.colours = { stray: shape(stray), drift: shape(drift) }
        result.systemFont = await page.evaluate(readFaces)
      }
      if (size.name === '390x844') {
        const appBuf = await page.screenshot({ clip: { x: 0, y: 0, width: RW, height: RH } })
        const { buf: designBuf, chrome } = await designAt390(s)
        const mask = maskOf(chrome)
        const dir = join(root, '_design', 'screenshots', 'compare', s.id)
        mkdirSync(dir, { recursive: true })
        const d1 = masked(designBuf, mask)
        const a1 = masked(appBuf, mask)
        writeFileSync(join(dir, 'design.png'), PNG.sync.write(d1))
        writeFileSync(join(dir, 'app.png'), PNG.sync.write(a1))
        writeFileSync(join(dir, 'side-by-side.png'), PNG.sync.write(sideBySide(d1, a1)))
        writeFileSync(join(dir, 'overlay.png'), PNG.sync.write(overlay(d1, a1)))
      }
    } catch (e) {
      result.error = `${size.name}: ${e.message.split('\n')[0]}`
    }
    await context.close()
  }
}
await browser.close()

/* ------------------------------------------------------------------ verdicts */

for (const r of results) {
  const fails = []
  if (r.error) fails.push(r.error)
  // D3 (answered 7 October): a screen whose DESIGN overflows a small phone may scroll there, and only there.
  const mayScrollAt = new Set(exceptions.screens[r.id]?.mayScrollAt ?? [])
  for (const [name, list] of Object.entries(r.fit)) if (list.length && held(r.id, 'no-scroll') && !mayScrollAt.has(name)) fails.push(`${name} ${list.map((o) => `${o.kind} ${Math.round(o.by)}px`).join(', ')}`)
  // A colour the client's own instruction puts on a screen (`allowColours`, e.g. the panic circle's red, M42 §5 #5).
  const allowed = new Set(exceptions.screens[r.id]?.allowColours ?? [])
  const stray = (r.colours?.stray ?? []).filter((c) => !allowed.has(c.colour))
  if (stray.length && held(r.id, 'colours')) fails.push(`${stray.length} stray colour(s)`)
  if (r.strings?.runs.length && held(r.id, 'strings')) fails.push(`${r.strings.runs.length} string difference(s)`)
  if (r.systemFont?.length && held(r.id, 'faces')) fails.push(`${r.systemFont.length} text run(s) in the phone's own font`)
  r.verdict = fails.length ? 'FAIL' : 'PASS'
  r.fails = fails
}

const outFile = join(root, '_design', 'RESIDENT_CHECKS.json')
const previous = existsSync(outFile) ? JSON.parse(readFileSync(outFile, 'utf8')).screens : []
const merged = [...previous.filter((p) => !results.some((r) => r.id === p.id)), ...results].sort((a, b) => a.id.localeCompare(b.id))
writeFileSync(outFile, JSON.stringify({ note: 'Written by tools/design/app-walk.mjs. Fidelity mode on Expo web; see the file header for what each check means.', base, sizes: SIZES, screens: merged }, null, 2) + '\n', 'utf8')

const pad = (t, n) => String(t).padEnd(n).slice(0, n)
console.log(`${pad('screen', 16)} ${pad('title', 26)} ${pad('scroll 397/390/360', 20)} ${pad('stray', 6)} ${pad('drift', 6)} ${pad('sysfont', 8)} ${pad('words', 12)} verdict`)
for (const r of results) {
  const fit = SIZES.map((z) => (r.fit[z.name] ? (r.fit[z.name].length ? Math.max(...r.fit[z.name].map((o) => Math.round(o.by))) : 0) : '?')).join('/')
  const words = r.strings ? `${r.strings.matched}/${r.strings.designWords}` : '?'
  const exempt = exceptions.screens[r.id] ? ` (${exceptions.screens[r.id].why.slice(0, 40)})` : ''
  console.log(`${pad(r.id, 16)} ${pad(r.title, 26)} ${pad(fit, 20)} ${pad(r.colours?.stray.length ?? '?', 6)} ${pad(r.colours?.drift.length ?? '?', 6)} ${pad(r.systemFont?.length ?? '?', 8)} ${pad(words, 12)} ${r.verdict}${exempt}${r.error ? ` — ${r.error}` : ''}`)
}
const failed = results.filter((r) => r.verdict === 'FAIL')
console.log(`\n${results.length - failed.length}/${results.length} pass · images in _design/screenshots/compare/<id>/ · details in _design/RESIDENT_CHECKS.json`)
process.exit(failed.length ? 1 : 0)

/**
 * The store's BRAND assets — app icons at every size both stores ask for, the Android
 * adaptive icon layers, the web favicon and Google Play's 1024x500 feature graphic.
 *
 *   node tools/store/brand-assets.mjs            write store/icons/** and store/feature-graphic/** (the store's app only)
 *   node tools/store/brand-assets.mjs --apply    …and replace the icon files the two apps ship with (both apps)
 *   node tools/store/brand-assets.mjs --check    fail if anything is missing or out of date
 *
 * NOTHING HERE IS DRAWN BY HAND. Every asset is the design system's own splash lockup:
 * the brand mark (packages/core/ui/BrandMark.tsx — two circles, r 12.5, centres at cx 15 and
 * cx 25, the right one mix-blend-mode:multiply, Foundation "Never redraw the mark") over the
 * app's own splash gradient (packages/core/theme/gradients.ts). Colours come from
 * packages/core/theme/tokens.json at run time; there is no colour literal in this file.
 *
 * It is rendered by Playwright — the same browser the fidelity harness already drives — because
 * `mix-blend-mode: multiply` is the definition of the mark, and a browser is the one renderer in
 * this repo that applies it exactly as the phone's New-Architecture renderer does. (sharp is not
 * a dependency of this repo; adding an image library to redraw a blend by hand would be a second,
 * drifting definition of the logo.)
 *
 * Apple's marketing icon must be opaque, square and un-rounded — the stores round it themselves —
 * so the icon canvases are painted, never transparent. Only the Android adaptive foreground and
 * the monochrome layer are transparent, which is what those two layers are for.
 */

import { chromium } from 'playwright'
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import path from 'node:path'
import { STORE_APPS } from './snapshot-path.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const STORE = path.join(ROOT, 'store')
const FONTS = path.join(ROOT, 'packages', 'core', 'assets', 'fonts')

const APPLY = process.argv.includes('--apply')
const CHECK = process.argv.includes('--check')

const tokens = JSON.parse(readFileSync(path.join(ROOT, 'packages', 'core', 'theme', 'tokens.json'), 'utf8'))
const c = (name) => {
  const hex = tokens.colors[name] ?? tokens.semantic[name]
  if (!hex) throw new Error(`theme token "${name}" does not exist`)
  return hex
}

/* ── the two apps, each with its own splash gradient (gradients.ts) and wordmark line ─────────── */

const APPS = {
  guard: {
    // gradients.guardSplash — linear-gradient(160deg, navy-800 0%, surface-900 78%)
    gradient: () => `linear-gradient(160deg, ${c('navy-800')} 0%, ${c('surface-900')} 78%)`,
    flat: () => c('surface-900'), // app.config.ts backgroundColor / adaptiveIcon.backgroundColor
    name: 'GeminiSecure',
    line: 'Guard App',
    strap: 'Gate, patrol, shift and pay — for the officers on duty.',
  },
  resident: {
    // gradients.residentSplash — linear-gradient(155deg, navy-600 0%, navy-800 78%)
    gradient: () => `linear-gradient(155deg, ${c('navy-600')} 0%, ${c('navy-800')} 78%)`,
    flat: () => c('navy-600'),
    name: 'GeminiSecure',
    line: 'Resident App',
    strap: 'Your gate, your visitors, your community — in one place.',
  },
}

/** The mark, exactly as Foundation defines it and exactly as BrandMark.tsx builds it. */
const markSvg = (size, { left, right, opacity, blend = true }) =>
  `<svg width="${size}" height="${size}" viewBox="0 0 40 40" style="display:block">` +
  `<circle cx="15" cy="20" r="12.5" fill="${left}" opacity="${opacity}"></circle>` +
  `<circle cx="25" cy="20" r="12.5" fill="${right}" opacity="${opacity}"${blend ? ' style="mix-blend-mode:multiply"' : ''}></circle>` +
  `</svg>`

/** splash variant — #FFFFFF + #FFB627 at .92 (BrandMark VARIANTS.splash). */
const splashMark = (size) => markSvg(size, { left: c('white'), right: c('amber-500'), opacity: 0.92 })

const page = (width, height, body, extraCss = '') => `<!doctype html><html><head><meta charset="utf-8"><style>
  @font-face { font-family: 'Poppins'; font-weight: 600; src: url('${pathToFileURL(path.join(FONTS, 'Poppins-SemiBold.ttf')).href}') format('truetype'); }
  @font-face { font-family: 'Poppins'; font-weight: 700; src: url('${pathToFileURL(path.join(FONTS, 'Poppins-Bold.ttf')).href}') format('truetype'); }
  html, body { margin: 0; padding: 0; }
  body { width: ${width}px; height: ${height}px; overflow: hidden; }
  .canvas { width: ${width}px; height: ${height}px; display: flex; align-items: center; justify-content: center; }
  ${extraCss}
</style></head><body>${body}</body></html>`

/* ── what each store asks for ─────────────────────────────────────────────────────────────────── */

/** Opaque, square, painted with the app's splash gradient. The mark is 60% of the canvas. */
const ICONS = [
  // Apple — App Store marketing icon plus the whole iPhone/iPad asset catalogue, so the set is
  // complete whoever builds the binary (Expo generates these from icon.png; these are the audit copy).
  ['ios/icon-1024.png', 1024, 'App Store marketing icon'],
  ['ios/icon-180.png', 180, 'iPhone app 60pt @3x'],
  ['ios/icon-120.png', 120, 'iPhone app 60pt @2x · spotlight 40pt @3x'],
  ['ios/icon-87.png', 87, 'iPhone settings 29pt @3x'],
  ['ios/icon-80.png', 80, 'iPhone spotlight 40pt @2x'],
  ['ios/icon-60.png', 60, 'iPhone notification 20pt @3x'],
  ['ios/icon-58.png', 58, 'iPhone settings 29pt @2x'],
  ['ios/icon-40.png', 40, 'iPhone notification 20pt @2x'],
  ['ios/icon-167.png', 167, 'iPad Pro 83.5pt @2x'],
  ['ios/icon-152.png', 152, 'iPad 76pt @2x'],
  ['ios/icon-76.png', 76, 'iPad 76pt @1x'],
  ['ios/icon-29.png', 29, 'iPad settings 29pt @1x'],
  ['ios/icon-20.png', 20, 'iPad notification 20pt @1x'],
  // Google Play
  ['android/play-icon-512.png', 512, 'Play Console app icon (32-bit PNG, no transparency)'],
  ['android/launcher-192.png', 192, 'launcher xxxhdpi'],
  ['android/launcher-144.png', 144, 'launcher xxhdpi'],
  ['android/launcher-96.png', 96, 'launcher xhdpi'],
  ['android/launcher-72.png', 72, 'launcher hdpi'],
  ['android/launcher-48.png', 48, 'launcher mdpi'],
  // The web build's tab icon
  ['web/favicon-48.png', 48, 'web favicon'],
  ['web/favicon-196.png', 196, 'web favicon @4x'],
]

const iconHtml = (app, size) =>
  page(size, size, `<div class="canvas" style="background:${APPS[app].gradient()}">${splashMark(Math.round(size * 0.6))}</div>`)

/**
 * The adaptive foreground fills 108dp but only the middle 66dp is guaranteed to be visible on
 * every launcher mask, so the mark is 40% of the canvas and centred — well inside the safe zone.
 */
const adaptiveForegroundHtml = (size) =>
  page(size, size, `<div class="canvas">${splashMark(Math.round(size * 0.4))}</div>`)

/** The background layer the launcher pans behind the foreground: the app's flat brand colour. */
const adaptiveBackgroundHtml = (app, size) =>
  page(size, size, `<div class="canvas" style="background:${APPS[app].flat()}"></div>`)

/**
 * Themed icons: Android paints the monochrome layer itself, so it must be ONE opaque silhouette
 * on transparency — no blend, no second colour, no opacity.
 */
const monochromeHtml = (size) =>
  page(size, size, `<div class="canvas">${markSvg(Math.round(size * 0.4), { left: c('white'), right: c('white'), opacity: 1, blend: false })}</div>`)

/** The splash image expo-splash-screen scales: the mark alone, on transparency. */
const splashIconHtml = (size) => page(size, size, `<div class="canvas">${splashMark(Math.round(size * 0.82))}</div>`)

/**
 * Google Play's feature graphic — 1024x500, no transparency, and it is cropped on some surfaces,
 * so everything that matters sits in the middle. Built from the theme's own gradient and the
 * wordmark lockup Foundation draws in the console sidebar (mark + Poppins 600 name + amber line).
 */
const featureGraphicHtml = (app) => {
  const a = APPS[app]
  return page(
    1024,
    500,
    `<div class="canvas" style="background:${a.gradient()}">
       <div class="lockup">
         ${splashMark(132)}
         <div class="words">
           <div class="name">${a.name}</div>
           <div class="line">${a.line.toUpperCase()}</div>
           <div class="strap">${a.strap}</div>
         </div>
       </div>
     </div>`,
    `.lockup { display: flex; align-items: center; gap: 38px; }
     .words { font-family: 'Poppins', sans-serif; }
     .name { font-weight: 600; font-size: 60px; line-height: 1.05; color: ${c('white')}; }
     .line { font-weight: 700; font-size: 20px; letter-spacing: 3.6px; color: ${c('amber-500')}; margin-top: 8px; }
     .strap { font-weight: 600; font-size: 19px; line-height: 1.45; color: ${c('navy-200')}; margin-top: 18px; white-space: nowrap; }`,
  )
}

/* ── render ───────────────────────────────────────────────────────────────────────────────────── */

const written = []
const stale = []

async function shot(browser, { html, width, height, out, transparent = false }) {
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1 })
  const p = await context.newPage()
  await p.setContent(html, { waitUntil: 'load' })
  await p.evaluate(() => document.fonts.ready)
  const buf = await p.screenshot({ clip: { x: 0, y: 0, width, height }, omitBackground: transparent })
  await context.close()

  if (CHECK) {
    if (!existsSync(out) || !readFileSync(out).equals(buf)) stale.push(path.relative(ROOT, out).replace(/\\/g, '/'))
    return
  }
  mkdirSync(path.dirname(out), { recursive: true })
  writeFileSync(out, buf)
  written.push({ file: path.relative(ROOT, out).replace(/\\/g, '/'), px: `${width}x${height}` })
}

const browser = await chromium.launch()

for (const app of Object.keys(APPS)) {
  // The store's sizes for the store's app only (M25): the Guard App goes to no store, so none of it is in store/.
  if (STORE_APPS.includes(app)) {
    for (const [rel, size] of ICONS) {
      await shot(browser, { html: iconHtml(app, size), width: size, height: size, out: path.join(STORE, 'icons', app, rel) })
    }
    await shot(browser, { html: adaptiveForegroundHtml(432), width: 432, height: 432, out: path.join(STORE, 'icons', app, 'android/adaptive-foreground-432.png'), transparent: true })
    await shot(browser, { html: adaptiveBackgroundHtml(app, 432), width: 432, height: 432, out: path.join(STORE, 'icons', app, 'android/adaptive-background-432.png') })
    await shot(browser, { html: monochromeHtml(432), width: 432, height: 432, out: path.join(STORE, 'icons', app, 'android/adaptive-monochrome-432.png'), transparent: true })
    await shot(browser, { html: featureGraphicHtml(app), width: 1024, height: 500, out: path.join(STORE, 'feature-graphic', `${app}-1024x500.png`) })
  }

  /* --apply: the files the two apps actually ship. Left alone without the flag, so a plain run
     only ever writes under store/. */
  if (APPLY) {
    const images = path.join(ROOT, 'apps', app, 'assets', 'images')
    await shot(browser, { html: iconHtml(app, 1024), width: 1024, height: 1024, out: path.join(images, 'icon.png') })
    await shot(browser, { html: adaptiveForegroundHtml(512), width: 512, height: 512, out: path.join(images, 'android-icon-foreground.png'), transparent: true })
    await shot(browser, { html: adaptiveBackgroundHtml(app, 512), width: 512, height: 512, out: path.join(images, 'android-icon-background.png') })
    await shot(browser, { html: monochromeHtml(432), width: 432, height: 432, out: path.join(images, 'android-icon-monochrome.png'), transparent: true })
    await shot(browser, { html: splashIconHtml(512), width: 512, height: 512, out: path.join(images, 'splash-icon.png'), transparent: true })
    await shot(browser, { html: iconHtml(app, 48), width: 48, height: 48, out: path.join(images, 'favicon.png') })
  }
}

await browser.close()

if (CHECK) {
  if (stale.length > 0) {
    console.log(`brand-assets · FAIL — ${stale.length} asset(s) missing or out of date (run: node tools/store/brand-assets.mjs):`)
    for (const s of stale) console.log(`  ${s}`)
    process.exit(1)
  }
  console.log('brand-assets · every store icon and feature graphic matches the design system')
} else {
  console.log(`brand-assets · ${written.length} file(s) written from the theme and the brand mark:`)
  const iosCount = written.filter((w) => w.file.includes('/ios/')).length
  const androidCount = written.filter((w) => w.file.includes('/android/')).length
  console.log(`  ${iosCount} Apple · ${androidCount} Android · ${written.filter((w) => w.file.includes('feature-graphic')).length} feature graphic(s)`)
  if (APPLY) console.log('  --apply: the icon files inside apps/guard and apps/resident were rewritten too')
}

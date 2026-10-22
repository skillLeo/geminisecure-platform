/**
 * The fidelity harness — `npm run fidelity`.
 *
 * For every screen that has a route in _design/SCREEN_ROUTES.json it opens the app
 * (Expo web, react-native-web rendering the SAME components the phone renders) at
 * 397 x 856, deviceScaleFactor 1, and compares it to the board cropped to that one
 * screen. It prints a table — screen, % differing, pass/fail — and on failure
 * writes original.png, actual.png and diff.png so the difference is looked at, not
 * guessed at.
 *
 * THE VIEWPORT IS THE REGION, 397 x 856 — not the 433 x 892 bezel. The bezel is a
 * drawing of a phone; diffing against it would bake a mock into the acceptance
 * criteria.
 *
 * WHAT IS MASKED, AND WHY. The boards draw five things INSIDE .screen that are the
 * physical device or the OS, and that the build brief forbids the app to render:
 * the 48px rounded screen corners, the Dynamic Island, the home indicator, the
 * status-bar clock and the status-bar icons. Together they are ~2.8% of the
 * pixels — more than the entire 2% budget — so leaving them in would fail every
 * screen for something the app must not draw. They are painted the same flat
 * colour on BOTH images before the diff, the percentage is taken over the
 * unmasked pixels only, and the masked share is printed so nobody mistakes it for
 * coverage. Everything else — including the backgrounds that run under the status
 * bar — is measured.
 *
 * THE APP SIDE IS PUT IN THE BOARD'S STATE. `?fidelity=<screen-id>` tells the app
 * (web + EXPO_PUBLIC_FIDELITY=1 only) to serve the board's own data from fixtures,
 * freeze the clock at the board's time, lay out with the board's insets (top 54,
 * bottom 26) and open whatever the board draws open (a result sheet, a focused
 * field). Without that the diff measures today's date against September's.
 * The app sets `data-fidelity-ready` on <html> when the state is fully rendered.
 *
 * React Native Web is not the phone: shadows and font metrics differ slightly.
 * This is the fast loop for spacing, colour and type scale; a Maestro screenshot
 * on a real device is the second check before a screen is called done.
 *
 *   npm run fidelity                       every routed screen
 *   npm run fidelity -- --surface guard-app
 *   npm run fidelity -- --only guard-app-03,guard-app-04
 */

import { chromium } from 'playwright'
import { PNG } from 'pngjs'
import { readFileSync, writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { maskedDiff, MASKED, W, H } from './lib.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const DESIGN = path.join(ROOT, '_design')
const OUT = path.join(DESIGN, 'screenshots', 'diff')
const ACTUAL = path.join(DESIGN, 'screenshots', 'actual')

const regions = JSON.parse(readFileSync(path.join(DESIGN, 'SCREEN_REGIONS.json'), 'utf8'))
const routes = JSON.parse(readFileSync(path.join(DESIGN, 'SCREEN_ROUTES.json'), 'utf8'))

const THRESHOLD = routes.threshold_percent ?? 2

const args = process.argv.slice(2)
const flag = (name) => {
    const i = args.indexOf(name)
    return i === -1 ? null : args[i + 1]
}
const only = flag('--only')?.split(',').map((s) => s.trim()) ?? null
const surface = flag('--surface')
const liftedMode = args.includes('--lifted')
// `--route guard-app-11=/orders` measures a screen before it is registered in SCREEN_ROUTES.json
// (parallel builders must not all edit that one file). Repeatable.
const routeOverrides = Object.fromEntries(args.flatMap((a, i) => (a === '--route' && args[i + 1] ? [args[i + 1].split('=')] : [])))
for (const [id, route] of Object.entries(routeOverrides)) routes.routes[id] = { ...(routes.routes[id] ?? {}), route }
const persistResults = Object.keys(routeOverrides).length === 0

/* ---- run -------------------------------------------------------------------- */

const FREEZE = `
*, *::before, *::after {
    animation-duration: 0s !important; animation-delay: 0s !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0s !important; transition-delay: 0s !important;
    caret-color: transparent !important;
}`

if (existsSync(OUT)) rmSync(OUT, { recursive: true })
mkdirSync(OUT, { recursive: true })
mkdirSync(ACTUAL, { recursive: true })

const targets = regions.screens
    .filter((s) => (surface ? s.surface === surface : true))
    .filter((s) => (only ? only.includes(s.id) : true))

const browser = await chromium.launch()
const results = []

for (const screen of targets) {
    const entry = liftedMode ? { route: `/lifted/${screen.id}` } : routes.routes[screen.id]

    if (!entry || !entry.route) {
        results.push({ screen, status: 'NOT BUILT' })
        continue
    }

    const base = routes.apps[screen.surface]
    const sep = entry.route.includes('?') ? '&' : '?'
    const url = `${base}${entry.route}${sep}fidelity=${screen.id}`

    const context = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1 })
    const page = await context.newPage()
    let actualBuf = null
    let loadError = null

    try {
        // Not 'networkidle': a phone connected to the same dev server keeps its network busy for minutes.
        // The app's own data-fidelity-ready flag (below) is the real signal that the drawn state is complete.
        // Generous: after a code change Metro rebuilds the Android bundle for any connected phone first
        // (30-40 s), and the web bundle request simply waits its turn behind it.
        const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 240000 })
        if (response && response.status() >= 400) {
            loadError = `HTTP ${response.status()}`
        } else {
            await page.waitForSelector('html[data-fidelity-ready]', { timeout: 45000 })
            await page.evaluate(() => document.fonts.ready)
            await page.waitForTimeout(150)
            await page.addStyleTag({ content: FREEZE })
            actualBuf = await page.screenshot({ clip: { x: 0, y: 0, width: W, height: H } })
        }
    } catch (e) {
        loadError = e.message.split('\n')[0]
    }

    await context.close()

    if (loadError !== null) {
        results.push({ screen, status: 'FAIL', percent: 100, note: loadError })
        continue
    }

    writeFileSync(path.join(ACTUAL, `${screen.id}.png`), actualBuf)

    const { percent, diff, expected, actual, sizeNote } = maskedDiff(readFileSync(path.join(ROOT, screen.expected)), actualBuf)
    const residual = entry.residual ?? null
    const pass = percent < THRESHOLD && sizeNote === null

    if (!pass) {
        const dir = path.join(OUT, screen.id)
        mkdirSync(dir, { recursive: true })
        writeFileSync(path.join(dir, 'original.png'), PNG.sync.write(expected))
        writeFileSync(path.join(dir, 'actual.png'), PNG.sync.write(actual))
        writeFileSync(path.join(dir, 'diff.png'), PNG.sync.write(diff))
    }

    results.push({ screen, status: pass ? 'PASS' : residual ? 'RESIDUAL' : 'FAIL', percent, note: sizeNote ?? residual })
}

await browser.close()

/* ---- the table -------------------------------------------------------------- */

const pad = (s, n) => String(s).padEnd(n)
const padL = (s, n) => String(s).padStart(n)

console.log(pad('SCREEN', 18) + pad('TITLE', 34) + padL('DIFF', 8) + '  RESULT')
console.log('-'.repeat(84))

for (const r of results.filter((x) => x.status !== 'NOT BUILT')) {
    const title = r.screen.title.length > 32 ? r.screen.title.slice(0, 31) + '…' : r.screen.title
    const figure = r.percent === undefined ? '—' : r.percent.toFixed(2) + '%'
    console.log(pad(r.screen.id, 18) + pad(title, 34) + padL(figure, 8) + '  ' + r.status + (r.note ? ` (${r.note})` : ''))
}

// Results are MERGED by screen id, so measuring three screens never forgets the other seventy-seven.
// A run with --route overrides (a builder checking its own unregistered screen) does not persist.
if (persistResults) {
    const file = path.join(DESIGN, liftedMode ? 'FIDELITY_LIFTED.json' : 'FIDELITY_RESULTS.json')
    const previous = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : []
    const fresh = results.filter((x) => x.status !== 'NOT BUILT').map((r) => ({ id: r.screen.id, title: r.screen.title, percent: r.percent === undefined ? null : Number(r.percent.toFixed(2)), status: r.status, note: r.note ?? null }))
    const merged = new Map([...previous, ...fresh].map((r) => [r.id, r]))
    writeFileSync(file, JSON.stringify([...merged.values()].sort((p, q) => p.id.localeCompare(q.id)), null, 2) + '\n')
}

const built = results.filter((r) => r.status !== 'NOT BUILT')
const passed = built.filter((r) => r.status === 'PASS')
const residuals = built.filter((r) => r.status === 'RESIDUAL')
const failed = built.filter((r) => r.status === 'FAIL')

console.log('-'.repeat(84))
console.log(`${passed.length}/${built.length} built screens under ${THRESHOLD}% · ${residuals.length} recorded residual(s) · ${failed.length} failing · ${results.length - built.length} not built`)
console.log(`Device mask: ${MASKED} px (${((MASKED / (W * H)) * 100).toFixed(2)}% of each screen) excluded — corners, island, home indicator, status-bar glyphs.`)

if (failed.length > 0) {
    console.log(`\nDiff images: _design/screenshots/diff/<screen>/`)
    process.exit(1)
}

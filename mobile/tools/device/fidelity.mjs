/**
 * `npm run device:fidelity` — the 80-screen sweep, on hardware.
 *
 * The browser sweep (tools/fidelity/compare.mjs) renders the same components through
 * react-native-web at exactly 397 x 856 and diffs them against the board. It is fast
 * and it is not the phone. This is the phone: navigate with a deep link, capture with
 * `screencap`, bring the capture into the board's frame (geometry.mjs), and hand it to
 * THE SAME comparison the browser sweep uses — `maskedDiff` and the same device mask
 * out of tools/fidelity/lib.mjs, imported, not copied. Nothing in compare.mjs is
 * touched, so no existing number moves.
 *
 * TWO NUMBERS PER SCREEN, ALWAYS BOTH:
 *   strict   what the browser harness's own comparison says about this capture, with
 *            no device allowance whatsoever;
 *   device   the same, after the bands a handset legitimately differs in — its own
 *            status bar, its own navigation bar, board rows no capture reaches — are
 *            excluded AND taken out of the denominator.
 * The 2% threshold is not moved for either. What the bands change is how much of the
 * screen was looked at, and that is printed under every table.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * READ THIS BEFORE BELIEVING A BOARD NUMBER FROM A DEVICE.
 *
 * The browser sweep does not only render the screen: `?fidelity=<id>` puts the app in
 * the BOARD'S STATE — the board's fixture data, the clock frozen at the board's
 * moment, the board's insets, whatever sheet the board draws open. Without that, a
 * comparison measures September's date against today's and tells you nothing.
 *
 * That mode is WEB ONLY, by design, and deliberately so: packages/core/fidelity checks
 * `Platform.OS === 'web'` at load and reads the id from the query string, and the env
 * flag is never set in eas.json so a store build can never enter it. Which means the
 * two development builds CANNOT be put in the board's state, and a
 * board comparison from them measures live data as well as rendering.
 *
 * So the default here is `--against browser`: device capture vs the browser harness's
 * own capture of the same screen, which isolates exactly the question being asked —
 * where the phone and the browser disagree. `--against board` is available and
 * produces a real number, but that number includes the data difference and says so on
 * every line.
 *
 * To get a true board number from a handset, two things have to happen, and both are
 * written up in the report rather than done quietly here:
 *   1. the fidelity module has to accept a screen id from a deep link on native, not
 *      only from a query string on web;
 *   2. a development build has to be made with EXPO_PUBLIC_FIDELITY=1 (or the dev
 *      client pointed at a Metro server started with it, which is how this laptop is
 *      already running).
 * ────────────────────────────────────────────────────────────────────────────────
 *
 *   npm run device:fidelity                          every routed screen, against the browser capture
 *   npm run device:fidelity -- --against board       against the design board
 *   npm run device:fidelity -- --only guard-app-03
 *   npm run device:fidelity -- --surface guard-app
 *   npm run device:fidelity -- --dry-run             every deep link it would open, with nothing attached
 *   npm run device:fidelity -- --from <dir>          compare captures already on disk, no handset needed
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync, rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { PNG } from 'pngjs'
import { adb, EXIT, ROOT } from './adb.mjs'
import { framePlan, normalise } from './geometry.mjs'
import { deviceDiff, extraExcluded, coverageLine } from './device-diff.mjs'
import { DEVICE_FILE, BUILDS } from './harness.mjs'

const DESIGN = path.join(ROOT, '_design')
const DEVICE_SHOTS = path.join(DESIGN, 'screenshots', 'device')
const DIFF_OUT = path.join(DESIGN, 'screenshots', 'diff-device')

const regions = JSON.parse(readFileSync(path.join(DESIGN, 'SCREEN_REGIONS.json'), 'utf8'))
const routes = JSON.parse(readFileSync(path.join(DESIGN, 'SCREEN_ROUTES.json'), 'utf8'))
const THRESHOLD = routes.threshold_percent ?? 2

const args = process.argv.slice(2)
const has = (f) => args.includes(f)
const valueOf = (f) => (args.indexOf(f) === -1 ? null : args[args.indexOf(f) + 1])

const only = valueOf('--only')?.split(',').map((s) => s.trim()) ?? null
const surface = valueOf('--surface')
const against = valueOf('--against') ?? 'browser'
const fromDir = valueOf('--from')
const settleMs = Number(valueOf('--settle') ?? 2500)
const dryRun = has('--dry-run')

const IS_ENTRY = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)

if (IS_ENTRY && !['browser', 'board'].includes(against)) {
  console.error(`--against takes "browser" or "board", not "${against}".`)
  process.exit(EXIT.failed)
}

/**
 * A deep link for one route. expo-router's group segments — `(tabs)` — are a file-tree
 * device and never part of a URL, so they come out. Recorded here rather than in a
 * comment on the day it surprises somebody.
 */
export function deepLink(scheme, route) {
  const clean = String(route)
    .split('/')
    .filter((seg) => seg.length > 0 && !/^\(.*\)$/.test(seg))
    .join('/')
  const [pathPart, query] = clean.split('?')
  return `${scheme}:///${pathPart}${query ? `?${query}` : ''}`
}

const referenceFor = (screen) =>
  against === 'board'
    ? path.join(ROOT, screen.expected)
    : path.join(DESIGN, 'screenshots', 'actual', `${screen.id}.png`)

async function main() {
  const targets = regions.screens
    .filter((s) => (surface ? s.surface === surface : true))
    .filter((s) => (only ? only.includes(s.id) : true))
    .filter((s) => routes.routes[s.id]?.route)

  console.log(`Device fidelity · ${targets.length} routed screen(s) · against the ${against === 'board' ? 'design board' : "browser harness's own capture"} · threshold ${THRESHOLD}%`)
  if (against === 'board') {
    console.log('CAVEAT, on every line below: these development builds cannot be put in the board\'s state (fidelity mode is web-only).')
    console.log('So a board number from a handset measures LIVE DATA AND THE REAL CLOCK as well as rendering. It is a real number; it is not the browser number\'s equal.')
  }

  /* facts: from the harness's record if it is there, otherwise asked now */
  let facts = null
  let device = null

  if (fromDir) {
    if (!existsSync(DEVICE_FILE)) {
      console.error(`--from needs ${DEVICE_FILE} for the handset's screen size, density and insets. Run npm run device:check first.`)
      process.exit(EXIT.failed)
    }
    facts = JSON.parse(readFileSync(DEVICE_FILE, 'utf8')).facts
  } else {
    device = adb({ dryRun, serial: valueOf('--serial') })
    device.requireDevice()
    facts = dryRun ? null : device.facts()
  }

  if (dryRun) {
    console.log('\nDRY RUN — no handset, no capture, no number. This is the sweep it would run:\n')
    for (const s of targets) {
      const build = BUILDS.find((b) => b.app === s.surface.replace('-app', ''))
      console.log(`  ${s.id.padEnd(18)} open ${deepLink(build?.scheme ?? '<scheme>', routes.routes[s.id].route)}`)
      console.log(`  ${''.padEnd(18)} wait ${settleMs} ms · screencap · normalise to 397x856 · maskedDiff against ${path.relative(ROOT, referenceFor(s))}`)
    }
    console.log(`\n${targets.length} screens. No number is claimed: a dry run has no capture.`)
    console.log('The arithmetic that turns a capture into a board frame is proved without a handset by: npm run device:selftest')
    return
  }

  const plan = framePlan(facts)
  console.log(`\nHandset: ${facts.manufacturer ?? ''} ${facts.model ?? ''} · ${facts.screen.width}x${facts.screen.height} @ ${facts.density} · ${facts.dpWidth} dp wide`)
  for (const n of plan.notes) console.log(`NOTE: ${n}`)

  mkdirSync(DEVICE_SHOTS, { recursive: true })
  if (existsSync(DIFF_OUT)) rmSync(DIFF_OUT, { recursive: true })
  mkdirSync(DIFF_OUT, { recursive: true })

  const results = []
  for (const screen of targets) {
    const build = BUILDS.find((b) => b.app === screen.surface.replace('-app', ''))
    const reference = referenceFor(screen)

    if (!existsSync(reference)) {
      results.push({ screen, status: 'NO REFERENCE', note: path.relative(ROOT, reference) })
      continue
    }

    let capture = null
    let note = null
    try {
      if (!fromDir) {
        device.openUrl(deepLink(build.scheme, routes.routes[screen.id].route))
        await new Promise((r) => setTimeout(r, settleMs))
        capture = PNG.sync.read(device.screencap())
      } else {
        const f = path.join(fromDir, `${screen.id}.png`)
        if (!existsSync(f)) {
          results.push({ screen, status: 'NO CAPTURE', note: path.relative(ROOT, f) })
          continue
        }
        capture = PNG.sync.read(readFileSync(f))
      }
    } catch (e) {
      results.push({ screen, status: 'FAIL', percent: 100, note: e.message.split('\n')[0] })
      continue
    }

    const framed = normalise(capture, plan)
    writeFileSync(path.join(DEVICE_SHOTS, `${screen.id}.png`), PNG.sync.write(framed))

    const d = deviceDiff(readFileSync(reference), framed, plan.bands)
    const pass = d.percent < THRESHOLD
    if (!pass) {
      const dir = path.join(DIFF_OUT, screen.id)
      mkdirSync(dir, { recursive: true })
      writeFileSync(path.join(dir, 'reference.png'), PNG.sync.write(d.expected))
      writeFileSync(path.join(dir, 'device.png'), PNG.sync.write(d.actual))
      writeFileSync(path.join(dir, 'diff.png'), PNG.sync.write(d.diff))
    }
    results.push({ screen, status: pass ? 'PASS' : 'FAIL', percent: d.percent, strict: d.strictPercent, note })
  }

  /* ---- the table ---- */
  const pad = (s, n) => String(s).padEnd(n)
  const padL = (s, n) => String(s).padStart(n)
  console.log('')
  console.log(pad('SCREEN', 18) + pad('TITLE', 32) + padL('STRICT', 9) + padL('DEVICE', 9) + '  RESULT')
  console.log('-'.repeat(88))
  for (const r of results) {
    const title = r.screen.title.length > 30 ? r.screen.title.slice(0, 29) + '…' : r.screen.title
    console.log(
      pad(r.screen.id, 18) +
        pad(title, 32) +
        padL(r.strict === undefined ? '—' : r.strict.toFixed(2) + '%', 9) +
        padL(r.percent === undefined ? '—' : r.percent.toFixed(2) + '%', 9) +
        '  ' + r.status + (r.note ? ` (${r.note})` : '')
    )
  }
  console.log('-'.repeat(88))

  const passed = results.filter((r) => r.status === 'PASS')
  console.log(`${passed.length}/${results.length} under ${THRESHOLD}% on device.`)
  console.log(coverageLine(extraExcluded(plan.bands)))
  console.log(`Reference: ${against === 'board' ? 'the design board' : "the browser harness's own capture of the same screen"}.`)

  const file = path.join(DESIGN, 'FIDELITY_DEVICE.json')
  const previous = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : { screens: [] }
  const fresh = results
    .filter((r) => r.percent !== undefined)
    .map((r) => ({ id: r.screen.id, title: r.screen.title, percent: Number(r.percent.toFixed(2)), strict: Number(r.strict.toFixed(2)), status: r.status }))
  const merged = new Map([...(previous.screens ?? []), ...fresh].map((r) => [r.id, r]))
  writeFileSync(
    file,
    JSON.stringify(
      {
        measuredAt: new Date().toISOString(),
        against,
        boardStateAvailable: false,
        caveat:
          against === 'board'
            ? 'These builds cannot be put in the board\'s state — fidelity mode is web-only — so this number includes live data and the real clock, not only rendering.'
            : 'Device capture against the browser harness\'s own capture of the same screen: what the handset renders differently, with the same data on both sides.',
        handset: { model: facts.model, android: facts.androidRelease, screen: facts.screen, density: facts.density, dpWidth: facts.dpWidth },
        frame: { scale: plan.scale, offsetY: plan.offsetY, typeScale: plan.typeScale, tailPx: plan.tailPx, notes: plan.notes },
        excludedExtraPx: extraExcluded(plan.bands),
        screens: [...merged.values()].sort((a, b) => a.id.localeCompare(b.id)),
      },
      null,
      2
    ) + '\n',
    'utf8'
  )
  console.log(`Written: ${path.relative(ROOT, file)} — then: node tools/fidelity/report.mjs`)

  if (results.some((r) => r.status === 'FAIL')) {
    console.log(`\nDiff images: _design/screenshots/diff-device/<screen>/`)
    process.exit(EXIT.failed)
  }
}

/** Only when run directly — selftest.mjs imports deepLink from here. */
if (IS_ENTRY) {
  main().catch((e) => {
    console.error(`\nThe device sweep stopped: ${e.message}`)
    process.exit(EXIT.failed)
  })
}

/**
 * PROVING THE DEVICE HARNESS WITH NO DEVICE.
 *
 * Everything below runs on made-up facts and generated images. No handset is touched,
 * no number from a handset is claimed, and nothing here would pass if the arithmetic
 * were wrong — which is the point, because the arithmetic is the half that would
 * otherwise be discovered to be wrong at 2am with a phone in somebody's hand.
 *
 * The probes, and what each one would catch:
 *
 *   1  adb's parsers        a handset that is attached but NOT authorised must be
 *                           reported as itself, never as absence. `wm size`, `wm
 *                           density` and three different shapes of inset dump.
 *   2  the frame arithmetic  four made-up handsets, including a 720p budget phone and
 *                           a tall 20:9 one. A wrong scale or a wrong anchor shows up
 *                           as an absurd offset here rather than as eighty failing
 *                           screens later.
 *   3  A ROUND TRIP          a board crop is blown up into a synthetic 2x handset
 *                           capture, complete with a status bar carrying the WRONG
 *                           clock and a navigation bar, then put back through
 *                           normalise() and the real comparison. It must come back
 *                           0.00%. This is the one probe that proves capture ->
 *                           frame -> diff end to end.
 *   4  the bands             changing ONLY the fake status bar and navigation bar must
 *                           still read 0.00%; changing 40x40 of the APP must not.
 *   5  THE DENOMINATOR       the invariant that stops a harness flattering itself:
 *                           excluding pixels must never make a percentage smaller.
 *                           With the same differences outside the bands, the device
 *                           number must be >= the strict number, always.
 *   6  the timezone rules    Europe/London is +00:00 in January and +01:00 in July,
 *                           and a verdict is only "set" when two independent readings
 *                           agree. A check that accepted one reading would pass on a
 *                           handset that had not moved.
 *   7  reading a screen      a made-up uiautomator dump: text, disabled controls,
 *                           tap-target sizes, money on a guard's screen, and whether
 *                           the operating system's permission box has focus.
 *   8  FLAG_SECURE           a black capture and a drawn one.
 *   9  redaction             nothing token-shaped reaches the evidence file.
 *  10  coverage              every numbered row in PHONE_CHECK.md is triaged in
 *                           plan.mjs, so the list cannot grow past the harness in
 *                           silence.
 *
 *   npm run device:selftest
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { PNG } from 'pngjs'
import { parseDevices, parseInsets, parseWmDensity, parseWmSize, ROOT } from './adb.mjs'
import { BOARD, framePlan, normalise, resample } from './geometry.mjs'
import { bandMask, deviceDiff, extraExcluded } from './device-diff.mjs'
import { MASK, MASKED, W, H, maskedDiff } from '../fidelity/lib.mjs'
import { expectedOffsetMinutes, parseOffset, timezoneVerdict, zoneVerdict } from './timezone.mjs'
import { findText, focusPackage, hasText, isPermissionDialog, moneyLike, parseUi, smallTargets } from './ui.mjs'
import { isMostlyBlack, colourDistance, regionColour } from './pixels.mjs'
import { redact } from './run-checks.mjs'
import { CHECKS, triage } from './plan.mjs'
import { deepLink } from './fidelity.mjs'

let failed = 0
const check = (name, ok, detail = '') => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  ${detail}` : ''}`)
  if (!ok) failed++
}
const section = (s) => console.log(`\n-- ${s} ${'-'.repeat(Math.max(0, 66 - s.length))}`)

/* ---- 1. adb's parsers ------------------------------------------------------ */
section('adb parsers')

check('no handset attached reads as an empty list', parseDevices('List of devices attached\n\n').length === 0)

const twoUp = parseDevices(
  'List of devices attached\nR5CT30ABCDE            device product:a52qnaxx model:SM_A525F device:a52q transport_id:3\nemulator-5554          offline\n1234567890             unauthorized\n'
)
check('a real handset is read with its model', twoUp[0]?.model === 'SM_A525F' && twoUp[0].state === 'device', twoUp[0]?.serial ?? '')
check('an UNAUTHORISED handset is reported as unauthorised, not as absent', twoUp.some((d) => d.state === 'unauthorized'))
check('an offline handset is kept in the list too', twoUp.some((d) => d.state === 'offline'))

check('wm size', JSON.stringify(parseWmSize('Physical size: 1080x2400')) === JSON.stringify({ width: 1080, height: 2400, source: 'physical' }))
check('wm size · an override wins, because it is what is drawn', parseWmSize('Physical size: 1440x3200\nOverride size: 1080x2400').width === 1080)
check('wm density', parseWmDensity('Physical density: 440') === 440)
check('wm density · override wins', parseWmDensity('Physical density: 560\nOverride density: 420') === 420)

const insetsModern = parseInsets(`
  InsetsState
    source [statusBars]: frame=[0,0][1080,110] visible=true
    source [navigationBars]: frame=[0,2337][1080,2400] visible=true
`)
check('insets · Android 13/14 InsetsState', insetsModern.statusBarPx === 110 && insetsModern.navBarPx === 63, JSON.stringify(insetsModern))

const insetsCutout = parseInsets('mDisplayCutout=DisplayCutout{insets=Rect(0, 96 - 0, 0) safeInsets=Rect(0, 96 - 0, 48)}')
check('insets · Android 11/12 DisplayCutout', insetsCutout.statusBarPx === 96 && insetsCutout.navBarPx === 48, JSON.stringify(insetsCutout))

const insetsOld = parseInsets('stable=(0,63,0,126)')
check('insets · the old stable= form', insetsOld.statusBarPx === 63 && insetsOld.navBarPx === 126, JSON.stringify(insetsOld))

const insetsNone = parseInsets('nothing useful here at all')
check('insets · nothing found comes back NULL rather than a guess', insetsNone.statusBarPx === null && insetsNone.navBarPx === null)

/* ---- 2. the frame arithmetic on four made-up handsets ---------------------- */
section('the board frame on four made-up handsets')

const handsets = [
  { name: 'a 1080x2400 20:9 phone @440', screen: { width: 1080, height: 2400 }, density: 440, insets: { statusBarPx: 110, navBarPx: 63 } },
  { name: 'a 720x1600 budget phone @320', screen: { width: 720, height: 1600 }, density: 320, insets: { statusBarPx: 72, navBarPx: 48 } },
  { name: 'a 1440x3200 flagship @560', screen: { width: 1440, height: 3200 }, density: 560, insets: { statusBarPx: 140, navBarPx: 84 } },
  { name: 'a short 1080x1920 16:9 phone @480', screen: { width: 1080, height: 1920 }, density: 480, insets: { statusBarPx: 72, navBarPx: 144 } },
]

for (const hs of handsets) {
  const p = framePlan(hs)
  const extra = extraExcluded(p.bands)
  const measured = W * H - MASKED - extra
  const ok =
    p.scaledWidth === BOARD.width &&
    Math.abs(p.offsetY + p.scale * hs.insets.statusBarPx - BOARD.statusBar) <= 1 &&
    measured > 0.6 * W * H
  check(`frame · ${hs.name}`, ok, `scale ${p.scale.toFixed(3)} · ${p.scaledWidth}x${p.scaledHeight} · offset ${p.offsetY} · type ${((p.typeScale - 1) * 100).toFixed(1)}% · ${((measured / (W * H)) * 100).toFixed(1)}% measured`)
}

const shortPhone = framePlan(handsets[3])
check(
  'frame · a 16:9 phone is SHORTER than the board and the uncovered rows are excluded, not counted as a match',
  shortPhone.tailPx < 0 && shortPhone.bands.some((b) => b.name === 'uncovered-bottom'),
  `tail ${shortPhone.tailPx} rows`
)
check('frame · a taller phone says so in words rather than silently cropping', framePlan(handsets[0]).notes.some((n) => /lays out from the BOTTOM/.test(n)))

/* ---- 3-5. the round trip, the bands, and the denominator ------------------- */
section('a board crop, through a synthetic handset, and back')

const boardFile = path.join(ROOT, '_design', 'screenshots', 'expected', 'guard-app-03.png')
const boardBuf = readFileSync(boardFile)
const board = PNG.sync.read(boardBuf)

/**
 * A handset that is EXACTLY 2x the board: 794 x 1712 at density 320 is 397 x 856 dp.
 * Chosen on purpose — every scaled pixel is a clean 2x2 block, so a correct pipeline
 * must come back bit-identical, and any residue is a bug and not resampling noise.
 */
const synthetic = { screen: { width: 794, height: 1712 }, density: 320, insets: { statusBarPx: 108, navBarPx: 52 } }
const synthPlan = framePlan(synthetic)
check('synthetic 2x handset · scale is exactly 0.5 and the anchor lands on the board', synthPlan.scale === 0.5 && synthPlan.offsetY === 0, `offset ${synthPlan.offsetY}`)

/** Nearest-neighbour 2x, so the downscale can undo it exactly. */
function blowUp2x(src) {
  const out = new PNG({ width: src.width * 2, height: src.height * 2 })
  for (let y = 0; y < out.height; y++) {
    for (let x = 0; x < out.width; x++) {
      const s = (Math.floor(y / 2) * src.width + Math.floor(x / 2)) * 4
      const o = (y * out.width + x) * 4
      out.data[o] = src.data[s]
      out.data[o + 1] = src.data[s + 1]
      out.data[o + 2] = src.data[s + 2]
      out.data[o + 3] = src.data[s + 3]
    }
  }
  return out
}

function fill(png, x0, y0, x1, y1, rgb) {
  for (let y = Math.max(0, y0); y < Math.min(png.height, y1); y++) {
    for (let x = Math.max(0, x0); x < Math.min(png.width, x1); x++) {
      const o = (y * png.width + x) * 4
      png.data[o] = rgb[0]
      png.data[o + 1] = rgb[1]
      png.data[o + 2] = rgb[2]
      png.data[o + 3] = 255
    }
  }
}

/** The capture a handset would hand over: the app, under a real status bar and nav bar. */
function capture({ statusRgb = [7, 20, 34], navRgb = [7, 20, 34], clockRgb = [255, 255, 255], blot = null, bars = true } = {}) {
  const png = blowUp2x(board)
  if (bars) {
    fill(png, 0, 0, png.width, synthetic.insets.statusBarPx, statusRgb)
    fill(png, 40, 30, 190, 80, clockRgb) // the handset's own clock, in its own place
    fill(png, png.width - 220, 30, png.width - 40, 80, clockRgb) // battery and signal
    fill(png, 0, png.height - synthetic.insets.navBarPx, png.width, png.height, navRgb)
  }
  if (blot) fill(png, blot.x, blot.y, blot.x + blot.w, blot.y + blot.h, blot.rgb)
  return png
}

const clean = normalise(capture(), synthPlan)
check('the normalised capture is exactly the board frame', clean.width === W && clean.height === H, `${clean.width}x${clean.height}`)

const roundTrip = deviceDiff(boardBuf, clean, synthPlan.bands)
check('ROUND TRIP · a handset capture of the board reads 0.00% against the board', roundTrip.percent === 0, `${roundTrip.percent.toFixed(4)}% device · ${roundTrip.strictPercent.toFixed(4)}% strict · ${roundTrip.differing} px differ`)

const otherClock = normalise(capture({ clockRgb: [255, 0, 0], statusRgb: [30, 30, 30], navRgb: [90, 90, 90] }), synthPlan)
const clockOnly = deviceDiff(boardBuf, otherClock, synthPlan.bands)
check(
  "BANDS · a different clock, a different status bar and a different nav bar do not move the number",
  clockOnly.percent === 0,
  `${clockOnly.percent.toFixed(4)}% device (strict, with no device allowance, would be ${clockOnly.strictPercent.toFixed(2)}%)`
)
check(
  'BANDS · and the strict number DOES see them, so the allowance is real and is being made on purpose',
  clockOnly.strictPercent > 0.5,
  `${clockOnly.strictPercent.toFixed(2)}%`
)

// 40 x 40 device pixels = 20 x 20 board pixels = 400 px of app.
const blotted = normalise(capture({ blot: { x: 360, y: 800, w: 40, h: 40, rgb: [255, 182, 39] } }), synthPlan)
const withBlot = deviceDiff(boardBuf, blotted, synthPlan.bands)
check('a 20x20 board-pixel change in the APP is caught', withBlot.differing >= 380 && withBlot.differing <= 400, `${withBlot.differing} px, ${withBlot.percent.toFixed(3)}%`)

/**
 * THE INVARIANT THAT STOPS A HARNESS FLATTERING ITSELF.
 *
 * Excluding pixels must never make a percentage smaller. Paint an exclusion on both
 * images and leave the denominator alone, and every screen quietly gets better — which
 * is exactly the dishonesty M6 warns about. So: the same differences, all of them
 * OUTSIDE the bands, must give a device number at least as large as the strict one,
 * because the denominator shrank and the numerator did not.
 *
 * The bars are left off this capture on purpose. With junk in the status bar the strict
 * number is legitimately larger — it is seeing the handset's own clock — and that
 * comparison would test nothing.
 */
const sameDifferences = normalise(capture({ bars: false, blot: { x: 360, y: 800, w: 40, h: 40, rgb: [255, 182, 39] } }), synthPlan)
const fair = deviceDiff(boardBuf, sameDifferences, synthPlan.bands)
check(
  'DENOMINATOR · with the same differences, excluding pixels never makes the percentage smaller',
  fair.differing === maskedDiff(boardBuf, PNG.sync.write(sameDifferences)).differing && fair.percent > fair.strictPercent,
  `same ${fair.differing} px differ · device ${fair.percent.toFixed(4)}% > strict ${fair.strictPercent.toFixed(4)}%`
)
check(
  'DENOMINATOR · the measured count is exactly the screen minus BOTH masks, counted once',
  withBlot.measured === W * H - MASKED - extraExcluded(synthPlan.bands),
  `${withBlot.measured} px measured of ${W * H}`
)

const overlap = bandMask([{ y0: 0, y1: 60 }])
let doubleCounted = 0
for (let i = 0; i < overlap.length; i++) if (overlap[i] && MASK[i]) doubleCounted++
check('DENOMINATOR · pixels the existing mask already covers are not subtracted twice', extraExcluded([{ y0: 0, y1: 60 }]) === 60 * W - doubleCounted, `${doubleCounted} px overlap with the existing mask`)

check('a downscale of a flat image is that flat image', (() => {
  const flat = new PNG({ width: 800, height: 800 })
  fill(flat, 0, 0, 800, 800, [17, 34, 51])
  const small = resample(flat, 397, 397)
  return small.data[0] === 17 && small.data[1] === 34 && small.data[2] === 51
})())

/* ---- 6. the timezone rules ------------------------------------------------- */
section('the timezone, which is the whole of R35')

const jan = new Date('2026-01-15T12:00:00Z')
const jul = new Date('2026-07-15T12:00:00Z')
check('Europe/London is +00:00 in January and +01:00 in July — so no fixed offset is ever asserted', expectedOffsetMinutes('Europe/London', jan) === 0 && expectedOffsetMinutes('Europe/London', jul) === 60)
check('Pacific/Auckland is +13:00 in January and +12:00 in July', expectedOffsetMinutes('Pacific/Auckland', jan) === 780 && expectedOffsetMinutes('Pacific/Auckland', jul) === 720)
check('America/Jamaica never moves: -05:00 all year', expectedOffsetMinutes('America/Jamaica', jan) === -300 && expectedOffsetMinutes('America/Jamaica', jul) === -300)
check('date +%z is read, not assumed', parseOffset('+0100') === 60 && parseOffset('-0500') === -300 && parseOffset('rubbish') === null)

check(
  'a zone is only "set" when the property AND the offset agree',
  timezoneVerdict({ wanted: 'Europe/London', prop: 'Europe/London', offsetText: '+0100', at: jul }).state === 'set'
)
check(
  'the property alone is NOT enough — the running processes may not have taken it',
  timezoneVerdict({ wanted: 'Europe/London', prop: 'Europe/London', offsetText: '-0500', at: jul }).state === 'partial'
)
check(
  'the offset alone is NOT enough — other zones share it (this is the one that would pass on a handset that never moved)',
  timezoneVerdict({ wanted: 'Europe/London', prop: 'America/Jamaica', offsetText: '+0100', at: jul }).state === 'partial'
)
check('a handset that ignored the command reads as unchanged', timezoneVerdict({ wanted: 'Pacific/Auckland', prop: 'America/Jamaica', offsetText: '-0500', at: jul }).state === 'unchanged')

/* The control pass — the reason this check does not report a failure every single run. */
check(
  'THE CONTROL PASS · a screen that moved 0.30% between zones and 0.29% in the SAME zone did not move because of the zone',
  zoneVerdict({ zoneDiff: 0.3, control: 0.29 }).state === 'unchanged',
  zoneVerdict({ zoneDiff: 0.3, control: 0.29 }).why
)
check(
  'THE CONTROL PASS · a screen that moved 0.90% between zones and 0.10% in the same zone DID — that is the five-hours-early bug',
  zoneVerdict({ zoneDiff: 0.9, control: 0.1 }).state === 'changed'
)
check(
  'THE CONTROL PASS · a screen that redraws 3% of itself every minute on its own is INCONCLUSIVE, never a pass',
  zoneVerdict({ zoneDiff: 3.2, control: 3.0 }).state === 'inconclusive'
)
check('THE CONTROL PASS · two identical captures are unchanged', zoneVerdict({ zoneDiff: 0, control: 0 }).state === 'unchanged')

/* ---- 7. reading a screen --------------------------------------------------- */
section('reading a screen out of uiautomator')

const dump = `<?xml version='1.0' encoding='UTF-8'?>
<hierarchy rotation="0">
 <node index="0" text="" class="android.widget.FrameLayout" package="jm.geminisecure.guard.dev" bounds="[0,0][1080,2400]">
  <node index="0" text="Main Gate — Static Post" class="android.widget.TextView" bounds="[48,320][900,380]" />
  <node index="1" text="Verify pass" class="android.widget.Button" clickable="true" enabled="false" bounds="[48,2000][1032,2132]" />
  <node index="2" text="7" class="android.widget.Button" clickable="true" enabled="true" bounds="[48,1200][148,1300]" />
  <node index="3" text="Gross $48,200.00" class="android.widget.TextView" bounds="[48,600][900,660]" />
  <node index="4" content-desc="Go back" class="android.widget.ImageView" clickable="true" enabled="true" bounds="[40,150][172,282]" />
  <node index="5" text="Let a duress alarm through Do Not Disturb" class="android.widget.Switch" checkable="true" checked="false" enabled="true" bounds="[48,900][1032,1010]" />
 </node>
</hierarchy>`

const nodes = parseUi(dump)
check('every node is read', nodes.length === 7, `${nodes.length} nodes`)
check('text is read, em dash and all', hasText(nodes, 'Main Gate — Static Post'))
check('a DISABLED control is seen as disabled — a screenshot could not tell you this', findText(nodes, 'Verify pass')[0].enabled === false)
check('an unticked switch is seen as unticked', findText(nodes, 'Do Not Disturb')[0].checked === false)
check('bounds are read', findText(nodes, 'Verify pass')[0].bounds.w === 984)
check('money on a guard screen is caught', moneyLike(nodes).length === 1 && moneyLike(nodes)[0].includes('$48,200.00'))
check('a 100x100 px target at density 440 is 36 dp and is caught as too small', smallTargets(nodes, 440, 48).some((t) => t.label === '7'), JSON.stringify(smallTargets(nodes, 440, 48).map((t) => t.label)))
check('a 132x132 px target at density 440 is 48 dp and passes', !smallTargets(nodes, 440, 48).some((t) => t.label === 'Go back'))

check('the app having focus is read as the app', focusPackage('mCurrentFocus=Window{a1b2 u0 jm.geminisecure.guard.dev/jm.geminisecure.guard.dev.MainActivity}') === 'jm.geminisecure.guard.dev')
check(
  "the operating system's permission box is recognised — this is the whole of G27",
  isPermissionDialog('mCurrentFocus=Window{c3d4 u0 com.google.android.permissioncontroller/com.android.permissioncontroller.permission.ui.GrantPermissionsActivity}')
)
check('…and the app is not mistaken for it', !isPermissionDialog('mCurrentFocus=Window{a1b2 u0 jm.geminisecure.guard.dev/jm.geminisecure.guard.dev.MainActivity}'))
check('nothing readable comes back null rather than a guess', focusPackage(null) === null && focusPackage('') === null)

/* ---- 8. FLAG_SECURE -------------------------------------------------------- */
section('FLAG_SECURE, which is how R19 is answered')

const blackShot = new PNG({ width: 100, height: 200 })
fill(blackShot, 0, 0, 100, 200, [0, 0, 0])
check('a screencap of a protected window is black, and is read as protected', isMostlyBlack(blackShot).ok)
check('a drawn screen is not', !isMostlyBlack(board).ok, `${(isMostlyBlack(board).black * 100).toFixed(1)}% black`)

const amber = regionColour(board, { x0: 0, y0: 0, x1: 40, y1: 40 })
check('a region average is read', amber !== null && typeof amber.r === 'number')
check('two clearly different colours are called different (G23)', colourDistance({ r: 255, g: 182, b: 39 }, { r: 220, g: 38, b: 38 }) > 40)
check('two ambers are NOT called different', colourDistance({ r: 255, g: 182, b: 39 }, { r: 250, g: 176, b: 45 }) < 40)

/* ---- 9. redaction ---------------------------------------------------------- */
section('nothing secret reaches the evidence file')

check('a JWT is redacted', !redact('token eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r').includes('eyJhbGciOiJIUzI1'))
check('an Expo push token is redacted', redact('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]').includes('[redacted'))
check('a long hex secret is redacted', redact('key a3f5b8c9d0e1f2a3b4c5d6e7f8091a2b').includes('[redacted'))
check('ordinary copy is left alone', redact('Main Gate — Static Post · Gross $48,200.00') === 'Main Gate — Static Post · Gross $48,200.00')

/* ---- 10. coverage ---------------------------------------------------------- */
section('every row of PHONE_CHECK.md is triaged')

const phoneCheck = readFileSync(path.join(ROOT, 'PHONE_CHECK.md'), 'utf8')
const rowIds = [...phoneCheck.matchAll(/^\|\s*([GRD]\d+b?)\s*\|/gm)].map((m) => m[1])
const triaged = new Set(CHECKS.map((c) => c.id))
const missing = rowIds.filter((id) => !triaged.has(id))
const extraRows = [...triaged].filter((id) => !rowIds.includes(id))

check(`PHONE_CHECK.md has ${rowIds.length} numbered rows and every one is triaged`, missing.length === 0, missing.length ? `missing: ${missing.join(', ')}` : '')
check('and nothing is triaged that is not in the list', extraRows.length === 0, extraRows.length ? `not in PHONE_CHECK.md: ${extraRows.join(', ')}` : '')
check('no row is triaged twice', triaged.size === CHECKS.length)

const t = triage()
check('the five headline checks are all scripted, not merely triaged', CHECKS.filter((c) => c.first).every((c) => c.method === 'scripted'), CHECKS.filter((c) => c.first).map((c) => c.id).join(' '))
check('what is left for a person is short', t.humanList.length <= 12, `${t.humanList.length} items`)

check('a deep link drops expo-router group segments', deepLink('gsguard', '/(tabs)/payslips') === 'gsguard:///payslips', deepLink('gsguard', '/(tabs)/payslips'))
check('a plain route is left alone', deepLink('gs', '/settings/notifications') === 'gs:///settings/notifications')

/* ---- done ------------------------------------------------------------------ */
console.log('')
console.log(`Board frame ${W}x${H} · existing device mask ${MASKED} px (${((MASKED / (W * H)) * 100).toFixed(2)}%) · synthetic-handset bands ${extraExcluded(synthPlan.bands)} px (${((extraExcluded(synthPlan.bands) / (W * H)) * 100).toFixed(2)}%).`)
console.log(`${t.total} PHONE_CHECK rows · ${t.scripted} scripted · ${t.automatable} automatable with the method named · ${t.offDevice} in the console · ${t.human} need a person.`)

if (failed > 0) {
  console.error(`\n${failed} device self-test(s) failed.`)
  process.exit(1)
}
console.log('\nDevice harness self-test passed. No handset was touched and no device number is claimed.')

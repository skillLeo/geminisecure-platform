/**
 * PUTTING A PHONE'S SCREENSHOT INTO THE BOARD'S FRAME — the arithmetic, on its own,
 * with no adb and no files, so it can be proved on made-up handsets.
 *
 * The browser harness renders at exactly 397 x 856 CSS pixels because the board's
 * region is 397 x 856 (tools/fidelity/compare.mjs). A handset is
 * not 397 x 856 anything. A 1080 x 2400 phone at density 440 draws the app 392.7 dp
 * wide and 872.7 dp tall. So a device screenshot has to be brought into the board's
 * frame before the EXISTING comparison can be used on it — and every place where
 * that cannot be done exactly is a real difference that gets NAMED, not absorbed.
 *
 * FOUR DIFFERENCES A DEVICE HAS AND A BROWSER DOES NOT. Each is handled explicitly
 * and each is printed:
 *
 *  1. **Width in dp is not 397.** Scaling the capture to 397 wide also scales the
 *     type: on a 392.7 dp phone a 16 dp label lands 1.1% larger than the board's.
 *     That is REPORTED as `typeScale`, not corrected — correcting it would be
 *     inventing a screenshot. It is the main reason a device number is a little
 *     higher than the browser number on text-heavy screens.
 *  2. **Height does not match either.** The board is 856 tall; a phone scaled to 397
 *     wide is usually taller (882 on the phone above). The image is anchored at the
 *     TOP OF THE APP'S CONTENT — the board's 54 px status bar lined up with the
 *     handset's real status-bar inset — so everything laid out from the top aligns.
 *     Anything laid out from the BOTTOM (a tab bar, a floating panic button) sits
 *     lower on a taller phone and WILL differ. That is honest: `tailPx` says how
 *     many board pixels of difference in height there are, and a screen with a tab
 *     bar is expected to show it.
 *  3. **The real status bar.** The board draws a made-up clock and three icons, and
 *     the existing mask already covers exactly those rectangles. A handset's status
 *     bar is a different height with different glyphs in different places, so the
 *     whole band above the app's content is excluded as `device-status-bar` — and
 *     the pixel count is printed every run, beside the existing mask's, so nobody
 *     mistakes exclusion for coverage.
 *  4. **The navigation bar.** Same again at the bottom, when the handset's gesture
 *     bar or three-button bar lands inside the board's 856 rows.
 *
 * Nothing here widens the 2% budget. The budget is the budget; what changes is that
 * fewer pixels are measured, and the count of unmeasured pixels is on the console.
 */

import { PNG } from 'pngjs'
import { W, H } from '../fidelity/lib.mjs'

/** The board's own frame, from packages/core/theme/spacing.ts `layout`. */
export const BOARD = { width: W, height: H, statusBar: 54, bottomInset: 26 }

/**
 * Work out how one handset's capture maps onto the board's frame.
 *
 * @param {{screen: {width: number, height: number}, density: number, insets: {statusBarPx: number, navBarPx: number}}} facts
 * @returns {{
 *   scale: number, scaledWidth: number, scaledHeight: number, offsetY: number,
 *   dpWidth: number, dpHeight: number, typeScale: number, tailPx: number,
 *   covered: {top: number, bottom: number}, bands: {name: string, reason: string, y0: number, y1: number}[],
 *   notes: string[]
 * }}
 */
export function framePlan(facts) {
  const px = facts.screen
  const density = facts.density
  const statusBarPx = facts.insets.statusBarPx
  const navBarPx = facts.insets.navBarPx

  if (!px || !px.width || !px.height) throw new Error('framePlan: no screen size')
  if (!density) throw new Error('framePlan: no density')

  const scale = BOARD.width / px.width
  const scaledWidth = BOARD.width
  const scaledHeight = Math.round(px.height * scale)

  const dpWidth = (px.width * 160) / density
  const dpHeight = (px.height * 160) / density
  const typeScale = BOARD.width / dpWidth

  // Anchor: the top of the app's content. The board puts it at y = 54.
  const deviceContentTop = statusBarPx * scale
  const offsetY = Math.round(BOARD.statusBar - deviceContentTop)

  const covered = { top: Math.max(0, offsetY), bottom: Math.min(BOARD.height, offsetY + scaledHeight) }
  const tailPx = offsetY + scaledHeight - BOARD.height

  const bands = []
  const notes = []

  // 1 — the handset's own status bar, plus the board's, whichever reaches lower.
  const statusBottom = Math.min(BOARD.height, Math.ceil(Math.max(BOARD.statusBar, offsetY + statusBarPx * scale)) + 1)
  bands.push({
    name: 'device-status-bar',
    reason: `the handset's real clock, battery and signal glyphs (${statusBarPx}px inset -> ${statusBottom} board rows). The board draws a different clock in a different place; the existing mask only covers the board's two rectangles.`,
    y0: 0,
    y1: statusBottom,
  })

  // 2 — the handset's navigation / gesture bar, when it lands inside the board's rows.
  const navTop = Math.floor(offsetY + (px.height - navBarPx) * scale) - 1
  if (navTop < BOARD.height) {
    bands.push({
      name: 'device-nav-bar',
      reason: `the handset's gesture or three-button bar (${navBarPx}px inset). The board draws a home indicator, which the existing mask covers; this is the band the real one occupies.`,
      y0: Math.max(0, navTop),
      y1: BOARD.height,
    })
  } else {
    notes.push(`The handset's navigation bar falls below the board's 856 rows (at ${navTop}), so it is not in the comparison at all.`)
  }

  // 3 — rows of the board no part of the capture reaches.
  if (covered.top > 0) bands.push({ name: 'uncovered-top', reason: `no capture covers board rows 0-${covered.top}`, y0: 0, y1: covered.top })
  if (covered.bottom < BOARD.height) bands.push({ name: 'uncovered-bottom', reason: `no capture covers board rows ${covered.bottom}-${BOARD.height}`, y0: covered.bottom, y1: BOARD.height })

  if (Math.abs(typeScale - 1) > 0.005) {
    notes.push(`This handset draws the app ${dpWidth.toFixed(1)} dp wide, not 397. Scaling the capture to the board's width also scales the type by ${((typeScale - 1) * 100).toFixed(1)}%. Reported, not corrected.`)
  }
  if (tailPx !== 0) {
    notes.push(`Scaled to the board's width the handset is ${scaledHeight} rows tall against the board's ${BOARD.height} (${tailPx > 0 ? '+' : ''}${tailPx}). Aligned at the top of the app's content, so anything the app lays out from the BOTTOM — a tab bar, the resident's panic button — sits ${Math.abs(tailPx)} rows out and will show as a difference. That is the handset, not the build.`)
  }

  return { scale, scaledWidth, scaledHeight, offsetY, dpWidth, dpHeight, typeScale, tailPx, covered, bands, notes }
}

/**
 * Area-average resample. For a downscale — which this always is, 1080 -> 397 — an
 * area average is the right filter and the closest thing to what a browser does at
 * deviceScaleFactor 1. Bilinear would alias the app's hairlines into and out of
 * existence between runs, which would make the number unrepeatable.
 */
export function resample(src, outW, outH) {
  const out = new PNG({ width: outW, height: outH })
  const xr = src.width / outW
  const yr = src.height / outH

  for (let y = 0; y < outH; y++) {
    const sy0 = y * yr
    const sy1 = Math.min(src.height, (y + 1) * yr)
    const y0 = Math.floor(sy0)
    const y1 = Math.max(y0 + 1, Math.ceil(sy1))

    for (let x = 0; x < outW; x++) {
      const sx0 = x * xr
      const sx1 = Math.min(src.width, (x + 1) * xr)
      const x0 = Math.floor(sx0)
      const x1 = Math.max(x0 + 1, Math.ceil(sx1))

      let r = 0, g = 0, b = 0, a = 0, n = 0
      for (let sy = y0; sy < y1 && sy < src.height; sy++) {
        for (let sx = x0; sx < x1 && sx < src.width; sx++) {
          const o = (sy * src.width + sx) * 4
          r += src.data[o]
          g += src.data[o + 1]
          b += src.data[o + 2]
          a += src.data[o + 3]
          n++
        }
      }
      const o = (y * outW + x) * 4
      out.data[o] = Math.round(r / n)
      out.data[o + 1] = Math.round(g / n)
      out.data[o + 2] = Math.round(b / n)
      out.data[o + 3] = Math.round(a / n)
    }
  }
  return out
}

/** The flat colour painted over anything excluded — the same magenta lib.mjs uses. */
export const NEUTRAL = [255, 0, 255, 255]

/**
 * Bring one capture into the board's 397 x 856 frame: scale by width, anchor at the
 * top of the app's content, crop or pad. Rows nothing covers are left NEUTRAL and
 * are already listed as `uncovered-*` bands by framePlan, so they are excluded from
 * the denominator rather than counted as a perfect match.
 *
 * @param {PNG} capture  the handset's screenshot, at its own pixel size
 * @param {ReturnType<framePlan>} plan
 * @returns {PNG} 397 x 856
 */
export function normalise(capture, plan) {
  const scaled = resample(capture, plan.scaledWidth, plan.scaledHeight)
  const canvas = new PNG({ width: BOARD.width, height: BOARD.height })

  for (let i = 0; i < canvas.data.length; i += 4) {
    canvas.data[i] = NEUTRAL[0]
    canvas.data[i + 1] = NEUTRAL[1]
    canvas.data[i + 2] = NEUTRAL[2]
    canvas.data[i + 3] = NEUTRAL[3]
  }

  const srcY = Math.max(0, -plan.offsetY)
  const dstY = Math.max(0, plan.offsetY)
  const rows = Math.min(plan.scaledHeight - srcY, BOARD.height - dstY)
  if (rows > 0) PNG.bitblt(scaled, canvas, 0, srcY, BOARD.width, rows, 0, dstY)

  return canvas
}

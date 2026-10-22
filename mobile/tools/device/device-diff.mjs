/**
 * The comparison for device captures. It does NOT reimplement the diff.
 *
 * `maskedDiff` in tools/fidelity/lib.mjs is the comparison for this project: 397x856,
 * pixelmatch at 0.1, the device mask (corners, island, home indicator, status-bar
 * glyphs) painted flat on both sides and taken out of the denominator. That code is
 * load-bearing and every number in FIDELITY.md came out of it, so it is imported here
 * unchanged and called — twice:
 *
 *   strict   exactly what the browser harness would report for this image. No extra
 *            allowance of any kind. This is the number that cannot be argued with.
 *   allowed  the same call, after the bands a real handset legitimately differs in
 *            (its own status bar, its own navigation bar, rows no capture reaches)
 *            have been painted the SAME flat colour on both images — and with those
 *            pixels REMOVED FROM THE DENOMINATOR, which is the part that is easy to
 *            get wrong in one's own favour. Painting them and leaving the denominator
 *            alone would quietly shrink every percentage.
 *
 * Both numbers are recorded for every screen. The threshold is still 2% and is never
 * moved; what the bands change is how many pixels were looked at, and that count is
 * printed on every run.
 */

import { PNG } from 'pngjs'
import { maskedDiff, MASK, MASKED, W, H, fit } from '../fidelity/lib.mjs'
import { NEUTRAL } from './geometry.mjs'

/** @param {{y0:number,y1:number,x0?:number,x1?:number}[]} bands */
export function bandMask(bands) {
  const mask = new Uint8Array(W * H)
  for (const b of bands) {
    const x0 = Math.max(0, b.x0 ?? 0)
    const x1 = Math.min(W, b.x1 ?? W)
    const y0 = Math.max(0, Math.floor(b.y0))
    const y1 = Math.min(H, Math.ceil(b.y1))
    for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) mask[y * W + x] = 1
  }
  return mask
}

/**
 * How many pixels the bands take out that the EXISTING device mask was not already
 * taking out. This is the number that must come off the denominator — counting the
 * overlap twice would make the percentage larger, not smaller, but it would still be
 * wrong, and a wrong denominator is how a harness starts reporting believable
 * nonsense.
 */
export function extraExcluded(bands) {
  const b = bandMask(bands)
  let n = 0
  for (let i = 0; i < b.length; i++) if (b[i] && !MASK[i]) n++
  return n
}

function paint(png, mask) {
  for (let i = 0; i < mask.length; i++) {
    if (mask[i]) {
      const o = i * 4
      png.data[o] = NEUTRAL[0]
      png.data[o + 1] = NEUTRAL[1]
      png.data[o + 2] = NEUTRAL[2]
      png.data[o + 3] = NEUTRAL[3]
    }
  }
  return png
}

/**
 * @param {Buffer} expectedBuf  the board crop, as it is on disk
 * @param {PNG}    actualPng    a capture already normalised to 397 x 856
 * @param {{name:string,reason:string,y0:number,y1:number}[]} bands
 */
export function deviceDiff(expectedBuf, actualPng, bands = []) {
  const actualBuf = PNG.sync.write(actualPng)
  const strict = maskedDiff(expectedBuf, actualBuf)

  if (bands.length === 0) {
    return {
      strictPercent: strict.percent,
      percent: strict.percent,
      differing: strict.differing,
      measured: W * H - MASKED,
      extraExcluded: 0,
      bands: [],
      diff: strict.diff,
      expected: strict.expected,
      actual: strict.actual,
      sizeNote: strict.sizeNote,
    }
  }

  const mask = bandMask(bands)
  const expectedPainted = paint(fit(PNG.sync.read(expectedBuf)), mask)
  const actualPainted = paint(fit(PNG.sync.read(actualBuf)), mask)
  const allowed = maskedDiff(PNG.sync.write(expectedPainted), PNG.sync.write(actualPainted))

  const extra = extraExcluded(bands)
  const measured = W * H - MASKED - extra
  if (measured <= 0) throw new Error('device-diff: the bands would leave nothing measured')

  return {
    strictPercent: strict.percent,
    percent: (allowed.differing / measured) * 100,
    differing: allowed.differing,
    measured,
    extraExcluded: extra,
    bands: bands.map((b) => ({ name: b.name, reason: b.reason, rows: `${Math.round(b.y0)}-${Math.round(b.y1)}` })),
    diff: allowed.diff,
    expected: allowed.expected,
    actual: allowed.actual,
    sizeNote: strict.sizeNote,
  }
}

/** For the console line under every table. */
export function coverageLine(extra) {
  const total = W * H
  return (
    `Excluded: ${MASKED} px (${((MASKED / total) * 100).toFixed(2)}%) by the existing device mask — corners, island, home indicator, status-bar glyphs; ` +
    `plus ${extra} px (${((extra / total) * 100).toFixed(2)}%) for this handset's own status bar, navigation bar and any rows the capture does not reach. ` +
    `${total - MASKED - extra} px (${(((total - MASKED - extra) / total) * 100).toFixed(2)}%) measured. Threshold unchanged at 2%.`
  )
}

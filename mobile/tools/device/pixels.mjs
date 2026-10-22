/**
 * The few questions that can only be answered by looking at pixels, kept pure so they
 * can be proved on generated images.
 *
 * `isMostlyBlack` is the whole of check R19. Android answers a `screencap` of a window
 * that has FLAG_SECURE with a BLACK image — that is the same refusal a screenshot from
 * the phone's own buttons gets. So a black capture of the ballot paper is positive
 * evidence that the paper is protected, and a NOT-black capture of the receipt
 * afterwards is positive evidence that the receipt is allowed, which is the other half
 * of the row and the half that is easy to forget.
 *
 * `regionColour` is check G23: a licence expiring soon and a licence already expired
 * must not both be amber. Two rows, two averages, and a refusal to call them different
 * unless they really are.
 */

/** @param {import('pngjs').PNG} png */
export function isMostlyBlack(png, { maxChannel = 12, minShare = 0.995 } = {}) {
  let black = 0
  const total = png.width * png.height
  for (let i = 0; i < png.data.length; i += 4) {
    if (png.data[i] <= maxChannel && png.data[i + 1] <= maxChannel && png.data[i + 2] <= maxChannel) black++
  }
  return { black: black / total, ok: black / total >= minShare }
}

/** Average colour of a rectangle, in device pixels. */
export function regionColour(png, { x0, y0, x1, y1 }) {
  let r = 0, g = 0, b = 0, n = 0
  const xa = Math.max(0, Math.floor(x0))
  const xb = Math.min(png.width, Math.ceil(x1))
  const ya = Math.max(0, Math.floor(y0))
  const yb = Math.min(png.height, Math.ceil(y1))
  for (let y = ya; y < yb; y++) {
    for (let x = xa; x < xb; x++) {
      const o = (y * png.width + x) * 4
      r += png.data[o]
      g += png.data[o + 1]
      b += png.data[o + 2]
      n++
    }
  }
  if (n === 0) return null
  return { r: Math.round(r / n), g: Math.round(g / n), b: Math.round(b / n), pixels: n }
}

/**
 * Plain Euclidean distance in RGB. Not a perceptual metric and not pretending to be
 * one — it is used for exactly one question, "are these two status colours actually
 * different?", where the two candidates are amber and red and the honest answer is
 * obvious at any threshold.
 */
export function colourDistance(a, b) {
  if (!a || !b) return null
  return Math.round(Math.sqrt((a.r - b.r) ** 2 + (a.g - b.g) ** 2 + (a.b - b.b) ** 2))
}

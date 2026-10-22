/**
 * Shared by compare.mjs and selftest.mjs: the device mask and the masked diff.
 * See compare.mjs for why the mask exists and what it covers.
 */

import { PNG } from 'pngjs'
import pixelmatch from 'pixelmatch'

export const W = 397
export const H = 856
export const PIXEL_THRESHOLD = 0.1 // per-pixel colour tolerance: antialiasing passes, a changed shade does not

/** True where a pixel belongs to the physical device or the OS, never to the app. */
function buildMask() {
    const mask = new Uint8Array(W * H)
    const set = (x, y) => {
        if (x >= 0 && x < W && y >= 0 && y < H) mask[y * W + x] = 1
    }

    // 48px rounded screen corners — the .screen's border-radius, with the bezel showing through.
    const R = 48
    for (let y = 0; y < R; y++) {
        for (let x = 0; x < R; x++) {
            const dx = R - x - 0.5
            const dy = R - y - 0.5
            // 1.5px inside the curve so its antialiased edge is masked too.
            if (dx * dx + dy * dy > (R - 1.5) * (R - 1.5)) {
                set(x, y)
                set(W - 1 - x, y)
                set(x, H - 1 - y)
                set(W - 1 - x, H - 1 - y)
            }
        }
    }

    const rect = (x0, y0, w, h) => {
        for (let y = y0; y < y0 + h; y++) for (let x = x0; x < x0 + w; x++) set(x, y)
    }

    // .island — top 12, centred, 120 x 35 (1px bleed for antialiasing).
    rect(Math.round((W - 120) / 2) - 1, 11, 122, 37)
    // .home-ind — bottom 9, centred, 134 x 5.
    rect(Math.round((W - 134) / 2) - 1, H - 9 - 5 - 1, 136, 7)
    // .statusbar clock — padding-left 30, box ending at y 45, 15px Inter 600.
    rect(26, 24, 52, 24)
    // .statusbar icons — 19 + 5 + 15 + 5 + 23 wide, right padding 30, 11 tall, bottom at 45.
    rect(W - 30 - 67 - 4, 30, 67 + 8, 19)

    return mask
}

export const MASK = buildMask()
export const MASKED = MASK.reduce((n, v) => n + v, 0)
export const MEASURED = W * H - MASKED

export function applyMask(png) {
    for (let i = 0; i < MASK.length; i++) {
        if (MASK[i]) {
            const o = i * 4
            png.data[o] = 255
            png.data[o + 1] = 0
            png.data[o + 2] = 255
            png.data[o + 3] = 255
        }
    }
    return png
}

/** Crop or pad to the nominal region without scaling — a size mismatch is reported, never scaled away. */
export function fit(png) {
    if (png.width === W && png.height === H) return png
    const out = new PNG({ width: W, height: H })
    PNG.bitblt(png, out, 0, 0, Math.min(W, png.width), Math.min(H, png.height), 0, 0)
    return out
}

/**
 * @returns {{ percent: number, differing: number, diff: PNG, expected: PNG, actual: PNG, sizeNote: string|null }}
 */
export function maskedDiff(expectedBuf, actualBuf) {
    const expectedRaw = PNG.sync.read(expectedBuf)
    const actualRaw = PNG.sync.read(actualBuf)
    const sizeNote =
        actualRaw.width !== W || actualRaw.height !== H ? `size ${actualRaw.width}x${actualRaw.height} vs ${W}x${H}` : null

    const expected = applyMask(fit(expectedRaw))
    const actual = applyMask(fit(actualRaw))
    const diff = new PNG({ width: W, height: H })

    const differing = pixelmatch(expected.data, actual.data, diff.data, W, H, {
        threshold: PIXEL_THRESHOLD,
        includeAA: false,
    })

    return { percent: (differing / MEASURED) * 100, differing, diff, expected, actual, sizeNote }
}

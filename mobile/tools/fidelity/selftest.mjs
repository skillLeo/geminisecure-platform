/**
 * Proves the harness on known inputs before any app exists.
 *
 *   1. a board crop against itself                    must read 0.00%
 *   2. a board crop against a different screen        must fail by a wide margin
 *   3. a crop with ONLY masked pixels changed          must still read 0.00%
 *      (the island, the home indicator, the corners and the status-bar glyphs are
 *       the OS's; changing them must not move the number)
 *   4. a crop with a 20 x 20 block changed OUTSIDE the mask must be detected
 *
 * A harness that cannot tell these apart would report believable percentages
 * that mean nothing, which is worse than no number.
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { PNG } from 'pngjs'
import { maskedDiff, MASK, MASKED, W, H } from './lib.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const crop = (id) => readFileSync(path.join(ROOT, '_design', 'screenshots', 'expected', `${id}.png`))

let failed = 0
const check = (name, ok, detail) => {
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}  ${detail}`)
    if (!ok) failed++
}

const a = crop('guard-app-03')
const b = crop('resident-app-05')

const same = maskedDiff(a, a)
check('identical crops', same.percent === 0, `${same.percent.toFixed(2)}%`)

const other = maskedDiff(a, b)
check('different screens', other.percent > 20, `${other.percent.toFixed(2)}%`)

// Change every masked pixel to white and nothing else.
const onlyMasked = PNG.sync.read(a)
for (let i = 0; i < MASK.length; i++) {
    if (MASK[i]) {
        onlyMasked.data[i * 4] = 255
        onlyMasked.data[i * 4 + 1] = 255
        onlyMasked.data[i * 4 + 2] = 255
    }
}
const masked = maskedDiff(a, PNG.sync.write(onlyMasked))
check('only device pixels changed', masked.percent === 0, `${masked.percent.toFixed(2)}%`)

// A 20 x 20 amber block in the middle of the screen — 400 px, ~0.12%.
const blot = PNG.sync.read(a)
for (let y = 400; y < 420; y++) {
    for (let x = 180; x < 200; x++) {
        const o = (y * W + x) * 4
        blot.data[o] = 255
        blot.data[o + 1] = 182
        blot.data[o + 2] = 39
    }
}
const blotted = maskedDiff(a, PNG.sync.write(blot))
check('a 20x20 change outside the mask', blotted.differing >= 380 && blotted.differing <= 400, `${blotted.differing} px, ${blotted.percent.toFixed(2)}%`)

console.log(`\nDevice mask: ${MASKED} px = ${((MASKED / (W * H)) * 100).toFixed(2)}% of a ${W}x${H} screen.`)

if (failed > 0) {
    console.error(`\n${failed} self-test(s) failed.`)
    process.exit(1)
}
console.log('Harness self-test passed.')

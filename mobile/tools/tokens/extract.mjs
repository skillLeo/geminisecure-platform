/**
 * Lifts the design tokens out of `GeminiSecure Foundation.dc.html` — the locked
 * style guide — into packages/core/theme/tokens.json.
 *
 * LIFTED, NOT TYPED. On web the boards' stylesheets were copied verbatim; React
 * Native has no CSS, so the values are parsed out of the Foundation file by this
 * script and nobody retypes a hex. `--check` exits 1 if tokens.json no longer
 * matches the Foundation file, which is the gate that catches a hand edit.
 *
 * What it reads:
 *   :root { --token: #HEX; … }     the 33 colour tokens
 *   const SEMANTIC = [...]          success / success-100 (not in :root)
 *   const RADII / SPACE / SHADOWS   radius, spacing rhythm, elevation
 *
 * Board-only values (a hex a mobile board draws that Foundation does not define)
 * live in packages/core/theme/board-only.json with the board that draws them, and
 * are never added here.
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const FOUNDATION = path.join(ROOT, '_design', 'wireframes', 'GeminiSecure Foundation.dc.html')
const OUT_DIR = path.join(ROOT, 'packages', 'core', 'theme')
const OUT = path.join(OUT_DIR, 'tokens.json')

const html = readFileSync(FOUNDATION, 'utf8')

/* ---- :root colours ---------------------------------------------------------- */
const rootBlock = /:root\s*\{([\s\S]*?)\}/.exec(html)?.[1]
if (!rootBlock) throw new Error('No :root block in the Foundation file.')

const colors = {}
for (const m of rootBlock.matchAll(/--([a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{3,8})\s*;/g)) {
    colors[m[1]] = m[2].toUpperCase()
}

/* ---- the data arrays in the x-dc script ------------------------------------- */
function arrayOf(name) {
    const block = new RegExp(`const ${name}\\s*=\\s*\\[([\\s\\S]*?)\\];`).exec(html)?.[1]
    if (!block) throw new Error(`No ${name} array in the Foundation file.`)
    const rows = []
    for (const obj of block.matchAll(/\{([^}]*)\}/g)) {
        const row = {}
        for (const kv of obj[1].matchAll(/([a-zA-Z]+)\s*:\s*'([^']*)'/g)) row[kv[1]] = kv[2]
        rows.push(row)
    }
    return rows
}

const semantic = {}
for (const row of arrayOf('SEMANTIC')) {
    if (!(row.name in colors)) semantic[row.name] = row.hex.toUpperCase()
}

const radii = Object.fromEntries(arrayOf('RADII').map((r) => [r.px.replace('px', ''), { px: Number(r.px.replace('px', '')), use: r.use }]))
const spacing = arrayOf('SPACE').map((s) => Number(s.n))
const shadows = Object.fromEntries(arrayOf('SHADOWS').map((s) => [s.name, s.css]))

const fontsHref = /fonts\.googleapis\.com\/css2\?([^"]+)"/.exec(html)?.[1] ?? ''
const families = [...fontsHref.matchAll(/family=([A-Za-z+]+):wght@([0-9;]+)/g)].map((m) => ({
    family: m[1].replace(/\+/g, ' '),
    weights: m[2].split(';').map(Number),
}))

/* ---- accessibility overrides ---------------------------------------
 * Three Foundation swatches fail WCAG AA as body text. The accessible value ships; the design's
 * value is kept beside it, and a changed Foundation value stops the build so nobody overrides blind. */
const OVERRIDES = JSON.parse(readFileSync(path.join(ROOT, 'tools', 'tokens', 'accessibility-overrides.json'), 'utf8')).colors
const accessibility = {}
for (const [name, o] of Object.entries(OVERRIDES)) {
    if (colors[name] !== o.from.toUpperCase()) {
        console.error(`Accessibility override for ${name} expects the Foundation to say ${o.from}, but it says ${colors[name]}. Re-measure and update tools/tokens/accessibility-overrides.json.`)
        process.exit(1)
    }
    accessibility[name] = { design: colors[name], shipped: o.to.toUpperCase() }
    colors[name] = o.to.toUpperCase()
}

const payload = {
    source: '_design/wireframes/GeminiSecure Foundation.dc.html',
    generated_by: 'node tools/tokens/extract.mjs',
    accessibility,
    colors,
    semantic,
    radii,
    spacing,
    shadows,
    fonts: {
        families,
        // The build brief adds IBM Plex Mono 400/500 for data; Foundation does not load it.
        mono: { family: 'IBM Plex Mono', weights: [400, 500], source: 'build brief (M1), not Foundation' },
    },
}

const json = JSON.stringify(payload, null, 2) + '\n'

if (Object.keys(colors).length !== 33) {
    console.error(`Expected 33 colour tokens in :root, found ${Object.keys(colors).length}.`)
    process.exit(1)
}

if (process.argv.includes('--check')) {
    if (!existsSync(OUT) || readFileSync(OUT, 'utf8') !== json) {
        console.error('tokens.json does not match the Foundation file. Run `node tools/tokens/extract.mjs`.')
        process.exit(1)
    }
    console.log(`OK - tokens.json matches Foundation (${Object.keys(colors).length} colours, ${Object.keys(radii).length} radii, ${spacing.length} spacing steps, ${Object.keys(shadows).length} shadows)`)
    process.exit(0)
}

mkdirSync(OUT_DIR, { recursive: true })
writeFileSync(OUT, json, 'utf8')
console.log(`Wrote ${path.relative(ROOT, OUT)} — ${Object.keys(colors).length} colours + ${Object.keys(semantic).length} semantic, ${Object.keys(radii).length} radii, ${spacing.length} spacing steps, ${Object.keys(shadows).length} shadows, ${families.length} families`)

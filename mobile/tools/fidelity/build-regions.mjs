/**
 * Measures the 80 mobile screen regions and writes:
 *
 *   _design/SCREEN_REGIONS.json          one entry per screen — board file, index, x/y/w/h
 *   _design/screenshots/expected/<id>.png the board cropped to that screen (397 x 856)
 *   _design/screens/<id>.html             the screen's own markup + the board's stylesheet,
 *                                         standalone, as a reading aid for the builder
 *
 * Every board is one HTML document holding four phone screens side by side, each
 * inside a .device bezel. The app renders one screen with no bezel, so the harness
 * needs to know exactly which rectangle of which board is which screen. Measured
 * with a browser, never hand-written; regenerate after any board change.
 *
 *   mobile boards  .screen  397 x 856  — inside .device (433 x 892 with bezel)
 *
 * The bezel, the Dynamic Island and the home indicator are frame, not screen. The
 * island and home indicator are drawn INSIDE .screen by the boards; compare.mjs
 * deals with that (see its header) — this script only records geometry.
 *
 * Screen numbers are the board's own: each screen is introduced by a .chip reading
 * "4  ·  Scan Visitor QR", numbered continuously across a surface's ten files.
 */

import { chromium } from 'playwright'
import { pathToFileURL } from 'node:url'
import { writeFileSync, readdirSync, mkdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..', '..')
const WIREFRAMES = path.join(ROOT, '_design', 'wireframes')
const EXPECTED = path.join(ROOT, '_design', 'screenshots', 'expected')
const SCREENS = path.join(ROOT, '_design', 'screens')

const SURFACES = [
    { dir: 'GeminiSecure Guard App Screens', slug: 'guard-app' },
    { dir: 'GeminiSecure Resident App Screens', slug: 'resident-app' },
]

const NOMINAL = { width: 397, height: 856 }

mkdirSync(EXPECTED, { recursive: true })
mkdirSync(SCREENS, { recursive: true })

const browser = await chromium.launch()
const screens = []
const boards = []

for (const surface of SURFACES) {
    const files = readdirSync(path.join(WIREFRAMES, surface.dir))
        .filter((f) => f.endsWith('.html'))
        .sort()

    for (const file of files) {
        const abs = path.join(WIREFRAMES, surface.dir, file)
        const html = readFileSync(abs, 'utf8')
        const styleBlock = /<style>([\s\S]*?)<\/style>/.exec(html)?.[1] ?? ''
        const fontLink = /<link href="(https:\/\/fonts\.googleapis\.com[^"]+)"/.exec(html)?.[1] ?? ''

        const page = await browser.newPage({ viewport: { width: 1920, height: 1200 }, deviceScaleFactor: 1 })
        await page.goto(pathToFileURL(abs).href, { waitUntil: 'load' })
        await page.evaluate(() => document.fonts.ready)

        const found = await page.evaluate(() => {
            const all = [...document.querySelectorAll('.chip, .screen')]
            const out = []
            let label = null
            for (const el of all) {
                if (el.matches('.chip')) {
                    label = el.textContent.trim().replace(/\s+/g, ' ')
                    continue
                }
                const r = el.getBoundingClientRect()
                out.push({
                    label,
                    x: Math.round(r.x + window.scrollX),
                    y: Math.round(r.y + window.scrollY),
                    width: Math.round(r.width),
                    height: Math.round(r.height),
                    outerHTML: el.outerHTML,
                    background: getComputedStyle(el).backgroundColor,
                })
            }
            return out
        })

        for (const [index, hit] of found.entries()) {
            const m = /^(\d+)\s*·\s*(.+)$/.exec(hit.label ?? '')
            const number = m ? Number(m[1]) : null
            const title = m ? m[2] : (hit.label ?? `screen ${index + 1}`)
            const id = `${surface.slug}-${String(number ?? index + 1).padStart(2, '0')}`

            screens.push({
                id,
                surface: surface.slug,
                kind: 'mobile',
                number,
                title,
                source: `${surface.dir}/${file}`,
                selector: '.screen',
                index,
                region: { x: hit.x, y: hit.y, width: hit.width, height: hit.height },
                viewport: { width: hit.width, height: hit.height },
                expected: `_design/screenshots/expected/${id}.png`,
                standalone: `_design/screens/${id}.html`,
                route: null,
            })

            // The board cropped to this one screen — what the app must match.
            const buf = await page.locator('.screen').nth(index).screenshot()
            writeFileSync(path.join(EXPECTED, `${id}.png`), buf)

            // The screen's own markup with the board's stylesheet, so a builder can
            // open one screen without the other three and the board chrome.
            const standalone = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${id} · ${title}</title>
${fontLink ? `<link rel="preconnect" href="https://fonts.googleapis.com">\n<link href="${fontLink}" rel="stylesheet">` : ''}
<style>${styleBlock}</style>
<style>body{padding:0;margin:0;background:#E7ECF3;display:flex;justify-content:center;padding:24px}</style>
</head>
<body>
<!-- ${surface.dir}/${file} · screen ${number} · ${title} -->
${hit.outerHTML}
</body>
</html>
`
            writeFileSync(path.join(SCREENS, `${id}.html`), standalone, 'utf8')
        }

        boards.push({ file: `${surface.dir}/${file}`, screens: found.length })
        await page.close()
    }
}

await browser.close()

const wrongSize = screens.filter((s) => s.region.width !== NOMINAL.width || s.region.height !== NOMINAL.height)
const unlabelled = screens.filter((s) => s.number === null)

const payload = {
    generated_by: 'node tools/fidelity/build-regions.mjs',
    note: 'Measured from the wireframe boards, never hand-written. Regenerate after any board changes.',
    viewport: '397x856 (.screen, inside the 433x892 .device bezel)',
    total: screens.length,
    nominal: NOMINAL,
    boards,
    screens,
}

writeFileSync(path.join(ROOT, '_design', 'SCREEN_REGIONS.json'), JSON.stringify(payload, null, 2) + '\n', 'utf8')

for (const surface of SURFACES) {
    const n = screens.filter((s) => s.surface === surface.slug).length
    console.log(`${surface.slug.padEnd(14)} ${String(n).padStart(3)} screens`)
}
console.log('-'.repeat(32))
console.log(`${'TOTAL'.padEnd(14)} ${String(screens.length).padStart(3)} screens`)

if (wrongSize.length > 0) {
    console.error(`\n${wrongSize.length} region(s) are not ${NOMINAL.width}x${NOMINAL.height}:`)
    for (const s of wrongSize) console.error(`  ${s.id}  ${s.region.width}x${s.region.height}  ${s.source}`)
    process.exit(1)
}
if (unlabelled.length > 0) {
    console.error(`\n${unlabelled.length} screen(s) have no numbered chip label:`)
    for (const s of unlabelled) console.error(`  ${s.id}  "${s.title}"  ${s.source}`)
    process.exit(1)
}
if (screens.length !== 80) {
    console.error(`\nExpected 80 mobile screens, measured ${screens.length}.`)
    process.exit(1)
}

console.log('\nEvery region is 397x856 and carries a numbered label. Expected PNGs and standalone screens written.')

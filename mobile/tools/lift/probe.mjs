/**
 * Debug aid: prints the rendered box of every element matching a selector, on the
 * BOARD and on the running LIFTED screen, side by side — so a fidelity miss can be
 * traced to the element that grew, instead of guessed at from a diff image.
 *
 *   node tools/lift/probe.mjs guard-app-28 ".note-input,.expire-warn,.result-sheet2"
 *
 * On the app side there are no class names, so it prints every box whose height is
 * within 40px of a board match, in document order, for eyeballing.
 */
import { chromium } from 'playwright'
import { readFileSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const [id, selectors] = process.argv.slice(2)
const regions = JSON.parse(readFileSync(path.join(ROOT, '_design', 'SCREEN_REGIONS.json'), 'utf8'))
const routes = JSON.parse(readFileSync(path.join(ROOT, '_design', 'SCREEN_ROUTES.json'), 'utf8'))
const screen = regions.screens.find((s) => s.id === id)

const browser = await chromium.launch()
const board = await browser.newPage({ viewport: { width: 1920, height: 1200 } })
await board.goto(pathToFileURL(path.join(ROOT, '_design', 'wireframes', screen.source)).href)
await board.evaluate(() => document.fonts.ready)
const boardBoxes = await board.evaluate(([i, sel]) => {
  const root = document.querySelectorAll('.screen')[i]; const r0 = root.getBoundingClientRect()
  return [...root.querySelectorAll(sel)].map((el) => { const r = el.getBoundingClientRect(); return { sel: el.className || el.tagName, y: +(r.top - r0.top).toFixed(2), h: +r.height.toFixed(2), w: +r.width.toFixed(2), text: el.textContent.trim().slice(0, 40) } })
}, [screen.index, selectors])

const app = await browser.newPage({ viewport: { width: 397, height: 856 } })
await app.goto(`${routes.apps[screen.surface]}/lifted/${id}?fidelity=${id}`, { waitUntil: 'networkidle' })
await app.waitForSelector('html[data-fidelity-ready]')
const appBoxes = await app.evaluate(() => [...document.querySelectorAll('#root div')].map((el) => { const r = el.getBoundingClientRect(); return { y: +r.top.toFixed(2), h: +r.height.toFixed(2), w: +r.width.toFixed(2), text: el.textContent.trim().slice(0, 40), kids: el.children.length } }))
await browser.close()

for (const b of boardBoxes) {
  const match = appBoxes.filter((a) => a.text === b.text && Math.abs(a.w - b.w) < 3).sort((p, q) => Math.abs(p.h - b.h) - Math.abs(q.h - b.h))[0]
  console.log(`${String(b.sel).padEnd(22)} board y=${String(b.y).padEnd(8)} h=${String(b.h).padEnd(7)} | app ${match ? `y=${String(match.y).padEnd(8)} h=${String(match.h).padEnd(7)} Δh=${(match.h - b.h).toFixed(2)}` : 'no match'}  "${b.text}"`)
}

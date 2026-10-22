/**
 * Debug aid: when a page never finishes loading because the main thread is blocked, this pauses the
 * JavaScript debugger a few seconds in and prints the call stack — i.e. WHERE it is stuck.
 *   node tools/fidelity/where-stuck.mjs guard-app "/clock-in?fidelity=guard-app-02"
 */
import { chromium } from 'playwright'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const routes = JSON.parse(readFileSync(path.join(ROOT, '_design', 'SCREEN_ROUTES.json'), 'utf8'))
const [surface, route] = process.argv.slice(2)

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 397, height: 856 } })
const cdp = await page.context().newCDPSession(page)
await cdp.send('Debugger.enable')
let printed = false
cdp.on('Debugger.paused', async (ev) => {
  if (printed) return
  printed = true
  console.log('STUCK AT:')
  for (const f of ev.callFrames.slice(0, 14)) console.log(`  ${f.functionName || '(anonymous)'}  line ${f.location.lineNumber}  ${(f.url || '').split('?')[0].slice(-60)}`)
  // Read a little source around the top frame so the function can be recognised.
  try {
    const top = ev.callFrames[0]
    const src = await cdp.send('Debugger.getScriptSource', { scriptId: top.location.scriptId })
    const lines = src.scriptSource.split('\n')
    console.log('--- source near the top frame')
    for (let i = Math.max(0, top.location.lineNumber - 3); i < Math.min(lines.length, top.location.lineNumber + 4); i++) console.log(`  ${i}: ${lines[i].slice(0, 200)}`)
  } catch (e) { console.log('(source unavailable)') }
  await browser.close()
  process.exit(0)
})
page.goto(routes.apps[surface] + route, { waitUntil: 'commit', timeout: 30000 }).catch(() => undefined)
setTimeout(() => cdp.send('Debugger.pause').catch(() => undefined), 12000)
setTimeout(async () => { if (!printed) { console.log('not stuck (no pause hit)'); await browser.close(); process.exit(0) } }, 25000)

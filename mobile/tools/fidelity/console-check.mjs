/**
 * Debug aid: opens one route and prints page errors, console errors, and how far the load got.
 *   node tools/fidelity/console-check.mjs guard-app "/lifted/guard-app-02?fidelity=guard-app-02"
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
page.on('pageerror', (e) => console.log('PAGEERROR', e.message.split('\n').slice(0, 3).join(' | ')))
page.on('requestfailed', (r) => console.log('REQUEST FAILED', r.url().slice(0, 120), r.failure()?.errorText))
page.on('response', (r) => { if (r.status() >= 400) console.log('HTTP', r.status(), r.url().slice(0, 140)) })
page.on('console', async (m) => {
  if (m.type() !== 'error' && m.type() !== 'warning') return
  const args = await Promise.all(m.args().map((a) => a.jsonValue().catch(() => '?')))
  console.log(m.type().toUpperCase(), args.map((a) => String(a).slice(0, 300)).join(' | '))
})
const t0 = Date.now()
try {
  await page.goto(routes.apps[surface] + route, { waitUntil: 'commit', timeout: 30000 })
  console.log(`committed after ${Date.now() - t0}ms`)
  await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).then(() => console.log(`domcontentloaded after ${Date.now() - t0}ms`)).catch(() => console.log('domcontentloaded NEVER fired (main thread blocked?)'))
  await page.waitForTimeout(6000)
  console.log('ready flag:', await page.evaluate(() => document.documentElement.hasAttribute('data-fidelity-ready')).catch((e) => 'evaluate failed: ' + e.message.split('\n')[0]))
  console.log('body text:', (await page.evaluate(() => document.body.innerText).catch(() => '')).slice(0, 200).replace(/\n/g, ' / '))
} catch (e) {
  console.log('FAILED:', e.message.split('\n')[0])
}
await browser.close()

/**
 * Debug aid: lists requests that are still pending N seconds into a page load.
 *   node tools/fidelity/pending-requests.mjs guard-app "/clock-in?fidelity=guard-app-02"
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
const open = new Map()
const t0 = Date.now()
page.on('request', (r) => open.set(r, Date.now() - t0))
page.on('requestfinished', (r) => open.delete(r))
page.on('requestfailed', (r) => { console.log('FAILED', r.url().slice(0, 140), r.failure()?.errorText); open.delete(r) })
page.goto(routes.apps[surface] + route, { waitUntil: 'commit', timeout: 30000 }).catch((e) => console.log('goto:', e.message.split('\n')[0]))
await new Promise((r) => setTimeout(r, 15000))
console.log(`pending after 15s: ${open.size}`)
for (const [r, at] of open) console.log(`  +${at}ms ${r.resourceType()} ${r.method()} ${r.url().slice(0, 170)}`)
await browser.close()

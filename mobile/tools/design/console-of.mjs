// What a Resident screen logs on the fidelity web build — warnings and errors. A warning there also puts Expo's
// dark dev toast on the page, which the design checks then count as stray colours (#1B1D1E, #4D5155, #ECEDEE).
//
//   node tools/design/console-of.mjs resident-app-17
//
// Needs the same server app-walk.mjs needs (Expo web on :8082, EXPO_PUBLIC_FIDELITY=1).

import { chromium } from 'playwright'
import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const id = process.argv[2]
const routes = JSON.parse(readFileSync(join(root, '_design', 'SCREEN_ROUTES.json'), 'utf8'))
const entry = routes.routes[id]
if (!entry) throw new Error(`no route for ${id} in _design/SCREEN_ROUTES.json`)
const url = `${routes.apps['resident-app']}${entry.route}${entry.route.includes('?') ? '&' : '?'}fidelity=${id}`

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 390, height: 844 } })
let said = 0
page.on('console', (m) => {
  if (m.type() !== 'warning' && m.type() !== 'error') return
  said++
  console.log(`[${m.type()}] ${m.text().slice(0, 800)}`)
})
page.on('pageerror', (e) => { said++; console.log(`[pageerror] ${e.message.slice(0, 800)}`) })
console.log(`open ${url}`)
await page.goto(url, { waitUntil: 'networkidle' }).catch((e) => console.log(`goto: ${e.message}`))
await page.waitForTimeout(4000)
await browser.close()
console.log(said === 0 ? 'nothing logged' : `${said} logged`)

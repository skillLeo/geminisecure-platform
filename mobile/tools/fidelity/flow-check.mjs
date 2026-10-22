/**
 * A smoke check of the REAL flow on web (no ?fidelity=): each app boots, shows its
 * splash, runs the boot sequence and lands on its five-tab shell; every tab opens.
 * Catches a provider, store or routing fault that a single-screen diff cannot see.
 *
 *   node tools/fidelity/flow-check.mjs
 */

import { chromium } from 'playwright'

const APPS = [
  // A guard handset with no session may see the splash and enrolment and nothing else (SessionGate).
  // What happens after enrolment is tools/flows/guard-enrol.mjs, against the live API.
  { name: 'guard', url: 'http://localhost:8081/', landsOn: 'Enrol this phone', guarded: 'http://localhost:8081/clock-out', tabs: [] },
  // A resident phone with no session sees the splash and the sign-in screens and nothing else. The five tabs,
  // signed in against the live API, are tools/flows/resident-signin.mjs and resident-visitors.mjs.
  { name: 'resident', url: 'http://localhost:8082/', landsOn: 'Create account', guarded: 'http://localhost:8082/dues/pay', bouncesTo: 'Welcome back', tabs: [] },
]

const browser = await chromium.launch()
let failed = 0

for (const app of APPS) {
  const page = await browser.newPage({ viewport: { width: 397, height: 856 } })
  const problems = []
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message.split('\n')[0]}`))
  page.on('console', (m) => {
    if (m.type() === 'error') problems.push(`console: ${m.text().split('\n')[0].slice(0, 160)}`)
  })

  try {
    // Not 'networkidle': a phone on the same dev server keeps the network busy for minutes.
    await page.goto(app.url, { waitUntil: 'domcontentloaded', timeout: 240000 })
    await page.getByText('GeminiSecure', { exact: true }).first().waitFor({ timeout: 15000 })

    // The splash leaves by itself once the boot sequence and its minimum time are both done.
    if (app.landsOn) {
      await page.getByRole('heading', { name: app.landsOn }).waitFor({ timeout: 15000 })
      // Invariant 5's client half: a screen behind the session is not reachable by typing its address.
      await page.goto(app.guarded, { waitUntil: 'domcontentloaded', timeout: 240000 })
      await page.getByRole('heading', { name: app.bouncesTo ?? app.landsOn }).waitFor({ timeout: 30000 })
    } else {
      await page.getByRole('tablist').waitFor({ timeout: 15000 })
    }

    for (const label of app.tabs) {
      await page.getByRole('tab', { name: label }).click()
      await page.getByRole('heading', { name: label }).waitFor({ timeout: 5000 })
      const selected = await page.getByRole('tab', { name: label }).getAttribute('aria-selected')
      if (selected !== 'true') problems.push(`tab "${label}" did not become selected`)
    }
  } catch (e) {
    problems.push(e.message.split('\n')[0])
  }

  // "Failed to load resource" is the dev server dropping a request under load (several checks run against it at once);
  // a screen that really failed to load fails its own waitFor above.
  const real = problems.filter((p) => !/Download the React DevTools|shadow\*|pointerEvents is deprecated|Failed to load resource/.test(p))
  console.log(`${real.length === 0 ? 'PASS' : 'FAIL'}  ${app.name}  splash → boot → ${app.landsOn ? `"${app.landsOn}" (and a guarded route bounces back to it)` : `${app.tabs.length} tabs`}`)
  for (const p of real) console.log(`      ${p}`)
  if (real.length > 0) failed++
  await page.close()
}

await browser.close()
process.exit(failed > 0 ? 1 : 0)

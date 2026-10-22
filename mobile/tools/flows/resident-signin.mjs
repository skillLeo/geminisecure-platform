/**
 * LIVE flow check — resident sign-in, end to end against the real API (no ?fidelity=, no fixtures):
 *
 *   Log In → a wrong community code is refused → the right one sends a code → a WRONG code is
 *   refused with the server's own sentence → the right code signs in → Home (tabs)
 *
 * Signs in as the durable test resident: the boards' Andrea Fletcher. The
 * six digits come from `php artisan mobile:signin-code resident --plain`, which issues the code
 * through the platform's own sign-in service and prints it instead of emailing it.
 *
 *   node tools/flows/resident-signin.mjs        (resident dev server on :8082, API on :8000)
 */

import { execFileSync } from 'node:child_process'
import { chromium } from 'playwright'

const LARAVEL = process.env.GS_SERVER_CHECKOUT ?? '../server'
const APP = 'http://localhost:8082'
const EMAIL = 'andrea.fletcher@email.com'
const ESTATE = 'phoenixpark'
const artisan = (...args) => execFileSync('php', ['artisan', ...args], { cwd: LARAVEL, encoding: 'utf8' })

artisan('mobile:test-accounts')

const browser = await chromium.launch()
const context = await browser.newContext({ viewport: { width: 397, height: 856 } })
const page = await context.newPage()

const steps = []
const problems = []
const wire = []
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message.split('\n')[0]}`))
page.on('console', (m) => {
  if (m.type() === 'error' && !/Download the React DevTools|shadow\*|pointerEvents is deprecated|Failed to load resource|WebSocket/.test(m.text())) problems.push(`console: ${m.text().split('\n')[0].slice(0, 200)}`)
})
page.on('request', (r) => {
  if (/token=|code=\d{6}/.test(r.url())) problems.push(`a secret travelled in a URL: ${r.url().slice(0, 80)}`)
  if (r.url().includes('/api/v1/')) wire.push(`→ ${r.method()} ${new URL(r.url()).pathname}`)
})
page.on('response', (r) => { if (r.url().includes('/api/v1/')) wire.push(`← ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`) })

const step = async (name, fn) => {
  try {
    await fn()
    steps.push(`ok    ${name}`)
  } catch (e) {
    steps.push(`FAIL  ${name} — ${e.message.split('\n')[0]}`)
    const alerts = await page.getByRole('alert').allInnerTexts().catch(() => [])
    for (const text of alerts) steps.push(`      on screen: ${text.replace(/\s+/g, ' ').slice(0, 200)}`)
    for (const line of wire.slice(-10)) steps.push(`      wire: ${line}`)
    throw e
  }
}

try {
  await step('a signed-out phone that opens a screen behind the session is sent to Log In', async () => {
    await page.goto(`${APP}/(tabs)/dues`, { waitUntil: 'domcontentloaded', timeout: 240000 })
    await page.getByRole('heading', { name: 'Welcome back' }).waitFor({ timeout: 60000 })
  })

  await step('the estate is a PICK-LIST, and with one active estate it is chosen already — nothing to type', async () => {
    await page.locator('role=button[name=/^Your estate: Phoenix Park/] >> visible=true').first().waitFor({ timeout: 20000 })
    if (await page.locator('role=textbox[name="Community code"] >> visible=true').count()) throw new Error('the typed community code is showing although the list arrived')
  })

  await step('an empty form is refused in plain words, on the field that is empty', async () => {
    await page.getByRole('button', { name: 'Log in', exact: true }).click()
    await page.getByText('Enter a valid email address', { exact: false }).waitFor({ timeout: 5000 })
  })

  await step('a phone number is told, honestly, that codes go by email here', async () => {
    await page.getByLabel('Email address').fill('876 555 0212')
    await page.getByRole('button', { name: 'Log in', exact: true }).click()
    await page.getByText('sent by email on this platform', { exact: false }).waitFor({ timeout: 5000 })
  })

  await step('the fallback is kept: "my estate isn\'t listed" → a typed code nobody uses is refused on that field', async () => {
    await page.getByLabel('Email address').fill(EMAIL)
    await page.locator('role=link[name=/My estate isn.t listed/] >> visible=true').first().click()
    await page.getByLabel('Community code').fill('nosuchestate')
    await page.getByRole('button', { name: 'Log in', exact: true }).click()
    await page.getByText('No community uses that code', { exact: false }).waitFor({ timeout: 20000 })
  })

  await step('back to the list, and the right details send a code and open the code screen (masked address shown)', async () => {
    await page.locator('role=link[name="Choose your estate from the list instead"] >> visible=true').first().click()
    await page.locator('role=button[name=/^Your estate: Phoenix Park/] >> visible=true').first().waitFor({ timeout: 10000 })
    await page.getByRole('button', { name: 'Log in', exact: true }).click()
    await page.getByRole('heading', { name: 'Check your email' }).waitFor({ timeout: 20000 })
  })

  await step('a wrong code is refused with the server’s own sentence (tries left), and the boxes clear', async () => {
    await page.getByLabel('Six-digit sign-in code').fill('000000')
    await page.getByRole('alert').filter({ hasText: /code|tries|try/i }).waitFor({ timeout: 20000 })
    if ((await page.getByLabel('Six-digit sign-in code').inputValue()) !== '') throw new Error('the wrong code was left in the boxes')
  })

  await step('the right code signs in, the token is in no web storage, and Home opens', async () => {
    const code = artisan('mobile:signin-code', 'resident', '--plain').trim().split(/\s+/).pop()
    if (!/^\d{6}$/.test(code ?? '')) throw new Error(`could not mint a code: ${code}`)
    await page.getByLabel('Six-digit sign-in code').fill(code)
    // The first time on this "phone", Home is reached by way of the permissions explanation —
    // and Continue ALWAYS continues, whatever the answers (a browser refuses or lacks all three).
    await page.getByRole('heading', { name: 'Before we get started' }).waitFor({ timeout: 30000 })
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.getByRole('tablist').waitFor({ timeout: 30000 })
    const dump = await page.evaluate(() => JSON.stringify({ ...window.localStorage }) + JSON.stringify({ ...window.sessionStorage }))
    if (/\d+\|[A-Za-z0-9]{30,}/.test(dump) || /"token"/i.test(dump)) throw new Error('something token-shaped is in localStorage/sessionStorage')
  })

  await step('Home is the real household: greeted by name, the estate and unit shown, the panic button present', async () => {
    await page.getByRole('heading', { name: /Good (morning|afternoon|evening), Andrea/ }).waitFor({ timeout: 20000 })
    // VISIBLE text only: the navigator keeps earlier screens mounted (hidden) underneath, and one of them names the estate too.
    await page.locator('text=/Phoenix Park Village 1/ >> visible=true').first().waitFor({ timeout: 20000 })
    await page.getByRole('button', { name: 'Panic alert' }).waitFor({ timeout: 5000 })
  })
} catch {
  /* the failing step is already recorded */
}

for (const line of steps) console.log(line)
for (const p of problems) console.log(`      ${p}`)
const failed = steps.some((l) => l.startsWith('FAIL')) || problems.length > 0
console.log(`${failed ? 'FAIL' : 'PASS'}  resident sign-in flow (${EMAIL})`)
await browser.close()
process.exit(failed ? 1 : 0)

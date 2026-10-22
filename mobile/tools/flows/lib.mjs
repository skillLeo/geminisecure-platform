/**
 * Shared pieces for the LIVE flow checks (real API, no fixtures): the artisan bridge to the
 * durable test accounts, a step recorder that shows what the person would be
 * looking at when a step fails, and a signed-in resident.
 */

import { execFileSync } from 'node:child_process'
import { chromium } from 'playwright'

export const LARAVEL = process.env.GS_SERVER_CHECKOUT ?? '../server'
export const RESIDENT_APP = 'http://localhost:8082'
export const GUARD_APP = 'http://localhost:8081'
export const RESIDENT_EMAIL = 'andrea.fletcher@email.com'
export const ESTATE = 'phoenixpark'

export const artisan = (...args) => execFileSync('php', ['artisan', ...args], { cwd: LARAVEL, encoding: 'utf8' })

export async function openPhone(options = {}) {
  const browser = await chromium.launch()
  const context = await browser.newContext({ viewport: { width: 397, height: 856 }, ...options })
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

  const finish = async (title) => {
    for (const line of steps) console.log(line)
    for (const p of problems) console.log(`      ${p}`)
    const failed = steps.some((l) => l.startsWith('FAIL')) || problems.length > 0
    console.log(`${failed ? 'FAIL' : 'PASS'}  ${title}`)
    await browser.close()
    process.exit(failed ? 1 : 0)
  }

  return { browser, context, page, step, finish, wire, problems }
}

/**
 * Airplane mode, as the APP sees it. `context.setOffline()` alone cuts the wire but does not tell the app: on
 * Chromium, NetInfo's web build listens only to `navigator.connection`'s "change" event, which Playwright never
 * fires — so `network.online` stayed true and no offline wording was drawn (every reviewer of the resident long
 * tail hit this, 2026-09-20). This cuts the wire AND fires the events a browser fires. Phones use the native module.
 */
export async function setConnection(page, online) {
  await page.context().setOffline(!online)
  await page.evaluate((isOnline) => {
    window.dispatchEvent(new Event(isOnline ? 'online' : 'offline'))
    const connection = navigator.connection
    if (connection && typeof connection.dispatchEvent === 'function') connection.dispatchEvent(new Event('change'))
  }, online)
  await page.waitForTimeout(600)
}

/**
 * "Your estate": a pick-list of the active estates, the typed community code as the fallback.
 * With one active estate it is chosen already; with several, the test estate is picked from the list; with no
 * list at all (the server could not give one) the typed field is what the screen shows, and it is filled.
 */
export async function chooseEstate(page) {
  const picker = page.locator('role=button[name=/^(Your estate: |Choose your estate)/] >> visible=true').first()
  const typed = page.locator('role=textbox[name="Community code"] >> visible=true').first()
  await Promise.race([picker.waitFor({ timeout: 20000 }), typed.waitFor({ timeout: 20000 })]).catch(() => undefined)
  if (await typed.isVisible().catch(() => false)) return void (await typed.fill(ESTATE))
  if (/^Your estate: /.test((await picker.getAttribute('aria-label')) ?? '')) return
  await picker.click()
  await page.locator('role=radio[name=/Phoenix Park/] >> visible=true').first().click()
}

/** Signs the durable test resident in through the real screens, and lands on Home. */
export async function signInResident(page) {
  artisan('mobile:test-accounts')
  await page.goto(`${RESIDENT_APP}/(auth)/log-in`, { waitUntil: 'domcontentloaded', timeout: 240000 })
  await page.getByRole('heading', { name: 'Welcome back' }).waitFor({ timeout: 60000 })
  await page.getByLabel('Email address').fill(RESIDENT_EMAIL)
  await chooseEstate(page)
  await page.getByRole('button', { name: 'Log in', exact: true }).click()
  await page.getByRole('heading', { name: 'Check your email' }).waitFor({ timeout: 20000 })
  const code = artisan('mobile:signin-code', 'resident', '--plain').trim().split(/\s+/).pop()
  await page.getByLabel('Six-digit sign-in code').fill(code)
  await page.getByRole('heading', { name: 'Before we get started' }).waitFor({ timeout: 30000 })
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByRole('tablist').waitFor({ timeout: 30000 })
}

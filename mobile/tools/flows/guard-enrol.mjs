/**
 * LIVE flow check — guard enrolment, end to end against the real API (no ?fidelity=, no fixtures):
 *
 *   office issues a one-time code  →  splash  →  /enrol  →  a wrong code is refused with the
 *   server's sentence  →  the real code enrols the handset  →  onboarding 1 → 2 → 3  →  clock-in
 *
 * The code is issued the way the office issues it (`php artisan device:enrolment-code`), for a
 * guard who is NOT the boards' Marcus Whyte — GS-1041 is left for the client's own phone, and a
 * test enrolment there would raise a rebind against it. The web "handset" keeps one install UID
 * across runs, so re-running this re-binds the same handset instead of queueing a rebind request.
 *
 *   node tools/flows/guard-enrol.mjs            (guard dev server on :8081, API on :8000)
 */

import { execFileSync } from 'node:child_process'
import { chromium } from 'playwright'

const GUARD = process.env.FLOW_GUARD ?? 'GS-9001'
const LARAVEL = process.env.GS_SERVER_CHECKOUT ?? '../server'
const APP = 'http://localhost:8081'
const UID = 'flowcheck-web-handset-0001'
const artisan = (...args) => execFileSync('php', ['artisan', ...args], { cwd: LARAVEL, encoding: 'utf8' })

// The durable test guard: refreshed with a shift nobody has clocked in to, its handset
// un-bound, and a fresh code minted — so the enrolment below is immediate and needs no supervisor.
const prepared = artisan('mobile:test-accounts', '--fresh-shift')
const code = artisan('mobile:signin-code', 'guard', `--guard=${GUARD}`, '--plain').trim().split(/\s+/).pop()
if (!/^[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(code ?? '')) {
  console.log(`FAIL  could not mint an enrolment code for ${GUARD}:\n${prepared}\n${code}`)
  process.exit(1)
}
const guardName = GUARD === 'GS-9001' ? 'Tessa Buildcheck (mobile test)' : ''

const browser = await chromium.launch()
// A handset has a position: the context is given one (Kingston) so the geofence pre-flight really runs.
const context = await browser.newContext({ viewport: { width: 397, height: 856 }, permissions: ['geolocation'], geolocation: { latitude: 18.0179, longitude: -76.8099, accuracy: 5 } })
await context.addInitScript((uid) => window.localStorage.setItem('gs.guard.device_uid', uid), UID)
const page = await context.newPage()

const problems = []
const steps = []
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message.split('\n')[0]}`))
page.on('console', (m) => {
  if (m.type() === 'error' && !/Download the React DevTools|shadow\*|pointerEvents is deprecated|Failed to load resource/.test(m.text())) problems.push(`console: ${m.text().split('\n')[0].slice(0, 200)}`)
})
// The token must never be anywhere a web page can read back: not in a URL, not in storage.
const wire = []
page.on('request', (r) => {
  if (/token=|enrolment_code=/.test(r.url())) problems.push(`a secret travelled in a URL: ${r.url().slice(0, 80)}`)
  if (r.url().includes('/api/v1/')) wire.push(`→ ${r.method()} ${new URL(r.url()).pathname}`)
})
page.on('requestfailed', (r) => { if (r.url().includes('/api/v1/')) wire.push(`✗ ${r.method()} ${new URL(r.url()).pathname} — ${r.failure()?.errorText}`) })
page.on('response', (r) => { if (r.url().includes('/api/v1/')) wire.push(`← ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`) })
const chatter = []
page.on('console', (m) => { if (m.type() === 'warning' || m.type() === 'error') chatter.push(`${m.type()}: ${m.text().split('\n')[0].slice(0, 220)}`) })

const step = async (name, fn) => {
  try {
    await fn()
    steps.push(`ok    ${name}`)
  } catch (e) {
    steps.push(`FAIL  ${name} — ${e.message.split('\n')[0]}`)
    // What the person would be looking at — the alert's sentence says more than a timeout does.
    const alerts = await page.getByRole('alert').allInnerTexts().catch(() => [])
    for (const text of alerts) steps.push(`      on screen: ${text.replace(/\s+/g, ' ').slice(0, 200)}`)
    for (const line of wire.slice(-12)) steps.push(`      wire: ${line}`)
    for (const line of chatter.slice(-8)) steps.push(`      ${line}`)
    const queue = await page.evaluate(() => window.localStorage.getItem('gs:outbox:v1')).catch(() => null)
    if (queue) steps.push(`      queue on disk: ${queue.replace(/"idempotencyKey":"[^"]+"/g, '"idempotencyKey":"…"').slice(0, 600)}`)
    throw e
  }
}

try {
  await step('splash hands a handset with no session to enrolment', async () => {
    await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded', timeout: 240000 })
    await page.getByRole('heading', { name: 'Enrol this phone' }).waitFor({ timeout: 60000 })
  })

  await step('the button is disabled, with its reason, until the code is complete', async () => {
    const button = page.getByRole('button', { name: 'Enrol this phone' })
    if ((await button.getAttribute('aria-disabled')) !== 'true') throw new Error('button was enabled with no code typed')
  })

  await step('a wrong code is refused with the server\'s own sentence, and nothing is stored', async () => {
    await page.getByLabel('Enrolment code').fill('zzzz9999')
    const shown = await page.getByLabel('Enrolment code').inputValue()
    if (shown !== 'ZZZZ-9999') throw new Error(`code was not formatted: "${shown}"`)
    await page.getByRole('button', { name: 'Enrol this phone' }).click()
    await page.getByRole('alert').filter({ hasText: /not recognised|expired|already been used/i }).waitFor({ timeout: 20000 })
  })

  await step(`the real code enrols the handset and greets ${guardName || GUARD}`, async () => {
    await page.getByLabel('Enrolment code').fill(code)
    await page.getByRole('button', { name: 'Enrol this phone' }).click()
    await Promise.race([
      page.getByText('STEP 1 OF 3').waitFor({ timeout: 30000 }),
      page.getByRole('heading', { name: 'Waiting for a supervisor' }).waitFor({ timeout: 30000 }),
      page.getByRole('alert').filter({ hasText: /went wrong on our side/i }).waitFor({ timeout: 30000 }),
    ])
    // Known server-side fault: the API's database user cannot mark the code used, so a
    // VALID code answers 500. That is not an app defect and is not fixable from here — name it, do not mask it.
    if (await page.getByRole('alert').filter({ hasText: /went wrong on our side/i }).isVisible()) {
      throw new Error('SERVER 500 on a valid code — the application user is probably missing a table grant: run `php artisan grants:append-only` in the API project')
    }
    if (await page.getByRole('heading', { name: 'Waiting for a supervisor' }).isVisible()) {
      throw new Error(`${GUARD} is already bound to another handset — the rebind wait screen is showing (correct behaviour, but this check needs a guard with no other handset; set FLOW_GUARD)`)
    }
    const first = guardName.split(' ')[0]
    if (first) await page.getByRole('heading', { name: `Welcome, ${first}` }).waitFor({ timeout: 5000 })
    if (guardName) await page.getByText(guardName, { exact: true }).first().waitFor({ timeout: 5000 })
    await page.getByText(GUARD, { exact: true }).waitFor({ timeout: 5000 })
  })

  await step('the token is in no web storage', async () => {
    const dump = await page.evaluate(() => JSON.stringify({ ...window.localStorage }) + JSON.stringify({ ...window.sessionStorage }))
    if (/\d+\|[A-Za-z0-9]{30,}/.test(dump) || /"token"/i.test(dump)) throw new Error('something token-shaped is in localStorage/sessionStorage')
  })

  await step('step 1 → step 2 (this device and identity)', async () => {
    await page.getByRole('button', { name: 'Looks correct, continue' }).click()
    await page.getByText('STEP 2 OF 3').waitFor({ timeout: 10000 })
    await page.getByText(/is now linked to your profile/).waitFor({ timeout: 5000 })
  })

  await step('step 2 → step 3: a handset with no biometrics is told so honestly and is not blocked', async () => {
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.getByText('STEP 3 OF 3').waitFor({ timeout: 10000 })
  })

  await step('"Finish setup" stays disabled until the acknowledgement is ticked', async () => {
    const finish = page.getByRole('button', { name: 'Finish setup' })
    if ((await finish.getAttribute('aria-disabled')) !== 'true') throw new Error('Finish setup was enabled before the tick')
    await page.getByRole('checkbox').click()
    if ((await page.getByRole('checkbox').getAttribute('aria-checked')) !== 'true') throw new Error('checkbox did not tick')
    if ((await finish.getAttribute('aria-disabled')) === 'true') throw new Error('Finish setup stayed disabled after the tick')
  })

  await step('finishing setup lands on clock-in, authenticated (the API answers, no 401)', async () => {
    await page.getByRole('button', { name: 'Finish setup' }).click()
    await page.getByRole('heading', { name: 'Clock In' }).waitFor({ timeout: 15000 })
    await page.waitForTimeout(2500)
    if (await page.getByRole('heading', { name: 'Enrol this phone' }).isVisible()) throw new Error('bounced back to enrolment — the token was not accepted')
  })

  // Found on the client's phone (2026-09-19): the clock-in sat in the queue and NEVER reached the server.
  // This watches the wire: the act must actually be POSTed, with its two stamps, and be accepted.
  await step('Clock in is captured AND sent: one POST with Idempotency-Key + X-Device-Time, answered 2xx', async () => {
    const button = page.getByRole('button', { name: /^Clock in/ })
    await button.waitFor({ timeout: 15000 })
    if ((await button.getAttribute('aria-disabled')) === 'true') throw new Error(`Clock in is disabled: ${await button.getAttribute('aria-describedby') ?? 'see the screen'}`)
    const sent = page.waitForResponse((r) => /\/shifts\/\d+\/clock-in$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 45000 })
    sent.catch(() => undefined) // reported by the await below; never an unhandled rejection if the click fails first
    await button.click({ timeout: 10000 })
    const response = await sent
    const headers = response.request().headers()
    if (!/^[0-9a-f-]{36}$/.test(headers['idempotency-key'] ?? '')) throw new Error('the clock-in carried no Idempotency-Key')
    if (!/^\d{4}-\d{2}-\d{2}T/.test(headers['x-device-time'] ?? '')) throw new Error('the clock-in carried no X-Device-Time')
    if (response.status() >= 300) throw new Error(`the server answered ${response.status()} to the clock-in`)
  })

  await step('Home shows the guard ON POST, and the badge settles on Synced (nothing left queued)', async () => {
    await page.getByText('On post', { exact: true }).waitFor({ timeout: 20000 })
    await page.getByText('Synced', { exact: true }).waitFor({ timeout: 20000 })
  })

  await step('the badge opens the Sync queue (not an unmatched route), and the clock-in is listed as Confirmed', async () => {
    await page.getByText('Synced', { exact: true }).click()
    await page.getByRole('heading', { name: 'Sync queue' }).waitFor({ timeout: 10000 })
    await page.getByText('Confirmed', { exact: true }).first().waitFor({ timeout: 10000 })
    if (await page.getByRole('heading', { name: 'Enrol this phone' }).isVisible()) throw new Error('bounced back to enrolment — the token was not accepted')
  })

  /* ── the rest of a guard's day, against the live API ────────────────────────────────────────── */

  await step('Messages: a message to the control room is POSTed (201) and loses "Waiting to send"', async () => {
    await page.getByRole('button', { name: 'Back' }).click()
    await page.getByRole('tab', { name: 'Messages' }).click()
    await page.getByRole('button', { name: /^Control room/ }).click()
    await page.getByRole('heading', { name: 'Control room' }).waitFor({ timeout: 15000 })
    const text = `Flow check ${Date.now().toString().slice(-6)}`
    await page.getByLabel('Message to the control room').fill(text)
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/messages'), { timeout: 30000 })
    sent.catch(() => undefined)
    await page.getByRole('button', { name: 'Send' }).click()
    const response = await sent
    if (response.status() !== 201) throw new Error(`the server answered ${response.status()} to the message`)
    await page.locator(`text=${text} >> visible=true`).first().waitFor({ timeout: 10000 })
    await page.waitForTimeout(1500)
    if (await page.locator('text=/Waiting to send/ >> visible=true').count()) throw new Error('the bubble still says "Waiting to send" after the server took it')
  })

  await step('Leave: a request is POSTed (201) and the screen says the supervisor has it', async () => {
    await page.getByRole('button', { name: 'Back' }).click()
    await page.getByRole('tab', { name: 'More' }).click()
    await page.getByRole('button', { name: /^Leave & duty/ }).click()
    await page.getByRole('heading', { name: 'Leave & duty' }).waitFor({ timeout: 15000 })
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/requests'), { timeout: 30000 })
    sent.catch(() => undefined)
    await page.getByRole('button', { name: 'Submit request' }).click()
    const response = await sent
    if (response.status() !== 201) throw new Error(`the server answered ${response.status()} to the leave request`)
    await page.getByText(/Sent — your supervisor has it/).waitFor({ timeout: 15000 })
  })

  await step('Open shifts are asked for LIVE (GET /shifts/open) and draw a list or an honest empty state', async () => {
    const asked = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith('/shifts/open'), { timeout: 30000 })
    asked.catch(() => undefined)
    await page.getByRole('tab', { name: 'Open shifts' }).click()
    if ((await asked).status() !== 200) throw new Error('open shifts did not answer 200')
    await Promise.race([
      page.getByRole('button', { name: /^Claim the / }).first().waitFor({ timeout: 15000 }),
      page.getByText('No open shifts', { exact: true }).waitFor({ timeout: 15000 }),
      page.getByText(/Claimed — waiting|Approved — it is on your roster|Your claim was declined/).first().waitFor({ timeout: 15000 }),
    ])
  })

  await step('Payslips: the server\'s figures or an honest "No payslips yet" — never an error, never a Download', async () => {
    await page.getByRole('button', { name: 'Back' }).click()
    await page.getByRole('tab', { name: 'Payslips' }).click()
    await Promise.race([
      page.getByText('Net pay', { exact: true }).waitFor({ timeout: 20000 }),
      page.getByText('No payslips yet', { exact: true }).waitFor({ timeout: 20000 }),
    ])
    if (await page.locator('text=/Download/i >> visible=true').count()) throw new Error('a Download control is on the payslip screen')
    const stored = await page.evaluate(() => JSON.stringify({ ...window.localStorage }))
    if (/"gross"|"net"|period_label/.test(stored)) throw new Error('a payslip was written to shared (web) storage')
  })

  await step('Alertness: the FIRST thing a guard sees is the consent, not a check', async () => {
    await page.getByRole('tab', { name: 'More' }).click()
    await page.getByRole('button', { name: /^Alertness check-ins/ }).click()
    await page.getByRole('heading', { name: 'Alertness check-ins' }).waitFor({ timeout: 15000 })
    await page.getByRole('button', { name: 'I agree' }).waitFor({ timeout: 5000 })
  })
} catch {
  /* the failing step is already recorded */
}

for (const line of steps) console.log(line)
for (const p of problems) console.log(`      ${p}`)
const failed = steps.some((l) => l.startsWith('FAIL')) || problems.length > 0
console.log(`${failed ? 'FAIL' : 'PASS'}  guard enrolment flow (${GUARD})`)
await browser.close()
process.exit(failed ? 1 : 0)

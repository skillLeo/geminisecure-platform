/**
 * LIVE flow check — the resident's visitor journey, end to end against the real API.
 *
 *   Visitors tab → Invite → an empty form is refused → a real invitation is SIGNED BY THE SERVER →
 *   the pass screen draws its QR on the phone and shows the short code → cancel it → it reads
 *   Cancelled and its code is no longer shown
 *
 *   Since the Visitors tab was rebuilt from board 08 (M42 §5 #7) it has no "Expected" tab and no
 *   "My e-Pass" row, so the flow no longer looks for either.
 *
 *   node tools/flows/resident-visitors.mjs        (resident dev server on :8082, API on :8000)
 */

import { openPhone, signInResident } from './lib.mjs'

const phone = await openPhone()
const { page, step, finish } = phone
const visitor = `Flow Check ${Date.now().toString().slice(-5)}`

try {
  await step('sign in as the test resident and open the Visitors tab', async () => {
    await signInResident(page)
    await page.getByRole('tab', { name: 'Visitors' }).click()
    await page.getByRole('tab', { name: 'On site now' }).waitFor({ timeout: 15000 })
  })

  await step('Invite: an empty form is refused in plain words, nothing is sent', async () => {
    await page.getByRole('button', { name: 'Invite visitor' }).first().click()
    await page.getByRole('heading', { name: 'Invite visitor' }).waitFor({ timeout: 15000 })
    let posted = false
    const watch = (r) => { if (r.method() === 'POST' && new URL(r.url()).pathname.endsWith('/visitor-passes')) posted = true }
    page.on('request', watch)
    await page.getByRole('button', { name: 'Generate QR pass' }).click()
    await page.getByText("Enter the visitor's name", { exact: false }).waitFor({ timeout: 5000 })
    page.off('request', watch)
    if (posted) throw new Error('an invalid invitation was sent to the server')
  })

  let code = null
  await step('a real invitation is signed by the server: the pass screen shows a QR drawn on the phone and the short code', async () => {
    await page.getByLabel('Visitor name').fill(visitor)
    await page.getByLabel('Purpose of visit').fill('Flow check')
    // All-day tomorrow keeps the window valid whatever time the check runs.
    await page.getByRole('radio', { name: 'Contractor' }).click()
    const issued = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/visitor-passes'), { timeout: 20000 })
    await page.getByRole('button', { name: 'Generate QR pass' }).click()
    const response = await issued
    if (response.status() !== 201) throw new Error(`the server answered ${response.status()}: ${(await response.text()).slice(0, 160)}`)
    const body = await response.json()
    if (!/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(body.token ?? '')) throw new Error('the pass carries no signed token')
    code = body.code
    await page.getByRole('heading', { name: 'Visitor pass' }).waitFor({ timeout: 15000 })
    await page.getByRole('img', { name: new RegExp(`QR code for ${visitor}`) }).waitFor({ timeout: 10000 })
    await page.getByText(code, { exact: true }).waitFor({ timeout: 5000 })
  })

  await step('the QR is drawn on the phone — no image was fetched for it', async () => {
    const images = await page.evaluate(() => performance.getEntriesByType('resource').filter((e) => /qr|chart|barcode/i.test(e.name)).map((e) => e.name))
    if (images.length > 0) throw new Error(`a QR image was fetched: ${images[0]}`)
  })

  await step('cancelled: it reads Cancelled, and its code is no longer shown', async () => {
    const cancelled = page.waitForResponse((r) => /\/visitor-passes\/\d+\/cancel$/.test(new URL(r.url()).pathname), { timeout: 20000 })
    await page.getByRole('button', { name: 'Cancel this pass' }).click()
    if ((await cancelled).status() !== 200) throw new Error('cancel was not accepted')
    await page.getByText('Cancelled', { exact: true }).waitFor({ timeout: 10000 })
    if (await page.getByText(code, { exact: true }).isVisible()) throw new Error('a cancelled pass still shows its code')
    if (await page.getByRole('img', { name: /QR code/ }).isVisible()) throw new Error('a cancelled pass still shows a QR code')
  })
} catch {
  /* the failing step is already recorded */
}

await finish('resident visitors flow')

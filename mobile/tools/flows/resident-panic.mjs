/**
 * LIVE flow check — the resident's panic alert, end to end against the real API.
 *
 *   Home → the amber shield → a TAP does nothing → HOLD sends → the alert is POSTed (with its two
 *   stamps) inside the three-second deadline → "Alert sent" and the server's own timeline →
 *   cancel needs a reason → cancelled inside the server's ten-second window → summary → Home
 *
 * It measures INVARIANT 8 on the wire: milliseconds from the hold completing to the request leaving.
 *
 *   node tools/flows/resident-panic.mjs        (resident dev server on :8082, API on :8000)
 */

import { openPhone, signInResident } from './lib.mjs'

const HOLD_MS = 1500
const DEADLINE_MS = 3000

const phone = await openPhone()
const { page, step, finish } = phone

try {
  await step('sign in as the test resident and reach Home', async () => signInResident(page))

  await step('the amber shield on Home opens the emergency screen — armed, nothing sent', async () => {
    await page.getByRole('button', { name: 'Panic alert' }).click()
    await page.getByRole('heading', { name: 'Emergency alert' }).waitFor({ timeout: 15000 })
    await page.getByText('Hold to send an alert').waitFor({ timeout: 5000 })
    // The REAL emergency number is one tap away, and the screen says plainly what the alert does NOT reach.
    await page.getByRole('button', { name: /Call the police on 119/ }).waitFor({ timeout: 5000 })
    await page.getByText(/does not reach the police/).waitFor({ timeout: 5000 })
  })

  const button = page.getByRole('button', { name: "Send an alert to your community's security officers" })
  let alertRequests = 0
  page.on('request', (r) => { if (r.method() === 'POST' && new URL(r.url()).pathname.endsWith('/duress')) alertRequests++ })

  await step('a single TAP sends nothing, and a hold released early sends nothing', async () => {
    await button.click()
    const box = await button.boundingBox()
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
    await page.mouse.down()
    await page.waitForTimeout(600)
    await page.mouse.up()
    await page.waitForTimeout(1200)
    if (alertRequests !== 0) throw new Error(`${alertRequests} alert(s) were sent without a full hold`)
    await page.getByText('Hold to send an alert').waitFor({ timeout: 3000 })
  })

  let measured = null
  await step(`HOLDING for ${HOLD_MS} ms sends it: POST /duress with both stamps, 201, inside ${DEADLINE_MS} ms of the hold completing`, async () => {
    const box = await button.boundingBox()
    const sent = page.waitForRequest((r) => r.method() === 'POST' && new URL(r.url()).pathname.endsWith('/duress'), { timeout: 15000 })
    const answered = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/duress'), { timeout: 20000 })
    sent.catch(() => undefined)
    answered.catch(() => undefined)
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
    const pressedAt = Date.now()
    await page.mouse.down()
    const request = await sent
    const leftAt = Date.now()
    await page.mouse.up()
    measured = leftAt - pressedAt - HOLD_MS
    const headers = request.headers()
    if (!/^[0-9a-f-]{36}$/.test(headers['idempotency-key'] ?? '')) throw new Error('the alert carried no Idempotency-Key')
    if (!/^\d{4}-\d{2}-\d{2}T/.test(headers['x-device-time'] ?? '')) throw new Error('the alert carried no X-Device-Time')
    if (measured > DEADLINE_MS) throw new Error(`the alert left ${measured} ms after the hold completed — over the ${DEADLINE_MS} ms deadline`)
    const response = await answered
    if (response.status() !== 201) throw new Error(`the server answered ${response.status()}`)
    const body = request.postDataJSON()
    if (Object.keys(body).some((k) => !['mode', 'latitude', 'longitude', 'captured_offline'].includes(k))) throw new Error(`the alert carried something that is not the alert: ${Object.keys(body)}`)
  })

  await step('exactly ONE alert was raised, and the screen says so with the server’s own timeline', async () => {
    await page.getByText('Alert sent', { exact: true }).waitFor({ timeout: 10000 })
    await page.getByText('Alert sent to gatehouse & dispatch').waitFor({ timeout: 5000 })
    await page.getByText('Waiting for dispatch to acknowledge').waitFor({ timeout: 5000 })
    if (alertRequests !== 1) throw new Error(`${alertRequests} POST /duress requests were made`)
  })

  await step('cancelling needs a reason: the button stays shut until one is chosen', async () => {
    await page.getByRole('button', { name: /Sent by mistake\? Cancel/ }).click()
    const confirm = page.getByRole('button', { name: 'Cancel the alert' })
    if ((await confirm.getAttribute('aria-disabled')) !== 'true') throw new Error('Cancel was enabled with no reason chosen')
    await page.getByRole('radio', { name: 'Pressed by accident' }).click()
    if ((await confirm.getAttribute('aria-disabled')) === 'true') throw new Error('Cancel stayed disabled after a reason was chosen')
  })

  await step('cancelled inside the server’s window, with the reason sent; the summary says so; Done returns Home', async () => {
    const cancelled = page.waitForResponse((r) => /\/duress\/\d+\/cancel$/.test(new URL(r.url()).pathname), { timeout: 15000 })
    await page.getByRole('button', { name: 'Cancel the alert' }).click()
    const response = await cancelled
    if (response.status() !== 200) throw new Error(`cancel answered ${response.status()}`)
    if (response.request().postDataJSON()?.reason !== 'Pressed by accident') throw new Error('the reason was not sent')
    if ((await response.json()).status !== 'false_alarm') throw new Error('the server did not record a false alarm')
    await page.getByText('Alert cancelled', { exact: true }).waitFor({ timeout: 10000 })
    await page.getByRole('button', { name: 'Done' }).click()
    await page.getByRole('tablist').waitFor({ timeout: 15000 })
  })

  if (measured !== null) console.log(`      invariant 8, on the wire: the alert left the phone ${Math.max(0, measured)} ms after the hold completed (deadline ${DEADLINE_MS} ms)`)
} catch {
  /* the failing step is already recorded */
}

await finish('resident panic flow')

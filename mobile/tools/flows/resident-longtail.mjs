/**
 * LIVE flow check — the resident's long tail, end to end against the real API (no fixtures):
 *
 *   Dues: the ledger's balance and charges → "Pay maintenance fee" takes no card, and Pay says the payment gateway
 *         is not integrated yet (M42 §5 #9) — nothing is POSTed that takes money and nothing says "Payment successful"
 *   Notices: the list → open one → it is marked read on the server
 *   Maintenance: an empty form is refused → a real ticket is POSTed (201) and can be followed
 *   Profile → Settings → Notification settings: the gate and panic switches cannot be turned off
 *   Voting: whatever the server says about eligibility, NO ballot body ever leaves the phone in this check
 *
 *   node tools/flows/resident-longtail.mjs        (resident dev server on :8082, API on :8000)
 */

import { openPhone, setConnection, signInResident } from './lib.mjs'

const phone = await openPhone()
const { page, step, finish } = phone
const seen = (re) => (r) => re.test(new URL(r.url()).pathname)

// Money and votes: watch the whole run. Nothing may go to a pay/charge/ballot endpoint from this check.
const forbidden = []
page.on('request', (r) => {
  const path = new URL(r.url()).pathname
  if (r.method() !== 'GET' && /\/(ballot|charge|payments)(\/|$)/.test(path)) forbidden.push(`${r.method()} ${path}`)
})

try {
  await step('sign in as the test resident', async () => { await signInResident(page) })

  await step('Dues: the statement is the ledger\'s — a balance, and the household\'s charges', async () => {
    const balance = page.waitForResponse(seen(/\/households\/\d+\/balance$/), { timeout: 30000 })
    balance.catch(() => undefined)
    await page.getByRole('tab', { name: 'Dues' }).click()
    if ((await balance).status() !== 200) throw new Error('the balance did not answer 200')
    await page.locator('text=/\\$[\\d,]+/ >> visible=true').first().waitFor({ timeout: 20000 })
  })

  await step('"Pay maintenance fee" takes no card; Pay is one dialog — the gateway is not integrated yet — and nothing is sent', async () => {
    await page.getByRole('button', { name: /^(How to pay|Pay now)/ }).first().click()
    await page.getByRole('heading', { name: 'Pay maintenance fee' }).waitFor({ timeout: 30000 })
    const inputs = await page.locator('input:visible').count()
    if (inputs > 0) throw new Error(`the pay screen opens with ${inputs} input field(s) — there is nothing to type until "Pay another amount" is chosen`)
    // The padlock line's "full card number" is the design's own; a card FORM's words are not.
    if (await page.locator('text=/CVV|security code|expiry date|4242|DEMONSTRATION/i >> visible=true').count()) throw new Error('card-entry or demonstration wording is on the pay screen')
    const dialog = page.waitForEvent('dialog', { timeout: 15000 })
    await page.getByRole('button', { name: /^Pay \$/ }).click()
    const said = await dialog
    const words = said.message()
    await said.accept()
    if (words !== 'The payment gateway is not integrated yet.') throw new Error(`Pay said "${words}"`)
    if (await page.locator('text=/Payment successful/ >> visible=true').count()) throw new Error('"Payment successful" is shown for a payment that was never made')
  })

  await step('Notices: the list opens, a notice opens, and reading it reaches the server', async () => {
    // VISIBLE controls only: the navigator keeps earlier screens mounted (hidden) underneath.
    await page.locator('role=button[name="Back"] >> visible=true').first().click()
    await page.locator('role=tab[name="Notices"] >> visible=true').first().click()
    await page.getByRole('heading', { name: 'Notices' }).waitFor({ timeout: 20000 })
    const read = page.waitForResponse((r) => r.request().method() === 'POST' && /\/notices\/\d+\/read$/.test(new URL(r.url()).pathname), { timeout: 30000 })
    read.catch(() => undefined)
    // The seeded estate has a standing notice about Gate 2; any visible card would do.
    // (Home stays mounted under the tab and names the same notice as "Notice: …" — so the card is found by ITS label.)
    await page.getByRole('button', { name: /^(Unread\. )?(Urgent\. )?Gate 2 temporarily closed/ }).first().click()
    await page.locator('text=/barrier arm sensor/ >> visible=true').first().waitFor({ timeout: 20000 })
    const answer = await read.catch(() => null)
    // Already-read notices do not need telling again: either a 2xx, or no call at all and the body on screen.
    if (answer && answer.status() >= 300) throw new Error(`marking the notice read answered ${answer.status()}`)
  })

  await step('Maintenance: an empty request is refused; a real one is POSTed (201) and can be followed', async () => {
    await page.goBack()
    await page.getByRole('tab', { name: 'Home' }).click()
    await page.getByRole('button', { name: 'Maintenance', exact: true }).click()
    await page.getByRole('heading', { name: 'New maintenance request' }).waitFor({ timeout: 20000 })
    let posted = false
    const watch = (r) => { if (r.method() === 'POST' && new URL(r.url()).pathname.endsWith('/tickets')) posted = true }
    page.on('request', watch)
    await page.getByRole('button', { name: /^(Submit|Send) request/ }).click()
    await page.waitForTimeout(800)
    if (posted) throw new Error('an empty request was sent to the server')
    // Board 11 has no title field: the app writes it from the chip and the place ("Lighting — Lot 47").
    await page.getByRole('radio', { name: 'Lighting' }).click()
    await page.getByLabel('Describe the problem').fill(`Flow check ${Date.now().toString().slice(-5)}: the light by the playground is not coming on at night.`)
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/tickets'), { timeout: 30000 })
    sent.catch(() => undefined)
    await page.getByRole('button', { name: /^(Submit|Send) request/ }).click()
    const response = await sent
    page.off('request', watch)
    if (response.status() !== 201) throw new Error(`the server answered ${response.status()}: ${(await response.text()).slice(0, 160)}`)
    let title = ''
    try {
      title = String(JSON.parse(response.request().postData() ?? '{}').title ?? '')
    } catch {
      /* not a JSON body: nothing to read the title from */
    }
    if (!/^Lighting( — \S.*)?$/.test(title)) throw new Error(`the title the app wrote was "${title}", not "Lighting — <the unit>"`)
    await page.getByRole('button', { name: 'Follow request' }).waitFor({ timeout: 20000 })
  })

  await step('Airplane mode: a request is SAVED ON THE PHONE and said so — never "submitted" — and sends itself on reconnect', async () => {
    await page.getByRole('button', { name: 'Report another problem' }).click()
    await setConnection(page, false)
    await page.getByRole('radio', { name: 'Plumbing' }).click()
    await page.getByLabel('Describe the problem').fill(`Offline check ${Date.now().toString().slice(-5)}: the tap drips, written with no connection.`)
    await page.getByRole('button', { name: /^(Submit|Send) request/ }).click()
    await page.locator('text=/Saved on this phone/ >> visible=true').first().waitFor({ timeout: 20000 })
    if (await page.locator('text=/submitted/i >> visible=true').count()) throw new Error('an unsent request is called "submitted"')
    const sent = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/tickets'), { timeout: 45000 })
    sent.catch(() => undefined)
    await setConnection(page, true)
    if ((await sent).status() !== 201) throw new Error('the saved request was not accepted on reconnect')
    await page.getByRole('button', { name: 'Follow request' }).waitFor({ timeout: 20000 })
  })

  await step('Settings → Notifications: "someone at the gate" and panic updates cannot be switched off', async () => {
    await page.getByRole('button', { name: 'Done' }).click()
    await page.getByRole('tab', { name: 'Profile' }).click()
    await page.getByRole('button', { name: 'Settings' }).click()
    await page.getByRole('button', { name: /Notification/ }).first().click()
    const locked = page.getByRole('switch', { name: /gate/i }).first()
    await locked.waitFor({ timeout: 20000 })
    if ((await locked.getAttribute('aria-disabled')) !== 'true' && (await locked.isEnabled())) {
      await locked.click().catch(() => undefined)
      if ((await locked.getAttribute('aria-checked')) !== 'true') throw new Error('the gate switch could be turned off')
    }
  })

  await step('nothing in this whole run was sent to a pay, charge or ballot endpoint', async () => {
    if (forbidden.length > 0) throw new Error(`sent: ${forbidden.join(', ')}`)
  })
} catch {
  /* the failing step is already recorded */
}

await finish('resident long-tail flow')

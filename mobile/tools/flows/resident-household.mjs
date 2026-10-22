/**
 * LIVE flow check — household members are the PRIMARY resident's to add and to remove.
 *
 *   node tools/flows/resident-household.mjs        (resident dev server on :8082, API on :8000)
 *
 * As the test resident (the household's primary): Profile → add a clearly-labelled test member → the server has
 * them (201, Pending) → "Remove" beside them → what removing means is SAID first → confirm → DELETE answers 200 →
 * they are gone from the list at once. Beside the primary herself there is no "Remove" at all.
 *
 * It adds and removes its own test member and leaves the household as it found it (the removed row stays in the
 * estate's history, as any removal does).
 */

import { openPhone, signInResident } from './lib.mjs'

const phone = await openPhone()
const { page, step, finish } = phone
const NAME = `Zed Removable (test ${Date.now().toString(36).slice(-4)})`

try {
  await step('sign in as the test resident', async () => { await signInResident(page) })

  await step('Profile: the primary resident sees "Add household member", and no "Remove" beside herself', async () => {
    await page.locator('role=tab[name="Profile"] >> visible=true').first().click()
    await page.locator('role=link[name="Add household member"] >> visible=true').first().waitFor({ timeout: 30000 })
    if (await page.locator('role=button[name=/^Remove Andrea Fletcher/] >> visible=true').count()) throw new Error('a "Remove" is drawn beside the primary resident')
  })

  await step('add a test member — the server takes it (201) and lists them as Pending', async () => {
    await page.locator('role=link[name="Add household member"] >> visible=true').first().click()
    await page.getByLabel('Full name').fill(NAME)
    await page.locator('role=button[name=/^Relationship:/] >> visible=true').first().click()
    await page.locator('role=radio[name=/^Other relative/] >> visible=true').first().click()
    const stored = page.waitForResponse((r) => r.request().method() === 'POST' && /\/household-members$/.test(new URL(r.url()).pathname), { timeout: 40000 })
    await page.locator('role=button[name="Save household member"] >> visible=true').first().click()
    const answer = await stored
    if (answer.status() !== 201) throw new Error(`members.store answered ${answer.status()}`)
    await page.locator('role=button[name="Done"] >> visible=true').first().click()
    await page.locator(`role=button[name="Remove ${NAME} from your household"] >> visible=true`).first().waitFor({ timeout: 30000 })
  })

  await step('"Remove" says what it means BEFORE anything is sent, and "Keep" changes nothing', async () => {
    let sent = 0
    const watch = (r) => { if (r.method() === 'DELETE') sent++ }
    page.on('request', watch)
    await page.locator(`role=button[name="Remove ${NAME} from your household"] >> visible=true`).first().click()
    await page.locator(`text=/leaves your household's list at once/ >> visible=true`).first().waitFor({ timeout: 10000 })
    await page.locator('role=button[name=/^Keep /] >> visible=true').first().click()
    page.off('request', watch)
    if (sent > 0) throw new Error('a DELETE was sent before the resident confirmed')
    await page.locator(`role=button[name="Remove ${NAME} from your household"] >> visible=true`).first().waitFor({ timeout: 10000 })
  })

  await step('confirming removes them on the server (200) and from the list at once', async () => {
    await page.locator(`role=button[name="Remove ${NAME} from your household"] >> visible=true`).first().click()
    const removed = page.waitForResponse((r) => r.request().method() === 'DELETE' && /\/household-members\/\d+$/.test(new URL(r.url()).pathname), { timeout: 40000 })
    await page.locator('role=button[name=/^Remove Zed$/] >> visible=true').first().click()
    const answer = await removed
    if (answer.status() !== 200) throw new Error(`members.destroy answered ${answer.status()}`)
    await page.locator(`text="${NAME}" >> visible=true`).first().waitFor({ state: 'detached', timeout: 30000 }).catch(async () => {
      if (await page.locator(`text="${NAME}" >> visible=true`).count()) throw new Error('the removed member is still on the list')
    })
  })
} finally {
  await finish()
}

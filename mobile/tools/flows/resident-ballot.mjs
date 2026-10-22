/**
 * LIVE flow check — ONE REAL BALLOT, end to end, against a seeded open election.
 *
 *   php artisan mobile:test-election          (in the API project — opens a labelled TEST election; every
 *                                              run opens a NEW one, because a household's paper cannot be recast)
 *   node tools/flows/resident-ballot.mjs      (resident dev server on :8082, API on :8000)
 *
 * What it proves, on the real screens with no fixtures:
 *   Home says voting is open → the gate lets an eligible household in → mark one choice per position →
 *   review shows the marks → "Send my vote" POSTs the paper ONCE → the receipt says "Your vote is in" with
 *   a date and NO choice, NO code → going back to the ballot shows the receipt, never the paper again →
 *   a second paper sent by hand is refused 409 already_voted → results name no winner while the poll is open.
 *
 * INVARIANTS it watches the whole way: exactly ONE ballot POST leaves the page; its body is never read,
 * logged or stored by this script (only the fact and the status); nothing is ever retried.
 *
 * This is the ONLY check that sends a ballot, and it refuses to touch any election whose title does not
 * start with the test prefix.
 */

import { openPhone, signInResident } from './lib.mjs'

const TEST_TITLE = /^Mobile build check election \(TEST /
// --after: the paper for the open test election is ALREADY in (an earlier run cast it). Casts nothing; checks only
// what must be true afterwards — the receipt, the paper never opening again, the refused second paper, the results.
const AFTER = process.argv.includes('--after')
const EXPECTED_POSTS = AFTER ? 0 : 1
const phone = await openPhone()
const { page, step, finish } = phone

// Count ballot POSTs. The body is deliberately never touched.
const ballotPosts = []
page.on('response', (r) => {
  const path = new URL(r.url()).pathname
  if (r.request().method() === 'POST' && /\/elections\/\d+\/ballot$/.test(path)) ballotPosts.push({ path, status: r.status() })
})

let electionId = null
let token = null
let api = null

// A route change INSIDE the running app. (page.goto would reload the page, and on the web harness the secure
// store is memory only — a reload signs the session out, which a phone never does.)
const visit = (path) => page.evaluate((to) => { window.history.pushState({}, '', to); window.dispatchEvent(new PopStateEvent('popstate')) }, path)

try {
  await step('sign in as the test resident', async () => {
    page.on('request', (r) => {
      const auth = r.headers().authorization
      if (auth && !token) { token = auth.replace(/^Bearer\s+/i, ''); api = r.url().replace(/(\/api\/v1)\/.*$/, '$1') }
    })
    await signInResident(page)
  })

  await step(AFTER ? 'the server lists the open test election as VOTED — turnout, never choice' : 'the server lists an OPEN test election this household has not voted in', async () => {
    const list = await page.waitForResponse((r) => /\/elections$/.test(new URL(r.url()).pathname) && r.status() === 200, { timeout: 40000 }).catch(() => null)
    const body = list ? await list.json() : await (await fetch(`${api}/elections`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } })).json()
    const open = (body.items ?? []).find((e) => e.voting_open && TEST_TITLE.test(e.title))
    if (!open) throw new Error('no open TEST election — run `php artisan mobile:test-election` in the API project first')
    if (AFTER && !open.has_voted) throw new Error(`--after expects the household's paper to be in already; #${open.id} has none`)
    if (!AFTER && open.has_voted) throw new Error(`the test household has already voted in #${open.id} — run \`php artisan mobile:test-election\` again for a fresh one, or check the aftermath with --after`)
    const leaked = Object.keys(open).filter((k) => /mark|choice|option_id|receipt/i.test(k))
    if (leaked.length) throw new Error(`the election row carries ${leaked.join(', ')} — it may say THAT the household voted, never how`)
    if (!/Z$|[+-]\d\d:\d\d$/.test(String(open.closes_at))) throw new Error(`closes_at is not an instant with an offset: ${open.closes_at}`)
    electionId = open.id
  })

  // The casting half runs only when a paper is still to be cast.
  const cast = (name, fn) => (AFTER ? Promise.resolve() : step(name, fn))

  await cast('Home tells the household that voting is open, and the row opens the ballot', async () => {
    const row = page.locator('role=button[name=/Voting is open/i] >> visible=true').first()
    await row.waitFor({ timeout: 30000 })
    await row.click()
    await page.locator(`text=/Mobile build check election/ >> visible=true`).first().waitFor({ timeout: 30000 })
  })

  await cast('the gate let an eligible household in: the paper shows both positions, nothing pre-marked', async () => {
    await page.locator('role=radiogroup >> visible=true').first().waitFor({ timeout: 30000 })
    const marked = await page.locator('role=radio[checked] >> visible=true').count()
    if (marked > 0) throw new Error(`${marked} choice(s) arrived already marked`)
  })

  await cast('mark one candidate for each position', async () => {
    await page.locator('role=radio[name=/Test Candidate A/] >> visible=true').first().click()
    // The second position may be on this page, folded under "Remaining positions", or on page two.
    const direct = page.locator('role=radio[name=/Test Candidate D/] >> visible=true').first()
    if (!(await direct.isVisible().catch(() => false))) {
      const unfold = page.locator('role=button[name=/Test Treasurer/] >> visible=true').first()
      if (await unfold.isVisible().catch(() => false)) await unfold.click()
      else {
        await page.locator('role=button[name=/Continue|Next/i] >> visible=true').first().click()
      }
    }
    await page.locator('role=radio[name=/Test Candidate D/] >> visible=true').first().click()
    if ((await page.locator('role=radio[checked] >> visible=true').count()) < 1) throw new Error('the marks did not take')
    if (ballotPosts.length > 0) throw new Error('a ballot was sent while only marking')
  })

  await cast('review shows exactly what was marked, and nothing has been sent yet', async () => {
    // Walk forward until the review screen: "Review…" from the last ballot page.
    for (let i = 0; i < 3; i++) {
      if (await page.locator('text=/Check your selections carefully/ >> visible=true').first().isVisible().catch(() => false)) break
      await page.locator('role=button[name=/Review|Continue|Next/i] >> visible=true').first().click()
      await page.waitForTimeout(1200)
    }
    await page.locator('text=/Check your selections carefully/ >> visible=true').first().waitFor({ timeout: 20000 })
    await page.locator('text=/Test Candidate A/ >> visible=true').first().waitFor({ timeout: 10000 })
    await page.locator('text=/Test Candidate D/ >> visible=true').first().waitFor({ timeout: 10000 })
    if (ballotPosts.length > 0) throw new Error('a ballot was sent before "Send my vote" was pressed')
  })

  await cast('"Send my vote" sends the paper ONCE and the server accepts it (2xx)', async () => {
    await page.locator('role=button[name="Send my vote"] >> visible=true').first().click()
    await page.locator('role=heading[name="Your vote is in"] >> visible=true').first().waitFor({ timeout: 40000 })
    if (ballotPosts.length !== 1) throw new Error(`${ballotPosts.length} ballot POSTs left the phone — it must be exactly one`)
    if (ballotPosts[0].status >= 300) throw new Error(`the ballot answered ${ballotPosts[0].status}`)
  })

  if (AFTER) {
    await step('Home says the household has voted, and the row opens the receipt', async () => {
      const row = page.locator('role=button[name=/Your household has voted/i] >> visible=true').first()
      await row.waitFor({ timeout: 30000 })
      await row.click()
      await page.locator('role=heading[name="Your vote is in"] >> visible=true').first().waitFor({ timeout: 40000 })
    })
  }

  await step('the receipt shows a date and NO choice and NO code', async () => {
    const text = await page.locator('body').innerText()
    const visible = text.split('Your vote is in').pop() ?? ''
    if (/Test Candidate [A-D]/.test(visible)) throw new Error('the receipt names a candidate — a receipt must never show a choice')
    // The screen SAYS "there is no receipt code" — that sentence is right. What must not exist is a code itself:
    // a labelled value, or anything shaped like one (GS-7F3K-22, 9A41-CC07, a long hex run).
    const code = /(receipt|confirmation|reference)\s*(code|number|no\.?)?\s*[:#]\s*\S+|\b[A-Z0-9]{2,6}(-[A-Z0-9]{2,8}){1,3}\b|\b[0-9a-f]{12,}\b/.exec(visible.replace(/no receipt code/gi, ''))
    if (code) throw new Error(`the receipt shows something shaped like a code ("${code[0]}") — a code is a linkage risk`)
    if (!/\b(20\d\d|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b/.test(visible)) throw new Error('the receipt shows no date')
  })

  await step('going back to the ballot shows the receipt — the paper never opens again', async () => {
    await visit(`/elections/${electionId}/ballot-a`)
    await page.locator('role=heading[name="Your vote is in"] >> visible=true').first().waitFor({ timeout: 40000 })
    if (await page.locator('role=radio >> visible=true').count()) throw new Error('a ballot paper is on screen after the household voted')
    if (ballotPosts.length !== EXPECTED_POSTS) throw new Error('another ballot POST left the phone')
  })

  await step('the server refuses a second paper for this household: 409 already_voted', async () => {
    // Sent by hand, with a fresh key, straight at the API — the app itself offers no way to do this. An empty,
    // invalid paper: the refusal must come from "already voted", before the paper is even looked at… or as 422;
    // either way it must NOT be a 2xx.
    const r = await fetch(`${api}/elections/${electionId}/ballot`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, 'Idempotency-Key': `flow-ballot-second-${Date.now()}`, 'X-Device-Time': new Date().toISOString() },
      body: JSON.stringify({ option_ids: [] }),
    })
    if (r.status < 400) throw new Error(`a second paper was ACCEPTED (${r.status})`)
    if (![409, 422].includes(r.status)) throw new Error(`a second paper answered ${r.status}, expected 409 (or 422 for the blank paper)`)
  })

  await step('results: a tally view that names no winner while the poll is open', async () => {
    await visit(`/elections/${electionId}/results`)
    await page.waitForTimeout(3000)
    const text = await page.locator('body').innerText()
    if (/\b(winner|elected|wins)\b/i.test(text)) throw new Error('the results screen declares a winner — that is the Secretary\'s act, not the app\'s')
  })
} finally {
  await finish()
}

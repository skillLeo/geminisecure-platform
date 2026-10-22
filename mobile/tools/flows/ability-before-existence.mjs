/**
 * LIVE check — INVARIANT 5: ability and device checks come BEFORE existence checks.
 *
 * An API that answers "404, no such shift" to someone who was never allowed to ask has just told
 * them which ids exist. So for every probe below, the answer to a REAL id and to an id that cannot
 * exist must be THE SAME refusal — same status, same error code — and never a 404 for one of them.
 *
 *   A  no token at all            → 401 both ways (guard routes and resident routes)
 *   B  a RESIDENT token on GUARD routes (no ability, wrong kind of principal)
 *                                  → the same 401/403 both ways — never 404, never 200
 *   C  a resident token on a record that is NOT THEIRS (someone else's alert / pass / ticket id)
 *                                  → indistinguishable from an id that does not exist
 *   D  a write with no Idempotency-Key / device time on a route the token may not use
 *                                  → refused for the ABILITY first, not for the missing header
 *
 *   node tools/flows/ability-before-existence.mjs        (API on :8000; uses the durable test resident)
 */

import { randomUUID } from 'node:crypto'
import { ESTATE, RESIDENT_EMAIL, artisan } from './lib.mjs'

const API = 'http://127.0.0.1:8000/api/v1'
const NOWHERE = 987654321
const lines = []
let failed = 0
const ok = (name) => lines.push(`ok    ${name}`)
const fail = (name, why) => { failed++; lines.push(`FAIL  ${name} — ${why}`) }

async function call(method, path, { token, body, stamped = true } = {}) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`
  if (method !== 'GET' && stamped) { headers['Idempotency-Key'] = randomUUID(); headers['X-Device-Time'] = new Date().toISOString() }
  const res = await fetch(`${API}${path}`, { method, headers, body: method === 'GET' ? undefined : JSON.stringify(body ?? {}) })
  let json = null
  try { json = await res.json() } catch { /* an empty body is an answer too */ }
  return { status: res.status, code: json?.error?.code ?? null }
}

const same = (p, q) => p.status === q.status && p.code === q.code
const show = (r) => `${r.status}${r.code ? ` ${r.code}` : ''}`

async function pair(name, method, template, real, options, allowed) {
  const hit = await call(method, template.replace('{id}', String(real)), options)
  const miss = await call(method, template.replace('{id}', String(NOWHERE)), options)
  if (!allowed.includes(hit.status)) return fail(name, `a real id answered ${show(hit)} — expected ${allowed.join(' or ')}`)
  if (!same(hit, miss)) return fail(name, `a real id answered ${show(hit)} but a missing id answered ${show(miss)} — existence leaks`)
  ok(`${name} — ${show(hit)} both ways`)
}

try {
  artisan('mobile:test-accounts')

  /* A — no token */
  await pair('A  no token · guard route   POST /shifts/{id}/clock-in', 'POST', '/shifts/{id}/clock-in', 1, {}, [401])
  await pair('A  no token · guard route   GET  /duress/{id}', 'GET', '/duress/{id}', 1, {}, [401])
  await pair('A  no token · resident route POST /visitor-passes/{id}/cancel', 'POST', '/visitor-passes/{id}/cancel', 1, {}, [401])
  await pair('A  no token · resident route GET  /payments/{id}/receipt', 'GET', '/payments/{id}/receipt', 1, {}, [401])

  /* a resident's token */
  const requested = await call('POST', '/auth/otp/request', { body: { estate: ESTATE, channel: 'email', destination: RESIDENT_EMAIL }, stamped: false })
  if (requested.status >= 400) throw new Error(`could not request a sign-in code: ${show(requested)}`)
  const code = artisan('mobile:signin-code', 'resident', '--plain').trim().split(/\s+/).pop()
  const verify = await fetch(`${API}/auth/otp/verify`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ estate: ESTATE, channel: 'email', destination: RESIDENT_EMAIL, code, device_uid: `abe-check-${randomUUID()}`, platform: 'android' }) })
  const token = (await verify.json()).token
  if (!token) throw new Error(`could not sign the test resident in (${verify.status})`)

  /* B — a resident on guard routes */
  for (const [method, template] of [['POST', '/shifts/{id}/clock-in'], ['POST', '/shifts/{id}/claim'], ['POST', '/alertness/{id}/respond'], ['POST', '/shifts/{id}/preflight']]) {
    await pair(`B  resident token · guard route ${method} ${template}`, method, template, 1, { token }, [401, 403])
  }

  /* C — a record that is not theirs looks exactly like one that does not exist */
  // READ-ONLY on purpose (a write probe would change whatever it hit). Over a run of ids, every answer is either
  // "yours" (200) or EXACTLY what an id that cannot exist gets — there is no third answer that says "exists, not yours".
  for (const template of ['/duress/{id}', '/payments/{id}/receipt']) {
    const nowhere = await call('GET', template.replace('{id}', String(NOWHERE)), { token })
    const leaks = []
    let mine = 0
    for (let id = 1; id <= 40; id++) {
      const r = await call('GET', template.replace('{id}', String(id)), { token })
      if (r.status === 200) mine++
      else if (!same(r, nowhere)) leaks.push(`#${id} → ${show(r)}`)
    }
    if (leaks.length > 0) fail(`C  resident token · GET ${template}`, `a missing id answers ${show(nowhere)} but ${leaks.slice(0, 3).join(', ')} — existence leaks`)
    else ok(`C  resident token · GET ${template} — ids 1–40: ${mine} theirs, every other one answers ${show(nowhere)}, the same as an id that cannot exist`)
  }

  /* D — ability before the write headers */
  const bare = await call('POST', '/shifts/1/clock-in', { token, stamped: false })
  if (bare.status === 422) fail('D  ability before headers', `a resident was told about the missing Idempotency-Key (${show(bare)}) on a route they may not use`)
  else if (![401, 403].includes(bare.status)) fail('D  ability before headers', `answered ${show(bare)}`)
  else ok(`D  a resident with no write headers on a guard route is refused for who they are — ${show(bare)}`)
} catch (e) {
  fail('setup', e.message.split('\n')[0])
}

for (const line of lines) console.log(line)
console.log(`${failed ? 'FAIL' : 'PASS'}  ability before existence`)
process.exit(failed ? 1 : 0)

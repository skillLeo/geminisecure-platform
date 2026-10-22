/**
 * LIVE check — a real photo, uploaded end to end, observed at both ends.
 *
 *   node tools/flows/photo-upload.mjs        (API on :8000)
 *
 * A REAL JPEG is made here (a browser screenshot, re-encoded the way the app re-encodes a photo: JPEG, no EXIF),
 * its SHA-256 is taken on this side, a maintenance request is created as the test resident, and the file goes up
 * exactly as the app sends it — multipart, one part named `file`, a stable Idempotency-Key, the device time. Then:
 *
 *   · the server's own hash and byte count of what ARRIVED equal what was SENT (nothing truncated or re-encoded)
 *   · the request now reports one attachment
 *   · sending the SAME upload again with the SAME key is answered, not repeated (still one attachment)
 *   · another household's / an unknown ticket id refuses the upload with the same 404
 *
 * What this cannot see is the phone's half — the camera, the re-encode on the device, React Native's file part.
 * That stays in PHONE_CHECK.md (a real camera photo is one of the few checks only a person can make).
 * The stored file is never read back from disk: the project's uploads folder is off limits to every tool here.
 */

import { createHash, randomUUID } from 'node:crypto'
import { chromium } from 'playwright'
import { ESTATE, RESIDENT_EMAIL, artisan } from './lib.mjs'

const API = process.env.GS_API ?? 'http://127.0.0.1:8000/api/v1'
const results = []
const ok = (name) => { results.push(true); console.log(`ok    ${name}`) }
const fail = (name, why) => { results.push(false); console.log(`FAIL  ${name} — ${why}`) }

const stamped = (token, key) => ({ Accept: 'application/json', Authorization: `Bearer ${token}`, 'Idempotency-Key': key, 'X-Device-Time': new Date().toISOString() })

/* a real JPEG */
const browser = await chromium.launch()
const shot = await browser.newPage({ viewport: { width: 1200, height: 900 } })
await shot.setContent('<body style="margin:0;background:linear-gradient(135deg,#1974D2,#0F3D6B);font:700 64px sans-serif;color:#fff;display:grid;place-items:center;height:100vh">Leaking pipe — test photo</body>')
const jpeg = await shot.screenshot({ type: 'jpeg', quality: 72 })
await browser.close()
const sha = createHash('sha256').update(jpeg).digest('hex')
if (jpeg[0] === 0xff && jpeg[1] === 0xd8 && jpeg.length > 5000) ok(`a real JPEG was made — ${jpeg.length} bytes, sha256 ${sha.slice(0, 12)}…`)
else fail('a real JPEG was made', `${jpeg.length} bytes, not a JPEG`)

/* sign in as the test resident */
await fetch(`${API}/auth/otp/request`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ estate: ESTATE, channel: 'email', destination: RESIDENT_EMAIL }) })
const code = artisan('mobile:signin-code', 'resident', '--plain').trim().split(/\s+/).pop()
const verify = await fetch(`${API}/auth/otp/verify`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ estate: ESTATE, channel: 'email', destination: RESIDENT_EMAIL, code, device_uid: 'flow-photo-upload', platform: 'android' }) })
const token = (await verify.json()).token
if (!token) { fail('sign in as the test resident', `verify answered ${verify.status}`); process.exit(1) }
ok('signed in as the test resident')

/* a maintenance request to hang it on */
const created = await fetch(`${API}/tickets`, { method: 'POST', headers: { ...stamped(token, `flow-photo-ticket-${randomUUID()}`), 'Content-Type': 'application/json' }, body: JSON.stringify({ title: 'Photo upload check (automated test)', category: 'plumbing', description: 'Created by tools/flows/photo-upload.mjs to observe a photo upload end to end. Safe to close.', priority: 'low' }) })
const ticket = await created.json()
if (created.status !== 201 || !ticket.id) { fail('a maintenance request was created', `${created.status}`); process.exit(1) }
ok(`a maintenance request was created — #${ticket.number ?? ticket.id}, ${ticket.media_count} attachments`)

/* the upload, exactly as the app sends it */
const key = `flow-photo-${randomUUID()}`
const send = async (id, idempotencyKey) => {
  const form = new FormData()
  form.append('file', new Blob([jpeg], { type: 'image/jpeg' }), 'photo-1.jpg')
  const r = await fetch(`${API}/tickets/${id}/media`, { method: 'POST', headers: stamped(token, idempotencyKey), body: form })
  return { status: r.status, body: await r.json().catch(() => ({})) }
}

const up = await send(ticket.id, key)
if (up.status !== 201 && up.status !== 200) fail('the photo went up', `${up.status} ${JSON.stringify(up.body).slice(0, 200)}`)
else if (up.body.sha256 !== sha) fail('what ARRIVED is what was SENT', `server sha256 ${String(up.body.sha256).slice(0, 12)}… ≠ ${sha.slice(0, 12)}…`)
else if (Number(up.body.bytes) !== jpeg.length) fail('what ARRIVED is what was SENT', `server counted ${up.body.bytes} bytes, ${jpeg.length} were sent`)
else ok(`the photo went up (${up.status}) and what ARRIVED is what was SENT — same ${jpeg.length} bytes, same SHA-256, detected as ${up.body.content_type}`)

const again = await send(ticket.id, key)
const list = await (await fetch(`${API}/tickets`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } })).json()
const row = (list.items ?? []).find((t) => t.id === ticket.id)
if (again.status >= 300) fail('the same upload sent again (same key) is answered, not refused', `${again.status}`)
else if (again.body.media_id !== up.body.media_id) fail('the same upload sent again is answered, not repeated', `a second attachment #${again.body.media_id} was made`)
else if (row?.media_count !== 1) fail('the request reports exactly one attachment', `media_count is ${row?.media_count}`)
else ok('a retry with the same key is answered, not repeated — the request reports exactly one attachment')

const nowhere = await send(987654321, `flow-photo-${randomUUID()}`)
// A write probe goes ONLY at an id that is provably not this household's (an earlier check once cancelled the
// test resident's own pass by assuming id 1 was somebody else's).
const owned = new Set((list.items ?? []).map((t) => t.id))
const other = [1, 2, 3, 4, 5, 6, 7, 8].find((id) => !owned.has(id))
const theirs = other === undefined ? null : await send(other, `flow-photo-${randomUUID()}`)
if (nowhere.status !== 404) fail('an unknown request refuses the upload', `${nowhere.status}`)
else if (theirs && theirs.status !== 404) fail("another household's request refuses the upload exactly like an unknown one", `#${other} answered ${theirs.status}`)
else ok("an unknown request — and another household's — refuse the upload with the same 404")

console.log(results.every(Boolean) ? 'PASS  photo upload, end to end' : 'FAIL  photo upload')
process.exit(results.every(Boolean) ? 0 : 1)

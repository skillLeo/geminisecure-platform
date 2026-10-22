/**
 * LIVE check — ONE TIME RULE, both halves.
 *
 * A builder noticed booking times coming back five hours early (2026-09-20): a controller parsed
 * "…T14:00:00-05:00" and the model saved its wall-clock digits into a UTC column. For a VISITOR PASS that
 * would be worse than cosmetic — the signed validity window would open and close five hours early, and a
 * guest would be refused at the gate at the time their host invited them for.
 *
 *   1  OUT AND BACK   a window a resident picks as 2:00–4:00 PM AT THE ESTATE is sent as the instant that
 *                     is, and the API hands back THE SAME INSTANT. The estate's zone is the one the server
 *                     itself sends (`estate.timezone`); this laptop's zone is never used.
 *   2  THE SERVER'S WORD  a guard's own shift arrives as a UTC instant with the site's IANA zone beside it,
 *                     and that zone applied to 12:00Z reads 7:00 AM.
 *   3  ON THE GLASS   the Guard App's web build, in a browser set to Asia/Tokyo, draws a shift the server
 *                     holds as 12:00Z as "7:00 AM – 7:00 PM". The phone's own zone changes nothing.
 *
 *   node tools/flows/time-roundtrip.mjs          (API on :8000, guard dev server on :8081)
 */

import { randomBytes, randomUUID } from 'node:crypto'
import { chromium } from 'playwright'
import { ESTATE, GUARD_APP, RESIDENT_EMAIL, artisan } from './lib.mjs'

const API = 'http://127.0.0.1:8000/api/v1'
const GUARD = process.env.FLOW_GUARD ?? 'GS-9001'
const UID = 'flowcheck-web-handset-0001'
const json = { Accept: 'application/json', 'Content-Type': 'application/json' }
const stamp = () => ({ 'Idempotency-Key': randomUUID(), 'X-Device-Time': new Date().toISOString() })
let failed = 0
const say = (ok, line) => { if (!ok) failed++; console.log(`${ok ? 'ok  ' : 'FAIL'}  ${line}`) }

/* ---- the app's own rule, in plain JS: a wall-clock time AT THE SITE → the instant it is ---- */

const two = (n) => String(n).padStart(2, '0')
const partsAt = (ms, zone) => {
  const f = new Intl.DateTimeFormat('en-US', { timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
  const got = Object.fromEntries(f.formatToParts(new Date(ms)).map((p) => [p.type, p.value]))
  return { wall: Date.UTC(+got.year, +got.month - 1, +got.day, +got.hour % 24, +got.minute, +got.second), hour: +got.hour % 24, minute: +got.minute }
}
const offsetAt = (ms, zone) => Math.round((partsAt(ms, zone).wall - ms) / 60_000)

/** "2026-09-21" + 14:00 at `zone` → "2026-09-21T14:00:00-05:00" (or −04:00, wherever daylight saving applies). */
function atSiteZone(dayIso, minutes, zone) {
  const [y, m, d] = dayIso.split('-').map(Number)
  const wall = Date.UTC(y, m - 1, d, Math.floor(minutes / 60), minutes % 60, 0)
  const offset = offsetAt(wall - offsetAt(wall, zone) * 60_000, zone)
  const sign = offset >= 0 ? '+' : '-'
  const abs = Math.abs(offset)
  return `${dayIso}T${two(Math.floor(minutes / 60))}:${two(minutes % 60)}:00${sign}${two(Math.floor(abs / 60))}:${two(abs % 60)}`
}

/** "7:00 AM" — the same rule packages/core/lib/siteTime.ts applies. */
function time12(iso, zone) {
  const p = partsAt(new Date(iso).getTime(), zone)
  return `${p.hour % 12 === 0 ? 12 : p.hour % 12}:${two(p.minute)} ${p.hour < 12 ? 'AM' : 'PM'}`
}

/* ══ 1 · out and back ═══════════════════════════════════════════════════════════════════════ */

artisan('mobile:test-accounts')
await fetch(`${API}/auth/otp/request`, { method: 'POST', headers: json, body: JSON.stringify({ estate: ESTATE, channel: 'email', destination: RESIDENT_EMAIL }) })
const code = artisan('mobile:signin-code', 'resident', '--plain').trim().split(/\s+/).pop()
const verified = await (await fetch(`${API}/auth/otp/verify`, { method: 'POST', headers: json, body: JSON.stringify({ estate: ESTATE, channel: 'email', destination: RESIDENT_EMAIL, code, device_uid: `time-check-${randomUUID()}`, platform: 'android' }) })).json()
const token = verified.token
if (!token) { console.log('FAIL  could not sign the test resident in'); process.exit(1) }
const auth = { ...json, Authorization: `Bearer ${token}` }

// The zone is the SERVER's, never this laptop's. Until the estate's own zone arrives the app shows the one default.
const zone = verified.estate?.timezone
say(typeof zone === 'string' && zone.includes('/'), `the estate answers its IANA zone: ${zone ?? '(none — the app would be left on its default)'}`)
const siteZone = zone ?? 'America/Jamaica'

// Tomorrow, 2:00–4:00 PM AT THE ESTATE, written the way the app writes it.
const tomorrow = new Date(Date.now() + 86_400_000)
const day = new Date(partsAt(tomorrow.getTime(), siteZone).wall).toISOString().slice(0, 10)
const from = atSiteZone(day, 14 * 60, siteZone)
const to = atSiteZone(day, 16 * 60, siteZone)

const issued = await fetch(`${API}/visitor-passes`, { method: 'POST', headers: { ...auth, ...stamp() }, body: JSON.stringify({ category: 'single', visitor_name: 'Time Roundtrip Check', valid_from: from, valid_to: to }) })
const pass = await issued.json()
say(issued.status === 201, `a pass is issued for ${day} 2–4 PM at the estate (${issued.status})`)
if (issued.status === 201) {
  const same = (sent, got) => new Date(sent).getTime() === new Date(got).getTime()
  say(same(from, pass.valid_from), `valid_from: sent ${from} · came back ${pass.valid_from}${same(from, pass.valid_from) ? '' : `  — ${(new Date(pass.valid_from) - new Date(from)) / 3_600_000} h out`}`)
  say(same(to, pass.valid_to), `valid_to:   sent ${to} · came back ${pass.valid_to}${same(to, pass.valid_to) ? '' : `  — ${(new Date(pass.valid_to) - new Date(to)) / 3_600_000} h out`}`)
  say(time12(pass.valid_from, siteZone) === '2:00 PM', `and it READS "${time12(pass.valid_from, siteZone)}" at the estate — the time the resident picked`)
  const listed = (await (await fetch(`${API}/visitor-passes`, { headers: auth })).json()).items?.find((p) => String(p.id) === String(pass.id))
  say(!!listed && same(from, listed.valid_from), `the list agrees: ${listed?.valid_from}`)
  const cancelled = await fetch(`${API}/visitor-passes/${pass.id}/cancel`, { method: 'POST', headers: { ...auth, ...stamp() }, body: '{}' })
  say(cancelled.status === 200, `the check's own pass is cancelled again (${cancelled.status})`)
}

/* ══ 2 · the server's word about a shift ════════════════════════════════════════════════════ */

// The durable test guard, on the handset the flow checks always use — the same UID, so this re-binds
// that handset rather than raising a rebind request against anyone's phone. GS-1041 is never touched.
artisan('mobile:test-accounts', '--fresh-shift')
const guardCode = artisan('mobile:signin-code', 'guard', `--guard=${GUARD}`, '--plain').trim().split(/\s+/).pop()
const enrolled = await (await fetch(`${API}/devices/enrol`, { method: 'POST', headers: json, body: JSON.stringify({ enrolment_code: guardCode, device_uid: UID, platform: 'android', public_key: randomBytes(32).toString('base64url'), label: 'time-roundtrip' }) })).json()

if (!enrolled.token) {
  say(false, `could not enrol ${GUARD} to read a shift: ${enrolled.error?.code ?? 'no token'} ${enrolled.error?.message ?? ''}`)
} else {
  const siteTimezone = enrolled.site?.timezone
  say(typeof siteTimezone === 'string' && siteTimezone.includes('/'), `the enrolment answers the site's IANA zone: ${siteTimezone ?? '(none)'}`)
  const current = await (await fetch(`${API}/shifts/me/current`, { headers: { ...json, Authorization: `Bearer ${enrolled.token}` } })).json()
  const shift = current.shift ?? null
  const shiftZone = shift?.site?.timezone ?? siteTimezone
  say(!!shift, `the guard's current shift is readable${shift ? ` (#${shift.id})` : ''}`)
  if (shift) {
    say(typeof shiftZone === 'string' && shiftZone.includes('/'), `it carries the site's zone: ${shiftZone ?? '(none)'}`)
    say(!Number.isNaN(new Date(shift.rostered_start).getTime()) && /Z$|[+-]\d{2}:\d{2}$/.test(shift.rostered_start), `rostered_start is a full instant: ${shift.rostered_start}`)
    // The work order's own case, against the zone the server just gave this handset.
    const reads = time12('2026-09-21T12:00:00Z', shiftZone ?? 'America/Jamaica')
    say(reads === '7:00 AM', `12:00Z reads "${reads}" in ${shiftZone} — the roster's own 7 AM`)
  }
}

/* ══ 3 · on the glass: the Guard App's web build, from Tokyo ════════════════════════════════ */

const browser = await chromium.launch()
try {
  // A handset whose OWN zone is fourteen hours from Kingston, on the other side of the date line: if
  // anything read the phone's clock the line below would be half a day out.
  const context = await browser.newContext({ viewport: { width: 397, height: 856 }, timezoneId: 'Asia/Tokyo' })
  const page = await context.newPage()
  // guard-app-02 · Clock In. Its shift is held the way the server holds one: 2026-09-04T12:00:00Z → 00:00Z.
  await page.goto(`${GUARD_APP}/clock-in?fidelity=guard-app-02`, { waitUntil: 'domcontentloaded', timeout: 240000 })
  await page.waitForSelector('html[data-fidelity-ready]', { timeout: 60000 })
  const drawn = (await page.locator('body').innerText()).replace(/\s+/g, ' ')
  say(drawn.includes('7:00 AM – 7:00 PM'), `the Guard App draws a 12:00Z shift as "7:00 AM – 7:00 PM" from a phone set to Asia/Tokyo`)
  say(!/9:00 PM|2:00 AM|12:00 PM/.test(drawn), 'and never the handset’s own clock, nor UTC')
  await context.close()
} catch (e) {
  say(false, `the Guard App's web build could not be read (is Metro running on :8081?) — ${String(e.message).split('\n')[0]}`)
} finally {
  await browser.close()
}

console.log(`${failed ? 'FAIL' : 'PASS'}  one clock: a time keeps its instant through the API and is read in the SITE's zone`)
process.exit(failed ? 1 : 0)

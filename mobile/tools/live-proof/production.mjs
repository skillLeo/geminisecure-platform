#!/usr/bin/env node
/**
 * THE PHONE SIDE OF THE C8 PROOF, ON PRODUCTION (DESIGN §11.4 option 2, §11.5; M11 §9.2).
 *
 * Every change a phone must see without a refresh, timed from the moment the actor's request is sent to the
 * moment the receiver's socket delivers it — and the reconnect test: a receiver cut off while three changes are
 * made gets all three, in order, once, within 5 s of coming back.
 *
 * LABELLED HONESTLY: "phone (HEADLESS — the phones' own API and socket protocol, no UI)". The receivers are the
 * phones' protocol exactly as MOBILE_HANDOFF.md states it — live.config, ONE live.auth per socket, Pusher on the
 * API's own host, private-live.{name}, live.catch_up with a cursor per channel and stream — run from Node. This
 * proves transport, authorisation, the channels each change reaches and catch-up; it does not prove rendering.
 *
 * WHO. The store-review demonstration accounts only, on the demonstration estate: two sessions of the reviewer
 * resident (two phones of one household: "A" acts, "B" receives) and the reviewer guard. Enrolling the guard
 * here binds this run's key to them and ends the guard's other session (DeviceEnrolment::revoke) — re-enrol the
 * Guard App afterwards with the same demo code. Every session is signed out at the end.
 *
 *   GS_RESIDENT_EMAIL, GS_RESIDENT_CODE   the reviewer resident's address and static code
 *   GS_GUARD_CODE                          the reviewer guard's static enrolment code
 *   GS_API                                 default https://geminisl.com/api/v1
 *   GS_RUNS                                default 5
 *   GS_OUT                                 the results file (JSON), default ./live-proof-production.json
 *
 * Nothing secret is printed: not a code, not a token, not a grant.
 *
 *   node tools/live-proof/production.mjs
 */

import { createRequire } from 'node:module'
import { generateKeyPairSync, randomUUID } from 'node:crypto'
import { writeFileSync } from 'node:fs'

const require = createRequire(import.meta.url)
const Pusher = require('pusher-js/node')

/*
 * THE ORIGIN AN ANDROID PHONE SENDS. Reverb admits only its allowed origins (4009 "Origin not allowed" otherwise).
 * React Native's Android WebSocket sends the socket URL's own scheme and host (packages/core/live/socket.ts), so this
 * client sends exactly that — `https://` + the API's host — and nothing a phone would not.
 */
const WebSocketBase = Pusher.Runtime.getWebSocketAPI()
Pusher.Runtime.getWebSocketAPI = () =>
  class PhoneOriginWebSocket extends WebSocketBase {
    constructor(url) {
      super(url, null, { headers: { origin: `https://${new URL(process.env.GS_API ?? 'https://geminisl.com/api/v1').hostname}` } })
    }
  }

const API = process.env.GS_API ?? 'https://geminisl.com/api/v1'
const RUNS = Number(process.env.GS_RUNS ?? 5)
const OUT = process.env.GS_OUT ?? 'live-proof-production.json'
const WAIT_MS = 15_000
const need = (name) => {
  const v = process.env[name]
  if (!v) throw new Error(`${name} is not set`)
  return v
}

const now = () => Date.now()
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const median = (xs) => {
  const s = [...xs].sort((a, b) => a - b)
  return s.length === 0 ? null : s.length % 2 ? s[(s.length - 1) / 2] : (s[s.length / 2 - 1] + s[s.length / 2]) / 2
}

/* ── the API ───────────────────────────────────────────────────────────────────────────────────── */

async function call(method, path, { token, body, idempotent } = {}) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Device-Time': new Date().toISOString() }
  if (token) headers.Authorization = `Bearer ${token}`
  if (idempotent) headers['Idempotency-Key'] = randomUUID()
  const res = await fetch(`${API}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
  const text = await res.text()
  let json = null
  try {
    json = text ? JSON.parse(text) : null
  } catch {
    json = null
  }
  if (!res.ok) {
    const code = json?.error?.code ?? res.status
    const message = json?.error?.message ?? text.slice(0, 200)
    throw new Error(`${method} ${path} → ${res.status} ${code}: ${message}`)
  }
  return json?.data && !Array.isArray(json.data) && Object.keys(json).length === 1 ? json.data : json
}

async function demoEstate() {
  const list = await call('GET', '/estates')
  const estates = Array.isArray(list) ? list : (list?.estates ?? list?.data ?? [])
  const demo = estates.find((e) => e.demo === true || e.is_demo === true || /demo/i.test(String(e.name ?? '')))
  if (!demo) throw new Error('no demonstration estate is listed')
  return String(demo.id ?? demo.estate ?? demo.key)
}

async function signInResident(estate, label) {
  const email = need('GS_RESIDENT_EMAIL')
  await call('POST', '/auth/otp/request', { body: { estate, channel: 'email', destination: email } })
  const answer = await call('POST', '/auth/otp/verify', {
    body: { estate, channel: 'email', destination: email, code: need('GS_RESIDENT_CODE'), device_uid: `live-proof-${label}-${randomUUID()}`, platform: 'android', device_name: `Live check ${label}` },
  })
  if (!answer?.token) throw new Error(`resident ${label}: no token`)
  return { label: `resident ${label}`, token: answer.token }
}

async function enrolGuard() {
  const { publicKey } = generateKeyPairSync('ed25519')
  const answer = await call('POST', '/devices/enrol', {
    body: { enrolment_code: need('GS_GUARD_CODE'), device_uid: `live-proof-guard-${randomUUID()}`, platform: 'android', public_key: publicKey.export({ format: 'jwk' }).x, label: 'Live check (headless)' },
  })
  if (answer?.status !== 'enrolled' || !answer.token) throw new Error(`guard: ${answer?.status ?? 'no answer'}`)
  return { label: 'guard', token: answer.token }
}

/* ── a receiver: the phones' socket and catch-up ──────────────────────────────────────────────── */

async function receiver(session) {
  const config = await call('GET', '/live/config', { token: session.token })
  const ws = config.ws
  const channels = config.channels.map((c) => ({ name: c.name, streams: c.streams ?? [] }))
  const cursors = new Map(channels.map((c) => [c.name, {}]))
  const epochs = new Map(channels.map((c) => [c.name, {}]))
  const messages = [] // every live message: {at, channel, env}
  const waiters = new Set()
  let pulses = 0

  const note = (channel, env, via) => {
    const at = now()
    messages.push({ at, channel, env, via })
    if (env.s && typeof env.q === 'number') {
      const cur = cursors.get(channel)
      if (cur && (cur[env.s] ?? 0) < env.q) cur[env.s] = env.q
      if (env.ep) epochs.get(channel)[env.s] = env.ep
    }
    for (const w of [...waiters]) if (w.match(env, channel)) {
      waiters.delete(w)
      w.resolve({ at, channel, env })
    }
  }

  const customHandler = async ({ socketId, channelName }, callback) => {
    try {
      const answer = await call('POST', '/live/auth', { token: session.token, body: { socket_id: socketId, channel_names: [channelName] } })
      const grant = answer.channels.find((c) => c.name === channelName)
      if (!grant || grant.refused) return callback(new Error('refused'), null)
      callback(null, { auth: grant.auth, channel_data: grant.channel_data ?? undefined })
    } catch (e) {
      callback(e, null)
    }
  }

  const refused = []
  const client = new Pusher(ws.key, {
    cluster: '',
    wsHost: new URL(API).hostname,
    wsPort: ws.port,
    wssPort: ws.port,
    wsPath: ws.path ?? '',
    forceTLS: ws.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    enableStats: false,
    channelAuthorization: { customHandler },
  })

  const subscribed = channels.map(
    (c) =>
      new Promise((resolve) => {
        const ch = client.subscribe(`private-live.${c.name}`)
        ch.bind('pusher:subscription_succeeded', () => resolve(true))
        ch.bind('pusher:subscription_error', () => {
          refused.push(c.name)
          resolve(false)
        })
        ch.bind('live', (data) => {
          const env = typeof data === 'string' ? JSON.parse(data) : data
          note(c.name, env, 'socket')
        })
      }),
  )
  const pulse = client.subscribe(config.pulse ?? 'live-pulse')
  pulse.bind_global((event) => {
    if (!event.startsWith('pusher:')) pulses++
  })
  const connected = await new Promise((resolve) => {
    if (client.connection.state === 'connected') return resolve(true)
    client.connection.bind('connected', () => resolve(true))
    setTimeout(() => resolve(false), WAIT_MS)
  })
  if (!connected) throw new Error(`${session.label}: the socket did not connect (state ${client.connection.state})`)

  await Promise.race([Promise.all(subscribed), sleep(WAIT_MS)])

  const catchUp = async () => {
    const body = { channels: channels.map((c) => ({ name: c.name, c: cursors.get(c.name).c ?? null, e: cursors.get(c.name).e ?? null, ep: epochs.get(c.name) })) }
    const answer = await call('POST', '/live/catch-up', { token: session.token, body })
    for (const ch of answer.channels) {
      if (ch.status === 'reset') {
        cursors.set(ch.name, { ...ch.head })
        epochs.set(ch.name, { ...(ch.ep ?? {}) })
      }
    }
    return answer
  }
  // The first cursors: whatever the log's head is now (a first catch-up answers `reset` with the head).
  await catchUp()

  return {
    label: session.label,
    channels,
    refused,
    get pulses() {
      return pulses
    },
    connected: () => client.connection.state === 'connected',
    waitFor(match, timeout = WAIT_MS) {
      return new Promise((resolve) => {
        const w = { match, resolve }
        waiters.add(w)
        setTimeout(() => {
          if (waiters.delete(w)) resolve(null)
        }, timeout)
      })
    },
    since: (t) => messages.filter((m) => m.at >= t),
    cursorsNow: () => structuredClone(Object.fromEntries(cursors)),
    catchUp,
    goOffline() {
      client.disconnect()
    },
    async goOnline() {
      client.connect()
      const t0 = now()
      while (client.connection.state !== 'connected' && now() - t0 < WAIT_MS) await sleep(25)
    },
    close() {
      client.disconnect()
    },
  }
}

/* ── the rows ──────────────────────────────────────────────────────────────────────────────────── */

const passBody = (label) => {
  const from = new Date(now() - 60_000)
  const to = new Date(now() + 2 * 3600_000)
  return { category: 'single', visitor_name: `Live check ${label}`, purpose: 'Live-update check (automated)', valid_from: from.toISOString(), valid_to: to.toISOString() }
}

const about = (entity, id) => (env) => env.e === entity && String(env.i) === String(id)
const aboutType = (entity, id, type) => (env) => about(entity, id)(env) && (type instanceof RegExp ? type.test(env.t) : env.t === type)

/**
 * One timed change: t0 when the actor's request is sent, then the first message about the record at each
 * receiver. The record's id is known only from the answer, and a message can beat the answer home, so each
 * receiver is asked, once the id is known, for the first matching message since t0 — already logged, or next.
 */
async function timed(label, act, receivers, matchOf) {
  const t0 = now()
  const result = await act()
  const committed = now()
  const match = matchOf(result.__id)
  const found = await Promise.all(receivers.map((r) => r.since(t0).find((m) => match(m.env)) ?? r.waitFor(match)))
  return receivers.map((r, k) => ({
    row: label,
    receiver: r.label,
    seconds: found[k] ? (found[k].at - t0) / 1000 : null,
    after_answer: found[k] ? (found[k].at - committed) / 1000 : null,
    type: found[k]?.env.t ?? null,
    channel: found[k]?.channel ?? null,
    id: result.__id,
    result,
  }))
}

async function main() {
  const estate = process.env.GS_ESTATE ?? (await demoEstate())
  console.log(`estate: ${estate}`)

  const a = await signInResident(estate, 'A')
  const b = await signInResident(estate, 'B')
  const g = await enrolGuard()
  const sessions = [a, b, g]

  const B = await receiver(b)
  const G = await receiver(g)
  console.log(`resident B holds ${B.channels.length} channels (${B.refused.length} refused); the guard ${G.channels.length} (${G.refused.length} refused)`)

  const rows = []
  const cleanup = { passes: [], tickets: [] }

  try {
    /*
     * Who hears what, as D-169 decides it: an issued pass reaches the household; its admission reaches the
     * household; a cancelled or used pass reaches the household AND the estate's passes channel, which every gate
     * handset holds. A pass is named on the wire by its PUBLIC id (`pass_id`, a UUID), never its number or token.
     */
    for (let run = 1; run <= RUNS; run++) {
      // 1. A pass issued by one phone of the household: the household's other phone.
      rows.push(
        ...(await timed('pass issued', async () => {
          const p = await call('POST', '/visitor-passes', { token: a.token, body: passBody(`${run}a`), idempotent: true })
          cleanup.passes.push(p.id)
          return { __id: p.pass_id, number: p.id, code: p.code, token: p.token }
        }, [B], (id) => about('pass', id))),
      )
      const admitted = rows.at(-1).result
      await sleep(1000)

      // 2. The visitor admitted at the gate: the household's phones, and every gate handset (the pass is used).
      rows.push(
        ...(await timed('visitor admitted at the gate', async () => {
          const v = await call('POST', '/gate/verify', { token: g.token, body: { pass: admitted.token } })
          if (!v.admit_allowed) throw new Error(`gate/verify said ${v.verdict}`)
          await call('POST', '/gate/entry', { token: g.token, body: { category: 'Visitor', pass_id: v.pass.pass_id }, idempotent: true })
          return { __id: admitted.__id }
        }, [B], (id) => about('pass', id))),
      )
      await sleep(1000)

      // 3. A pass cancelled: every gate handset must refuse it at once, and the household's other phone shows it.
      const q = await call('POST', '/visitor-passes', { token: a.token, body: passBody(`${run}b`), idempotent: true })
      cleanup.passes.push(q.id)
      await sleep(1500)
      rows.push(
        ...(await timed('pass cancelled', async () => {
          await call('POST', `/visitor-passes/${q.id}/cancel`, { token: a.token, idempotent: true })
          return { __id: q.pass_id }
        }, [B, G], (id) => aboutType('pass', id, /cancel|revoked/))),
      )
      await sleep(1000)
    }

    // 4. A maintenance request (three runs, not five: each one stays in the demonstration estate's queue).
    for (let run = 1; run <= Math.min(3, RUNS); run++) {
      rows.push(
        ...(await timed('maintenance request reported', async () => {
          const t = await call('POST', '/tickets', { token: a.token, body: { title: `Live-update check ${run} (automated, please ignore)`, category: 'other', description: 'Sent by the live-update check; nothing needs doing.' }, idempotent: true })
          cleanup.tickets.push(t.id)
          return { __id: t.id }
        }, [B], (id) => about('ticket', id))),
      )
      await sleep(1000)
    }

    /* ── C6: cut off, three changes elsewhere, back — all three, in order, once, within 5 s ── */
    const reconnect = []
    for (const [R, changes] of [
      [B, 'resident'],
      [G, 'guard'],
    ]) {
      const before = R.cursorsNow()
      R.goOffline()
      await sleep(1000)
      const made = []
      const p1 = await call('POST', '/visitor-passes', { token: a.token, body: passBody(`c6-${changes}-1`), idempotent: true })
      made.push({ entity: 'pass', id: p1.id, what: 'pass issued' })
      await call('POST', `/visitor-passes/${p1.id}/cancel`, { token: a.token, idempotent: true })
      made.push({ entity: 'pass', id: p1.id, what: 'pass cancelled' })
      const p2 = await call('POST', '/visitor-passes', { token: a.token, body: passBody(`c6-${changes}-2`), idempotent: true })
      made.push({ entity: 'pass', id: p2.id, what: 'pass issued' })
      cleanup.passes.push(p1.id, p2.id)
      await sleep(1500)

      const back = now()
      await R.goOnline()
      const answer = await R.catchUp()
      const done = now()
      const events = answer.channels.filter((c) => c.status === 'ok').flatMap((c) => (c.events ?? []).map((e) => ({ ...e, channel: c.name })))
      const ours = events.filter((e) => e.e === 'pass' && [String(p1.pass_id), String(p2.pass_id)].includes(String(e.i)))
      const perChannel = new Map()
      for (const e of ours) perChannel.set(`${e.channel}/${e.s}`, [...(perChannel.get(`${e.channel}/${e.s}`) ?? []), e.q])
      const ordered = [...perChannel.values()].every((qs) => qs.every((v, k) => k === 0 || qs[k - 1] < v))
      const keys = ours.map((e) => `${e.channel}/${e.s}/${e.q}`)
      const duplicates = keys.length - new Set(keys).size
      reconnect.push({
        receiver: R.label,
        cursors_before: before,
        changes: made.map((m) => m.what),
        caught_up: ours.map((e) => ({ t: e.t, id: e.i, q: e.q, channel: e.channel })),
        // Catch-up merges by record (DESIGN §5.1): a pass issued and then cancelled may come back as ONE event, its latest.
        p1_last: ours.filter((e) => String(e.i) === String(p1.pass_id)).at(-1)?.t ?? null,
        p1_last_summary: ours.filter((e) => String(e.i) === String(p1.pass_id)).at(-1)?.sum ?? null,
        p2_seen: ours.some((e) => String(e.i) === String(p2.pass_id)),
        ordered,
        duplicates,
        seconds: (done - back) / 1000,
        relay: answer.relay ?? null,
      })
    }

    const summary = {}
    for (const r of rows) {
      const k = `${r.row} → ${r.receiver}`
      summary[k] ??= { row: r.row, receiver: r.receiver, seconds: [], types: new Set(), channels: new Set(), missed: 0 }
      if (r.seconds === null) summary[k].missed++
      else summary[k].seconds.push(r.seconds)
      if (r.type) summary[k].types.add(r.type)
      if (r.channel) summary[k].channels.add(r.channel.replace(/\.\d+(\.|$)/g, '.{id}$1'))
    }
    const table = Object.values(summary).map((s) => ({
      row: s.row,
      receiver: s.receiver,
      runs: s.seconds.length + s.missed,
      missed: s.missed,
      median_s: median(s.seconds),
      max_s: s.seconds.length ? Math.max(...s.seconds) : null,
      types: [...s.types],
      channels: [...s.channels],
    }))

    const out = { at: new Date().toISOString(), api: API, estate, receiver_kind: 'phone (HEADLESS — the phones\' own API and socket protocol, no UI)', pulses: { resident: B.pulses, guard: G.pulses }, table, reconnect, rows: rows.map(({ result, ...r }) => r) }
    writeFileSync(OUT, JSON.stringify(out, null, 2))

    console.log('\nentity/action → receiver | runs | median s | max s | missed | event')
    for (const t of table) console.log(`${t.row} → ${t.receiver} | ${t.runs} | ${t.median_s?.toFixed(3) ?? '—'} | ${t.max_s?.toFixed(3) ?? '—'} | ${t.missed} | ${t.types.join(', ')}`)
    console.log('\nreconnect (C6):')
    for (const r of reconnect) console.log(`${r.receiver}: ${r.caught_up.length} events for the three changes, in order ${r.ordered}, duplicates ${r.duplicates}, ${r.seconds.toFixed(3)} s from reconnect to caught up`)
    console.log(`\npulses heard: resident ${B.pulses}, guard ${G.pulses}`)
    console.log(`written: ${OUT}`)
  } finally {
    B.close()
    G.close()
    for (const s of sessions) await call('DELETE', '/session', { token: s.token }).catch(() => {})
  }
}

main().catch((e) => {
  console.error(e.message)
  process.exit(1)
})

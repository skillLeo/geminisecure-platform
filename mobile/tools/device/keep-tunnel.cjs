/**
 * KEEPS THE TEST SERVER'S PUBLIC ADDRESS ALIVE — and keeps it the SAME address.
 *
 *   node tools/device/keep-tunnel.cjs            (start-apk-test-server.ps1 runs it)
 *
 * The test APKs have the API's address built in (eas.json, preview + emulator). The free Cloudflare
 * tunnel handed out a new random address on every start, and was shut down overnight on 2026-09-22,
 * which left both client APKs pointing at nothing. localtunnel can ask for a fixed subdomain, so:
 *
 *   - it asks for WANT and nothing else. Handed any other address (the name is still held from a
 *     connection that has not timed out yet), it lets go and asks again ten seconds later — a
 *     tunnel on the wrong address is worse than none, because it looks as if it is working;
 *   - it reconnects by itself when the tunnel closes or errors;
 *   - every minute it calls the API THROUGH the public address, as a phone would, and after three
 *     misses in a row it tears the tunnel down and builds it again.
 *
 * The Host header is rewritten to 127.0.0.1 (`local_host`): the API's central domains are 127.0.0.1
 * and localhost, and a request carrying the tunnel's own name would be read as an estate that does
 * not exist.
 *
 * localtunnel is not a dependency of the apps. It lives in the ignored .tools folder:
 *   npm install --prefix .tools/lt localtunnel@2.0.2
 */

const path = require('node:path')
const localtunnel = require(path.join(__dirname, '..', '..', '.tools', 'lt', 'node_modules', 'localtunnel'))

const SUBDOMAIN = process.env.GS_TUNNEL_SUBDOMAIN || 'geminisecure-umar-test'
const PORT = Number(process.env.GS_API_PORT || 8001)
const WANT = `https://${SUBDOMAIN}.loca.lt`
const PROBE = `${WANT}/api/v1/estates`

const stamp = () => new Date().toLocaleTimeString()
const say = (line) => console.log(`[${stamp()}] ${line}`)
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

let tunnel = null
let misses = 0

async function open() {
  for (;;) {
    try {
      const t = await localtunnel({ port: PORT, local_host: '127.0.0.1', subdomain: SUBDOMAIN })
      if (t.url !== WANT) {
        say(`Handed ${t.url} instead of ${WANT} (the name is still held). Letting go; asking again in 10 s.`)
        t.close()
        await sleep(10_000)
        continue
      }
      say(`UP  ${WANT}  ->  http://127.0.0.1:${PORT}`)
      misses = 0
      t.on('close', () => { if (tunnel === t) { tunnel = null; say('Tunnel closed. Reconnecting ...'); void open() } })
      t.on('error', (e) => { say(`Tunnel error: ${e && e.message}. Reconnecting ...`); t.close() })
      tunnel = t
      return
    } catch (e) {
      say(`Could not open the tunnel (${e && e.message}). Trying again in 10 s.`)
      await sleep(10_000)
    }
  }
}

async function probe() {
  if (!tunnel) return
  try {
    const r = await fetch(PROBE, { headers: { Accept: 'application/json', 'User-Agent': 'okhttp/4.12.0' }, signal: AbortSignal.timeout(20_000) })
    if (r.ok) { if (misses) say(`Answering again (${r.status}).`); misses = 0; return }
    misses++
    say(`The public address answered ${r.status} (${misses}/3).`)
  } catch (e) {
    misses++
    say(`The public address did not answer (${misses}/3): ${e && e.message}`)
  }
  if (misses >= 3 && tunnel) {
    say('Three misses in a row: rebuilding the tunnel.')
    const t = tunnel
    tunnel = null
    t.close()
    misses = 0
    void open()
  }
}

say(`Keeping ${WANT} open for the API on port ${PORT}. Leave this window open.`)
void open()
setInterval(() => void probe(), 60_000)

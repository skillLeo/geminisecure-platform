/**
 * GATE â€” the server's address is ONE configuration value.
 *
 * Moving off the laptop must be a change to `EXPO_PUBLIC_API_URL` (an env file, or the EAS profile) and
 * nothing else. So, in everything that ships:
 *
 *   1  `EXPO_PUBLIC_API_URL` is read in exactly one place â€” packages/core/lib/env.ts (`apiUrl()`)
 *   2  no source file holds a network address of its own: no IPv4 literal, no `localhost`, no
 *      `http(s)://host:port` pointing at an API (test files and comments are not shipped and are skipped)
 *   3  each app's app.config.ts REFUSES a production build without an https address, and no EAS profile pins a
 *      laptop address â€” the value comes from the environment (`.env.*` on the laptop, `eas env:create` for a build)
 *
 *   node tools/gates/one-api-url.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { refusesProductionWithoutHttps } from './api-url-rules.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'generated', 'lifted', '__gateprobe__'])
const ONLY_READER = 'packages/core/lib/env.ts'

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.(ts|tsx)$/.test(name) && !/\.test\.tsx?$/.test(name) ? [full] : []
  })

const offenders = []
let scanned = 0
const roots = [path.join(ROOT, 'packages', 'core'), ...['guard', 'resident'].flatMap((app) => [path.join(ROOT, 'apps', app, 'src'), path.join(ROOT, 'apps', app, 'app')])]

for (const file of roots.flatMap(walk)) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  scanned++
  let inBlock = false
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/^\s*\/\*/.test(line)) inBlock = true
    const comment = inBlock || /^\s*(\*|\/\/)/.test(line)
    if (/\*\//.test(line)) inBlock = false
    if (comment) return
    const code = line.replace(/\/\/.*$/, '')
    if (/EXPO_PUBLIC_API_URL/.test(code) && rel !== ONLY_READER) offenders.push(`${rel}:${i + 1}  reads EXPO_PUBLIC_API_URL â€” only ${ONLY_READER} may; call apiUrl()`)
    if (/['"`][^'"`]*\b(\d{1,3}\.){3}\d{1,3}\b[^'"`]*['"`]/.test(code) || /['"`](https?|wss?):\/\/(localhost|[^'"`/]+:\d{2,5})/.test(code)) offenders.push(`${rel}:${i + 1}  holds a network address of its own â€” the server's address is one configuration value\n      ${code.trim().slice(0, 140)}`)
  })
}

for (const app of ['guard', 'resident']) {
  const config = path.join(ROOT, 'apps', app, 'app.config.ts')
  const source = existsSync(config) ? readFileSync(config, 'utf8') : ''
  // The value arrives from the environment (an env file on the laptop; `eas env:create` for a build). What the
  // config must do is REFUSE a production build that has no https address â€” never fall back to one of its own.
  // The rule itself lives in api-url-rules.mjs, because `gate:store` checks the same thing and two copies drift.
  if (!refusesProductionWithoutHttps(source)) offenders.push(`apps/${app}/app.config.ts does not refuse a production build without an https EXPO_PUBLIC_API_URL`)
  const eas = path.join(ROOT, 'apps', app, 'eas.json')
  if (!existsSync(eas)) { offenders.push(`apps/${app}/eas.json is missing`); continue }
  for (const [name, profile] of Object.entries(JSON.parse(readFileSync(eas, 'utf8')).build ?? {})) {
    const url = profile?.env?.EXPO_PUBLIC_API_URL
    if (typeof url === 'string' && /\b(\d{1,3}\.){3}\d{1,3}\b|localhost/.test(url)) offenders.push(`apps/${app}/eas.json Â· profile "${name}" pins a laptop address (${url}) â€” set it with \`eas env:create\` instead`)
  }
}

if (offenders.length > 0) {
  console.log(`GATE one-api-url Â· FAIL â€” ${offenders.length} place(s) that would need a code change to move the server:`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE one-api-url Â· ${scanned} files: the server's address is read in one place and set only by configuration`)

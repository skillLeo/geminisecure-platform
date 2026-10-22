/**
 * Prints the server's own contract for one or more endpoints, compactly.
 *   node tools/api/describe.mjs shifts.clock_out orders.current
 *   node tools/api/describe.mjs --grep gate.        (every endpoint whose name contains "gate.")
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const cat = JSON.parse(readFileSync(path.join(ROOT, '_design', 'api', 'catalogue.json'), 'utf8')).endpoints
const args = process.argv.slice(2)
const names = args[0] === '--grep' ? Object.keys(cat).filter((n) => n.includes(args[1])) : args
const map = (v) => (v && !Array.isArray(v) ? Object.entries(v) : [])
const camel = (n) => n.replace(/[._-]+([a-z0-9])/gi, (_, c) => c.toUpperCase())

for (const n of names) {
  const e = cat[n]
  if (!e) { console.log(`?? ${n} — not in the catalogue`); continue }
  const kind = e.method === 'GET' ? 'Query' : 'Mutation'
  console.log(`\n=== ${n}   ${e.method} /${e.uri}   ability ${e.capability ? e.capability + ':' + e.access : 'none (no token)'}${e.write ? '   WRITE' : ''}${e.opaque ? '   OPAQUE' : ''}`)
  console.log(`    hook: use${camel(n)[0].toUpperCase() + camel(n).slice(1)}${kind}   ·   types: Api.${camel(n)[0].toUpperCase() + camel(n).slice(1)}Request / …Response`)
  console.log(`    ${e.summary}`)
  for (const [k, v] of map(e.request)) console.log(`    > ${k}: ${v}`)
  for (const [k, v] of map(e.response)) console.log(`    < ${k}: ${v}`)
  for (const [k, v] of map(e.errors)) console.log(`    ! ${k}: ${v}`)
  console.log(`    offline: ${e.offline}`)
}

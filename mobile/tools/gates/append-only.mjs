/**
 * GATE — INVARIANT 12: incidents, panic/duress events, gate events and the audit trail are APPEND-ONLY.
 *
 *   1  THE CONTRACT   the pulled catalogue (_design/api/catalogue.json) has no route that edits or deletes one:
 *                     for these resources every route is a GET or a POST, and none is named update/destroy/delete.
 *                     (A cancel is a new fact appended to the record — who cancelled, when and why — not an edit.)
 *   2  THE APPS       no screen or hook calls, names or draws one: no generated hook for such a route is used,
 *                     and the feature folders that show these records hold no "Delete"/"Edit"/"Remove" control.
 *
 *   node tools/gates/append-only.mjs
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const RESOURCES = /^(incidents|alerts|duress|gate|patrol|presence|alertness|audit)\./
const FORBIDDEN_NAME = /\.(update|destroy|delete|remove|edit|restore)$/
const FOLDERS = ['apps/guard/src/features/incidents', 'apps/guard/src/features/duress', 'apps/guard/src/features/gate', 'apps/guard/src/features/walkup', 'apps/guard/src/features/patrol', 'apps/resident/src/features/panic', 'apps/resident/src/features/incidents']

const offenders = []

/* 1 — the contract */
const catalogue = JSON.parse(readFileSync(path.join(ROOT, '_design', 'api', 'catalogue.json'), 'utf8'))
const raw = catalogue.endpoints ?? catalogue
const endpoints = Array.isArray(raw) ? raw : Object.entries(raw).map(([name, e]) => ({ name, ...e }))
let checked = 0
for (const e of endpoints) {
  if (!RESOURCES.test(e.name)) continue
  checked++
  const method = String(e.method ?? '').toUpperCase()
  if (!['GET', 'POST'].includes(method)) offenders.push(`catalogue: ${e.name} is ${method} — an append-only record has no PUT, PATCH or DELETE`)
  if (FORBIDDEN_NAME.test(e.name)) offenders.push(`catalogue: ${e.name} — an append-only record is never edited or deleted`)
}
if (checked === 0) offenders.push('catalogue: no append-only routes were found to check — the catalogue shape changed; fix this gate')

/* 2 — the apps */
const walk = (dir) => (existsSync(dir) ? readdirSync(dir).flatMap((name) => {
  if (name === '__tests__' || name === 'node_modules') return []
  const full = path.join(dir, name)
  return statSync(full).isDirectory() ? walk(full) : /\.tsx?$/.test(name) ? [full] : []
}) : [])

const HOOK = /use(Incidents|Alerts|Duress|Gate\w*|Patrol\w*|Audit\w*)(Update|Destroy|Delete|Remove|Edit)(Mutation)?\b/
// The verb must be ABOUT the record ("Delete this report"). Taking a photo off a report that has not been sent yet is editing a draft, not a record.
const CONTROL = /(aria-label|label|title)\s*=\s*["'`{][^\n]*\b(Delete|Remove|Edit|Amend)\s+(this\s+|the\s+|my\s+)?(report|incident|alert|entry|event|scan|checkpoint)\b/i
const VISIBLE = /\{\s*['"`](Delete|Remove|Edit)( this)? (report|incident|alert|entry|event)['"`]\s*\}/i

for (const folder of FOLDERS) {
  for (const file of walk(path.join(ROOT, folder))) {
    const rel = path.relative(ROOT, file).replace(/\\/g, '/')
    readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
      if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
      if (HOOK.test(line)) offenders.push(`${rel}:${i + 1}  calls an edit/delete route on an append-only record`)
      if (CONTROL.test(line) || VISIBLE.test(line)) offenders.push(`${rel}:${i + 1}  draws an edit/delete control on an append-only record`)
    })
  }
}

if (offenders.length > 0) {
  console.log(`GATE append-only · FAIL (${offenders.length}):`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE append-only · ${checked} incident/alert/gate/patrol routes are GET or POST only, and no screen edits or deletes one`)

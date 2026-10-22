/**
 * GATE — INVARIANT 3, the source half: the Guard App cannot even FORMAT money.
 *
 * The API layer already drops every unlisted key from a guard response (an allowlist), and the
 * gate verdict carries restriction as one boolean. This gate closes the last door: no file in
 * apps/guard may import the money formatter, and none may hold a currency figure or a word that
 * only a ledger uses — with ONE exception, the guard's OWN payslip and hours (their pay, not a
 * household's), which live in `features/payslips/`.
 *
 *   node tools/gates/guard-no-money.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const GUARD = path.join(ROOT, 'apps', 'guard')
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', 'lifted', '__tests__'])
const OWN_PAY = /\/src\/(features\/payslips|fidelity\/payslips?)\b/

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx?$/.test(name) ? [full] : []
  })

const RULES = [
  { why: 'imports the money formatter', re: /import\s*\{[^}]*\bmoney(Sign)?\b[^}]*\}\s*from\s*['"]@gs\/core/ },
  { why: 'a currency figure', re: /(J\$|JMD)\s?\d|\$\d[\d,]*(\.\d\d)?\b/ },
  { why: 'a ledger word on a guard screen', re: /['"`>][^'"`<]*\b(arrears|outstanding balance|overdue|amount due|balance due|owes|owing|invoice|payment plan)\b/i },
]

const offenders = []
for (const file of walk(GUARD)) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  if (OWN_PAY.test('/' + rel)) continue
  const lines = readFileSync(file, 'utf8').split('\n')
  lines.forEach((line, i) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(line)) return // prose explaining the rule is not a breach of it
    const code = line.replace(/\/\/.*$/, '')
    for (const rule of RULES) if (rule.re.test(code)) offenders.push(`${rel}:${i + 1}  ${rule.why}\n      ${code.trim().slice(0, 140)}`)
  })
}

if (offenders.length > 0) {
  console.log(`GATE guard-no-money · FAIL — a guard never sees household money (${offenders.length}):`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log('GATE guard-no-money · no Guard App screen can format, name or hold household money')

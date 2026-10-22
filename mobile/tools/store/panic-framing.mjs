/**
 * GATE — the alert button is never described as reaching the emergency services (Apple guideline 5.1.5).
 *
 * GeminiSecure's alert notifies the PRIVATE security company contracted to the community: the officers on duty
 * and their control room. It is not an emergency dispatch service, and a store listing or a screen that says
 * otherwise is rejected whatever the code does. So, in everything a RESIDENT reads — the Resident App's shipped
 * copy and the store listing text under store/ — these words may not appear in copy:
 *
 *     emergency services · first responders · 911 · dispatch / dispatcher / dispatched
 *
 * …except in the one sentence that says what the button is NOT ("does not contact the police…", "not an
 * emergency service") or that points at the real number (119). A line may opt out with  // framing-ok: <reason>.
 * And the panic feature must offer a working call to the real emergency number.
 *
 * Only TEXT is read (quoted strings and JSX text), so `dispatch(action)` in code is not copy.
 * (The Guard App talks about its own control room and dispatcher — that is its users' vocabulary, not a promise
 * to the public — so it is not scanned.)
 *
 *   node tools/store/panic-framing.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'lifted', 'fidelity', 'generated'])
const FORBIDDEN = /\b(emergency services?|first responders?|911|dispatch(?:er|ers|ed|es)?)\b/i
const ALLOWED_CONTEXT = /\b(not|never|does not|doesn't|do not|cannot|isn't|is not)\b[^.]*\b(emergency services?|first responders?|911|dispatch)/i

const walk = (dir, exts) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full, exts) : exts.test(name) ? [full] : []
  })

/** The human-readable text on a line of TSX: quoted strings and JSX text — never identifiers. */
const copyOf = (line) => [...line.matchAll(/'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\]|\\.)*)`|>([^<>{}]+)</g)].map((m) => m[1] ?? m[2] ?? m[3] ?? m[4] ?? '').filter((t) => /\s/.test(t.trim()))

const offenders = []
let scanned = 0

for (const file of [...walk(path.join(ROOT, 'apps', 'resident', 'src'), /\.(ts|tsx)$/), ...walk(path.join(ROOT, 'apps', 'resident', 'app'), /\.(ts|tsx)$/)]) {
  scanned++
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(line) || /framing-ok:/.test(line)) return
    for (const text of copyOf(line.replace(/\/\/.*$/, ''))) {
      if (FORBIDDEN.test(text) && !ALLOWED_CONTEXT.test(text)) offenders.push(`${path.relative(ROOT, file).replace(/\\/g, '/')}:${i + 1}  "${text.trim().slice(0, 140)}"`)
    }
  })
}

for (const file of walk(path.join(ROOT, 'store'), /\.(md|txt|json)$/)) {
  if (/manifests/.test(file)) continue
  scanned++
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/framing-ok:/.test(line)) return
    if (FORBIDDEN.test(line) && !ALLOWED_CONTEXT.test(line)) offenders.push(`${path.relative(ROOT, file).replace(/\\/g, '/')}:${i + 1}  "${line.trim().slice(0, 140)}"`)
  })
}

// The real number must be one tap away on the panic screens, with or without a connection (a tel: link needs neither).
const panic = walk(path.join(ROOT, 'apps', 'resident', 'src', 'features', 'panic'), /\.(ts|tsx)$/).map((f) => readFileSync(f, 'utf8')).join('\n')
if (!/EMERGENCY_NUMBER\s*=\s*'119'/.test(panic)) offenders.push('apps/resident/src/features/panic: the real emergency number (119) is not defined')
if (!/tel:/.test(panic)) offenders.push('apps/resident/src/features/panic: no tel: call to the real emergency number')

if (offenders.length > 0) {
  console.log(`GATE panic-framing · FAIL — ${offenders.length} place(s) that describe the alert as something it is not:`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE panic-framing · ${scanned} files: the alert is never described as reaching the emergency services, and 119 is one tap away`)

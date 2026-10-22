/**
 * GATE · the earlier darker inks stay out of the Resident App (D1, answered 7 October 2026).
 *
 * The client's design wins: the Resident App draws the design's exact colours — the rd-* names of the palette,
 * reached through apps/resident/src/design/tokens.ts. The SHARED palette's names are what carry the earlier work
 * orders' darker inks into a screen: navy-600, slate-500, slate-600 and amber-700 ship darker than the design draws
 * them, text-primary / text-body / text-placeholder / text-on-dark are the darker inks themselves, and the kit's
 * <Text> maps every other navy and slate name onto those inks on a phone (TEXT_TONE). So in the Resident App a
 * colour written by its shared name, or TEXT_TONE / textColor, is a darker ink reached for by habit.
 *
 * Today's screens are full of them until each one is rebuilt, so this is a RATCHET: every Resident file may hold at
 * most the count in tools/gates/resident-dark-inks.baseline.json, a file that is not in it holds none, and the
 * baseline only ever goes down. Home, My bookings and Language & accessibility keep theirs — the client froze them
 * (E4, E7, E8).
 *
 *   node tools/gates/resident-dark-inks.mjs             the gate
 *   node tools/gates/resident-dark-inks.mjs --update    lower the baseline to today's counts (it never raises one)
 *   node tools/gates/resident-dark-inks.mjs --init      write the first baseline (refuses if one exists)
 *   node tools/gates/resident-dark-inks.mjs --selftest
 */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const BASELINE = path.join(ROOT, 'tools', 'gates', 'resident-dark-inks.baseline.json')
const SCAN = ['apps/resident/app', 'apps/resident/src', 'packages/core/ui/resident'].map((p) => path.join(ROOT, p))
// Generated and dev-only code never ships to a resident: the lifted boards, the dev gallery, the tests.
const SKIP_DIRS = new Set(['node_modules', 'lifted', 'gallery', '__tests__', '__fixtures__', '.expo', 'android', 'ios'])
const EXT = /\.(ts|tsx|js|jsx)$/
const SKIP_FILE = /(\.test\.|\.spec\.|\.d\.ts$)/

const theme = (f) => JSON.parse(readFileSync(path.join(ROOT, 'packages', 'core', 'theme', f), 'utf8'))
const tokens = theme('tokens.json')
const shared = [...Object.keys(tokens.colors), ...Object.keys(tokens.semantic), ...Object.keys(theme('board-only.json').colors)]
const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
const NAMED = new RegExp(`(['"\`])(${shared.map(escape).join('|')})\\1`, 'g')
const MAPPING = /\b(TEXT_TONE|textColor)\b/g

export function count(source) {
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1')
  return [...code.matchAll(NAMED)].length + [...code.matchAll(MAPPING)].length
}

const walk = (dir) =>
  !existsSync(dir)
    ? []
    : readdirSync(dir).flatMap((name) => {
        if (SKIP_DIRS.has(name)) return []
        const full = path.join(dir, name)
        return statSync(full).isDirectory() ? walk(full) : EXT.test(name) && !SKIP_FILE.test(name) ? [full] : []
      })

function measure() {
  const counts = {}
  for (const file of SCAN.flatMap(walk)) {
    const n = count(readFileSync(file, 'utf8'))
    if (n > 0) counts[path.relative(ROOT, file).replace(/\\/g, '/')] = n
  }
  return counts
}

const writeBaseline = (files) => {
  const sorted = Object.fromEntries(Object.entries(files).sort(([a], [b]) => a.localeCompare(b)))
  const total = Object.values(sorted).reduce((a, b) => a + b, 0)
  writeFileSync(BASELINE, JSON.stringify({ note: 'The ratchet for tools/gates/resident-dark-inks.mjs: each Resident file\'s count of shared-palette colour names and TEXT_TONE/textColor uses. It only goes down (run the gate with --update after a rebuild).', total, files: sorted }, null, 2) + '\n', 'utf8')
  return total
}

if (process.argv.includes('--selftest')) {
  const caught = count(`<Text color="slate-500">a</Text>; const k = { backgroundColor: c('navy-600'), color: palette['text-primary'] }; const t = TEXT_TONE`)
  const clean = count(`<Text color={ink.secondary}>a</Text>; const k = { backgroundColor: fill.primary, color: c('rd-navy-900') } // 'slate-500' in a comment`)
  if (caught !== 4 || clean !== 0) {
    console.error(`SELFTEST FAILED · caught ${caught} (want 4), clean ${clean} (want 0)`)
    process.exit(1)
  }
  console.log('SELFTEST resident-dark-inks · 4 darker-ink uses caught, rd-* tokens and comments ignored')
  process.exit(0)
}

const now = measure()

if (process.argv.includes('--init')) {
  if (existsSync(BASELINE)) { console.error('A baseline exists; it only goes down — use --update.'); process.exit(1) }
  console.log(`baseline written: ${writeBaseline(now)} uses in ${Object.keys(now).length} files`)
  process.exit(0)
}

const base = JSON.parse(readFileSync(BASELINE, 'utf8')).files
const offences = []
for (const [file, n] of Object.entries(now)) {
  const allowed = base[file] ?? 0
  if (n > allowed) offences.push(`${file}: ${n} (allowed ${allowed}) — ${n - allowed} new`)
}

if (process.argv.includes('--update')) {
  if (offences.length) { console.error('Refusing to update: the baseline never goes up.\n  ' + offences.join('\n  ')); process.exit(1) }
  const lowered = Object.fromEntries(Object.entries(base).filter(([f]) => now[f]).map(([f, n]) => [f, Math.min(n, now[f])]))
  const before = Object.values(base).reduce((a, b) => a + b, 0)
  console.log(`baseline lowered: ${before} → ${writeBaseline(lowered)}`)
  process.exit(0)
}

if (offences.length) {
  console.error(`GATE FAILED · ${offences.length} Resident file(s) reach for the earlier darker inks:\n`)
  for (const o of offences) console.error('  ' + o)
  console.error('\nDraw with apps/resident/src/design/tokens.ts (ink, fill, line …): the design\'s exact colours (D1).')
  process.exit(1)
}
const total = Object.values(now).reduce((a, b) => a + b, 0)
const baseTotal = Object.values(base).reduce((a, b) => a + b, 0)
console.log(`GATE resident-dark-inks · ${total} uses in ${Object.keys(now).length} files, none new${total < baseTotal ? ` · ${baseTotal - total} fewer than the baseline — run --update to lock them in` : ''}`)

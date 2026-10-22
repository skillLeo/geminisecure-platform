/**
 * GATE — accessibility: every control says what it is, to a screen reader as well as to an eye.
 *
 *   1  a <Pressable> has a `role` (button · tab · radio · checkbox · switch · link …) — or spreads props that carry one;
 *   2  a <Pressable> with NO text inside it (an icon, an image, a bare shape) has an `aria-label`;
 *   3  the old React Native spellings are not used: `accessibilityRole` / `accessibilityState` /
 *      `accessibilityLabel` are not mapped by react-native-web any more, so a control written with them is
 *      silent in the browser harness and inconsistent on a phone. `role` and `aria-*` work on both.
 *
 * "Text inside it" is read from the source between the tag and its closing tag: a <Text>, or a kit
 * component that draws its own label (`label=`). A line can opt out with `// a11y-ok: <why>`.
 *
 *   node tools/gates/a11y-labels.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const SKIP = new Set(['node_modules', 'lifted', '.expo', 'dist', 'android', 'ios', '__tests__'])

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx$/.test(name) ? [full] : []
  })

function openingTag(source, from) {
  let depth = 0
  let quote = null
  for (let i = from; i < source.length; i++) {
    const ch = source[i]
    if (quote) { if (ch === quote && source[i - 1] !== '\\') quote = null; continue }
    if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue }
    if (ch === '{') depth++
    else if (ch === '}') depth--
    else if (ch === '>' && depth === 0 && source[i - 1] !== '=') return source.slice(from, i + 1)
  }
  return source.slice(from)
}

/** The source between an opening <Pressable …> and ITS closing tag (nested Pressables are skipped over). */
function innerOf(source, afterOpen) {
  let depth = 1
  const re = /<Pressable\b|<\/Pressable>/g
  re.lastIndex = afterOpen
  for (let m = re.exec(source); m; m = re.exec(source)) {
    if (m[0] === '</Pressable>') { depth--; if (depth === 0) return source.slice(afterOpen, m.index) }
    else if (!openingTag(source, m.index).endsWith('/>')) depth++
  }
  return source.slice(afterOpen)
}

const SPREAD = /\{\s*\.\.\.\w+/
export function scan(rel, source) {
  const found = []
  const lines = source.split('\n')
  const lineOf = (index) => source.slice(0, index).split('\n').length
  const optedOut = (index) => /\/\/\s*a11y-ok:/.test(lines[lineOf(index) - 1] ?? '')

  lines.forEach((line, i) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
    // accessibilityHint stays: ARIA has no equivalent a phone's screen reader speaks, and it is harmless in a browser.
    if (/\baccessibility(Role|State|Label)\s*=/.test(line) && !/\/\/\s*a11y-ok:/.test(line)) found.push(`${rel}:${i + 1}  uses accessibility* — write role / aria-* instead (they work on the phone AND in the browser)`)
  })

  for (const m of source.matchAll(/<Pressable\b/g)) {
    if (optedOut(m.index)) continue
    const tag = openingTag(source, m.index)
    if (SPREAD.test(tag)) continue
    // Deliberately hidden from a screen reader: a touch target that only forwards focus to the real control inside it.
    if (/\baccessible\s*=\s*\{\s*false\s*\}/.test(tag)) continue
    if (!/\brole\s*=/.test(tag)) found.push(`${rel}:${lineOf(m.index)}  a Pressable with no role`)
    if (/\baria-label\s*=/.test(tag) || /\baria-labelledby\s*=/.test(tag)) continue
    const inner = tag.endsWith('/>') ? '' : innerOf(source, m.index + tag.length)
    if (!/<Text\b|\blabel\s*=/.test(inner)) found.push(`${rel}:${lineOf(m.index)}  an icon-only Pressable with no aria-label`)
  }
  return found
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const offenders = []
  for (const file of [...walk(path.join(ROOT, 'apps')), ...walk(path.join(ROOT, 'packages', 'core', 'ui'))]) {
    offenders.push(...scan(path.relative(ROOT, file).replace(/\\/g, '/'), readFileSync(file, 'utf8')))
  }
  if (offenders.length > 0) {
    console.log(`GATE a11y-labels · FAIL — a control a screen reader cannot name (${offenders.length}):`)
    for (const o of offenders) console.log(`  ${o}`)
    process.exit(1)
  }
  console.log('GATE a11y-labels · every Pressable has a role, and every icon-only one has a label')
}

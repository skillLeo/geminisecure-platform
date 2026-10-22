/**
 * GATE — INVARIANT 11: no dead controls.
 *
 * Every <Pressable> (and every kit button built on it) either DOES something or says why it cannot:
 *
 *   1  a Pressable has a press handler (onPress / onLongPress / onPressIn / onPressOut) — or spreads props
 *      that carry one (`{...props}`, the kit's own wrappers);
 *   2  a handler is never a no-op (`() => undefined`, `() => {}`, `noop`);
 *   3  a kit button that can be `disabled` carries the reason with it (`disabledReason`) — a greyed button
 *      with no explanation is a dead control to the person holding the phone.
 *
 * The board-only drawings behind `isFidelity` are plain <View>s, never Pressables, so they do not appear here.
 * A line can opt out with `// dead-ok: <why>` (e.g. a Pressable that exists only to swallow a touch).
 *
 *   node tools/gates/no-dead-controls.mjs
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

/** The opening tag starting at `from` ("<Name"), brace- and quote-aware, up to its closing ">". */
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

const HANDLER = /\bon(Press|LongPress|PressIn|PressOut)\s*=/
const SPREAD = /\{\s*\.\.\.\w+/
const NOOP = /\bon(?:Press|LongPress)\s*=\s*\{\s*(?:\(\s*\)\s*=>\s*(?:undefined|null|void 0|\{\s*\})|noop)\s*\}/
const KIT_BUTTONS = ['ResidentButton', 'GuardButton', 'Button']

const offenders = []
export function scan(rel, source) {
  const found = []
  const lineOf = (index) => source.slice(0, index).split('\n').length
  const optedOut = (index) => /\/\/\s*dead-ok:/.test(source.split('\n')[lineOf(index) - 1] ?? '')

  for (const m of source.matchAll(/<Pressable\b/g)) {
    if (optedOut(m.index)) continue
    const tag = openingTag(source, m.index)
    if (!HANDLER.test(tag) && !SPREAD.test(tag)) found.push(`${rel}:${lineOf(m.index)}  a Pressable with no press handler`)
    if (NOOP.test(tag)) found.push(`${rel}:${lineOf(m.index)}  a press handler that does nothing`)
  }
  for (const name of KIT_BUTTONS) {
    for (const m of source.matchAll(new RegExp(`<${name}\\b`, 'g'))) {
      if (optedOut(m.index)) continue
      const tag = openingTag(source, m.index)
      // A button that is ALWAYS disabled, with its reason, has nothing to do when pressed — that is honest, not dead.
      const alwaysOff = /\bdisabled(?!\s*=)/.test(tag) && /\bdisabledReason\s*=/.test(tag)
      if (NOOP.test(tag) && !alwaysOff) found.push(`${rel}:${lineOf(m.index)}  a ${name} whose handler does nothing`)
      if (/\bdisabled\b/.test(tag) && !/\bdisabledReason\b/.test(tag) && !SPREAD.test(tag)) found.push(`${rel}:${lineOf(m.index)}  a ${name} that can be disabled without a reason`)
    }
  }
  return found
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  for (const file of [...walk(path.join(ROOT, 'apps')), ...walk(path.join(ROOT, 'packages', 'core', 'ui'))]) {
    const rel = path.relative(ROOT, file).replace(/\\/g, '/')
    offenders.push(...scan(rel, readFileSync(file, 'utf8')))
  }
  if (offenders.length > 0) {
    console.log(`GATE dead-controls · FAIL — every control does something or says why it cannot (${offenders.length}):`)
    for (const o of offenders) console.log(`  ${o}`)
    process.exit(1)
  }
  console.log('GATE dead-controls · every Pressable has a real handler; no kit button is disabled without a reason')
}

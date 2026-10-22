/**
 * THE WORDS A CHECK LOOKS FOR COME OUT OF THE APP'S OWN SOURCE, NOT OUT OF THIS FILE.
 *
 * A device check that says `hasText(nodes, 'Your position is read when you start a
 * shift')` is a second copy of the app's copy. The day somebody improves the sentence,
 * the check fails on hardware for no reason, somebody softens the check, and from then
 * on it proves nothing. That is how a green suite stops catching anything.
 *
 * So the phrases are READ from the modules that define them — the same constants the
 * screens render and the same ones tools/gates/permission-rules.mjs holds Google's
 * policy against. If a constant is renamed or deleted, this throws by name, which is a
 * broken check rather than a silently weakened one.
 *
 * It is a regex over source, not a TypeScript import, because these are .ts modules in
 * an Expo app and this is a plain node script. The regex only ever reads a top-level
 * `export const NAME = '…'`; anything cleverer would deserve a parser.
 */

import { readFileSync, existsSync } from 'node:fs'
import path from 'node:path'
import { ROOT } from './adb.mjs'

/** @returns {string} the string literal assigned to `export const <name>` */
export function constantFrom(relPath, name) {
  const file = path.join(ROOT, relPath)
  if (!existsSync(file)) throw new Error(`copy: ${relPath} is not there — a check is looking for words in a file that has moved`)
  const source = readFileSync(file, 'utf8')

  const re = new RegExp(`export\\s+const\\s+${name}\\s*(?::[^=]+)?=\\s*(['"\`])([\\s\\S]*?)\\1`, 'm')
  const m = re.exec(source)
  if (!m) throw new Error(`copy: ${relPath} no longer exports a string constant called ${name}`)
  return m[2].replace(/\\'/g, "'").replace(/\\"/g, '"')
}

/**
 * A distinctive slice of a sentence, for matching against a screen. The whole sentence
 * would be brittle — a phone wraps, a screen reader joins, a designer adds a comma —
 * so a check matches the opening clause, which is the part that carries the meaning.
 */
export function openingClause(sentence, words = 9) {
  return sentence.split(/\s+/).slice(0, words).join(' ')
}

export const COPY = {
  get guardLocationDisclosure() {
    return constantFrom('apps/guard/src/features/enrolment/setupPermissions.ts', 'LOCATION_DISCLOSURE')
  },
  get guardLocationWithout() {
    return constantFrom('apps/guard/src/features/enrolment/setupPermissions.ts', 'LOCATION_WITHOUT')
  },
}

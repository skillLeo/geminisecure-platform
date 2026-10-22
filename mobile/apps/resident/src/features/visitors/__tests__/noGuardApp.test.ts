/**
 * M24 (web D-223): no resident learns there is a guard app. The Resident App may speak of the guard at the gate, as
 * any resident knows one; it may not say the guard has an app or a phone the system talks to. Read from the shipped
 * copy only (quoted strings and JSX text), never comments, the board files or the fidelity fixtures.
 *
 * Not checked here: "officers' phones" on the alert screen, which comes from packages/core and is frozen with the
 * panic screen until the client answers (POST_DEMO PD-8).
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

const SRC = path.join(__dirname, '..', '..', '..')
const SKIP = new Set(['__tests__', 'lifted', 'fidelity', 'generated'])
const TELLS = /\bguard app\b|\bguard'?s (?:phone|app|device)\b|\bguards'? (?:phones|apps|devices)\b|\bGeminiSecure Guard\b/i

const files = (dir: string): string[] =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? files(full) : /\.(ts|tsx)$/.test(name) ? [full] : []
  })

const copyOf = (line: string): string[] =>
  [...line.matchAll(/'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\]|\\.)*)`|>([^<>{}]+)</g)].map((m) => m[1] ?? m[2] ?? m[3] ?? m[4] ?? '')

it('says nothing in the Resident App that tells a resident the guard has an app or a phone', () => {
  const tells: string[] = []
  for (const file of files(SRC)) {
    readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
      if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
      for (const text of copyOf(line.replace(/\/\/.*$/, ''))) {
        if (TELLS.test(text)) tells.push(`${path.relative(SRC, file)}:${i + 1}  ${text.slice(0, 120)}`)
      }
    })
  }
  expect(tells).toEqual([])
})

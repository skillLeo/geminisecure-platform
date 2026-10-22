/**
 * M50 §4.1 (the user, quoting Hassam): "If it is not mentioned in the design, remove it. If it IS mentioned in the design,
 * make it functional." — "Nothing in this app is left saying 'not available yet'."
 *
 * A refusal says what is closed and the server's own sentence for why ("Dues aren't open to your account" over "Dues
 * and payments are for the household's Members. Your access … is Dependent — ask …"); a feature is built or gone. Read
 * from the shipped copy only (quoted strings and JSX text), never comments, the board files or the fidelity fixtures.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

const SRC = path.join(__dirname, '..', '..', '..')
const SKIP = new Set(['__tests__', 'lifted', 'fidelity', 'generated'])
const EXCUSES = /\bnot available yet\b|\bnot yet available\b|\bavailable soon\b|\bcoming soon\b/i

const files = (dir: string): string[] =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? files(full) : /\.(ts|tsx)$/.test(name) ? [full] : []
  })

const copyOf = (line: string): string[] =>
  [...line.matchAll(/'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\]|\\.)*)`|>([^<>{}]+)</g)].map((m) => m[1] ?? m[2] ?? m[3] ?? m[4] ?? '')

it('says "not available yet" nowhere in the Resident App', () => {
  const excuses: string[] = []
  for (const file of files(SRC)) {
    readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
      if (/^\s*(\*|\/\/|\/\*)/.test(line)) return
      for (const text of copyOf(line.replace(/\/\/.*$/, ''))) {
        if (EXCUSES.test(text)) excuses.push(`${path.relative(SRC, file)}:${i + 1}  ${text.slice(0, 120)}`)
      }
    })
  }
  expect(excuses).toEqual([])
})

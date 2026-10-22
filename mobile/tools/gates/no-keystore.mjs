#!/usr/bin/env node
/**
 * GATE: NO SIGNING KEY IS EVER IN THE REPOSITORY (M33 §2.3).
 *
 *   npm run gate:no-keystore
 *
 * The GeminiSecure release key lives outside every repository (tools/signing/make-release-keystore.ps1). This fails
 * the moment a keystore — .jks, .keystore, .p12, .pfx — or the properties file that holds its password is TRACKED, or
 * STAGED to be committed, wherever it sits and whatever it is called beside its extension. `.gitignore` keeps them
 * out of `git add .`; this catches `git add -f`, a renamed file, and anything already committed.
 */
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const KEYSTORE = /\.(jks|keystore|p12|pfx)$/i
const PASSWORDS = /(^|\/)(signing|keystore)\.properties$/i

/** @returns {string[]} the paths among these that are a signing key or its passwords */
export function secretsAmong(paths) {
  return paths.filter((p) => KEYSTORE.test(p) || PASSWORDS.test(p.replaceAll('\\', '/')))
}

function git(args, cwd) {
  return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

/** @returns {{ tracked: string[], staged: string[] }} */
export function checkRepository(cwd = process.cwd()) {
  return {
    tracked: secretsAmong(git(['ls-files'], cwd)),
    staged: secretsAmong(git(['diff', '--cached', '--name-only', '--diff-filter=ACMR'], cwd)),
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const { tracked, staged } = checkRepository()

  if (tracked.length === 0 && staged.length === 0) {
    console.log('GATE no-keystore · no keystore and no signing password is tracked or staged')
    process.exit(0)
  }

  for (const file of staged) console.log(`FAIL  staged to be committed: ${file} — a signing key or its password never enters the repository (M33 §2.3). git restore --staged "${file}"`)
  for (const file of tracked) console.log(`FAIL  committed: ${file} — a signing key or its password never enters the repository (M33 §2.3)`)
  process.exit(1)
}

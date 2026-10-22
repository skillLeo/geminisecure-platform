/**
 * Runs every Jest suite with the MACHINE's clock set to somewhere far from Kingston.
 *
 * A formatter that quietly reads the phone's own zone passes on a laptop in Jamaica and fails a guard in
 * Miami. Running the whole suite from Asia/Tokyo — fourteen hours the other way, and on the other side of
 * the date line — makes that class of bug impossible to hide: a wrong clock is wrong by half a day.
 *
 *   node tools/tests/tz.mjs                 (Asia/Tokyo)
 *   node tools/tests/tz.mjs Pacific/Auckland
 */

import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const TZ = process.argv[2] ?? 'Asia/Tokyo'
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm'

let failed = 0
for (const workspace of ['@gs/core', '@gs/guard', '@gs/resident']) {
  console.log(`\n--- ${workspace} · TZ=${TZ} ---`)
  const run = spawnSync(npm, ['test', '--workspace', workspace], { cwd: ROOT, stdio: 'inherit', env: { ...process.env, TZ }, shell: process.platform === 'win32' })
  if (run.status !== 0) failed++
}
console.log(failed ? `\nFAIL  ${failed} workspace(s) do not pass from ${TZ}` : `\nPASS  every suite passes with the machine set to ${TZ}`)
process.exit(failed ? 1 : 0)

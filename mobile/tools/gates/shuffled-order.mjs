/**
 * GATE — THE TEST SUITE STILL RUNS IN A SHUFFLED ORDER.
 *
 *   node tools/gates/shuffled-order.mjs
 *
 * ── WHY THE ORDER IS RANDOM. READ THIS BEFORE YOU SWITCH IT OFF ──────────────────────────────────
 *
 * If you are reading this in a year because the suite went red on a seed and switching the shuffle
 * off would make it green again: that is precisely the move this gate exists to stop, and here is
 * what it cost the last time.
 *
 * The Laravel suite used to run in the order PHPUnit happened to find the files in. It was green.
 * It was green because tests were leaning on each other: one test left a row, a claimed scratch lot,
 * a cached singleton or a changed setting behind, and the next test passed BECAUSE of it. Every one
 * of those pairs is a test that is not testing what its name says.
 *
 * When the order was randomised, TEN separate defects fell out that had been sitting under that
 * green — tests whose results change with run order are not tests. They had
 * passed alone and failed together, so nothing anybody had run would ever have shown them. One file
 * was worse than that: it failed in two DIFFERENT groupings and passed in the whole suite, so even
 * running everything hid it. There is no amount of care at the keyboard that finds that. Only
 * shuffling finds it.
 *
 * And the real cost is not the ten. It is what a suite people do not trust does to everything else.
 * The two worst defects in this build — the phone's own passcode being accepted as a guard's
 * identity, and mis-typed supervisor PINs written into the log in plain text — were invisible to the
 * suite and were found only by reading the code. A suite that cannot be trusted stops being
 * read, stops being extended, and stops catching real defects; that is how those two survived as
 * long as they did. Shuffling is what makes a green run mean something again.
 *
 * So: a red seed is a FINDING, not noise. `Tests\Support\RandomOrderSeed` prints the seed and the
 * exact command that reproduces that order whenever a run goes red. Reproduce it, and fix the test
 * that was leaning — never the order it was leaning in.
 *
 * ── WHAT THIS GATE CHECKS ────────────────────────────────────────────────────────────────────────
 *
 *   1  every PHPUnit configuration in the server project still says executionOrder="random" — gone,
 *      changed, or watered down by a second mode that pins part of the order back
 *   2  resolveDependencies is still stated, and still true
 *   3  the seed reporter is still registered, and still exists, and still reads the seed — a
 *      shuffled failure nobody can reproduce is a shuffle that gets switched off within the week
 *   4  no configuration OTHER than the one PHPUnit discovers can quietly lack any of that. This is
 *      case four: `phpunit.xml` is not magic, it is merely first of `phpunit.xml`,
 *      `phpunit.dist.xml`, `phpunit.xml.dist`. Rename it and the next one wins in silence
 *   5  no test file pins an order from the inside: a @depends annotation, a #[Depends…] attribute,
 *      a Pest ->depends(), an #[Order] from an ordering plugin, or a test reaching for PHPUnit's
 *      sorter itself
 *   6  no script that runs the suite takes it back off on the command line — --order-by, a pinned
 *      --random-order-seed, --no-configuration, or a -c pointing at a configuration never checked
 *   7  the server's own runtime guard is still there (tests/Unit/TestOrderIsRandomisedTest.php).
 *      That test sees the order the suite is ACTUALLY running in; this gate sees the files. Neither
 *      replaces the other, so neither may disappear on its own.
 *
 * A line that must name one of these on purpose says so on the line: `order-ok: <reason>`.
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { DISCOVERY_ORDER, annotationProblems, configProblems, discoveredConfig, overrideProblems, runtimeGuardProblems, seedReporterProblems } from './shuffle-rules.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')

/** The Laravel project: an override for anyone who keeps it elsewhere, then the sibling, then the machine it was built on. */
const SERVER = path.resolve(
  process.env.GS_SERVER_ROOT ??
    [path.join(path.dirname(ROOT), 'geminisecure'), '../server'].find((dir) => existsSync(path.join(dir, 'artisan'))) ??
    path.join(path.dirname(ROOT), 'geminisecure'),
)

/* `uploads` is never read by anything in this project, and that is deliberate — it holds residents' own files. */
const SKIP = new Set(['vendor', 'node_modules', '.git', 'storage', 'public', 'uploads', 'dist', 'build', '_design', '.expo'])
/** The two files that exist to talk ABOUT the ordering. They may name the sorter; they still may not pin an order. */
const OWNS_ORDERING = new Set(['tests/Support/RandomOrderSeed.php', 'tests/Unit/TestOrderIsRandomisedTest.php'])
const RUNTIME_GUARD = 'tests/Unit/TestOrderIsRandomisedTest.php'
const SEED_REPORTER_FILE = 'tests/Support/RandomOrderSeed.php'
/** A script only gets judged if it actually runs the suite; a document that quotes a flag is not a runner. */
const RUNNER_EXT = /\.(ps1|sh|bat|cmd|ya?ml|json|mjs|cjs|js)$/i
const RUNS_THE_SUITE = /artisan\s+test|phpunit/i

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : [full]
  })

const rel = (full) => path.relative(SERVER, full).replace(/\\/g, '/')
const read = (full) => (existsSync(full) ? readFileSync(full, 'utf8') : null)
/* `geminisecure-mobile` starts with `geminisecure`, so a string prefix is not containment. */
const inServer = (full) => !rel(full).startsWith('..')

const offenders = []

/* ── the server project has to be there at all ─────────────────────────────────────────────────── */
if (!existsSync(path.join(SERVER, 'artisan'))) {
  console.log('GATE shuffled-order · FAIL — the Laravel project is not where this gate can see it:')
  console.log(`  looked for an "artisan" file in ${SERVER}`)
  console.log('  Set GS_SERVER_ROOT to the Laravel project. A gate that cannot find what it guards must say so,')
  console.log('  because "nothing to check" and "everything is fine" print the same green line otherwise.')
  process.exit(1)
}

/* ── 1–4: every PHPUnit configuration anywhere in the project, not just the one that runs ──────── */
const configFiles = walk(SERVER).filter((full) => {
  const name = path.basename(full)
  if (!/\.xml(\.dist)?$/i.test(name)) return false
  // every root-level XML, plus any phpunit-ish name deeper in the tree
  if (path.dirname(full) === SERVER || /^phpunit/i.test(name)) return /<phpunit\b/.test(readFileSync(full, 'utf8'))
  return false
})

if (configFiles.length === 0) {
  offenders.push(
    `there is no PHPUnit configuration in ${SERVER} at all. The suite would run in the order the files are found in, with nothing shuffled and nothing saying so.`,
  )
}

for (const full of configFiles) offenders.push(...configProblems({ file: rel(full), xml: readFileSync(full, 'utf8') }))

const rootNames = readdirSync(SERVER).filter((name) => statSync(path.join(SERVER, name)).isFile())
const discovered = discoveredConfig(rootNames)
if (discovered === null && configFiles.length > 0) {
  offenders.push(
    `${configFiles.length} PHPUnit configuration(s) exist but none is named ${DISCOVERY_ORDER.join(', ')}, so PHPUnit discovers none of them and every run needs a -c nobody will remember to pass.`,
  )
}

/* ── 3 and 7: the two files this whole arrangement rests on ───────────────────────────────────── */
offenders.push(...seedReporterProblems(read(path.join(SERVER, SEED_REPORTER_FILE))))
offenders.push(...runtimeGuardProblems(read(path.join(SERVER, RUNTIME_GUARD)), RUNTIME_GUARD))

/* ── 5: nothing pins an order from inside a test file ─────────────────────────────────────────── */
const testFiles = walk(path.join(SERVER, 'tests')).filter((full) => /\.php$/i.test(full))
for (const full of testFiles) {
  const file = rel(full)
  offenders.push(...annotationProblems({ file, source: readFileSync(full, 'utf8'), ownsOrdering: OWNS_ORDERING.has(file) }))
}

/* ── 6: nothing takes it back off on the command line ─────────────────────────────────────────── */
const configNames = configFiles.map((full) => path.basename(full))
const runners = walk(SERVER).filter(
  (full) => RUNNER_EXT.test(full) && existsSync(full) && RUNS_THE_SUITE.test(readFileSync(full, 'utf8')),
)
const runnerNames = []
for (const full of runners) {
  const file = inServer(full) ? rel(full) : path.relative(ROOT, full).replace(/\\/g, '/')
  runnerNames.push(file)
  offenders.push(...overrideProblems({ file, text: readFileSync(full, 'utf8'), configs: configNames }))
}

/* ── the verdict ──────────────────────────────────────────────────────────────────────────────── */
if (offenders.length > 0) {
  console.log(`GATE shuffled-order · FAIL — ${offenders.length} thing(s) that would let the suite stop shuffling:`)
  for (const o of offenders) console.log(`  ${o}`)
  console.log('')
  console.log('  Shuffled ordering is a RULING, not a preference. It is what found the ten order-dependent')
  console.log('  failures, and a suite that cannot be trusted stops catching real defects — read the header of')
  console.log('  tools/gates/shuffled-order.mjs before changing any of the above.')
  process.exit(1)
}

console.log(
  `GATE shuffled-order · ${configFiles.length} PHPUnit configuration(s), ${testFiles.length} test files and ${runners.length} runner script(s): ` +
    `${discovered} is the one PHPUnit discovers and every configuration present still orders RANDOMLY ` +
    `(executionOrder="random", resolveDependencies="true", the seed printed on failure by Tests\\Support\\RandomOrderSeed) — ` +
    `no second configuration can slip in without it, no annotation or attribute pins a test's position, no script unshuffles a run on the command line, ` +
    `and ${RUNTIME_GUARD} proves the same thing from inside the run itself`,
)

console.log('\n  the configurations checked — PHPUnit takes the first of these it finds in the project root:')
for (const name of DISCOVERY_ORDER) console.log(`    ${name === discovered ? '>' : ' '} ${name.padEnd(18)}${rootNames.includes(name) ? (name === discovered ? 'THIS ONE RUNS' : 'present, would run if the one above went') : 'not present'}`)
for (const full of configFiles) if (!DISCOVERY_ORDER.includes(path.basename(full))) console.log(`      ${rel(full).padEnd(18)}not discovered automatically — checked anyway, because a -c would reach it`)
console.log(`  every script that runs the suite, checked for a flag that would unshuffle it: ${runnerNames.join(', ')}`)
console.log('')

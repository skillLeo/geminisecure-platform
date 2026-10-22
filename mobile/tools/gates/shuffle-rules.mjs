/**
 * THE SHUFFLED-ORDER RULES, AS PURE FUNCTIONS.
 *
 * Every rule here takes facts and returns sentences. Nothing in this file reads a file, walks a
 * tree or exits a process — `tools/gates/shuffled-order.mjs` gathers the facts and prints what
 * comes back, and `tools/gates/selftest.mjs` proves each rule on MADE-UP facts, so a probe never
 * writes a file into either project and never touches `phpunit.xml`.
 *
 * The WHY — what shuffled ordering is for, and what happened when it was off — is written at the
 * top of `tools/gates/shuffled-order.mjs`. Read that first if you are here to switch something off.
 */

/** The only modes PHPUnit will accept in `executionOrder`; anything else is a typo, not a decision. */
export const KNOWN_ORDER_MODES = ['default', 'defects', 'depends', 'duration', 'no-depends', 'random', 'reverse', 'size']

/** PHPUnit looks for these three, in this order, in the working directory. Nothing else is automatic. */
export const DISCOVERY_ORDER = ['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']

/** The extension that prints the seed when a run goes red. Without it a shuffled failure is unrepeatable. */
export const SEED_REPORTER = 'Tests\\Support\\RandomOrderSeed'

/** A line may opt out by saying why, in the open, on the line: `order-ok: <reason>`. */
export const ORDER_OK = /order-ok:\s*\S/

/**
 * Which configuration PHPUnit would actually load, given the file names present in the project root.
 *
 * This is the whole of case 4: `phpunit.xml` is not magic, it is merely FIRST. Delete it and
 * `phpunit.xml.dist` is picked up silently, with whatever it happens to say.
 *
 *   names  string[] of base names present in the project root
 */
export function discoveredConfig(names) {
  const present = new Set(names)
  return DISCOVERY_ORDER.find((name) => present.has(name)) ?? null
}

/** The `<phpunit …>` opening tag's attributes, with XML comments removed first. */
export function rootAttributes(xml) {
  const stripped = String(xml).replace(/<!--[\s\S]*?-->/g, '')
  const tag = /<phpunit\b([^>]*)>/.exec(stripped)
  if (tag === null) return null
  const attributes = {}
  for (const m of tag[1].matchAll(/([A-Za-z_][\w:.-]*)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) attributes[m[1]] = m[2] ?? m[3] ?? ''
  return attributes
}

/** Every `<bootstrap class="…"/>` the configuration registers, comments removed. */
export function bootstrapClasses(xml) {
  const stripped = String(xml).replace(/<!--[\s\S]*?-->/g, '')
  return [...stripped.matchAll(/<bootstrap\b[^>]*\bclass\s*=\s*(?:"([^"]*)"|'([^']*)')/g)].map((m) => m[1] ?? m[2] ?? '')
}

/**
 * RULE 1 — THE CONFIGURATION STILL SHUFFLES, AND SAYS SO OUT LOUD.
 *
 * Applied to EVERY PHPUnit configuration found anywhere in the server project, not just the one
 * PHPUnit happens to load today. A second configuration that quietly lacks `executionOrder` is one
 * `-c` away from being the one that runs, and nothing about the run would look unusual.
 *
 *   file  the path to name in a failure
 *   xml   the raw text of the configuration
 */
export function configProblems({ file, xml }) {
  const problems = []
  const at = rootAttributes(xml)
  if (at === null) return [`${file} has no <phpunit> element — it is not a PHPUnit configuration, or it no longer parses`]

  const raw = at.executionOrder
  if (raw === undefined) {
    problems.push(
      `${file} no longer sets executionOrder. PHPUnit then runs the files in the order it finds them, which is the order the ten order-dependent failures hid in. Put executionOrder="random" back on the <phpunit> element.`,
    )
  } else {
    const modes = String(raw).split(',').map((m) => m.trim().toLowerCase()).filter(Boolean)
    const unknown = modes.filter((m) => !KNOWN_ORDER_MODES.includes(m))
    if (unknown.length > 0) problems.push(`${file}: executionOrder="${raw}" names ${unknown.join(', ')}, which PHPUnit does not know — check the spelling; an unknown mode is silently no shuffling at all`)
    if (!modes.includes('random')) {
      problems.push(`${file}: executionOrder="${raw}" — the suite no longer shuffles. Set executionOrder="random"; an order-dependent test must fail on some seed, not wait to be found by luck.`)
    } else {
      const extra = modes.filter((m) => m !== 'random')
      if (extra.length > 0) {
        problems.push(
          `${file}: executionOrder="${raw}" adds ${extra.join(', ')} to random, and each of those pins part of the order back down — the shuffle is weaker than it reads. Set executionOrder="random" on its own.`,
        )
      }
    }
  }

  const resolve = at.resolveDependencies
  if (resolve === undefined) {
    problems.push(
      `${file} no longer states resolveDependencies. It is written down explicitly so the decision is visible: a PHPUnit default is not a decision this suite may inherit. Put resolveDependencies="true" back.`,
    )
  } else if (String(resolve).trim().toLowerCase() !== 'true') {
    problems.push(
      `${file}: resolveDependencies="${resolve}" — a test with @depends would now run before the test it depends on, fail for the wrong reason, and shuffling would get the blame and be switched off. Set resolveDependencies="true".`,
    )
  }

  if (!bootstrapClasses(xml).includes(SEED_REPORTER)) {
    problems.push(
      `${file} no longer registers ${SEED_REPORTER} as an <extensions><bootstrap>. A shuffled failure then prints no seed, cannot be reproduced, and the first person who meets one switches the shuffle off rather than chase it.`,
    )
  }

  return problems
}

/**
 * RULE 2 — NOTHING PINS AN ORDER FROM INSIDE A TEST FILE.
 *
 * A configuration is one file that one gate watches. An annotation is 96 files that nobody watches,
 * and it defeats the shuffle exactly where the shuffle is needed: `@depends` chains two tests
 * together, so the second is once again allowed to rely on what the first left behind — which is
 * the defect, written down as if it were a feature.
 *
 *   file           the path to name in a failure
 *   source         the file's text
 *   ownsOrdering   true for the two files that exist to talk ABOUT the ordering (the seed reporter
 *                  and the runtime guard). They may name the sorter; they still may not pin an order.
 */
export function annotationProblems({ file, source, ownsOrdering = false }) {
  const PINS = [
    {
      test: /@depends[A-Za-z]*\b/,
      say: 'a @depends annotation chains this test behind another, so it may again rely on what that one left behind. Give the test its own setup instead',
    },
    {
      test: /#\[[\s\\A-Za-z]*Depends[A-Za-z]*\s*[(\]]/,
      say: 'a #[Depends…] attribute chains this test behind another, so it may again rely on what that one left behind. Give the test its own setup instead',
    },
    {
      test: /->\s*depends\s*\(/,
      say: "a Pest ->depends() chains this test behind another. Give the test its own setup instead",
    },
    {
      test: /#\[\s*Order\s*\(|@order\b|@phpunit-order\b|@priority\b|@runInOrder\b/,
      say: 'an ordering annotation pins this file or this test to a fixed position, which is the shuffle switched off one file at a time',
    },
    {
      machineryExempt: true,
      test: /TestSuiteSorter|ORDER_DEFAULT|ORDER_REVERSED|executionOrder|--order-by/,
      say: 'names PHPUnit\'s ordering machinery from inside a test file. Ordering is decided in phpunit.xml and nowhere else',
    },
  ]
  const problems = []
  String(source).split('\n').forEach((line, i) => {
    if (ORDER_OK.test(line)) return
    for (const pin of PINS) {
      if (pin.machineryExempt && ownsOrdering) continue
      if (pin.test.test(line)) problems.push(`${file}:${i + 1}  ${pin.say}\n      ${line.trim().slice(0, 120)}`)
    }
  })
  return problems
}

/**
 * RULE 3 — NO SCRIPT THAT RUNS THE SUITE TAKES THE SHUFFLE BACK OFF ON THE COMMAND LINE.
 *
 * The command line beats the configuration file. `--order-by=default` in a wrapper, a task runner
 * or a CI step leaves `phpunit.xml` looking perfect while every run is in file order. A pinned
 * `--random-order-seed` is the same failure wearing the right words: it shuffles once, and then
 * runs that one order for ever.
 *
 *   file     the path to name in a failure
 *   text     the script's text
 *   configs  the base names of the configurations this gate has checked
 */
export function overrideProblems({ file, text, configs = [] }) {
  const known = new Set(configs)
  const problems = []
  String(text).split('\n').forEach((line, i) => {
    if (ORDER_OK.test(line)) return
    const where = `${file}:${i + 1}`
    for (const m of line.matchAll(/--order-by[= ]\s*([A-Za-z,-]+)/g)) {
      const modes = m[1].split(',').map((x) => x.trim().toLowerCase()).filter(Boolean)
      if (modes.length !== 1 || modes[0] !== 'random') problems.push(`${where}  runs the suite with --order-by=${m[1]}, which beats phpunit.xml and unshuffles every run this script makes. Drop the flag.`)
    }
    for (const _ of line.matchAll(/--random-order-seed[= ]\s*\d+/g)) {
      problems.push(`${where}  pins --random-order-seed, so this script shuffles once and then runs that same order for ever. A seed is for reproducing one failure by hand, never for a script.`)
    }
    if (/--no-configuration\b/.test(line)) problems.push(`${where}  runs the suite with --no-configuration, so phpunit.xml is ignored entirely and nothing shuffles. Drop the flag.`)
    for (const m of line.matchAll(/(?:--configuration[= ]\s*|-c\s+)["']?([^\s"';,)]+\.xml(?:\.dist)?)/g)) {
      const base = m[1].replace(/\\/g, '/').split('/').pop()
      if (!known.has(base)) problems.push(`${where}  runs the suite against ${m[1]}, a configuration this gate has never checked. Either it does not exist, or it is a second configuration that can quietly lack executionOrder.`)
    }
  })
  return problems
}

/**
 * RULE 4 — THE SEED REPORTER IS STILL THERE AND STILL WATCHES FOR A RANDOMISED RUN.
 *
 * `source` is the text of tests/Support/RandomOrderSeed.php, or null if it is gone.
 */
export function seedReporterProblems(source) {
  if (source === null || source === undefined) {
    return [`tests/Support/RandomOrderSeed.php is gone. A shuffled failure would then print no seed and could not be reproduced — and an unreproducible failure is how the shuffle gets switched off.`]
  }
  const problems = []
  if (!/ORDER_RANDOMIZED/.test(source)) problems.push('tests/Support/RandomOrderSeed.php no longer checks that the run really was randomised, so it either says nothing or says something misleading')
  if (!/randomOrderSeed\s*\(/.test(source)) problems.push('tests/Support/RandomOrderSeed.php no longer reads the seed, so the line it prints cannot reproduce anything')
  return problems
}

/**
 * RULE 5 — THE SERVER CARRIES ITS OWN GUARD, AND IT STILL ASSERTS THE SAME THING.
 *
 * This gate reads files. Only a test inside the run can see the EFFECTIVE configuration — after the
 * command line, after `-c`, after whatever a person typed at a prompt. The two guards catch
 * different things, so neither replaces the other, and this rule stops the server one being deleted
 * quietly. `source` is the text of the runtime guard test, or null if it is gone.
 */
export function runtimeGuardProblems(source, file) {
  if (source === null || source === undefined) {
    return [`${file} is gone. It is the only check that sees the order the suite is ACTUALLY running in, including a --order-by typed by hand that never reaches a file this gate can read. Restore it.`]
  }
  const problems = []
  if (!/ORDER_RANDOMIZED/.test(source)) problems.push(`${file} no longer asserts the run is randomised — it is passing without proving anything`)
  if (!/resolveDependencies/.test(source)) problems.push(`${file} no longer asserts resolveDependencies, so a @depends chain could be silently unresolved`)
  return problems
}

/**
 * RULE 6 — THE GATE IS STILL IN THE CHAIN.
 *
 * A gate nobody runs is a comment. `npm run gates` is the chain, and the self-test is in it, so the
 * self-test is the right place to notice that this gate has been dropped out of it.
 */
export function gatesChainProblems(gatesScript, gateName) {
  if (!String(gatesScript).includes(gateName)) {
    return [`package.json "gates" no longer runs ${gateName} — the shuffled-order guard is switched off, which is the one thing it exists to prevent. Put it back in the chain.`]
  }
  return []
}

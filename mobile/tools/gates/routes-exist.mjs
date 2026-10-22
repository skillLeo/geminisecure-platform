/**
 * GATE — every route a control navigates to EXISTS.
 *
 * The client met "Unmatched Route" on a real phone (2026-09-19): a badge led to a screen that had not
 * been built. `router.push(path as never)` silences the typed-routes check that would have caught it,
 * so this gate does the check instead: every string path handed to router.push / replace / navigate,
 * to a local `go(...)` helper, or to `pathname:` / `href=` must resolve to a file under that app's
 * `app/` folder (groups like `(tabs)` are transparent; `[id]` matches any one segment).
 *
 * AND EVERY ROUTE A NOTIFICATION LEADS TO. `packages/core/notify/links.ts` is a table of paths that no
 * screen mentions — it is where a tap on a banner or on a push opened from the lock screen goes
 * — so it is checked here too, per app, with `${id}` and an alias's `$1` standing for one segment.
 * (`resident_report` pointed at `/report/…`, which no guard screen answered: the same "Unmatched Route",
 * reachable only from a notification, where no screen's source would ever have shown it.)
 *
 *   node tools/gates/routes-exist.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const SKIP = new Set(['node_modules', 'lifted', '.expo', 'dist', 'android', 'ios', '__tests__'])

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx?$/.test(name) ? [full] : []
  })

/** app/(tabs)/index.tsx → [] · app/visitors/pass/[id].tsx → ['visitors','pass','[id]'] */
function routesOf(appDir) {
  return walk(appDir)
    .map((file) => path.relative(appDir, file).replace(/\\/g, '/').replace(/\.tsx?$/, ''))
    .filter((rel) => !/(^|\/)(_layout|\+not-found|\+html|\+native-intent)$/.test(rel))
    .map((rel) => rel.split('/').filter((seg) => !/^\(.*\)$/.test(seg)).filter((seg, i, all) => !(seg === 'index' && i === all.length - 1)))
}

const matches = (route, target) => route.length === target.length && route.every((seg, i) => /^\[.*\]$/.test(seg) || seg === target[i])

const CALL = /(?:router\.(?:push|replace|navigate)|\bgo|\bopen|href\s*=\s*\{?|pathname\s*:)\s*\(?\s*(['"`])(\/[^'"`$]*)\1/g

/** `/x/${v}` and `/visitors/pass/$1` → `/x/·`: one segment, whatever it turns out to be. */
const LINKS = path.join(ROOT, 'packages', 'core', 'notify', 'links.ts')
const linkPaths = (app) => {
  if (!existsSync(LINKS)) return []
  const source = readFileSync(LINKS, 'utf8')
  const name = app === 'guard' ? 'GUARD' : 'RESIDENT'
  const table = new RegExp(`const ${name}[\\s\\S]*?=\\s*\\{([\\s\\S]*?)\\n\\}`).exec(source)?.[1] ?? ''

  // ALIASES nests one array per app; a regex over nested brackets is a trap, so the block is read a line at a time.
  const block = /const ALIASES[\s\S]*?=\s*\{([\s\S]*?)\n\}/.exec(source)?.[1] ?? ''
  let inApp = false
  const aliases = block.split('\n').filter((line) => {
    const head = /^\s{2}([a-z]+)\s*:/.exec(line)
    if (head) inApp = head[1] === app
    return inApp
  })

  return [...[table, ...aliases].join('\n').matchAll(/(['"`])(\/[^'"`\n]*)\1/g)]
    .map((m) => m[2].replace(/\$\{[^}]*\}/g, '·').replace(/\$\d/g, '·'))
    .filter((p) => !p.includes('$'))
}

const offenders = []
let checked = 0
for (const app of ['guard', 'resident']) {
  const appDir = path.join(ROOT, 'apps', app, 'app')
  const routes = routesOf(appDir)

  for (const target of linkPaths(app)) {
    const segments = target.split('?')[0].split('/').filter(Boolean).filter((seg) => !/^\(.*\)$/.test(seg))
    checked++
    if (!routes.some((route) => matches(route, segments))) {
      offenders.push(`packages/core/notify/links.ts  ${target}  — a notification for the ${app} app leads nowhere: no such screen in apps/${app}/app/`)
    }
  }

  for (const file of walk(path.join(ROOT, 'apps', app))) {
    const rel = path.relative(ROOT, file).replace(/\\/g, '/')
    const source = readFileSync(file, 'utf8')
    for (const m of source.matchAll(CALL)) {
      const line = source.slice(0, m.index).split('\n').length
      if (/^\s*(\*|\/\/)/.test(source.split('\n')[line - 1] ?? '')) continue
      const target = m[2].split('?')[0].split('#')[0].split('/').filter(Boolean).filter((seg) => !/^\(.*\)$/.test(seg))
      checked++
      if (!routes.some((route) => matches(route, target))) offenders.push(`${rel}:${line}  ${m[2]}  — no such screen in apps/${app}/app/`)
    }
  }
}

if (offenders.length > 0) {
  console.log(`GATE routes-exist · FAIL — a control leads nowhere (${offenders.length}):`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE routes-exist · ${checked} navigation targets all resolve to a screen`)

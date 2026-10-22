/**
 * GATE — ONE CLOCK.
 *
 * Every time either app shows a person is rendered in the SITE's zone, from the IANA name the server
 * sent. The bug this gate exists to stop came back twice: a roster read five hours early because a
 * formatter used the phone's own clock, and a booking sent from the phone's zone that the gate then
 * refused. So, in everything that ships (test files are not shipped and are skipped):
 *
 *   1  `toLocaleTimeString` / `toLocaleDateString` / `toLocaleString` / `Intl.DateTimeFormat` (with or
 *      without `new` — it is callable both ways) with no `timeZone` — the phone's own zone, silently
 *   2  a fixed Jamaica offset written into code: `-05:00` / `+05:00` inside a time, `UTC-5`, `UTC−5`,
 *      an offset multiplied out (`5 * 3_600_000`), `setTimezoneOffset`
 *   3  `getTimezoneOffset()` anywhere but the ONE file that builds `X-Device-Time` — that value is a
 *      fact about the handset, not a time to display
 *   4  the string 'America/Jamaica' anywhere in shipped code (M19A §9: nothing assumes Jamaica). The default a phone
 *      reads before the server names the estate's zone is the BUILD's setting (EXPO_PUBLIC_PLATFORM_TIMEZONE) or
 *      UTC, in lib/siteTime.ts. Only the boards' fixture data (a `fidelity/` folder) may name the boards' estate zone
 *   5  a local-time `Date` constructor, `new Date(y, m, d, …)`, whose day depends on where the phone is
 *   6  `resolvedOptions().timeZone` — asking the HANDSET which zone it is in. The site's zone is the
 *      IANA name the server sent; the handset's answers only one thing: `phoneZone()` in lib/siteTime.ts,
 *      for a reader who CHOSE their phone's clock (M11 §4.1), where every time shown then names its zone
 *   7  a LOCAL-time getter or setter on a Date — `getHours`, `getMinutes`, `getDate`, `getDay`,
 *      `getMonth`, `getFullYear`, `setHours` … — which read the phone's clock exactly as surely as
 *      `toLocaleTimeString` does, and are the commonest way this bug gets written. Their UTC twins
 *      (`getUTCHours` …) are fine: they have no zone. `toDateString()` and `toTimeString()` are the
 *      same thing wearing a different name. Only lib/time.ts, which builds X-Device-Time out of the
 *      handset's own wall clock on purpose, may use them
 *   8  `new Date(x).toISOString()` — an instant you ALREADY hold, written back out on the UTC clock.
 *      As an instant it is a no-op; as a day or a time it is UTC's, not the site's. This is how the
 *      five-hour bug came back a third time: Home's "Posted …" named the day off `toISOString()`, so
 *      a notice the estate put up at 9 PM in Kingston read "Posted September 1" instead of August 31.
 *      Use siteDayIso() for the day and time12()/dateShort() for the reading. Epoch ARITHMETIC
 *      (`new Date(Date.parse(`${ymd}T12:00:00Z`) + days * DAY_MS)`) is not this: it is calendar
 *      counting anchored at noon, where no zone can move the day, and it is left alone.
 *
 * A line that genuinely measures the PHONE's own clock — a countdown, "3 seconds ago", the device time
 * header — passes by saying so on the line: `// phone-clock: <reason>`.
 *
 *   node tools/gates/one-clock.mjs
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
// `__gateprobe__` is deliberately NOT skipped: tools/gates/selftest.mjs proves this gate on probes there.
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'generated', 'lifted'])

/** The one file that owns the zone, the default and the zone database. */
const THE_CLOCK = 'packages/core/lib/siteTime.ts'
/** The one file that may read the HANDSET's clock: it builds X-Device-Time, which is about the handset. */
const THE_DEVICE_CLOCK = 'packages/core/lib/time.ts'

const ALLOW = /\/\/\s*phone-clock:\s*\S/

const RULES = [
  {
    id: 'no-timezone',
    // `Intl.DateTimeFormat(…)` is callable without `new`; requiring `new` let the phone's zone straight through.
    test: (code) => /\.toLocale(?:Time|Date)?String\s*\(/.test(code) || /\bIntl\.DateTimeFormat\s*\(/.test(code),
    unless: (code) => /timeZone\s*:/.test(code),
    say: 'formats a time with no timeZone — it would draw the PHONE\'s zone. Use @gs/core/lib (time12, dateShort, siteParts…)',
  },
  {
    id: 'handset-zone',
    test: (code) => /resolvedOptions\s*\(\s*\)\s*\.\s*timeZone/.test(code),
    say: `asks the HANDSET which zone it is in. The site's zone is the IANA name the server sent — siteZone() in ${THE_CLOCK}`,
  },
  {
    id: 'local-parts',
    // `setDay` is a React state setter, not a Date method — Date has no such thing — so the write side
    // names only the methods Date actually has AND nothing else plausibly does.
    test: (code) => /\.get(?:Hours|Minutes|Seconds|Milliseconds|Date|Day|Month|FullYear)\s*\(/.test(code) || /\.set(?:Hours|Minutes|Seconds|Milliseconds|Month|FullYear)\s*\(/.test(code) || /\.to(?:Date|Time)String\s*\(/.test(code),
    say: `reads a Date on the PHONE's clock. Use siteParts()/time12()/dateShort() from @gs/core/lib, or the getUTC* twin; only ${THE_DEVICE_CLOCK} may`,
    only: THE_DEVICE_CLOCK,
  },
  {
    id: 'utc-day',
    // A BARE instant re-serialised: `new Date(iso).toISOString()`, `new Date(row.sent_at).toISOString()`.
    // Epoch arithmetic inside the constructor is calendar counting, not a reading, and is not matched.
    test: (code) => /new\s+Date\s*\(\s*[A-Za-z_$][\w$]*(?:\??\.[A-Za-z_$][\w$]*)*\s*\)\s*\.\s*toISOString\s*\(/.test(code),
    say: "writes an instant it already holds back out on the UTC clock. As a day or a time that is UTC's, not the site's — use siteDayIso(), time12() or dateShort()",
  },
  {
    id: 'fixed-offset',
    test: (code) => /\d{2}:\d{2}(?::\d{2})?[-+]0[45]:00/.test(code) || /UTC\s*[-−]\s*5/.test(code) || /\b5\s*\*\s*3[_,]?600[_,]?000\b/.test(code) || /setTimezoneOffset/.test(code),
    say: 'writes a fixed Jamaica offset into code. The zone is an IANA NAME from the server; build instants with atSiteZone()',
  },
  {
    id: 'device-offset',
    test: (code) => /getTimezoneOffset\s*\(/.test(code),
    say: `reads the HANDSET's offset. Only ${THE_DEVICE_CLOCK} may, for X-Device-Time`,
    only: THE_DEVICE_CLOCK,
  },
  {
    id: 'hardcoded-zone',
    test: (code) => /America\/Jamaica/.test(code),
    say: `hard-codes a zone name. An estate's zone comes from the server; the default before it is the build's setting (${THE_CLOCK})`,
    // The boards' own fixture data, served only behind isFidelity — a seeded estate's zone, as a test's would be. And
    // the phone example's table (M32 F7): which country keeps the zone the SERVER sent, so a phone box shows that
    // country's example. It names zones as keys to look up and reads no time on any clock.
    allowIn: /\/fidelity\/|^\/packages\/core\/lib\/contactFields\.ts$/,
  },
  {
    id: 'local-date',
    test: (code) => /new\s+Date\s*\(\s*(?:[A-Za-z_$][\w$.]*|\d{4})\s*,/.test(code),
    say: 'builds a Date from local-time parts — the day depends on where the phone is. Use Date.UTC() or atSiteZone()',
  },
]

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.(ts|tsx)$/.test(name) && !/\.test\.tsx?$/.test(name) ? [full] : []
  })

const roots = [path.join(ROOT, 'packages', 'core'), ...['guard', 'resident'].flatMap((app) => [path.join(ROOT, 'apps', app, 'src'), path.join(ROOT, 'apps', app, 'app')])]

const offenders = []
let scanned = 0

for (const file of roots.flatMap(walk)) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  scanned++
  let inBlock = false
  readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/^\s*\/\*/.test(line)) inBlock = true
    const comment = inBlock || /^\s*(\*|\/\/)/.test(line)
    if (/\*\//.test(line)) inBlock = false
    if (comment) return
    if (ALLOW.test(line)) return
    const code = line.replace(/\/\/.*$/, '')
    for (const rule of RULES) {
      if (rule.only === rel) continue
      if (rule.allowIn?.test(`/${rel}`)) continue
      if (!rule.test(code)) continue
      if (rule.unless?.(code)) continue
      offenders.push(`${rel}:${i + 1}  ${rule.say}\n      ${code.trim().slice(0, 140)}`)
    }
  })
}

// The one clock must hold the default, and take it from the build's setting rather than a name in the code (M19A §9).
const clock = existsSync(path.join(ROOT, THE_CLOCK)) ? readFileSync(path.join(ROOT, THE_CLOCK), 'utf8') : ''
if (!/export const DEFAULT_SITE_ZONE\b/.test(clock)) offenders.push(`${THE_CLOCK} no longer declares DEFAULT_SITE_ZONE — the one default is gone`)
if (!/EXPO_PUBLIC_PLATFORM_TIMEZONE/.test(clock)) offenders.push(`${THE_CLOCK}: DEFAULT_SITE_ZONE must come from the build's EXPO_PUBLIC_PLATFORM_TIMEZONE, falling back to UTC`)

if (offenders.length > 0) {
  console.log(`GATE one-clock · FAIL — ${offenders.length} place(s) that would read a time on the wrong clock:`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE one-clock · ${scanned} files: every time is rendered in the site's zone, through ${THE_CLOCK}`)

/**
 * AUDIT — WCAG 2.1 AA contrast for the text/surface pairs the two apps actually use.
 *
 * The colours are the boards' (packages/core/theme/tokens.json — generated from the design, never
 * edited by hand), so this is a MEASUREMENT of the design as much as a gate on the code:
 *
 *   body text     4.5 : 1      large text (≥ 18.66 px bold or ≥ 24 px) and meaningful icons   3 : 1
 *
 * The client ruled: AA outranks the board's swatch. Three Foundation colours failed it and
 * ship darkened/lightened through tools/tokens/accessibility-overrides.json, so
 * `--strict` is now part of `npm run gates`: a body-text pair under 4.5 : 1 fails the build.
 *
 * AND THE TABLE ITSELF IS CHECKED, which is the change that matters (M8 §6).
 * ========================================================================
 * The table below was hand-maintained, and on 23 September 2026 the client said he could not read
 * the app: "the font is too pale … on a sky blue … it is like a grey, it's not showing black". Every
 * pair in the table passed. The pale text was `amber-600` — thirty-four times, as the LINK colour on
 * the sign-in screens ("My estate isn't listed", "Log out", "Didn't get it?") — and the table had no
 * row for it, so nothing measured it and nothing could fail.
 *
 * So the audit now reads the SOURCE: every `color="…"` a shipped screen draws text with has to
 * appear in the table for that app, or the audit fails saying which token is drawn and unmeasured.
 * A hand-kept list cannot be trusted to be complete; a list checked against the code can.
 *
 *   node tools/gates/contrast.mjs            (exit 0 — prints the table and the findings)
 *   node tools/gates/contrast.mjs --strict   (exit 1 if any BODY pair is under 4.5, or a token is unmeasured)
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const theme = JSON.parse(readFileSync(path.join(ROOT, 'packages', 'core', 'theme', 'tokens.json'), 'utf8'))
const boardOnly = JSON.parse(readFileSync(path.join(ROOT, 'packages', 'core', 'theme', 'board-only.json'), 'utf8'))
const tokens = {
  ...theme.colors,
  ...theme.semantic,
  ...Object.fromEntries(Object.entries(boardOnly.colors ?? {}).map(([name, entry]) => [name, entry.hex ?? entry])),
}

const lin = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4 }
const lum = (hex) => { const n = parseInt(String(hex).replace('#', ''), 16); return 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255) }
const ratio = (fg, bg) => { const [a, b] = [lum(fg), lum(bg)].sort((x, y) => y - x); return (a + 0.05) / (b + 0.05) }

// [app, foreground, background, role, where it is used]
const PAIRS = [
  ['guard', 'white', 'surface-900', 'body', 'titles and values on the screen'],
  ['guard', 'white', 'surface-800', 'body', 'titles and values on cards, the app bar'],
  ['guard', 'white', 'surface-700', 'body', 'values on the darker inset rows'],
  ['guard', 'text-2', 'surface-900', 'body', 'paragraphs'],
  ['guard', 'text-2', 'surface-800', 'body', 'paragraphs on cards'],
  ['guard', 'text-2', 'surface-700', 'body', 'paragraphs on inset rows'],
  ['guard', 'text-3', 'surface-900', 'body', 'section headings, footnotes'],
  ['guard', 'text-3', 'surface-800', 'body', 'row details, timestamps, idle tab labels'],
  ['guard', 'text-3', 'surface-700', 'body', 'day badges'],
  ['guard', 'amber-500', 'surface-900', 'body', 'links, warnings'],
  ['guard', 'amber-500', 'surface-800', 'body', 'values, active tab, warnings on cards'],
  ['guard', 'amber-500', 'surface-700', 'body', 'values on inset rows'],
  ['guard', 'g-navy-900', 'amber-500', 'body', 'the primary button\'s label'],
  ['guard', 'navy-900', 'amber-500', 'body', 'the amber button\'s label on the boards that use it'],
  ['guard', 'red-500', 'surface-900', 'body', 'errors'],
  ['guard', 'red-500', 'surface-800', 'body', 'errors and deductions on cards'],
  ['guard', 'green-400', 'surface-800', 'body', 'a good outcome on a card'],
  ['guard', 'green-400', 'surface-900', 'body', 'a good outcome on the screen'],
  ['guard', 'white', 'navy-600', 'body', 'the claim / secondary button'],
  ['guard', 'white', 'navy-700', 'body', 'the shift card'],
  ['guard', 'text-2', 'navy-700', 'body', 'the shift card\'s detail lines'],
  ['guard', 'white', 'red-700', 'body', 'a destructive button\'s label'],

  ['resident', 'navy-900', 'white', 'body', 'titles and values'],
  ['resident', 'navy-900', 'navy-100', 'body', 'titles on tinted rows'],
  ['resident', 'navy-900', 'amber-500', 'body', 'the amber chip and the panic label'],
  ['resident', 'slate-600', 'white', 'body', 'labels, paragraphs'],
  ['resident', 'slate-600', 'navy-100', 'body', 'labels on tinted rows'],
  ['resident', 'slate-500', 'white', 'body', 'details, timestamps, idle tab labels'],
  ['resident', 'slate-500', 'navy-100', 'body', 'details on tinted rows'],
  ['resident', 'slate-500', 'res-page-bg', 'body', 'details on the page background'],
  ['resident', 'navy-700', 'navy-100', 'body', 'chips'],
  ['resident', 'navy-700', 'white', 'body', 'headings on cards'],
  ['resident', 'navy-800', 'white', 'body', 'emphasis on cards'],
  ['resident', 'navy-800', 'navy-100', 'body', 'emphasis on tinted rows'],
  ['resident', 'navy-600', 'white', 'body', 'links, active tab'],
  ['resident', 'navy-600', 'navy-100', 'body', 'links on tinted rows'],
  ['resident', 'white', 'navy-600', 'body', 'the primary button\'s label'],
  ['resident', 'white', 'navy-700', 'body', 'the app bar'],
  ['resident', 'white', 'navy-800', 'body', 'the hero'],
  ['resident', 'white', 'red-700', 'body', 'a destructive button\'s label'],
  ['resident', 'amber-700', 'white', 'body', 'warnings'],
  ['resident', 'amber-700', 'amber-100', 'body', 'warnings on the amber panel'],
  ['resident', 'red-700', 'white', 'body', 'errors'],
  ['resident', 'red-700', 'red-100', 'body', 'errors on the red panel'],
  ['resident', 'green-700', 'white', 'body', 'a good outcome'],
  ['resident', 'green-700', 'success-100', 'body', 'a good outcome on the green panel'],
  // M18B/M19: the status inks also sit straight on the page background (WaitingScreen, InviteParts), and a link does.
  ['resident', 'green-700', 'res-page-bg', 'body', 'a good outcome on the page background'],
  ['resident', 'amber-700', 'res-page-bg', 'body', 'a warning on the page background'],
  ['resident', 'red-700', 'res-page-bg', 'body', 'an error on the page background'],
  ['resident', 'navy-600', 'res-page-bg', 'body', 'a link on the page background'],
  ['resident', 'text-primary', 'res-page-bg', 'body', 'a heading on the page background'],
  ['resident', 'text-body', 'res-page-bg', 'body', 'body text on the page background'],
  ['resident', 'text-placeholder', 'white', 'body', 'a field\'s placeholder'],
  ['resident', 'black', 'white', 'body', 'the few places the boards draw pure black'],

  /*
   * THE HEROES. Every amber eyebrow and every faded white subtitle in this app sits on one of two
   * navy gradients, and a gradient has to be measured at its LIGHTEST stop — which is where the
   * contrast is worst and where the eye actually lands on these screens.
   */
  ['resident', 'amber-100', 'navy-600', 'body', 'the eyebrow over a hero whose gradient starts at navy-600'],
  ['resident', 'amber-100', 'navy-700', 'body', 'the eyebrow over a darker hero'],
  ['resident', 'amber-500', 'navy-700', 'body', 'amber values on a dark panel'],
  ['resident', 'amber-500', 'navy-800', 'body', 'amber values on the darkest panel'],
  ['resident', 'amber-500', 'navy-900', 'body', 'amber on the panic backdrop'],
  ['resident', 'white@0.92', 'navy-600', 'body', 'the hero\'s faded subtitle, at its lightest stop'],
  ['resident', 'white@0.92', 'navy-700', 'body', 'the same subtitle further down the gradient'],

  /*
   * THE LINK COLOUR THE CLIENT COULD NOT READ (M8 §6). `amber-600` is the boards' amber for a FILL —
   * a button, an icon tile — and it was being used as the link TEXT on the sign-in screens, where it
   * is 2.23 : 1 on white. The screens use `amber-700` (the darkened, AA-passing amber) for text now;
   * these rows stay so a fill colour can never quietly become text again.
   *
   * `decor` is a role, not an excuse: a border, a divider, a disabled glyph and a decorative rule
   * carry no information on their own, so WCAG sets them no ratio. Anything a person has to READ or
   * has to SEE to operate the app is `body` or `icon` and is held to 4.5 or 3.
   */
  ['resident', 'amber-600', 'white', 'decor', 'an amber ICON or fill on a light surface — never text'],
  ['resident', 'amber-500', 'white', 'decor', 'the amber fill of a button or tile — never text'],
  ['resident', 'navy-500', 'white', 'icon', 'input and row icons'],
  ['resident', 'navy-300', 'white', 'decor', 'a decorative rule or an inactive dot'],
  ['resident', 'slate-300', 'white', 'decor', 'a chevron, a divider, a disabled glyph — never body text'],
  ['resident', 'navy-200', 'white', 'decor', 'borders and disabled fills'],
]

/**
 * `white@0.92` — a translucent text colour over a surface, composited the way a phone draws it.
 *
 * The apps write faded whites with `a('white', 0.72)`, and no audit that reads only solid tokens can
 * see them at all. That is how the hero's subtitle — the line under the greeting on Home — ended up
 * at 3.39 : 1 while every measured pair passed.
 */
function resolve(name) {
  const [token, opacity] = name.split('@')

  return { hex: tokens[token], opacity: opacity === undefined ? 1 : Number(opacity) }
}

const over = (fg, bg) => {
  if (fg.opacity >= 1) return fg.hex
  const [f, b] = [parseInt(fg.hex.replace('#', ''), 16), parseInt(bg.hex.replace('#', ''), 16)]
  const mix = (shift) => Math.round(fg.opacity * ((f >> shift) & 255) + (1 - fg.opacity) * ((b >> shift) & 255))

  return `#${[16, 8, 0].map((s) => mix(s).toString(16).padStart(2, '0')).join('')}`
}

/* ── which tokens are actually DRAWN AS TEXT, read from the shipped screens ────────────────────── */

const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'lifted', 'fidelity', 'generated'])

const walk = (dir) =>
  !existsSync(dir) ? [] : readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx$/.test(name) ? [full] : []
  })

/**
 * Every token a `<Text …>` is given, per app.
 *
 * Deliberately narrow: only `<Text` elements, so an ICON's colour (which answers to 3 : 1, not 4.5)
 * is not counted as text. A token used for both — `amber-500` is a fill in one place and a label in
 * another — shows up here through its Text use, which is the stricter of the two.
 */
function textTokensOf(app) {
  const found = new Map()

  for (const file of [...walk(path.join(ROOT, 'apps', app, 'src')), ...walk(path.join(ROOT, 'apps', app, 'app'))]) {
    const source = readFileSync(file, 'utf8')

    for (const match of source.matchAll(/<Text\b[^>]*?\scolor="([a-z0-9-]+)"/gs)) {
      const token = match[1]
      if (!found.has(token)) found.set(token, path.relative(ROOT, file).replace(/\\/g, '/'))
    }
  }

  return found
}

/**
 * Faded white text — `a('white', 0.7)` — is not a token, so the check above cannot see it, and it is
 * how the pale text the client could not read got in: at 0.70 it is 3.38 : 1 on `navy-600`, the light
 * stop every hero and both auth backdrops open with.
 *
 * One floor for all of it. 0.92 is the lightest alpha that still clears 4.5 : 1 on `navy-600`
 * (4.66 : 1); on the dark navies it is brighter still, and hierarchy is carried by size and weight
 * rather than by fading the words. Anything dimmer than this in a `<Text>` is a defect, not a choice.
 */
const FADED_TEXT_FLOOR = 0.92

function fadedTextBelowFloor(app) {
  const offenders = []

  for (const file of [...walk(path.join(ROOT, 'apps', app, 'src')), ...walk(path.join(ROOT, 'apps', app, 'app'))]) {
    for (const match of readFileSync(file, 'utf8').matchAll(/<Text\b[^>]*?\b(?:a|alpha)\('white',\s*(0?\.\d+)\)/gs)) {
      const value = Number(match[1])
      if (value < FADED_TEXT_FLOOR) offenders.push([value, path.relative(ROOT, file).replace(/\\/g, '/')])
    }
  }

  return offenders
}

const strict = process.argv.includes('--strict')
const rows = []
const findings = []

for (const [app, fgName, bgName, role, where] of PAIRS) {
  const fg = resolve(fgName)
  const bg = resolve(bgName)

  if (!fg.hex || !bg.hex) { findings.push(`${app}: ${fgName} on ${bgName} — a token is missing from the theme`); continue }

  const drawn = over(fg, bg)
  const r = ratio(drawn, bg.hex)
  const need = role === 'body' ? 4.5 : role === 'icon' ? 3 : 0
  const verdict = need === 0 ? 'decoration' : r >= need ? 'AA' : r >= 3 ? 'large text only' : 'FAILS'
  rows.push(`${app.padEnd(9)} ${fgName.padEnd(11)} on ${bgName.padEnd(12)} ${r.toFixed(2).padStart(5)} : 1   ${verdict.padEnd(16)} ${where}`)
  if (r < need) findings.push(`${app}: ${fgName} (${drawn}) on ${bgName} (${bg.hex}) is ${r.toFixed(2)} : 1 — under ${need} : 1 for ${where}`)
}

// The completeness check: a token drawn as TEXT and measured against nothing.
for (const app of ['guard', 'resident']) {
  const measured = new Set(PAIRS.filter(([a, , , role]) => a === app && role === 'body').map(([, fg]) => fg))

  for (const [token, file] of textTokensOf(app)) {
    if (measured.has(token)) continue
    findings.push(`${app}: \`${token}\` is drawn as TEXT (${file}) and no row above measures it — add the pair it sits on, or use a token that is measured`)
  }

  for (const [value, file] of fadedTextBelowFloor(app)) {
    findings.push(`${app}: white faded to ${value} is drawn as TEXT (${file}) — under ${FADED_TEXT_FLOOR}, which is 3.38 : 1 on navy-600 at 0.70. Use ${FADED_TEXT_FLOOR}.`)
  }
}

console.log(rows.join('\n'))

if (findings.length > 0) {
  console.log(`\nAUDIT contrast · ${findings.length} finding(s):`)
  for (const f of findings) console.log(`  ${f}`)
  process.exit(strict ? 1 : 0)
}

console.log('\nAUDIT contrast · every text/surface pair in use meets WCAG 2.1 AA, and every token drawn as text is measured')

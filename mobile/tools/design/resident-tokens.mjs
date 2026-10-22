// M42 §2 / M44 §5: the Resident App's design tokens, written from what the design draws — never eyeballed.
//
// Inputs   _design/RESIDENT_VALUES.json   every CSS value in the ten Resident screen files (tools/design/resident-values.mjs)
//          _design/RESIDENT_DESIGN.json   every text run as the browser renders it (tools/design/design-walk.mjs)
//          _design/screens/resident-app-*.html   the screens' own SVG markup (fill / stroke / stop-color)
//          tools/design/resident-jobs.json      the job each colour, gradient and shadow does (hand-written)
// Outputs  apps/resident/src/design/tokens.ts  the token module rebuilt screens draw with, and nothing else
//          _design/RESIDENT_TOKENS.md          the evidence: each token, the design rules that use it, and where the
//                                              phone's palette is not the design's
//
// It FAILS when the design draws a colour, gradient or shadow that has no job, when a job names something the design
// never draws, or (with --check) when either output is out of date.
//
//   node tools/design/resident-tokens.mjs           write both outputs
//   node tools/design/resident-tokens.mjs --check   fail if anything is missing, invented or stale

import { readFileSync, writeFileSync, readdirSync, mkdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (p) => JSON.parse(readFileSync(join(root, p), 'utf8'))
const values = read('_design/RESIDENT_VALUES.json')
const design = read('_design/RESIDENT_DESIGN.json')
const jobs = read('tools/design/resident-jobs.json')
const core = read('packages/core/theme/tokens.json')
const boardOnly = read('packages/core/theme/board-only.json')
const check = process.argv.includes('--check')
const OUT_TS = join(root, 'apps', 'resident', 'src', 'design', 'tokens.ts')
const OUT_MD = join(root, '_design', 'RESIDENT_TOKENS.md')
// The design's exact colours, registered in the shared palette as rd-<name> (M42 §2.1: every colour by its own name,
// as an exact hex). Generated here so the palette can never drift from the design files.
const OUT_PALETTE = join(root, 'packages', 'core', 'theme', 'resident-design.json')
// The client's locked style guide, byte-identical to the design project's copy (re-read 7 October 2026).
const FOUNDATION = join(root, '_design', 'wireframes', 'GeminiSecure Foundation.dc.html')

const problems = []

/* ------------------------------------------------------------------ Foundation.dc.html */

// M42 §2.1: "From Foundation.dc.html, extract and write into one token module: every colour, by its own name, as an
// exact hex … the type scale … the spacing scale … radii … shadows". Read from the file, never retyped.
const fHtml = readFileSync(FOUNDATION, 'utf8')
const fRoot = /:root\s*\{([\s\S]*?)\}/.exec(fHtml)?.[1] ?? ''
const fArray = (name) => {
  const block = new RegExp(`const ${name}\\s*=\\s*\\[([\\s\\S]*?)\\];`).exec(fHtml)?.[1]
  if (!block) throw new Error(`Foundation has no ${name} array`)
  return [...block.matchAll(/\{([^}]*)\}/g)].map((o) => Object.fromEntries([...o[1].matchAll(/([a-zA-Z]+)\s*:\s*'([^']*)'/g)].map((kv) => [kv[1], kv[2]])))
}
const foundationHex = Object.fromEntries([...fRoot.matchAll(/--([a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{3,8})\s*;/g)].map((m) => [m[1], m[2].toUpperCase()]))
const foundationUse = {}
for (const group of ['NAVY', 'AMBER', 'PURPLE', 'SLATE', 'SEMANTIC', 'DARK']) {
  for (const row of fArray(group)) {
    foundationUse[row.name] = row.use
    if (!foundationHex[row.name]) foundationHex[row.name] = row.hex.toUpperCase() // success, success-100: listed, not in :root
    else if (foundationHex[row.name] !== row.hex.toUpperCase()) problems.push(`Foundation ${row.name}: :root says ${foundationHex[row.name]}, the ${group} table says ${row.hex}`)
  }
}
const foundationRadii = fArray('RADII').map((r) => ({ px: Number.parseFloat(r.px), use: r.use }))
const foundationSpace = fArray('SPACE').map((s) => Number(s.n))
const foundationShadowRows = fArray('SHADOWS')

// The type scale is drawn, not listed: thirteen specimen rows, each a label ("10.5 / 700 · .3px") and a sample whose
// class (or inline style) carries the value. The CSS is the value; the label is checked against it.
const fCss = Object.fromEntries([...fHtml.matchAll(/\.([a-z0-9-]+)\{([^}]*)\}/g)].map((m) => [m[1], m[2]]))
const decl = (body, prop) => new RegExp(`(?:^|;)\\s*${prop}\\s*:\\s*([^;]+)`).exec(body)?.[1].trim()
const foundationType = []
for (const m of fHtml.matchAll(/<div class="t-row"><span class="t-spec">([^<]*)<\/span><span (?:class="([^"]+)"|style="([^"]+)")>([^<]*)<\/span><\/div>/g)) {
  const [, label, cls, inline, sample] = m
  const body = cls ? fCss[cls] ?? '' : inline
  const family = /Poppins/.test(decl(body, 'font-family') ?? '') ? 'Poppins' : 'Inter' // the page's body face is Inter
  const weight = Number(decl(body, 'font-weight') ?? 400)
  const size = Number.parseFloat(decl(body, 'font-size'))
  const lh = decl(body, 'line-height') ? Number(decl(body, 'line-height')) : undefined
  const ls = decl(body, 'letter-spacing') ? Number.parseFloat(decl(body, 'letter-spacing')) : undefined
  const upper = decl(body, 'text-transform') === 'uppercase'
  const [lSize, lWeight] = label.split('/').map((p) => Number.parseFloat(p))
  if (lSize !== size || lWeight !== weight) problems.push(`Foundation type row "${label}" draws ${size} / ${weight}`)
  const key = cls ? cls.replace(/-/g, '') : `u${String(size).replace('.', '')}`
  foundationType.push({ key, label, sample, family, weight, size, lh, ls, upper })
}
if (foundationType.length !== 13) problems.push(`Foundation type scale: expected 13 specimen rows, read ${foundationType.length}`)

/* ------------------------------------------------------------------ colour */

// The design's own palette: its :root, plus the two colours it writes as literals.
const designHex = Object.fromEntries(Object.entries(values.rootVariables).map(([k, v]) => [k.slice(2), v[0].toUpperCase()]))
const shipped = { ...core.colors, ...core.semantic, ...Object.fromEntries(Object.entries(boardOnly.colors).map(([k, v]) => [k, v.hex])) }
const literalToken = { '#000000': 'black', '#FDEDD3': 'b-fdedd3' }
const hexToToken = new Map([...Object.entries(designHex).map(([n, h]) => [h, n]), ...Object.entries(literalToken)])
const hexOf = (token) => designHex[token] ?? shipped[token]?.toUpperCase()

const longHex = (h) => {
  const x = h.replace('#', '').toUpperCase()
  return '#' + (x.length === 3 ? x.split('').map((c) => c + c).join('') : x.slice(0, 6))
}
const toHex = (r, g, b) => '#' + [r, g, b].map((n) => Math.round(Number(n)).toString(16).padStart(2, '0')).join('').toUpperCase()

/** Every colour in a CSS value, as [token, opacity]. */
function colours(value) {
  const found = []
  for (const m of value.matchAll(/var\(--([a-z0-9-]+)\)|rgba?\(([^)]*)\)|(#[0-9a-fA-F]{3,8})\b|\b(white|black)\b/g)) {
    if (m[1]) found.push([m[1], 1])
    else if (m[2]) {
      const [r, g, b, a = '1'] = m[2].split(/[\s,/]+/).filter(Boolean)
      const hex = toHex(r, g, b)
      const token = hexToToken.get(hex)
      if (!token) problems.push(`colour ${m[0]} is not a design colour`)
      else found.push([token, Number(a)])
    } else if (m[3]) {
      const hex = longHex(m[3])
      const token = hexToToken.get(hex)
      if (!token) problems.push(`colour ${m[3]} is not a design colour`)
      else found.push([token, 1])
    } else if (m[4]) found.push([m[4], 1])
  }
  return found
}

const key = (token, opacity) => `${token}@${Number(opacity)}`
const drawn = { ink: new Map(), fill: new Map(), line: new Map() } // key -> Map(rule -> Set(file))
const note = (kind, k, rule, file) => {
  if (!drawn[kind].has(k)) drawn[kind].set(k, new Map())
  const rules = drawn[kind].get(k)
  if (!rules.has(rule)) rules.set(rule, new Set())
  rules.get(rule).add(file)
}

const gradientsDrawn = new Map() // canonical -> rules
const shadowsDrawn = new Map()
const short = (f) => f.replace(/\.html$/, '')

for (const [prop, byValue] of Object.entries(values.colour)) {
  for (const [value, use] of Object.entries(byValue)) {
    if (prop === 'box-shadow') {
      shadowsDrawn.set(canonShadow(value), use)
      continue
    }
    if (value.includes('linear-gradient')) {
      gradientsDrawn.set(canonGradient(value), use)
      continue
    }
    const kind = prop === 'color' || prop === 'fill' || prop === 'stroke' ? 'ink' : prop.startsWith('background') ? 'fill' : 'line'
    for (const [token, opacity] of colours(value)) for (const rule of use.selectors) for (const file of use.files) note(kind, key(token, opacity), rule, short(file))
  }
}

// The screens' SVG markup draws with presentation attributes, not CSS.
const screensDir = join(root, '_design', 'screens')
for (const file of readdirSync(screensDir).filter((f) => f.startsWith('resident-app-') && f.endsWith('.html'))) {
  const html = readFileSync(join(screensDir, file), 'utf8')
  const body = html.slice(html.indexOf('<body'))
  for (const m of body.matchAll(/\b(fill|stroke|stop-color)="([^"]+)"/g)) {
    if (/^(none|currentColor|url\()/.test(m[2])) continue
    for (const [token, opacity] of colours(m[2])) note('ink', key(token, opacity), `svg ${m[1]}`, file.replace('.html', ''))
  }
}

/* ------------------------------------------------------------------ gradients and shadows */

function splitTop(s) {
  const parts = []
  let depth = 0
  let cur = ''
  for (const ch of s) {
    if (ch === '(') depth++
    if (ch === ')') depth--
    if (ch === ',' && depth === 0) { parts.push(cur.trim()); cur = '' } else cur += ch
  }
  if (cur.trim()) parts.push(cur.trim())
  return parts
}

function canonGradient(value) {
  const inner = /linear-gradient\((.*)\)\s*$/.exec(value)[1]
  const [first, ...rest] = splitTop(inner)
  const angle = Number.parseFloat(first)
  const stops = rest.map((s, i) => {
    const [[token, opacity]] = colours(s)
    const at = /(\d+(?:\.\d+)?)%\s*$/.exec(s)
    return [token, opacity, at ? Number(at[1]) / 100 : i / (rest.length - 1)]
  })
  return JSON.stringify({ angle, stops })
}

function canonShadow(value) {
  return JSON.stringify(
    splitTop(value).map((layer) => {
      const [[token, opacity]] = colours(layer)
      const nums = layer.replace(/rgba?\([^)]*\)|var\([^)]*\)|#[0-9a-fA-F]+/g, '').trim().split(/\s+/).map((n) => Number.parseFloat(n))
      const [x, y, blur = 0, spread = 0] = nums
      return [x, y, blur, spread, token, opacity]
    }),
  )
}

/* ------------------------------------------------------------------ coverage */

const jobKey = (entry) => key(entry[0], entry[1])
const KINDS = { ink: ['ink', 'inkAlpha'], fill: ['fill'], line: ['line'] }
for (const [kind, groups] of Object.entries(KINDS)) {
  const named = new Map()
  for (const g of groups) for (const [name, entry] of Object.entries(jobs[g])) named.set(jobKey(entry), `${g}.${name}`)
  // The design's ink includes SVG shapes and stop colours drawn with a palette colour; gradients' stops are covered below.
  for (const k of drawn[kind].keys()) if (!named.has(k)) problems.push(`${kind}: the design draws ${k} (${[...drawn[kind].get(k).keys()].slice(0, 4).join(', ')}) and no job in ${groups.join('/')} names it`)
  for (const [k, job] of named) if (!drawn[kind].has(k)) problems.push(`${job} = ${k} is not drawn by the design as ${kind}`)
}
for (const g of ['ink', 'inkAlpha', 'fill', 'line']) {
  const seen = new Map()
  for (const [name, entry] of Object.entries(jobs[g])) {
    const k = jobKey(entry)
    if (seen.has(k)) problems.push(`${g}.${name} and ${g}.${seen.get(k)} are the same value ${k}`)
    seen.set(k, name)
  }
}
for (const g of ['ink']) for (const [name, [, opacity]] of Object.entries(jobs[g])) if (opacity !== 1) problems.push(`ink.${name} has opacity ${opacity}; translucent text belongs in inkAlpha`)

const gradientJob = new Map(Object.entries(jobs.gradient).map(([name, g]) => [JSON.stringify(g), name]))
for (const [canon, use] of gradientsDrawn) if (!gradientJob.has(canon)) problems.push(`gradient ${canon} (${use.selectors.join(', ')}) has no job`)
for (const [canon, name] of gradientJob) if (!gradientsDrawn.has(canon)) problems.push(`gradient.${name} is not drawn by the design`)
const shadowJob = new Map(Object.entries(jobs.shadow).map(([name, s]) => [JSON.stringify(s.layers), name]))
for (const [canon, use] of shadowsDrawn) if (!shadowJob.has(canon)) problems.push(`shadow ${canon} (${use.selectors.join(', ')}) has no job`)
for (const [canon, name] of shadowJob) if (!shadowsDrawn.has(canon)) problems.push(`shadow.${name} is not drawn by the design`)

/* ------------------------------------------------------------------ type */

const FACES = {
  Inter: { 400: 'Inter-Regular', 500: 'Inter-Medium', 600: 'Inter-SemiBold', 700: 'Inter-Bold' },
  Poppins: { 500: 'Poppins-Medium', 600: 'Poppins-SemiBold', 700: 'Poppins-Bold' },
}
const flags = []
const fonts = new Map() // name -> { face, size, lh, ls, rules: Map(rule -> Set(screen)), weight, family, italic }
const num = (n) => String(n).replace('.', '_')
for (const s of design.screens) {
  for (const r of s.runs) {
    let weight = r.weight
    if (!FACES[r.family]) { problems.push(`${s.id} ${r.rule}: family ${r.family} is not one of the design's two faces`); continue }
    if (!FACES[r.family][weight]) {
      // A <b> inside 700 asks for 900; neither the board's font link nor the phone carries it, so both draw 700.
      const nearest = weight > 700 ? 700 : 400
      flags.push(`${s.id} ${r.rule} asks for ${r.family} ${weight}; no such face is bundled or loaded by the board, so it draws ${nearest}`)
      weight = nearest
    }
    const lh = r.lineHeight === 'normal' ? undefined : Math.round((r.lineHeight / r.size) * 1000) / 1000
    const ls = r.letterSpacing || undefined
    const italic = r.style === 'italic'
    if (italic) flags.push(`${s.id} ${r.rule} is ${r.family} ${weight} italic; no italic ${r.family} face is bundled — the board's browser slants it`)
    const name = `${r.family.toLowerCase()}${weight}_${num(r.size)}${lh ? `_lh${num(lh)}` : ''}${ls ? `_ls${num(ls)}` : ''}${italic ? '_italic' : ''}`
    if (!fonts.has(name)) fonts.set(name, { face: FACES[r.family][weight], size: r.size, lh, ls, italic, rules: new Map() })
    const f = fonts.get(name)
    if (!f.rules.has(r.rule)) f.rules.set(r.rule, new Set())
    f.rules.get(r.rule).add(s.id)
  }
}

/* ------------------------------------------------------------------ closed sets */

const pxNumbers = (group, props) => {
  const set = new Set([0])
  for (const [prop, byValue] of Object.entries(values[group])) {
    if (props && !props.includes(prop)) continue
    for (const v of Object.keys(byValue)) for (const m of v.matchAll(/(-?\d*\.?\d+)px/g)) set.add(Number(m[1]))
  }
  return [...set].sort((a, b) => a - b)
}
const space = pxNumbers('space')
const size = pxNumbers('size', ['width', 'height', 'min-height', 'max-width'])
const corner = pxNumbers('radius')
const strokeSet = new Set()
for (const [prop, byValue] of Object.entries(values.colour)) {
  if (!prop.startsWith('border')) continue
  for (const v of Object.keys(byValue)) for (const m of v.matchAll(/(\d*\.?\d+)px\s+(solid|dashed)/g)) strokeSet.add(Number(m[1]))
}
const stroke = [...strokeSet].sort((a, b) => a - b)

/* ------------------------------------------------------------------ write */

/* ------------------------------------------------------------------ roles and icons */

// M42 §2.1: the type scale "with the role each one plays". A role names a font token AND the design rules that show
// it plays that role, so a role cannot be invented: every rule listed must be one that font is drawn by.
const roleProblems = []
for (const [name, r] of Object.entries(jobs.role)) {
  const f = fonts.get(r.font)
  if (!f) { roleProblems.push(`role.${name}: font ${r.font} is not a style the design draws`); continue }
  for (const rule of r.rules) if (![...f.rules.keys()].some((k) => k === rule || k.endsWith(` ${rule}`))) roleProblems.push(`role.${name}: the design never draws ${rule} in ${r.font}`)
}
problems.push(...roleProblems)

// M42 §2.1 again: "radii — card, field, button, chip, avatar, tile". Each named radius cites the rules that draw it.
for (const [name, r] of Object.entries(jobs.radiusRole)) {
  const use = values.radius['border-radius']?.[r.radius]
  if (!use) { problems.push(`radiusRole.${name}: the design draws no border-radius ${r.radius}`); continue }
  for (const rule of r.rules) if (!use.selectors.some((s) => s === rule || s.endsWith(` ${rule}`))) problems.push(`radiusRole.${name}: ${rule} is not drawn at ${r.radius}`)
}
// 50% is round (a function of the box's side); "0 0 34px 34px" is the bottom two corners only, said in the comment.
const radiusExpr = (v) => (v === '50%' ? 'corner.round' : `corner[${Number.parseFloat(/(\d*\.?\d+)px\s*$/.exec(v)[1])}]`)

// Icon sizes: every width an svg rule sets (the design's icons are its own inline SVGs, lifted into packages/core/ui/icons).
const iconSet = new Set()
for (const [prop, byValue] of Object.entries(values.size)) {
  if (prop !== 'width') continue
  for (const [v, use] of Object.entries(byValue)) if (use.selectors.some((s) => /\bsvg\b/.test(s))) for (const m of v.matchAll(/(\d*\.?\d+)px/g)) iconSet.add(Number(m[1]))
}
const icon = [...iconSet].sort((a, b) => a - b)
const iconsDb = JSON.parse(readFileSync(join(root, 'packages', 'core', 'ui', 'icons', 'icons.json'), 'utf8'))

/* ------------------------------------------------------------------ the exact palette */

// rd-<Foundation's own name> for every Foundation colour, plus the two the Resident screens write as literals and
// Foundation does not list. The screens' :root names are Foundation's, with one exception checked here: their
// `page-bg` (#EEF3F9, the board's field) is Foundation's `res-page-bg`; Foundation's `page-bg` is #E7ECF3.
const SCREEN_TO_FOUNDATION = { 'page-bg': 'res-page-bg' }
for (const [name, hex] of Object.entries(designHex)) {
  const fName = SCREEN_TO_FOUNDATION[name] ?? name
  if (foundationHex[fName] !== hex) problems.push(`the Resident screens' --${name} ${hex} is not Foundation's ${fName} (${foundationHex[fName] ?? 'absent'})`)
}
const rd = (token) => (token === 'b-fdedd3' ? 'rd-fdedd3' : `rd-${SCREEN_TO_FOUNDATION[token] ?? token}`)
const exact = { ...foundationHex, black: '#000000', fdedd3: '#FDEDD3' }
const paletteJson = JSON.stringify(
  {
    note: 'GENERATED by tools/design/resident-tokens.mjs — do not edit. The client\'s design at its exact values, as rd-<name>: every colour of Foundation.dc.html (its 33 :root tokens and the two semantic ones it lists), and the two the Resident screens write as literals (#FDEDD3, and black for the video scrims).',
    colors: Object.fromEntries(Object.entries(exact).sort().map(([t, hex]) => [`rd-${t}`, hex])),
  },
  null,
  2,
) + '\n'

// Foundation's shadows, as layers of its own colours.
const fRgbName = new Map([...Object.entries(exact)].map(([n, h]) => [h, n]))
const foundationShadows = foundationShadowRows.map((row) => {
  const layers = splitTop(row.css).map((layer) => {
    const m = /rgba?\(([^)]*)\)/.exec(layer)
    const [r, g, b, a = '1'] = m[1].split(/[\s,/]+/).filter(Boolean)
    const name = fRgbName.get(toHex(r, g, b))
    if (!name) problems.push(`Foundation shadow "${row.name}" uses ${m[0]}, not a design colour`)
    const [x, y, blur = 0, spread = 0] = layer.replace(m[0], '').trim().split(/\s+/).map((n) => Number.parseFloat(n))
    return [x, y, blur, spread, name, Number(a)]
  })
  const key = row.name.toLowerCase().replace(/ (\w)/g, (_, ch) => ch.toUpperCase())
  return { key, name: row.name, css: row.css, layers }
})

const lit = (n) => (Number.isInteger(n) ? String(n) : String(n))
const objKey = (n) => (n < 0 ? `'${n}'` : Number.isInteger(n) ? String(n) : `'${n}'`)
const colourExpr = ([token, opacity]) => (opacity === 1 ? `c('${rd(token)}')` : `a('${rd(token)}', ${opacity})`)
const stopsExpr = (stops) => stops.map(([token, opacity, at]) => `{ token: '${rd(token)}', at: ${at}${opacity === 1 ? '' : `, opacity: ${opacity}`} }`).join(', ')
const layersExpr = (layers) => layers.map(([x, y, blur, spread, token, opacity]) => `{ x: ${x}, y: ${y}, blur: ${blur}${spread ? `, spread: ${spread}` : ''}, token: '${rd(token)}', opacity: ${opacity} }`).join(', ')

// Only colours the app screens draw; the board's own page background (body) is not the app.
const drawnTokens = new Set([
  ...Object.values(drawn).flatMap((m) => [...m.keys()].map((k) => k.split('@')[0])),
  ...Object.values(jobs.gradient).flatMap((g) => g.stops.map(([t]) => t)),
  ...Object.values(jobs.shadow).flatMap((s) => s.layers.map((l) => l[4])),
])
const drift = Object.keys(designHex).filter((t) => drawnTokens.has(t) && shipped[t] && designHex[t] !== shipped[t].toUpperCase())

// What the screens carry that Foundation does not list — written into the module's header (the 7 October answer:
// "keep a short note in the token module recording which values came from the screens rather than Foundation").
const fTypeSet = new Set(foundationType.map((t) => `${t.family} ${t.weight} ${t.size}`))
const screenTypeSet = new Set([...fonts.values()].map((f) => `${f.face.startsWith('Poppins') ? 'Poppins' : 'Inter'} ${Object.entries(FACES[f.face.startsWith('Poppins') ? 'Poppins' : 'Inter']).find(([, v]) => v === f.face)[0]} ${f.size}`))
const typeOff = [...screenTypeSet].filter((s) => !fTypeSet.has(s))
const spaceOff = space.filter((n) => n > 0 && !foundationSpace.includes(n))
const cornerOff = corner.filter((n) => n > 0 && !foundationRadii.some((r) => r.px === n))
const carriedNote = [
  '// CARRIED FROM THE SCREENS, NOT FOUNDATION (M43 §16: the screen outranks the spec; answered 7 October 2026):',
  `//   type     ${typeOff.length} of the screens' ${screenTypeSet.size} face·weight·size combinations, e.g. the app-bar title Poppins 600 16.5`,
  `//   spacing  ${spaceOff.length} values off Foundation's ${foundationSpace.join('·')} rhythm: ${spaceOff.join(' ')}`,
  `//   radius   ${cornerOff.length} off Foundation's ${foundationRadii.map((r) => r.px).join('·')}: ${cornerOff.join(' ')}`,
  `//   shadow   all ${Object.keys(jobs.shadow).length}; the primary-button glow is .32 on every screen — Foundation's "Mobile primary" .28 is stale`,
  '//   colour   #FDEDD3 (the urgent notice\'s tile) and black (the live room\'s scrims, E5) — not in Foundation',
  '// The earlier darker inks (text-primary, text-body, text-placeholder, text-on-dark, and the darker navy-600,',
  '// slate-500, slate-600, amber-700 of the shared palette) are deliberately absent (D1); tools/gates/resident-dark-inks.mjs',
  '// stops them spreading in the Resident App. Where the design\'s own text colours fail AA: _design/RESIDENT_CONTRAST.md.',
].join('\n')

const ts = `// GENERATED by tools/design/resident-tokens.mjs — do not edit; change tools/design/resident-jobs.json and re-run it.
//
${carriedNote}
//
// The Resident App's design tokens (M42 §2): every colour, gradient, shadow, text style, space, size, corner and stroke
// the client's ten Resident App screen files draw — and nothing they do not. A rebuilt screen takes every visual value
// from here (M44 §2, gate 3); the evidence for each token, and the rules that draw it, is _design/RESIDENT_TOKENS.md.
//
// COLOURS ARE THE DESIGN'S EXACT HEX (M42 §2.1), as rd-<the design's own name> in the shared palette
// (packages/core/theme/resident-design.json, generated with this file). The rest of the app draws ${drift.length} of those names
// darker (D-M081, D-M155, D-M174), and the kit's <Text> maps navy and slate text to two darker inks on a phone (M18B
// §3); rd-* tokens pass through both untouched. Which the rebuilt screens show is the decision in the evidence file,
// "Where the phone's palette is not the design's".
import type { ViewStyle } from 'react-native'
import { a, c, sh, type ColorToken, type FontFace, type GradientSpec } from '@gs/core'

/** Text and icon colours: a palette token, for <Text color> and <Icon color>. */
export const ink = {
${Object.entries(jobs.ink).map(([n, [t]]) => `  ${n}: '${rd(t)}',`).join('\n')}
} as const satisfies Record<string, ColorToken>

/** Translucent text and strokes on the navy screens: a colour string, for style.color. */
export const inkAlpha = {
${Object.entries(jobs.inkAlpha).map(([n, e]) => `  ${n}: ${colourExpr(e)},`).join('\n')}
} as const

/** Backgrounds. */
export const fill = {
${Object.entries(jobs.fill).map(([n, e]) => `  ${n}: ${colourExpr(e)},`).join('\n')}
} as const

/** Borders and rules. */
export const line = {
${Object.entries(jobs.line).map(([n, e]) => `  ${n}: ${colourExpr(e)},`).join('\n')}
} as const

/** CSS gradients, as angle + stops; <Gradient spec> converts them for the box it fills. */
export const gradient = {
${Object.entries(jobs.gradient).map(([n, g]) => `  ${n}: { angle: ${g.angle}, stops: [${stopsExpr(g.stops)}] },`).join('\n')}
} as const satisfies Record<string, GradientSpec>

/** Shadows exactly as the design writes them (boxShadow: iOS, Android 9+, web); elevation is the Android 8 fallback. */
export const shadow = {
${Object.entries(jobs.shadow).map(([n, s]) => `  ${n}: { boxShadow: sh([${layersExpr(s.layers)}]), elevation: ${s.elevation} },`).join('\n')}
} as const satisfies Record<string, ViewStyle>

/**
 * Every text style the design renders — face, size, line-height multiplier (lh), letter spacing (ls) — named by value
 * because the design reuses no scale. Spread into the kit's Text: <Text {...font.inter600_12} color={ink.label}>.
 */
export const font = {
${[...fonts.entries()].sort(([a], [b]) => a.localeCompare(b, 'en', { numeric: true })).map(([n, f]) => `  ${n}: { face: '${f.face}', size: ${f.size}${f.lh ? `, lh: ${f.lh}` : ''}${f.ls ? `, ls: ${f.ls}` : ''} },`).join('\n')}
} as const satisfies Record<string, { face: FontFace; size: number; lh?: number; ls?: number }>

/** The type scale by the role each style plays (M42 §2.1); each role names the design rules that prove it. */
export const role = {
${Object.entries(jobs.role).map(([n, r]) => `  /** ${r.rules.join(' · ')} */\n  ${n}: font.${r.font},`).join('\n')}
} as const

/** Icon sizes the design draws (its icons are its own inline SVGs, lifted into packages/core/ui/icons/icons.json). */
export const icon = { ${icon.map((n) => `${objKey(n)}: ${lit(n)}`).join(', ')} } as const

/** Padding, margin, gap and offset values the design uses (px = pt). space[14] compiles; space[15] would not unless the design used it. */
export const space = { ${space.map((n) => `${objKey(n)}: ${lit(n)}`).join(', ')} } as const

/** Widths and heights the design uses. */
export const size = { ${size.map((n) => `${objKey(n)}: ${lit(n)}`).join(', ')} } as const

/** Corner radii the design uses; round(side) is the design's 50%. */
export const corner = { ${corner.map((n) => `${objKey(n)}: ${lit(n)}`).join(', ')}, round: (side: number) => side / 2 } as const

/** Radii by component (M42 §2.1: card, field, button, chip, avatar, tile); each names the design rules that draw it. */
export const radius = {
${Object.entries(jobs.radiusRole).map(([n, r]) => `  /** ${r.radius}${r.radius.startsWith('0 0') ? ' — the bottom two corners only' : ''} · ${r.rules.join(' · ')} */\n  ${n}: ${radiusExpr(r.radius)},`).join('\n')}
} as const

/** Border widths the design uses. */
export const stroke = { ${stroke.map((n) => `${objKey(n)}: ${lit(n)}`).join(', ')} } as const

/**
 * FOUNDATION.dc.html — the client's locked style guide, every value exact and under its own name (M42 §2.1). It is the
 * named base the screens are drawn from; where a Resident screen draws a value Foundation's scales do not list, the
 * screen's value is in the sections above and wins on that screen (M43 §16). The evidence file sets the two side by side.
 */
export const foundation = {
  /** ${Object.keys(foundationHex).length} colours: Foundation's :root and its semantic pair, with Foundation's own word for each use. */
  colour: {
${Object.keys(foundationHex).map((n) => `    /** ${foundationHex[n]}${foundationUse[n] ? ` · ${foundationUse[n]}` : ''} */\n    '${n}': c('rd-${n}'),`).join('\n')}
  },
  /** The type scale as Foundation draws it — keyed by Foundation's own class, with its specimen. */
  type: {
${foundationType.map((t) => `    /** ${t.family} ${t.weight} · ${t.size}${t.lh ? ` / ${t.lh}` : ''}${t.ls ? ` · ${t.ls}px` : ''}${t.upper ? ' · uppercase' : ''} · "${t.sample.replace(/&amp;/g, '&')}" */\n    ${t.key}: { face: '${FACES[t.family][t.weight]}', size: ${t.size}${t.lh ? `, lh: ${t.lh}` : ''}${t.ls ? `, ls: ${t.ls}` : ''}${t.upper ? ', upper: true' : ''} },`).join('\n')}
  },
  /** The spacing rhythm. */
  space: { ${foundationSpace.map((n) => `${n}: ${n}`).join(', ')} },
  /** Radius, with Foundation's use for each. */
  radius: {
${foundationRadii.map((r) => `    /** ${r.use} */ ${r.px}: ${r.px},`).join('\n')}
  },
  /** Elevation. */
  shadow: {
${foundationShadows.map((s) => `    /** ${s.name} · ${s.css}${s.key === 'mobilePrimary' ? ' — STALE: every Resident screen draws .32; use shadow.primaryButton' : ''} */\n    ${s.key}: { boxShadow: sh([${s.layers.map(([x, y, b, sp, t, o]) => `{ x: ${x}, y: ${y}, blur: ${b}${sp ? `, spread: ${sp}` : ''}, token: 'rd-${t}', opacity: ${o} }`).join(', ')}]) },`).join('\n')}
  },
} as const
`

const rulesList = (rules, n = 10) => {
  const list = [...rules.keys()]
  return list.slice(0, n).join(', ') + (list.length > n ? ` … +${list.length - n}` : '')
}
const filesOf = (rules) => new Set([...rules.values()].flatMap((s) => [...s])).size
const md = [
  '# Resident App design tokens — the evidence',
  '',
  'Generated by `node tools/design/resident-tokens.mjs`; do not edit. Every token in `apps/resident/src/design/tokens.ts` is listed with the design rules that draw it. The design is the ten files in `_design/wireframes/GeminiSecure Resident App Screens/` (byte-identical to the client\'s design project).',
  '',
  '## Where the phone\'s palette is not the design\'s',
  '',
  'The token module draws with the design\'s exact values (rd-*). Today\'s app does not: the shared palette ships these design colours darker, by earlier accessibility decisions, and everything drawn in them (text, fills, shadows) is off by this much.',
  '',
  '| design name | design (rd-*) | today\'s app |',
  '|---|---|---|',
  ...drift.map((t) => `| ${t} | ${designHex[t]} | ${shipped[t].toUpperCase()} |`),
  '',
  'And the kit\'s `<Text>` draws every navy and slate text colour as one of two darker inks on a live phone (`TEXT_TONE` in packages/core/ui/Text.tsx, M18B §3: navy-900 → text-primary #0B1220; navy-800, navy-700, slate-600, slate-500, slate-300 → text-body #1E2A3A). The fidelity build turns that off, which is why the 25 September fidelity pass never saw it. rd-* tokens are not in that map.',
  '',
  '## role — the type scale by job',
  '',
  '| role | font | face · size · lh · ls | the rules that play it |',
  '|---|---|---|---|',
  ...Object.entries(jobs.role).map(([n, r]) => {
    const f = fonts.get(r.font)
    return `| ${n} | ${r.font} | ${f.face} · ${f.size} · ${f.lh ?? 'normal'} · ${f.ls ?? 0} | ${r.rules.join(', ')} |`
  }),
  '',
  `## icon — ${icon.length} sizes, ${Object.keys(iconsDb).length} lifted icons`,
  '',
  `Sizes: ${icon.join(' ')}. The set is the design's own inline SVGs, deduplicated into packages/core/ui/icons/icons.json by the lifter.`,
  '',
  ...['ink', 'inkAlpha', 'fill', 'line'].flatMap((g) => {
    const kind = g === 'inkAlpha' ? 'ink' : g
    return [
      `## ${g}`,
      '',
      '| job | value | design hex | rules (files) |',
      '|---|---|---|---|',
      ...Object.entries(jobs[g]).map(([n, e]) => {
        const rules = drawn[kind].get(jobKey(e)) ?? new Map()
        return `| ${n} | ${e[0]}${e[1] === 1 ? '' : ` @ ${e[1]}`} | ${hexOf(e[0])} | ${rulesList(rules)} (${filesOf(rules)}) |`
      }),
      '',
    ]
  }),
  '## gradient',
  '',
  '| job | angle | stops | rules |',
  '|---|---|---|---|',
  ...Object.entries(jobs.gradient).map(([n, g]) => `| ${n} | ${g.angle}° | ${g.stops.map(([t, o, at]) => `${t}${o === 1 ? '' : ` @ ${o}`} ${at * 100}%`).join(' → ')} | ${(gradientsDrawn.get(JSON.stringify(g))?.selectors ?? []).join(', ')} |`),
  '',
  '## shadow',
  '',
  '| job | layers | elevation | rules (files) |',
  '|---|---|---|---|',
  ...Object.entries(jobs.shadow).map(([n, s]) => {
    const use = shadowsDrawn.get(JSON.stringify(s.layers))
    return `| ${n} | ${s.layers.map(([x, y, b, sp, t, o]) => `${x} ${y} ${b}${sp ? ` ${sp}` : ''} ${t} @ ${o}`).join(', ')} | ${s.elevation} | ${(use?.selectors ?? []).join(', ')} (${(use?.files ?? []).map(short).join('; ')}) |`
  }),
  '',
  `## font — ${fonts.size} text styles`,
  '',
  '| token | face | size | lh | ls | rules (screens) |',
  '|---|---|---|---|---|---|',
  ...[...fonts.entries()].sort(([a], [b]) => a.localeCompare(b, 'en', { numeric: true })).map(([n, f]) => `| ${n} | ${f.face} | ${f.size} | ${f.lh ?? 'normal'} | ${f.ls ?? 0} | ${rulesList(f.rules, 6)} (${filesOf(f.rules)}) |`),
  '',
  '### Faces the design asks for that do not exist',
  '',
  ...(flags.length ? [...new Set(flags)].map((f) => `- ${f}`) : ['- none']),
  '',
  '## Closed sets',
  '',
  `- space (${space.length}): ${space.join(' ')}`,
  `- size (${size.length}): ${size.join(' ')}`,
  `- corner (${corner.length} + round): ${corner.join(' ')}`,
  `- stroke (${stroke.length}): ${stroke.join(' ')}`,
  '',
  '## radius — by component',
  '',
  '| component | radius | rules |',
  '|---|---|---|',
  ...Object.entries(jobs.radiusRole).map(([n, r]) => `| ${n} | ${r.radius} | ${r.rules.join(', ')} |`),
  '',
  '## Foundation.dc.html — the named base',
  '',
  `Read from \`_design/wireframes/GeminiSecure Foundation.dc.html\` (byte-identical to the client's project, re-read 7 October 2026) into \`foundation\` in the token module.`,
  '',
  `### Colour — ${Object.keys(foundationHex).length}`,
  '',
  '| name | exact hex | Foundation\'s use |',
  '|---|---|---|',
  ...Object.keys(foundationHex).map((n) => `| ${n} | ${foundationHex[n]} | ${foundationUse[n] ?? '—'} |`),
  '',
  `The Resident screens' own :root agrees with Foundation name for name, except one: the screens' \`--page-bg\` (#EEF3F9, the boards' field) is Foundation's \`res-page-bg\`; Foundation's \`page-bg\` is #E7ECF3. The screens also write two colours Foundation does not list: #FDEDD3 (the urgent notice's icon tile) and black (the video scrims of the live room, which E5 removes).`,
  '',
  '### Type scale — 13 rows',
  '',
  '| class | face | size | line height | letter spacing | case | specimen |',
  '|---|---|---|---|---|---|---|',
  ...foundationType.map((t) => `| ${t.key} | ${t.family} ${t.weight} | ${t.size} | ${t.lh ?? 'normal'} | ${t.ls ?? 0} | ${t.upper ? 'upper' : '—'} | ${t.sample.replace(/&amp;/g, '&')} |`),
  '',
  `### Spacing — ${foundationSpace.join(' · ')}`,
  '',
  `### Radius — ${foundationRadii.map((r) => `${r.px} (${r.use})`).join(' · ')}`,
  '',
  '### Elevation',
  '',
  ...foundationShadows.map((s) => `- **${s.name}** — ${s.css}`),
  '',
  '## The Resident screens against Foundation',
  '',
  'M43 §16: the design screen outranks the spec files, so where a screen draws a value Foundation\'s scales do not list, the screen\'s value is the one built (the sections above hold every one). This is how far the screens sit from the scales:',
  '',
  (() => {
    const fSizes = new Set(foundationType.map((t) => `${t.family} ${t.weight} ${t.size}`))
    const screenStyles = [...fonts.values()].map((f) => `${f.face.startsWith('Poppins') ? 'Poppins' : 'Inter'} ${Object.entries(FACES[f.face.startsWith('Poppins') ? 'Poppins' : 'Inter']).find(([, v]) => v === f.face)[0]} ${f.size}`)
    const on = [...new Set(screenStyles)].filter((s) => fSizes.has(s))
    return `- **Type**: of the ${new Set(screenStyles).size} face-weight-size combinations the screens draw, ${on.length} are on Foundation's 13-row scale (${on.join(', ') || 'none'}); the other ${new Set(screenStyles).size - on.length} are the screens' own — the app bar title, Poppins 600 16.5, among them — and every one is in the font table above.`
  })(),
  `- **Spacing**: the screens use ${space.filter((n) => n > 0).length} positive spacing values; ${space.filter((n) => foundationSpace.includes(n)).length} are on Foundation's rhythm (${foundationSpace.join(', ')}). Off it: ${space.filter((n) => n > 0 && !foundationSpace.includes(n)).join(', ')}.`,
  `- **Radius**: the screens use ${corner.filter((n) => n > 0).length} radii plus 50%; on Foundation's scale: ${corner.filter((n) => foundationRadii.some((r) => r.px === n)).join(', ')}. Off it: ${corner.filter((n) => n > 0 && !foundationRadii.some((r) => r.px === n)).join(', ')} — the field (14), the button (15) and the hero cards (18) among them.`,
  `- **Shadow**: Foundation's "Mobile primary" is 0 10px 20px navy-600 @ .28; every Resident screen's primary button draws @ .32. Foundation's four elevations are console and frame shadows; the screens' eight are listed above.`,
  `- **Colour use**: Foundation names slate-300 "Placeholders only"; the Resident screens draw placeholders in slate-500 and use slate-300 for times and chevrons.`,
  '',
].join('\n')

if (problems.length) {
  console.error(`resident tokens: ${problems.length} problem(s)`)
  for (const p of [...new Set(problems)]) console.error('  ' + p)
  process.exit(1)
}

if (check) {
  const stale = [[OUT_TS, ts], [OUT_MD, md], [OUT_PALETTE, paletteJson]].filter(([p, text]) => { try { return readFileSync(p, 'utf8') !== text } catch { return true } })
  if (stale.length) {
    console.error(`out of date: ${stale.map(([p]) => p).join(', ')} — run node tools/design/resident-tokens.mjs`)
    process.exit(1)
  }
  console.log(`resident tokens are current · every design value has a job · ${fonts.size} text styles`)
} else {
  mkdirSync(dirname(OUT_TS), { recursive: true })
  writeFileSync(OUT_TS, ts, 'utf8')
  writeFileSync(OUT_MD, md, 'utf8')
  writeFileSync(OUT_PALETTE, paletteJson, 'utf8')
  console.log(`wrote ${OUT_TS}, ${OUT_MD} and ${OUT_PALETTE}`)
  console.log(`  ink ${Object.keys(jobs.ink).length} · inkAlpha ${Object.keys(jobs.inkAlpha).length} · fill ${Object.keys(jobs.fill).length} · line ${Object.keys(jobs.line).length} · gradient ${Object.keys(jobs.gradient).length} · shadow ${Object.keys(jobs.shadow).length} · font ${fonts.size} · role ${Object.keys(jobs.role).length} · icon ${icon.length} · space ${space.length} · size ${size.length} · corner ${corner.length} · stroke ${stroke.length}`)
  console.log(`  palette drift: ${drift.map((t) => `${t} ${designHex[t]}→${shipped[t]}`).join(', ') || 'none'}`)
  if (flags.length) console.log(`  ${new Set(flags).size} face flag(s), see the evidence file`)
}

/**
 * THE BOARD LIFTER — lift the entire <style> block verbatim; never hand-author.
 *
 * React Native has no CSS, so a stylesheet cannot be pasted into it. What CAN be
 * done without retyping a single value is to let a real browser resolve the board's
 * own stylesheet (cascade, inheritance, var(), shorthands, percentages) and read back
 * each element's AUTHORED properties at their COMPUTED values. extract.js does that
 * inside Chromium; this file turns the result into a React Native screen:
 *
 *   apps/<app>/src/lifted/<Id>.tsx     static, faithful, GENERATED — never edited
 *   apps/<app>/src/lifted/index.ts     id → component, for the dev-only /lifted/[id] route
 *   packages/core/ui/icons/icons.json  every single-colour board SVG, deduplicated
 *   packages/core/theme/board-only.json colours a board draws that Foundation lacks
 *
 * A wired screen starts as a copy of its lifted file; the lifted file stays as the
 * measured reference. CSS and RN differ in five places and each is handled here, once:
 *   margin collapse      → vertical gaps are MEASURED from the rendered boxes
 *   flex-shrink default  → CSS 1, RN 0: text-bearing row children get flexShrink 1
 *   inline formatting    → runs of inline boxes become a wrapping row at measured offsets
 *   Text inheritance     → every text run carries its own resolved face/size/colour
 *   grid                 → a wrapping row with measured child widths
 *
 *   node tools/lift/lift.mjs --all | --surface guard-app | --only guard-app-02,guard-app-03
 */

import { chromium } from 'playwright'
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const DESIGN = path.join(ROOT, '_design')
const THEME = path.join(ROOT, 'packages', 'core', 'theme')
const ICONS = path.join(ROOT, 'packages', 'core', 'ui', 'icons', 'icons.json')

const args = process.argv.slice(2)
const flag = (n) => { const i = args.indexOf(n); return i === -1 ? null : args[i + 1] }
const only = flag('--only')?.split(',').map((s) => s.trim()) ?? null
const surfaceArg = flag('--surface')
if (!args.includes('--all') && !only && !surfaceArg) { console.error('usage: lift.mjs --all | --surface <s> | --only <ids>'); process.exit(1) }

const regions = JSON.parse(readFileSync(path.join(DESIGN, 'SCREEN_REGIONS.json'), 'utf8'))
const tokens = JSON.parse(readFileSync(path.join(THEME, 'tokens.json'), 'utf8'))
const boardOnly = JSON.parse(readFileSync(path.join(THEME, 'board-only.json'), 'utf8'))
const iconsDb = existsSync(ICONS) ? JSON.parse(readFileSync(ICONS, 'utf8')) : {}
const extractSrc = readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), 'extract.js'), 'utf8')

/* ---------------------------------------------------------------- colour → token */

const GUARD_FIRST = ['surface-900', 'surface-800', 'surface-700', 'border-700', 'border-600', 'text-2', 'text-3', 'g-navy-900', 'g-navy-300']
const hexToNames = new Map()
const addHex = (name, hex) => { const k = hex.toUpperCase(); hexToNames.set(k, [...(hexToNames.get(k) ?? []), name]) }
for (const [n, h] of Object.entries({ ...tokens.colors, ...tokens.semantic })) addHex(n, h)
for (const [n, e] of Object.entries(boardOnly.colors)) addHex(n, e.hex)

function parseRgb(str) {
  const m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)/.exec(str)
  if (!m) return null
  const hex = '#' + [m[1], m[2], m[3]].map((v) => Math.round(Number(v)).toString(16).padStart(2, '0')).join('').toUpperCase()
  return { hex, alpha: m[4] === undefined ? 1 : Number(m[4]) }
}

let currentSurface = 'guard-app'
let currentBoardRef = ''
function tokenFor(hex) {
  let names = hexToNames.get(hex)
  if (!names) {
    const name = 'b-' + hex.slice(1).toLowerCase()
    boardOnly.colors[name] = { hex, board: currentBoardRef }
    addHex(name, hex)
    names = [name]
  }
  if (names.length === 1) return names[0]
  const guardish = names.filter((n) => GUARD_FIRST.includes(n))
  if (currentSurface === 'guard-app') return guardish[0] ?? names[0]
  return names.find((n) => !GUARD_FIRST.includes(n)) ?? names[0]
}

/** A colour as TypeScript source: c('navy-600') · a('white', 0.14) · 'transparent' */
function colourSrc(css) {
  if (!css || css === 'transparent') return `'transparent'`
  const p = parseRgb(css)
  if (!p) return `'transparent'`
  if (p.alpha === 0) return `'transparent'`
  const t = tokenFor(p.hex)
  return p.alpha === 1 ? `c('${t}')` : `a('${t}', ${Number(p.alpha.toFixed(3))})`
}
const colourToken = (css) => { const p = parseRgb(css); return p && p.alpha === 1 ? tokenFor(p.hex) : null }

/* ---------------------------------------------------------------- small parsers */

function splitTop(str) {
  const out = []; let depth = 0, cur = ''
  for (const ch of str) { if (ch === '(') depth++; if (ch === ')') depth--; if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = '' } else cur += ch }
  if (cur.trim()) out.push(cur.trim())
  return out
}

function parseGradient(bg) {
  const m = /linear-gradient\((.*)\)\s*$/.exec(bg)
  if (!m) return null
  const parts = splitTop(m[1])
  let angle = 180
  const first = parts[0]
  if (/deg$/.test(first)) { angle = parseFloat(first); parts.shift() }
  else if (/^to /.test(first)) { angle = { 'to top': 0, 'to right': 90, 'to bottom': 180, 'to left': 270, 'to bottom right': 135, 'to top right': 45, 'to bottom left': 225, 'to top left': 315 }[first] ?? 180; parts.shift() }
  const stops = parts.map((p) => { const c = parseRgb(p); const pos = /([\d.]+)%\s*$/.exec(p); return { c, at: pos ? Number(pos[1]) / 100 : null } })
  if (stops.some((s) => !s.c)) return null
  stops.forEach((s, i) => { if (s.at === null) s.at = stops.length === 1 ? 0 : i / (stops.length - 1) })
  return { angle, stops }
}

function parseShadow(str) {
  return splitTop(str).map((layer) => {
    const c = parseRgb(layer); const nums = layer.replace(/rgba?\([^)]*\)/, '').trim().split(/\s+/).map(parseFloat)
    return c ? { c, x: nums[0] || 0, y: nums[1] || 0, blur: nums[2] || 0, spread: nums[3] || 0, inset: /inset/.test(layer) } : null
  }).filter(Boolean)
}

function parseTransform(str) {
  const out = []
  for (const m of str.matchAll(/(\w+)\(([^)]*)\)/g)) {
    const vals = m[2].split(',').map((v) => v.trim())
    const len = (v) => (/%$/.test(v) ? `'${v}'` : String(parseFloat(v)))
    if (m[1] === 'translate') { out.push(`{ translateX: ${len(vals[0])} }`); out.push(`{ translateY: ${len(vals[1] ?? '0')} }`) }
    else if (m[1] === 'translateX' || m[1] === 'translateY') out.push(`{ ${m[1]}: ${len(vals[0])} }`)
    else if (m[1] === 'rotate') out.push(`{ rotate: '${vals[0]}' }`)
    else if (m[1] === 'scale') out.push(`{ scale: ${parseFloat(vals[0])} }`)
    else if (m[1] === 'scaleX' || m[1] === 'scaleY') out.push(`{ ${m[1]}: ${parseFloat(vals[0])} }`)
  }
  return out.length ? `[${out.join(', ')}]` : null
}

/* ---------------------------------------------------------------- layout pass */

const near = (v) => (Math.abs(v) < 0.05 ? 0 : Math.round(v * 100) / 100)

function isInlineOnly(node) {
  if (node.type !== 'el') return false
  const kids = node.children.filter((k) => !(k.pseudo && k.style.position === 'absolute'))
  // A box whose ONLY content is inline spans is still a line of text: the parent's own font sets the
  // line's height (the CSS "strut"), so <div 16px><span 12.5px>…</span></div> is 19px tall, not 15.
  // Emitting it as nested Text keeps that; treating the span as a loose box lost 4px per line.
  const hasText = (n) => n.type === 'text' || (n.type === 'el' && n.children.some(hasText))
  if (!kids.some(hasText)) return false
  return kids.every((k) => k.type === 'text' || k.type === 'space' || (k.type === 'el' && k.level === 'inline' && (isInlineOnly(k) || k.children.length === 0)))
}

function layout(node) {
  if (node.type !== 'el') return
  node.m = node.m ?? {}
  if (isInlineOnly(node)) { node.textBlock = true; return }
  if (node.pseudo || !node.content) return // a ::before/::after box: no flow of its own to measure

  const kids = node.children.filter((k) => k.type !== 'space')
  node.children = kids
  const isFlex = /flex|grid/.test(node.display)
  const isGrid = /grid/.test(node.display)
  const row = isFlex && (node.style.flexDirection ?? 'row').startsWith('row')

  if (isFlex) {
    for (const k of kids) {
      k.m = {}
      if (k.type === 'text') continue
      if (k.style?.position === 'absolute') continue
      const cm = k.cm ?? {}
      if (cm.lAuto && cm.rAuto) k.m.alignSelfAuto = true
      else { if (cm.lAuto) k.m.left = `'auto'`; else if (cm.l) k.m.left = cm.l; if (cm.rAuto) k.m.right = `'auto'`; else if (cm.r) k.m.right = cm.r }
      if (cm.tAuto) k.m.top = `'auto'`; else if (cm.t) k.m.top = cm.t
      if (cm.b) k.m.bottom = cm.b
      if (isGrid && k.style.width === undefined) k.style.width = k.rect.w
      // CSS flex items shrink by default; RN's do not. Without this, long text pushes a row wider than the screen.
      if (row && k.type === 'el' && k.style.flex === undefined && k.style.flexShrink === undefined && k.style.width === undefined) k.style.flexShrink = 1
    }
  } else {
    const flow = kids.filter((k) => k.type === 'text' || (k.inFlow && k.style?.position !== 'absolute'))
    const allInlineLevel = flow.length > 0 && flow.every((k) => k.type === 'text' || k.level !== 'block')
    if (allInlineLevel) {
      // An inline formatting context with boxes in it (chips, an icon beside a word): a wrapping row at measured offsets.
      node.style.flexDirection = 'row'; node.style.flexWrap = 'wrap'; node.style.alignItems = 'flex-start'
      const centred = node.textAlign === 'center'
      if (centred) node.style.justifyContent = 'center'
      let lineTop = node.content.top, lineBottom = node.content.top, prev = null
      for (const k of flow) {
        k.m = {}
        const r = k.rect
        const newLine = prev && (r.x < prev.rect.x + prev.rect.w - 0.5 ? r.y >= prev.rect.y + prev.rect.h - 1 || r.x <= prev.rect.x : false)
        if (newLine) { lineTop = lineBottom; prev = null }
        const ml = prev ? r.x - (prev.rect.x + prev.rect.w) : centred ? 0 : r.x - node.content.left
        if (near(ml)) k.m.left = near(ml)
        if (near(r.y - lineTop)) k.m.top = near(r.y - lineTop)
        lineBottom = Math.max(lineBottom, r.y + r.h)
        prev = k
      }
      if (node.autoHeight && near(node.content.bottom - lineBottom) > 0) node.style.paddingBottom = (node.style.paddingBottom ?? 0) + near(node.content.bottom - lineBottom)
    } else {
      // Block flow. Margins collapse in CSS and never in RN, so the gaps are MEASURED, not copied.
      let cursor = node.content.top
      for (const k of flow) {
        k.m = {}
        const top = near(k.rect.y - cursor)
        if (top) k.m.top = top
        cursor = k.rect.y + k.rect.h
        if (k.type === 'text') continue
        // position:relative offsets are already inside the measured box; emitting them too would apply them twice.
        if (k.relative && k.style) { delete k.style.top; delete k.style.bottom }
        const cm = k.cm ?? {}
        if (cm.lAuto && cm.rAuto) k.m.alignSelfAuto = true
        else if (cm.lAuto) k.m.alignSelf = 'flex-end'
        else { if (cm.l) k.m.left = cm.l; if (cm.r) k.m.right = cm.r }
        if (k.level === 'inline-box' && !k.m.alignSelfAuto) k.m.alignSelf = node.textAlign === 'center' ? 'center' : node.textAlign === 'right' ? 'flex-end' : 'flex-start'
      }
      const last = flow[flow.length - 1]
      if (last && node.autoHeight) { const mb = near(node.content.bottom - cursor); if (mb > 0) last.m.bottom = mb }
      for (const k of kids) if (!flow.includes(k)) k.m = k.m ?? {}
    }
  }
  for (const k of kids) layout(k)
}

/* ---------------------------------------------------------------- icons */

const LEAF = { path: 'path', circle: 'circle', rect: 'rect', polyline: 'polyline', line: 'line', ellipse: 'ellipse', polygon: 'polygon' }
const num = (v) => (v === undefined || v === null ? undefined : Number(v))

function iconElOf(shape) {
  const t = LEAF[shape.tag.toLowerCase()]; if (!t) return null
  const a = shape.a
  if (a.opacity !== undefined || a.fillOpacity !== undefined || a.strokeOpacity !== undefined || a.transform || a.blend || a['stroke-dasharray']) return null
  const el = { t }
  if (t === 'path') el.d = a.d
  if (t === 'circle') Object.assign(el, { cx: num(a.cx), cy: num(a.cy), r: num(a.r) })
  if (t === 'rect') Object.assign(el, { x: num(a.x ?? 0), y: num(a.y ?? 0), w: num(a.width), h: num(a.height), ...(a.rx ? { rx: num(a.rx) } : {}) })
  if (t === 'polyline' || t === 'polygon') el.points = a.points
  if (t === 'line') Object.assign(el, { x1: num(a.x1), y1: num(a.y1), x2: num(a.x2), y2: num(a.y2) })
  if (t === 'ellipse') Object.assign(el, { cx: num(a.cx), cy: num(a.cy), rx: num(a.rx), ry: num(a.ry) })
  if (a.stroke && a.stroke !== 'none') { el.sw = a.strokeWidth; if (a.cap) el.cap = a.cap; if (a.join) el.join = a.join }
  if (a.fill && a.fill !== 'none') el.fill = true
  return el
}

const iconKey = (def) => JSON.stringify([def.viewBox ?? '0 0 24 24', def.els])
const iconByKey = new Map(Object.entries(iconsDb).map(([name, def]) => [iconKey(def), name]))

function registerIcon(svg, hint) {
  if (Math.abs(svg.rect.w - svg.rect.h) > 0.6 || svg.shapes.length === 0) return null
  const els = svg.shapes.map(iconElOf)
  if (els.some((e) => e === null)) return null
  const paints = new Set()
  for (const s of svg.shapes) for (const p of [s.a.fill, s.a.stroke]) if (p && p !== 'none') paints.add(p)
  if (paints.size !== 1) return null
  const paint = [...paints][0]
  if (/^url/.test(paint)) return null
  const def = svg.viewBox === '0 0 24 24' ? { els } : { viewBox: svg.viewBox, els }
  const key = iconKey(def)
  let name = iconByKey.get(key)
  if (!name) {
    const base = (hint || 'icon').replace(/[^a-z0-9-]/gi, '-').toLowerCase()
    name = base; let i = 2
    while (iconsDb[name]) name = `${base}-${i++}`
    iconsDb[name] = def; iconByKey.set(key, name)
  }
  return { name, paint }
}

/* ---------------------------------------------------------------- emission */

function makeEmitter() {
  const styles = new Map() // source → name
  const usedNames = new Set()
  const imports = { rn: new Set(['View']), svg: new Set(), core: new Set(['Text', 'useFidelityReady']) }
  const warnings = []

  function styleName(hint, src) {
    if (styles.has(src)) return styles.get(src)
    const base = (hint || 'box').replace(/-([a-z0-9])/gi, (_, c) => c.toUpperCase()).replace(/[^a-zA-Z0-9]/g, '') || 'box'
    let name = /^[0-9]/.test(base) ? 'n' + base : base; let i = 2
    while (usedNames.has(name)) name = `${base}${i++}`
    usedNames.add(name); styles.set(src, name)
    return name
  }

  /** Four equal sides collapse to one property — what a person would have written, and what a wired screen keeps. */
  function compact(src) {
    const s = { ...src }
    const all = (keys) => keys.every((k) => s[k] !== undefined && s[k] === s[keys[0]])
    const fold = (keys, to) => { if (all(keys)) { s[to] = s[keys[0]]; for (const k of keys) delete s[k] } }
    fold(['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'], 'borderRadius')
    fold(['borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth'], 'borderWidth')
    fold(['borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor'], 'borderColor')
    fold(['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'], 'padding')
    fold(['paddingLeft', 'paddingRight'], 'paddingHorizontal')
    fold(['paddingTop', 'paddingBottom'], 'paddingVertical')
    fold(['rowGap', 'columnGap'], 'gap')
    return s
  }

  function styleSrc(rawStyle, m = {}) {
    const style = compact(rawStyle)
    const out = []
    const put = (k, v) => out.push(`${k}: ${v}`)
    for (const [k, v] of Object.entries(style)) {
      if (k.startsWith('__') || v === undefined || v === null) continue
      if (/Color$/.test(k)) { imports.core.add('c'); imports.core.add('a'); put(k, colourSrc(v)); continue }
      put(k, typeof v === 'number' ? v : `'${v}'`)
    }
    if (style.__boxShadow) {
      const layers = parseShadow(style.__boxShadow).filter((l) => !l.inset)
      if (layers.length) { imports.core.add('sh'); put('boxShadow', `sh([${layers.map((l) => `{ x: ${l.x}, y: ${l.y}, blur: ${l.blur}, spread: ${l.spread}, token: '${tokenFor(l.c.hex)}', opacity: ${Number(l.c.alpha.toFixed(3))} }`).join(', ')}])`) }
    }
    if (style.__transform) { const t = parseTransform(style.__transform); if (t) put('transform', t) }
    if (m.top) put('marginTop', m.top); if (m.bottom) put('marginBottom', m.bottom)
    if (m.left) put('marginLeft', m.left); if (m.right) put('marginRight', m.right)
    if (m.alignSelfAuto) put('alignSelf', `'center'`); else if (m.alignSelf && style.alignSelf === undefined) put('alignSelf', `'${m.alignSelf}'`)
    return out.length ? `{ ${out.join(', ')} }` : null
  }

  const FACES = { Poppins: { 500: 'Poppins-Medium', 600: 'Poppins-SemiBold', 700: 'Poppins-Bold' }, Inter: { 400: 'Inter-Regular', 500: 'Inter-Medium', 600: 'Inter-SemiBold', 700: 'Inter-Bold' } }
  function faceOf(t) {
    const fam = FACES[t.family] ?? FACES.Inter
    if (!FACES[t.family]) warnings.push(`font-family "${t.family}" mapped to Inter`)
    if (fam[t.weight]) return fam[t.weight]
    const ws = Object.keys(fam).map(Number); const nearest = ws.reduce((b, w) => (Math.abs(w - t.weight) < Math.abs(b - t.weight) ? w : b))
    warnings.push(`${t.family} ${t.weight} is not bundled; used ${nearest}`)
    return fam[nearest]
  }

  function textProps(t, parent) {
    const p = []; const st = []
    const face = faceOf(t)
    if (!parent || faceOf(parent) !== face) p.push(`face="${face}"`)
    if (!parent || parent.size !== t.size) p.push(`size={${t.size}}`)
    if (t.lh !== undefined && (!parent || parent.lh !== t.lh)) p.push(`lh={${t.lh}}`)
    if (t.ls !== undefined && (!parent || parent.ls !== t.ls)) p.push(`ls={${t.ls}}`)
    if (!parent || parent.color !== t.color) { const tok = colourToken(t.color); if (tok) p.push(`color="${tok}"`); else { imports.core.add('a'); imports.core.add('c'); st.push(`color: ${colourSrc(t.color)}`) } }
    if (!parent && t.align) p.push(`align="${t.align}"`)
    if (t.transform === 'uppercase' && (!parent || parent.transform !== 'uppercase')) p.push('upper')
    else if (t.transform && t.transform !== 'uppercase' && (!parent || parent.transform !== t.transform)) st.push(`textTransform: '${t.transform}'`)
    if (t.decoration && (!parent || parent.decoration !== t.decoration)) st.push(`textDecorationLine: '${t.decoration}'`)
    if (t.italic) st.push(`fontStyle: 'italic'`)
    if (t.tabular) st.push(`fontVariant: ['tabular-nums']`)
    if (!parent && t.opacity !== undefined) st.push(`opacity: ${t.opacity}`)
    if (!parent && t.nowrap) p.push('numberOfLines={1}')
    return { p, st }
  }

  const lit = (s) => `{${JSON.stringify(s)}}`

  function inlineContent(node, indent) {
    let out = ''
    const kids = node.children
    kids.forEach((k, i) => {
      if (k.type === 'space') { out += lit(' '); return }
      if (k.type === 'text') { let s = k.text; if (i === 0) s = s.replace(/^\s+/, ''); if (i === kids.length - 1) s = s.replace(/\s+$/, ''); if (s) out += lit(s); return }
      if (k.type === 'el') {
        const { p, st } = textProps(k.t, node.t)
        if (Object.keys(k.style).some((key) => ['backgroundColor', 'paddingLeft', 'paddingTop', 'borderTopWidth'].includes(key))) warnings.push(`inline <${k.tag}.${k.cls.join('.')}> has box styling that nested Text cannot draw`)
        const stAttr = st.length ? ` style={{ ${st.join(', ')} }}` : ''
        out += `<Text ${p.join(' ')}${stAttr}>${inlineContent(k, indent)}</Text>`
      }
    })
    return out
  }

  function emitTextLeaf(node, indent, parentHint) {
    const { p, st } = textProps(node.t, null)
    const m = node.m ?? {}
    if (m.top) st.push(`marginTop: ${m.top}`); if (m.bottom) st.push(`marginBottom: ${m.bottom}`); if (m.left) st.push(`marginLeft: ${m.left}`)
    const stAttr = st.length ? ` style={{ ${st.join(', ')} }}` : ''
    return `${indent}<Text ${p.join(' ')}${stAttr}>${lit(node.text.trim())}</Text>`
  }

  function emitSvg(node, indent, hint) {
    const st = styleSrc(node.style, node.m)
    const stAttr = st ? ` style={s.${styleName(hint + 'Svg', st)}}` : ''
    const shapes = node.shapes
    if (shapes.length === 2 && shapes.every((x) => x.tag.toLowerCase() === 'circle' && Number(x.a.r) === 12.5) && shapes[1].a.blend === 'multiply') {
      imports.core.add('BrandMark')
      const left = parseRgb(shapes[0].a.fill)?.hex
      const variant = left === '#FFFFFF' ? 'splash' : left === '#1974D2' ? 'authBadge' : 'console'
      return `${indent}<BrandMark size={${node.rect.w}} variant="${variant}"${stAttr} />`
    }
    const icon = registerIcon(node, hint)
    if (icon) {
      imports.core.add('Icon')
      const tok = colourToken(icon.paint)
      if (tok) return `${indent}<Icon name="${icon.name}" size={${node.rect.w}} color="${tok}"${stAttr} />`
    }
    // Anything multi-coloured, translucent, transformed or non-square is drawn inline, exactly as the board draws it.
    const TAGS = { svg: 'Svg', g: 'G', path: 'Path', circle: 'Circle', rect: 'Rect', line: 'Line', polyline: 'Polyline', polygon: 'Polygon', ellipse: 'Ellipse', defs: 'Defs', lineargradient: 'LinearGradient', radialgradient: 'RadialGradient', stop: 'Stop', clippath: 'ClipPath', mask: 'Mask' }
    const paint = (v) => (v === undefined ? null : v === 'none' ? `"none"` : /^url/.test(v) ? `"${v.replace(/"/g, '')}"` : `{${colourSrc(v)}}`)
    function shape(sh, ind) {
      const tag = TAGS[sh.tag.toLowerCase()]; imports.svg.add(tag)
      const at = []
      for (const [k, v] of Object.entries(sh.a)) {
        if (k === 'fill' || k === 'stroke') { const pv = paint(v); if (pv) at.push(`${k}=${pv}`) }
        else if (k === 'stopColor') at.push(`stopColor={${colourSrc(v)}}`)
        else if (k === 'strokeWidth') at.push(`strokeWidth={${v}}`)
        else if (k === 'cap') at.push(`strokeLinecap="${v}"`)
        else if (k === 'join') at.push(`strokeLinejoin="${v}"`)
        else if (k === 'blend') warnings.push('svg mix-blend-mode inside an inline Svg is not drawn')
        else if (['opacity', 'fillOpacity', 'strokeOpacity', 'stopOpacity'].includes(k)) at.push(`${k}={${v}}`)
        else if (k === 'stroke-dasharray') at.push(`strokeDasharray="${v}"`)
        else if (k === 'stroke-dashoffset') at.push(`strokeDashoffset="${v}"`)
        else if (k === 'fill-rule') at.push(`fillRule="${v}"`)
        else if (k === 'clip-rule') at.push(`clipRule="${v}"`)
        else at.push(`${k}="${String(v).replace(/"/g, '')}"`)
      }
      if (/Color|fill=\{|stroke=\{/.test(at.join(' '))) { imports.core.add('c'); imports.core.add('a') }
      if (!sh.c.length) return `${ind}<${tag} ${at.join(' ')} />`
      return `${ind}<${tag} ${at.join(' ')}>\n${sh.c.map((x) => shape(x, ind + '  ')).join('\n')}\n${ind}</${tag}>`
    }
    imports.svg.add('Svg')
    const par = node.par ? ` preserveAspectRatio="${node.par}"` : ''
    return `${indent}<Svg width={${node.rect.w}} height={${node.rect.h}} viewBox="${node.viewBox}"${par} fill="none"${stAttr}>\n${shapes.map((x) => shape(x, indent + '  ')).join('\n')}\n${indent}</Svg>`
  }

  function emit(node, indent, parentHint = 'box') {
    if (node.type === 'text') return emitTextLeaf(node, indent, parentHint)
    const hint = node.cls?.[0] ?? (node.pseudo ? parentHint + 'Pseudo' : parentHint + (node.tag ? node.tag[0].toUpperCase() + node.tag.slice(1) : ''))
    if (node.type === 'svg') return emitSvg(node, indent, node.cls?.[0] ?? parentHint)

    const grad = node.style.__backgroundImage ? parseGradient(node.style.__backgroundImage) : null
    if (node.style.__backgroundImage && !grad) warnings.push(`background-image not lifted on .${node.cls.join('.')}: ${node.style.__backgroundImage.slice(0, 60)}`)

    if (node.textBlock) {
      const boxKeys = Object.keys(node.style).filter((k) => !k.startsWith('__') || k === '__boxShadow')
      const { p, st } = textProps(node.t, null)
      if (boxKeys.length === 0 && !grad) {
        const m = node.m ?? {}
        if (m.top) st.push(`marginTop: ${m.top}`); if (m.bottom) st.push(`marginBottom: ${m.bottom}`)
        if (m.left) st.push(`marginLeft: ${m.left}`); if (m.right) st.push(`marginRight: ${m.right}`)
        if (m.alignSelfAuto) st.push(`alignSelf: 'center'`); else if (m.alignSelf) st.push(`alignSelf: '${m.alignSelf}'`)
        const stAttr = st.length ? ` style={{ ${st.join(', ')} }}` : ''
        return `${indent}<Text ${p.join(' ')}${stAttr}>${inlineContent(node, indent)}</Text>`
      }
      const stAttr = st.length ? ` style={{ ${st.join(', ')} }}` : ''
      const inner = `${indent}  <Text ${p.join(' ')}${stAttr}>${inlineContent(node, indent)}</Text>`
      return wrap(node, hint, indent, inner, grad)
    }

    const inner = node.children.map((k) => emit(k, indent + '  ', hint)).join('\n')
    return wrap(node, hint, indent, inner, grad)
  }

  function wrap(node, hint, indent, inner, grad) {
    const st = styleSrc(node.style, node.m)
    const stAttr = st ? ` style={s.${styleName(hint, st)}}` : ''
    if (grad) {
      imports.core.add('Gradient')
      const spec = `{ angle: ${grad.angle}, stops: [${grad.stops.map((x) => `{ token: '${tokenFor(x.c.hex)}', at: ${Number(x.at.toFixed(4))}${x.c.alpha !== 1 ? `, opacity: ${Number(x.c.alpha.toFixed(3))}` : ''} }`).join(', ')}] }`
      return inner ? `${indent}<Gradient spec={${spec}}${stAttr}>\n${inner}\n${indent}</Gradient>` : `${indent}<Gradient spec={${spec}}${stAttr} />`
    }
    return inner ? `${indent}<View${stAttr}>\n${inner}\n${indent}</View>` : `${indent}<View${stAttr} />`
  }

  return { emit, styles, imports, warnings }
}

/* ---------------------------------------------------------------- run */

const pascal = (id) => id.split('-').map((p) => p[0].toUpperCase() + p.slice(1)).join('')
const appDir = (surface) => path.join(ROOT, 'apps', surface === 'guard-app' ? 'guard' : 'resident', 'src', 'lifted')

const targets = regions.screens.filter((s) => (surfaceArg ? s.surface === surfaceArg : true)).filter((s) => (only ? only.includes(s.id) : true))
const byBoard = new Map()
for (const t of targets) byBoard.set(t.source, [...(byBoard.get(t.source) ?? []), t])

const browser = await chromium.launch()
const report = []

for (const [source, screens] of byBoard) {
  const page = await browser.newPage({ viewport: { width: 1920, height: 1200 }, deviceScaleFactor: 1 })
  await page.goto(pathToFileURL(path.join(DESIGN, 'wireframes', source)).href, { waitUntil: 'load' })
  await page.evaluate(() => document.fonts.ready)
  await page.addScriptTag({ content: extractSrc })

  for (const screen of screens) {
    currentSurface = screen.surface
    currentBoardRef = `${screen.id} · ${source}`
    const { tree, warnings, background } = await page.evaluate((i) => extractScreen(i), screen.index)

    // The root is the phone's screen: it fills whatever screen it is on; its 397x856 box and 48px corners are the bezel's.
    for (const k of ['width', 'height', 'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'borderBottomRightRadius', 'position']) delete tree.style[k]
    Object.assign(tree.style, { flex: 1, overflow: 'hidden', backgroundColor: background })
    tree.autoHeight = false
    layout(tree); tree.m = {}

    const E = makeEmitter()
    const body = E.emit(tree, '    ', 'screen')
    const name = pascal(screen.id)
    const coreImports = [...E.imports.core].sort()
    const svgImports = [...E.imports.svg].filter((x) => x !== 'Svg').sort()
    const styleEntries = [...E.styles.entries()].map(([src, n]) => `  ${n}: ${src},`).join('\n')

    const file = `// GENERATED by tools/lift/lift.mjs — do not edit; re-run the lifter.
// ${screen.id} · ${screen.title}
// Board: ${source} · screen ${screen.number}
// Every value below is the board's own stylesheet as resolved by a browser. A wired screen starts as a copy of this file.
/* eslint-disable */
import { StyleSheet, View } from 'react-native'
${E.imports.svg.size ? `import Svg${svgImports.length ? `, { ${svgImports.join(', ')} }` : ''} from 'react-native-svg'\n` : ''}import { ${coreImports.join(', ')} } from '@gs/core'

export default function ${name}Lifted() {
  useFidelityReady(true)
  return (
${body}
  )
}

const s = StyleSheet.create({
${styleEntries}
})
`
    mkdirSync(appDir(screen.surface), { recursive: true })
    writeFileSync(path.join(appDir(screen.surface), `${name}.tsx`), file, 'utf8')
    const all = [...new Set([...warnings, ...E.warnings])]
    report.push({ id: screen.id, title: screen.title, warnings: all })
  }
  await page.close()
}
await browser.close()

/* index per app — every lifted file that exists, not only this run's */
import { readdirSync } from 'node:fs'
for (const surface of ['guard-app', 'resident-app']) {
  const dir = appDir(surface)
  if (!existsSync(dir)) continue
  const files = readdirSync(dir).filter((f) => /^(Guard|Resident)App\d+\.tsx$/.test(f)).sort()
  const lines = files.map((f) => { const n = f.replace('.tsx', ''); const id = n.replace(/^(Guard|Resident)App(\d+)$/, (_, a, b) => `${a.toLowerCase()}-app-${b}`); return `  '${id}': () => require('./${n}').default,` })
  writeFileSync(path.join(dir, 'index.ts'), `// GENERATED by tools/lift/lift.mjs\n/* eslint-disable */\nimport type { ComponentType } from 'react'\n\nexport const lifted: Record<string, () => ComponentType> = {\n${lines.join('\n')}\n}\n`, 'utf8')
}

writeFileSync(ICONS, JSON.stringify(iconsDb, null, 2) + '\n', 'utf8')
writeFileSync(path.join(THEME, 'board-only.json'), JSON.stringify(boardOnly, null, 2) + '\n', 'utf8')
writeFileSync(path.join(DESIGN, 'LIFT_REPORT.json'), JSON.stringify(report, null, 2) + '\n', 'utf8')

const withWarnings = report.filter((r) => r.warnings.length)
console.log(`lifted ${report.length} screen(s) · ${Object.keys(iconsDb).length} icons · ${Object.keys(boardOnly.colors).length} board-only colours · ${withWarnings.length} with warnings`)
for (const r of withWarnings) console.log(`  ${r.id}: ${r.warnings.slice(0, 4).join(' | ')}${r.warnings.length > 4 ? ` (+${r.warnings.length - 4})` : ''}`)

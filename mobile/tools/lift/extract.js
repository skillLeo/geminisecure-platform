/**
 * Runs INSIDE the browser (page.evaluate). Walks one `.screen` of a board and returns
 * an intermediate tree in which every element carries its AUTHORED properties at their
 * COMPUTED values — so the board's whole <style> block is lifted through the engine
 * that was designed to resolve it (cascade, inheritance, var(), shorthands), and no
 * value is ever hand-authored. tools/lift/lift.mjs turns the tree into React Native.
 *
 * Exposed as a string-able function: (screenIndex) => tree.
 */
/* eslint-disable */
function extractScreen(screenIndex) {
  const SKIP = ['statusbar', 'island', 'home-ind']
  const root = document.querySelectorAll('.screen')[screenIndex]
  const R0 = root.getBoundingClientRect()
  const warnings = []

  const rules = []
  for (const sheet of document.styleSheets) {
    try { for (const r of sheet.cssRules) if (r.type === 1) rules.push(r) } catch (e) { /* cross-origin font sheet */ }
  }

  const round = (n) => Math.round(n * 100) / 100
  const px = (v) => round(parseFloat(v) || 0)
  const rectOf = (r) => ({ x: round(r.left - R0.left), y: round(r.top - R0.top), w: round(r.width), h: round(r.height) })

  function authored(el) {
    const props = new Map()
    for (const r of rules) {
      for (const sel of r.selectorText.split(',')) {
        const s = sel.trim()
        if (/::?(before|after)/.test(s)) continue
        let ok = false
        try { ok = el.matches(s) } catch (e) {}
        if (ok) for (const p of r.style) props.set(p, r.style.getPropertyValue(p).trim())
      }
    }
    for (const p of el.style) props.set(p, el.style.getPropertyValue(p).trim())
    return props
  }

  function pseudoAuthored(el, which) {
    const props = new Map()
    const re = new RegExp('::?' + which + '\\s*$')
    for (const r of rules) {
      for (const sel of r.selectorText.split(',')) {
        const s = sel.trim()
        if (!re.test(s)) continue
        let ok = false
        try { ok = el.matches(s.replace(re, '') || '*') } catch (e) {}
        if (ok) for (const p of r.style) props.set(p, r.style.getPropertyValue(p).trim())
      }
    }
    return props
  }

  function textStyle(cs) {
    const fam = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim()
    const size = px(cs.fontSize)
    const t = { family: fam, weight: parseInt(cs.fontWeight, 10) || 400, size, color: cs.color }
    if (cs.lineHeight !== 'normal') t.lh = round(parseFloat(cs.lineHeight) / size * 1000) / 1000
    if (cs.letterSpacing !== 'normal' && parseFloat(cs.letterSpacing) !== 0) t.ls = px(cs.letterSpacing)
    if (['center', 'right', 'end', 'justify'].includes(cs.textAlign)) t.align = cs.textAlign === 'end' ? 'right' : cs.textAlign
    if (cs.textTransform !== 'none') t.transform = cs.textTransform
    if (cs.textDecorationLine && cs.textDecorationLine !== 'none') t.decoration = cs.textDecorationLine
    if (cs.fontStyle === 'italic') t.italic = true
    if (/tabular-nums/.test(cs.fontVariantNumeric)) t.tabular = true
    if (cs.whiteSpace === 'nowrap') t.nowrap = true
    if (parseFloat(cs.opacity) !== 1) t.opacity = parseFloat(cs.opacity)
    return t
  }

  function boxStyle(el, cs, A, r) {
    const s = {}
    const has = (p) => A.has(p)
    const decl = (p) => A.get(p) || ''
    const dim = (p) => (/%$/.test(decl(p)) ? decl(p) : /calc|auto|content/.test(decl(p)) ? null : px(cs.getPropertyValue(p)))

    if (cs.position === 'absolute' || cs.position === 'fixed') {
      s.position = 'absolute'
      for (const side of ['top', 'right', 'bottom', 'left']) if (has(side) && decl(side) !== 'auto') s[side] = /%$/.test(decl(side)) ? decl(side) : px(cs.getPropertyValue(side))
    }
    else if (cs.position === 'relative') {
      for (const side of ['top', 'right', 'bottom', 'left']) if (has(side) && decl(side) !== 'auto' && px(cs.getPropertyValue(side)) !== 0) s[side] = /%$/.test(decl(side)) ? decl(side) : px(cs.getPropertyValue(side))
    }
    for (const [css, rn] of [['width', 'width'], ['height', 'height'], ['min-width', 'minWidth'], ['min-height', 'minHeight'], ['max-width', 'maxWidth'], ['max-height', 'maxHeight']]) {
      if (has(css)) { const v = dim(css); if (v !== null && !(v === 0 && /^min/.test(css))) s[rn] = v }
    }
    if (has('aspect-ratio') && cs.aspectRatio !== 'auto') s.aspectRatio = cs.aspectRatio.replace(/\s/g, '')

    if (has('flex-grow') || has('flex-shrink') || has('flex-basis')) {
      const g = parseFloat(cs.flexGrow), k = parseFloat(cs.flexShrink), b = cs.flexBasis
      if (g === 1 && k === 1 && (b === '0%' || b === '0px')) s.flex = 1
      else {
        s.flexGrow = g; s.flexShrink = k
        if (b !== 'auto') s.flexBasis = /%$/.test(b) ? b : px(b)
      }
    }
    if (has('align-self') && cs.alignSelf !== 'auto' && cs.alignSelf !== 'normal') s.alignSelf = cs.alignSelf
    if (has('order')) s.order = parseInt(cs.order, 10)

    const isFlex = /flex/.test(cs.display), isGrid = /grid/.test(cs.display)
    if (isFlex) {
      s.flexDirection = cs.flexDirection
      if (cs.flexWrap !== 'nowrap') s.flexWrap = cs.flexWrap
    }
    if (isGrid) { s.flexDirection = 'row'; s.flexWrap = 'wrap' }
    if (isFlex || isGrid) {
      const ai = cs.alignItems, jc = cs.justifyContent
      if (ai !== 'normal' && ai !== 'stretch') s.alignItems = ai === 'start' ? 'flex-start' : ai === 'end' ? 'flex-end' : ai
      if (jc !== 'normal' && jc !== 'flex-start' && jc !== 'start') s.justifyContent = jc === 'end' ? 'flex-end' : jc
      if (cs.rowGap !== 'normal' && px(cs.rowGap)) s.rowGap = px(cs.rowGap)
      if (cs.columnGap !== 'normal' && px(cs.columnGap)) s.columnGap = px(cs.columnGap)
    }

    for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
      const p = px(cs['padding' + side]); if (p) s['padding' + side] = p
      const bw = px(cs['border' + side + 'Width'])
      if (bw && cs['border' + side + 'Style'] !== 'none') { s['border' + side + 'Width'] = bw; s['border' + side + 'Color'] = cs['border' + side + 'Color']; if (cs['border' + side + 'Style'] !== 'solid') s.borderStyle = cs['border' + side + 'Style'] }
    }
    for (const [css, rn] of [['borderTopLeftRadius', 'borderTopLeftRadius'], ['borderTopRightRadius', 'borderTopRightRadius'], ['borderBottomRightRadius', 'borderBottomRightRadius'], ['borderBottomLeftRadius', 'borderBottomLeftRadius']]) {
      const raw = cs[css]
      let v = /%/.test(raw) ? round(Math.min(r.w, r.h) * parseFloat(raw) / 100) : px(raw)
      if (v) s[rn] = Math.min(v, round(Math.max(r.w, r.h)))
    }
    if (cs.backgroundColor !== 'rgba(0, 0, 0, 0)') s.backgroundColor = cs.backgroundColor
    if (cs.backgroundImage !== 'none') s.__backgroundImage = cs.backgroundImage
    if (parseFloat(cs.opacity) !== 1) s.opacity = parseFloat(cs.opacity)
    if (cs.overflow === 'hidden' || cs.overflowX === 'hidden' || cs.overflowY === 'hidden') s.overflow = 'hidden'
    if (has('z-index') && cs.zIndex !== 'auto') s.zIndex = parseInt(cs.zIndex, 10)
    if (cs.boxShadow !== 'none') s.__boxShadow = cs.boxShadow
    if (has('transform') && decl('transform') !== 'none') s.__transform = decl('transform')
    if (cs.mixBlendMode !== 'normal') s.mixBlendMode = cs.mixBlendMode
    return s
  }

  function pseudoNode(el, which) {
    const cs = getComputedStyle(el, '::' + which)
    if (!cs || cs.content === 'none' || cs.content === 'normal' || cs.display === 'none') return null
    const A = pseudoAuthored(el, which)
    const w = px(cs.width), h = px(cs.height)
    const style = boxStyle({ style: [] }, cs, A, { w, h })
    const text = cs.content.replace(/^["']|["']$/g, '')
    const node = { type: 'el', tag: 'pseudo', cls: [which], rect: { x: 0, y: 0, w, h }, style, children: [], inFlow: cs.position !== 'absolute', level: 'block', cm: { t: 0, r: 0, b: 0, l: 0 }, pseudo: true, t: textStyle(cs), display: cs.display }
    if (text) node.children.push({ type: 'text', text, t: textStyle(cs) })
    return node
  }

  function svgNode(el, cs, A) {
    const r = rectOf(el.getBoundingClientRect())
    const shapes = []
    const ATTRS = ['d', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'width', 'height', 'points', 'x1', 'y1', 'x2', 'y2', 'transform', 'id', 'offset', 'gradientUnits', 'gradientTransform', 'fill-rule', 'clip-rule', 'stroke-dasharray', 'stroke-dashoffset', 'fx', 'fy']
    function ser(n) {
      const tag = n.tagName.toLowerCase()
      if (!['g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask'].includes(tag)) { warnings.push('svg <' + tag + '> dropped'); return null }
      const o = { tag: n.tagName, a: {} }
      for (const k of ATTRS) if (n.hasAttribute(k)) o.a[k] = n.getAttribute(k)
      const st = getComputedStyle(n)
      if (tag === 'stop') { o.a.stopColor = st.stopColor; o.a.stopOpacity = st.stopOpacity }
      else if (!['defs', 'lineargradient', 'radialgradient', 'clippath', 'mask'].includes(tag)) {
        if (tag !== 'g') {
          o.a.fill = st.fill; o.a.stroke = st.stroke
          if (st.stroke !== 'none') { o.a.strokeWidth = px(st.strokeWidth); if (st.strokeLinecap !== 'butt') o.a.cap = st.strokeLinecap; if (st.strokeLinejoin !== 'miter') o.a.join = st.strokeLinejoin }
          if (parseFloat(st.fillOpacity) !== 1) o.a.fillOpacity = parseFloat(st.fillOpacity)
          if (parseFloat(st.strokeOpacity) !== 1) o.a.strokeOpacity = parseFloat(st.strokeOpacity)
        }
        if (parseFloat(st.opacity) !== 1) o.a.opacity = parseFloat(st.opacity)
        if (st.mixBlendMode !== 'normal') o.a.blend = st.mixBlendMode
      }
      o.c = [...n.children].map(ser).filter(Boolean)
      return o
    }
    for (const c of el.children) { const s = ser(c); if (s) shapes.push(s) }
    return { type: 'svg', cls: [...el.classList], rect: r, viewBox: el.getAttribute('viewBox') || `0 0 ${r.w} ${r.h}`, par: el.getAttribute('preserveAspectRatio'), shapes, style: boxStyle(el, cs, A, r), inFlow: cs.position !== 'absolute' && cs.position !== 'fixed', level: 'inline-box', cm: margins(cs, A) }
  }

  function margins(cs, A) {
    const m = { t: px(cs.marginTop), r: px(cs.marginRight), b: px(cs.marginBottom), l: px(cs.marginLeft) }
    if (A.get('margin-left') === 'auto') m.lAuto = true
    if (A.get('margin-right') === 'auto') m.rAuto = true
    if (A.get('margin-top') === 'auto') m.tAuto = true
    return m
  }

  function walk(el) {
    if (el.nodeType !== 1) return null
    if ([...el.classList].some((c) => SKIP.includes(c))) return null
    const cs = getComputedStyle(el)
    if (cs.display === 'none' || cs.visibility === 'hidden') return null
    const A = authored(el)
    if (el.tagName.toLowerCase() === 'svg') return svgNode(el, cs, A)
    if (el.tagName === 'IMG') warnings.push('<img> rendered as an empty box')

    const r = rectOf(el.getBoundingClientRect())
    const node = {
      type: 'el', tag: el.tagName.toLowerCase(), cls: [...el.classList], rect: r,
      style: boxStyle(el, cs, A, r), t: textStyle(cs), display: cs.display,
      inFlow: cs.position !== 'absolute' && cs.position !== 'fixed',
      level: cs.display === 'inline' ? 'inline' : /^inline-/.test(cs.display) ? 'inline-box' : 'block',
      relative: cs.position === 'relative', cm: margins(cs, A), autoHeight: !A.has('height') && !A.has('flex-grow') && !A.has('bottom'),
      content: { top: r.y + px(cs.borderTopWidth) + px(cs.paddingTop), bottom: r.y + r.h - px(cs.borderBottomWidth) - px(cs.paddingBottom), left: r.x + px(cs.borderLeftWidth) + px(cs.paddingLeft) },
      textAlign: cs.textAlign, children: [],
    }

    const before = pseudoNode(el, 'before'); if (before) node.children.push(before)
    for (const child of el.childNodes) {
      if (child.nodeType === 3) {
        const raw = child.textContent.replace(/ /g, ' ')
        if (!raw.trim()) { if (/\S/.test(raw) === false && node.children.length && raw.includes(' ')) node.children.push({ type: 'space' }); continue }
        const range = document.createRange(); range.selectNodeContents(child)
        node.children.push({ type: 'text', text: raw.replace(/\s+/g, ' '), rect: rectOf(range.getBoundingClientRect()), t: node.t })
      } else if (child.nodeType === 1) {
        if (child.tagName === 'BR') { node.children.push({ type: 'text', text: '\n', t: node.t, br: true }); continue }
        const n = walk(child); if (n) node.children.push(n)
      }
    }
    const after = pseudoNode(el, 'after'); if (after) node.children.push(after)
    return node
  }

  const tree = walk(root)
  return { tree, warnings, background: getComputedStyle(root).backgroundColor }
}

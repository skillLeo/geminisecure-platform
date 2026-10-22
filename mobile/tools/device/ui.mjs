/**
 * READING A PHONE SCREEN AS TEXT.
 *
 * A screenshot proves what a screen looks like. It cannot prove that "Verify pass" is
 * DISABLED, that a tap target is 48 dp, that there is no way anywhere to start a
 * message to another guard, or that no screen in the Resident App says "Do Not
 * Disturb". Those are the checks in PHONE_CHECK.md that read like sentences, and they
 * are answerable because Android will hand over the accessibility tree:
 *
 *     adb exec-out uiautomator dump /dev/tty
 *
 * React Native publishes every `role`, `aria-label` and piece of text into that tree —
 * the same props the a11y gate already holds this build to — so the tree is a faithful
 * reading of what a screen reader, and therefore a reviewer's accessibility scanner,
 * sees.
 *
 * The parser below is pure: XML in, nodes out. That is deliberate, because it is the
 * half that can be proved on made-up screens with no handset in the room, and it is
 * the half that would silently rot if Android changed an attribute name.
 *
 * WHAT THIS CANNOT DO, so no check is ever written as if it could:
 *  - it cannot see a colour (use a screenshot and pixels.mjs);
 *  - it cannot see an image, a camera preview or a QR code;
 *  - it cannot hear anything;
 *  - a screen drawn inside a WebView or a canvas comes back as one opaque node.
 */

const ATTR = /([a-zA-Z-]+)="([^"]*)"/g

/**
 * @param {string} xml  a uiautomator hierarchy dump
 * @returns {{text: string, desc: string, id: string, cls: string, pkg: string,
 *            clickable: boolean, enabled: boolean, checked: boolean, checkable: boolean,
 *            focused: boolean, scrollable: boolean, selected: boolean, password: boolean,
 *            bounds: {x0:number,y0:number,x1:number,y1:number,w:number,h:number}|null}[]}
 */
export function parseUi(xml) {
  const nodes = []
  const source = String(xml ?? '')
  for (const tag of source.match(/<node\b[^>]*>/g) ?? []) {
    const a = {}
    ATTR.lastIndex = 0
    let m
    while ((m = ATTR.exec(tag)) !== null) a[m[1]] = decode(m[2])

    nodes.push({
      text: a.text ?? '',
      desc: a['content-desc'] ?? '',
      id: a['resource-id'] ?? '',
      cls: a.class ?? '',
      pkg: a.package ?? '',
      clickable: a.clickable === 'true',
      enabled: a.enabled !== 'false',
      checked: a.checked === 'true',
      checkable: a.checkable === 'true',
      focused: a.focused === 'true',
      scrollable: a.scrollable === 'true',
      selected: a.selected === 'true',
      password: a.password === 'true',
      bounds: parseBounds(a.bounds ?? ''),
    })
  }
  return nodes
}

function decode(s) {
  return s
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d)))
    .replace(/&amp;/g, '&')
}

/** `[0,110][1080,220]` */
export function parseBounds(s) {
  const m = /\[(-?\d+),(-?\d+)\]\[(-?\d+),(-?\d+)\]/.exec(String(s))
  if (!m) return null
  const [x0, y0, x1, y1] = m.slice(1).map(Number)
  return { x0, y0, x1, y1, w: x1 - x0, h: y1 - y0 }
}

/** Everything a person could read on this screen, in order. */
export function readable(nodes) {
  return nodes.map((n) => (n.text || n.desc || '').trim()).filter((s) => s.length > 0)
}

/** One string, for "does this screen mention X anywhere at all". */
export function allText(nodes) {
  return readable(nodes).join('\n')
}

const norm = (s) => String(s).replace(/\s+/g, ' ').trim().toLowerCase()

export function findText(nodes, needle, { exact = false } = {}) {
  const want = norm(needle)
  return nodes.filter((n) => {
    const hay = [norm(n.text), norm(n.desc)]
    return exact ? hay.includes(want) : hay.some((h) => h.length > 0 && h.includes(want))
  })
}

export function hasText(nodes, needle, o) {
  return findText(nodes, needle, o).length > 0
}

/** The point to tap for a node. */
export function centre(node) {
  if (!node?.bounds) throw new Error('centre: node has no bounds')
  return { x: Math.round((node.bounds.x0 + node.bounds.x1) / 2), y: Math.round((node.bounds.y0 + node.bounds.y1) / 2) }
}

/** A node's size in dp, which is what the ≥48 / ≥44 rule in the brief is written in. */
export function sizeDp(node, density) {
  if (!node?.bounds) return null
  const f = 160 / density
  return { w: Number((node.bounds.w * f).toFixed(1)), h: Number((node.bounds.h * f).toFixed(1)) }
}

/**
 * Every tap target smaller than the rule. Guard is 48 dp, resident 44.
 * Nodes with no bounds, and zero-sized nodes (React Native publishes a few), are
 * skipped rather than reported — a phantom failure teaches people to ignore the list.
 */
export function smallTargets(nodes, density, minDp) {
  const out = []
  for (const n of nodes) {
    if (!n.clickable || !n.bounds) continue
    if (n.bounds.w <= 0 || n.bounds.h <= 0) continue
    const dp = sizeDp(n, density)
    if (dp.w + 0.5 < minDp || dp.h + 0.5 < minDp) out.push({ label: n.text || n.desc || n.id || n.cls, dp })
  }
  return out
}

/**
 * Anything on this screen that reads like money. This is the on-device half of the
 * guard-no-money gate: the gate reads the source, this reads the screen a guard is
 * actually holding (PHONE_CHECK G6, G12).
 */
export function moneyLike(nodes) {
  return readable(nodes).filter((s) => /(?:\$|\bJMD\b|\bUSD\b)\s?\d|\d[\d,]*\.\d{2}\b/.test(s))
}

/**
 * The window that has focus, reduced to a package name. `null` when it could not be
 * read — never guessed, because "is the operating system's permission box on top?"
 * is the whole of check G27 and a guess there is worse than no answer.
 */
export function focusPackage(focusLine) {
  if (!focusLine) return null
  const m = /([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+)\//.exec(String(focusLine))
  return m ? m[1] : null
}

/** The packages Android's own runtime-permission dialog belongs to. */
export const PERMISSION_UI = ['com.google.android.permissioncontroller', 'com.android.permissioncontroller', 'com.android.packageinstaller']

export function isPermissionDialog(focusLine) {
  const pkg = focusPackage(focusLine)
  return pkg !== null && PERMISSION_UI.includes(pkg)
}

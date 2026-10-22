/**
 * GATE — no style that a PHONE silently drops.
 *
 * The fidelity harness measures Expo WEB. A style that only a browser understands passes
 * every measurement and then vanishes on Android/iOS without an error — which is how the
 * enrolment button lost its amber box on a real handset while the computer drew it correctly.
 * This gate reads every screen and kit component and refuses the classes of style that
 * can disappear that way:
 *
 *   1  a style FUNCTION on anything but the core Pressable      (NativeWind drops it)
 *   2  web-only properties        cursor, userSelect, transition*, animation*, backdropFilter,
 *                                 backgroundImage, whiteSpace, textOverflow, float, willChange…
 *   3  web-only values            position fixed/sticky · display grid/block/inline* ·
 *                                 overflow auto/scroll · CSS length strings ('12px', '1rem',
 *                                 'calc(…)', '100vh') · width/height 'fit-content' etc.
 *   4  fontWeight / fontStyle     with custom font files Android ignores or fakes them; the
 *                                 face is chosen by family (`face="Inter-Bold"`)
 *   5  a colour written as a CSS function with spaces or a named web colour the theme
 *      does not own is already covered by the colour gate.
 *   6  a font face that is not one of the nine loaded files   (Android falls back to Roboto
 *                                 without a word; the browser hides it behind its own fallback)
 *   7  iOS-only shadow props      shadowColor/Offset/Opacity/Radius do nothing on Android —
 *                                 the theme's boxShadow helper draws on both
 *   8  CSS shorthands             background, border: '1px solid', font, textDecoration, flexFlow,
 *                                 flex: '1 1 0%', margin/padding: '0 auto', order, justifyItems,
 *                                 justifySelf, place* — a browser accepts them, Yoga drops them
 *   9  a unitless lineHeight      (1.4 is a multiplier in CSS and 1.4 PIXELS on a phone)
 *  10  a % where a phone wants a number   fontSize, lineHeight, letterSpacing, border widths
 *  11  one-platform decoration    textDecorationColor/Style (iOS only), a dashed/dotted border
 *                                 with per-side widths (Android draws nothing)
 *  12  className                  on anything NativeWind has not been taught (a third-party
 *                                 component keeps it in the browser and drops it on a phone)
 *  13  filter / mixBlendMode / isolation   depend on the OS version — each use must say, on its
 *                                 line, what the phone shows without it (// native-ok: …)
 *
 * A line may opt out with a trailing comment  // native-ok: <reason>  — for the few places
 * that are deliberately per-platform (e.g. `Platform.OS === 'web'` branches).
 *
 *   node tools/gates/native-style-safety.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const SKIP = new Set(['node_modules', '.expo', 'dist', 'android', 'ios', '__tests__', 'generated'])
const ALLOW_FUNCTION_STYLE = 'packages/core/ui/Pressable.tsx'

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    if (SKIP.has(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx$/.test(name) ? [full] : []
  })

const WEB_ONLY_PROPS = [
  'cursor', 'userSelect', 'WebkitUserSelect', 'backdropFilter', 'WebkitBackdropFilter', 'backgroundImage', 'backgroundSize',
  'backgroundPosition', 'backgroundRepeat', 'backgroundClip', 'WebkitBackgroundClip', 'whiteSpace', 'textOverflow', 'wordBreak',
  // Not listed on purpose: `content`, `animation`, `transition`, `clear` — ordinary words in JS objects and navigator
  // options (`animation: 'fade'`); their longhands below are what a stylesheet habit actually types.
  'overflowWrap', 'float', 'willChange', 'listStyle', 'visibility', 'objectPosition', 'touchAction',
  'scrollSnapType', 'scrollSnapAlign', 'scrollBehavior', 'clipPath', 'maskImage', 'WebkitMaskImage', 'textShadow',
  'transitionProperty', 'transitionDuration', 'transitionDelay', 'transitionTimingFunction',
  'animationName', 'animationDuration', 'animationDelay', 'animationIterationCount', 'animationTimingFunction',
  'gridTemplateColumns', 'gridTemplateRows', 'gridColumn', 'gridRow', 'gridArea', 'columnCount', 'verticalAlign', 'tableLayout',
]

const RULES = [
  { why: 'web-only style property — a phone ignores it silently', re: new RegExp(`(?<![\\w.])(${WEB_ONLY_PROPS.join('|')})\\s*:\\s*(?!\\s*\\()`, 'g'), inStyleOnly: true },
  { why: "position 'fixed'/'sticky' does not exist on a phone", re: /\bposition\s*:\s*['"](fixed|sticky)['"]/g },
  { why: 'display value a phone does not have (only flex / none / contents)', re: /\bdisplay\s*:\s*['"](grid|block|inline|inline-block|inline-flex|table|table-cell|inline-grid)['"]/g },
  { why: "overflow 'auto'/'scroll' is web-only — use a ScrollView", re: /\boverflow[XY]?\s*:\s*['"](auto|scroll)['"]/g },
  { why: 'CSS length string — a phone wants a number (or a % string)', re: /\b(width|height|minWidth|minHeight|maxWidth|maxHeight|top|right|bottom|left|margin\w*|padding\w*|gap|rowGap|columnGap|fontSize|lineHeight|letterSpacing|borderRadius|border\w*Width|border\w*Radius)\s*:\s*['"](-?[\d.]+(px|rem|em|vh|vw|pt)|calc\(|fit-content|max-content|min-content|auto\s)/g },
  { why: 'fontWeight/fontStyle on a custom font is ignored or faked on Android — choose the face (face="Inter-Bold")', re: /\b(fontWeight|fontStyle)\s*:\s*['"\d]/g },
  { why: "style FUNCTION — NativeWind drops it on a phone; use Pressable from '@gs/core'", re: /\bstyle=\{\s*\(\s*\{?\s*\w*\s*\}?\s*\)\s*=>/g, functionStyle: true },
  { why: 'iOS-only shadow property — Android draws nothing; use the theme boxShadow helper', re: /(?<![\w.])(shadowColor|shadowOffset|shadowOpacity|shadowRadius)\s*:/g },
  { why: 'CSS shorthand a phone drops — write the longhand (backgroundColor, borderWidth + borderColor, textDecorationLine, flexDirection + flexWrap…)', re: /(?<![\w.])(background|font|textDecoration|flexFlow|outline|placeItems|placeContent|placeSelf|justifyItems|justifySelf|order)\s*:\s*['"\d]/g },
  { why: "CSS multi-value string — a phone wants one value per property ('0 auto', '1px solid', '1 1 0%')", re: /(?<![\w.])(margin|padding|border|borderTop|borderRight|borderBottom|borderLeft|borderRadius|flex|inset|gap)\s*:\s*['"][^'"]*\s[^'"]*['"]/g },
  // type() takes a multiplier and turns it into pixels, so what is inside its call is not a style.
  { why: 'unitless lineHeight — a multiplier in a browser, that many PIXELS on a phone', re: /(?<![\w.])lineHeight\s*:\s*[0-3](\.\d+)?\s*[,}]/g, strip: /\btype\([^)]*\)/g },
  { why: 'a % string where a phone wants a number', re: /(?<![\w.])(fontSize|lineHeight|letterSpacing|border\w*Width)\s*:\s*['"][\d.]+%['"]/g },
  { why: 'iOS-only text decoration — Android draws the line in the text colour, solid', re: /(?<![\w.])(textDecorationColor|textDecorationStyle)\s*:/g },
  { why: 'dashed/dotted border with per-side widths — Android draws no border at all; use one borderWidth', re: /borderStyle\s*:\s*['"](dashed|dotted)['"][^}]*\bborder(Top|Right|Bottom|Left)Width\b|\bborder(Top|Right|Bottom|Left)Width\b[^}]*borderStyle\s*:\s*['"](dashed|dotted)['"]/g },
  { why: 'depends on the OS version — say on this line what the phone shows without it (// native-ok: …)', re: /(?<![\w.])(filter|mixBlendMode|isolation)\s*:\s*['"]/g },
  { why: 'className on a component NativeWind has not been taught is dropped on a phone — use style', re: /<(?!(?:View|Text|ScrollView|Image|TextInput|Pressable|SafeAreaView|KeyboardAvoidingView|FlatList|SectionList|ActivityIndicator|Switch|Modal)\b)[A-Z][\w.]*\b[^>]*\bclassName=/g },
]

// The nine faces that are really loaded (packages/core/theme/fonts.ts). Any other family name is a
// silent fall-back to the system font on Android.
const FACES = new Set([...readFileSync(path.join(ROOT, 'packages', 'core', 'theme', 'fonts.ts'), 'utf8').matchAll(/^\s*'([\w-]+)'\s*:\s*require\(/gm)].map((m) => m[1]))
const FACE_RE = /(?<![\w.])(?:fontFamily\s*:|face=)\s*\{?\s*['"]([^'"]+)['"]/g

const offenders = []
let filesScanned = 0

for (const file of [...walk(path.join(ROOT, 'apps')), ...walk(path.join(ROOT, 'packages', 'core', 'ui'))]) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/')
  // The lifted files are generated, dev-only references; what is checked is what ships as a screen.
  if (rel.includes('/src/lifted/')) continue
  filesScanned++
  const source = readFileSync(file, 'utf8')
  const lines = source.split('\n')
  // A style function is safe when (and only when) the element it sits on is the core Pressable,
  // which is what every file imports (the no-rn-pressable gate guarantees it). Anything else — a
  // View, a TouchableOpacity, a third-party button — gets the function dropped.
  const importsCorePressable = /import\s*\{[^}]*\bPressable\b[^}]*\}\s*from\s*['"](@gs\/core|\.\.?\/Pressable|\.\/Pressable)['"]/.test(source)

  lines.forEach((line, i) => {
    if (/\/\/\s*native-ok:/.test(line)) return
    const code = line.replace(/\/\/.*$/, '')
    if (/^\s*(\*|\/\*)/.test(line)) return
    for (const m of code.matchAll(FACE_RE)) {
      if (!FACES.has(m[1])) offenders.push(`${rel}:${i + 1}  font face "${m[1]}" is not one of the loaded files — Android falls back to the system font silently (packages/core/theme/fonts.ts)`)
    }
    for (const rule of RULES) {
      rule.re.lastIndex = 0
      if (!rule.re.test(rule.strip ? code.replace(rule.strip, '') : code)) continue
      if (rule.functionStyle) {
        if (rel === ALLOW_FUNCTION_STYLE) continue
        // Walk back to the element this `style=` belongs to.
        let tag = null
        for (let j = i; j >= Math.max(0, i - 12) && tag === null; j--) tag = /<([A-Z][\w.]*)\b(?![^<]*>)/.exec(lines[j])?.[1] ?? /<([A-Z][\w.]*)/.exec(lines[j])?.[1] ?? null
        if (tag === 'Pressable' && importsCorePressable) continue
        offenders.push(`${rel}:${i + 1}  ${rule.why}  (on <${tag ?? '?'}>)`)
        continue
      }
      offenders.push(`${rel}:${i + 1}  ${rule.why}\n      ${code.trim().slice(0, 140)}`)
    }
  })
}

if (offenders.length > 0) {
  console.log(`GATE native-style · FAIL — ${offenders.length} style(s) a phone would silently drop:`)
  for (const o of offenders) console.log(`  ${o}`)
  process.exit(1)
}
console.log(`GATE native-style · ${filesScanned} screen/kit files hold no style a phone silently drops`)

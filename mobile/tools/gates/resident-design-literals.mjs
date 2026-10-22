/**
 * GATE · a rebuilt Resident screen draws with the design's tokens and nothing else (M44 §2, gate 3).
 *
 * Every file of every screen listed in apps/resident/src/design/rebuilt.json is read, and any visual value written
 * as a literal fails: a colour (#hex, rgb(), a palette token named directly — color="navy-600", c('…'), a('…', n)),
 * a hand-built shadow or gradient (sh([…]), spec={{…}}), or a number on a size, spacing, radius, border, type or
 * icon property. Each must come from apps/resident/src/design/tokens.ts (ink, inkAlpha, fill, line, gradient,
 * shadow, font, space, size, corner, stroke), which is generated from the design and holds only values it draws.
 *
 * Zero is allowed (it is "none", not a measurement). flex, opacity and zIndex are not design dimensions.
 *
 * And ONE PATH (M42 Stage 0 report §4.1): a rebuilt screen's file never reads `isFidelity`. Fidelity mode may swap in
 * the design's data and freeze the clock — in the screen's hook or its fixtures — and nothing else, so the screen the
 * checks measure is the screen a phone shows.
 *
 *   node tools/gates/resident-design-literals.mjs             the gate
 *   node tools/gates/resident-design-literals.mjs --selftest  prove it catches each kind of literal
 */

import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const MANIFEST = path.join(ROOT, 'apps', 'resident', 'src', 'design', 'rebuilt.json')

const DIMENSION = /\b(margin|marginTop|marginBottom|marginLeft|marginRight|marginHorizontal|marginVertical|marginStart|marginEnd|padding|paddingTop|paddingBottom|paddingLeft|paddingRight|paddingHorizontal|paddingVertical|paddingStart|paddingEnd|gap|rowGap|columnGap|top|bottom|left|right|start|end|width|height|minWidth|minHeight|maxWidth|maxHeight|flexBasis|borderRadius|borderTopLeftRadius|borderTopRightRadius|borderBottomLeftRadius|borderBottomRightRadius|borderWidth|borderTopWidth|borderBottomWidth|borderLeftWidth|borderRightWidth|fontSize|lineHeight|letterSpacing|elevation)\s*:\s*(-?(?:\d*\.)?\d+)\b/g
const RULES = [
    { name: 'colour literal', re: /(['"`]#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})['"`]|\brgba?\(|\bhsla?\()/g },
    { name: 'palette token named directly (use ink.*)', re: /\bcolor=["'][a-z][a-z0-9-]*["']/g },
    { name: 'palette call (use fill.*, line.* or inkAlpha.*)', re: /\b[ca]\(\s*['"][a-z][a-z0-9-]*['"]/g },
    { name: 'hand-built shadow (use shadow.*)', re: /\bsh\(\s*\[/g },
    { name: 'hand-built gradient (use gradient.*)', re: /\bspec=\{\{/g },
    { name: 'type size/line-height/spacing prop (use font.*)', re: /\b(size|lh|ls)=\{\s*-?(?:\d*\.)?\d+\s*\}/g },
    { name: 'reads isFidelity (one path: swap the data in the hook, never the drawing)', re: /\bisFidelity\b/g },
]

export function scan(source) {
    // Comments are documentation; blank them, keeping newlines so line numbers stay true.
    const code = source.replace(/\/\*[\s\S]*?\*\//g, (b) => b.replace(/[^\n]/g, ' ')).replace(/\/\/.*$/gm, '')
    const found = []
    code.split(/\r?\n/).forEach((line, i) => {
        for (const r of RULES) for (const m of line.matchAll(r.re)) found.push({ line: i + 1, kind: r.name, text: m[0] })
        for (const m of line.matchAll(DIMENSION)) if (Number(m[2]) !== 0) found.push({ line: i + 1, kind: `number on ${m[1]} (use space/size/corner/stroke/font)`, text: m[0] })
    })
    return found
}

if (process.argv.includes('--selftest')) {
    const bad = [
        `const s = { box: { marginTop: 14, borderRadius: 16, backgroundColor: c('navy-100') } }`,
        `<Text face="Inter-SemiBold" size={12} color="slate-600">x</Text>`,
        `<View style={{ boxShadow: sh([{ x: 0, y: 8, blur: 20, token: 'navy-800', opacity: 0.07 }]) }} />`,
        `<Gradient spec={{ angle: 135, stops: [] }} />`,
        `const k = { color: '#1974D2', border: 'rgba(255,255,255,.16)', width: 34.5, lineHeight: 1.5 }`,
        `const label = isFidelity ? board.title : live.title`,
    ].join('\n')
    const good = [
        `const s = { box: { marginTop: space[14], borderRadius: corner[16], backgroundColor: fill.tint, flex: 1, opacity: 0.5, zIndex: 2, padding: 0 } }`,
        `<Text {...font.inter600_12} color={ink.label}>x</Text>`,
        `<View style={shadow.card} /> <Gradient spec={gradient.header} />`,
        `// marginTop: 14 in a comment is fine`,
    ].join('\n')
    const hits = scan(bad)
    const kinds = new Set(hits.map((h) => h.kind.split(' (')[0]))
    const want = ['colour literal', 'palette token named directly', 'palette call', 'hand-built shadow', 'hand-built gradient', 'type size/line-height/spacing prop', 'reads isFidelity', 'number on marginTop', 'number on borderRadius', 'number on width', 'number on lineHeight']
    const missing = want.filter((w) => ![...kinds].some((k) => k.startsWith(w)))
    const falsePositives = scan(good)
    if (missing.length || falsePositives.length) {
        console.error('SELFTEST FAILED')
        if (missing.length) console.error('  not caught: ' + missing.join(', '))
        for (const f of falsePositives) console.error(`  false positive line ${f.line}: ${f.kind} ${f.text}`)
        process.exit(1)
    }
    console.log(`SELFTEST resident-design-literals · ${hits.length} literals caught across ${want.length} kinds, 0 false positives`)
    process.exit(0)
}

// Imported for scan() (by a check that wants the counts), the gate itself does not run.
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const manifest = existsSync(MANIFEST) ? JSON.parse(readFileSync(MANIFEST, 'utf8')) : { screens: {} }
    const offences = []
    let files = 0
    for (const [screen, entry] of Object.entries(manifest.screens)) {
        for (const rel of entry.files) {
            const full = path.join(ROOT, rel)
            if (!existsSync(full)) { offences.push(`${screen}: ${rel} is listed but does not exist`); continue }
            files++
            for (const f of scan(readFileSync(full, 'utf8'))) offences.push(`${screen}  ${rel}:${f.line}  ${f.kind}  ${f.text}`)
        }
    }

    if (offences.length) {
        console.error(`GATE FAILED · ${offences.length} literal(s) in rebuilt Resident screens:\n`)
        for (const o of offences) console.error('  ' + o)
        console.error('\nEvery visual value comes from apps/resident/src/design/tokens.ts (generated from the design).')
        process.exit(1)
    }
    console.log(`GATE resident-design-literals · ${Object.keys(manifest.screens).length} rebuilt screen(s), ${files} file(s), no literals`)
}

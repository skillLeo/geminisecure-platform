/**
 * Reads the vertical metrics out of the bundled TTFs and writes
 * packages/core/theme/font-metrics.json.
 *
 * WHY. The boards almost never set line-height, so the browser uses `normal`,
 * which is (ascent + descent + lineGap) / unitsPerEm of the font. React Native
 * must be given an explicit lineHeight or text height differs between Android
 * and iOS, so the ratio is read from the font file itself — not remembered, not
 * measured off a screenshot. Chromium uses the hhea table unless the font sets
 * USE_TYPO_METRICS (OS/2 fsSelection bit 7), in which case it uses the typo values.
 */

import { readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const FONTS = path.join(ROOT, 'packages', 'core', 'assets', 'fonts')
const OUT = path.join(ROOT, 'packages', 'core', 'theme', 'font-metrics.json')

function tables(buf) {
    const n = buf.readUInt16BE(4)
    const out = {}
    for (let i = 0; i < n; i++) {
        const o = 12 + i * 16
        out[buf.toString('ascii', o, o + 4)] = { offset: buf.readUInt32BE(o + 8), length: buf.readUInt32BE(o + 12) }
    }
    return out
}

function postscriptName(buf, t) {
    const base = t.name.offset
    const count = buf.readUInt16BE(base + 2)
    const strings = base + buf.readUInt16BE(base + 4)
    for (let i = 0; i < count; i++) {
        const r = base + 6 + i * 12
        const platform = buf.readUInt16BE(r)
        const nameId = buf.readUInt16BE(r + 6)
        const len = buf.readUInt16BE(r + 8)
        const off = buf.readUInt16BE(r + 10)
        if (nameId !== 6) continue
        const raw = buf.subarray(strings + off, strings + off + len)
        if (platform === 3 || platform === 0) {
            let s = ''
            for (let k = 0; k + 1 < raw.length; k += 2) s += String.fromCharCode(raw.readUInt16BE(k))
            return s
        }
        return raw.toString('latin1')
    }
    return null
}

const result = {}

for (const file of readdirSync(FONTS).filter((f) => f.endsWith('.ttf')).sort()) {
    const buf = readFileSync(path.join(FONTS, file))
    const t = tables(buf)
    const upm = buf.readUInt16BE(t.head.offset + 18)
    const hhea = {
        ascender: buf.readInt16BE(t.hhea.offset + 4),
        descender: buf.readInt16BE(t.hhea.offset + 6),
        lineGap: buf.readInt16BE(t.hhea.offset + 8),
    }
    const os2 = t['OS/2'].offset
    const fsSelection = buf.readUInt16BE(os2 + 62)
    const typo = {
        ascender: buf.readInt16BE(os2 + 68),
        descender: buf.readInt16BE(os2 + 70),
        lineGap: buf.readInt16BE(os2 + 72),
    }
    const useTypo = (fsSelection & 0x80) !== 0
    const m = useTypo ? typo : hhea
    const normal = (m.ascender - m.descender + m.lineGap) / upm

    result[file.replace('.ttf', '')] = {
        postscriptName: postscriptName(buf, t),
        unitsPerEm: upm,
        hhea,
        typo,
        useTypoMetrics: useTypo,
        // line-height: normal, as a multiple of font-size
        normalLineHeight: Number(normal.toFixed(5)),
        ascent: Number((m.ascender / upm).toFixed(5)),
        descent: Number((-m.descender / upm).toFixed(5)),
        lineGap: Number((m.lineGap / upm).toFixed(5)),
    }
}

writeFileSync(OUT, JSON.stringify(result, null, 2) + '\n', 'utf8')

for (const [name, m] of Object.entries(result)) {
    console.log(`${name.padEnd(22)} ps=${String(m.postscriptName).padEnd(24)} normal=${m.normalLineHeight}  (typo:${m.useTypoMetrics})`)
}

/**
 * GATE · a colour literal in a component is a defect.
 *
 * Every colour comes from packages/core/theme. This walks the app and UI source
 * and fails on any #hex, rgb()/rgba() or hsl() literal outside the theme folder,
 * printing file:line for each. There is no escape comment: if a board draws a
 * colour Foundation lacks, it goes into theme/board-only.json with the board that
 * draws it, and the component names the token.
 *
 * Scanned:   apps/<app>/app, apps/<app>/src, packages/core/<everything but theme>
 * Exempt:    packages/core/theme, any __fixtures__ folder (board data, not styling),
 *            *.test.* files, node_modules, generated files
 */

import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')

const ROOTS = ['apps', 'packages/core'].map((p) => path.join(ROOT, p)).filter(existsSync)
const SKIP_DIRS = new Set(['node_modules', '.expo', 'dist', 'web-build', 'android', 'ios', '__fixtures__', 'assets'])
const EXEMPT = [path.join(ROOT, 'packages', 'core', 'theme')]
const EXT = /\.(ts|tsx|js|jsx)$/
const SKIP_FILE = /(\.test\.|\.spec\.|\.config\.(js|ts|cjs|mjs)$|\.d\.ts$)/

// A hex counts only as a WHOLE quoted value ('#1974D2'). "receipt #4471" inside copy is a receipt number, not a colour.
const LITERAL = /(['"`]#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})['"`]|\brgba?\(|\bhsla?\()/g

const offences = []

function walk(dir) {
    for (const name of readdirSync(dir)) {
        const full = path.join(dir, name)
        if (EXEMPT.some((e) => full.startsWith(e))) continue
        const st = statSync(full)
        if (st.isDirectory()) {
            if (!SKIP_DIRS.has(name)) walk(full)
            continue
        }
        if (!EXT.test(name) || SKIP_FILE.test(name)) continue

        // A hex in a comment is documentation, not styling. Block comments are blanked
        // out with their newlines kept, so reported line numbers stay true.
        const source = readFileSync(full, 'utf8').replace(/\/\*[\s\S]*?\*\//g, (block) => block.replace(/[^\n]/g, ' '))
        const original = readFileSync(full, 'utf8').split(/\r?\n/)
        const lines = source.split(/\r?\n/)
        lines.forEach((stripped, i) => {
            const line = original[i] ?? stripped
            const code = stripped.replace(/\/\/.*$/, '')
            for (const m of code.matchAll(LITERAL)) {
                offences.push(`${path.relative(ROOT, full).replace(/\\/g, '/')}:${i + 1}  ${m[0]}  ${line.trim().slice(0, 100)}`)
            }
        })
    }
}

ROOTS.forEach(walk)

if (offences.length > 0) {
    console.error(`GATE FAILED · ${offences.length} colour literal(s) outside packages/core/theme:\n`)
    for (const o of offences) console.error('  ' + o)
    console.error('\nUse a token. A board-only colour goes in packages/core/theme/board-only.json with its board.')
    process.exit(1)
}

console.log('GATE tokens · no colour literals outside packages/core/theme')

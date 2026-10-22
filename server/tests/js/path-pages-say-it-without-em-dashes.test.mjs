/**
 * Sunday's residents and meetings pages say it without an em dash (M53 Law 3; M83 item 5; steps 3 and 7).
 *
 *   `Pages/Estate/Residents/Index.vue` and `Show.vue` (step 3, the register and Lot 47's household) and
 *   `Pages/Estate/Governance/Meetings.vue` and `Meeting.vue` (step 7, the AGM). Read from the source with its comments
 *   taken out: a comment may still use one, what a person reads may not. The lone "—" that stands for an empty value
 *   waits on Law 5's decision and is left alone.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const PAGES = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/Pages/Estate')

const drawn = (file) =>
    fs
        .readFileSync(path.join(PAGES, file), 'utf8')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1')

const dashes = (source) =>
    [...source.matchAll(/[^\n]{0,40}—[^\n]{0,40}/g)]
        .map((m) => m[0].trim())
        .filter((line) => !/(>\s*—\s*<|['"`]\s*—\s*['"`])/.test(line))

describe("Sunday's residents and meetings pages say it without an em dash (Law 3)", () => {
    for (const file of ['Residents/Index.vue', 'Residents/Show.vue', 'Governance/Meetings.vue', 'Governance/Meeting.vue']) {
        it(`${file} draws no em dash`, () => {
            assert.deepEqual(dashes(drawn(file)), [])
        })
    }
})

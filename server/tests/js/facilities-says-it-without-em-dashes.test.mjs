/**
 * The maintenance queue and the work order say it without an em dash — `Pages/Estate/Facilities/Maintenance.vue` and
 * `Ticket.vue` (M53 Law 3; Sunday step 6, DR-128).
 *
 *   Every sentence the two pages draw (empty states, tooltips, labels, refusals) read with an em dash between its
 *   halves. Read from the source with its comments taken out, so a comment may still use one: what a person reads may
 *   not. The lone "—" that stands for an empty value waits on Law 5's decision and is left alone.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const PAGES = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/Pages/Estate/Facilities')

/** The page as a person can read it: HTML comments, block comments and line comments taken out. */
const drawn = (file) =>
    fs
        .readFileSync(path.join(PAGES, file), 'utf8')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1')

/** Each em dash left, with the words around it, except the lone one that means "nothing here" (Law 5's question). */
const dashes = (source) =>
    [...source.matchAll(/[^\n]{0,40}—[^\n]{0,40}/g)]
        .map((m) => m[0].trim())
        .filter((line) => !/(>\s*—\s*<|['"`]\s*—\s*['"`])/.test(line))

/** Each ledger account code a person would read ("Dr 2200 Resident Deposits Held"): the books' machinery (Law 2). */
const codes = (source) => [...source.matchAll(/[^\n]{0,30}\b(?:Dr|Cr) \d{4}\b[^\n]{0,30}/g)].map((m) => m[0].trim())

describe('the Facilities pages say it without an em dash (Law 3)', () => {
    for (const file of ['Maintenance.vue', 'Ticket.vue', 'Bookings.vue', 'Booking.vue', 'AmenitySettings.vue']) {
        it(`${file} draws no em dash`, () => {
            assert.deepEqual(dashes(drawn(file)), [])
        })
    }

    for (const file of ['Booking.vue', 'AmenitySettings.vue', 'Bookings.vue']) {
        it(`${file} prints no ledger account code for a person to read (Law 2)`, () => {
            assert.deepEqual(codes(drawn(file)), [])
        })
    }

    it('still finds one when a sentence has it, and leaves the lone empty value and the comments alone', () => {
        assert.equal(dashes('<p>Nothing has changed — the job stands.</p>').length, 1)
        assert.equal(dashes('<div class="vendor-avatar">—</div>').length, 0)
        assert.equal(dashes("label: '—'").length, 0)
        // The real Ticket.vue keeps em dashes in its comments; read raw it has them, read as drawn it has none.
        const raw = fs.readFileSync(path.join(PAGES, 'Ticket.vue'), 'utf8')
        assert.ok(dashes(raw).length > 0)
        assert.deepEqual(dashes(drawn('Ticket.vue')), [])
    })
})

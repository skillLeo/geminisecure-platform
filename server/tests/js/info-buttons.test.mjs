/**
 * EVERY `i` IN BOTH CONSOLES OPENS ITS NOTE AND CLOSES CLEANLY (M32 F12, §14.3) — `Components/InfoButton.vue`,
 * `lib/infoTopics.js`.
 *
 *   - each topic opens a note with its own words, and closes on Close, on Escape and on a click outside it, leaving
 *     nothing behind; an unknown key draws nothing at all;
 *   - the words are short: a title and two or three sentences, and "What happens next" only where it helps;
 *   - every topic stands on a page, beside a heading — the six §14.2 names among them — and never inside a label, a
 *     button or a link, so no control moves, grows or becomes harder to reach for it;
 *   - the glyph is 16 px, its hit area 28 px, pulled back by negative margins so the line it sits on keeps its height.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { byTag, fire, mount, nextTick } from './support/render.mjs'
import { INFO } from '../../resources/js/lib/infoTopics.js'

const { default: InfoButton } = await import('../../resources/js/Components/InfoButton.vue')

const ROOT = new URL('../../', import.meta.url)
const PAGES = new URL('../../resources/js/Pages/', import.meta.url)
const KEYS = Object.keys(INFO)

const byData = (name) => (el) => el.attrs?.[`data-${name}`] !== undefined
const sheetOf = (view) => view.one(byData('info-sheet'))

/** Every .vue file under Pages, as text: where the topics are placed. */
function pages(dir = fileURLToPath(PAGES)) {
    return readdirSync(dir).flatMap((name) => {
        const file = join(dir, name)
        if (statSync(file).isDirectory()) {
            return pages(file)
        }

        return file.endsWith('.vue') ? [{ file, text: readFileSync(file, 'utf8') }] : []
    })
}

describe('each topic opens its note and closes cleanly', () => {
    for (const key of KEYS) {
        it(key, async () => {
            const topic = INFO[key]
            const view = await mount(InfoButton, { topic: key })
            const button = view.one(byData('info-button'))

            assert.ok(button, 'the i is drawn')
            assert.equal(button.attrs['aria-label'], `About ${topic.title}`)
            assert.equal(button.attrs['aria-expanded'], 'false')
            assert.equal(sheetOf(view) === null, true, 'closed until asked')

            await view.click(button)
            const sheet = sheetOf(view)

            assert.ok(sheet, 'the note opens')
            assert.equal(sheet.attrs.role, 'dialog')
            assert.equal(sheet.attrs['aria-modal'], 'true')
            assert.equal(button.attrs['aria-expanded'], 'true')
            const text = view.text()
            assert.ok(text.includes(topic.title))
            for (const line of topic.lines) {
                assert.ok(text.includes(line), line)
            }
            if (topic.next) {
                assert.ok(text.includes('What happens next') && text.includes(topic.next))
            }

            await view.click(view.all(byTag('button')).find((el) => el.children.some((c) => c.text === 'Close')))
            assert.equal(sheetOf(view) === null, true, 'Close closes it')
            assert.ok(!view.text().includes(topic.lines[0]), 'and nothing of it is left')
            view.unmount()
        })
    }

    it('closes on Escape and on a click outside it, and opens again', async () => {
        const view = await mount(InfoButton, { topic: 'delinquency' })
        const button = view.one(byData('info-button'))

        await view.click(button)
        globalThis.document.dispatchEvent('keydown', { key: 'Escape', preventDefault() {} })
        await nextTick()
        assert.equal(sheetOf(view) === null, true, 'Escape closes it')

        await view.click(button)
        const backdrop = view.one(byData('info-backdrop'))
        // A press on the note itself is not outside it…
        fire(sheetOf(view), 'mousedown')
        await nextTick()
        assert.equal(sheetOf(view) !== null, true, 'a press inside the note keeps it')
        // …a press on the dimmed page around it is.
        fire(backdrop, 'mousedown')
        await nextTick()
        assert.equal(sheetOf(view) === null, true, 'a click outside closes it')

        await view.click(button)
        assert.equal(sheetOf(view) !== null, true, 'and it opens again')
        view.unmount()
    })

    it('draws nothing for a key it does not hold, rather than an empty note', async () => {
        const view = await mount(InfoButton, { topic: 'noSuchTopic' })
        assert.equal(view.one(byData('info-button')) === null, true)
        view.unmount()
    })
})

describe('the words are short, and say what the server does', () => {
    for (const key of KEYS) {
        it(`${key}: a title and two or three sentences`, () => {
            const topic = INFO[key]
            const sentences = topic.lines.join(' ').split(/(?<=[.!?"])\s+(?=[A-Z])/).filter(Boolean)

            assert.ok(topic.title.length > 0)
            assert.ok(topic.lines.length >= 1 && topic.lines.length <= 3)
            assert.ok(sentences.length <= 4, `${key} says ${sentences.length} sentences`)
            for (const line of [...topic.lines, ...(topic.next ? [topic.next] : [])]) {
                assert.ok(line.length <= 260, `${key}: ${line.length} characters`)
            }
        })
    }

    it('gives the guard\'s only words at a restricted household exactly as the server does', () => {
        const policy = readFileSync(new URL('app/Services/Restriction/RestrictionPolicy.php', ROOT), 'utf8')
        const sentence = policy.match(/GATE_SENTENCE = '([^']+)'/)?.[1]

        assert.equal(sentence, 'Access restricted — contact management')
        assert.ok(INFO.delinquency.lines[0].includes(`"${sentence}"`))
        assert.ok(policy.includes("'eligible at 90 days overdue'") || policy.includes('eligible at 90 days overdue'))
        assert.ok(policy.includes("14 days' written notice"))
    })

    // M33 §3: the one address a person is offered is the client's. The company's old mailbox on geminisl.com takes no mail.
    it('names no contact address but the client\'s gemisecja@gmail.com', () => {
        for (const key of KEYS) {
            const words = [INFO[key].title, ...INFO[key].lines, INFO[key].next ?? ''].join(' ')

            assert.ok(!/geminisl\.com/i.test(words), `${key} names the geminisl.com mailbox, which takes no mail`)
            for (const address of words.match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g) ?? []) {
                assert.equal(address.toLowerCase(), 'gemisecja@gmail.com', `${key} offers ${address}`)
            }
        }
    })
})

describe('every topic stands beside a heading, and never inside a control', () => {
    const placed = pages().filter((page) => page.text.includes('<InfoButton'))

    it('places every topic on a page, the six §14.2 names among them', () => {
        const all = placed.map((page) => page.text).join('\n')

        for (const key of ['delinquency', 'contractedLimit', 'lotRanges', 'geofence', 'journals', 'householdRoles']) {
            assert.ok(KEYS.includes(key), `${key} is a topic`)
        }
        for (const key of KEYS) {
            assert.match(all, new RegExp(`<InfoButton[^>]*topic="${key}"`), `${key} is on a page`)
        }
        // Both consoles.
        assert.ok(placed.some((page) => /[\\/]Estate[\\/]/.test(page.file)))
        assert.ok(placed.some((page) => /[\\/]Gemini[\\/]/.test(page.file)))
    })

    it('is never inside a label, a button or a link', () => {
        for (const page of placed) {
            for (const line of page.text.split('\n').filter((l) => l.includes('<InfoButton'))) {
                assert.ok(!/<label|<button|<Link|<a /.test(line), `${page.file}: ${line.trim()}`)
            }
            // And no label is still open around one: the nearest <label> above it is closed before it.
            for (const [at] of [...page.text.matchAll(/<InfoButton/g)].map((m) => [m.index])) {
                const before = page.text.slice(0, at)
                assert.ok(before.lastIndexOf('<label') <= before.lastIndexOf('</label>'), `${page.file}: an i inside a label`)
            }
        }
    })

    it('is a 16 px glyph in a 28 px hit area that keeps the line it sits on at its height', () => {
        const source = readFileSync(new URL('resources/js/Components/InfoButton.vue', ROOT), 'utf8')

        assert.match(source, /width="16" height="16"/)
        assert.match(source, /width: 28px;\s*height: 28px;\s*margin: -6px 0 -6px 2px;/)
    })
})

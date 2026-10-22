/**
 * A notice on a door card is a small icon beside the words, never a hero graphic (the front door, M97 item 2).
 *
 *   `/login`, `/forgot-password` and `/verify-contact` drew their notices with board classes (`.mfa-note`,
 *   `.crit-banner`) that only the Gemini door's sheet defines, so on the estate door the icon had no size at all and
 *   filled the card, pushing the field and the button below the fold. `Components/DoorNotice.vue` sizes itself: a 16px
 *   icon, the words beside it, the same on both doors; and no door page uses the unsized classes any more.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { h } from 'vue'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const COMPONENT = path.resolve(HERE, '../../resources/js/Components/DoorNotice.vue')
const AUTH = path.resolve(HERE, '../../resources/js/Pages/Auth')

const { default: DoorNotice } = await import('../../resources/js/Components/DoorNotice.vue')

const render = async (tone, words) => {
    const view = await mount({ render: () => h(DoorNotice, { tone }, () => words) })
    await nextTick()

    return view
}

/** The px a rule sets for `prop` on `selector`, read from the component's own style block. */
const px = (css, selector, prop) => {
    const rule = css.match(new RegExp(`${selector.replace(/[.-]/g, (c) => `\\${c}`)}\\s*\\{([^}]*)\\}`))

    return rule ? Number((rule[1].match(new RegExp(`(?:^|;|\\s)${prop}:\\s*(\\d+)px`)) ?? [])[1]) : NaN
}

describe('a door notice (M97 item 2)', () => {
    it('sizes its own icon, beside the words, whichever door draws it: 32px when something happened, small otherwise', () => {
        const css = fs.readFileSync(COMPONENT, 'utf8').split('<style')[1] ?? ''

        assert.match(css, /\.door-notice\s*\{[^}]*display:\s*flex/, 'the icon sits beside the words, not above them')
        assert.equal(px(css, '.door-notice svg', 'width'), 15, 'every icon has a size, so none fills the card')
        assert.equal(px(css, '.door-notice svg', 'height'), 15)

        // "Make it 32 to 48 px, beside the message."
        const ok = px(css, '.door-notice--ok svg', 'width')
        assert.ok(ok >= 32 && ok <= 48, `the status icon is ${ok}px`)
        assert.equal(px(css, '.door-notice--ok svg', 'height'), ok)

        // "A form error is not a hero graphic: small, inline, next to the field."
        assert.ok(px(css, '.door-notice--error svg', 'width') <= 16)
        assert.match(css, /\.door-notice--error\s*\{[^}]*background:\s*none/, 'a refusal is a line, not a banner')
    })

    it('says a failure as an alert and anything else as a status, with one icon and the words', async () => {
        for (const [tone, role] of [['error', 'alert'], ['ok', 'status'], ['info', 'status'], ['warn', 'status']]) {
            const view = await render(tone, 'Those credentials do not match our records.')
            try {
                const notice = view.one((el) => byClass('door-notice')(el))
                assert.equal(notice.attrs.role, role, tone)
                assert.ok(byClass(`door-notice--${tone}`)(notice))
                assert.equal(notice.children.filter((el) => el.tag === 'svg').length, 1, 'one small icon')
                assert.match(textOf(notice), /Those credentials do not match our records\./)
            } finally {
                view.unmount()
            }
        }
    })

    it('is what every door page uses: no page draws the unsized board notices any more', () => {
        for (const file of fs.readdirSync(AUTH).filter((name) => name.endsWith('.vue'))) {
            const source = fs.readFileSync(path.join(AUTH, file), 'utf8')

            assert.doesNotMatch(source, /class="(mfa-note|crit-banner)"/, `${file} still draws a board notice`)
        }
    })
})

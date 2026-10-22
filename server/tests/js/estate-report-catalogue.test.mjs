/**
 * The report catalogue — `Pages/Estate/Reports/Index.vue` (board 29; M83 §1.2 item 3, M84 §3.3, REP-1; Law 1).
 *
 *   Budget vs Actual's Generate was a disabled button drawn exactly like the six working links, its only signal the
 *   cursor on hover, which a touch screen never shows. A card that cannot be generated now looks it, and says why on the
 *   card in one line; a card that can is a link, with nothing under it. Until budgets are switched on (M84 §3.3), its
 *   line says budgets are coming.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: Index } = await import('../../resources/js/Pages/Estate/Reports/Index.vue')

const BUDGET = 'Budgets are coming.'
const ACCESS = 'Generating a report needs Reports export access.'

const card = (key, name, overrides = {}) => ({
    key,
    name,
    description: `${name}, described.`,
    icon: 'currency',
    action: 'Generate',
    ready: true,
    reason: `${name}, over a period you choose.`,
    built: true,
    withheld: null,
    ...overrides,
})

const open = async (canGenerate = true) => {
    resetInertia({ auth: { user: { id: 7, name: 'Tracey Reid', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {} }, '/estate/phoenixpark/reports')

    const view = await mount(Index, {
        estate: { name: 'Phoenix Park' },
        groups: [
            {
                heading: 'Financial',
                cards: [
                    card('profit_and_loss', 'Profit & Loss'),
                    card('budget_vs_actual', 'Budget vs Actual', { ready: false, built: false, reason: BUDGET, icon: 'calendar' }),
                ],
            },
        ],
        canGenerate,
        reasons: { access: ACCESS },
    })
    await nextTick()

    const cardOf = (name) => view.all((el) => el.className?.split?.(/\s+/).includes('report-card')).find((el) => textOf(el).includes(name))

    return {
        view,
        action: (name) => cardOf(name).children.find((el) => el.className?.split?.(/\s+/).includes('rc-action')) ?? null,
        why: (name) => {
            const line = cardOf(name).children.find((el) => el.className?.split?.(/\s+/).includes('rc-why'))

            return line ? textOf(line).trim() : null
        },
    }
}

describe('a card that cannot be generated looks it, and says why on the card', () => {
    it('draws Budget vs Actual as an off button, unlike the links, with its reason under it in one line', async () => {
        const page = await open()
        const off = page.action('Budget vs Actual')

        assert.equal(off.tag, 'button')
        // A bare `disabled` attribute arrives as the empty string: present is what disables it.
        assert.equal(off.attrs.disabled !== undefined && off.attrs.disabled !== false, true, 'it should not be pressable')
        assert.equal(off.className.split(/\s+/).includes('rc-action-off'), true, 'it is drawn exactly like the working links')
        assert.equal(off.attrs.title, BUDGET)
        assert.equal(page.why('Budget vs Actual'), BUDGET, 'the reason is only under the cursor, which a touch screen never shows')
    })

    it('draws a card that runs as a link, with no reason under it', async () => {
        const page = await open()
        const link = page.action('Profit & Loss')

        assert.notEqual(link.tag, 'button')
        assert.equal(link.className.split(/\s+/).includes('rc-action-off'), false)
        assert.equal(page.why('Profit & Loss'), null)
    })

    // M98 (M96 level 3): a role that can never generate has no Generate at all, where it had a greyed one. A yes/no, never
    // an element, in the assertion: a failing assert prints its values, and an element prints the whole rendered tree.
    it('says the role\'s own reason under every card for a role that cannot generate, with no Generate to press', async () => {
        const page = await open(false)

        for (const name of ['Profit & Loss', 'Budget vs Actual']) {
            assert.equal(page.action(name) === null, true, `${name} draws a Generate this role can never press`)
            assert.equal(page.why(name), ACCESS)
        }
    })
})

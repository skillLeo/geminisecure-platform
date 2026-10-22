/**
 * Resident access: a row that needs another is drawn disabled, with why — `Pages/Estate/Settings/ResidentAccess.vue`
 * (M62 B6, DR-025; Law 1).
 *
 *   "Pay dues and fees, save cards, turn on AutoPay" cannot be had without "See the household's dues, statement and
 *   receipts", and "Change the household's vehicles and emergency contacts" without "See the household". While the row
 *   it needs is off, its switch was drawn off but enabled: pressing it changed nothing on screen and quietly changed
 *   what Save would store. It is now disabled, saying in one line what would make it available, and pressing it
 *   changes nothing at all. Its label names the level with a middot, not an em dash (Law 3).
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: ResidentAccess } = await import('../../resources/js/Pages/Estate/Settings/ResidentAccess.vue')

const SEE_HOUSEHOLD = 'See the household: its people, vehicles and emergency contacts'
const SEE_DUES = "See the household's dues, statement and receipts"

const GROUPS = [
    { key: 'household', label: SEE_HOUSEHOLD, flag: 'privacy', needs: null, dependent: true, helper: false, dependent_default: true, helper_default: false },
    { key: 'household_edit', label: "Change the household's vehicles and emergency contacts", flag: null, needs: SEE_HOUSEHOLD, dependent: true, helper: true, dependent_default: true, helper_default: false },
    { key: 'dues', label: SEE_DUES, flag: 'money', needs: null, dependent: false, helper: false, dependent_default: false, helper_default: false },
    { key: 'payments', label: 'Pay dues and fees, save cards, turn on AutoPay', flag: 'money', needs: SEE_DUES, dependent: false, helper: false, dependent_default: false, helper_default: false },
]

let user = 64000

const open = async () => {
    resetInertia({ auth: { user: { id: ++user, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null }, '/settings/resident-access')
    globalThis.window.location ??= { search: '', pathname: '/settings/resident-access' }
    const view = await mount(ResidentAccess, {
        estate: { name: 'Phoenix Park' },
        sections: [],
        groups: GROUPS,
        fixed: [],
        helper_days: ['mon'],
        helper_from: '08:00',
        helper_to: '17:00',
        entry: { dependent: 'primary', helper: 'primary' },
        levelsOpen: true,
        levelsClosedReason: '',
        canEdit: true,
        blockedReason: '',
    })
    await flush(nextTick)

    const sw = (label, level) => view.one((el) => el.tag === 'button' && el.attrs?.role === 'switch' && el.attrs?.['aria-label'] === `${label} · ${level}`)

    return { view, sw }
}

describe('a row that needs another is disabled while that one is off (DR-025)', () => {
    it('"Pay dues…" for the Dependent is disabled, says why, and its label has no em dash', async () => {
        const { sw } = await open()
        const pay = sw('Pay dues and fees, save cards, turn on AutoPay', 'Dependent')

        assert.ok(pay, 'the switch is found by its label, written with a middot')
        assert.equal(pay.attrs.disabled !== undefined && pay.attrs.disabled !== false, true, 'disabled')
        assert.equal(pay.attrs.title, `Turn on “${SEE_DUES}” for the Dependent first.`)
    })

    it('pressing it changes nothing, so there is nothing to save', async () => {
        const { view, sw } = await open()
        await view.click(sw('Pay dues and fees, save cards, turn on AutoPay', 'Helper'))

        const save = view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Save changes')
        assert.equal(save.attrs.disabled !== undefined && save.attrs.disabled !== false, true, 'nothing changed, so Save is greyed')
        assert.equal(view.text().includes('Nothing has changed yet.'), true, 'and says why beside it')
        assert.equal(visits.filter((v) => v.method === 'post').length, 0)
    })

    it('names every change before anything is saved, and saves only from the question (12 Oct)', async () => {
        const { view, sw } = await open()
        await view.click(sw(SEE_DUES, 'Dependent'))
        await view.click(view.all((el) => el.tag === 'button' && textOf(el).trim() === 'Save changes')[0])

        assert.equal(view.text().includes(`Dependent gains “${SEE_DUES}”`), true, 'the question names the change')
        assert.equal(visits.filter((v) => v.method === 'post').length, 0, 'asking posts nothing')

        await view.click(view.all((el) => el.tag === 'button' && textOf(el).trim() === 'Save changes').at(-1))
        assert.equal(visits.filter((v) => v.method === 'post').at(-1).data.dependent.dues, true, 'the question\'s button saves it')
    })

    it('"Change the household\'s vehicles…" for the Helper is disabled while the Helper cannot see the household', async () => {
        const { sw } = await open()
        const edit = sw("Change the household's vehicles and emergency contacts", 'Helper')

        assert.equal(edit.attrs.disabled !== undefined && edit.attrs.disabled !== false, true)
        assert.equal(edit.attrs.title, `Turn on “${SEE_HOUSEHOLD}” for the Helper first.`)
    })

    it('once the row it needs is on, it can be pressed and turns on', async () => {
        const { view, sw } = await open()
        await view.click(sw(SEE_DUES, 'Dependent'))
        const pay = sw('Pay dues and fees, save cards, turn on AutoPay', 'Dependent')

        assert.ok(pay.attrs.disabled === undefined || pay.attrs.disabled === false, 'enabled now')
        await view.click(pay)
        assert.equal(String(sw('Pay dues and fees, save cards, turn on AutoPay', 'Dependent').attrs['aria-checked']), 'true')
    })
})
